<?php

namespace App\Http\Resources;

use App\Models\CustomerOrder;
use App\Models\ShopOrderSetting;
use App\Services\Customer\OrderPricingCalculator;
use App\Services\Order\Enums\OrderSplitMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The single place the kiosk order JSON shape is produced — so the by-table
 * and by-code reads of GET /api/v1/kiosk/orders are byte-identical. Money for a
 * SUBMITTED order is read from its PERSISTED breakdown (`$order->total_amount`
 * etc.) — the exact figure the customer agreed to and that customer-web shows —
 * so the two surfaces can't disagree (#501). Only a still-open dine-in order
 * (tax/service not yet stamped) is priced live from the branch config.
 *
 * @property-read CustomerOrder $resource
 */
class KioskOrderResource extends JsonResource
{
    public function __construct(
        CustomerOrder $resource,
        private readonly string $currency,
        private readonly ?string $tableId,
        private readonly ?string $tableName,
    ) {
        parent::__construct($resource);
    }

    /**
     * Resolve an order item's product image URL.
     *
     * Prefers the main gallery image (`galleryFirst`) and falls back to the
     * `thumbnail` collection — the same precedence customer-web uses
     * (CustomerOrderSummaryResource::resolveItemImageUrl). Note this reads via
     * File::getUrl(); the old `->url` property never existed on the File model
     * so it always serialized `null` and the kiosk bill showed grey placeholders.
     */
    private function resolveItemImageUrl($item): ?string
    {
        $product = $item->productSku?->product;
        if (! $product) {
            return null;
        }

        if ($product->relationLoaded('galleryFirst') && $product->galleryFirst) {
            return $product->galleryFirst->getUrl();
        }

        if ($product->relationLoaded('thumbnail') && $product->thumbnail) {
            return $product->thumbnail->getUrl();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;

        $setting = ShopOrderSetting::where('branch_id', $order->branch_id)->first();

        // Money source depends on where the order is in its life:
        //
        //  - OPEN / DINING (a dine-in order still being built at the table):
        //    tax + service are only stamped at checkout, so the stored breakdown
        //    is 0. Price it LIVE from the branch config so the running bill shows
        //    the fee it will incur (matches the split-by-items preview).
        //
        //  - Everything else (a SUBMITTED takeaway/spot order awaiting counter
        //    payment, or already finalized): the total is LOCKED — it is exactly
        //    what the customer agreed to and what every customer-web surface shows
        //    via `$order->total_amount`. #501 — re-pricing these with the CURRENT
        //    shop rates made the kiosk charge a different number than the customer
        //    saw (e.g. 2,200đ → 2,298đ when the order's stored breakdown didn't
        //    match a fresh recompute). Read the stored breakdown so the two
        //    surfaces can never disagree.
        // plan-043 unified this: OrderPricingCalculator::forOrder() returns a
        // PricingResult for BOTH live and finalized orders — finalized statuses
        // freeze the scalar totals to the stored (checkout-time) values so a
        // later rate change never retro-prices a locked order (#501), while the
        // per-rate breakdown stays exposed for the receipt.
        $pricing = app(OrderPricingCalculator::class)->forOrder($order, $setting);

        return [
            'id' => $order->id,
            'table_id' => $this->tableId,
            'table_name' => $this->tableName,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                // Single resolver (CustomerOrderItem::menu_item_name):
                // SKU name → localized product name → '' ; guard '(unknown)'.
                'name' => $item->menu_item_name ?: '(unknown)',
                // Always the locale-resolved product name (vi→ja→en→any) so the
                // client can show it regardless of the SKU label.
                'product_name' => $item->productSku?->product?->localizedName(),
                'sku_code' => $item->productSku?->sku,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'image_url' => $this->resolveItemImageUrl($item),
                'extras' => $item->orderItemToppings->map(fn ($t) => [
                    'label' => $t->toppingGroupItem?->product?->name,
                    'price' => (int) $t->unit_price,
                    // Surface stacked qty so the kiosk receipt renders
                    // "5 x Egg ¥500" for max_qty_per_item > 1 toppings —
                    // pre-fix this resource omitted the field and the kiosk
                    // fell back to qty=1 (mismatch with workstation print).
                    // Matches CustomerOrderItemResource.php:72.
                    'quantity' => (int) $t->quantity,
                    // BackedEnum on the model — unwrap so the kiosk client
                    // sees the raw string (matches CustomerOrderItemResource).
                    'modifier_type' => $t->toppingGroupItem?->toppingGroup?->modifier_type instanceof \BackedEnum
                        ? $t->toppingGroupItem->toppingGroup->modifier_type->value
                        : $t->toppingGroupItem?->toppingGroup?->modifier_type,
                ])->values(),
            ])->values(),
            'subtotal' => $pricing->subtotal,
            'discount' => $pricing->discount,
            'tax_amount' => $pricing->taxAmount,
            'service_charge' => $pricing->serviceCharge,
            'total' => $pricing->totalAmount,
            // plan-043 — per-rate breakdown so the kiosk bill can show
            // 8%対象 / 10%対象 blocks (additive; old clients ignore it).
            'tax_breakdown' => $pricing->groupsToArray(),
            // plan-043 audit B-I.1 (2026-07-14) — 総額表示 mode so the kiosk
            // labels the tax line 税込/税抜 (parity with the workstation kiosk
            // shape, which already ships this). Snapshot on the order.
            'is_tax_included' => (bool) $order->is_tax_included,
            'paid_amount' => (float) $order->paid_amount,
            'currency' => $this->currency,
            // plan-043 T6.2 — legacy branch tax_rate dropped; the kiosk reads
            // per-rate figures from tax_breakdown above.
            'service_charge_rate' => (float) ($setting?->service_charge_rate ?? 0),
            // #377 — `split_mode` set on customer-web payment view skips
            // the kiosk chooser. `split_mode_locked` flips true after the
            // first payment so the kiosk can't reset the mode mid-flow.
            'split_mode' => $order->split_mode,
            'split_mode_locked' => (float) $order->paid_amount > 0,
            // plan-039 follow-up — when split_mode === "even" AND
            // the customer-web counter-pay UI pre-declared the headcount,
            // these two fields let the kiosk skip /split/people entirely
            // and jump straight to /split/method with the per-person
            // amount already displayed. NULL when the customer didn't
            // pre-declare (kiosk falls back to its existing chooser).
            'split_people_count' => $order->split_people_count,
            'amount_per_person' => $order->split_mode === OrderSplitMode::Even->value && $order->split_people_count
                ? round((float) $pricing->totalAmount / (int) $order->split_people_count, 2)
                : null,
        ];
    }
}
