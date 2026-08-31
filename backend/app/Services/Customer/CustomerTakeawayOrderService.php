<?php

namespace App\Services\Customer;

use App\Exceptions\BranchClosedException;
use App\Exceptions\PickupOutsideOpeningHoursException;
use App\Exceptions\TakeawayContactRequiredException;
use App\Mail\OrderPlacedMail;
use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Rules\PhoneFormatForBranch;
use App\Services\CustomerPickupService;
use App\Services\Order\Contracts\BranchOpeningWindow;
use App\Services\Order\Coupon\OrderCouponService;
use App\Services\Shop\EffectiveOrderPolicyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Plan-047 thin-controller/fat-service — the takeaway ("place a branch order by
 * slug") business logic extracted from CustomerOrderController::createBranchOrder.
 * Owns contact validation, payment-policy resolution, country-aware phone
 * validation, pickup estimation, order assembly + status, the atomic
 * create+addItems+coupon transaction, and the order-placed mail. The controller
 * keeps HTTP concerns (branch/org resolution, idempotency lock/cache, response
 * shape).
 */
class CustomerTakeawayOrderService
{
    public function __construct(
        private readonly CustomerOrderService $customerOrderService,
        private readonly OrderCouponService $couponService,
        private readonly CustomerPickupService $pickupService,
        private readonly EffectiveOrderPolicyService $policyService,
        // #962 — giờ mở cửa là dữ liệu của Organization; hỏi qua cổng thay vì
        // gọi static sang `App\Services\Shop\BranchOpeningHours`.
        private readonly BranchOpeningWindow $openingWindow,
    ) {}

    /**
     * Create a takeaway order for the branch (or resolve a durable idempotent
     * replay). Returns the order with `wasRecentlyCreated` intact so the caller
     * can tell a fresh create from a replay.
     *
     * #962 — `$customerId` là ID của khách đã đăng nhập (trước đây là chính model
     * `App\Models\Customer`). Method chỉ từng dùng `->id` của nó và một phép so
     * null, nên cầm cả model là kéo CustomerEngagement vào Ordering không vì gì.
     *
     * @param  array<string, mixed>  $validated  the CustomerOrderStoreRequest payload
     * @param  string|null  $customerId  the logged-in customer's id, or null for a guest
     *
     * @throws TakeawayContactRequiredException when a guest supplies no phone or email
     * @throws ValidationException on a country-invalid phone
     */
    public function place(
        Branch $branch,
        Organization $organization,
        array $validated,
        ?string $customerId,
        ?string $durableOrderId,
        string $orderLocale,
    ): CustomerOrder {
        $takeawayName = $validated['customer_takeaway_name'] ?? $validated['customer_name'] ?? null;
        $takeawayPhone = $validated['customer_takeaway_phone'] ?? $validated['customer_phone'] ?? null;
        $takeawayEmail = $validated['customer_takeaway_email'] ?? $validated['customer_email'] ?? null;

        // Issue #603 — defense-in-depth: a *guest* takeaway order must carry at
        // least one reachable contact channel (phone OR email). Logged-in
        // customers are exempt (they fall back to their account profile).
        // Blank/whitespace values are already collapsed to null by the global
        // TrimStrings + ConvertEmptyStringsToNull middleware, so blank() catches
        // "", "   ", and null uniformly.
        if ($customerId === null && blank($takeawayPhone) && blank($takeawayEmail)) {
            throw new TakeawayContactRequiredException;
        }

        // plan-035 — resolve the effective payment policy. Default flow creates
        // takeaway orders as `pending` (legacy "pay-first"). When the shop/brand
        // opts OUT via prep_before_payment=false, force status=open so the
        // kitchen starts before payment. Stripe pre-paid flows always go open.
        $policy = $this->policyService->resolve($branch);

        // plan-035 — country-aware phone validation. The branch's country
        // (derived from branches.locale) decides the acceptable numbering plan.
        // A null/empty phone is left to the request's `nullable` rule.
        if (! empty($takeawayPhone)) {
            Validator::make(
                ['customer_takeaway_phone' => $takeawayPhone],
                ['customer_takeaway_phone' => [new PhoneFormatForBranch($policy['phone_country'])]],
            )->validate();
        }

        // #1160 — a scheduled pickup must land inside the shop's opening
        // hours. customer-web caps the picker, but a stale tab (hours edited
        // after page load) or a direct API call would otherwise book food for
        // 03:00. Judged on the BRANCH's clock (#1091), and a no-op for shops
        // that never filled in weekly_hours.
        $isScheduledPickup = ($validated['pickup_type'] ?? null) === 'scheduled'
            && ! empty($validated['scheduled_pickup_time']);

        if ($isScheduledPickup) {
            $requestedPickup = Carbon::parse($validated['scheduled_pickup_time']);

            if (! $this->openingWindow->isOpenAt($branch, $requestedPickup)) {
                throw new PickupOutsideOpeningHoursException(
                    $this->openingWindow->closingAt($branch, $requestedPickup)?->toIso8601String(),
                );
            }
        } elseif (! $this->openingWindow->isOpenAt($branch, now())) {
            // #1167 — everything else on this endpoint is "prepare it now", so
            // it has to be placed while the shop is actually open. This
            // REVERSES #1160's carve-out for immediate pickups: that read them
            // as a walk-up customer standing at the counter, but the endpoint
            // is the remote customer-web basket, and a 23:00 order lands in an
            // empty kitchen. A walk-up is unaffected — the shop is open.
            throw new BranchClosedException(
                $this->openingWindow->nextOpeningAt($branch, now())?->toIso8601String(),
            );
        }

        // #1106 — ALL takeaway orders follow the prep_before_payment rule; a
        // Stripe-online order (payment_method=card) creates `confirmed` like
        // counter and is closed by the intent/webhook path. The old
        // `payment_method in ['online','stripe']` always-open branch was dead
        // code (the store request never admits those values) and activating it
        // is unsafe until CancelOverdueTakeawayOrders learns to reap unpaid
        // `open` orders — see the issue before resurrecting prepaid-open.
        $shouldStartOpen = ! $policy['prep_before_payment'];

        // Calculate pickup time estimation if items provided.
        $pickupTimeData = [];
        if (! empty($validated['items'])) {
            $estimation = $this->pickupService->calculateEstimatedReadyTime(
                $branch,
                $validated['items'],
            );

            $pickupTimeData = [
                'pickup_type' => $validated['pickup_type'] ?? 'immediate',
                // When the customer doesn't schedule a specific slot ("immediate"),
                // default the pickup time to the estimated ready time so every
                // takeaway order carries a concrete pickup time the admin (and
                // kitchen) can see — instead of leaving it null.
                //
                // The client sends this as a UTC instant ("…Z"); normalizing it
                // to the app timezone is handled once by CustomerOrder's
                // scheduledPickupTime mutator, so every write path is covered
                // rather than just this one. See that model for the round-trip
                // details.
                'scheduled_pickup_time' => $validated['scheduled_pickup_time']
                    ?? $estimation['estimated_ready_time'],
                'estimated_ready_time' => $estimation['estimated_ready_time'],
                'preparation_minutes' => $estimation['preparation_minutes'],
            ];
        }

        $orderData = [
            'order_type' => 'takeaway',
            'payment_method' => $validated['payment_method'] ?? null,
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'brand_id' => $branch->brand?->id,
            'customer_id' => $customerId,
            'note' => $validated['note'] ?? null,
            'customer_takeaway_name' => $takeawayName,
            'customer_takeaway_phone' => $takeawayPhone,
            'customer_takeaway_email' => $takeawayEmail,
            // Issue #365 — stamp the active website locale so OrderPaidInvoiceMail
            // (dispatched later from a Stripe webhook with no Accept-Language
            // header) still renders in the customer's chosen language.
            'customer_locale' => $orderLocale,
            // plan-037 durable idempotency — stamp the derived key so the
            // service's unique(client_order_id) guard dedupes a retried submit
            // even when the cache map has been flushed. Null (no header) leaves
            // the column NULL, preserving the pre-existing behaviour.
            'client_order_id' => $durableOrderId,
            ...$pickupTimeData,
        ];

        // The review step is FE-only (draft in localStorage). BE receives
        // POST /orders only after the customer confirms → status `confirmed`
        // (counter AND card alike — #1106). Shops with
        // prep_before_payment=false skip the review → status `open`.
        if ($shouldStartOpen) {
            $orderData['status'] = CustomerOrderStatusEnum::Open->value;
        } else {
            $orderData['status'] = CustomerOrderStatusEnum::Confirmed->value;
        }

        // plan-019 — order create + addItems + couponApply share a single
        // transaction so a bad coupon_code rolls the whole creation back.
        $order = DB::transaction(function () use ($orderData, $validated, $customerId) {
            $order = $this->customerOrderService->create($orderData);

            // Durable idempotent replay: create() resolved to a pre-existing row
            // (a retry whose cache map was flushed, or the loser of a concurrent
            // race recovered via the unique-constraint catch). Never re-run
            // addItems / coupon against it — that would double the line items.
            if (! $order->wasRecentlyCreated) {
                return $order;
            }

            if (! empty($validated['items'])) {
                $this->customerOrderService->addItems($order, ['items' => $validated['items']]);
                // addItems recomputes subtotal in the DB but leaves this
                // in-memory instance stale (0). couponService::apply clamps a
                // fixed discount to min(value, subtotal) — without this refresh
                // the discount lands at 0 on every takeaway coupon.
                $order->refresh();
            }

            if (! empty($validated['coupon_code'])) {
                $this->couponService->apply(
                    $order,
                    $validated['coupon_code'],
                    $customerId,
                    'customer_web',
                    user: null,
                    downgradeExclusivePromotions: (bool) ($validated['downgrade_exclusive_promotions'] ?? false),
                );
            }

            return $order;
        });

        // Durable idempotent replay short-circuit — items/coupon/mail were
        // intentionally skipped above, so just return the existing order.
        if (! $order->wasRecentlyCreated) {
            return $order;
        }

        $this->dispatchOrderPlacedMail($order);

        return $order;
    }

    /**
     * plan-035 — fire the order-placed mail when the customer left an email.
     * Queued so SMTP/Resend latency doesn't stretch checkout. plan-037 — skip
     * while the order still awaits confirmation; commit() re-dispatches it so a
     * customer who ultimately cancels never gets a placed-mail. Mail failures
     * must never break order creation.
     */
    private function dispatchOrderPlacedMail(CustomerOrder $order): void
    {
        $isAwaitingConfirmation = $order->status instanceof CustomerOrderStatusEnum
            ? $order->status === CustomerOrderStatusEnum::AwaitingConfirmation
            : $order->status === CustomerOrderStatusEnum::AwaitingConfirmation->value;

        if (! $order->customer_takeaway_email || $isAwaitingConfirmation) {
            return;
        }

        try {
            $loaded = CustomerOrder::with(['branch', 'items.productSku.product'])
                ->find($order->id);
            Mail::to($order->customer_takeaway_email)
                ->queue(new OrderPlacedMail($loaded));
        } catch (\Throwable $e) {
            // Mail must never break order creation. Log + move on; the PDF
            // receipt at pay-time is the durable record anyway.
            Log::warning('OrderPlacedMail queue failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
