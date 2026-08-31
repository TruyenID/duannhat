<?php

namespace App\Services\Order\Contracts;

use App\Services\DomainMutation\AggregateSnapshot;
use Carbon\CarbonInterface;

/**
 * Read-only projection of an order for modules OUTSIDE Ordering (#1544).
 *
 * The accessor list is deliberately the exact set another module was measured
 * to use — nothing added "for completeness". Payments reads eight scalars and
 * nothing else: no line items, no customer, not even the currency (that comes
 * from ShopOrderSetting by branch, never from the order).
 *
 * Keeping it to what is actually used is the point of a port. An interface that
 * mirrors the whole model is the model with extra steps, and it invites the
 * next caller to reach for a field the owner never meant to publish.
 */
interface OrderSnapshot extends AggregateSnapshot
{
    public function branchId(): string;

    public function status(): string;

    /** Null when the branch is not attached to a brand (half-configured shop). */
    public function brandId(): ?string;

    /** Human-facing order number, used in gateway metadata and logs. */
    public function orderCode(): string;

    public function totalAmount(): float;

    public function paidAmount(): float;

    /**
     * Hạn thanh toán của đơn — null khi đơn không đặt hạn.
     *
     * #1594 — thêm vì đường mint QR PayPay ĐO ĐƯỢC là đọc nó ở hai chỗ, và cả
     * hai đều là quyết định về tiền: R24 từ chối mint khi cửa sổ đã đóng, và
     * `clampToOrderDeadline` không cho mã sống lâu hơn đơn nó thu hộ. Không
     * thêm "cho đủ bộ" — bỏ nó ra thì Payments phải cầm lại `CustomerOrder`
     * chỉ để đọc một cột.
     *
     * Trả `CarbonInterface` chứ không phải `DateTimeImmutable`: người gọi so nó
     * với `now()`, và chỉ Carbon mới tôn trọng `Carbon::setTestNow()` — đổi
     * kiểu ở đây là làm mọi test đóng băng đồng hồ nói dối.
     */
    public function paymentDueAt(): ?CarbonInterface;
}
