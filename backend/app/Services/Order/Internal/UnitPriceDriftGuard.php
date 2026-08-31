<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Support\RoundingMode;

/**
 * #1715 — chặn đơn được tạo ở mức giá KHÁC cái khách đang nhìn thấy.
 *
 * ## Vì sao cần
 *
 * customer-web chụp giá vào giỏ lúc khách bấm card rồi giữ nguyên, còn server
 * **luôn định giá lại** lúc tạo đơn. Khung giờ ưu đãi đóng lúc 20:00 mà món vào
 * giỏ từ 19:59: giỏ hiện ¥800, đơn ra ¥1,100. Không màn nào của customer-web hỏi
 * server trước khi commit (`/order-confirm` render từ draft phía client), nên
 * khách chỉ biết khi đơn đã nằm trong hệ thống.
 *
 * Chốt chặn đặt ở ĐÂY chứ không phải ở client vì hai lý do:
 *   • **Không có khe TOCTOU** — phép so xảy ra trong chính lời gọi tạo đơn, không
 *     phải "client soát xong rồi mới POST".
 *   • **Phủ mọi client** — kiosk/handy sau này đặt đơn cũng được che, không phải
 *     đi sửa lại từng cái.
 *
 * ## Bất đối xứng, có chủ đích
 *
 * Chỉ từ chối khi **server > expected** (khách sắp bị tính CAO hơn cái đã thấy).
 * `server < expected` thì **nhận đơn** — khách được lợi, và quan trọng hơn: luật
 * #514 cho phép server hợp lệ tính RẺ hơn card khách bấm (nó lấy dòng menu rẻ
 * nhất toàn chi nhánh cho một SKU, xem `cheapestActiveMenuLine`). So đối xứng sẽ
 * biến chuyện bình thường đó thành 409 giả hàng loạt.
 *
 * ## So theo DÒNG, không theo tổng
 *
 * Tổng đi qua gom nhóm theo mức thuế, làm tròn một lần mỗi nhóm, phí phục vụ và
 * coupon — client tự tính bằng số học riêng nên lệch ¥1 là chuyện thường, và so
 * tổng sẽ 409 mọi đơn. `unit_price` thì đúng bằng con số in trên card và không đi
 * qua đường ống làm tròn nào.
 *
 * ## So trên ĐÚNG LƯỚI mà động cơ tiền đang dùng
 *
 * Cả hai vế được **lượng tử hoá về cùng bước** `RoundingMode::step('auto', …)` rồi
 * so số nguyên. Hai thứ đạt được cùng lúc: hết sạch nhiễu float của phép nhân phần
 * trăm khuyến mãi (`1000 * 0.9` không phải lúc nào cũng là `900.0`), và không bao
 * giờ chặn một độ lệch mà chính động cơ tiền **không biểu diễn nổi**.
 *
 * Vì sao là `step('auto', currency)` chứ không phải một cài đặt của chi nhánh: chi
 * nhánh CÓ ba cột làm tròn — `split_bill_rounding_mode`, `tax_rounding_mode`,
 * `tax_rounding_decimals` — nhưng **không cột nào chạm vào định giá đơn**. Mọi chỗ
 * tính tiền (`CustomerOrderPricingResolution`, `recalculateTotals`,
 * `writeConditions`…) đều gọi
 * `RoundingMode::step('auto', $currency)` với mode ghim cứng; cột
 * `split_bill_rounding_mode` đúng như tên của nó, chỉ dùng khi chia bill.
 *
 * Và `auto` KHÔNG đồng nghĩa với `CurrencyMinorUnit::exponent`: IDR/LAK/MMK/COP có
 * minor unit 2 chữ số nhưng chỉ lưu hành đơn vị nguyên, nên `autoStep` trả 1.0. So
 * theo exponent sẽ mịn gấp 100 lần cái động cơ tiền biểu diễn được và 409 vì một
 * khoản chênh không bao giờ được tính vào hoá đơn.
 */
final class UnitPriceDriftGuard
{
    private readonly float $step;

    private readonly int $decimals;

    /** @var list<array<string, mixed>> */
    private array $drifts = [];

    public function __construct(private readonly string $currency = 'JPY')
    {
        // Cùng lưới với mọi phép tính tiền khác của đơn. `step` chỉ có thể là 0
        // ở mode `none`, mà định giá đơn không dùng mode đó — vẫn chặn để một
        // thay đổi sau này không lặng lẽ biến phép chia thành chia-cho-không.
        $step = RoundingMode::step('auto', $currency);
        $this->step = $step > 0 ? $step : 1.0;
        $this->decimals = match (true) {
            $this->step >= 1 => 0,
            $this->step <= 0.001 => 3,
            default => 2,
        };
    }

    /**
     * Ghi nhận một dòng lệch. `$expected` null (client cũ) ⇒ bỏ qua.
     */
    public function record(int|string $index, string $productSkuId, mixed $expected, float $actual): void
    {
        if ($expected === null || $expected === '') {
            return;
        }

        $expectedSteps = $this->toSteps((float) $expected);
        $actualSteps = $this->toSteps($actual);

        if ($actualSteps <= $expectedSteps) {
            return;
        }

        $this->drifts[] = [
            'index' => (string) $index,
            'product_sku_id' => $productSkuId,
            'expected_unit_price' => $this->toMajorString($expectedSteps),
            'actual_unit_price' => $this->toMajorString($actualSteps),
            'currency' => $this->currency,
        ];
    }

    public function hasDrift(): bool
    {
        return $this->drifts !== [];
    }

    /**
     * 409 kèm giá server của TỪNG dòng lệch.
     *
     * Gom cả lô rồi mới ném (thay vì ném ở dòng đầu tiên) để client cập nhật giỏ
     * một lượt thay vì bị chặn nhiều lần liên tiếp — thân lỗi này chính là cái
     * "báo giá" mà nếu không có nó sẽ phải dựng riêng một endpoint quote.
     *
     * Gọi sau khi vòng lặp định giá xong; mọi đường ghi đều nằm trong transaction
     * nên `abort` ở đây rollback sạch.
     */
    public function assertNoDrift(): void
    {
        if ($this->drifts === []) {
            return;
        }

        abort(response()->json([
            'message' => 'Some item prices changed since they were shown. Refresh the cart and confirm the new total.',
            'code' => 'line_unit_price_drift',
            'items' => $this->drifts,
        ], 409));
    }

    /** Số BƯỚC tròn — đơn vị nhỏ nhất mà hoá đơn ở tiền tệ này biểu diễn được. */
    private function toSteps(float $major): int
    {
        return (int) round($major / $this->step);
    }

    private function toMajorString(int $steps): string
    {
        return number_format($steps * $this->step, $this->decimals, '.', '');
    }
}
