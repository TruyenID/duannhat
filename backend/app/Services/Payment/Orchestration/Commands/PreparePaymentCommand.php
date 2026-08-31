<?php

namespace App\Services\Payment\Orchestration\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Payment\Orchestration\Enums\PaymentObligation;
use App\Services\Payment\Orchestration\ValueObjects\PaymentSplitPlan;
use App\Services\Payment\Orchestration\ValueObjects\PaymentTenderPayload;
use InvalidArgumentException;

final readonly class PreparePaymentCommand extends MutationCommand
{
    public string $attemptId;

    public string $orderId;

    public string $branchId;

    public string $connectionId;

    public string $optionId;

    public string $currencyCode;

    public string $authorizationReference;

    public string $requestFingerprint;

    public function __construct(
        MutationContext $context,
        string $attemptId,
        string $orderId,
        string $branchId,
        string $connectionId,
        string $optionId,
        public int $amountMinor,
        string $currencyCode,
        public int $policyRevision,
        public int $expectedSubtotalMinor,
        public int $expectedTotalMinor,
        public ?PaymentTenderPayload $tender = null,
        public ?PaymentSplitPlan $splitPlan = null,
        string $authorizationReference = '',
    ) {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->attemptId = self::uuid($attemptId, 'attemptId');
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->branchId = self::uuid($branchId, 'branchId');
        $this->connectionId = self::uuid($connectionId, 'connectionId');
        $this->optionId = self::uuid($optionId, 'optionId');
        $this->currencyCode = strtoupper(trim($currencyCode));

        if ($amountMinor < 1 || $policyRevision < 1 || $expectedSubtotalMinor < 0 || $expectedTotalMinor < 1 || $expectedSubtotalMinor > $expectedTotalMinor || $amountMinor > $expectedTotalMinor || preg_match('/^[A-Z]{3}$/', $this->currencyCode) !== 1) {
            throw new InvalidArgumentException('Payment amount, policy revision, and currency must be valid.');
        }
        if ($context->organizationId === null || $context->actorId === null) {
            throw new InvalidArgumentException('Payment preparation requires tenant and actor context.');
        }
        $this->authorizationReference = self::safeToken($authorizationReference, 'authorizationReference', 255);
        if ($splitPlan !== null && ($splitPlan->expectedSubtotalMinor !== $expectedSubtotalMinor || $splitPlan->expectedTotalMinor !== $expectedTotalMinor)) {
            throw new InvalidArgumentException('Split plan totals must match server-resolved order evidence.');
        }
        if ($tender !== null && (($tender->obligation === PaymentObligation::Split) !== ($splitPlan !== null))) {
            throw new InvalidArgumentException('Split tender and split plan must be supplied together.');
        }
        $this->requestFingerprint = hash('sha256', json_encode([
            $this->attemptId, $this->orderId, $this->branchId, $this->connectionId, $this->optionId,
            $amountMinor, $this->currencyCode, $policyRevision, $expectedSubtotalMinor, $expectedTotalMinor,
            $tender, $splitPlan, $context->organizationId, $context->actorId, $context->correlationId,
            $context->idempotencyKeyHash, $context->expectedVersion,
            $this->authorizationReference,
        ], JSON_THROW_ON_ERROR));
    }
}
