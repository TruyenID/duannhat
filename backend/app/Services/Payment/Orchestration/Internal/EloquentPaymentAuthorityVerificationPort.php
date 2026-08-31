<?php

namespace App\Services\Payment\Orchestration\Internal;

use App\Models\OrderPayment;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayConnectionOption;
use App\Models\PaymentMethod;
use App\Models\PaymentPolicyRevision;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\DomainMutation\VerificationAuthority;
use App\Services\Order\Contracts\OrderQueryPort;
use App\Services\Payment\Orchestration\Commands\PreparePaymentCommand;
use App\Services\Payment\Orchestration\Commands\RecordPaymentTenderCommand;
use App\Services\Payment\Orchestration\Commands\RequestPaymentRefundCommand;
use App\Services\Payment\Orchestration\Contracts\PaymentAuthorityVerificationPort;
use App\Services\Payment\Orchestration\Enums\TenderKind;
use App\Services\Payment\Orchestration\ValueObjects\RefundVerificationEvidence;
use App\Services\Payment\Orchestration\ValueObjects\ResolvedPaymentMethodEvidence;
use App\Services\Payment\Orchestration\ValueObjects\VerifiedPaymentPreparation;
use App\Services\Payment\Orchestration\ValueObjects\VerifiedRefundIntent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/** Production payment authority adapter for orchestrator prepare/tender/refund paths. */
final class EloquentPaymentAuthorityVerificationPort implements PaymentAuthorityVerificationPort
{
    public function __construct(private readonly OrderQueryPort $orders) {}

    /** @var list<string> */
    private const ISSUANCE_SCOPES = [
        'payment.resolved_method',
        'payment.verified_preparation',
        'payment.verified_refund',
    ];

    public function verifyPreparation(PreparePaymentCommand $command): VerifiedPaymentPreparation
    {
        $authority = $this->authority();
        $organizationId = $command->context->organizationId
            ?? throw new InvalidArgumentException('Payment preparation requires tenant context.');

        // #1544 — đọc đơn qua PORT của Ordering, không import model. Port đã
        // lọc theo organization nên phép so tenant bên dưới chỉ còn kiểm branch.
        $order = $this->orders->findById((string) $organizationId, (string) $command->orderId);
        if ($order === null || $order->branchId() !== $command->branchId) {
            throw new InvalidArgumentException('Order is not in scope for payment preparation.');
        }

        $connection = PaymentGatewayConnection::query()->with('provider')->find($command->connectionId);
        if ($connection === null
            || (string) $connection->organization_id !== (string) $organizationId
            || ! $connection->is_active) {
            throw new InvalidArgumentException('Payment connection is not active for this tenant.');
        }

        $connectionOption = PaymentGatewayConnectionOption::query()
            ->where('connection_id', $command->connectionId)
            ->where('option_id', $command->optionId)
            ->where('is_enabled', true)
            ->first();
        if ($connectionOption === null) {
            throw new InvalidArgumentException('Payment option is not enabled on this connection.');
        }

        if (! PaymentPolicyRevision::query()
            ->where('branch_id', $command->branchId)
            ->where('revision', $command->policyRevision)
            ->exists()) {
            throw new InvalidArgumentException('Payment policy revision is not published for this branch.');
        }

        $tender = $command->tender
            ?? throw new InvalidArgumentException('Payment preparation requires tender evidence until provider-only initiation is wired.');
        $method = $this->resolvePaymentMethod(
            $tender->paymentMethodId,
            $organizationId,
            $command->branchId,
            $tender->method,
        );

        $methodEvidence = ResolvedPaymentMethodEvidence::issueForPreparation(
            $this,
            $authority,
            $command,
            (string) $method->id,
            $this->methodRevision($method),
            (bool) $method->requires_tendered,
            $this->allowsChange($method),
            $this->allowsDebt($method),
        );

        return VerifiedPaymentPreparation::issue(
            $this,
            $authority,
            $command,
            $this->providerCode($connection),
            (string) $connectionOption->id,
            $methodEvidence,
        );
    }

    public function resolveTenderMethod(RecordPaymentTenderCommand $command): ResolvedPaymentMethodEvidence
    {
        $organizationId = $command->context->organizationId
            ?? throw new InvalidArgumentException('Tender recording requires tenant context.');
        $method = $this->resolvePaymentMethod(
            $command->tender->paymentMethodId,
            $organizationId,
            $command->branchId,
            $command->tender->method,
        );

        return ResolvedPaymentMethodEvidence::issue(
            $this,
            $this->authority(),
            $command,
            $this->methodRevision($method),
            (bool) $method->requires_tendered,
            $this->allowsChange($method),
            $this->allowsDebt($method),
        );
    }

    public function verifyRefund(RequestPaymentRefundCommand $command): VerifiedRefundIntent
    {
        $organizationId = $command->context->organizationId
            ?? throw new InvalidArgumentException('Refund requires tenant context.');
        $payload = $command->payload;

        $payment = OrderPayment::query()->find($payload->orderPaymentId);
        if ($payment === null || (string) $payment->organization_id !== (string) $organizationId) {
            throw new InvalidArgumentException('Order payment is not in scope for refund.');
        }

        $attempt = PaymentAttempt::query()->find($payload->attemptId);
        if ($attempt === null
            || (string) $attempt->organization_id !== (string) $organizationId
            || (string) $attempt->customer_order_id !== $payload->orderId
            || (string) $attempt->branch_id !== $command->branchId) {
            throw new InvalidArgumentException('Payment attempt is not in scope for refund.');
        }

        if ((string) $payment->customer_order_id !== $payload->orderId) {
            throw new InvalidArgumentException('Refund payment and attempt order binding mismatch.');
        }

        $providerPaymentReference = (string) ($attempt->provider_object_id ?? $payment->reference_no ?? '');
        if ($providerPaymentReference === '') {
            throw new InvalidArgumentException('Refund requires a provider payment reference on the attempt or ledger row.');
        }

        $connection = PaymentGatewayConnection::query()->find($attempt->connection_id);
        $providerConnectionIdentity = $connection === null
            ? 'legacy-unlinked'
            : implode(':', [
                (string) $connection->merchant_account_id,
                (string) ($connection->merchant_store_id ?? ''),
            ]);

        $verification = new RefundVerificationEvidence(
            $command->context->actorId ?? throw new InvalidArgumentException('Refund requires actor context.'),
            $command->authorizationReference !== '' ? $command->authorizationReference : $command->refundRequestId,
            now()->toIso8601String(),
        );

        return VerifiedRefundIntent::issue(
            $this,
            $this->authority(),
            $command,
            $providerPaymentReference,
            $providerConnectionIdentity,
            $verification,
        );
    }

    private function authority(): VerificationAuthority
    {
        return VerificationAuthority::forConfiguredAdapter(
            $this,
            PaymentAuthorityVerificationPort::class,
            self::ISSUANCE_SCOPES,
        );
    }

    private function resolvePaymentMethod(
        string $paymentMethodId,
        string $organizationId,
        string $branchId,
        TenderKind $expectedKind,
    ): PaymentMethod {
        try {
            $method = PaymentMethod::query()->findOrFail($paymentMethodId);
        } catch (ModelNotFoundException) {
            throw new InvalidArgumentException('Payment method was not found.');
        }

        if ((string) $method->organization_id !== (string) $organizationId) {
            throw new InvalidArgumentException('Payment method is outside tenant scope.');
        }

        if ($method->branch_id !== null && (string) $method->branch_id !== (string) $branchId) {
            throw new InvalidArgumentException('Payment method is outside branch scope.');
        }

        if (! $method->is_active) {
            throw new InvalidArgumentException('Payment method is inactive.');
        }

        $kind = TenderKind::tryFrom((string) $method->type) ?? TenderKind::Other;
        if ($kind !== $expectedKind) {
            throw new InvalidArgumentException('Payment method tender kind does not match the request.');
        }

        return $method;
    }

    private function methodRevision(PaymentMethod $method): int
    {
        return max(1, (int) ($method->updated_at?->getTimestamp() ?? 1));
    }

    private function allowsChange(PaymentMethod $method): bool
    {
        return (string) $method->type === TenderKind::Cash->value;
    }

    private function allowsDebt(PaymentMethod $method): bool
    {
        return (string) $method->type === TenderKind::OnAccount->value;
    }

    private function providerCode(PaymentGatewayConnection $connection): string
    {
        $provider = $connection->provider->code ?? null;

        return $provider instanceof PaymentGatewayProviderCodeEnum
            ? $provider->value
            : (string) $provider;
    }
}
