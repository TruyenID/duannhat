<?php

namespace App\Http\Resources\Customer;

use App\Models\ShopOrderSetting;
use App\Services\Customer\OrderPricingCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerOrderDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // plan-043 T1.16 — per-rate breakdown from the pricing engine (§8),
        // built from the order's immutable line snapshots. Authoritative
        // (matches order.tax_amount), not a per-line sum.
        $setting = ShopOrderSetting::where('branch_id', $this->branch_id)->first();
        $pricing = app(OrderPricingCalculator::class)->forOrder($this->resource, $setting);

        $total = (float) $this->total_amount;
        $paid = (float) $this->paid_amount;
        $remaining = max(0, $total - $paid);

        return [
            'id' => $this->id,
            'order_code' => $this->order_code,
            // Alias of `order_code`. The account order screens render the same
            // components as the guest ones, and the guest payload
            // (CustomerOrderController::formatOrder) names it `code`. Aliasing
            // here is what lets one component read one contract instead of two
            // screens carrying two shapes of the same order.
            'code' => $this->order_code,
            'order_type' => $this->order_type,
            'status' => $this->status,
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            // One coupon per order — shown on the money summary as
            // "Giảm giá (mã XXX)" instead of a bare discount line.
            'coupon_code_snapshot' => $this->coupon_code_snapshot,
            'service_charge' => (float) $this->service_charge,
            'tax_amount' => (float) $this->tax_amount,
            // plan-043 — tax mode snapshot + per-rate breakdown for インボイス
            // display (8%対象 / 10%対象 blocks).
            'is_tax_included' => (bool) $this->is_tax_included,
            'tax_breakdown' => $pricing->groupsToArray(),
            'total_amount' => $total,
            'paid_amount' => $paid,
            'total_tip' => (float) $this->total_tip,
            // Guest-payload aliases + derived payment state, so the shared
            // order-detail components read one contract on both screens.
            'total' => $total,
            'paid' => $paid,
            'remaining' => $remaining,
            'is_fully_paid' => $remaining <= 0 && $paid > 0,
            'payment_count' => $this->whenLoaded('payments', fn () => $this->payments->count(), 0),
            // plan-031 — takeaway payment countdown. `seconds_until_due` is a
            // SERVER delta so the countdown cannot drift on a device with a
            // skewed clock; `is_payment_overdue` is the authoritative "no longer
            // payable" flag the UI gates its Pay button on.
            'payment_due_at' => $this->payment_due_at?->toISOString(),
            'seconds_until_due' => $this->payment_due_at
                ? max(0, (int) floor(now()->diffInSeconds($this->payment_due_at, false)))
                : null,
            'is_payment_overdue' => $this->payment_due_at && $this->payment_due_at->isPast(),
            // Placed-at. Named `opened_at` below too; both stay, since dropping
            // either would break a client that reads only one of them.
            'placed_at' => $this->opened_at?->toISOString(),
            // Currency the order was created in — customer-web formats money with
            // this, not the ambient selected-branch currency.
            'currency' => (string) $this->whenLoaded('branch', fn () => $this->branch?->currency ?? 'JPY', 'JPY'),
            'note' => $this->note,
            'guest_count' => $this->guest_count,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'checkout_at' => $this->checkout_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            // Kitchen prep timing — FE dùng để hiển thị ETA chính xác.
            // Priority: actual_ready_time > estimated_ready_time >
            // opened_at + preparation_minutes > heuristic fallback.
            //
            // Serialized as UTC ("…Z") to match the create + status endpoints
            // for these same three fields. toIso8601String() renders the
            // APP-timezone wall-clock with a +09:00 suffix, which a client that
            // ignores the offset re-shifts by its own zone — so the pickup time
            // on this history screen drifted against the order/status screens.
            'scheduled_pickup_time' => $this->scheduled_pickup_time?->toISOString(),
            'estimated_ready_time' => $this->estimated_ready_time?->toISOString(),
            'actual_ready_time' => $this->actual_ready_time?->toISOString(),
            'preparation_minutes' => $this->preparation_minutes,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'slug' => $this->branch->slug,
            ]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => self::formatItem($item))),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'tip_amount' => (float) ($payment->tip_amount ?? 0),
                'status' => $payment->status,
                'payment_method' => $payment->paymentMethod?->name,
                'created_at' => $payment->created_at?->toIso8601String(),
            ])),
            'tables' => $this->whenLoaded('tables', fn () => $this->tables->map(fn ($table) => [
                'id' => $table->id,
                'name' => $table->name,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * One order line, in the shape the guest detail screen already consumes.
     *
     * Deliberately a superset of what this resource used to emit: `product_name`
     * / `quantity` stay (clients read them), and `name` / `qty` / `image_url` /
     * `variant` / `options` join them so the account screen can render the same
     * components as the guest one. Mirrors
     * `CustomerOrderController::formatItem()` — the two are a pair; a field
     * added to one belongs in the other.
     *
     * @return array<string, mixed>
     */
    private static function formatItem($item): array
    {
        $sku = $item->productSku;
        $product = $sku?->product;

        $productName = $product?->name;
        $skuName = $sku?->name;
        // Only a SKU name that says something the product name does not is a
        // variant worth showing ("Large", not a second copy of the dish name).
        $variant = ($skuName && $skuName !== $productName) ? $skuName : null;

        // Topping name resolution: the topping itself is a Product (its
        // ToppingGroupItem is a junction row with no name column of its own).
        // Prefer the picked SKU's product name, fall back to the group item's
        // product, then to the topping's note as a last-ditch label.
        $options = $item->relationLoaded('orderItemToppings')
            ? $item->orderItemToppings->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->productSku?->product?->name
                    ?? $t->toppingGroupItem?->product?->name
                    ?? $t->note,
                'unit_price' => (float) $t->unit_price,
                'quantity' => (int) $t->quantity,
                'product_sku_id' => $t->product_sku_id,
            ])->values()->all()
            : [];

        return [
            'id' => $item->id,
            'product_sku_id' => $item->product_sku_id,
            'product_name' => $productName,
            'name' => $productName ?? $skuName,
            // SKU photo first (a variant may have its own), then the product's.
            'image_url' => $sku?->galleryFirst?->getUrl()
                ?? $product?->galleryFirst?->getUrl()
                ?? $product?->thumbnail?->getUrl(),
            'variant' => $variant,
            'quantity' => (float) $item->quantity,
            'qty' => (int) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'subtotal' => (float) $item->subtotal,
            'status' => $item->status,
            'note' => $item->note,
            'options' => $options,
            // plan-043 — per-line tax snapshot (for the ※ reduced-rate
            // marker + per-rate rendering on customer-web).
            'tax_type_id' => $item->tax_type_id,
            'tax_rate' => $item->tax_rate !== null ? (float) $item->tax_rate : null,
            'tax_amount' => (float) $item->tax_amount,
        ];
    }
}
