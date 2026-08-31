<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use App\Services\Order\Contracts\OrderTaxBreakdownReads;

/**
 * plan-053 T5.1d slice 1a (#1908) — phân tách thuế theo TỪNG MỨC của một phiếu.
 *
 * Đối ứng PHP của `receiptTaxSummary` (workstation
 * `internal/service/print_tax_blocks.go`). Emitter của họ bill đọc nó ở HAI chỗ
 * và cả hai phải nói giống nhau: dấu ※ trên từng dòng món, và khối thuế theo
 * mức ở chân phiếu. Vì vậy nó được tính MỘT lần ở prologue rồi để trên
 * {@see PrintRenderContext}, không tính lại trong emitter.
 *
 * ── KHÔNG tính lại thuế. Đọc snapshot. ────────────────────────────────────
 *
 * Bên Go, `buildReceiptTaxSummary` chạy `priceGroups()` — tức TÍNH LẠI từ
 * `itemTaxableSubtotal`. **Bản PHP không được làm thế**, và đây là lý do:
 *
 * Cloud đã có `OrderTaxBreakdownReads` → `OrderTaxBreakdownAggregator`, đang
 * được `ShopTillTrackingService` (Z-report) và `TillSessionService`
 * (`settlement_snapshot` bất biến của plan-046) dùng. Docblock của chính cổng
 * đó nói thẳng:
 *
 *   *"Làm tròn đã xảy ra MỘT LẦN cho mỗi nhóm mức thuế rồi mới phân bổ xuống
 *   dòng… Cộng theo bất kỳ cách nào khác — làm tròn lại từng dòng, hay tính
 *   `tax = taxable × rate` — sẽ ra một con số KHÁC với hoá đơn đã in, và đó là
 *   báo cáo thuế."*
 *
 * Port `priceGroups` sang PHP là dựng **engine thuế thứ hai** cạnh một engine
 * đã có và đã được hai bề mặt kết ca tin dùng — đúng cái bẫy `plans/plan-053`
 * T5.3 nêu cho raster: *"tự viết một bộ ở PHP sẽ tạo ra chuẩn thứ hai mà không
 * có gì để đối chiếu"*.
 *
 * Hai bên vẫn phải ra cùng số: Go tự khai `priceGroups` được dùng lại *"so a
 * printed block agrees with the engine total to the yen"*. Nếu chúng lệch, đó
 * là lỗi phải tìm — không phải lý do để PHP tự tính.
 */
final class ReceiptTaxSummary
{
    /**
     * @param  list<ReceiptTaxBlock>  $blocks  đã sắp theo mức TĂNG DẦN
     * @param  bool  $hasReduced  có mức thấp hơn mức cao nhất ⇒ in chú thích ※
     * @param  float  $reducedRate  mức mang dấu ※. Chỉ có nghĩa khi $hasReduced
     * @param  float  $maxRate  mức cao nhất có mặt — ngưỡng để chấm một dòng là "giảm"
     */
    private function __construct(
        public readonly array $blocks,
        public readonly bool $hasReduced,
        public readonly float $reducedRate,
        public readonly float $maxRate,
    ) {}

    /**
     * Dựng từ payload của {@see OrderTaxBreakdownReads::forOrders()}.
     *
     * `by_rate` rỗng ⇒ summary RỖNG, và đó là một trạng thái có nghĩa: đơn cũ
     * không có snapshot mức thuế trên dòng nào. Bên Go nhánh ấy làm phiếu rơi
     * về đường "Thue" một dòng thay vì in khối theo mức — giữ nguyên hành vi đó,
     * đừng thay bằng một khối 0%.
     *
     * @param  array{by_rate?: list<array{rate: float|int|string, taxable: float|int|string, tax: float|int|string}>}  $breakdown
     */
    public static function fromBreakdown(array $breakdown): self
    {
        $rows = $breakdown['by_rate'] ?? [];

        if ($rows === []) {
            return self::empty();
        }

        $blocks = array_map(
            static fn (array $row): ReceiptTaxBlock => new ReceiptTaxBlock(
                rate: (float) $row['rate'],
                taxable: (int) round((float) $row['taxable']),
                tax: (int) round((float) $row['tax']),
            ),
            $rows,
        );

        usort($blocks, static fn (ReceiptTaxBlock $a, ReceiptTaxBlock $b): int => $a->rate <=> $b->rate);

        $maxRate = $blocks[count($blocks) - 1]->rate;

        // Mức "giảm" = mức thấp nhất **LỚN HƠN 0**, và chỉ khi có từ hai mức
        // phân biệt trở lên. Đơn một mức không đánh dấu gì — インボイス
        // 適格簡易請求書 §1.3.2 yêu cầu ※ để phân biệt hai tầng, nên một tầng
        // thì không có gì để phân biệt.
        //
        // VÌ SAO PHẢI LOẠI MỨC 0 (#2086): 非課税 / 免税 KHÔNG PHẢI 軽減税率.
        // Đó là hai chế độ thuế khác hẳn — Peppol còn tách hẳn thành hai loại
        // (Z/E so với S). Coi 0% là "mức giảm" làm chân phiếu in
        // 「※は軽減税率対象」 cho một món 非課税, tức một TUYÊN BỐ SAI trên
        // chứng từ thuế.
        //
        // Lỗi này chỉ tới được giấy sau #2069 (nhóm 0% bắt đầu vào sổ). Trước
        // đó nó bị che vì bảng thuế không bao giờ có dòng 0%.
        $positiveRates = array_values(array_filter(
            $blocks,
            static fn (ReceiptTaxBlock $b): bool => $b->rate > 0.0,
        ));
        $reducedCandidate = $positiveRates === [] ? null : $positiveRates[0]->rate;
        $hasReduced = $reducedCandidate !== null && $reducedCandidate < $maxRate;

        return new self(
            blocks: array_values($blocks),
            hasReduced: $hasReduced,
            reducedRate: $hasReduced ? $reducedCandidate : 0.0,
            maxRate: $maxRate,
        );
    }

    /** Không dòng nào mang snapshot mức thuế — xem doc của {@see fromBreakdown()}. */
    public static function empty(): self
    {
        return new self(blocks: [], hasReduced: false, reducedRate: 0.0, maxRate: 0.0);
    }

    public function isEmpty(): bool
    {
        return $this->blocks === [];
    }

    /**
     * Dòng này có mang dấu ※ không.
     *
     * Sự thật được định nghĩa (§1.3.2): mức của dòng **lớn hơn 0**, **có mặt**
     * và **thấp hơn hẳn** mức cao nhất của đơn. Nên 8% được đánh dấu khi có
     * dòng 10% đứng cạnh; đơn một mức không đánh dấu gì.
     *
     * Mức 0 KHÔNG BAO GIỜ được đánh dấu (#2086): 非課税/免税 không phải
     * 軽減税率, và ※ trỏ tới chú thích 「※は軽減税率対象」 — dán nó lên một món
     * 非課税 là tuyên bố sai trên chứng từ thuế.
     *
     * `null` = dòng không có snapshot mức (đơn cũ) ⇒ không đánh dấu.
     */
    public function isReducedLine(?float $lineRate): bool
    {
        if ($lineRate === null || $lineRate <= 0.0 || $this->isEmpty()) {
            return false;
        }

        return $lineRate < $this->maxRate;
    }
}
