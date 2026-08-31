<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerOrderSummaryResource extends JsonResource
{
    /**
     * Resolve image URL from product's galleryFirst or thumbnail.
     */
    private function resolveItemImageUrl($item): ?string
    {
        $product = $item->productSku?->product;
        if (! $product) {
            return null;
        }

        // Prefer galleryFirst (main gallery image)
        if ($product->relationLoaded('galleryFirst') && $product->galleryFirst) {
            return $product->galleryFirst->getUrl();
        }

        // Fallback to thumbnail
        if ($product->relationLoaded('thumbnail') && $product->thumbnail) {
            return $product->thumbnail->getUrl();
        }

        return null;
    }

    public function toArray(Request $request): array
    {
        $total = (float) $this->total_amount;
        $paid = (float) $this->paid_amount;

        return [
            'id' => $this->id,
            'code' => $this->order_code, // plan-031: add 'code' for frontend compatibility
            'order_code' => $this->order_code,
            'order_type' => $this->order_type,
            'status' => $this->status,
            'placed_at' => $this->opened_at?->toIso8601String(), // plan-031: frontend expects placed_at (= opened_at)
            'total_amount' => $total,
            'total' => $total, // plan-031: add 'total' for frontend compatibility
            // Currency the order was created in — customer-web formats money with
            // this, not the ambient selected-branch currency.
            'currency' => (string) $this->whenLoaded('branch', fn () => $this->branch?->currency ?? 'JPY', 'JPY'),
            'paid_amount' => $paid,
            'paid' => $paid, // plan-031: frontend expects 'paid' field
            'item_count' => $this->items->count(),
            'is_fully_paid' => $paid >= $total && $paid > 0, // plan-031: payment status
            'payment_count' => $this->whenLoaded('payments', fn () => $this->payments->count(), 0),
            'payment_due_at' => $this->payment_due_at?->toIso8601String(), // plan-031: countdown
            // Server delta for the skew-immune countdown (matches CustomerOrderResource).
            'seconds_until_due' => $this->payment_due_at
                ? max(0, now()->diffInSeconds($this->payment_due_at, false))
                : null,
            // Authoritative "no longer payable" flag. Without this the list page's
            // isServerConfirmedExpired() can never flip an expired order's action from
            // "Thanh toán" to "Đặt lại" — the cosmetic countdown badge shows 「Hết hạn」
            // but the button stays a pay button. Mirror CustomerOrderResource exactly.
            'is_payment_overdue' => $this->payment_due_at && $this->payment_due_at->isPast(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_sku_id' => $item->product_sku_id, // plan-031: needed for reorder
                'name' => $item->productSku?->product?->name ?? $item->menu_item_name,
                'image_url' => $this->resolveItemImageUrl($item),
                'qty' => (int) $item->quantity, // plan-031: frontend expects 'qty' not 'quantity'
                'status' => $item->status, // plan-031: needed to derive kitchen status (pending/preparing/ready/served)
            ])),
            // #1758 — đơn đã được đánh giá chưa. Card lịch sử dùng cái này để
            // đổi nút "Viết đánh giá" thành trạng thái "Đã đánh giá": bấm lại
            // chỉ dẫn tới một trang review không còn gì để gửi (trang tự lọc
            // món `already_reviewed`, còn branch-review thì BE trả 422).
            //
            // Tính theo ĐƠN, không theo từng món: khách chấm sao chi nhánh mà
            // bỏ qua món (hoặc ngược lại) đều là đã đánh giá đơn đó — trang
            // review vốn cho gửi một trong hai là đủ.
            //
            // Đọc từ `withExists()` chứ không tự query: list trả tới 20 đơn một
            // trang, một lazy-load ở đây là 40 câu truy vấn thêm. Query nào
            // quên `withExists` thì rơi về `false` — thà mời khách đánh giá
            // thừa một lần còn hơn nuốt N+1 vào đường đọc nóng nhất của khách.
            'is_reviewed' => (bool) ($this->product_reviews_exists ?? false)
                || (bool) ($this->branch_reviews_exists ?? false),
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'slug' => $this->branch->slug,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
