<?php

declare(strict_types=1);

namespace App\Services\Order\Coupon;

use App\Exceptions\CouponException;
use App\Models\CustomerOrder;
use App\Models\User;
use App\Omnify\Enums\CouponStatusEnum;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\Customer\CustomerOrderService;
use App\Services\Order\Commands\ApplyWorkstationOrderCouponCommand;
use App\Services\Order\Commands\BindOrderCouponCommand;
use App\Services\Order\Commands\DowngradeExclusivePromotionsCommand;
use App\Services\Order\Commands\RefreshOrderPricingCommand;
use App\Services\Order\Commands\RemoveOrderCouponCommand;
use App\Services\Order\Contracts\CouponTerms;
use App\Services\Order\Contracts\OrderCouponLedger;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Internal\OrderMutationContextFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gắn và gỡ coupon TRÊN MỘT ĐƠN HÀNG (#1581, epic #962).
 *
 * Tách ra từ `App\Services\Promotion\CouponService`, vốn là HAI class trong
 * một: CRUD coupon (Pricing) và mutation đơn hàng (đây). Hệ quả đo được của việc
 * gộp: **26 trong 27 cạnh RA của Pricing** đến từ đúng một file, và chiều phụ
 * thuộc bị ngược — tầng tính toán giá phụ thuộc tầng đơn hàng.
 *
 * Giờ chiều đã đúng: lớp này (Ordering) hỏi Pricing qua `OrderCouponLedger`.
 *
 * #962 khép nốt nửa còn lại. #1581 chỉ dời phần HỎI GIÁ (`CouponPricing`), nên
 * class này vẫn tự khoá `Coupon`, tự tăng `times_used`, tự ghi/xoá
 * `CouponRedemption` — ba thao tác GHI vào bảng của Pricing. Giờ chúng đi qua
 * `OrderCouponLedger` (hợp đồng công bố), nên nửa đơn hàng không còn nhắc tên
 * `App\Models\Coupon` hay `App\Models\CouponRedemption`. Thứ tự thao tác và
 * từng câu khoá giữ NGUYÊN — xem `EloquentOrderCouponLedger`.
 *
 * ## `apply()` là writer hợp pháp DUY NHẤT của chuỗi coupon
 *
 * Thứ tự dưới đây là hợp đồng, không phải chi tiết cài đặt:
 *
 *   cổng modifiability → freshness/caps/branch eligibility → dòng redemption
 *   → recompute discount → downgrade khuyến mãi độc quyền (plan-019)
 *
 * `EloquentOrderPersistence::applyCoupon()` gọi thẳng vào đây và có comment ghi
 * rõ điều đó. **Cắt giữa chuỗi là hỏng tiền.**
 *
 * Tên method giữ NGUYÊN như bản cũ để caller không phải đổi cách gọi — chỉ đổi
 * đối tượng được tiêm.
 */
final class OrderCouponService
{
    public function __construct(
        private readonly OrderMutationFacade $orders,
        private readonly OrderCouponLedger $coupons,
    ) {}

    /**
     * ORCHESTRATOR — the whole "workstation applied a coupon offline" use case.
     *
     * #1686 (con của #1666). The two halves below used to be two calls sitting
     * side by side in `OrderLifecycleController::applyCoupon`, with the
     * controller holding the `DB::transaction` that made them one unit. That is
     * the shape ADR 0001 §4 rules out: a transaction is owned by the module
     * that owns the use case, and a delivery surface is an adapter, not a
     * module. A controller holding a consistency boundary means every other
     * caller of the same pair (a console replay, a future sync endpoint) has to
     * remember to re-open it, and half a batch applied here is money:
     * `customer_orders.coupon_id` set with NO `coupon_redemptions` row means the
     * usage cap and every coupon report are wrong, silently and permanently.
     *
     * Placed HERE, not in a new class, and not in Pricing:
     *
     *  - both halves are already this class's collaborators — `OrderMutationFacade`
     *    (Ordering's own command API) and `OrderCouponLedger` (Ordering's
     *    published gate into Pricing). So the orchestrator adds **zero** new
     *    cross-module edges; a standalone orchestrator elsewhere would add two.
     *  - `config/modules.php` puts the whole `App\Services\Order` namespace in
     *    Ordering, and Ordering owns `customer_orders` — the row the first half
     *    writes. Pricing owns `coupons` / `coupon_redemptions` and must not
     *    reach back into an order to start a use case (that is the exact
     *    reversed edge #1581 spent its budget removing).
     *  - `config/domain-mutation-guard.php` already names this file as a legal
     *    writer of `coupon_redemptions`, so the write stays inside the one file
     *    that is allowed to make it.
     *
     * Transaction SCOPE is unchanged — the same two calls, in the same order,
     * inside one transaction. `recordWorkstationRedemption()` keeps its own
     * transaction so its other callers stay safe; nested, it is a savepoint.
     */
    public function applyWorkstationCoupon(
        ApplyWorkstationOrderCouponCommand $command,
        CustomerOrder $order,
        ?string $customerId = null,
    ): void {
        DB::transaction(function () use ($command, $order, $customerId): void {
            $this->orders->applyWorkstationCoupon($command);

            // Guarded increment + CouponRedemption row. A bare
            // `$coupon->increment('times_used')` here had neither the
            // usage-limit race guard nor the redemption record that reporting
            // and per-customer limits read.
            $this->recordWorkstationRedemption(
                $command->couponId,
                $order,
                $command->discountAmount,
                $customerId,
            );
        });
    }

    /**
     * Record a redemption for a coupon a WORKSTATION applied while offline.
     *
     * The workstation resolves and prices the coupon locally, then syncs the
     * result UP, so it cannot go through `apply()` — that path re-resolves and
     * re-prices, which would contradict the receipt the customer already holds.
     * What it must NOT skip is the bookkeeping `apply()` does around the money:
     *
     *   - the usage-limit race guard, so a coupon capped at 100 uses cannot be
     *     pushed past it by concurrent syncs, and
     *   - the CouponRedemption row, which is the only record of WHO redeemed
     *     WHAT — reporting and per-customer limits both read it.
     *
     * The workstation controller previously did a bare
     * `$coupon->increment('times_used')`, which had neither.
     *
     * Idempotent by construction: `customer_order_id` is uniquely indexed on
     * coupon_redemptions, so a replayed sync no-ops instead of double-counting.
     */
    public function recordWorkstationRedemption(
        string $couponId,
        CustomerOrder $order,
        float $discount,
        ?string $customerId = null,
    ): void {
        DB::transaction(function () use ($couponId, $order, $discount, $customerId): void {
            $locked = $this->coupons->lockByIdOrFail($couponId);

            if ($this->coupons->hasRedemptionForOrder((string) $order->id)) {
                return; // replayed sync — already counted
            }

            if (! $this->coupons->claimUsage($locked) && $locked->usageLimitTotal !== null) {
                // The cap was reached between the offline sale and this sync.
                // The sale itself stands — the customer already has the goods —
                // but it is recorded as over-limit rather than silently counted.
                $order->logAudit('coupon_redeemed_over_limit', [
                    'coupon_code' => $locked->code,
                    'usage_limit_total' => $locked->usageLimitTotal,
                    'times_used' => $locked->timesUsed,
                ]);
            }

            // A concurrent sync of the same order winning the race is the same
            // outcome — but only the WINNER logs, so the audit line stays
            // one-per-redemption like the redemption row itself.
            if ($this->coupons->recordRedemption(
                $locked->id,
                (string) $order->id,
                $customerId,
                $discount,
                via: 'workstation',
            )) {
                // #2189 — từ #2154 `discount_applied_amount` bám giá trị HIỆN
                // HÀNH, nên số tiền LÚC ÁP chỉ còn sống trong audit. Đường
                // `apply()` đã ghi nó từ đầu; đường workstation thì chưa — với
                // coupon POS/offline, bản gốc mất hẳn ở lần giỏ đổi đầu tiên.
                $order->logAudit('coupon_applied', [
                    'coupon_code' => $locked->code,
                    'discount' => (string) $discount,
                    'via' => 'workstation',
                ]);
            }
        });
    }

    /**
     * Atomically apply a coupon to an order.
     *
     * Server flow per DESIGN.md endpoint #8. lockForUpdate on Coupon row
     * prevents oversell under concurrent applies (Decision 1).
     *
     * Replaces any existing coupon on the order — releases the prior one
     * inside the same transaction.
     */
    public function apply(
        CustomerOrder $order,
        string $code,
        ?string $customerId = null,
        string $via = 'pos',
        ?User $user = null,
        bool $downgradeExclusivePromotions = false,
    ): CustomerOrder {
        $this->assertOrderModifiable($order);

        // Plan-019 — when the caller passes downgradeExclusivePromotions=true,
        // skip the hard stacking guard and instead revert every line that
        // carries an exclusive-with-coupons promotion snapshot back to its
        // original unit_price BEFORE the coupon runs. This gives the FE a
        // "use coupon instead of promotion" CTA on the conflict dialog —
        // staff/customer pick which discount type they prefer.
        if (! $downgradeExclusivePromotions) {
            $this->assertNoExclusivePromotionStacking($order);
        }

        return DB::transaction(function () use ($order, $code, $customerId, $via, $user, $downgradeExclusivePromotions) {
            if ($downgradeExclusivePromotions) {
                $this->downgradeExclusivePromotions($order, $user);
                $order->refresh();
            }

            // If order already has an actively-applied coupon, release it
            // first (replace flow). releaseLocked(hardDelete: true) decrements
            // the counter, writes audit, and deletes the row so the unique
            // customer_order_id constraint is clear for the new insert.
            if ($order->coupon_id !== null) {
                $this->releaseLocked($order, hardDelete: true);
                $order->refresh();
            }

            // Sweep any orphan soft-released redemption row left behind by a
            // prior release() that took the default soft-release path
            // (released_at stamped, row preserved for audit per Decision 5).
            // Counter + audit log are already written at release time — we
            // only need to free up customer_order_id for the new insert.
            // Without this sweep, re-applying after a release blew up with a
            // UniqueConstraintViolationException that the write below misread
            // as a concurrency race and rethrew as `order_not_modifiable`.
            $this->coupons->purgeReleasedRedemptions((string) $order->id);

            $coupon = $this->coupons->lockByCode((string) $order->brand_id, $code);

            if ($coupon === null) {
                throw CouponException::notFound($code);
            }

            $now = CarbonImmutable::now();
            $this->validateForApply($coupon, $order, $customerId, $now);

            $discount = $this->coupons->computeDiscount(
                $coupon->id,
                (float) $order->subtotal,
                $this->coupons->resolveCurrencyForBranch($order->branch_id),
            );

            $this->coupons->claimUsage($coupon);

            // Race-safe redundancy: customer_order_id has a DB unique index. If
            // two concurrent applies reach this point, the loser is refused
            // here — surface as exhausted-equivalent.
            if (! $this->coupons->recordRedemption(
                $coupon->id,
                (string) $order->id,
                $customerId,
                $discount,
                via: $via,
                redeemedByUserId: $user?->id === null ? null : (string) $user->id,
                redeemedAt: $now,
            )) {
                throw CouponException::orderNotModifiable($order->status);
            }

            $this->orders->bindCoupon(new BindOrderCouponCommand(
                OrderMutationContextFactory::fromOrder($order, $user?->id),
                (string) $order->id,
                (string) $coupon->id,
                $coupon->code,
                $discount,
            ));
            $order->refresh();

            $order->logAudit('coupon_applied', [
                'coupon_code' => $coupon->code,
                'discount' => (string) $discount,
            ]);

            return $order->fresh();
        });
    }

    /**
     * Release the coupon currently bound to an order. Idempotent —
     * already-released redemptions become no-ops.
     */
    public function release(CustomerOrder $order): CustomerOrder
    {
        $this->assertOrderModifiable($order);

        if ($order->coupon_id === null) {
            throw CouponException::noCouponApplied();
        }

        return DB::transaction(function () use ($order) {
            $this->releaseLocked($order);
            $order->logAudit('coupon_released');

            return $order->fresh();
        });
    }

    /**
     * No-throw helper for void/cancel hooks. Releases iff the order
     * actually has a coupon. Closed orders are immutable per BR-COUP07
     * — caller (CustomerOrderService::voidOrder) decides whether to call
     * this; it should NOT be invoked for closed orders.
     */
    public function releaseIfApplied(CustomerOrder $order): void
    {
        if ($order->coupon_id === null) {
            return;
        }
        $this->releaseLocked($order);
    }

    /**
     * Re-derive the coupon discount for an order against its CURRENT
     * subtotal (#550). Called from CustomerOrderService::recalculateTotals
     * on every add/void/update-item so the discount tracks the live cart
     * instead of freezing at apply() time.
     *
     * Returns:
     *  - null    → order carries no coupon; caller keeps the manual-discount
     *              intent stored separately from the applied ledger amount.
     *  - 0.0     → coupon still attached but the cart dropped below its
     *              min_order_subtotal → coupon does not apply right now
     *              (re-adding items above the threshold restores it).
     *  - >0      → recomputed discount, honouring min-spend + max cap.
     *
     * The coupon's discount_value / min_order_subtotal / max_discount_cap
     * are frozen at redemption time (assertNotLocked), so the live Coupon
     * row matches the redemption snapshot for these fields.
     */
    public function recomputeDiscountForOrder(CustomerOrder $order, float $subtotal): ?float
    {
        if ($order->coupon_id === null) {
            return null;
        }

        $coupon = $this->coupons->find((string) $order->coupon_id);
        if ($coupon === null) {
            return null;
        }

        // Min-spend guard: a coupon that cleared min_order_subtotal at apply
        // time must NOT keep discounting once the cart shrinks below it —
        // otherwise a fixed coupon becomes a house giveaway that bypasses
        // the threshold silently.
        if ($subtotal < $coupon->minOrderSubtotal) {
            return 0.0;
        }

        return $this->coupons->computeDiscount(
            $coupon->id,
            $subtotal,
            $this->coupons->resolveCurrencyForBranch($order->branch_id),
        );
    }

    /**
     * Inner release path — caller already inside a transaction.
     *
     * @param  bool  $hardDelete  When true (replace flow only), the
     *                            redemption row is hard-deleted instead of just stamping
     *                            released_at. This is necessary because customer_order_id has a
     *                            DB unique constraint (Decision 4) — a fresh insert for the new
     *                            coupon on the same order would otherwise hit the unique guard.
     *                            The audit trail survives via:
     *                            - audit_logs (coupon_released event records the snapshot),
     *                            - customer_orders.coupon_code_snapshot (only the latest, but
     *                            the audit log carries the full prior chain).
     */
    protected function releaseLocked(CustomerOrder $order, bool $hardDelete = false): void
    {
        // The ledger owns the coupon side of a release (lock the redemption,
        // give the usage back, delete or stamp it) and is idempotent when the
        // order carries none; the order-side binding is cleared either way.
        $this->coupons->releaseRedemptionForOrder((string) $order->id, $hardDelete);

        $this->clearOrderCouponBinding($order);
    }

    private function clearOrderCouponBinding(CustomerOrder $order): void
    {
        $this->orders->removeCoupon(new RemoveOrderCouponCommand(
            OrderMutationContextFactory::fromOrder($order),
            (string) $order->id,
        ));
        $order->refresh();
    }

    protected function assertOrderModifiable(CustomerOrder $order): void
    {
        $allowed = [
            CustomerOrderStatusEnum::Open->value,
            CustomerOrderStatusEnum::Dining->value,
            CustomerOrderStatusEnum::Pending->value,
            // Takeaway counter-pay orders land as `confirmed` at creation
            // (customer confirmed the draft, payment not yet taken). Coupons
            // are applied atomically in that same create transaction, so this
            // pre-payment state must count as modifiable — else a takeaway
            // order carrying a coupon_code rolls back with order_not_modifiable.
            CustomerOrderStatusEnum::Confirmed->value,
            CustomerOrderStatusEnum::Checkout->value,
        ];
        $current = $order->status instanceof CustomerOrderStatusEnum
            ? $order->status->value
            : (string) $order->status;
        if (! in_array($current, $allowed, true)) {
            throw CouponException::orderNotModifiable($current);
        }
    }

    protected function validateForApply(CouponTerms $coupon, CustomerOrder $order, ?string $customerId, CarbonImmutable $now): void
    {
        if ($coupon->status === CouponStatusEnum::Paused->value) {
            throw CouponException::paused();
        }
        if ($now->lt($coupon->validFrom)) {
            throw CouponException::notStarted($coupon->validFrom);
        }
        if ($now->gt($coupon->validUntil)) {
            throw CouponException::expired($coupon->validUntil);
        }
        if ((float) $order->subtotal < $coupon->minOrderSubtotal) {
            throw CouponException::minSubtotalNotMet($coupon->minOrderSubtotal);
        }
        if ($coupon->usageLimitTotal !== null && $coupon->timesUsed >= $coupon->usageLimitTotal) {
            throw CouponException::exhausted();
        }

        $this->coupons->assertBranchEligible($coupon->id, $order->branch_id);
        $this->coupons->assertCustomerEligible($coupon->id, $customerId);
    }

    /**
     * Decision B5 — coupon × promotion stacking guard. Reject when the
     * cart already has at least one item bound to a MenuPromotion whose
     * stacking_mode = exclusive_with_coupons.
     */
    protected function assertNoExclusivePromotionStacking(CustomerOrder $order): void
    {
        $blockingItems = $order->items()
            ->where('status', '!=', OrderItemStatusEnum::Voided->value)
            ->whereNotNull('applied_promotion_id')
            ->whereHas('appliedPromotion', function ($q) {
                $q->where('stacking_mode', 'exclusive_with_coupons');
            })
            ->get(['id', 'applied_promotion_id']);

        if ($blockingItems->isEmpty()) {
            return;
        }

        throw CouponException::excludedByActivePromotion(
            $blockingItems->pluck('applied_promotion_id')->unique()->all(),
            $blockingItems->pluck('id')->all(),
        );
    }

    /**
     * Plan-019 — revert every active line that carries an
     * `exclusive_with_coupons` promotion back to its `original_unit_price`
     * so the coupon can apply without the stacking guard tripping. The
     * caller has explicitly opted in (FE "use coupon instead of promo"
     * CTA), so we don't ask again; instead we audit each affected line
     * with the original promotion snapshot so the reverted state is
     * traceable.
     *
     * Note: this only touches lines whose promotion was EXCLUSIVE; lines
     * carrying a stackable-mode promotion are left alone (they can
     * coexist with the new coupon).
     */
    protected function downgradeExclusivePromotions(CustomerOrder $order, ?User $user = null): void
    {
        // #1564 — thân method đã chuyển sang Ordering. Trước đây chỗ này tự truy
        // vấn item, tự khoá, tự ghi giá qua `app(EloquentOrderPersistence::class)
        // ->patchOrderItemUnchecked()` và tự ghi audit lên order: bốn việc của
        // Ordering, làm từ Pricing, đi vòng qua OrderMutationFacade.
        $this->orders->downgradeExclusivePromotions(new DowngradeExclusivePromotionsCommand(
            OrderMutationContextFactory::fromOrder($order, $user?->id),
            (string) $order->id,
            $user?->id === null ? null : (string) $user->id,
        ));
    }

    /**
     * #555 M14 — re-price through the ONE pricing engine.
     *
     * This used to re-sum the total from the STORED tax_amount, which is the
     * pre-coupon tax: `subtotal - discount + service_charge + tax_amount`. Tax
     * is levied on the post-discount base (OrderPricingCalculator §8), so a
     * fixed-200 coupon on a 1000 @10% order persisted 900 while the engine says
     * 880 — a systematic overcharge on every couponed order, and the payment
     * path never re-prices, so 900 is what the cashier collects.
     *
     * Delegating to the canonical path also keeps the per-rate groups (plan-043)
     * intact: a mixed 8% / 10% basket needs the discount allocated pro-rata per
     * rate group before tax, which a flat re-sum cannot express. The negative-
     * total clamp the old body existed for is enforced inside the engine
     * (`max(0, subtotal - discount)`), so it survives.
     *
     * Resolved lazily via the container — CustomerOrderService already reaches
     * back into this service the same way (`CustomerOrderService::couponService`),
     * so constructor injection either way would be circular.
     *
     * The engine derives the order's money from its LINES, so it can only run on
     * a materialised cart. A headless order — a stored subtotal with no item rows
     * (a workstation order synced before its items land) — has nothing to price,
     * and re-pricing it would zero the subtotal. It keeps the flat re-sum and is
     * re-priced for real by applyPricing() the moment its items arrive.
     */
    protected function recalculateOrderTotal(CustomerOrder $order): void
    {
        $order->refresh();

        if ($order->items()->exists()) {
            $this->orders->refreshPricing(new RefreshOrderPricingCommand(
                OrderMutationContextFactory::fromOrder($order),
                (string) $order->id,
            ));
            $order->refresh();

            return;
        }

        $this->orders->refreshPricing(new RefreshOrderPricingCommand(
            OrderMutationContextFactory::fromOrder($order),
            (string) $order->id,
        ));
        $order->refresh();
    }
}
