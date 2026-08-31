<?php

namespace App\Services\Payment\Orchestration\Contracts;

interface PaymentQueryPort
{
    public function findAttemptById(string $organizationId, string $attemptId): ?PaymentSnapshot;

    public function findRefundById(string $organizationId, string $refundId): ?PaymentSnapshot;

    public function ledgerNetMinorForOrder(string $organizationId, string $orderId): int;
}
