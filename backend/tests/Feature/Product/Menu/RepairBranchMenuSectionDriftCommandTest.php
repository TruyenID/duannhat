<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The ops half of the section-drift fix. `syncSectionLayoutFromMaster` used to
 * attach-and-update only, so a section HQ dropped stayed attached to every
 * branch clone forever — the shop rendered a section HQ had removed, and
 * because clone-time uses sync() (which detaches) the extra only appeared AFTER
 * someone pressed sync.
 *
 * Fixed forward, but that cannot reach a menu already holding the debris, and
 * nothing repairs one automatically: sync waits for a human in the shop to
 * press a button, which for most shops means never.
 *
 * These tests build the BROKEN shape on purpose (raw pivot writes that bypass
 * the fixed path) because that is what production actually holds today — the
 * live database had branch menu ランチ carrying 10 sections against its
 * master's 9. A test that synced through the repaired code would prove nothing
 * about the rows this command exists to fix.
 */
beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->master = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Approved',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);

    $this->branchMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_master' => false,
        'status' => 'Active',
        'created_by_id' => $this->user->id,
        'master_menu_id' => $this->master->id,
        'brand_id' => $this->brand->id,
    ]);

    $this->makeSection = function (string $name): MenuSection {
        return MenuSection::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'name' => $name,
        ]);
    };

    // "Live" section — on both master and branch. "Debris" — branch only,
    // exactly the shape the old attach-only sync left behind.
    $this->live = ($this->makeSection)('Đồ uống');
    $this->debris = ($this->makeSection)('セット');

    DB::table('menu_menu_sections')->insert([
        ['menu_id' => $this->master->id, 'menu_section_id' => $this->live->id, 'display_order' => 1],
        ['menu_id' => $this->branchMenu->id, 'menu_section_id' => $this->live->id, 'display_order' => 1],
        ['menu_id' => $this->branchMenu->id, 'menu_section_id' => $this->debris->id, 'display_order' => 2],
    ]);
});

it('reports the extra section but writes nothing on a dry run', function () {
    $this->artisan('menus:repair-section-drift')
        ->expectsOutputToContain('セット')
        ->expectsOutputToContain('detached=1')
        ->expectsOutputToContain('Dry run — nothing written.')
        ->assertSuccessful();

    expect($this->branchMenu->menuSections()->count())->toBe(2);
});

it('detaches the extra section with --apply', function () {
    $this->artisan('menus:repair-section-drift', ['--apply' => true])
        ->expectsOutputToContain('detached=1')
        ->assertSuccessful();

    expect($this->branchMenu->menuSections()->pluck('name')->all())->toBe(['Đồ uống']);
});

it('never deletes the menu_sections row itself', function () {
    // A section is shared by many menus (that is why the tax tier lives on the
    // pivot), so tidying one branch menu must not strip it from the others.
    $otherMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_master' => false,
        'status' => 'Active',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);
    DB::table('menu_menu_sections')->insert([
        ['menu_id' => $otherMenu->id, 'menu_section_id' => $this->debris->id, 'display_order' => 1],
    ]);

    $this->artisan('menus:repair-section-drift', ['--apply' => true])->assertSuccessful();

    expect(MenuSection::whereKey($this->debris->id)->exists())->toBeTrue();
    expect($otherMenu->menuSections()->count())->toBe(1);
});

it('keeps a section that still holds products unless forced', function () {
    $productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $productType->id,
        'brand_id' => $this->brand->id,
    ]);
    $this->branchMenu->menuProducts()->create([
        'product_id' => $product->id,
        'menu_section_id' => $this->debris->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $this->artisan('menus:repair-section-drift', ['--apply' => true])
        ->expectsOutputToContain('kept_with_products=1')
        ->assertSuccessful();

    expect($this->branchMenu->menuSections()->count())->toBe(2);

    // --force detaches it AND soft-deletes the rows, so nothing is left
    // pointing at a section the menu no longer has.
    $this->artisan('menus:repair-section-drift', ['--apply' => true, '--force' => true])
        ->expectsOutputToContain('detached=1')
        ->assertSuccessful();

    expect($this->branchMenu->menuSections()->count())->toBe(1);
    expect($this->branchMenu->menuProducts()->where('menu_section_id', $this->debris->id)->count())->toBe(0);
});

it('leaves a branch menu alone when it matches its master', function () {
    DB::table('menu_menu_sections')
        ->where('menu_id', $this->branchMenu->id)
        ->where('menu_section_id', $this->debris->id)
        ->delete();

    $this->artisan('menus:repair-section-drift', ['--apply' => true])
        ->expectsOutputToContain('touched=0')
        ->assertSuccessful();

    expect($this->branchMenu->menuSections()->count())->toBe(1);
});

it('limits the repair to one branch with --branch', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $otherBranchMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $otherBranch->id,
        'is_master' => false,
        'status' => 'Active',
        'created_by_id' => $this->user->id,
        'master_menu_id' => $this->master->id,
        'brand_id' => $this->brand->id,
    ]);
    DB::table('menu_menu_sections')->insert([
        ['menu_id' => $otherBranchMenu->id, 'menu_section_id' => $this->debris->id, 'display_order' => 1],
    ]);

    $this->artisan('menus:repair-section-drift', ['--apply' => true, '--branch' => $this->branch->id])
        ->expectsOutputToContain('detached=1')
        ->assertSuccessful();

    expect($this->branchMenu->menuSections()->count())->toBe(1);
    expect($otherBranchMenu->menuSections()->count())->toBe(1);
});
