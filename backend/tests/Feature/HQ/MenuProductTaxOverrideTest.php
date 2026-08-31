<?php

use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * plan-043 T2.3 — MenuProduct tax-type override endpoint.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);

    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->user->assignRole($role, $this->orgId);
    grantOrgAccess($this->user, $this->orgId);

    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->product = Product::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id]);
    $this->menu = Menu::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->mp = MenuProduct::factory()->create(['menu_id' => $this->menu->id, 'product_id' => $this->product->id]);

    $this->red = TaxType::factory()->reduced()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->std = TaxType::factory()->standard()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->exempt = TaxType::factory()->exempt()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

    $this->url = "/api/v1/hq/{$this->brand->slug}/menus/{$this->menu->id}/products/{$this->mp->id}/tax-type";
});

it('sets and clears a menu-item tax-type override', function () {
    $this->actingAs($this->user)->patchJson($this->url, ['tax_type_id' => $this->red->id])->assertOk();
    expect(MenuProduct::find($this->mp->id)->tax_type_id)->toBe($this->red->id);

    $this->actingAs($this->user)->patchJson($this->url, ['tax_type_id' => null])->assertOk();
    expect(MenuProduct::find($this->mp->id)->tax_type_id)->toBeNull();
});

it('rejects a tax type from another brand (422)', function () {
    $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $foreign = TaxType::factory()->standard()->create(['organization_id' => $this->orgId, 'brand_id' => $otherBrand->id]);

    $this->actingAs($this->user)->patchJson($this->url, ['tax_type_id' => $foreign->id])->assertStatus(422);
});
