<?php

namespace App\Services\Payment\Gateway\Stripe;

use App\Models\PaymentGatewayConnection;
use App\Services\Payment\Gateway\Commands\CancelPaymentCommand;
use App\Services\Payment\Gateway\Commands\CapturePaymentCommand;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Commands\RefundPaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrievePaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrieveRefundCommand;
use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Contracts\PaymentGatewayContract;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Gateway\Exceptions\UnsupportedPaymentOperation;
use App\Services\Payment\Gateway\Exceptions\WebhookVerificationFailed;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\Results\GatewayRefundResult;
use App\Services\Payment\Gateway\Results\VerifiedGatewayEvent;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\ConnectionLocator;
use App\Services\Payment\Gateway\ValueObjects\ConnectionLookup;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use App\Services\Payment\ProviderEvent\LegacyGlobalStripeConnection;
use App\Services\Payment\ProviderEvent\StripePlatformAccount;
use App\Services\Payment\Secret\GatewayConnectionSecretResolver;
use App\Services\Payment\Secret\ValueObjects\GatewaySecretAccessContext;
use App\Support\ZeroDecimalCurrency;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Charge;
use Stripe\Collection;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Terminal\Location;
use Stripe\Terminal\Reader;
use Stripe\Webhook;

/** Stripe SDK adapter — no Eloquent, orders, or ledger mutations. */
final class StripePaymentGateway implements PaymentGatewayContract
{
    private ?StripeClient $client;

    private StripeLifecycleMapper $mapper;

    private StripeMutationIdempotency $idempotency;

    public function __construct(
        ?StripeClient $client = null,
        ?StripeLifecycleMapper $mapper = null,
        ?StripeMutationIdempotency $idempotency = null,
    ) {
        $this->client = $client;
        $this->mapper = $mapper ?? new StripeLifecycleMapper;
        $this->idempotency = $idempotency ?? new StripeMutationIdempotency;
    }

    /**
     * #1232 — built on FIRST USE, never in the constructor. This adapter is a
     * container singleton pulled in by anything that touches payments (the
     * PayPay webhook path resolves it transitively), so constructing the SDK
     * eagerly made a missing STRIPE_SECRET fatal for code that never calls
     * Stripe. The failure stays loud for callers that DO reach the API.
     */
    private function client(): StripeClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $secret = (string) config('services.stripe.secret');

        if ($secret === '') {
            throw new RuntimeException('STRIPE_SECRET is not configured.');
        }

        return $this->client = new StripeClient($secret);
    }

    public function capabilities(GatewayConnectionData $connection): CapabilitySet
    {
        return StripeCapabilitySet::forEnvironment($connection->environment);
    }

    /**
     * #2938 — kiến thức phân giải webhook của Stripe, ở TRONG adapter Stripe.
     *
     * Trước #2938 ba đoạn dưới đây nằm trong `WebhookConnectionResolver` sau
     * một `match ($provider)`. Chúng không đổi hành vi khi chuyển về đây; chỉ
     * đổi CHỖ Ở, để nhà cung cấp thứ tư không phải sửa một file dùng chung.
     *
     * Ba lượt tra, theo đúng thứ tự cũ:
     *
     * 1. **Connect** — `account` ở gốc sự kiện là định danh mạnh nhất Stripe
     *    cấp, và rào DB `payment_gateway_connections_merchant_unique`
     *    (provider_id, environment, merchant_account_id) đảm bảo nó không nhập
     *    nhằng. Cờ `haltWhenOnlyInactiveMatches` là vế **#1109**: merchant BIẾT
     *    nhưng đã tắt phải TỪ CHỐI ngay, không được rơi tiếp xuống bước 2/3 —
     *    tắt kích hoạt là công tắc chặn thu.
     *
     * 2. **#2893 — tài khoản NỀN.** KHÔNG có `account` ⇒ sự kiện thuộc về tài
     *    khoản mà `STRIPE_SECRET` xác thực thành. Nó có chủ thật, và chủ đó là
     *    hàng connection mang đúng định danh `acct_…` ấy. Cùng một khoá cho cả
     *    hai ca: Connect thì khoá nằm trên sự kiện, nền thì khoá nằm trong cấu
     *    hình — không ca nào phải đoán.
     *
     *    Trước #2893 mọi sự kiện tài khoản nền rơi vào hàng tổng hợp
     *    `LegacyGlobalStripeConnection`, thuộc một tổ chức KHÔNG có thành viên
     *    nào; hệ quả đo được là 747 hàng settlement (¥939.235) vô hình với
     *    chính chủ sở hữu, vì `SettlementController` lọc theo org+brand của
     *    người đăng nhập.
     *
     * 3. **Lưới cuối, CỐ Ý giữ** — `STRIPE_ACCOUNT_ID` chưa khai (hoặc chưa
     *    hàng nào mang định danh đó: cửa sổ giữa lượt deploy mã và lượt chạy
     *    `payments:migrate-stripe-attribution`) thì webhook vẫn phải vào sổ.
     *    Rơi vào đây KHÔNG còn là chuyện bình thường, nên nó kêu: hàng tổng hợp
     *    đã ngưng dùng, và mỗi dòng log này là một bản ghi tiền quy sai chủ.
     *
     * @param  array<string, mixed>  $payload
     */
    public function identifyConnection(array $payload): ?ConnectionLocator
    {
        $lookups = [];
        $binding = [];

        $account = $payload['account'] ?? null;
        if (is_string($account) && $account !== '') {
            $lookups[] = ConnectionLookup::merchantAccount([$account], haltWhenOnlyInactiveMatches: true);

            // Định danh sự kiện TỰ KHAI — dùng để chặn `?connection={uuid}`
            // rehome một sự kiện ký hợp lệ sang connection khác.
            $binding[] = $account;
        }

        $platformAccount = StripePlatformAccount::accountId();
        if ($platformAccount !== null) {
            $lookups[] = ConnectionLookup::merchantAccount(
                [$platformAccount],
                StripePlatformAccount::environment(),
            );
        }

        $lookups[] = ConnectionLookup::designated(
            LegacyGlobalStripeConnection::CONNECTION_ID,
            'stripe_webhook_attributed_to_retired_connection',
            [
                'connection_id' => LegacyGlobalStripeConnection::CONNECTION_ID,
                'platform_account_configured' => $platformAccount !== null,
                'remedy' => $platformAccount === null
                    ? 'đặt STRIPE_ACCOUNT_ID rồi chạy `php artisan payments:migrate-stripe-attribution --apply`'
                    : 'chưa connection nào mang merchant_account_id='.$platformAccount,
            ],
        );

        return new ConnectionLocator($lookups, $binding);
    }

    public function preparePayment(CreatePaymentCommand $command): GatewayPaymentResult
    {
        $this->assertCapability($command->connection, GatewayCapability::Create, $command->request->correlationId);

        $fingerprint = StripeMutationIdempotency::fingerprint([
            'connection' => $command->connection->jsonSerialize(),
            'operation_id' => $command->request->operationId,
            'order_id' => $command->orderId,
            'option_id' => $command->gatewayOptionId,
            'minor_amount' => $command->money->minorAmount,
            'currency' => $command->money->currency,
            'operation' => $command->operation->value,
            'channel' => $command->channel->value,
            'policy_revision' => $command->policyRevision,
            'payment_source_sha256' => $command->paymentSourceReference() === null
                ? null
                : hash('sha256', $command->paymentSourceReference()),
            'metadata' => $command->metadata->jsonSerialize(),
        ]);

        return $this->idempotency->payment(
            $command->connection->connectionId,
            'create',
            $command->request->idempotencyKey,
            $fingerprint,
            $command->request->correlationId,
            fn (): GatewayPaymentResult => $this->mapper->mapPaymentIntent(
                $this->client()->paymentIntents->create([
                    'amount' => $command->money->minorAmount,
                    'currency' => strtolower($command->money->currency),
                    // #1125 (D3 hotfix) — card ONLY. automatic_payment_methods opened
                    // Konbini/bank-transfer, async flows this pipeline cannot settle
                    // (no processing/failed handlers): a valid voucher got a 422 and
                    // late-succeeding money landed unrecorded. Re-enable per method
                    // only alongside real async support (#1125 option B).
                    'payment_method_types' => ['card'],
                    'metadata' => $this->stripeMetadata($command),
                ], array_merge(
                    StripeConnectScope::requestOptions($command->connection),
                    ['idempotency_key' => $command->request->idempotencyKey],
                )),
                $command->connection,
            ),
            fn (string $paymentIntentId): GatewayPaymentResult => $this->mapper->mapPaymentIntent(
                $this->client()->paymentIntents->retrieve(
                    $paymentIntentId,
                    [],
                    StripeConnectScope::requestOptions($command->connection),
                ),
                $command->connection,
            ),
        );
    }

    public function retrievePayment(RetrievePaymentCommand $command): GatewayPaymentResult
    {
        $this->assertCapability($command->connection, GatewayCapability::RetrievePayment, $command->request->correlationId);

        $scope = StripeConnectScope::requestOptions($command->connection);

        try {
            $intent = $this->client()->paymentIntents->retrieve($command->payment->value, [], $scope);
        } catch (InvalidRequestException $e) {
            // #1108 — transition fallback until the T7.3 credential cutover:
            // customer-web intents are still CREATED on the platform account
            // while attempts already carry the policy-backed Connect
            // connection, so a scoped retrieve 404s. Retrying platform-scoped
            // is self-limiting — it only succeeds when the object genuinely
            // lives on the platform account.
            if ($scope === [] || $e->getStripeCode() !== 'resource_missing') {
                throw $e;
            }

            $intent = $this->client()->paymentIntents->retrieve($command->payment->value, []);
        }

        return $this->mapper->mapPaymentIntent($intent, $command->connection);
    }

    public function capture(CapturePaymentCommand $command): GatewayPaymentResult
    {
        $this->assertCapability($command->connection, GatewayCapability::Capture, $command->request->correlationId, [
            'attempt_provider_state' => 'requires_capture',
        ]);

        $fingerprint = StripeMutationIdempotency::fingerprint([
            'connection' => $command->connection->jsonSerialize(),
            'operation_id' => $command->request->operationId,
            'payment' => $command->payment->value,
            'minor_amount' => $command->money->minorAmount,
            'currency' => $command->money->currency,
            'metadata' => $command->metadata->jsonSerialize(),
        ]);

        return $this->idempotency->payment(
            $command->connection->connectionId,
            'capture',
            $command->request->idempotencyKey,
            $fingerprint,
            $command->request->correlationId,
            fn (): GatewayPaymentResult => $this->mapper->mapPaymentIntent(
                $this->client()->paymentIntents->capture(
                    $command->payment->value,
                    ['amount_to_capture' => $command->money->minorAmount],
                    array_merge(
                        StripeConnectScope::requestOptions($command->connection),
                        ['idempotency_key' => $command->request->idempotencyKey],
                    ),
                ),
                $command->connection,
            ),
            fn (string $paymentIntentId): GatewayPaymentResult => $this->retrievePayment(new RetrievePaymentCommand(
                $command->connection,
                $command->request,
                new ProviderObjectReference($paymentIntentId),
            )),
        );
    }

    public function cancel(CancelPaymentCommand $command): GatewayPaymentResult
    {
        $this->assertCapability($command->connection, GatewayCapability::Cancel, $command->request->correlationId);

        $fingerprint = StripeMutationIdempotency::fingerprint([
            'connection' => $command->connection->jsonSerialize(),
            'operation_id' => $command->request->operationId,
            'payment' => $command->payment->value,
            'metadata' => $command->metadata->jsonSerialize(),
        ]);

        return $this->idempotency->payment(
            $command->connection->connectionId,
            'cancel',
            $command->request->idempotencyKey,
            $fingerprint,
            $command->request->correlationId,
            fn (): GatewayPaymentResult => $this->mapper->mapPaymentIntent(
                $this->client()->paymentIntents->cancel(
                    $command->payment->value,
                    [],
                    array_merge(
                        StripeConnectScope::requestOptions($command->connection),
                        ['idempotency_key' => $command->request->idempotencyKey],
                    ),
                ),
                $command->connection,
            ),
            fn (string $paymentIntentId): GatewayPaymentResult => $this->retrievePayment(new RetrievePaymentCommand(
                $command->connection,
                $command->request,
                new ProviderObjectReference($paymentIntentId),
            )),
        );
    }

    public function refund(RefundPaymentCommand $command): GatewayRefundResult
    {
        $this->assertCapability($command->connection, GatewayCapability::Refund, $command->request->correlationId);

        $fingerprint = StripeMutationIdempotency::fingerprint([
            'connection' => $command->connection->jsonSerialize(),
            'operation_id' => $command->request->operationId,
            'payment' => $command->payment->value,
            'minor_amount' => $command->money->minorAmount,
            'currency' => $command->money->currency,
            'metadata' => $command->metadata->jsonSerialize(),
        ]);

        return $this->idempotency->refund(
            $command->connection->connectionId,
            'refund',
            $command->request->idempotencyKey,
            $fingerprint,
            $command->request->correlationId,
            fn (): GatewayRefundResult => $this->mapper->mapRefund(
                $this->client()->refunds->create([
                    'payment_intent' => $command->payment->value,
                    'amount' => $command->money->minorAmount,
                ], array_merge(
                    StripeConnectScope::requestOptions($command->connection),
                    ['idempotency_key' => 'refund_'.$command->request->idempotencyKey],
                )),
                $command->connection,
            ),
            fn (string $refundId): GatewayRefundResult => $this->retrieveRefund(new RetrieveRefundCommand(
                $command->connection,
                $command->request,
                new ProviderObjectReference($refundId),
            )),
        );
    }

    public function retrieveRefund(RetrieveRefundCommand $command): GatewayRefundResult
    {
        $this->assertCapability($command->connection, GatewayCapability::RetrieveRefund, $command->request->correlationId);

        return $this->mapper->mapRefund(
            $this->client()->refunds->retrieve(
                $command->refund->value,
                [],
                StripeConnectScope::requestOptions($command->connection),
            ),
            $command->connection,
        );
    }

    public function verifyWebhook(VerifyWebhookCommand $command): VerifiedGatewayEvent
    {
        $this->assertCapability($command->connection, GatewayCapability::WebhookVerification, $command->correlationId);

        $payload = $command->rawBody();
        $payloadHash = hash('sha256', $payload);
        $signature = (string) ($command->headers()['Stripe-Signature'] ?? $command->headers()['stripe-signature'] ?? '');

        if ($signature === '') {
            throw new WebhookVerificationFailed($command->correlationId);
        }

        $event = null;
        foreach ($this->webhookSecretCandidates($command->connection) as $secret) {
            try {
                $event = Webhook::constructEvent($payload, $signature, $secret);
                break;
            } catch (SignatureVerificationException) {
                continue;
            } catch (\UnexpectedValueException) {
                throw new WebhookVerificationFailed($command->correlationId);
            }
        }

        if ($event === null) {
            throw new WebhookVerificationFailed($command->correlationId);
        }

        return $this->mapper->mapVerifiedEvent($event, $payloadHash, $command->connection);
    }

    /**
     * Raw Stripe passthrough helpers used by the legacy customer-web synchronous
     * path. Every call is scoped to its owning connection via StripeConnectScope so
     * a Connect merchant's money can never silently land on the platform account —
     * the connection argument is REQUIRED so no caller can forget the scope. For the
     * legacy global-platform connection the scope resolves to empty options (platform).
     */
    public function retrievePaymentIntent(string $paymentIntentId, GatewayConnectionData $connection): PaymentIntent
    {
        $scope = StripeConnectScope::requestOptions($connection);

        return $scope === []
            ? $this->client()->paymentIntents->retrieve($paymentIntentId)
            : $this->client()->paymentIntents->retrieve($paymentIntentId, [], $scope);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function createPaymentIntent(array $params, GatewayConnectionData $connection, ?string $idempotencyKey = null): PaymentIntent
    {
        $options = StripeConnectScope::requestOptions($connection);

        // #555 M10 — a client retry (poll timeout, flaky network) with the same
        // key must return the SAME PaymentIntent instead of minting a second
        // charge. Stripe dedupes create calls by this request option for 24h.
        if ($idempotencyKey !== null) {
            $options['idempotency_key'] = $idempotencyKey;
        }

        return $options === []
            ? $this->client()->paymentIntents->create($params)
            : $this->client()->paymentIntents->create($params, $options);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function updatePaymentIntent(string $paymentIntentId, array $params, GatewayConnectionData $connection): PaymentIntent
    {
        $scope = StripeConnectScope::requestOptions($connection);

        return $scope === []
            ? $this->client()->paymentIntents->update($paymentIntentId, $params)
            : $this->client()->paymentIntents->update($paymentIntentId, $params, $scope);
    }

    public function cancelPaymentIntent(string $paymentIntentId, GatewayConnectionData $connection): PaymentIntent
    {
        $scope = StripeConnectScope::requestOptions($connection);

        return $scope === []
            ? $this->client()->paymentIntents->cancel($paymentIntentId)
            : $this->client()->paymentIntents->cancel($paymentIntentId, [], $scope);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function createRefund(array $params, GatewayConnectionData $connection, array $options = []): Refund
    {
        return $this->client()->refunds->create(
            $params,
            array_merge($options, StripeConnectScope::requestOptions($connection)),
        );
    }

    // =========================================================================
    //  Stripe Terminal — server-driven card_present raw ops (#1088)
    //
    //  Thin SDK passthroughs, Connect-scoped like every other raw op. The
    //  orchestration (branch locations, reader rows, pending-ledger flow)
    //  lives in App\Services\Payment\Terminal\StripeTerminalService — this
    //  adapter never touches Eloquent.
    // =========================================================================

    /** @param array<string, mixed> $params */
    public function createTerminalLocation(array $params, GatewayConnectionData $connection): Location
    {
        $scope = StripeConnectScope::requestOptions($connection);

        return $scope === []
            ? $this->client()->terminal->locations->create($params)
            : $this->client()->terminal->locations->create($params, $scope);
    }

    /** @return Collection<Location> */
    public function listTerminalLocations(GatewayConnectionData $connection, int $limit = 100): Collection
    {
        $scope = StripeConnectScope::requestOptions($connection);

        return $scope === []
            ? $this->client()->terminal->locations->all(['limit' => $limit])
            : $this->client()->terminal->locations->all(['limit' => $limit], $scope);
    }

    /** @param array<string, mixed> $params */
    public function registerTerminalReader(array $params, GatewayConnectionData $connection): Reader
    {
        $scope = StripeConnectScope::requestOptions($connection);

        return $scope === []
            ? $this->client()->terminal->readers->create($params)
            : $this->client()->terminal->readers->create($params, $scope);
    }

    public function retrieveTerminalReader(string $readerId, GatewayConnectionData $connection): Reader
    {
        $scope = StripeConnectScope::requestOptions($connection);

        return $scope === []
            ? $this->client()->terminal->readers->retrieve($readerId)
            : $this->client()->terminal->readers->retrieve($readerId, [], $scope);
    }

    /** Hand a card_present PaymentIntent to a smart reader (server-driven). */
    public function processTerminalPaymentIntent(string $readerId, string $paymentIntentId, GatewayConnectionData $connection): Reader
    {
        $scope = StripeConnectScope::requestOptions($connection);
        $params = ['payment_intent' => $paymentIntentId];

        return $scope === []
            ? $this->client()->terminal->readers->processPaymentIntent($readerId, $params)
            : $this->client()->terminal->readers->processPaymentIntent($readerId, $params, $scope);
    }

    public function cancelTerminalReaderAction(string $readerId, GatewayConnectionData $connection): Reader
    {
        $scope = StripeConnectScope::requestOptions($connection);

        return $scope === []
            ? $this->client()->terminal->readers->cancelAction($readerId)
            : $this->client()->terminal->readers->cancelAction($readerId, [], $scope);
    }

    /** TEST MODE ONLY — simulated reader taps a simulated card. */
    public function simulateTerminalPaymentPresented(string $readerId, GatewayConnectionData $connection): Reader
    {
        $scope = StripeConnectScope::requestOptions($connection);

        return $scope === []
            ? $this->client()->testHelpers->terminal->readers->presentPaymentMethod($readerId)
            : $this->client()->testHelpers->terminal->readers->presentPaymentMethod($readerId, [], $scope);
    }

    public function retrieveCharge(string $chargeId, GatewayConnectionData $connection): Charge
    {
        $scope = StripeConnectScope::requestOptions($connection);

        return $scope === []
            ? $this->client()->charges->retrieve($chargeId)
            : $this->client()->charges->retrieve($chargeId, [], $scope);
    }

    /** @return array<string, string> */
    private function stripeMetadata(CreatePaymentCommand $command): array
    {
        $metadata = [];
        foreach ($command->metadata->jsonSerialize() as $key => $value) {
            if (is_string($value) || is_int($value)) {
                $metadata[$key] = (string) $value;
            }
        }

        $metadata['order_id'] = $command->orderId;
        $metadata['gateway_option_id'] = $command->gatewayOptionId;
        $metadata['idempotency_key'] = $command->request->idempotencyKey;

        return $metadata;
    }

    /**
     * Webhook secret candidates, newest first. Legacy global connection keeps
     * the single STRIPE_WEBHOOK_SECRET; real connections resolve per-connection
     * secrets from the GatewaySecretStore (plan-048 T3.3), returning every
     * still-valid candidate so signature checks keep passing through the
     * dual-read rotation window (047 SECRET-STORE-RUNBOOK).
     *
     * @return list<string>
     */
    private function webhookSecretCandidates(GatewayConnectionData $connection): array
    {
        if (LegacyGlobalStripeConnection::isLegacy($connection)) {
            $secret = (string) config('services.stripe.webhook_secret');

            if ($secret === '') {
                throw new RuntimeException('STRIPE_WEBHOOK_SECRET is not configured.');
            }

            return [$secret];
        }

        $model = PaymentGatewayConnection::query()->find($connection->connectionId);
        if ($model === null) {
            return [];
        }

        if ($model->webhook_secret_ref === null) {
            // revoke() also nulls webhook_secret_ref, so "ref is null" alone
            // cannot distinguish never-configured from deliberately revoked —
            // secret HISTORY does. A revoked merchant secret is an operator
            // kill switch: fail CLOSED (no candidates, no global fallback →
            // 400), never silently downgrade to the shared endpoint secret.
            $hasWebhookSecretHistory = DB::table('payment_gateway_secret_versions')
                ->where('connection_id', $model->id)
                ->where('purpose', 'webhook')
                ->exists();

            if ($hasWebhookSecretHistory) {
                Log::channel('payment_orchestration')->warning('stripe_webhook_secret_revoked_reject', [
                    'connection_id' => $connection->connectionId,
                ]);

                return [];
            }

            // Never rotated in — the ONLY state that may fall back to the
            // endpoint-global secret (#1109). A decrypt / master-key fault
            // (ref present, resolver throws below) fails LOUD instead and
            // surfaces as a 5xx via the #1112 mapping.
            Log::channel('payment_orchestration')->warning('stripe_webhook_using_global_secret_fallback', [
                'connection_id' => $connection->connectionId,
                'reason' => 'webhook_secret_not_rotated_in',
            ]);

            $candidates = [];
        } else {
            // Resolved from the container instead of the constructor: the
            // gateway is constructed positionally throughout the test suite
            // and the secret store is only needed on this webhook path.
            $resolver = app(GatewayConnectionSecretResolver::class);

            $context = new GatewaySecretAccessContext(
                (string) $model->organization_id,
                $connection->connectionId,
                $connection->provider,
                $connection->environment,
                'system:webhook-intake',
                'webhook:'.$connection->connectionId,
            );

            $candidates = array_map(
                static fn ($candidate): string => $candidate->use(static fn (string $plaintext): string => $plaintext),
                $resolver->webhookCandidates($context),
            );
        }

        // #1109 — transition guard until T7.3: Stripe signs every delivery to
        // one endpoint with that endpoint's signing secret (Connect events
        // included), so a connection whose per-connection secret has not been
        // rotated in yet must not brick its merchant. The global secret is the
        // LAST candidate — a rotated-in per-connection secret always wins.
        $global = (string) config('services.stripe.webhook_secret');
        if ($global !== '') {
            $candidates[] = $global;
        }

        return $candidates;
    }

    /** @param  array<string, bool|string>  $facts */
    private function assertCapability(
        GatewayConnectionData $connection,
        GatewayCapability $operation,
        string $correlationId,
        array $facts = [],
    ): void {
        $capabilities = $this->capabilities($connection);

        if ($capabilities->supports($operation, new DateTimeImmutable, $facts)) {
            return;
        }

        throw new UnsupportedPaymentOperation(
            $operation,
            $capabilities->id,
            $capabilities->revision,
            $capabilities->apiVersion,
            $correlationId,
        );
    }

    public function fromMinorUnits(int $minorAmount, string $currency): float
    {
        if (ZeroDecimalCurrency::contains($currency)) {
            return (float) $minorAmount;
        }

        return round($minorAmount / 100, 2);
    }

    public function toMinorUnits(float $amount, string $currency): int
    {
        if (ZeroDecimalCurrency::contains($currency)) {
            return (int) round($amount);
        }

        return (int) round($amount * 100);
    }
}
