<?php

namespace App\Services\Payment\Orchestration\Internal;

use App\Models\OrderPayment;

/** Sole low-level order_payments mutation boundary (Plan 047 T2.13). */
interface OrderPaymentLedgerWriter
{
    public function findByOrderAndIdempotencyKey(string $customerOrderId, string $idempotencyKey): ?OrderPayment;

    public function findByMetadataStripeRefundId(string $stripeRefundId): ?OrderPayment;

    public function findOriginalByReferenceNoForUpdate(string $referenceNo): ?OrderPayment;

    public function sumAbsRefundAmountForOriginal(string $originalPaymentId): float;

    public function lockById(string $paymentId): OrderPayment;

    /** @param array<string, mixed> $paymentData */
    public function createRow(array $paymentData): OrderPayment;

    /** @param array<string, mixed> $attributes */
    public function updateRow(OrderPayment $payment, array $attributes): OrderPayment;

    /** Fail a pending row when still past expires_at; null when skipped or missing. */
    public function expireIfStalePending(string $paymentId): ?OrderPayment;

    /** @param list<string> $paymentIds */
    public function stampTillSessionIds(array $paymentIds, string $tillSessionId): int;
}
