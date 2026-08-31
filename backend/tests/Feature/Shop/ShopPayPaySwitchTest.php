<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentGatewayOption;
use App\Models\Role;
use App\Models\ShopPaymentOption;
use App\Models\User;
use App\Services\Customer\PayPayAvailabilityService;
use Database\Seeders\PaymentGatewayCatalogSeeder;
use Illuminate\Support\Str;

/**
 * plan-054 D9 / T5.6 — the shop-level PayPay off switch.
 *
 * D9: the brand enables PayPay, an individual shop may opt out. The backend
 * already honours the row (`PayPayAvailabilityService::shopOptedOut()`); this
 * covers the operator-reachable path that WRITES it, which is the half that
 * decides whether the switch exists at all.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'paypay-switch-shop',
        'currency' => 'JPY',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($role, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    config([
        'services.paypay.api_key' => 'a_key',
        'services.paypay.api_secret' => 'a_secret',
        'services.paypay.merchant_id' => '991602796635988897',
    ]);
});

function paypayQrOption(): ?PaymentGatewayOption
{
    return PaymentGatewayOption::query()
        ->where('code', PaymentGatewayCatalogSeeder::PAYPAY_QR_OPTION_CODE)
        ->first();
}

// =========================================================================
//  GET — the state an operator opens the screen to
// =========================================================================

it('reports inherit and enabled on a shop that has never touched the switch', function () {
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/paypay")
        ->assertOk()
        ->assertJsonPath('data.preference', 'inherit')
        ->assertJsonPath('data.effective_enabled', true)
        ->assertJsonPath('data.brand_enabled', true)
        ->assertJsonPath('data.reason', null)
        // Four enum cases exist; `enabled` and `blocked` are refused by
        // updateShopOption, so offering them would be offering a 4xx.
        ->assertJsonPath('data.available_preferences', ['inherit', 'disabled']);
});

it('separates what the shop controls from what it does not', function () {
    // Nothing this shop can do makes PayPay appear on a VND branch, so the
    // screen must say so rather than blame the switch.
    $this->shop->update(['currency' => 'VND']);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/paypay")
        ->assertOk()
        ->assertJsonPath('data.effective_enabled', false)
        ->assertJsonPath('data.brand_enabled', false)
        ->assertJsonPath('data.reason', 'currency_unsupported');
});

// =========================================================================
//  PATCH — the half that did not exist
// =========================================================================

it('turns PayPay off for this shop and the availability service agrees', function () {
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/paypay", ['preference' => 'disabled'])
        ->assertOk()
        ->assertJsonPath('data.preference', 'disabled')
        ->assertJsonPath('data.effective_enabled', false)
        // The brand is still fine — only this shop opted out.
        ->assertJsonPath('data.brand_enabled', true)
        ->assertJsonPath('data.reason', 'disabled_for_shop');

    // The switch must be wired to the thing customer-web actually reads, not
    // merely to a row that looks right.
    expect(app(PayPayAvailabilityService::class)->forBranch($this->shop->fresh()))
        ->toBe(['enabled' => false, 'reason' => 'disabled_for_shop']);
});

it('provisions the catalog capability so the switch works before the first sale', function () {
    // db:seed never runs on staging/production and the catalog migration ships
    // only the internal slice, so on a real shop this row does not exist until
    // the first PayPay checkout. An off switch reachable only after the money
    // has already moved is not an off switch.
    expect(paypayQrOption())->toBeNull();

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/paypay", ['preference' => 'disabled'])
        ->assertOk();

    $option = paypayQrOption();
    expect($option)->not->toBeNull()
        ->and(ShopPaymentOption::query()
            ->where('branch_id', $this->shop->id)
            ->where('option_id', $option->id)
            ->first()
            ->preference
            ->value)
        ->toBe('disabled');
});

it('inherits back to the brand when the shop changes its mind', function () {
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/paypay", ['preference' => 'disabled'])
        ->assertOk();

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/paypay", ['preference' => 'inherit'])
        ->assertOk()
        ->assertJsonPath('data.preference', 'inherit')
        ->assertJsonPath('data.effective_enabled', true);

    // Inherit is the ABSENCE of an opinion, matching how both the resolver and
    // PayPayAvailabilityService read a missing row.
    expect(ShopPaymentOption::query()->where('branch_id', $this->shop->id)->count())->toBe(0);
});

it('does not mint catalog rows just to say it has no opinion', function () {
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/paypay", ['preference' => 'inherit'])
        ->assertOk()
        ->assertJsonPath('data.preference', 'inherit');

    expect(paypayQrOption())->toBeNull();
});

it('records the opt-out even while the brand still has no credentials', function () {
    // The shop's intent has to survive until the day the keys land, otherwise
    // "we do not want this here" is only sayable in the window after the brand
    // switched PayPay on and before the first customer used it.
    config(['services.paypay.api_key' => '']);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/paypay", ['preference' => 'disabled'])
        ->assertOk()
        ->assertJsonPath('data.preference', 'disabled')
        ->assertJsonPath('data.brand_enabled', false)
        ->assertJsonPath('data.reason', 'credentials_missing');

    config([
        'services.paypay.api_key' => 'a_key',
        'services.paypay.api_secret' => 'a_secret',
        'services.paypay.merchant_id' => '991602796635988897',
    ]);

    expect(app(PayPayAvailabilityService::class)->forBranch($this->shop->fresh()))
        ->toBe(['enabled' => false, 'reason' => 'disabled_for_shop']);
});

// =========================================================================
//  Refusals
// =========================================================================

it('refuses the preferences a shop may not express', function (string $preference) {
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/paypay", ['preference' => $preference])
        ->assertStatus(422)
        ->assertJsonValidationErrors('preference');
})->with(['enabled', 'blocked', 'off', '']);

it('leaves other shops in the brand alone', function () {
    $other = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'paypay-switch-other-shop',
        'currency' => 'JPY',
        'is_active' => true,
    ]);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/paypay", ['preference' => 'disabled'])
        ->assertOk();

    // D9's whole point: opting one branch out is not opting the brand out.
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$other->slug}/settings/paypay")
        ->assertOk()
        ->assertJsonPath('data.preference', 'inherit')
        ->assertJsonPath('data.effective_enabled', true);
});
