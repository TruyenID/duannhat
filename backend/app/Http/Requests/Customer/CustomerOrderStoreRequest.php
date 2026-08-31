<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CustomerOrderStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_sku_id' => ['required', 'uuid', 'exists:product_skus,id'],
            // #514 — exact menu line the customer saw. Optional (older clients
            // omit it → server falls back to a menu price for the SKU), but when
            // present the order is charged THAT line's price so the displayed and
            // charged prices match even when a SKU sits in several menu lines.
            'items.*.menu_product_sku_id' => ['nullable', 'uuid', 'exists:menu_product_skus,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.note' => ['nullable', 'string'],

            // #1715 — giá MỘT ĐƠN VỊ mà client đang hiển thị cho dòng này.
            // Nullable: client cũ bỏ qua thì đặt đơn y như trước.
            //
            // Server KHÔNG BAO GIỜ tính theo con số này — nó chỉ dùng để TỪ CHỐI.
            // Khung giờ ưu đãi đóng lúc 20:00 mà món đã nằm trong giỏ từ 19:59 thì
            // giỏ vẫn hiện giá khuyến mãi, còn server định giá lại ra giá thường:
            // khách thấy một giá, bị tính một giá khác, và không màn nào của
            // customer-web hỏi server trước khi commit. Gửi kèm giá đang hiển thị
            // cho phép server chặn đúng lúc đó.
            //
            // Cùng khuôn với `expected_total_amount` của plan-007 split-bill
            // (`OrderPaymentService::…` → 422 `split_bill_total_drift`): fail-CLOSED,
            // client vẫn không có đường nào quyết giá.
            'items.*.expected_unit_price' => ['nullable', 'numeric', 'min:0'],

            // Plan 015 — topping selections per item. Group-level rules
            // (effective_min/max_select, max_qty_per_item, group_attached) are
            // enforced inside CustomerOrderService::validateAndPriceToppings
            // because they depend on the parent product's ProductToppingGroup
            // pivot, which FormRequest validators can't see cleanly.
            // Without these five rules, $request->validated() strips toppings
            // before they reach the service and the bill prints without them.
            'items.*.toppings' => ['nullable', 'array'],
            'items.*.toppings.*.topping_group_item_id' => ['required_with:items.*.toppings.*', 'string', 'exists:topping_group_items,id'],
            'items.*.toppings.*.product_sku_id' => ['required_with:items.*.toppings.*', 'string', 'exists:product_skus,id'],
            'items.*.toppings.*.quantity' => ['required_with:items.*.toppings.*', 'integer', 'min:1'],
            'items.*.toppings.*.note' => ['nullable', 'string', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'customer_email' => ['nullable', 'string', 'email', 'max:255'],
            'customer_takeaway_name' => ['nullable', 'string', 'max:255'],
            'customer_takeaway_phone' => ['nullable', 'string', 'max:50'],
            'customer_takeaway_email' => ['nullable', 'string', 'email', 'max:255'],
            'note' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string', 'in:counter,transfer,call_staff,qr_pay,card'],
            'payment_timing' => ['nullable', 'string', 'in:before,after'],

            // Pickup time fields (takeaway only).
            'pickup_type' => ['nullable', 'string', 'in:immediate,scheduled'],
            'scheduled_pickup_time' => [
                'nullable',
                'date',
                'after:now',
                'required_if:pickup_type,scheduled',
            ],

            // Plan-019 — optional coupon code applied atomically inside the
            // order-create transaction via CouponService::apply. FE has
            // already previewed it; if it fails here the whole order
            // creation rolls back (422 CouponException with error_code).
            'coupon_code' => ['nullable', 'string', 'max:50'],

            // Plan-019 — "Use coupon instead of promotion" CTA. When the
            // customer's cart has exclusive-with-coupons HH items and
            // they pick the coupon over the promo discount, FE sets this
            // flag and backend reverts those lines to original_unit_price
            // before applying the coupon.
            'downgrade_exclusive_promotions' => ['nullable', 'boolean'],
        ];
    }
}
