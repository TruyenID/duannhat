<?php

namespace App\Services\Payment\Orchestration\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Payment\Orchestration\Enums\PaymentObligation;
use App\Services\Payment\Orchestration\ValueObjects\PaymentSplitPlan;
use App\Services\Payment\Orchestration\ValueObjects\PaymentTenderPayload;

final readonly class RecordPaymentTenderCommand extends MutationCommand
{
    public string $orderPaymentId;

    public string $orderId;

    public string $branchId;

    public string $currencyCode;

    public string $authorizationReference;

    public string $requestFingerprint;

    public function __construct(MutationContext $context, string $orderPaymentId, string $orderId, string $branchId, public int $amountMinor, string $currencyCode, public PaymentTenderPayload $tender, public ?PaymentSplitPlan $splitPlan = null, string $authorizationReference = '')
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderPaymentId = self::uuid($orderPaymentId, 'orderPaymentId');
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->branchId = self::uuid($branchId, 'branchId');
        $this->currencyCode = strtoupper(trim($currencyCode));
        if ($amountMinor < 1 || preg_match('/^[A-Z]{3}$/', $this->currencyCode) !== 1) {
            throw new \InvalidArgumentException('Tender amount and currency must be valid.');
        }
        if (($tender->obligation === PaymentObligation::Split) !== ($splitPlan !== null)) {
            throw new \InvalidArgumentException('Split tenders require exactly one split plan.');
        }
        if ($splitPlan !== null) {
            $matchingAllocations = array_values(array_filter($splitPlan->allocations, static fn ($allocation): bool => $allocation->allocationId === $tender->allocationId));
            if (count($matchingAllocations) !== 1 || $matchingAllocations[0]->amountMinor !== $amountMinor) {
                throw new \InvalidArgumentException('Tender must exactly match one allocation in the split plan.');
            }
        }
        if ($context->organizationId === null || $context->actorId === null) {
            throw new \InvalidArgumentException('Tender recording requires tenant and actor context.');
        }
        $this->authorizationReference = self::safeToken($authorizationReference, 'authorizationReference', 255);
        $this->requestFingerprint = hash('sha256', json_encode([
            $this->orderPaymentId, $this->orderId, $this->branchId, $amountMinor, $this->currencyCode,
            $tender, $splitPlan, $context->organizationId, $context->actorId, $context->correlationId,
            $context->idempotencyKeyHash, $context->expectedVersion,
            $this->authorizationReference,
        ], JSON_THROW_ON_ERROR));
    }
}
