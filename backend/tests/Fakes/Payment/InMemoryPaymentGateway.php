<?php

namespace Tests\Fakes\Payment;

use App\Omnify\Enums\PaymentAttemptOperationEnum;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Services\Payment\Gateway\Commands\CancelPaymentCommand;
use App\Services\Payment\Gateway\Commands\CapturePaymentCommand;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Commands\RefundPaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrievePaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrieveRefundCommand;
use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Contracts\PaymentGatewayContract;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Gateway\Exceptions\GatewayAuthenticationFailed;
use App\Services\Payment\Gateway\Exceptions\IdempotencyPayloadMismatch;
use App\Services\Payment\Gateway\Exceptions\UnsupportedPaymentOperation;
use App\Services\Payment\Gateway\Exceptions\WebhookPayloadConflict;
use App\Services\Payment\Gateway\Exceptions\WebhookVerificationFailed;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\Results\GatewayRefundResult;
use App\Services\Payment\Gateway\Results\VerifiedGatewayEvent;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\ConnectionLocator;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use App\Services\Payment\Gateway\ValueObjects\RedactedData;
use DateTimeImmutable;
use JsonException;
use Tests\Contracts\Payment\ProviderFault;

final class InMemoryPaymentGateway implements PaymentGatewayContract
{
    /** @var array<string, array{fingerprint: string, result: GatewayPaymentResult|GatewayRefundResult}> */
    private array $mutations = [];

    /** @var array<string, GatewayPaymentResult> */
    private array $payments = [];

    /** @var array<string, GatewayRefundResult> */
    private array $refunds = [];

    /** @var array<string, VerifiedGatewayEvent> */
    private array $events = [];

    /** @var array<string, int> */
    private array $calls = [];

    public function __construct(
        private readonly CapabilitySet $capabilitySet,
        private readonly DateTimeImmutable $operationStartedAt,
        private readonly ?ProviderFault $fault = null,
    ) {}

    public function capabilities(GatewayConnectionData $connection): CapabilitySet
    {
        $this->calls['capabilities'] = ($this->calls['capabilities'] ?? 0) + 1;

        return $this->capabilitySet;
    }

    /**
     * #2938 — fake dùng chung, không mang định danh của nhà cung cấp nào, nên
     * nó KHÔNG nhận ra payload nào. Adapter thật mới biết đọc `account` /
     * `merchantPaymentId`; ở đây trả `null` là câu trả lời trung thực.
     *
     * @param  array<string, mixed>  $payload
     */
    public function identifyConnection(array $payload): ?ConnectionLocator
    {
        return null;
    }

    public function preparePayment(CreatePaymentCommand $command): GatewayPaymentResult
    {
        $required = $command->operation === PaymentAttemptOperationEnum::Authorize
            ? GatewayCapability::Authorize
            : GatewayCapability::Create;
        $this->requireCapability(GatewayCapability::Create, $command->request->correlationId);
        $this->requireCapability($required, $command->request->correlationId, [
            'attempt_provider_state' => 'requires_capture',
        ]);

        $fingerprint = $this->fingerprint([
            'connection' => $command->connection->jsonSerialize(),
            'operation_id' => $command->request->operationId,
            'order_id' => $command->orderId,
            'option_id' => $command->gatewayOptionId,
            'minor_amount' => $command->money->minorAmount,
            'currency' => $command->money->currency,
            'operation' => $command->operation->value,
            'channel' => $command->channel->value,
            'policy_revision' => $command->policyRevision,
            'payment_source_sha256' => $command->paymentSourceReference() === null ? null : hash('sha256', $command->paymentSourceReference()),
            'metadata' => $command->metadata->jsonSerialize(),
        ]);

        if ($this->fault === ProviderFault::Authentication) {
            $this->calls['create'] = ($this->calls['create'] ?? 0) + 1;
            throw new GatewayAuthenticationFailed($command->request->correlationId);
        }

        $reference = new ProviderObjectReference('fake_pay_'.substr(hash('sha256', $command->connection->connectionId.'|'.$command->request->idempotencyKey), 0, 24));
        $result = match ($this->fault) {
            ProviderFault::Decline => new GatewayPaymentResult(
                PaymentAttemptStateEnum::Failed,
                'card_declined',
                summary: new RedactedData(['reason_code' => 'provider_declined']),
            ),
            ProviderFault::Timeout => new GatewayPaymentResult(
                PaymentAttemptStateEnum::ReconciliationRequired,
                'network_timeout',
                $reference,
                summary: new RedactedData(['reason_code' => 'provider_outcome_unknown']),
            ),
            default => new GatewayPaymentResult(
                PaymentAttemptStateEnum::Succeeded,
                'succeeded',
                $reference,
                $command->money,
                summary: new RedactedData(['outcome' => 'provider_succeeded']),
            ),
        };

        /** @var GatewayPaymentResult $stored */
        $stored = $this->mutate($command->connection->connectionId, 'create', $command->request->idempotencyKey, $fingerprint, $result, $command->request->correlationId);
        if ($stored->payment !== null) {
            $this->payments[$this->remoteKey($command->connection->connectionId, $stored->payment)] = $stored;
        }

        return $stored;
    }

    public function retrievePayment(RetrievePaymentCommand $command): GatewayPaymentResult
    {
        $this->requireCapability(GatewayCapability::RetrievePayment, $command->request->correlationId);
        $this->calls['retrieve_payment'] = ($this->calls['retrieve_payment'] ?? 0) + 1;

        return $this->payments[$this->remoteKey($command->connection->connectionId, $command->payment)]
            ?? new GatewayPaymentResult(PaymentAttemptStateEnum::ReconciliationRequired, 'not_found');
    }

    public function capture(CapturePaymentCommand $command): GatewayPaymentResult
    {
        $this->requireCapability(GatewayCapability::Capture, $command->request->correlationId, [
            'attempt_provider_state' => 'requires_capture',
        ]);
        if ($this->fault === ProviderFault::Authentication) {
            $this->calls['capture'] = ($this->calls['capture'] ?? 0) + 1;
            throw new GatewayAuthenticationFailed($command->request->correlationId);
        }

        $result = match ($this->fault) {
            ProviderFault::Decline => new GatewayPaymentResult(PaymentAttemptStateEnum::Failed, 'capture_failed', $command->payment),
            ProviderFault::Timeout => new GatewayPaymentResult(PaymentAttemptStateEnum::ReconciliationRequired, 'capture_timeout', $command->payment),
            default => new GatewayPaymentResult(PaymentAttemptStateEnum::Succeeded, 'captured', $command->payment, $command->money),
        };

        /** @var GatewayPaymentResult $stored */
        $stored = $this->mutate($command->connection->connectionId, 'capture', $command->request->idempotencyKey, $this->fingerprint([
            'connection' => $command->connection->jsonSerialize(),
            'operation_id' => $command->request->operationId,
            'payment' => $command->payment->value,
            'minor_amount' => $command->money->minorAmount,
            'currency' => $command->money->currency,
            'metadata' => $command->metadata->jsonSerialize(),
        ]), $result, $command->request->correlationId);
        $this->payments[$this->remoteKey($command->connection->connectionId, $command->payment)] = $stored;

        return $stored;
    }

    public function cancel(CancelPaymentCommand $command): GatewayPaymentResult
    {
        $this->requireCapability(GatewayCapability::Cancel, $command->request->correlationId);
        if ($this->fault === ProviderFault::Authentication) {
            $this->calls['cancel'] = ($this->calls['cancel'] ?? 0) + 1;
            throw new GatewayAuthenticationFailed($command->request->correlationId);
        }

        $result = match ($this->fault) {
            ProviderFault::Decline => new GatewayPaymentResult(PaymentAttemptStateEnum::Failed, 'cancel_failed', $command->payment),
            ProviderFault::Timeout => new GatewayPaymentResult(PaymentAttemptStateEnum::ReconciliationRequired, 'cancel_timeout', $command->payment),
            default => new GatewayPaymentResult(PaymentAttemptStateEnum::Canceled, 'canceled', $command->payment),
        };

        /** @var GatewayPaymentResult $stored */
        $stored = $this->mutate($command->connection->connectionId, 'cancel', $command->request->idempotencyKey, $this->fingerprint([
            'connection' => $command->connection->jsonSerialize(),
            'operation_id' => $command->request->operationId,
            'payment' => $command->payment->value,
            'metadata' => $command->metadata->jsonSerialize(),
        ]), $result, $command->request->correlationId);
        $this->payments[$this->remoteKey($command->connection->connectionId, $command->payment)] = $stored;

        return $stored;
    }

    public function refund(RefundPaymentCommand $command): GatewayRefundResult
    {
        $this->requireCapability(GatewayCapability::Refund, $command->request->correlationId);
        if ($this->fault === ProviderFault::Authentication) {
            $this->calls['refund'] = ($this->calls['refund'] ?? 0) + 1;
            throw new GatewayAuthenticationFailed($command->request->correlationId);
        }

        $reference = new ProviderObjectReference('fake_ref_'.substr(hash('sha256', $command->request->idempotencyKey), 0, 24));
        $result = match ($this->fault) {
            ProviderFault::Decline => new GatewayRefundResult(PaymentRefundStateEnum::Failed, 'refund_failed', $reference),
            ProviderFault::Timeout => new GatewayRefundResult(PaymentRefundStateEnum::ReconciliationRequired, 'refund_timeout', $reference),
            default => new GatewayRefundResult(PaymentRefundStateEnum::Succeeded, 'succeeded', $reference, $command->money),
        };

        /** @var GatewayRefundResult $stored */
        $stored = $this->mutate($command->connection->connectionId, 'refund', $command->request->idempotencyKey, $this->fingerprint([
            'connection' => $command->connection->jsonSerialize(),
            'operation_id' => $command->request->operationId,
            'payment' => $command->payment->value,
            'minor_amount' => $command->money->minorAmount,
            'currency' => $command->money->currency,
            'metadata' => $command->metadata->jsonSerialize(),
        ]), $result, $command->request->correlationId);
        $this->refunds[$this->remoteKey($command->connection->connectionId, $reference)] = $stored;

        return $stored;
    }

    public function retrieveRefund(RetrieveRefundCommand $command): GatewayRefundResult
    {
        $this->requireCapability(GatewayCapability::RetrieveRefund, $command->request->correlationId);
        $this->calls['retrieve_refund'] = ($this->calls['retrieve_refund'] ?? 0) + 1;

        return $this->refunds[$this->remoteKey($command->connection->connectionId, $command->refund)]
            ?? new GatewayRefundResult(PaymentRefundStateEnum::ReconciliationRequired, 'not_found');
    }

    public function verifyWebhook(VerifyWebhookCommand $command): VerifiedGatewayEvent
    {
        $this->requireCapability(GatewayCapability::WebhookVerification, $command->correlationId);

        if (($command->headers()['Provider-Signature'] ?? null) !== 'valid-signature') {
            throw new WebhookVerificationFailed($command->correlationId);
        }

        try {
            /** @var array{id?: mixed, type?: mixed, payment?: mixed, status?: mixed} $payload */
            $payload = json_decode($command->rawBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WebhookVerificationFailed($command->correlationId);
        }

        if (! is_string($payload['id'] ?? null) || ! is_string($payload['type'] ?? null)) {
            throw new WebhookVerificationFailed($command->correlationId);
        }

        $eventKey = $command->connection->connectionId.'|'.$payload['id'];
        if (isset($this->events[$eventKey])) {
            if (! hash_equals($this->events[$eventKey]->payloadSha256, hash('sha256', $command->rawBody()))) {
                throw new WebhookPayloadConflict($command->correlationId);
            }

            return $this->events[$eventKey];
        }

        $this->calls['verify_webhook'] = ($this->calls['verify_webhook'] ?? 0) + 1;
        $event = new VerifiedGatewayEvent(
            $payload['id'],
            $payload['type'],
            $this->operationStartedAt,
            hash('sha256', $command->rawBody()),
            isset($payload['payment']) && is_string($payload['payment']) ? new ProviderObjectReference($payload['payment']) : null,
            payload: new RedactedData(['raw_status' => is_string($payload['status'] ?? null) ? $payload['status'] : 'unknown']),
        );
        $this->events[$eventKey] = $event;

        return $event;
    }

    public function callCount(string $operation): int
    {
        return $this->calls[$operation] ?? 0;
    }

    /** @param array<string, bool|string> $facts */
    private function requireCapability(GatewayCapability $operation, string $correlationId, array $facts = []): void
    {
        if ($this->capabilitySet->supports($operation, $this->operationStartedAt, $facts)) {
            return;
        }

        throw new UnsupportedPaymentOperation(
            $operation,
            $this->capabilitySet->id,
            $this->capabilitySet->revision,
            $this->capabilitySet->apiVersion,
            $correlationId,
        );
    }

    private function mutate(
        string $connectionId,
        string $operation,
        string $idempotencyKey,
        string $fingerprint,
        GatewayPaymentResult|GatewayRefundResult $result,
        string $correlationId,
    ): GatewayPaymentResult|GatewayRefundResult {
        $key = "{$connectionId}|{$operation}|{$idempotencyKey}";
        if (isset($this->mutations[$key])) {
            if (! hash_equals($this->mutations[$key]['fingerprint'], $fingerprint)) {
                throw new IdempotencyPayloadMismatch($correlationId);
            }

            return $this->mutations[$key]['result'];
        }

        $this->calls[$operation] = ($this->calls[$operation] ?? 0) + 1;
        $this->mutations[$key] = ['fingerprint' => $fingerprint, 'result' => $result];

        return $result;
    }

    /** @param array<string, mixed> $values */
    private function fingerprint(array $values): string
    {
        return hash('sha256', json_encode($this->canonicalize($values), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function remoteKey(string $connectionId, ProviderObjectReference $reference): string
    {
        return $connectionId.'|'.$reference->value;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
