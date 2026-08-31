<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->productA = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
    ]);
    $this->productB = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
    ]);

    ProductSku::factory()->create([
        'product_id' => $this->productA->id,
        'selling_price' => 30000,
        'is_active' => true,
    ]);
    ProductSku::factory()->create([
        'product_id' => $this->productB->id,
        'selling_price' => 25000,
        'is_active' => true,
    ]);

    $this->masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'is_master' => true,
        'master_menu_id' => null,
        'status' => 'Approved',
        'created_by_id' => $this->user->id,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
});

// =============================================================================
// cloneToBranch — preserves menu_menu_sections
// =============================================================================

it('clones master menu_menu_sections pivot to the branch', function () {
    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Default', 'product_ids' => [$this->productA->id]],
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productB->id]],
            ],
        ])
        ->assertSuccessful();

    expect($this->masterMenu->menuSections()->count())->toBe(2);

    $response = $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ]);

    $response->assertCreated();

    $branchMenu = Menu::where('master_menu_id', $this->masterMenu->id)->firstOrFail();
    expect($branchMenu->menuSections()->count())->toBe(2);
    expect($branchMenu->menuSections()->pluck('name')->sort()->values()->all())
        ->toBe(['Default', 'Đồ uống']);
});

// =============================================================================
// cloneToBranch — multi-row encoding (1 product in N sections → N menu_products rows)
// =============================================================================

it('clones each (product, section) pair as its own menu_products row', function () {
    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Best Seller', 'product_ids' => [$this->productA->id]],
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productA->id, $this->productB->id]],
                ['section_name' => 'Combo', 'product_ids' => [$this->productA->id]],
            ],
        ])
        ->assertSuccessful();

    // Master: productA in 3 sections = 3 rows; productB in 1 section = 1 row; total 4
    expect($this->masterMenu->menuProducts()->count())->toBe(4);
    expect($this->masterMenu->menuProducts()->where('product_id', $this->productA->id)->count())->toBe(3);

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])
        ->assertCreated();

    $branchMenu = Menu::where('master_menu_id', $this->masterMenu->id)->firstOrFail();

    // Branch mirrors master 1:1 — 4 rows
    expect($branchMenu->menuProducts()->count())->toBe(4);
    expect($branchMenu->menuProducts()->where('product_id', $this->productA->id)->count())->toBe(3);

    // Each branch row points at the same section_id as its master row
    $branchSectionIds = $branchMenu->menuProducts()
        ->where('product_id', $this->productA->id)
        ->pluck('menu_section_id')->sort()->values()->all();
    $masterSectionIds = $this->masterMenu->menuProducts()
        ->where('product_id', $this->productA->id)
        ->pluck('menu_section_id')->sort()->values()->all();
    expect($branchSectionIds)->toBe($masterSectionIds);
});

// =============================================================================
// syncFromMaster — copies new (product, section) pairs from master
// =============================================================================

it('syncs newly added (product, section) pairs from master', function () {
    // Master starts with 1 product in "Đồ uống"
    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productA->id]],
            ],
        ])
        ->assertSuccessful();

    // Clone to branch
    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])
        ->assertCreated();

    $branchMenu = Menu::where('master_menu_id', $this->masterMenu->id)->firstOrFail();
    expect($branchMenu->menuProducts()->count())->toBe(1);

    // Master adds productB to a NEW section "Best Seller"
    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productA->id]],
                ['section_name' => 'Best Seller', 'product_ids' => [$this->productB->id]],
            ],
        ])
        ->assertSuccessful();

    // Sync the branch from master
    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$branchMenu->id}/sync-from-master")
        ->assertSuccessful();

    $branchMenu->refresh();

    // Branch now has 2 menu_products rows (productA in Đồ uống + productB in Best Seller)
    expect($branchMenu->menuProducts()->count())->toBe(2);

    // Branch menu now has both sections attached via menu_menu_sections
    expect($branchMenu->menuSections()->count())->toBe(2);
    expect($branchMenu->menuSections()->pluck('name')->sort()->values()->all())
        ->toBe(['Best Seller', 'Đồ uống']);

    // productB lands in Best Seller
    $branchMpB = $branchMenu->menuProducts()->where('product_id', $this->productB->id)->firstOrFail();
    expect($branchMpB->menuSection->name)->toBe('Best Seller');
});

// =============================================================================
// cloneToBranch — schedules are copied to the branch menu
// =============================================================================

it('clones all non-deleted schedules from master to branch menu', function () {
    MenuSchedule::factory()->create([
        'menu_id' => $this->masterMenu->id,
        'start_time' => '08:00:00',
        'end_time' => '11:00:00',
        'days_of_week' => 62, // Mon–Fri
        'is_active' => true,
        'priority' => 0,
    ]);
    MenuSchedule::factory()->create([
        'menu_id' => $this->masterMenu->id,
        'start_time' => '17:00:00',
        'end_time' => '21:00:00',
        'days_of_week' => 96, // Sat+Sun = bit5+bit6 = 32+64
        'is_active' => false,
        'priority' => 1,
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])
        ->assertCreated();

    $branchMenu = Menu::where('master_menu_id', $this->masterMenu->id)->firstOrFail();

    expect($branchMenu->schedules()->count())->toBe(2);

    $schedules = $branchMenu->schedules()->orderBy('priority')->get();
    expect($schedules[0]->getRawOriginal('start_time'))->toBe('08:00:00');
    expect($schedules[0]->getRawOriginal('end_time'))->toBe('11:00:00');
    expect($schedules[0]->days_of_week)->toBe(62);
    expect($schedules[0]->is_active)->toBeTrue();

    expect($schedules[1]->getRawOriginal('start_time'))->toBe('17:00:00');
    expect($schedules[1]->getRawOriginal('end_time'))->toBe('21:00:00');
    expect($schedules[1]->days_of_week)->toBe(96);
    expect($schedules[1]->is_active)->toBeFalse();
});

it('does not clone soft-deleted schedules from master', function () {
    $active = MenuSchedule::factory()->create([
        'menu_id' => $this->masterMenu->id,
        'start_time' => '08:00:00',
        'end_time' => '12:00:00',
        'days_of_week' => 127,
        'is_active' => true,
        'priority' => 0,
    ]);
    $deleted = MenuSchedule::factory()->create([
        'menu_id' => $this->masterMenu->id,
        'start_time' => '20:00:00',
        'end_time' => '23:00:00',
        'days_of_week' => 127,
        'is_active' => true,
        'priority' => 1,
    ]);
    $deleted->delete();

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])
        ->assertCreated();

    $branchMenu = Menu::where('master_menu_id', $this->masterMenu->id)->firstOrFail();
    expect($branchMenu->schedules()->count())->toBe(1);
    expect($branchMenu->schedules()->first()->getRawOriginal('start_time'))->toBe('08:00:00');
});

// =============================================================================
// Trade-off explicit: branch has SEPARATE SKU sets per (product, section)
// =============================================================================

it('creates separate menu_product_skus per (product, section) row in cloned branch', function () {
    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Best Seller', 'product_ids' => [$this->productA->id]],
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productA->id]],
            ],
        ])
        ->assertSuccessful();

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])
        ->assertCreated();

    $branchMenu = Menu::where('master_menu_id', $this->masterMenu->id)->firstOrFail();

    // 2 menu_products rows (one per section)
    expect($branchMenu->menuProducts()->count())->toBe(2);

    $totalSkus = DB::table('menu_product_skus')
        ->whereIn('menu_product_id', $branchMenu->menuProducts()->pluck('id'))
        ->count();

    // 1 SKU × 2 menu_products rows = 2 SKU rows. This is the documented trade-off
    // of the multi-row approach: shop has to override price per (product, section).
    expect($totalSkus)->toBe(2);
});

// =============================================================================
// syncFromMaster — sections HQ dropped are DETACHED from the branch
// =============================================================================

it('detaches a section from the branch when master no longer has it', function () {
    // Master starts with two sections.
    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productA->id]],
                ['section_name' => 'Khai vị', 'product_ids' => [$this->productB->id]],
            ],
        ])
        ->assertSuccessful();

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])
        ->assertCreated();

    $branchMenu = Menu::where('master_menu_id', $this->masterMenu->id)->firstOrFail();
    expect($branchMenu->menuSections()->count())->toBe(2);

    // HQ drops "Khai vị" entirely.
    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productA->id]],
            ],
        ])
        ->assertSuccessful();

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$branchMenu->id}/sync-from-master")
        ->assertSuccessful();

    // Before the fix the branch kept BOTH sections — the shop rendered a section
    // HQ had removed, and it only appeared after pressing sync.
    expect($branchMenu->menuSections()->count())->toBe(1);
    expect($branchMenu->menuSections()->pluck('name')->all())->toBe(['Đồ uống']);
});

it('re-syncing does not grow the branch section list', function () {
    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productA->id]],
                ['section_name' => 'Khai vị', 'product_ids' => [$this->productB->id]],
            ],
        ])
        ->assertSuccessful();

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])
        ->assertCreated();

    $branchMenu = Menu::where('master_menu_id', $this->masterMenu->id)->firstOrFail();

    foreach (range(1, 3) as $_) {
        $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/menus/{$branchMenu->id}/sync-from-master")
            ->assertSuccessful();
    }

    expect($branchMenu->menuSections()->count())->toBe(2);
    expect($branchMenu->menuProducts()->count())->toBe(2);
});

it('soft-deletes branch products stranded in a detached section', function () {
    // productA sits in a section HQ will delete; it stays on sale elsewhere.
    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Khai vị', 'product_ids' => [$this->productA->id]],
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productB->id]],
            ],
        ])
        ->assertSuccessful();

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])
        ->assertCreated();

    $branchMenu = Menu::where('master_menu_id', $this->masterMenu->id)->firstOrFail();
    expect($branchMenu->menuProducts()->count())->toBe(2);

    // HQ deletes "Khai vị" and does NOT re-home productA.
    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productB->id]],
            ],
        ])
        ->assertSuccessful();

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$branchMenu->id}/sync-from-master")
        ->assertSuccessful();

    // No row may point at a section the menu no longer has — that renders
    // under no section at the shop.
    $liveSectionIds = $branchMenu->menuSections()->pluck('menu_sections.id')->all();
    $live = $branchMenu->menuProducts()->get();

    expect($live)->toHaveCount(1);
    expect($live->first()->product_id)->toBe($this->productB->id);
    foreach ($live as $mp) {
        expect($liveSectionIds)->toContain($mp->menu_section_id);
    }
});

it('keeps a product HQ moved out of a deleted section into a live one', function () {
    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Khai vị', 'product_ids' => [$this->productA->id]],
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productB->id]],
            ],
        ])
        ->assertSuccessful();

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])
        ->assertCreated();

    $branchMenu = Menu::where('master_menu_id', $this->masterMenu->id)->firstOrFail();

    // HQ deletes "Khai vị" but MOVES productA into "Đồ uống".
    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productB->id, $this->productA->id]],
            ],
        ])
        ->assertSuccessful();

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$branchMenu->id}/sync-from-master")
        ->assertSuccessful();

    // The relink step moves the row into the live section BEFORE the stranded
    // sweep runs — a moved product must survive, not be swept as debris.
    expect($branchMenu->menuSections()->count())->toBe(1);
    expect($branchMenu->menuProducts()->count())->toBe(2);
    expect($branchMenu->menuProducts()->pluck('product_id')->sort()->values()->all())
        ->toBe(collect([$this->productA->id, $this->productB->id])->sort()->values()->all());
});
