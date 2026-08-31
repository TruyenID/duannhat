<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;

/**
 * plan-043 T1.15 — audit trail for tax config (compliance). TaxType + ShopOrderSetting
 * carry the AuditsActivity trait; Product already did (covers tax_type_id).
 */
beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001'; // seeded by Pest beforeEach
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
});

it('audits TaxType create, update and delete', function () {
    $type = TaxType::factory()->reduced()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'auditable_type' => 'TaxType', 'auditable_id' => $type->id, 'action' => 'created',
    ]);

    $type->update(['rate' => 9]);
    $this->assertDatabaseHas('audit_logs', [
        'auditable_type' => 'TaxType', 'auditable_id' => $type->id, 'action' => 'updated',
    ]);

    $type->delete();
    $this->assertDatabaseHas('audit_logs', [
        'auditable_type' => 'TaxType', 'auditable_id' => $type->id, 'action' => 'deleted',
    ]);
});

it('audits a tax settings change', function () {
    $setting = ShopOrderSetting::factory()->create([
        'organization_id' => $this->orgId, 'prices_include_tax' => false,
    ]);

    $setting->update(['prices_include_tax' => true]);

    $this->assertDatabaseHas('audit_logs', [
        'auditable_type' => 'ShopOrderSetting', 'auditable_id' => $setting->id, 'action' => 'updated',
    ]);
});
