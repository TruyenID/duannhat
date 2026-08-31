<?php

namespace App\Services\Order\ValueObjects;

use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\VerificationAuthority;
use App\Services\DomainMutation\VerifiedObjectRegistry;
use App\Services\Order\Commands\CreateOrderCommand;
use App\Services\Order\Commands\ReplayOfflineOrderCommand;
use App\Services\Order\Contracts\OrderEvidenceVerificationPort;
use App\Services\Order\Contracts\OrderPricingResolutionPort;
use InvalidArgumentException;

/** Server-resolved immutable pricing/tax/promotion snapshot; never constructed from an ordinary API request body. */
final readonly class TrustedOrderSnapshot implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $resolverFingerprint;

    public string $currencyCode;

    public string $resolvedAt;

    public string $orderId;

    public string $branchId;

    public ?string $organizationId;

    public ?string $actorId;

    public string $correlationId;

    public string $idempotencyKeyHash;

    public ?int $expectedVersion;

    public string $requestFingerprint;

    /**
     * When the sale ACTUALLY happened (#1091), ISO-8601.
     *
     * Null on the online path, where "now" is the sale. Set for an offline
     * replay from the device's SIGNED evidence, so the order is dated by when
     * it happened rather than when it reached Cloud — and a device cannot
     * backdate a sale without invalidating its own signature.
     */
    public ?string $soldAt;

    private function __construct(
        public OrderDraftPayload $draft,
        public CustomerOrderStatusEnum $initialStatus,
        string $currencyCode,
        string $resolverFingerprint,
        string $resolvedAt,
        MutationCommand $request,
        public bool $offlineReplay = false,
        ?string $soldAt = null,
    ) {
        if (! in_array($initialStatus, [CustomerOrderStatusEnum::Pending, CustomerOrderStatusEnum::AwaitingConfirmation, CustomerOrderStatusEnum::Open], true)) {
            throw new InvalidArgumentException('Trusted order construction cannot begin in a terminal or progressed status.');
        }
        if ($draft->status !== $initialStatus) {
            throw new InvalidArgumentException('Trusted order initial status must match its immutable draft snapshot.');
        }

        $hasMissingLineEvidence = false;
        foreach ($draft->lines as $line) {
            $hasMissingLineEvidence = $hasMissingLineEvidence || $line->evidence === null;
            if ($line->status !== OrderItemStatusEnum::Pending || $line->refundedQuantity !== 0 || $line->startedPreparingAt !== null || $line->readyAt !== null || $line->servedAt !== null || $line->voidedAt !== null) {
                throw new InvalidArgumentException('New trusted order lines must begin pending without lifecycle or refund state.');
            }
        }

        if ($draft->pricingEvidence === null || $hasMissingLineEvidence) {
            throw new InvalidArgumentException('Trusted order construction requires complete immutable line and total evidence.');
        }

        $pricing = $draft->pricingEvidence;
        // 内税 (taxIncluded): the tax already sits INSIDE the line prices and the
        // service charge, so the customer total must NOT add it again — that is
        // the classic double-charge. 外税 adds it on top. The legacy engine
        // (OrderPricingCalculator::priceGroups) branches exactly this way.
        $reconciledTotal = $pricing->taxIncluded
            ? $pricing->subtotalMinor - $pricing->discountMinor + $pricing->serviceChargeMinor
            : $pricing->subtotalMinor - $pricing->discountMinor + $pricing->serviceChargeMinor + $pricing->taxMinor;
        if ($pricing->totalMinor !== $reconciledTotal) {
            throw new InvalidArgumentException(sprintf(
                'Trusted order totals do not reconcile (%s mode): subtotal %d - discount %d + service charge %d%s = %d, but the order total says %d (off by %d).',
                $pricing->taxIncluded ? 'tax-included' : 'tax-excluded',
                $pricing->subtotalMinor,
                $pricing->discountMinor,
                $pricing->serviceChargeMinor,
                $pricing->taxIncluded ? '' : sprintf(' + tax %d', $pricing->taxMinor),
                $reconciledTotal,
                $pricing->totalMinor,
                $pricing->totalMinor - $reconciledTotal,
            ));
        }

        // The service charge is its own evidence-carrying payload (issue #1090):
        // it is EXCLUDED from subtotalMinor — `customer_orders.subtotal` has
        // always excluded it and every report reads it that way — but the tax ON
        // it is part of the order's taxMinor, exactly like a product line's tax.
        $serviceCharge = $draft->serviceCharge;
        $serviceChargeMinor = $serviceCharge?->amountMinor ?? 0;
        $serviceChargeTaxMinor = $serviceCharge?->taxAmountMinor ?? 0;

        if ($serviceChargeMinor !== $pricing->serviceChargeMinor) {
            throw new InvalidArgumentException(sprintf(
                'Service charge evidence says %d but the order totals say %d. The charge line and the order total must agree.',
                $serviceChargeMinor,
                $pricing->serviceChargeMinor,
            ));
        }

        $lineSubtotal = array_sum(array_map(static fn (OrderLinePayload $line): int => $line->evidence->lineSubtotalMinor + $line->evidence->toppingSubtotalMinor, $draft->lines));
        $lineDiscount = array_sum(array_map(static fn (OrderLinePayload $line): int => $line->evidence->promotionDiscountMinor, $draft->lines));
        $lineTax = array_sum(array_map(static fn (OrderLinePayload $line): int => $line->evidence->taxAmountMinor, $draft->lines));
        $taxedEvidence = $lineTax + $serviceChargeTaxMinor;

        if ($lineSubtotal !== $pricing->subtotalMinor) {
            throw new InvalidArgumentException(sprintf(
                'Line subtotals add up to %d but the order subtotal says %d (off by %d). Every product line must be accounted for, and the service charge must NOT be counted here.',
                $lineSubtotal,
                $pricing->subtotalMinor,
                $lineSubtotal - $pricing->subtotalMinor,
            ));
        }

        if ($lineDiscount > $pricing->discountMinor) {
            throw new InvalidArgumentException(sprintf(
                'Line promotion discounts add up to %d, more than the order discount of %d. A line cannot discount more than the order records.',
                $lineDiscount,
                $pricing->discountMinor,
            ));
        }

        if ($taxedEvidence !== $pricing->taxMinor) {
            throw new InvalidArgumentException(sprintf(
                'Tax evidence adds up to %d (%d from lines + %d from the service charge) but the order tax says %d (off by %d). Every minor unit of tax the customer pays must be attributable to a line or to the service charge.',
                $taxedEvidence,
                $lineTax,
                $serviceChargeTaxMinor,
                $pricing->taxMinor,
                $taxedEvidence - $pricing->taxMinor,
            ));
        }

        $this->resolverFingerprint = MutationCommand::fingerprint($resolverFingerprint, 'resolverFingerprint');
        $this->currencyCode = strtoupper(trim($currencyCode));
        if (preg_match('/^[A-Z]{3}$/', $this->currencyCode) !== 1) {
            throw new InvalidArgumentException('currencyCode must be an ISO 4217 code.');
        }
        $this->resolvedAt = MutationCommand::isoDateTime($resolvedAt, 'resolvedAt');
        $this->soldAt = $soldAt === null ? null : MutationCommand::isoDateTime($soldAt, 'soldAt');
        if (! $request instanceof CreateOrderCommand && ! $request instanceof ReplayOfflineOrderCommand) {
            throw new InvalidArgumentException('Trusted order snapshot requires an authoritative create or offline replay request.');
        }
        if ($request->context->organizationId === null) {
            throw new InvalidArgumentException('Trusted order snapshot requires an organization tenant.');
        }
        $this->orderId = $request->orderId;
        $this->branchId = $request->branchId;
        $this->organizationId = $request->context->organizationId;
        $this->actorId = $request->context->actorId;
        $this->correlationId = $request->context->correlationId;
        $this->idempotencyKeyHash = $request->context->idempotencyKeyHash;
        $this->expectedVersion = $request->context->expectedVersion;
        $this->requestFingerprint = $request->mutationFingerprint();
    }

    public static function fromPricingResolver(OrderPricingResolutionPort $resolver, VerificationAuthority $authority, CreateOrderCommand $request, OrderDraftPayload $draft, CustomerOrderStatusEnum $initialStatus, string $currencyCode, string $resolverFingerprint, string $resolvedAt): self
    {
        $snapshot = new self($draft, $initialStatus, $currencyCode, $resolverFingerprint, $resolvedAt, $request, false);
        VerifiedObjectRegistry::seal($snapshot, $resolver, $authority, 'order.trusted_snapshot', OrderPricingResolutionPort::class);

        return $snapshot;
    }

    public static function fromOfflineVerifier(OrderEvidenceVerificationPort $verifier, VerificationAuthority $authority, ReplayOfflineOrderCommand $request, OrderDraftPayload $draft, CustomerOrderStatusEnum $initialStatus, string $currencyCode, string $resolverFingerprint, string $resolvedAt): self
    {
        // The signed `issued_at` IS the sale instant (#1091).
        $snapshot = new self($draft, $initialStatus, $currencyCode, $resolverFingerprint, $resolvedAt, $request, true, $request->evidence->issuedAt);
        VerifiedObjectRegistry::seal($snapshot, $verifier, $authority, 'order.trusted_snapshot', OrderEvidenceVerificationPort::class);

        return $snapshot;
    }

    public function assertTrusted(): void
    {
        VerifiedObjectRegistry::assertSealed($this, 'order.trusted_snapshot');
    }

    public function jsonSerialize(): array
    {
        return ['draft' => $this->draft, 'initial_status' => $this->initialStatus->value, 'currency_code' => $this->currencyCode, 'resolver_fingerprint' => $this->resolverFingerprint, 'resolved_at' => $this->resolvedAt, 'sold_at' => $this->soldAt, 'offline_replay' => $this->offlineReplay, 'order_id' => $this->orderId, 'branch_id' => $this->branchId, 'organization_id' => $this->organizationId, 'actor_id' => $this->actorId, 'correlation_id' => $this->correlationId, 'idempotency_key_hash' => $this->idempotencyKeyHash, 'expected_version' => $this->expectedVersion, 'request_fingerprint' => $this->requestFingerprint];
    }
}
