<?php

namespace App\Jobs;

use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\CustomerOrderService;
use App\Services\Customer\StripePaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Auto-expire takeaway counter-pay orders whose payment window has elapsed.
 *
 * Runs every minute (scheduled in routes/console.php). Moves matching orders
 * to the terminal `expired` status and stamps `auto_cancelled_at`, so the
 * countdown the customer/admin sees ("auto-cancelled after …") actually
 * results in a state change instead of a stuck `confirmed` row.
 *
 * A row matches when ALL of:
 *   - order_type = 'takeaway'
 *   - payment_due_at IS NOT NULL AND payment_due_at < now()  (window elapsed)
 *   - auto_cancelled_at IS NULL                              (not already swept)
 *   - status IN (pending, awaiting_confirmation, confirmed)  (not yet paid)
 *
 * Dine-in orders and orders already paying/closed/voided are never touched.
 *
 * issue #512 — the previous implementation referenced a non-existent `Order`
 * model and wrote dropped `payment_method`/`cancellation_reason` columns plus
 * an invalid `cancelled` status, so it fataled on every run and 16 overdue
 * orders piled up.
 */
class CancelOverdueTakeawayOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Non-terminal, not-yet-paid statuses a takeaway order can sit in while
     * its counter-payment window ticks down.
     *
     * @var list<CustomerOrderStatusEnum>
     */
    private const CANCELLABLE_STATUSES = [
        CustomerOrderStatusEnum::Pending,
        CustomerOrderStatusEnum::AwaitingConfirmation,
        CustomerOrderStatusEnum::Confirmed,
    ];

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $orders = app(CustomerOrderService::class);

        $overdueOrders = CustomerOrder::query()
            ->where('order_type', 'takeaway')
            ->whereNotNull('payment_due_at')
            ->where('payment_due_at', '<', now())
            ->whereNull('auto_cancelled_at')
            ->whereIn('status', array_map(
                fn (CustomerOrderStatusEnum $s) => $s->value,
                self::CANCELLABLE_STATUSES,
            ))
            ->get();

        $expiredCount = 0;

        foreach ($overdueOrders as $order) {
            try {
                // #1125 option B — an order awaiting an ASYNC Stripe payment
                // (live Konbini voucher / bank transfer in flight) must have
                // its intent canceled at Stripe BEFORE the order dies, or the
                // guest could still pay a voucher for an expired order. When
                // the intent already succeeded (money arrived while the sweep
                // ran) or Stripe is unreachable, the order is SKIPPED — never
                // expire an order whose money may be live.
                //
                // The pending-row precheck runs HERE so the Stripe service is
                // only constructed when a release is actually owed — counter
                // -pay orders must keep expiring on hosts with no STRIPE_SECRET.
                $needsAsyncRelease = $order->stripe_payment_intent_id !== null
                    && OrderPayment::query()
                        ->where('reference_no', $order->stripe_payment_intent_id)
                        ->where('status', PaymentStatusEnum::Pending->value)
                        ->exists();

                if ($needsAsyncRelease && ! app(StripePaymentService::class)->releaseAsyncIntentBeforeExpiry($order)) {
                    Log::info('Skipped expiring takeaway order — async payment live or unreleasable', [
                        'order_id' => $order->id,
                        'order_code' => $order->order_code,
                        'payment_intent_id' => $order->stripe_payment_intent_id,
                    ]);

                    continue;
                }

                // Route through the full void-style teardown (coupon released,
                // items voided, tables freed, audit + OrderVoided broadcast)
                // rather than a raw status flip, so the terminal state is
                // consistent with every other cancellation path.
                $orders->expireOverdueTakeaway($order);
            } catch (\Throwable $e) {
                // Isolate a single bad row so the rest of the sweep still runs.
                Log::error('Failed to auto-expire overdue takeaway order', [
                    'order_id' => $order->id,
                    'order_code' => $order->order_code,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $expiredCount++;

            Log::info('Takeaway order auto-expired due to payment timeout', [
                'order_id' => $order->id,
                'order_code' => $order->order_code,
                'payment_due_at' => $order->payment_due_at,
                'expired_at' => now(),
            ]);
        }

        if ($expiredCount > 0) {
            Log::info("Auto-expired {$expiredCount} overdue takeaway orders");
        }
    }
}
