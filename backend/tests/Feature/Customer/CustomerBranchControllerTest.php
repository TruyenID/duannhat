<?php

/**
 * #130 P2 — Customer/CustomerBranchController coverage
 *
 * Public endpoints (no auth):
 *   GET /api/v1/customer/branches            — all active branches
 *   GET /api/v1/customer/branches/{slug}/zones — zones + tables for a branch
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Models\TaxType;
use App\Models\Zone;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
});

// =============================================================================
// /customer/branches
// =============================================================================

it('returns active branches publicly (no auth required)', function () {
    Branch::factory()->count(3)->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->getJson('/api/v1/customer/branches')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('excludes inactive branches', function () {
    Branch::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => true]);
    Branch::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => false]);

    $this->getJson('/api/v1/customer/branches')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('orders branches by name ascending', function () {
    Branch::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => true, 'name' => 'Zeta']);
    Branch::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => true, 'name' => 'Alpha']);

    $names = collect($this->getJson('/api/v1/customer/branches')->json('data'))->pluck('name')->all();
    expect($names)->toBe(['Alpha', 'Zeta']);
});

it('includes service_charge_rate from ShopOrderSetting (0 when absent)', function () {
    $branchWithSetting = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
        'name' => 'A With Setting',
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $branchWithSetting->id,
        'organization_id' => $this->orgId,
        'service_charge_rate' => '5.50',
        'currency_code' => 'USD',
    ]);

    Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
        'name' => 'B No Setting',
    ]);

    $rows = $this->getJson('/api/v1/customer/branches')->json('data');

    $withSetting = collect($rows)->firstWhere('name', 'A With Setting');
    $noSetting = collect($rows)->firstWhere('name', 'B No Setting');

    expect((float) $withSetting['service_charge_rate'])->toBe(5.5)
        ->and((float) $noSetting['service_charge_rate'])->toBe(0.0)
        ->and($withSetting['currency_code'])->toBe('USD')
        ->and($noSetting['currency_code'])->toBe('JPY');
});

it('exposes the plan-043 per-rate tax fields', function () {
    // plan-043 T5.5 — the public payload gains prices_include_tax,
    // service_charge_tax_rate and default_tax_type. T6.2 dropped the legacy
    // flat tax_rate key entirely (customer-web now reads the per-rate fields).
    $brand = Brand::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => true]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
        'name' => 'Tax Branch',
    ]);
    $reduced = TaxType::factory()->reduced()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $brand->id,
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $branch->id,
        'organization_id' => $this->orgId,
        'prices_include_tax' => true,
        'service_charge_tax_rate' => '10.00',
        'default_tax_type_id' => $reduced->id,
    ]);

    $row = collect($this->getJson('/api/v1/customer/branches')->json('data'))
        ->firstWhere('name', 'Tax Branch');

    expect($row['prices_include_tax'])->toBeTrue()
        ->and((float) $row['service_charge_tax_rate'])->toBe(10.0)
        ->and($row['default_tax_type'])->not->toBeNull()
        ->and($row['default_tax_type']['code'])->toBe('REDUCED')
        ->and((float) $row['default_tax_type']['rate'])->toBe(8.0);
});

it('returns null default_tax_type and safe defaults when unset', function () {
    Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
        'name' => 'Bare Branch',
    ]);

    $row = collect($this->getJson('/api/v1/customer/branches')->json('data'))
        ->firstWhere('name', 'Bare Branch');

    expect($row['default_tax_type'])->toBeNull()
        ->and($row['prices_include_tax'])->toBeFalse()
        ->and((float) $row['service_charge_tax_rate'])->toBe(0.0);
});

it('includes brand snapshot in each branch row', function () {
    $brand = Brand::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => true]);
    Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);

    $row = $this->getJson('/api/v1/customer/branches')->json('data.0');
    expect($row)->toHaveKey('brand')
        ->and($row['brand'])->toHaveKey('id')->toHaveKey('name')->toHaveKey('slug')
        ->and($row['brand'])->toHaveKey('logo_url')
        ->and($row['brand'])->toHaveKey('customer_header_logo_url')
        ->and($row['brand'])->toHaveKey('customer_order_logo_url')
        ->and($row['brand'])->toHaveKey('customer_order_subtitle');
});

// =============================================================================
// /customer/branches/{slug}/zones
// =============================================================================

it('returns zones with active tables for a branch by slug', function () {
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
        'slug' => 'main-shop',
    ]);
    $zone = Zone::factory()->create([
        'branch_id' => $branch->id,
        'organization_id' => $this->orgId,
        'is_active' => true,
    ]);
    Table::factory()->count(2)->create([
        'branch_id' => $branch->id,
        'organization_id' => $this->orgId,
        'zone_id' => $zone->id,
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/customer/branches/main-shop/zones');
    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.tables'))->toHaveCount(2);
});

it('excludes inactive zones', function () {
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
        'slug' => 'shop-active-zones',
    ]);
    Zone::factory()->create(['branch_id' => $branch->id, 'organization_id' => $this->orgId, 'is_active' => true]);
    Zone::factory()->create(['branch_id' => $branch->id, 'organization_id' => $this->orgId, 'is_active' => false]);

    $this->getJson('/api/v1/customer/branches/shop-active-zones/zones')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('excludes inactive tables within active zones', function () {
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
        'slug' => 'shop-active-tables',
    ]);
    $zone = Zone::factory()->create(['branch_id' => $branch->id, 'organization_id' => $this->orgId, 'is_active' => true]);
    Table::factory()->create([
        'branch_id' => $branch->id, 'organization_id' => $this->orgId,
        'zone_id' => $zone->id, 'is_active' => true,
    ]);
    Table::factory()->create([
        'branch_id' => $branch->id, 'organization_id' => $this->orgId,
        'zone_id' => $zone->id, 'is_active' => false,
    ]);

    $tables = $this->getJson('/api/v1/customer/branches/shop-active-tables/zones')->json('data.0.tables');
    expect($tables)->toHaveCount(1);
});

it('returns 404 for non-existent branch slug', function () {
    $this->getJson('/api/v1/customer/branches/does-not-exist/zones')
        ->assertNotFound();
});

it('returns 404 for inactive branch slug', function () {
    Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => false,
        'slug' => 'closed-shop',
    ]);

    $this->getJson('/api/v1/customer/branches/closed-shop/zones')
        ->assertNotFound();
});
