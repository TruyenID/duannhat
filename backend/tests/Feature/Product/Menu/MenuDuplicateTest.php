<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

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
        'console_brand_id' => $this->brand->id,
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

    $this->menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'is_master' => false,
        'master_menu_id' => null,
        'status' => 'Active',
        'priority' => 5,
        'created_by_id' => $this->user->id,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
});

it('duplicates sections, products, SKUs, and schedules into an independent Draft copy', function () {
    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/{$this->menu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Best Seller', 'product_ids' => [$this->productA->id]],
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productA->id, $this->productB->id]],
            ],
        ])
        ->assertSuccessful();

    // Give productA's row in "Best Seller" a price override — the copy must preserve it.
    $sourceMp = $this->menu->menuProducts()
        ->where('product_id', $this->productA->id)
        ->whereHas('menuSection', fn ($q) => $q->where('name', 'Best Seller'))
        ->firstOrFail();
    $sourceMp->menuProductSkus()->first()->update([
        'selling_price' => 19999,
        'is_price_overridden' => true,
    ]);

    MenuSchedule::factory()->create([
        'menu_id' => $this->menu->id,
        'start_time' => '11:00:00',
        'end_time' => '14:00:00',
        'days_of_week' => 62,
        'is_active' => true,
        'priority' => 0,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$this->menu->id}/duplicate");

    $response->assertCreated();

    $copy = Menu::where('id', $response->json('data.id'))->firstOrFail();

    expect($copy->id)->not->toBe($this->menu->id);
    expect($copy->name)->toBe("{$this->menu->name} (Copy)");
    expect($copy->status)->toBe('Draft');
    expect($copy->branch_id)->toBe($this->menu->branch_id);
    expect($copy->is_master)->toBe($this->menu->is_master);
    expect($copy->master_menu_id)->toBeNull();

    // Sections pivot copied
    expect($copy->menuSections()->count())->toBe(2);
    expect($copy->menuSections()->pluck('name')->sort()->values()->all())
        ->toBe(['Best Seller', 'Đồ uống']);

    // Products copied 1:1 (3 rows: productA×2 sections + productB×1 section)
    expect($copy->menuProducts()->count())->toBe(3);
    $copy->menuProducts->each(fn ($mp) => expect($mp->master_menu_product_id)->toBeNull());

    // Price override preserved on the copy
    $copiedMp = $copy->menuProducts()
        ->where('product_id', $this->productA->id)
        ->whereHas('menuSection', fn ($q) => $q->where('name', 'Best Seller'))
        ->firstOrFail();
    $copiedSku = $copiedMp->menuProductSkus()->first();
    expect((float) $copiedSku->selling_price)->toBe(19999.0);
    expect($copiedSku->is_price_overridden)->toBeTrue();

    // Schedule copied, independent (no master_schedule_id link)
    expect($copy->schedules()->count())->toBe(1);
    $copiedSchedule = $copy->schedules()->first();
    expect($copiedSchedule->getRawOriginal('start_time'))->toBe('11:00:00');
    expect($copiedSchedule->getRawOriginal('end_time'))->toBe('14:00:00');
    expect($copiedSchedule->days_of_week)->toBe(62);
    expect($copiedSchedule->master_schedule_id)->toBeNull();
});

it('assigns the next priority within the same branch scope', function () {
    Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'is_master' => false,
        'priority' => 9,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/menus/{$this->menu->id}/duplicate")
        ->assertCreated();

    $copy = Menu::findOrFail($response->json('data.id'));
    expect($copy->priority)->toBe(10);
});

it('forbids duplicating a menu from another organization', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);

    $otherUser = User::factory()->create([
        'console_organization_id' => $otherOrgId,
    ]);
    $otherAdminRole = Role::firstOrCreate(
        ['slug' => 'org-admin'],
        ['name' => 'Org Admin', 'level' => 100],
    );
    $otherUser->assignRole($otherAdminRole, $otherOrgId);

    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $otherOrgId,
    ]);

    $this->actingAs($otherUser)
        ->postJson("/api/v1/hq/{$this->brand->slug}/menus/{$this->menu->id}/duplicate")
        ->assertForbidden();
});
