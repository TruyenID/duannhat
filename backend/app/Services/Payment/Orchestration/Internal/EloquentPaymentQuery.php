<?php

namespace App\Services\Payment\Orchestration\Internal;

use App\Models\OrderPayment;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Payment\Orchestration\Contracts\PaymentQueryPort;
use App\Services\Payment\Orchestration\Contracts\PaymentSnapshot;

/** Read-only payment aggregate projections for orchestration and settlement checks. */
final class EloquentPaymentQuery implements PaymentQueryPort
{
    public function findAttemptById(string $organizationId, string $attemptId): ?PaymentSnapshot
    {
        $attempt = PaymentAttempt::query()
            ->where('organization_id', $organizationId)
            ->where('id', $attemptId)
            ->first();

        return $attempt === null ? null : PaymentAttemptSnapshot::fromModel($attempt);
    }

    public function findRefundById(string $organizationId, string $refundId): ?PaymentSnapshot
    {
        $refund = PaymentRefund::query()
            ->where('organization_id', $organizationId)
            ->where('id', $refundId)
            ->first();

        return $refund === null ? null : PaymentRefundSnapshot::fromModel($refund);
    }

    public function ledgerNetMinorForOrder(string $organizationId, string $orderId): int
    {
        $totalMajor = (float) OrderPayment::query()
            ->where('organization_id', $organizationId)
            ->where('customer_order_id', $orderId)
            ->whereNull('metadata->settles_payment_id')
            ->whereIn('status', [
                PaymentStatusEnum::Succeeded->value,
                PaymentStatusEnum::Refunded->value,
            ])
            ->sum('amount');

        return (int) round($totalMajor);
    }
}
