<?php

declare(strict_types=1);

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderCondition;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;

/**
 * Ba câu hỏi của chủ dự án, mỗi câu một mệnh đề kiểm được.
 *
 * ## 1. Thuế có bị tính TRÙNG không — dòng rồi lại đơn?
 *
 * Không, và lý do là CHIỀU: `OrderPricingCalculator` tính thuế theo NHÓM MỨC
 * (`$taxAmount = $totalGroupTax + $serviceChargeTax`), rồi `stampLineTaxAmounts`
 * PHÂN BỔ con số nhóm ấy XUỐNG từng dòng. Dòng là dẫn xuất của nhóm, không
 * phải nguồn cộng lên đơn.
 *
 * Nên trùng thuế chỉ xảy ra nếu ai đó đảo chiều — cộng `items.tax_amount` lên
 * thành thuế đơn. Test dưới ghim điều đó bằng cách nhét số RÁC vào
 * `items.tax_amount` rồi tính lại: nếu đơn cộng dòng, nó sẽ ăn rác.
 *
 * ## 2. Tip có bị đánh thuế không? Nếu cần đánh thuế thì có làm được không?
 *
 * Tip KHÔNG chịu thuế, và đó là đúng: tip tự nguyện không phải đối giá của
 * hàng hoá/dịch vụ (不課税). Nó nằm ở `order_payments.tip_amount`, ngoài
 * `total_amount` (BR-P03).
 *
 * Nếu một quán cần một khoản "tip" CÓ chịu thuế thì theo chuẩn nó không phải
 * tip — nó là **phí phục vụ bắt buộc**, và cái đó hệ thống làm được: phí phục
 * vụ mang thuế suất riêng. Ranh giới tự nguyện/bắt buộc mới là thứ quyết định
 * chịu thuế hay không, không phải tên gọi.
 *
 * ## 3. Phụ phí CÓ thuế và KHÔNG thuế — điều khiển được qua sổ chưa?
 *
 * Được, qua `rate` trên dòng `service_charge`: có mức ⇒ chịu thuế ở mức đó;
 * `null` ⇒ ngoài phạm vi thuế. Hai test cuối ghim cả hai chiều.
 */
function scOrder(float $scRate, float $scTaxRate, array $attrs = []): CustomerOrder
{
    $order = CustomerOrder::factory()->create(array_merge([
        'status' => 'open',
        'is_tax_included' => false,
        'discount_amount' => 0,
        'coupon_id' => null,
        'tax_rounding_mode' => 'round',
        'tax_rounding_decimals' => 0,
    ], $attrs));

    ShopOrderSetting::query()->firstOrCreate(
        ['branch_id' => $order->branch_id],
        [
            'organization_id' => $order->organization_id,
            'service_charge_rate' => $scRate,
            'service_charge_tax_rate' => $scTaxRate,
            'currency_code' => 'JPY',
            'tax_rounding_mode' => 'round',
        ]
    );

    return $order;
}

function scLine(CustomerOrder $order, float $price, float $rate, float $staleTax = 0): CustomerOrderItem
{
    return CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => $price,
        'topping_subtotal' => 0,
        'subtotal' => $price,
        'tax_rate' => $rate,
        'tax_amount' => $staleTax,
        'status' => 'served',
    ]);
}

function scSettle(CustomerOrder $order): CustomerOrder
{
    $fresh = $order->fresh('items');
    app(CustomerOrderService::class)->refreshOrderTotals($fresh);

    return $fresh->fresh('items');
}

function scRows(CustomerOrder $order, string $type)
{
    return OrderCondition::query()
        ->where('conditionable_type', $order->getMorphClass())
        ->where('conditionable_id', $order->id)
        ->where('type', $type)
        ->get();
}

// ─── 1. Thuế KHÔNG bị tính trùng ────────────────────────────────────────────

it('thuế đơn KHÔNG cộng thêm thuế đã đóng dấu trên dòng — dòng là DẪN XUẤT của nhóm', function () {
    // Nhét số RÁC vào `items.tax_amount` trước khi tính lại. Nếu đơn cộng dòng
    // lên (chiều sai) thì thuế sẽ ra 9.999 + phần thật. Đúng chiều thì rác bị
    // GHI ĐÈ bởi phép phân bổ từ nhóm.
    $order = scOrder(0, 0);
    scLine($order, 1000, 10, staleTax: 9999);
    $order = scSettle($order);

    expect((float) $order->tax_amount)->toBe(100.0);
    expect((float) $order->items->sum(fn ($i) => (float) $i->tax_amount))->toBe(100.0);
});

it('tính lại NHIỀU LẦN không làm thuế phình lên', function () {
    // Trùng thuế hay lộ ra ở lần tính thứ hai chứ không phải lần đầu: một cài
    // đặt cộng dồn sẽ tăng đều mỗi lượt chạm đơn.
    $order = scOrder(10, 10);
    scLine($order, 1000, 10);
    $order = scSettle($order);
    $first = (float) $order->tax_amount;

    scSettle($order);
    scSettle($order);
    $order = $order->fresh('items');

    expect((float) $order->tax_amount)->toBe($first);
    expect(scRows($order, 'tax'))->toHaveCount(1);
});

it('Σ thuế dòng == thuế đơn TRỪ phần thuế của phí phục vụ', function () {
    // Phí phục vụ chịu thuế nhưng KHÔNG phải một dòng món, nên thuế của nó nằm
    // trong `tax_amount` mà không nằm trong Σ dòng. Chênh lệch đó phải bằng
    // đúng thuế của phí — nếu lớn hơn, có gì đó bị đếm hai lần.
    $order = scOrder(10, 10);
    scLine($order, 1000, 10);
    $order = scSettle($order);

    $lineSum = (float) $order->items->sum(fn ($i) => (float) $i->tax_amount);
    $scTax = (float) $order->tax_amount - $lineSum;

    // phí = 1000 × 10% = 100 ⇒ thuế phí = 100 × 10% = 10
    expect($scTax)->toBe(10.0);
    expect((float) $order->tax_amount)->toBe(110.0);
});

// ─── 2. Tip ─────────────────────────────────────────────────────────────────

it('tip KHÔNG bị đánh thuế và KHÔNG nằm trong total_amount', function () {
    // 不課税: tip tự nguyện không phải đối giá của hàng hoá/dịch vụ. BR-P03 để
    // nó ngoài `total_amount` — tiền cho nhân viên, không phải doanh thu quán.
    $order = scOrder(0, 0);
    scLine($order, 1000, 10);
    $order = scSettle($order);

    $order->update(['total_tip' => 500]);
    $order = scSettle($order);

    expect((float) $order->tax_amount)->toBe(100.0, 'tip đã bị kéo vào nền chịu thuế');
    expect((float) $order->total_amount)->toBe(1100.0, 'tip đã bị cộng vào tổng đơn');
    expect(scRows($order, 'tip'))->toHaveCount(0);
});

it('cần một khoản "tip" CÓ chịu thuế thì dùng phí phục vụ — hệ thống làm được', function () {
    // Ranh giới tự nguyện/bắt buộc quyết định chịu thuế, không phải tên gọi.
    // Một khoản bắt buộc cộng vào hoá đơn là PHÍ PHỤC VỤ, và nó chịu thuế ở
    // mức riêng của nó — không cần thêm cơ chế nào.
    $order = scOrder(scRate: 15, scTaxRate: 10);
    scLine($order, 1000, 10);
    $order = scSettle($order);

    expect((float) $order->service_charge)->toBe(150.0);
    // thuế = 1000×10% + 150×10% = 115
    expect((float) $order->tax_amount)->toBe(115.0);

    $row = scRows($order, 'service_charge')->first();
    expect((float) $row->rate)->toBe(10.0, 'khoản bắt buộc phải mang mức thuế của nó');
});

// ─── 3. Phụ phí CÓ thuế / KHÔNG thuế ────────────────────────────────────────

it('phụ phí CHỊU thuế: dòng mang mức, và mức đó vào nền chịu thuế', function () {
    $order = scOrder(scRate: 10, scTaxRate: 8);
    scLine($order, 1000, 10);
    $order = scSettle($order);

    $row = scRows($order, 'service_charge')->first();

    expect((float) $row->amount)->toBe(100.0);
    expect((float) $row->rate)->toBe(8.0, 'phí chịu mức RIÊNG, không theo mức của món');
    // thuế = 1000×10% + 100×8% = 108
    expect((float) $order->tax_amount)->toBe(108.0);
});

it('phụ phí KHÔNG chịu thuế: rate NULL, và không kéo nền nào lên', function () {
    // `rate = null` là "ngoài phạm vi thuế" — khác hẳn `rate = 0` (chịu thuế ở
    // mức 0%, vẫn vào nền của tờ khai).
    $order = scOrder(scRate: 10, scTaxRate: 0);
    scLine($order, 1000, 10);
    $order = scSettle($order);

    $row = scRows($order, 'service_charge')->first();

    expect((float) $row->amount)->toBe(100.0);
    expect($row->rate)->toBeNull();
    expect((float) $order->tax_amount)->toBe(100.0, 'phí không chịu thuế mà vẫn sinh thuế');
    expect((float) $order->total_amount)->toBe(1200.0); // 1000 + 100 phí + 100 thuế
});

it('phụ phí gộp vào nhóm mức ĐÃ CÓ, không sinh nhóm trùng mức', function () {
    // Phí chịu 10% trên đơn đã có món 10% ⇒ MỘT dòng thuế mức 10%, nền gồm cả
    // hai. Sinh nhóm thứ hai cùng mức làm hoá đơn in hai dòng "10%対象".
    $order = scOrder(scRate: 10, scTaxRate: 10);
    scLine($order, 1000, 10);
    $order = scSettle($order);

    $taxRows = scRows($order, 'tax');

    expect($taxRows)->toHaveCount(1);
    expect((float) $taxRows[0]->taxable_base)->toBe(1100.0);
    expect((float) $taxRows[0]->amount)->toBe(110.0);
});

it('phụ phí chịu mức KHÁC mọi món: sinh nhóm mức RIÊNG', function () {
    $order = scOrder(scRate: 10, scTaxRate: 8);
    scLine($order, 1000, 10);
    $order = scSettle($order);

    $rates = scRows($order, 'tax')->pluck('rate')->map(fn ($r) => (float) $r)->sort()->values()->all();

    expect($rates)->toBe([8.0, 10.0]);
});

// ─── Dòng VOID / REFUND trong mẫu số pro-rata ───────────────────────────────

it('dòng VOID ở mức KHÔNG phải cuối: giảm giá vẫn chia đúng', function () {
    // Ca này ẩn được rất lâu vì mức CUỐI hấp thụ phần dư — dòng huỷ nằm ở mức
    // cuối thì tổng vẫn khớp và không gì lộ ra. Chỉ khi nó nằm ở mức KHÔNG
    // phải cuối thì mẫu số sai mới đẩy ra con số vô lý.
    //
    // Đo trước khi sửa: mức 8% ăn −800 (gấp đôi CẢ khoản giảm 400) và mức 10%
    // không có dòng nào vì phần còn lại ra âm.
    $order = scOrder(0, 0, ['discount_amount' => 400]);
    scLine($order, 1000, 8);
    scLine($order, 1000, 10);
    $voided = scLine($order, 3000, 8);
    $voided->update(['status' => 'voided']);
    $order = scSettle($order);

    $rows = scRows($order, 'discount');

    expect($rows)->toHaveCount(2);
    expect((float) $rows->sum(fn ($r) => (float) $r->amount))->toBe(-400.0);
    foreach ($rows as $r) {
        expect((float) $r->amount)->toBe(-200.0, "mức {$r->rate}% chia sai");
    }
});

it('dòng REFUND cũng bị loại khỏi mẫu số pro-rata', function () {
    // `rateSubtotalsForOrder` loại dòng hoàn khỏi TỬ SỐ. Để lại chúng trong
    // MẪU SỐ là so hai tập khác nhau. (Phía Go loại đúng từ đầu — chênh lệch
    // này là một trong 12 lệch mà lượt rà parity tìm ra.)
    $order = scOrder(0, 0, ['discount_amount' => 200]);
    scLine($order, 1000, 8);
    scLine($order, 1000, 10);
    $order = scSettle($order);

    $item = $order->items->first();
    app(CustomerOrderService::class)->refundItem($order->fresh('items'), $item->id, 1.0, 'test');
    $order = $order->fresh();

    // Bất biến phải đứng bất kể có dòng hoàn: tổng sổ khớp cột.
    expect((float) scRows($order, 'discount')->sum(fn ($r) => (float) $r->amount))
        ->toBe(-1.0 * (float) $order->discount_amount);
});
