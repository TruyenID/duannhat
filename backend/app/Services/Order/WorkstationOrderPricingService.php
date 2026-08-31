<?php

namespace App\Services\Order;

use App\Models\CustomerOrder;
use App\Services\Order\ValueObjects\CouponDiscountTerms;
use App\Services\Product\Contracts\BranchSkuPricing;
use App\Services\Promotion\Contracts\MenuPromotionResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Plan-047 thin-controller/fat-service — the server-side money resolution the
 * workstation order-lifecycle sync must own (a LAN client is untrusted).
 * Extracted from Api/V1/Workstation/OrderLifecycleController. Holds no request
 * state; the controller passes the order + validated rows in.
 */
class WorkstationOrderPricingService
{
    public function __construct(
        private readonly MenuPromotionResolver $promotions,
        private readonly BranchSkuPricing $skuPricing,
    ) {}

    /**
     * plan-040 H17: resolve the authoritative unit_price per line SERVER-SIDE.
     * Throws a 422 for an unknown/non-sellable SKU and logs whenever a
     * client-supplied price is overridden so tampering is auditable.
     *
     * The MenuProductSku lookup is scoped to the ORDER'S branch menu (not a
     * global is_active lookup that is non-deterministic for a SKU shared across
     * menus), then matches CustomerOrderService::addItems — the Cloud cashier
     * path — by falling back to `$sku->selling_price` for an off-menu SKU instead
     * of 422'ing a legitimately off-menu workstation sale. The client-supplied
     * price is ignored/overridden either way, and the same active MenuPromotion
     * the Cloud path applies is applied here so a synced promo line is not
     * re-inflated to the full menu price.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, float> keyed by the item index
     */
    public function resolveAuthoritativeItemPrices(CustomerOrder $order, array $items): array
    {
        $prices = [];

        foreach ($items as $idx => $row) {
            $sku = $this->skuPricing->forBranch((string) $order->branch_id, (string) $row['product_sku_id']);
            if ($sku === null) {
                throw ValidationException::withMessages([
                    "items.{$idx}.product_sku_id" => 'Unknown product SKU.',
                ]);
            }

            if (! $sku->isSellable) {
                throw ValidationException::withMessages([
                    "items.{$idx}.product_sku_id" => 'Cannot sync a line for a non-sellable product.',
                ]);
            }

            // Off-menu SKU → fall back to the raw ProductSku price (mirrors the
            // Cloud addItems path) rather than rejecting the line.
            $authoritative = $sku->effectivePrice();

            // Apply the SAME active MenuPromotion the Cloud cashier path applies.
            // Without this, syncing an order item down from the workstation
            // re-priced it to the FULL menu price and STRIPPED the Happy Hour
            // discount the customer already saw.
            $promotion = $this->promotions->activeFor(
                $order->branch_id,
                $sku->productId,
                $sku->categoryIds,
            );
            if ($promotion !== null) {
                $authoritative = round(
                    $authoritative * (100 - $promotion->discountPercent) / 100,
                    2,
                    PHP_ROUND_HALF_UP,
                );
            }

            if (isset($row['unit_price']) && (float) $row['unit_price'] !== $authoritative) {
                Log::warning('workstation.addItems: client unit_price overridden', [
                    'order_id' => (string) $order->id,
                    'product_sku_id' => $sku->skuId,
                    'client_unit_price' => (float) $row['unit_price'],
                    'authoritative_unit_price' => $authoritative,
                ]);
            }

            $prices[$idx] = $authoritative;
        }

        return $prices;
    }

    /**
     * Compute the coupon discount against a subtotal, capped at the subtotal.
     *
     * Backend's CouponDiscountTypeEnum uses 'fixed' + 'percent'; the workstation
     * replica stores the same values now (PullCoupons normalizes the legacy
     * 'flat' alias before INSERT). 'flat' is accepted here only to handle replay
     * of historical queue rows enqueued before that normalization landed.
     *
     * Takes the coupon's TERMS, not the `Coupon` row (epic #962): the row is
     * Pricing's, and nothing here ever read more than these three fields. The
     * enum-vs-string normalisation moved verbatim into
     * {@see CouponDiscountTerms::of}.
     */
    public function computeCouponDiscount(CouponDiscountTerms $coupon, float $subtotal): float
    {
        switch ($coupon->discountType) {
            case 'fixed':
            case 'flat':
                return min($coupon->discountValue, $subtotal);
            case 'percent':
                $raw = $subtotal * $coupon->discountValue / 10000.0;
                // Cloud column is `max_discount_cap`; workstation replica mirrors
                // the same name now. Guard against either being null.
                if ($coupon->maxDiscountCap !== null) {
                    $raw = min($raw, $coupon->maxDiscountCap);
                }

                return min($raw, $subtotal);
            default:
                return 0.0;
        }
    }
}
