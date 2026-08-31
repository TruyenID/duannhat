<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\DevicePaymentOption;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentGatewayProvider;
use App\Models\ShopPaymentOption;
use App\Models\User;
use App\Omnify\Enums\PaymentConnectionHealthEnum;
use App\Omnify\Enums\PaymentConnectionOwnerScopeEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentPolicyPreferenceEnum;
use App\Services\Payment\Secret\DatabaseGatewaySecretStore;
use App\Services\Payment\Secret\FileGatewayMasterKeyProvider;
use App\Services\Payment\Secret\GatewayConnectionSecretResolver;
use App\Services\Payment\Secret\GatewaySecretAuditProtection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fakes\Payment\StripeFakePaymentGateway;
use Tests\Support\Payment\PaymentGatewayFixtures;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-pay',
        'is_active' => true,
    ]);

    $this->hqBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'hq-store',
        'is_headquarters' => true,
        'is_active' => true,
    ]);

    $this->shopA = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'shop-a',
        'is_active' => true,
    ]);

    $this->shopB = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'shop-b',
        'is_active' => true,
    ]);

    $this->shopC = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'shop-c',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
    $this->actingAs($this->user);

    $this->provider = PaymentGatewayProvider::factory()->create([
        'code' => PaymentGatewayProviderCodeEnum::Stripe,
        'is_active' => true,
    ]);

    $this->option = PaymentGatewayOption::factory()->create([
        'provider_id' => $this->provider->id,
        'code' => PaymentGatewayFixtures::fullCapability()->id,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->keyringPath = realpath(sys_get_temp_dir()).'/tempo-hq-gateway-'.Str::uuid().'.json';
    file_put_contents($this->keyringPath, json_encode([
        'active_key_id' => 'payment-master-test-a',
        'keys' => [
            'payment-master-test-a' => 'base64:'.base64_encode(str_repeat('A', 32)),
        ],
    ], JSON_THROW_ON_ERROR));
    chmod($this->keyringPath, 0600);
    config(['payments.secret_store.keyring_path' => $this->keyringPath]);
    config(['payments.gateway_drivers.stripe' => StripeFakePaymentGateway::class]);

    $keys = new FileGatewayMasterKeyProvider($this->keyringPath, [base_path(), public_path()]);
    $auditProtection = new GatewaySecretAuditProtection(DB::connection());
    $auditProtection->install();
    app()->instance(GatewayConnectionSecretResolver::class, new GatewayConnectionSecretResolver(new DatabaseGatewaySecretStore(
        DB::connection(),
        $keys,
        $auditProtection,
    )));

    $this->identity = [
        'identity_brand_id' => (string) Str::uuid(),
        'brand_owner_org_unit_id' => (string) Str::uuid(),
        'operator_org_unit_id' => (string) Str::uuid(),
        'ownership_revision' => '10001',
    ];

    $this->base = "/api/v1/hq/{$this->brand->slug}";
});

afterEach(function () {
    if (isset($this->keyringPath) && is_file($this->keyringPath)) {
        unlink($this->keyringPath);
    }
});

function hqConnectionPayload(array $overrides = []): array
{
    return array_merge([
        'provider_code' => 'stripe',
        'environment' => 'test',
        'merchant_account_id' => 'acct_hq_admin_test',
        'merchant_display_name' => 'Acme HQ Merchant',
        'charge_model' => 'direct',
        'identity_brand_id' => test()->identity['identity_brand_id'],
        'brand_owner_org_unit_id' => test()->identity['brand_owner_org_unit_id'],
        'operator_org_unit_id' => test()->identity['operator_org_unit_id'],
        'ownership_revision' => test()->identity['ownership_revision'],
    ], $overrides);
}

function createHqConnection(array $overrides = []): PaymentGatewayConnection
{
    $response = test()->postJson(test()->base.'/payment-gateways', hqConnectionPayload($overrides));
    $response->assertCreated();

    return PaymentGatewayConnection::query()->findOrFail($response->json('data.id'));
}

describe('G1 list and detail', function () {
    it('lists HQ connections with safe display identity and health metadata', function () {
        $connection = createHqConnection();

        $this->getJson("{$this->base}/payment-gateways")
            ->assertOk()
            ->assertJsonPath('data.0.id', $connection->id)
            ->assertJsonPath('data.0.owner_scope', PaymentConnectionOwnerScopeEnum::Hq->value)
            ->assertJsonPath('data.0.environment', PaymentGatewayEnvironmentEnum::Test->value)
            ->assertJsonPath('data.0.merchant_display_name', 'Acme HQ Merchant')
            ->assertJsonPath('data.0.health', PaymentConnectionHealthEnum::PendingVerification->value)
            ->assertJsonMissingPath('data.0.secret_ref')
            ->assertJsonMissingPath('data.0.webhook_secret_ref');
    });

    it('shows connection detail with capabilities after validation', function () {
        $connection = createHqConnection();
        $this->postJson("{$this->base}/payment-gateways/{$connection->id}/rotate", [
            'api_secret' => 'sk_test_hq_validate_secret',
        ])->assertOk();

        $this->postJson("{$this->base}/payment-gateways/{$connection->id}/validate")
            ->assertOk()
            ->assertJsonPath('data.health', PaymentConnectionHealthEnum::Ready->value)
            ->assertJsonPath('data.last_validated_at', fn ($value) => $value !== null)
            ->assertJsonMissingPath('data.secret_ref');
    });

    it('exposes the provider webhook registration URL on connection detail (plan-048 T3.6)', function () {
        $connection = createHqConnection();

        $this->getJson("{$this->base}/payment-gateways/{$connection->id}")
            ->assertOk()
            ->assertJsonPath('data.webhook_url', fn ($value) => is_string($value)
                && str_contains($value, '/api/v1/webhooks/payment/')
                && str_contains($value, 'connection='.$connection->id));
    });
});

describe('G2 onboarding idempotency', function () {
    it('creates one HQ onboarding row per merchant identity', function () {
        $payload = hqConnectionPayload();

        $first = $this->postJson("{$this->base}/payment-gateways", $payload)->assertCreated();
        $second = $this->postJson("{$this->base}/payment-gateways", $payload)->assertOk();

        expect(PaymentGatewayConnection::query()->count())->toBe(1)
            ->and($first->json('data.id'))->toBe($second->json('data.id'));
    });
});

describe('G3 health states', function () {
    it('returns typed restriction for revoked connections', function () {
        $connection = createHqConnection(['merchant_account_id' => 'acct_revoked']);
        $connection->update([
            'health' => PaymentConnectionHealthEnum::Revoked,
            'health_reason_code' => 'revoked',
        ]);

        $this->postJson("{$this->base}/payment-gateways/{$connection->id}/validate")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PAYMENT_CONNECTION_RESTRICTED');
    });
});

describe('G4 secret redaction', function () {
    it('never echoes rotated secrets in responses', function () {
        $connection = createHqConnection();
        $secret = 'sk_test_NEVER_ECHO_THIS_SECRET_VALUE';

        $response = $this->postJson("{$this->base}/payment-gateways/{$connection->id}/rotate", [
            'api_secret' => $secret,
        ])->assertOk();

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        expect($encoded)->not->toContain($secret)
            ->and($response->json('data.key_fingerprint'))->toHaveLength(64);

        $this->getJson("{$this->base}/payment-gateways/{$connection->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.secret_ref');
    });

    it('rejects PAN-like credential payloads', function () {
        $this->postJson("{$this->base}/payment-gateways/".Str::uuid().'/rotate', [
            'api_secret' => 'sk_test_ok',
            'card_number' => '4111111111111111',
        ])->assertStatus(404);

        createHqConnection();
        $connection = PaymentGatewayConnection::query()->firstOrFail();

        $this->postJson("{$this->base}/payment-gateways/{$connection->id}/rotate", [
            'api_secret' => 'sk_test_ok',
            'card_number' => '4111111111111111',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'PAYMENT_SENSITIVE_DATA_REJECTED');
    });
});

describe('G5 disconnect impact', function () {
    it('lists exact shop/device impact and blocks unsafe disconnect', function () {
        $connection = createHqConnection(['merchant_account_id' => 'acct_disconnect']);

        foreach ([$this->shopA, $this->shopB, $this->shopC] as $shop) {
            ShopPaymentOption::factory()->create([
                'organization_id' => $this->orgId,
                'brand_id' => $this->brand->id,
                'branch_id' => $shop->id,
                'option_id' => $this->option->id,
                'connection_id' => $connection->id,
                'preference' => PaymentPolicyPreferenceEnum::Enabled,
            ]);
        }

        $devices = Device::factory()->count(5)->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->shopA->id,
        ]);

        foreach ($devices as $device) {
            $shopOption = ShopPaymentOption::query()->where('branch_id', $this->shopA->id)->firstOrFail();
            DevicePaymentOption::factory()->create([
                'device_id' => $device->id,
                'shop_payment_option_id' => $shopOption->id,
                'preference' => 'inherit',
            ]);
        }

        $this->getJson("{$this->base}/payment-gateways/{$connection->id}/disconnect-impact")
            ->assertOk()
            ->assertJsonPath('data.shop_count', 3)
            ->assertJsonPath('data.device_count', 5);

        $this->deleteJson("{$this->base}/payment-gateways/{$connection->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PAYMENT_GATEWAY_DISCONNECT_REQUIRES_CONFIRMATION');

        $this->deleteJson("{$this->base}/payment-gateways/{$connection->id}", ['confirm' => true])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PAYMENT_GATEWAY_DISCONNECT_BLOCKED');

        $this->deleteJson("{$this->base}/payment-gateways/{$connection->id}", [
            'confirm' => true,
            'acknowledge_shop_impact' => true,
        ])->assertNoContent();

        expect(PaymentGatewayConnection::withTrashed()->find($connection->id)?->trashed())->toBeTrue();
    });
});

describe('G6 HQ option policies', function () {
    it('distinguishes default on, default off, and blocked preferences', function () {
        $index = $this->getJson("{$this->base}/payment-options")->assertOk();
        $optionId = $index->json('data.0.option_id');

        $this->patchJson("{$this->base}/payment-options/{$optionId}", [
            'preference' => 'enabled',
        ])->assertOk()
            ->assertJsonPath('data.effective_preview', 'default_on');

        $this->patchJson("{$this->base}/payment-options/{$optionId}", [
            'preference' => 'disabled',
            'version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.effective_preview', 'default_off');

        $this->patchJson("{$this->base}/payment-options/{$optionId}", [
            'preference' => 'blocked',
            'version' => 2,
        ])->assertOk()
            ->assertJsonPath('data.effective_preview', 'blocked_upstream')
            ->assertJsonPath('data.owner_policy', 'denied');
    });
});

describe('payment coverage', function () {
    it('reports setup required when no ready HQ connection exists', function () {
        $this->getJson("{$this->base}/payment-coverage")
            ->assertOk()
            ->assertJsonPath('data.0.setup_required', true)
            ->assertJsonPath('data.0.public_error_code', 'PAYMENT_CONNECTION_REQUIRED');
    });
});
