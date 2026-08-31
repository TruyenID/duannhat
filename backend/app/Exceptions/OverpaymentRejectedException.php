<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * plan-020 — MONEY / overpayment race.
 *
 * Thrown by the SYNCHRONOUS payer confirmation path
 * (StripePaymentService::confirmAndRecordPayment) when a Stripe PaymentIntent
 * has already `succeeded` (the card WAS charged) but the per-order overpayment
 * guard refused to ledger the slice because a concurrent full/split payment
 * had already collected the whole balance.
 *
 * The old behaviour returned HTTP 200 to the payer even though their money was
 * stranded and no refund had been issued — a silent overpayment. This surfaces
 * a clear non-200 so customer-web can render a "refund pending" state, and the
 * caller logs the intent id at error level for refund reconciliation.
 *
 * The webhook path (markOrderPaidFromIntent called directly) intentionally does
 * NOT throw — Stripe webhooks must ACK 200 or Stripe retries forever, and the
 * rejection is a business decision, not a transient failure. The service already
 * logs the rejection there for the same reconciliation queue.
 */
class OverpaymentRejectedException extends \RuntimeException
{
    public function __construct(
        public readonly string $paymentIntentId,
        public readonly string $orderId,
        public readonly float $chargedAmount,
    ) {
        parent::__construct(
            "Payment intent [{$paymentIntentId}] was charged but rejected as an overpayment on order [{$orderId}]; refund pending."
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'This bill was fully paid by someone else while your payment was processing. Your charge will be refunded.',
            'error_code' => 'overpayment_refund_pending',
            'meta' => [
                'payment_intent_id' => $this->paymentIntentId,
                'order_id' => $this->orderId,
                'charged_amount' => $this->chargedAmount,
            ],
        ], 409);
    }
}
