<?php

use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
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
    $this->productC = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
    ]);

    $this->skuA = ProductSku::factory()->create([
        'product_id' => $this->productA->id,
        'selling_price' => 30000,
    ]);
    $this->skuB = ProductSku::factory()->create([
        'product_id' => $this->productB->id,
        'selling_price' => 25000,
    ]);
    $this->skuC = ProductSku::factory()->create([
        'product_id' => $this->productC->id,
        'selling_price' => 50000,
    ]);

    $this->menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Draft',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}/menus/{$this->menu->id}/layout";
});

// =============================================================================
// Create from empty
// =============================================================================

it('creates sections and assigns products from an empty menu', function () {
    $payload = [
        'menu_items' => [
            [
                'section_name' => 'Default',
                'product_ids' => [$this->productA->id],
            ],
            [
                'section_name' => 'Đồ uống',
                'product_ids' => [$this->productB->id, $this->productC->id],
            ],
        ],
    ];

    $response = $this->actingAs($this->user)->putJson($this->baseUrl, $payload);

    $response->assertSuccessful();

    expect($this->menu->menuSections()->count())->toBe(2);
    // 1 row for productA + 1 row for productB + 1 row for productC = 3 rows
    expect($this->menu->menuProducts()->count())->toBe(3);
});

// =============================================================================
// Multi-row: 1 product in N sections produces N menu_products rows
// =============================================================================

it('creates one menu_products row per (product, section) pair when product is in multiple sections', function () {
    $payload = [
        'menu_items' => [
            ['section_name' => 'Best Seller', 'product_ids' => [$this->productA->id]],
            ['section_name' => 'Đồ uống', 'product_ids' => [$this->productA->id, $this->productB->id]],
            ['section_name' => 'Combo', 'product_ids' => [$this->productA->id]],
        ],
    ];

    $this->actingAs($this->user)->putJson($this->baseUrl, $payload)->assertSuccessful();

    // productA in 3 sections → 3 menu_products rows
    expect($this->menu->menuProducts()->where('product_id', $this->productA->id)->count())->toBe(3);

    // productB in 1 section → 1 menu_products row
    expect($this->menu->menuProducts()->where('product_id', $this->productB->id)->count())->toBe(1);

    // 3 menu_section rows attached to the menu
    expect($this->menu->menuSections()->count())->toBe(3);

    // Total: 3 + 1 = 4 menu_products rows
    expect($this->menu->menuProducts()->count())->toBe(4);

    // Each row has its own menu_section_id pointing to a different section
    $sectionIds = $this->menu->menuProducts()->where('product_id', $this->productA->id)
        ->pluck('menu_section_id')->all();
    expect(array_unique($sectionIds))->toHaveCount(3);
});

// =============================================================================
// Composite unique blocks duplicate (product, section) pairs in same payload
// =============================================================================

it('does not duplicate when the same product + section appears twice in payload', function () {
    // Same section name twice → service merges them
    // Same product_id twice in the merged payload → array_unique drops the dup
    $payload = [
        'menu_items' => [
            ['section_name' => 'Default', 'product_ids' => [$this->productA->id, $this->productA->id]],
        ],
    ];

    $this->actingAs($this->user)->putJson($this->baseUrl, $payload)->assertSuccessful();

    expect($this->menu->menuProducts()->where('product_id', $this->productA->id)->count())->toBe(1);
});

// =============================================================================
// Soft-delete (product, section) pairs no longer in payload
// =============================================================================

it('soft-deletes (product, section) pairs absent from the new layout', function () {
    // First sync: productA in 2 sections
    $this->actingAs($this->user)->putJson($this->baseUrl, [
        'menu_items' => [
            ['section_name' => 'Best Seller', 'product_ids' => [$this->productA->id]],
            ['section_name' => 'Đồ uống', 'product_ids' => [$this->productA->id]],
        ],
    ])->assertSuccessful();

    expect($this->menu->menuProducts()->count())->toBe(2);

    // Second sync: productA only in 1 section
    $this->actingAs($this->user)->putJson($this->baseUrl, [
        'menu_items' => [
            ['section_name' => 'Best Seller', 'product_ids' => [$this->productA->id]],
        ],
    ])->assertSuccessful();

    // The "Đồ uống" pair was soft-deleted
    expect($this->menu->menuProducts()->count())->toBe(1);
    expect($this->menu->menuProducts()->first()->menuSection->name)->toBe('Best Seller');
});

// =============================================================================
// Detach sections removed from payload
// =============================================================================

it('detaches sections that are no longer in the payload', function () {
    $this->actingAs($this->user)->putJson($this->baseUrl, [
        'menu_items' => [
            ['section_name' => 'Default', 'product_ids' => [$this->productA->id]],
            ['section_name' => 'Old', 'product_ids' => [$this->productB->id]],
        ],
    ])->assertSuccessful();

    expect($this->menu->menuSections()->count())->toBe(2);

    $this->actingAs($this->user)->putJson($this->baseUrl, [
        'menu_items' => [
            ['section_name' => 'Default', 'product_ids' => [$this->productA->id]],
        ],
    ])->assertSuccessful();

    expect($this->menu->menuSections()->count())->toBe(1);
    expect($this->menu->menuSections()->first()->name)->toBe('Default');
});

// =============================================================================
// Reuse existing sections by name (no duplicate section rows on re-sync)
// =============================================================================

it('reuses sections with the same name across repeated syncs', function () {
    $this->actingAs($this->user)->putJson($this->baseUrl, [
        'menu_items' => [
            ['section_name' => 'Default', 'product_ids' => [$this->productA->id]],
        ],
    ])->assertSuccessful();

    $sectionId = $this->menu->menuSections()->first()->id;

    $this->actingAs($this->user)->putJson($this->baseUrl, [
        'menu_items' => [
            ['section_name' => 'Default', 'product_ids' => [$this->productA->id, $this->productB->id]],
        ],
    ])->assertSuccessful();

    expect($this->menu->menuSections()->first()->id)->toBe($sectionId);
    expect(MenuSection::where('name', 'Default')->count())->toBe(1);
});

// =============================================================================
// Branch menu: each (product, section) row gets its OWN set of SKUs
// =============================================================================

it('adds products to a branch menu with is_active=false so shop must explicitly activate them', function () {
    $branchMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => false,
        'status' => 'Draft',
        'created_by_id' => $this->user->id,
        'master_menu_id' => $this->menu->id,
        'brand_id' => $this->brand->id,
    ]);

    $url = "/api/v1/hq/{$this->brand->slug}/menus/{$branchMenu->id}/layout";

    $this->actingAs($this->user)->putJson($url, [
        'menu_items' => [
            ['section_name' => 'Best Seller', 'product_ids' => [$this->productA->id]],
            ['section_name' => 'Đồ uống', 'product_ids' => [$this->productA->id]],
        ],
    ])->assertSuccessful();

    // 2 menu_products rows for productA (one per section)
    expect($branchMenu->menuProducts()->count())->toBe(2);

    // All new branch products start inactive — shop must toggle them on
    expect($branchMenu->menuProducts()->where('is_active', true)->count())->toBe(0);

    // Each row still gets its own menu_product_skus (1 SKU per row × 2 rows = 2)
    $totalSkus = MenuProductSku::whereIn(
        'menu_product_id',
        $branchMenu->menuProducts()->pluck('id'),
    )->count();
    expect($totalSkus)->toBe(2);
});

// =============================================================================
// Empty payload clears the layout
// =============================================================================

it('clears the entire layout when menu_items is empty', function () {
    MenuProduct::factory()->create([
        'menu_id' => $this->menu->id,
        'product_id' => $this->productA->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $this->actingAs($this->user)->putJson($this->baseUrl, [
        'menu_items' => [],
    ])->assertSuccessful();

    expect($this->menu->menuProducts()->count())->toBe(0);
    expect($this->menu->menuSections()->count())->toBe(0);
});

// =============================================================================
// Validation
// =============================================================================

it('rejects payload without menu_items field', function () {
    $this->actingAs($this->user)
        ->putJson($this->baseUrl, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['menu_items']);
});

it('rejects a section without a name', function () {
    $this->actingAs($this->user)
        ->putJson($this->baseUrl, [
            'menu_items' => [
                ['product_ids' => [$this->productA->id]],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['menu_items.0.section_name']);
});

it('rejects a section with a non-existent product id', function () {
    $this->actingAs($this->user)
        ->putJson($this->baseUrl, [
            'menu_items' => [
                [
                    'section_name' => 'Default',
                    'product_ids' => ['019d0000-0000-0000-0000-000000000099'],
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['menu_items.0.product_ids.0']);
});
