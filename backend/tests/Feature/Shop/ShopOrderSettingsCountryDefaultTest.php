<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\ShopOrderSetting;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * #2108 (từ #2102) — create-only country default for prices_include_tax.
 *
 * JP organizations get 総額表示 (tax-included) by default on the FIRST
 * shop_order_settings row: 総額表示義務 requires displayed prices to include
 * tax, so a new JP shop starting tax-EXCLUDED silently over-collects at the
 * register. VN keeps false. The default applies only at row creation — an
 * explicit admin value wins, and existing rows are never rewritten.
 *
 * The country rides ComplianceProfileResolver (#1445 single reader of
 * organizations.operating_country) and inherits its fail-safe-to-JP posture
 * (#1153): an org with no mirrored country resolves as JP here too.
 */
function makeSettingsShop(string $country = ''): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
        ...($country !== '' ? ['operating_country' => $country] : []),
    ]);

    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);

    $shop = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'slug' => 'country-default-'.Str::random(6),
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );
    $manager = User::factory()->create(['console_organization_id' => $orgId]);
    $manager->assignRole($role, $orgId);
    grantOrgAccess($manager, $orgId);

    return [$shop, $manager];
}

it('creates the first settings row of a JP branch with prices_include_tax = true', function () {
    [$shop, $manager] = makeSettingsShop('JP');

    expect(ShopOrderSetting::where('branch_id', $shop->id)->exists())->toBeFalse();

    // A PATCH that does NOT mention the flag — the lazy-creation path.
    $this->actingAs($manager)
        ->patchJson("/api/v1/shops/{$shop->slug}/settings/order", [
            'enable_quick_order' => true,
        ])
        ->assertOk();

    expect((bool) ShopOrderSetting::where('branch_id', $shop->id)->value('prices_include_tax'))
        ->toBeTrue();
});

it('creates the first settings row of a VN branch with prices_include_tax = false', function () {
    [$shop, $manager] = makeSettingsShop('VN');

    $this->actingAs($manager)
        ->patchJson("/api/v1/shops/{$shop->slug}/settings/order", [
            'enable_quick_order' => true,
        ])
        ->assertOk();

    expect((bool) ShopOrderSetting::where('branch_id', $shop->id)->value('prices_include_tax'))
        ->toBeFalse();
});

it('fail-safes an org without a mirrored country to the JP default (#1153 posture)', function () {
    [$shop, $manager] = makeSettingsShop();

    $this->actingAs($manager)
        ->patchJson("/api/v1/shops/{$shop->slug}/settings/order", [
            'enable_quick_order' => true,
        ])
        ->assertOk();

    expect((bool) ShopOrderSetting::where('branch_id', $shop->id)->value('prices_include_tax'))
        ->toBeTrue();
});

it('lets an explicit value at creation beat the country default', function () {
    [$shop, $manager] = makeSettingsShop('JP');

    $this->actingAs($manager)
        ->patchJson("/api/v1/shops/{$shop->slug}/settings/order", [
            'prices_include_tax' => false,
        ])
        ->assertOk();

    expect((bool) ShopOrderSetting::where('branch_id', $shop->id)->value('prices_include_tax'))
        ->toBeFalse();
});

it('never rewrites an existing row with the country default', function () {
    [$shop, $manager] = makeSettingsShop('JP');

    // Operator already decided: tax-excluded, despite being JP.
    ShopOrderSetting::create([
        'branch_id' => $shop->id,
        'organization_id' => $shop->console_organization_id,
        'prices_include_tax' => false,
    ]);

    $this->actingAs($manager)
        ->patchJson("/api/v1/shops/{$shop->slug}/settings/order", [
            'enable_quick_order' => true,
        ])
        ->assertOk();

    expect((bool) ShopOrderSetting::where('branch_id', $shop->id)->value('prices_include_tax'))
        ->toBeFalse();
});
