<?php

namespace App\Services\Order\ValueObjects;

use App\Omnify\Enums\OrderItemPriceSourceEnum;
use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class OrderLineEvidence implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public ?string $menuId;

    public ?string $menuProductId;

    public ?string $menuProductSkuId;

    public ?string $taxTypeId;

    public ?string $promotionId;

    /**
     * #2411 — `$taxRateBasisPoints` KHÔNG nullable: 0% là một tỉ lệ (非課税), còn
     * "chưa biết" thì không phải trạng thái hợp lệ của một dòng đã bán. Cột
     * `customer_order_items.tax_rate` là NOT NULL, và cả ba chỗ dựng DTO này
     * (`OfflineOrderEvidenceVerifier`, hai lối của `CustomerOrderPricingResolution`)
     * vốn đã truyền `(int) round($rate * 100)`. Kiểu chặn tại chỗ dựng nên đường
     * persist không cần một nhánh `?? null` — thứ mà ruling #2188 cấm.
     *
     * #2618 (ruling #2132 §B) — `$priceSource` là nguồn đã QUYẾT giá cuối của
     * dòng, do resolver đóng dấu tại chính chỗ precedence chạy (menu|sku_base →
     * floating CHỈ KHI thấp hơn → menu_promotion). Nullable vì evidence cũng
     * được dựng cho các đường KHÔNG chạy precedence của Cloud; hai producer
     * hiện có (live resolver, offline verifier) luôn truyền non-null.
     */
    public function __construct(?string $menuId, ?string $menuProductId, ?string $menuProductSkuId, ?string $taxTypeId, public ?int $originalUnitPriceMinor, public int $taxRateBasisPoints, public int $taxAmountMinor, ?string $promotionId = null, public ?string $promotionCode = null, public int $promotionDiscountMinor = 0, public int $lineSubtotalMinor = 0, public int $toppingSubtotalMinor = 0, public ?OrderItemPriceSourceEnum $priceSource = null)
    {
        $this->menuId = MutationCommand::nullableUuid($menuId, 'menuId');
        $this->menuProductId = MutationCommand::nullableUuid($menuProductId, 'menuProductId');
        // Nullable since #1090 phase 2: an off-menu SKU (legacy fallback to
        // the SKU's own selling price) has no menu row to cite as evidence.
        $this->menuProductSkuId = MutationCommand::nullableUuid($menuProductSkuId, 'menuProductSkuId');
        $this->taxTypeId = MutationCommand::nullableUuid($taxTypeId, 'taxTypeId');
        $this->promotionId = MutationCommand::nullableUuid($promotionId, 'promotionId');
        if (($originalUnitPriceMinor !== null && $originalUnitPriceMinor < 0) || $taxAmountMinor < 0 || $promotionDiscountMinor < 0 || $lineSubtotalMinor < 0) {
            throw new \InvalidArgumentException('Order line monetary evidence cannot be negative.');
        }

        // Discount toppings may pull the topping subtotal NEGATIVE, but the
        // legacy floor is law: a discount can zero a line, never pay the
        // customer. qty x (unit + topping) >= 0 ⇔ line + topping evidence >= 0.
        if ($lineSubtotalMinor + $toppingSubtotalMinor < 0) {
            throw new \InvalidArgumentException(sprintf(
                'Topping discount %d exceeds the line subtotal %d — a discount can zero a line, never pay the customer.',
                $toppingSubtotalMinor,
                $lineSubtotalMinor,
            ));
        }
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
