<?php

/**
 * Plan 047 acceptance — ownership and tenant isolation A5–A9.
 */

use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Omnify\Enums\PaymentConnectionOwnerScopeEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Admin\PaymentAdminAuthorizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

describe('A5 cross-tenant concealment', function () {
    it('A5 hides foreign tenant connections from shop configuration API', function () {
        $fixtures = new PaymentPolicyApiFixtures;
        $fixtures->bind();
        $fixtures->seedConnection();

        $foreignOrg = Organization::factory()->create([
            'id' => (string) Str::uuid(),
            'console_organization_id' => (string) Str::uuid(),
        ]);
        $foreignBrand = Brand::factory()->create([
            'console_organization_id' => $foreignOrg->console_organization_id,
        ]);
        $provider = PaymentGatewayProvider::query()->firstOrCreate(
            ['code' => PaymentGatewayProviderCodeEnum::Stripe->value],
            ['is_active' => true],
        );

        $foreignConnectionId = (string) Str::uuid();
        DB::table('payment_gateway_connections')->insert([
            'id' => $foreignConnectionId,
            'provider_id' => $provider->id,
            'organization_id' => $foreignOrg->id,
            'brand_id' => $foreignBrand->id,
            'owner_branch_id' => null,
            'identity_brand_id' => (string) Str::uuid(),
            'owner_scope' => PaymentConnectionOwnerScopeEnum::Hq->value,
            'brand_owner_org_unit_id' => (string) Str::uuid(),
            'operator_org_unit_id' => (string) Str::uuid(),
            'ownership_revision' => 'foreign-rev',
            'environment' => PaymentGatewayEnvironmentEnum::Test->value,
            'merchant_account_id' => 'acct_foreign_secret',
            'merchant_display_name' => 'Foreign Merchant',
            'charge_model' => 'direct',
            'health' => 'ready',
            'health_reason_code' => 'ready',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager = User::factory()->create([
            'console_organization_id' => $fixtures->organization->console_organization_id,
        ]);
        grantOrgAccess($manager, (string) $fixtures->organization->id);

        $this->actingAs($manager)
            ->getJson("{$fixtures->shopBase()}/payment-configuration")
            ->assertOk()
            ->assertJsonMissing(['merchant_account_id' => 'acct_foreign_secret']);

        $foreign = PaymentGatewayConnection::query()->findOrFail($foreignConnectionId);
        $service = app(PaymentAdminAuthorizationService::class);
        expect($service->concealUnlessOrganization($foreign, $fixtures->organization->id))->toBeNull();
    });
});

describe('A6 A7 authorization boundaries', function () {
    it('A6 returns identical ownership projection for manager and org admin', function () {
        $fixtures = new PaymentPolicyApiFixtures;
        $fixtures->bind();
        $fixtures->seedConnection();

        $admin = User::factory()->create([
            'console_organization_id' => $fixtures->organization->console_organization_id,
        ]);
        grantOrgAccess($admin, (string) $fixtures->organization->id, 'org-admin');

        $manager = User::factory()->create([
            'console_organization_id' => $fixtures->organization->console_organization_id,
        ]);
        grantOrgAccess($manager, (string) $fixtures->organization->id, 'shop-manager', $fixtures->shop->id);

        $adminConfig = $this->actingAs($admin)
            ->getJson("{$fixtures->shopBase()}/payment-configuration")
            ->assertOk()
            ->json('data.ownership');

        $managerConfig = $this->actingAs($manager)
            ->getJson("{$fixtures->shopBase()}/payment-configuration")
            ->assertOk()
            ->json('data.ownership');

        expect($managerConfig['management_model'])->toBe($adminConfig['management_model'])
            ->and($managerConfig['ownership_revision'])->toBe($adminConfig['ownership_revision']);
    });

    it('A7 denies device token from HQ gateway mutation routes', function () {
        $fixtures = new PaymentPolicyApiFixtures;
        $fixtures->bind();
        $fixtures->seedConnection();
        $device = $fixtures->seedDevice('pos');

        $this->withHeaders(['Authorization' => "Bearer {$device->device_token}"])
            ->postJson("{$fixtures->shopBase()}/payment-gateways", [
                'provider_id' => $fixtures->provider->id,
                'merchant_account_id' => 'acct_device_blocked',
            ])
            ->assertStatus(401);
    });
});

describe('A8 environment isolation', function () {
    it('A8 exposes only test-environment connections in effective options for test shops', function () {
        $fixtures = new PaymentPolicyApiFixtures;
        $fixtures->bind();

        $testConnection = $fixtures->seedConnection([
            'environment' => PaymentGatewayEnvironmentEnum::Test->value,
            'merchant_account_id' => 'acct_test_env',
        ]);

        $liveConnectionId = (string) Str::uuid();
        DB::table('payment_gateway_connections')->insert(array_merge([
            'id' => $liveConnectionId,
            'provider_id' => $fixtures->provider->id,
            'organization_id' => $fixtures->organization->id,
            'brand_id' => $fixtures->brand->id,
            'owner_branch_id' => null,
            'identity_brand_id' => $fixtures->identity['identity_brand_id'],
            'owner_scope' => PaymentConnectionOwnerScopeEnum::Hq->value,
            'brand_owner_org_unit_id' => $fixtures->identity['brand_owner_org_unit_id'],
            'operator_org_unit_id' => $fixtures->identity['operator_org_unit_id'],
            'ownership_revision' => $fixtures->identity['ownership_revision'],
            'environment' => PaymentGatewayEnvironmentEnum::Live->value,
            'merchant_account_id' => 'acct_live_env',
            'merchant_display_name' => 'Live Merchant',
            'charge_model' => 'direct',
            'health' => 'ready',
            'health_reason_code' => 'ready',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        DB::table('payment_gateway_connection_options')->insert([
            'id' => (string) Str::uuid(),
            'connection_id' => $liveConnectionId,
            'option_id' => $fixtures->option->id,
            'verification_state' => 'verified',
            'approved_currencies' => json_encode(['JPY'], JSON_THROW_ON_ERROR),
            'approved_channels' => json_encode(['pos'], JSON_THROW_ON_ERROR),
            'approved_operations' => json_encode(['sale'], JSON_THROW_ON_ERROR),
            'capability_revision' => 1,
            'effective_from' => now()->subDay(),
            'evidence_ref' => 'contract:live',
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fixtures->publishInitialPolicyRevision();

        $effective = $this->actingAs($fixtures->manager)
            ->getJson("{$fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data.options.0');

        expect($effective['connection_id'])->toBe((string) $testConnection->id)
            ->and($effective['connection_id'])->not->toBe($liveConnectionId);
    });
});

describe('A9 organization-scoped replication', function () {
    it('A9 workstation effective options never include another organization rows', function () {
        $orgB = (string) Str::uuid();
        Organization::factory()->create(['id' => $orgB, 'console_organization_id' => $orgB]);

        PaymentMethod::factory()->create([
            'organization_id' => $orgB,
            'branch_id' => null,
            'code' => 'cash_b_leak',
        ]);

        $fixturesA = new PaymentPolicyApiFixtures(shopSlug: 'ws-scope-a-'.Str::lower(Str::random(4)));
        $fixturesA->bind();
        $fixturesA->seedConnection();
        $fixturesA->publishInitialPolicyRevision();

        $device = Device::factory()->create([
            'type' => 'workstation',
            'status' => 'active',
            'device_token' => 'ws-scope-token',
            'organization_id' => $fixturesA->organization->id,
            'branch_id' => $fixturesA->shop->id,
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ws-scope-token'])
            ->getJson('/api/v1/workstation/effective-payment-options')
            ->assertOk()
            ->json('data');

        $legacyCodes = collect($response['options'] ?? [])->pluck('legacy_payment_method_code')->filter()->all();

        expect($legacyCodes)->not->toContain('cash_b_leak');
    });
});
