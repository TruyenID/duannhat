<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\TaxType;
use App\Models\User;

/**
 * #1227 — the three menu-side tax tiers (#1218) must reach the BRANCH menu.
 *
 * Customers and the workstation are served the branch clone, never the master,
 * so a tier that stops at HQ is a tier that never reaches a bill. `cloneToBranch`
 * copied placement, ordering and schedules but none of the three tax columns,
 * and `syncFromMaster` did not repair it — so a takeaway menu built exactly as
 * designed at HQ still billed the standard rate everywhere it was actually sold.
 *
 * The per-item tier is the oldest of the three (plan-043 / #1099), so that half
 * of the bug shipped long before #1218 added the other two.
 *
 * Tax mirrors the master like `service_type` and placement do — NOT like
 * `is_active`, which is the shop's own decision and must survive a sync. The
 * shop has no tax editor at all (#1226), so "mirror" is the whole contract:
 * clearing at HQ must clear at the branch, or a retired rate would live on in
 * every shop forever.
 */
beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'tax-shop',
        'is_active' => true,
    ]);

    $this->standard = TaxType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'code' => 'STD-1227',
        'rate' => 10,
        'is_default' => true,
    ]);

    $this->reduced = TaxType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'code' => 'RED-1227',
        'rate' => 8,
    ]);

    $type = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    foreach (['A', 'B'] as $key) {
        $this->{"product{$key}"} = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $type->id,
            'brand_id' => $this->brand->id,
        ]);
        ProductSku::factory()->create([
            'product_id' => $this->{"product{$key}"}->id,
            'selling_price' => 10000,
            'is_active' => true,
        ]);
    }

    $this->masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'is_master' => true,
        'master_menu_id' => null,
        'status' => 'Approved',
        'created_by_id' => $this->user->id,
    ]);

    $this->hqUrl = "/api/v1/hq/{$this->brand->slug}";
    $this->shopUrl = "/api/v1/shops/{$this->branch->slug}";

    $this->actingAs($this->user)
        ->putJson("{$this->hqUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productA->id, $this->productB->id]],
            ],
        ])->assertSuccessful();

    $this->masterMenu->refresh()->load('menuSections');
    $this->section = $this->masterMenu->menuSections->firstOrFail();
});

/** Set all three tiers on the master: menu 8%, section 10%, item A 10%. */
function setMasterTaxTiers($test): void
{
    $test->masterMenu->update(['tax_type_id' => $test->reduced->id]);
    $test->masterMenu->menuSections()->updateExistingPivot(
        $test->section->id,
        ['tax_type_id' => $test->standard->id],
    );
    $test->masterMenu->menuProducts()
        ->where('product_id', $test->productA->id)
        ->firstOrFail()
        ->update(['tax_type_id' => $test->standard->id]);
}

function cloneMasterToBranch($test): Menu
{
    $test->actingAs($test->user)
        ->postJson("{$test->hqUrl}/menus/{$test->masterMenu->id}/clone-to-branch", [
            'branch_id' => $test->branch->id,
        ])->assertCreated();

    return Menu::where('master_menu_id', $test->masterMenu->id)->firstOrFail();
}

function branchSectionTaxId(Menu $branchMenu, string $sectionId): ?string
{
    return $branchMenu->menuSections()
        ->whereKey($sectionId)
        ->first()?->pivot?->tax_type_id;
}

// =========================================================================
//  Clone
// =========================================================================

it('carries all three tax tiers onto the branch clone', function () {
    setMasterTaxTiers($this);

    $branchMenu = cloneMasterToBranch($this);

    expect($branchMenu->tax_type_id)->toBe($this->reduced->id)
        ->and(branchSectionTaxId($branchMenu, $this->section->id))->toBe($this->standard->id)
        ->and(
            $branchMenu->menuProducts()->where('product_id', $this->productA->id)->value('tax_type_id')
        )->toBe($this->standard->id);
});

it('leaves an untaxed tier null on the clone rather than inventing a value', function () {
    // Only the menu tier set — the other two must arrive null so the resolver
    // falls through to the product, not to a copied-by-accident rate.
    $this->masterMenu->update(['tax_type_id' => $this->reduced->id]);

    $branchMenu = cloneMasterToBranch($this);

    expect($branchMenu->tax_type_id)->toBe($this->reduced->id)
        ->and(branchSectionTaxId($branchMenu, $this->section->id))->toBeNull()
        ->and(
            $branchMenu->menuProducts()->where('product_id', $this->productA->id)->value('tax_type_id')
        )->toBeNull();
});

// =========================================================================
//  Sync — repairs branches cloned before the fix
// =========================================================================

it('repairs a branch menu that was cloned before tax propagated', function () {
    $branchMenu = cloneMasterToBranch($this);

    // Reproduce the pre-fix state: the branch has none of the three tiers.
    $branchMenu->update(['tax_type_id' => null]);
    $branchMenu->menuSections()->updateExistingPivot($this->section->id, ['tax_type_id' => null]);
    $branchMenu->menuProducts()->update(['tax_type_id' => null]);

    setMasterTaxTiers($this);

    $this->actingAs($this->user)
        ->postJson("{$this->shopUrl}/menus/{$branchMenu->id}/sync")
        ->assertOk();

    $branchMenu->refresh();

    expect($branchMenu->tax_type_id)->toBe($this->reduced->id)
        ->and(branchSectionTaxId($branchMenu, $this->section->id))->toBe($this->standard->id)
        ->and(
            $branchMenu->menuProducts()->where('product_id', $this->productA->id)->value('tax_type_id')
        )->toBe($this->standard->id);
});

it('clears the branch tiers when HQ clears them', function () {
    setMasterTaxTiers($this);
    $branchMenu = cloneMasterToBranch($this);

    // HQ retires the overrides — everything falls back to the product tier.
    $this->masterMenu->update(['tax_type_id' => null]);
    $this->masterMenu->menuSections()->updateExistingPivot($this->section->id, ['tax_type_id' => null]);
    $this->masterMenu->menuProducts()->update(['tax_type_id' => null]);

    $this->actingAs($this->user)
        ->postJson("{$this->shopUrl}/menus/{$branchMenu->id}/sync")
        ->assertOk();

    $branchMenu->refresh();

    // Fill-blanks-only propagation would leave the retired rate live in every
    // shop forever, which is why the write is unconditional.
    expect($branchMenu->tax_type_id)->toBeNull()
        ->and(branchSectionTaxId($branchMenu, $this->section->id))->toBeNull()
        ->and(
            $branchMenu->menuProducts()->where('product_id', $this->productA->id)->value('tax_type_id')
        )->toBeNull();
});

// =========================================================================
//  Shop screen — reports the billed rate, resolved server-side
// =========================================================================

it('reports each branch line at the rate it will actually be billed', function () {
    setMasterTaxTiers($this);
    $branchMenu = cloneMasterToBranch($this);

    $response = $this->actingAs($this->user)
        ->getJson("{$this->shopUrl}/menus/{$branchMenu->id}?compact=1")
        ->assertOk();

    $payload = $response->json('data');

    // Menu tier 8%, section tier 10%. Product A also carries its own 10%
    // override; product B inherits, and its section's 10% beats the menu's 8%.
    expect($payload['tax_rate'])->toEqual(8.0)
        ->and($payload['section_tax_rates'][$this->section->id])->toEqual(10.0);

    $rates = collect($payload['menu_products'])
        ->mapWithKeys(fn ($mp) => [$mp['product_id'] => $mp['effective_tax_rate']]);

    expect($rates[$this->productA->id])->toEqual(10.0)
        ->and($rates[$this->productB->id])->toEqual(10.0);
});

it('reports the menu rate for a line whose section carries none', function () {
    // Only the whole-menu tier — every line must report 8% even though the
    // products themselves resolve to the brand default 10%. This is the case
    // the shop screen exists to explain, and the one a client-side re-walk
    // would most easily get wrong.
    $this->masterMenu->update(['tax_type_id' => $this->reduced->id]);
    $branchMenu = cloneMasterToBranch($this);

    $payload = $this->actingAs($this->user)
        ->getJson("{$this->shopUrl}/menus/{$branchMenu->id}?compact=1")
        ->assertOk()
        ->json('data');

    expect(collect($payload['menu_products'])->pluck('effective_tax_rate')->unique()->all())
        ->toEqual([8.0]);
});

it('does not let the tax mirror trample the shop toggle', function () {
    $branchMenu = cloneMasterToBranch($this);
    $branchMenu->menuProducts()->update(['is_active' => true]);

    setMasterTaxTiers($this);

    $this->actingAs($this->user)
        ->postJson("{$this->shopUrl}/menus/{$branchMenu->id}/sync")
        ->assertOk();

    // is_active is the shop's decision and must survive every sync — the tax
    // write sits in the same update() call, so this guards the blast radius.
    expect($branchMenu->menuProducts()->where('is_active', false)->count())->toBe(0);
});

// =========================================================================
//  Writing a tier on a shop menu
// =========================================================================

/**
 * The mirror only works one way. HQ lists branch menus on their own tab and
 * renders the same three tax selects there, so setting a tier on a clone is one
 * click away — and `syncFromMaster` then rewrites it from the master (writing
 * NULL when the master holds none). Accepting the write means the UI reports
 * success and the value disappears at the next sync with nothing to show for it.
 * Refuse at the API instead, naming the master.
 */
it('refuses the whole-menu tier on a shop menu inherited from HQ', function () {
    $branchMenu = cloneMasterToBranch($this);

    $this->actingAs($this->user)
        ->patchJson("{$this->hqUrl}/menus/{$branchMenu->id}/tax-type", [
            'tax_type_id' => $this->reduced->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('tax_type_id');

    expect($branchMenu->refresh()->tax_type_id)->toBeNull();
});

it('refuses the section tier on a shop menu inherited from HQ', function () {
    $branchMenu = cloneMasterToBranch($this);

    $this->actingAs($this->user)
        ->patchJson("{$this->hqUrl}/menus/{$branchMenu->id}/sections/{$this->section->id}/tax-type", [
            'tax_type_id' => $this->standard->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('tax_type_id');

    expect(branchSectionTaxId($branchMenu, $this->section->id))->toBeNull();
});

it('refuses the item tier on a shop menu inherited from HQ', function () {
    $branchMenu = cloneMasterToBranch($this);
    $branchMp = $branchMenu->menuProducts()->where('product_id', $this->productA->id)->firstOrFail();

    $this->actingAs($this->user)
        ->patchJson("{$this->hqUrl}/menus/{$branchMenu->id}/products/{$branchMp->id}/tax-type", [
            'tax_type_id' => $this->standard->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('tax_type_id');

    expect($branchMp->refresh()->tax_type_id)->toBeNull();
});

/**
 * A branch menu built AT the branch has no master, so nothing ever overwrites
 * it — it is the only place its own tax can live. Refusing here would leave it
 * permanently untaxable, so the guard must key off `master_menu_id`, not off
 * "has a branch_id".
 */
it('still allows the tier on a shop menu created at the shop', function () {
    $standalone = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'is_master' => false,
        'master_menu_id' => null,
        'status' => 'Approved',
        'created_by_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->patchJson("{$this->hqUrl}/menus/{$standalone->id}/tax-type", [
            'tax_type_id' => $this->reduced->id,
        ])
        ->assertOk();

    expect($standalone->refresh()->tax_type_id)->toBe($this->reduced->id);
});

// =========================================================================
//  Duplicate (#1233) — the sibling of clone, which the #1227 fix did not reach
// =========================================================================

/**
 * `duplicate()` is how an admin makes "Menu mang về (Copy)" before editing it.
 * It carried the per-item tier but not the menu or section tiers, so the copy
 * silently resolved to the branch/brand default — a takeaway menu built as
 * 軽減税率 8% at the menu or section level came back charging 10%.
 *
 * The clone path was fixed in #1227; this one sits 100 lines below it in the
 * same class and was missed because no test exercised tax through duplicate.
 */
it('carries all three tax tiers onto a duplicated menu', function () {
    setMasterTaxTiers($this);

    $this->actingAs($this->user)
        ->postJson("{$this->hqUrl}/menus/{$this->masterMenu->id}/duplicate")
        ->assertSuccessful();

    $copy = Menu::where('id', '!=', $this->masterMenu->id)
        ->where('brand_id', $this->brand->id)
        ->latest('created_at')->firstOrFail();

    expect($copy->tax_type_id)->toBe($this->reduced->id)
        ->and(branchSectionTaxId($copy, $this->section->id))->toBe($this->standard->id)
        ->and(
            $copy->menuProducts()->where('product_id', $this->productA->id)->value('tax_type_id')
        )->toBe($this->standard->id);
});

it('leaves an untaxed tier null on a duplicate rather than inventing a value', function () {
    // Same contract as the clone: absence must survive the copy, or the
    // resolver stops falling through to the product tier.
    $this->masterMenu->update(['tax_type_id' => $this->reduced->id]);

    $this->actingAs($this->user)
        ->postJson("{$this->hqUrl}/menus/{$this->masterMenu->id}/duplicate")
        ->assertSuccessful();

    $copy = Menu::where('id', '!=', $this->masterMenu->id)
        ->where('brand_id', $this->brand->id)
        ->latest('created_at')->firstOrFail();

    expect($copy->tax_type_id)->toBe($this->reduced->id)
        ->and(branchSectionTaxId($copy, $this->section->id))->toBeNull()
        ->and(
            $copy->menuProducts()->where('product_id', $this->productA->id)->value('tax_type_id')
        )->toBeNull();
});
