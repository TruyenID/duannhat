<?php

use App\Exceptions\TaxTypeInUseException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Role;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Models\User;
use App\Services\Tax\TaxTypeService;
use Illuminate\Support\Str;

/**
 * Audit fixes 1.5 + 3.4 + 3.8 (2026-07-14).
 *
 * 1.5 — the close-report tax-breakdown print toggle is an audit control; an
 * anonymous device token must NOT be able to flip it (layout toggles stay
 * device-accessible). 3.4 — client-sent tax fields on order/item writes are
 * server-recomputed, never trusted (regression pin). 3.8 — a tax type that is
 * still a brand/branch default can't be deactivated into a stale config.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->shop->id,
        'currency_code' => 'JPY',
        'service_charge_rate' => 0,
        'service_charge_tax_rate' => 0,
        'prices_include_tax' => false,
    ]);

    $this->deviceToken = Str::random(64);
    Device::factory()->create([
        'type' => 'pos', 'status' => 'active', 'device_token' => $this->deviceToken,
        'organization_id' => $this->orgId, 'branch_id' => $this->shop->id,
        'name' => 'POS Guard', 'pairing_code' => null, 'notes' => null,
    ]);

    $managerRole = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);
});

// ─── 1.5 — tax-breakdown toggle authorization ────────────────────────────

it('1.5 — a bare device token cannot flip close_report_tax_breakdown (403), layout toggles still work', function () {
    $headers = ['Authorization' => 'Bearer '.$this->deviceToken, 'X-Shop-Slug' => $this->shop->slug];

    // Tax toggle: blocked for anonymous devices.
    $this->withHeaders($headers)
        ->patchJson('/api/v1/pos/settings/order', ['close_report_tax_breakdown' => false])
        ->assertStatus(403)
        ->assertJsonPath('code', 'TAX_BREAKDOWN_TOGGLE_FORBIDDEN');
    expect((bool) ShopOrderSetting::where('branch_id', $this->shop->id)->value('close_report_tax_breakdown'))
        ->toBeTrue();

    // Layout toggle: still device-accessible (pos-web settings page path).
    $this->withHeaders($headers)
        ->patchJson('/api/v1/pos/settings/order', ['close_report_denominations' => false])
        ->assertOk()
        ->assertJsonPath('data.close_report_denominations', false);
});

it('1.5 — a signed-in user CAN flip close_report_tax_breakdown', function () {
    $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->patchJson('/api/v1/pos/settings/order', ['close_report_tax_breakdown' => false])
        ->assertOk()
        ->assertJsonPath('data.close_report_tax_breakdown', false);
});

// ─── 3.8 — deactivation guard for live defaults ───────────────────────────

it('3.8 — deactivating a tax type that is a brand or branch default returns 409', function () {
    $service = app(TaxTypeService::class);

    // Brand default (is_default = 1).
    $brandDefault = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'is_default' => true, 'is_active' => true,
    ]);
    expect(fn () => $service->toggleStatus($brandDefault))
        ->toThrow(TaxTypeInUseException::class);

    // Branch default (shop_order_settings.default_tax_type_id).
    $branchDefault = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'is_default' => false, 'is_active' => true,
    ]);
    ShopOrderSetting::where('branch_id', $this->shop->id)->update(['default_tax_type_id' => $branchDefault->id]);
    expect(fn () => $service->toggleStatus($branchDefault))
        ->toThrow(TaxTypeInUseException::class);

    // A plain non-default type still toggles fine (deactivate + reactivate).
    $plain = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'is_default' => false, 'is_active' => true,
    ]);
    expect($service->toggleStatus($plain)->is_active)->toBeFalse()
        ->and($service->toggleStatus($plain->fresh())->is_active)->toBeTrue();
});

// ─── 3.4 — client-sent tax fields are recomputed server-side ──────────────
