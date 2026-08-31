<?php

namespace App\Services\Payment\Orchestration\Internal;

use App\Models\Brand;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayConnectionOption;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentPolicyRevision;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Orchestration\ValueObjects\OrderRef;
use App\Services\Payment\Policy\UnresolvedOwnership;
use Database\Seeders\PaymentGatewayCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Ensures catalog-backed gateway rows exist for orchestrator prepare (Stripe customer web). */
final class PaymentGatewayOrchestrationBootstrap
{
    /**
     * @return array{connectionId: string, connectionOptionId: string, optionId: string, policyRevision: int}
     */
    public function resolveStripeCustomerWebForOrder(OrderRef $order): array
    {
        return DB::transaction(function () use ($order): array {
            $provider = $this->ensureStripeProvider();
            $option = $this->ensureCatalogOption($provider);
            $connection = $this->ensureOrgConnection($order, $provider);
            $connectionOption = $this->ensureConnectionOption($connection, $option);
            $policyRevision = $this->ensurePolicyRevision($order);

            return [
                'connectionId' => (string) $connection->id,
                'connectionOptionId' => (string) $connectionOption->id,
                'optionId' => (string) $option->id,
                'policyRevision' => (int) $policyRevision->revision,
            ];
        });
    }

    private function ensureStripeProvider(): PaymentGatewayProvider
    {
        $existing = PaymentGatewayProvider::query()
            ->where('code', PaymentGatewayProviderCodeEnum::Stripe->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return PaymentGatewayProvider::query()->create([
            'code' => PaymentGatewayProviderCodeEnum::Stripe->value,
            'is_active' => true,
            'name' => 'Stripe',
            'sort_order' => 10,
        ]);
    }

    private function ensureCatalogOption(PaymentGatewayProvider $provider): PaymentGatewayOption
    {
        $code = PaymentGatewayCatalogSeeder::STRIPE_OPTION_CODE;

        $existing = PaymentGatewayOption::query()
            ->where('provider_id', $provider->id)
            ->where('code', $code)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return PaymentGatewayOption::query()->create([
            'provider_id' => $provider->id,
            'code' => $code,
            'name:en' => 'Stripe card (customer web)',
            'name:ja' => 'Stripeカード（顧客Web）',
            'name:vi' => 'Stripe thẻ (web khách)',
            'integration_product' => 'payment_intents',
            'api_version' => '2026-06-30',
            'rail' => 'card',
            'method_type' => 'card',
            'brands' => ['account_configured'],
            'channels' => ['customer_web'],
            'device_classes' => ['browser'],
            'currencies' => [
                ['code' => 'JPY', 'minor_unit' => 0],
                ['code' => 'USD', 'minor_unit' => 2],
                ['code' => 'VND', 'minor_unit' => 0],
            ],
            'workflows' => ['sale', 'authorize_capture'],
            'operations' => [
                'create',
                'retrieve_payment',
                'capture',
                'cancel',
                'refund',
                'retrieve_refund',
                'webhook_verification',
            ],
            'revision' => 1,
            'effective_from' => '2026-07-22 00:00:00',
            'is_active' => true,
            'sort_order' => 10,
        ]);
    }

    /**
     * Resolve THIS org's connection — keyed on the tenant, never on the merchant label.
     *
     * The lookup used to key on `merchant_account_id` (the synthetic
     * `orchestrator:customer-web:{org}` string). That made the key correct only
     * for as long as nobody ever wrote a real PSP identifier into that column —
     * and #2893 did exactly that, on purpose: the ruling there requires the
     * connection to carry the real Stripe account (`acct_…`) so payout
     * reconciliation is unambiguous when one PSP account is shared.
     *
     * The moment that stamp landed on production the lookup stopped matching,
     * so this method created a SECOND connection carrying the old synthetic
     * label, and Stripe attempts started landing on it while the webhook's
     * provider events kept landing on the stamped one. `ProviderEventApplicator`
     * matches `(connection_id, provider_object_id)`, so every pair missed and
     * the whole flow fell back to `LegacyStripeWebhookBridge` — which is
     * fail-open, so the money stayed correct and NOTHING went red. Seven
     * payments split the ledger before anyone measured it (#3070).
     *
     * Keying on `(provider, environment, organization, brand)` is the property
     * this method actually means — its own name says "org connection". The
     * merchant column is then free to hold whatever identity the PSP requires
     * without silently forking the tenant's connection.
     *
     * The ordering is a TRANSITIONAL guard, not the design. Production still
     * carries the duplicate this bug created, and until it is retired the query
     * must resolve to the same row every time — the tenant's original one.
     * Deliberately NOT a rule about what the merchant column looks like:
     * sniffing an identifier by prefix (`acct_…`) guesses at a vendor's format
     * that nobody has promised to keep, and would be a second heuristic layered
     * on the first.
     *
     * The real fix is that two rows must be impossible: the natural key of a
     * connection is `(provider, environment, organization, brand)` and belongs
     * in a UNIQUE index. Once that lands, this ordering has nothing left to
     * choose between and should be deleted with it (#3070).
     */
    private function ensureOrgConnection(OrderRef $order, PaymentGatewayProvider $provider): PaymentGatewayConnection
    {
        $environment = $this->resolveEnvironment();
        $merchantAccountId = 'orchestrator:customer-web:'.(string) $order->organizationId;

        $existing = PaymentGatewayConnection::query()
            ->where('provider_id', $provider->id)
            ->where('environment', $environment->value)
            ->where('organization_id', (string) $order->organizationId)
            ->where('brand_id', (string) $order->brandId)
            ->oldest('created_at')
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $brand = Brand::query()->findOrFail($order->brandId);

        return PaymentGatewayConnection::query()->create([
            'provider_id' => $provider->id,
            'organization_id' => $order->organizationId,
            'brand_id' => $order->brandId,
            'identity_brand_id' => (string) $brand->id,
            'owner_scope' => 'hq',
            // #3084 — sentinel, KHÔNG phải `Str::uuid()`. Hai cột này là câu trả
            // lời cho "tiền thuộc về ai về mặt pháp lý", và đường customer-web
            // không biết câu đó: nguồn là Identity, mà nguồn đọc còn chưa có.
            // Một UUID ngẫu nhiên ở đây là câu trả lời SAI trông như dữ liệu thật;
            // sentinel nói đúng một câu — chưa phân giải — và tra lại được.
            'brand_owner_org_unit_id' => UnresolvedOwnership::BRAND_OWNER_ORG_UNIT_ID,
            'operator_org_unit_id' => UnresolvedOwnership::OPERATOR_ORG_UNIT_ID,
            'ownership_revision' => 'orchestrator-customer-web-v1',
            'environment' => $environment->value,
            'merchant_account_id' => $merchantAccountId,
            'merchant_display_name' => 'Customer-web Stripe',
            'charge_model' => 'direct',
            'health' => 'ready',
            'is_active' => true,
        ]);
    }

    private function ensureConnectionOption(
        PaymentGatewayConnection $connection,
        PaymentGatewayOption $option,
    ): PaymentGatewayConnectionOption {
        $existing = PaymentGatewayConnectionOption::query()
            ->where('connection_id', $connection->id)
            ->where('option_id', $option->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return PaymentGatewayConnectionOption::query()->create([
            'connection_id' => $connection->id,
            'option_id' => $option->id,
            'verification_state' => 'verified',
            'approved_currencies' => ['JPY', 'USD', 'VND'],
            'approved_channels' => ['customer_web'],
            'approved_operations' => [
                'create',
                'retrieve_payment',
                'capture',
                'cancel',
                'refund',
                'retrieve_refund',
                'webhook_verification',
            ],
            'capability_revision' => 1,
            'effective_from' => now(),
            'is_enabled' => true,
        ]);
    }

    private function ensurePolicyRevision(OrderRef $order): PaymentPolicyRevision
    {
        $existing = PaymentPolicyRevision::query()
            ->where('branch_id', $order->branchId)
            ->where('revision', 1)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return PaymentPolicyRevision::query()->create([
            'organization_id' => $order->organizationId,
            'brand_id' => $order->brandId,
            'branch_id' => $order->branchId,
            'revision' => 1,
            'ownership_revision' => 'orchestrator-customer-web-v1',
            'snapshot_hash' => hash('sha256', 'orchestrator-customer-web-policy-v1'),
            'snapshot' => ['source' => 'payment_gateway_orchestration_bootstrap'],
            'effective_option_count' => 1,
            'source' => 'orchestrator_bootstrap',
            'published_at' => now(),
        ]);
    }

    private function resolveEnvironment(): PaymentGatewayEnvironmentEnum
    {
        $secret = (string) config('services.stripe.secret', '');

        return str_contains($secret, '_live_')
            ? PaymentGatewayEnvironmentEnum::Live
            : PaymentGatewayEnvironmentEnum::Test;
    }
}
