<?php

namespace App\Services\Payment\ProviderEvent;

use App\Jobs\Payment\ProcessPaymentProviderEventJob;
use App\Models\PaymentProviderEvent;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\DomainMutation\MutationContext;
use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Exceptions\WebhookPayloadConflict;
use App\Services\Payment\Gateway\Exceptions\WebhookVerificationFailed;
use App\Services\Payment\Gateway\PaymentGatewayRegistry;
use App\Services\Payment\Gateway\Results\VerifiedGatewayEvent;
use App\Services\Payment\Orchestration\Commands\ProcessProviderEventCommand;
use App\Services\Payment\Orchestration\Contracts\PaymentMutationFacade;

final class ProviderEventIntakeService
{
    public function __construct(
        private readonly LegacyGlobalStripeConnection $legacyConnection,
        private readonly WebhookConnectionResolver $connectionResolver,
        private readonly PaymentGatewayRegistry $gatewayRegistry,
        private readonly PaymentMutationFacade $payments,
        private readonly ProviderEventInboxService $inbox,
    ) {}

    /**
     * Legacy Stripe alias (`POST /customer/stripe/webhook`) — same pipeline as
     * the generic intake, connection resolution included (Connect `account`
     * events on the old URL still land on the right merchant during Gate 3).
     */
    public function intakeLegacyStripeWebhook(string $payload, string $signatureHeader): ProviderEventIntakeResult
    {
        return $this->intakeProviderWebhook(
            PaymentGatewayProviderCodeEnum::Stripe,
            $payload,
            ['Stripe-Signature' => $signatureHeader],
        );
    }

    /**
     * Plan-048 Gate 3 (T3.1) — generic verified-webhook intake for
     * `POST /webhooks/payment/{provider}`. Verify → dedupe → inbox → async
     * process; 2xx only after the inbox row is durable.
     *
     * @param  array<string, string>  $headers
     */
    public function intakeProviderWebhook(
        PaymentGatewayProviderCodeEnum $provider,
        string $payload,
        array $headers,
        ?string $connectionHint = null,
        ?string $clientIp = null,
    ): ProviderEventIntakeResult {
        $correlationId = $provider->value.'-webhook:'.hash('sha256', $payload);

        $connection = $this->connectionResolver->resolve($provider, $payload, $connectionHint);
        if ($connection === null) {
            throw new WebhookVerificationFailed($correlationId);
        }

        $connectionData = (string) $connection->id === LegacyGlobalStripeConnection::CONNECTION_ID
            ? $this->legacyConnection->connectionData()
            : GatewayConnectionDataFactory::fromModel($connection);

        $gateway = $this->gatewayRegistry->forProvider($provider, $correlationId);

        try {
            $verified = $gateway->verifyWebhook(new VerifyWebhookCommand(
                $connectionData,
                $payload,
                $headers,
                $correlationId,
                $clientIp,
            ));
        } catch (WebhookVerificationFailed|WebhookPayloadConflict $e) {
            throw $e;
        }

        $context = new MutationContext(
            (string) $connection->organization_id,
            null,
            $correlationId,
            $verified->providerEventId,
        );

        $result = $this->payments->processProviderEvent(new ProcessProviderEventCommand(
            $context,
            (string) $connection->id,
            $verified,
        ));

        if ($result->deduplicated) {
            return new ProviderEventIntakeResult(true, true, $result->providerEventId);
        }

        $this->attachWebhookSnapshot($result->providerEventId, $payload, $verified);
        $this->inbox->queue($result->providerEventId);
        ProcessPaymentProviderEventJob::dispatch($result->providerEventId);

        return new ProviderEventIntakeResult(true, false, $result->providerEventId);
    }

    private function attachWebhookSnapshot(string $recordId, string $payload, VerifiedGatewayEvent $verified): void
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $object = $decoded['data']['object'] ?? null;

        if (! is_array($object)) {
            return;
        }

        $snapshotKey = match ($verified->eventType) {
            'payment_intent.succeeded' => 'intent_snapshot',
            // #1125 option B — async lifecycle events carry the intent object
            // so the applicator needs no Stripe round-trip.
            'payment_intent.processing',
            'payment_intent.payment_failed',
            'payment_intent.canceled' => 'intent_snapshot',
            'charge.refunded' => 'charge_snapshot',
            // #1123 (D2) — dispute lifecycle events carry the dispute object so
            // the applicator can move the ledger without a Stripe round-trip.
            'charge.dispute.created',
            'charge.dispute.closed',
            'charge.dispute.funds_withdrawn',
            'charge.dispute.funds_reinstated' => 'dispute_snapshot',
            // Plan-050 M2 (#1155) — payout events carry the payout object so
            // the settlement recorder can upsert gateway_payouts without a
            // Stripe round-trip (the mapper extracts no provider_object_id
            // for payouts, so the snapshot is the only reference).
            'payout.paid',
            'payout.failed' => 'payout_snapshot',
            default => null,
        };

        if ($snapshotKey === null) {
            return;
        }

        $existing = PaymentProviderEvent::query()->find($recordId);
        $payloadData = is_array($existing?->redacted_payload) ? $existing->redacted_payload : [];

        $snapshot = [$snapshotKey => $this->stripCardholderPii($object)];

        // For charge.refunded, surface the provider refund ids so the orchestrator
        // applicator can reconcile the matching PaymentRefund records' state (the
        // legacy bridge still owns the ledger reversal). Ids are not PII.
        if ($verified->eventType === 'charge.refunded') {
            $refundIds = [];
            foreach (($object['refunds']['data'] ?? []) as $refund) {
                $id = is_array($refund) ? ($refund['id'] ?? null) : null;
                if (is_string($id) && $id !== '') {
                    $refundIds[] = $id;
                }
            }
            if ($refundIds !== []) {
                $snapshot['refund_ids'] = array_values(array_unique($refundIds));
            }
        }

        PaymentProviderEvent::query()
            ->where('id', $recordId)
            ->update([
                'redacted_payload' => array_merge($payloadData, $snapshot),
            ]);
    }

    /**
     * Cardholder-PII keys that must never land at rest in `redacted_payload`.
     * The snapshot is only a cache for LegacyStripeWebhookBridge to rebuild the
     * Stripe object (via constructFrom) and avoid an API round-trip; it reads
     * only ids/amounts/currency/status/metadata/refunds — never these — so
     * stripping them removes no functional field.
     */
    private const CARDHOLDER_PII_KEYS = [
        'billing_details',
        'payment_method_details',
        'receipt_email',
        'shipping',
    ];

    /**
     * Recursively drop cardholder-PII keys (name/email/phone/address, card
     * brand/last4/fingerprint) wherever they appear in a Stripe object — top
     * level and nested (e.g. charges.data[]) — before it is persisted.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function stripCardholderPii(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::CARDHOLDER_PII_KEYS, true)) {
                unset($data[$key]);

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->stripCardholderPii($value);
            }
        }

        return $data;
    }
}
