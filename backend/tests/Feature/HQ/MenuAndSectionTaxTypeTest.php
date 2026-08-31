<?php

use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuSection;
use App\Models\Organization;
use App\Models\Role;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * #1218 — the two endpoints that set the new tiers: one tax type for a whole
 * menu, and one per section WITHIN a menu.
 *
 * Both mirror the assignability rule every other tier already uses (decision D):
 * the type must belong to this brand and be ACTIVE, or 422. Deactivation blocks
 * new assignment only — lines already pointing at a type keep resolving through
 * it, which is why the resolver applies no is_active filter anywhere.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);

    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->user->assignRole($role, $this->orgId);
    grantOrgAccess($this->user, $this->orgId);

    $this->menu = Menu::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->section = MenuSection::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->menu->menuSections()->attach($this->section->id, ['display_order' => 0]);

    $this->red = TaxType::factory()->reduced()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->std = TaxType::factory()->standard()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

    $this->menuUrl = "/api/v1/hq/{$this->brand->slug}/menus/{$this->menu->id}/tax-type";
    $this->sectionUrl = "/api/v1/hq/{$this->brand->slug}/menus/{$this->menu->id}/sections/{$this->section->id}/tax-type";

    $this->pivotTaxTypeId = fn () => $this->menu->fresh()
        ->menuSections->firstWhere('id', $this->section->id)?->pivot->tax_type_id;
});

it('sets and clears the whole-menu tax type', function () {
    $this->actingAs($this->user)->patchJson($this->menuUrl, ['tax_type_id' => $this->red->id])->assertOk();
    expect(Menu::find($this->menu->id)->tax_type_id)->toBe($this->red->id);

    $this->actingAs($this->user)->patchJson($this->menuUrl, ['tax_type_id' => null])->assertOk();
    expect(Menu::find($this->menu->id)->tax_type_id)->toBeNull();
});

it('sets and clears the section tax type on the pivot', function () {
    $this->actingAs($this->user)->patchJson($this->sectionUrl, ['tax_type_id' => $this->red->id])->assertOk();
    expect(($this->pivotTaxTypeId)())->toBe($this->red->id);

    $this->actingAs($this->user)->patchJson($this->sectionUrl, ['tax_type_id' => null])->assertOk();
    expect(($this->pivotTaxTypeId)())->toBeNull();
});

it('leaves the section untouched in every OTHER menu that shows it', function () {
    // The whole reason the column is on the pivot. If this ever fails, setting a
    // takeaway rate has just re-rated the same section in every dine-in menu.
    $otherMenu = Menu::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $otherMenu->menuSections()->attach($this->section->id, ['display_order' => 0]);

    $this->actingAs($this->user)->patchJson($this->sectionUrl, ['tax_type_id' => $this->red->id])->assertOk();

    expect(($this->pivotTaxTypeId)())->toBe($this->red->id)
        ->and($otherMenu->fresh()->menuSections->firstWhere('id', $this->section->id)?->pivot->tax_type_id)
        ->toBeNull();
});

it('rejects a tax type from another brand (422)', function () {
    $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $foreign = TaxType::factory()->standard()->create(['organization_id' => $this->orgId, 'brand_id' => $otherBrand->id]);

    $this->actingAs($this->user)->patchJson($this->menuUrl, ['tax_type_id' => $foreign->id])->assertStatus(422);
    $this->actingAs($this->user)->patchJson($this->sectionUrl, ['tax_type_id' => $foreign->id])->assertStatus(422);
});

it('rejects an inactive tax type (422) while leaving existing references alone', function () {
    $retired = TaxType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'code' => 'RETIRED',
        'rate' => 5,
        'is_active' => false,
    ]);

    $this->actingAs($this->user)->patchJson($this->menuUrl, ['tax_type_id' => $retired->id])->assertStatus(422);
    $this->actingAs($this->user)->patchJson($this->sectionUrl, ['tax_type_id' => $retired->id])->assertStatus(422);

    // Assignment is blocked; a menu already pointing at it keeps its value.
    $this->menu->update(['tax_type_id' => $retired->id]);
    expect(Menu::find($this->menu->id)->tax_type_id)->toBe($retired->id);
});

it('rejects a section that is not part of this menu (422)', function () {
    $strayId = MenuSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ])->id;

    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/menus/{$this->menu->id}/sections/{$strayId}/tax-type", [
            'tax_type_id' => $this->red->id,
        ])
        ->assertStatus(422);
});
