<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use Carbon\CarbonImmutable;

/**
 * plan-053 T5.1d (#1925) — một dòng món như tầng in nhìn thấy nó.
 *
 * Tập trường ĐO ĐƯỢC từ cả hai phía tiêu thụ, không phải mirror `Item` của
 * workstation (struct đó có 20 trường; tầng in đọc 10):
 *
 *   printRunnerItem      → MenuItemName · Quantity · UnitPrice
 *   printVariantLine     → Note · SkuVariantName
 *   printToppingLines    → Toppings[]
 *   isReducedLine        → TaxRate
 *   lọc dòng đã void     → VoidedAt · Status
 *   itemTaxableSubtotal  → ToppingSubtotal
 *
 * ── `taxRate` là NULLABLE, và null KHÁC 0 ────────────────────────────────
 *
 * `null` = dòng chưa được đóng dấu mức thuế (đơn cũ, trước plan-043). `0.0` =
 * 非課税, một trong ba loại thuế hợp lệ. Gộp hai cái này là lỗi mà #1128 vừa
 * chặn ở đường pull: một dòng chưa đóng dấu sẽ hiện ra như 非課税 và không ai
 * nhìn thấy.
 *
 * `isReducedLine()` của {@see ReceiptTaxSummary} vì thế nhận `?float`, và trả
 * `false` cho null thay vì so sánh.
 */
final class PrintRenderItem
{
    /** @param list<PrintRenderTopping> $toppings */
    public function __construct(
        public readonly string $menuItemName,
        public readonly int $quantity,
        public readonly int $unitPrice,
        public readonly string $skuVariantName = '',
        public readonly string $note = '',
        public readonly array $toppings = [],
        /** Tổng tiền topping đã chốt. 0 ⇒ cộng lại từ `toppings`, đúng như Go. */
        public readonly int $toppingSubtotal = 0,
        /** null = CHƯA đóng dấu mức (đơn cũ). 0.0 = 非課税. Xem doc class. */
        public readonly ?float $taxRate = null,
        public readonly ?CarbonImmutable $voidedAt = null,
        public readonly string $status = '',
    ) {}

    /**
     * Dòng này có bị loại khỏi phiếu và khỏi mọi phép cộng không.
     *
     * Hai điều kiện, không phải một: Go kiểm `VoidedAt != nil` **hoặc**
     * `Status == voided`. Giữ cả hai — một dòng void qua đường trạng thái mà
     * chưa kịp đóng dấu thời điểm vẫn phải biến mất khỏi giấy.
     */
    public function isVoided(): bool
    {
        return $this->voidedAt !== null || $this->status === 'voided';
    }

    /**
     * Cơ sở chịu thuế của dòng: qty × đơn giá + tiền topping.
     *
     * Dùng `toppingSubtotal` đã chốt khi có, ngược lại cộng lại từ `toppings` —
     * đúng thứ tự ưu tiên của `itemTaxableSubtotal` bên Go.
     *
     * ⚠️ KHÔNG dùng nó để tính thuế. Số thuế in ra đến từ snapshot bất biến qua
     * `OrderTaxBreakdownReads` ({@see ReceiptTaxSummary}); hàm này chỉ phục vụ
     * những chỗ cần biết cơ sở của MỘT dòng.
     */
    public function taxableSubtotal(): int
    {
        $base = $this->unitPrice * $this->quantity;

        if ($this->toppingSubtotal !== 0) {
            return $base + $this->toppingSubtotal;
        }

        foreach ($this->toppings as $topping) {
            $base += $topping->unitPrice * $topping->quantity;
        }

        return $base;
    }
}
