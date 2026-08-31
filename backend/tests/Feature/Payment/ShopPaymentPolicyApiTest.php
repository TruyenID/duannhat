<?php

use App\Models\Device;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentPolicyRevision;
use App\Models\ShopPaymentOption;
use App\Omnify\Enums\PaymentConnectionOwnerScopeEnum;
use App\Services\Payment\Policy\Enums\BranchManagementModel;
use App\Services\Payment\Policy\Enums\UpstreamPolicyState;
use Illuminate\Support\Str;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

beforeEach(function () {
    $this->fixtures = new PaymentPolicyApiFixtures;
    $this->fixtures->bind();
    $this->actingAs($this->fixtures->manager);
});

describe('ownership scenarios A1-A4', function () {
    it('A1 HQ-managed shop resolves HQ connection in configuration and effective options', function () {
        $hq = $this->fixtures->seedConnection([
            'owner_scope' => PaymentConnectionOwnerScopeEnum::Hq->value,
            'merchant_account_id' => 'acct_hq_visible',
        ]);

        $config = $this->getJson("{$this->fixtures->shopBase()}/payment-configuration")
            ->assertOk()
            ->json('data');

        expect($config['ownership']['management_model'])->toBe('hq_managed')
            ->and($config['connection_mutable'])->toBeFalse()
            ->and($config['connections'][0]['id'])->toBe((string) $hq->id)
            ->and($config['connections'][0]['owner_scope'])->toBe('hq');

        $effective = $this->getJson("{$this->fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data.options.0');

        expect($effective['effective'])->toBeTrue()
            ->and($effective['owner_scope'])->toBe('hq')
            ->and($effective['connection_id'])->toBe((string) $hq->id)
            ->and($effective)->not->toHaveKey('secret')
            ->and($effective)->toHaveKeys(['id', 'display_name', 'provider', 'rail', 'source', 'reason', 'trace']);
    });

    it('A2 franchise shop uses franchise connection and never exposes HQ merchant', function () {
        $this->fixtures->switchManagementModel(BranchManagementModel::FranchiseOwned);

        $this->fixtures->seedConnection([
            'owner_scope' => PaymentConnectionOwnerScopeEnum::Hq->value,
            'merchant_account_id' => 'acct_hq_hidden',
        ]);

        $franchise = $this->fixtures->seedConnection([
            'owner_scope' => PaymentConnectionOwnerScopeEnum::Franchise->value,
            'merchant_account_id' => 'acct_franchise_shop',
        ]);

        $config = $this->getJson("{$this->fixtures->shopBase()}/payment-configuration")
            ->assertOk()
            ->json('data');

        expect($config['connection_mutable'])->toBeTrue()
            ->and($config['connections'])->toHaveCount(1)
            ->and($config['connections'][0]['id'])->toBe((string) $franchise->id)
            ->and(collect($config['connections'])->pluck('merchant_account_id'))
            ->not->toContain('acct_hq_hidden');

        $effective = $this->getJson("{$this->fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data.options.0');

        expect($effective['effective'])->toBeTrue()
            ->and($effective['owner_scope'])->toBe('franchise')
            ->and($effective['connection_id'])->toBe((string) $franchise->id);
    });

    it('A3 franchise without connection reports setup required and ineffective options', function () {
        $this->fixtures->switchManagementModel(BranchManagementModel::FranchiseOwned);

        $config = $this->getJson("{$this->fixtures->shopBase()}/payment-configuration")
            ->assertOk()
            ->json('data');

        expect($config['setup_required'])->toBeTrue()
            ->and($config['connections'])->toBe([])
            ->and($config['connection_mutable'])->toBeTrue();

        $effective = $this->getJson("{$this->fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data');

        expect($effective['options'])->toBe([]);
    });

    it('A4 ambiguous ownership fails closed in effective options', function () {
        $this->fixtures->seedConnection();
        $this->fixtures->registerUnresolvedOwnership();

        $effective = $this->getJson("{$this->fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data.options.0');

        expect($effective['effective'])->toBeFalse()
            ->and($effective['source'])->toBe('ownership')
            ->and($effective['error_code'])->toBe('PAYMENT_OWNERSHIP_UNRESOLVED');
    });
});

describe('policy narrowing B3-B6', function () {
    it('B3 HQ-blocked option cannot be widened by shop PATCH', function () {
        $this->fixtures->seedConnection();
        $this->fixtures->ownerPolicySource->set(
            (string) $this->fixtures->brand->id,
            (string) $this->fixtures->option->id,
            UpstreamPolicyState::Denied,
        );

        $before = PaymentPolicyRevision::query()->count();

        $this->patchJson("{$this->fixtures->shopBase()}/payment-options/{$this->fixtures->option->id}", [
            'preference' => 'enabled',
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'PAYMENT_POLICY_CANNOT_WIDEN');

        expect(PaymentPolicyRevision::query()->count())->toBe($before);

        $effective = $this->getJson("{$this->fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data.options.0');

        expect($effective['effective'])->toBeFalse()
            ->and($effective['source'])->toBe('owner_policy');
    });

    it('B4 shop disable then restore inherit publishes two revisions', function () {
        $this->fixtures->seedConnection();

        $this->patchJson("{$this->fixtures->shopBase()}/payment-options/{$this->fixtures->option->id}", [
            'preference' => 'disabled',
            'change_reason' => 'closing card lane',
        ])->assertOk()
            ->assertJsonPath('data.option.effective', false);

        $afterDisable = PaymentPolicyRevision::query()
            ->where('branch_id', $this->fixtures->shop->id)
            ->max('revision');

        $this->patchJson("{$this->fixtures->shopBase()}/payment-options/{$this->fixtures->option->id}", [
            'preference' => 'inherit',
        ])->assertOk()
            ->assertJsonPath('data.option.effective', true);

        $afterRestore = PaymentPolicyRevision::query()
            ->where('branch_id', $this->fixtures->shop->id)
            ->max('revision');

        expect($afterDisable)->toBeGreaterThan(0)
            ->and($afterRestore)->toBe($afterDisable + 1);
    });

    it('B5 device disable affects only that device', function () {
        $this->fixtures->seedConnection();
        $deviceA = $this->fixtures->seedDevice('pos');
        $deviceB = $this->fixtures->seedDevice('pos');

        $this->patchJson("{$this->fixtures->shopBase()}/devices/{$deviceA->id}/payment-options", [
            'option_id' => $this->fixtures->option->id,
            'preference' => 'disabled',
        ])->assertOk()
            ->assertJsonPath('data.option.effective', false);

        $shopEffective = $this->getJson("{$this->fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data.options.0.effective');
        $deviceAEffective = $this->getJson("{$this->fixtures->shopBase()}/devices/{$deviceA->id}/payment-options")
            ->assertOk()
            ->json('data.options.0.effective');
        $deviceBEffective = $this->getJson("{$this->fixtures->shopBase()}/devices/{$deviceB->id}/payment-options")
            ->assertOk()
            ->json('data.options.0.effective');

        expect($shopEffective)->toBeTrue()
            ->and($deviceAEffective)->toBeFalse()
            ->and($deviceBEffective)->toBeTrue();
    });

    it('B6 device cannot widen a shop-disabled option', function () {
        $this->fixtures->seedConnection();

        $this->patchJson("{$this->fixtures->shopBase()}/payment-options/{$this->fixtures->option->id}", [
            'preference' => 'disabled',
        ])->assertOk();

        $device = $this->fixtures->seedDevice('pos');

        $this->patchJson("{$this->fixtures->shopBase()}/devices/{$device->id}/payment-options", [
            'option_id' => $this->fixtures->option->id,
            'preference' => 'enabled',
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'PAYMENT_POLICY_CANNOT_WIDEN');

        expect(
            $this->getJson("{$this->fixtures->shopBase()}/devices/{$device->id}/payment-options")
                ->json('data.options.0.effective'),
        )->toBeFalse();
    });
});

describe('configuration UI contract G7-G10', function () {
    it('G7 HQ-managed shop blocks franchise gateway mutation', function () {
        $this->fixtures->seedConnection();

        $config = $this->getJson("{$this->fixtures->shopBase()}/payment-configuration")
            ->assertOk()
            ->json('data');

        expect($config['connection_mutable'])->toBeFalse()
            ->and($config['ownership']['management_model'])->toBe('hq_managed');

        $this->postJson("{$this->fixtures->shopBase()}/payment-gateways", [
            'provider_id' => $this->fixtures->provider->id,
            'brand_owner_org_unit_id' => $this->fixtures->identity['brand_owner_org_unit_id'],
            'operator_org_unit_id' => $this->fixtures->identity['operator_org_unit_id'],
            'ownership_revision' => $this->fixtures->identity['ownership_revision'],
            'merchant_account_id' => 'acct_blocked',
        ])->assertStatus(403)
            ->assertJsonPath('error_code', 'PAYMENT_GATEWAY_MUTATION_FORBIDDEN');
    });

    it('G8 franchise missing connection shows setup prerequisite immediately', function () {
        $this->fixtures->switchManagementModel(BranchManagementModel::FranchiseOwned);

        $config = $this->getJson("{$this->fixtures->shopBase()}/payment-configuration")
            ->assertOk()
            ->json('data');

        expect($config['setup_required'])->toBeTrue()
            ->and($config['connections'])->toBe([])
            ->and($config['connection_mutable'])->toBeTrue();
    });

    it('G9 option rows expose capability preference effective source and trace', function () {
        $this->fixtures->seedConnection();

        $shape = $this->getJson("{$this->fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data.options.0');

        expect($shape)->toHaveKeys([
            'id', 'display_name', 'provider', 'rail', 'effective',
            'source', 'reason', 'trace', 'shop_preference', 'device_preference',
        ])->and($shape['trace'])->not->toBeEmpty();

        $this->fixtures->ownerPolicySource->set(
            (string) $this->fixtures->brand->id,
            (string) $this->fixtures->option->id,
            UpstreamPolicyState::Denied,
        );

        $blocked = $this->getJson("{$this->fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data.options.0');

        expect($blocked['effective'])->toBeFalse()
            ->and($blocked['source'])->toBe('owner_policy');

        $this->fixtures->ownerPolicySource->set(
            (string) $this->fixtures->brand->id,
            (string) $this->fixtures->option->id,
            UpstreamPolicyState::Allowed,
        );

        $this->patchJson("{$this->fixtures->shopBase()}/payment-options/{$this->fixtures->option->id}", [
            'preference' => 'disabled',
        ])->assertOk();

        $shopOff = $this->getJson("{$this->fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data.options.0');

        expect($shopOff['effective'])->toBeFalse()
            ->and($shopOff['source'])->toBe('shop')
            ->and($shopOff['shop_preference'])->toBe('disabled');
    });

    it('G10 device inherit customize disable reset flow is reversible', function () {
        $this->fixtures->seedConnection();
        $device = $this->fixtures->seedDevice('pos');
        $base = "{$this->fixtures->shopBase()}/devices/{$device->id}/payment-options";

        $inherit = $this->getJson($base)->assertOk()->json('data.options.0');
        expect($inherit['effective'])->toBeTrue()
            ->and($inherit['device_preference'])->toBe('inherit');

        $this->patchJson($base, [
            'option_id' => $this->fixtures->option->id,
            'preference' => 'disabled',
        ])->assertOk()
            ->assertJsonPath('data.option.effective', false);

        $this->patchJson($base, [
            'option_id' => $this->fixtures->option->id,
            'preference' => 'inherit',
        ])->assertOk()
            ->assertJsonPath('data.option.effective', true)
            ->assertJsonPath('data.option.device_preference', 'inherit');

        expect(ShopPaymentOption::query()->where('branch_id', $this->fixtures->shop->id)->count())->toBe(1);
    });
});

it('workstation effective-payment-options returns device snapshot without secrets', function () {
    $this->fixtures->seedConnection();
    $device = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => 'ws-policy-token',
        'organization_id' => $this->fixtures->organization->id,
        'branch_id' => $this->fixtures->shop->id,
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer ws-policy-token'])
        ->getJson('/api/v1/workstation/effective-payment-options')
        ->assertOk()
        ->json('data');

    expect($response)->toHaveKeys(['revision', 'snapshot_hash', 'ownership_revision', 'options'])
        ->and($response['options'][0]['effective'])->toBeTrue()
        ->and($response['options'][0])->not->toHaveKey('api_key')
        ->and($response['options'][0])->not->toHaveKey('secret');
});

it('franchise shop can onboard a gateway connection', function () {
    $this->fixtures->switchManagementModel(BranchManagementModel::FranchiseOwned);

    $response = $this->postJson("{$this->fixtures->shopBase()}/payment-gateways", [
        'provider_id' => $this->fixtures->provider->id,
        'brand_owner_org_unit_id' => $this->fixtures->identity['brand_owner_org_unit_id'],
        'operator_org_unit_id' => $this->fixtures->identity['operator_org_unit_id'],
        'ownership_revision' => $this->fixtures->identity['ownership_revision'],
        'merchant_account_id' => 'acct_franchise_new',
        'merchant_display_name' => 'Franchise Onboarded',
    ])->assertCreated()
        ->json('data');

    expect($response['owner_scope'])->toBe('franchise')
        ->and($response['merchant_account_id'])->toBe('acct_franchise_new')
        ->and($response)->not->toHaveKey('secret');

    expect(PaymentGatewayConnection::query()->where('owner_branch_id', $this->fixtures->shop->id)->count())->toBe(1);
});

/**
 * #1211 — the payment-configuration endpoint 500s for every REAL branch.
 *
 * `branchOrgUnitId` is the console-issued branch id, and the console issues
 * ULIDs — every seeder writes `console_branch_id => Str::ulid()`. But
 * PaymentPolicyRequest validates it as an RFC-4122 UUID, so the constructor
 * throws and `GET /shops/{slug}/payment-configuration` returns 500. All four
 * tabs of the shop payment screen share that call, so the whole screen dies.
 *
 * The suite was blind to it because BranchFactory writes a UUID there while the
 * seeders write a ULID: the fixture and the seeder disagreed about the field's
 * format, and the validator sided with the fixture. This test uses the shape
 * real data has.
 */
it('#1211 serves the configuration for a branch whose console id is a ULID, as the console issues', function () {
    $this->fixtures->shop->forceFill([
        'console_branch_id' => (string) Str::ulid(),
    ])->save();

    $this->getJson("{$this->fixtures->shopBase()}/payment-configuration")
        ->assertOk();
});
