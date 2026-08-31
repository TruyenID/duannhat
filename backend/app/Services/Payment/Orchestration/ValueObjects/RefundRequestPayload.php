<?php

namespace App\Services\Payment\Orchestration\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;
use App\Services\Payment\Orchestration\Enums\RefundReason;

final readonly class RefundRequestPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $orderId;

    public string $orderPaymentId;

    public string $attemptId;

    public string $currencyCode;

    public function __construct(string $orderId, string $orderPaymentId, string $attemptId, public int $amountMinor, string $currencyCode, public RefundReason $reason)
    {
        $this->orderId = MutationCommand::uuid($orderId, 'orderId');
        $this->orderPaymentId = MutationCommand::uuid($orderPaymentId, 'orderPaymentId');
        $this->attemptId = MutationCommand::uuid($attemptId, 'attemptId');
        $this->currencyCode = strtoupper(trim($currencyCode));
        if ($amountMinor < 1 || preg_match('/^[A-Z]{3}$/', $this->currencyCode) !== 1) {
            throw new \InvalidArgumentException('Refund request is invalid.');
        }
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
