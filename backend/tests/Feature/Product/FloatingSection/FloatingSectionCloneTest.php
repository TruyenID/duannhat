<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\FloatingSection;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\TaxType;
use App\Models\User;
use App\Services\Product\FloatingSectionService;
use App\Services\Product\ProductSkuService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->adminRole = Role::firstOrCreate(
        ['slug' => 'org-admin'],
        ['name' => 'Org Admin', 'level' => 100],
    );
    $this->adminRole->permissions()->syncWithoutDetaching(collect(['menu.view', 'menu.manage'])
        ->map(fn ($slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'group' => 'menu'])->id));

    $this->admin = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->admin->assignRole($this->adminRole, $this->orgId);

    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->master = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'name' => 'Happy Hour',
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
});

it('clones schedules and products into an independent branch section', function () {
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/schedules", [
            'start_time' => '17:00', 'end_time' => '19:00', 'days_of_week' => 127,
        ])
        ->assertCreated();

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
    ]);
    $sku1 = ProductSku::factory()->create(['product_id' => $product->id, 'is_active' => true]);
    $sku2 = ProductSku::factory()->withSequencedOption()->create(['product_id' => $product->id, 'is_active' => true]);
    ProductSku::factory()->withSequencedOption()->create(['product_id' => $product->id, 'is_active' => false]);
    $addResponse = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/products", [
            'product_ids' => [$product->id],
        ])
        ->assertCreated();
    $masterProductId = $addResponse->json('data.0.id');

    // HQ sets a promo price on one variant.
    $masterProduct = FloatingSection::find($this->master->id)->products()->firstOrFail();
    $masterSku1 = $masterProduct->skus()->where('product_sku_id', $sku1->id)->firstOrFail();
    $this->actingAs($this->admin)
        ->patchJson("{$this->baseUrl}/floating-sections/{$this->master->id}/products/{$masterProductId}/skus/{$masterSku1->id}/price", [
            'selling_price' => 12345,
        ])
        ->assertOk();

    $response = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Happy Hour')
        ->assertJsonPath('data.branch_id', $this->branch->id)
        ->assertJsonPath('data.master_section_id', $this->master->id);

    $branchSection = FloatingSection::where('master_section_id', $this->master->id)->firstOrFail();
    expect($branchSection->schedules()->count())->toBe(1);
    expect($branchSection->schedules()->first()->getRawOriginal('start_time'))->toBe('17:00:00');

    expect($branchSection->products()->count())->toBe(1);
    $clonedProduct = $branchSection->products()->first();

    // Only the 2 ACTIVE SKUs got cloned, carrying over HQ's promo price
    // and override flag from the master's own rows.
    expect($clonedProduct->skus()->count())->toBe(2);
    expect($clonedProduct->skus()->where('is_active', true)->count())->toBe(2);
    $clonedSku1 = $clonedProduct->skus()->where('product_sku_id', $sku1->id)->firstOrFail();
    expect((float) $clonedSku1->selling_price)->toBe(12345.0);
    expect($clonedSku1->is_price_overridden)->toBeTrue();
    $clonedSku2 = $clonedProduct->skus()->where('product_sku_id', $sku2->id)->firstOrFail();
    expect($clonedSku2->is_price_overridden)->toBeFalse();

    // The clone is fully independent — editing the shop's copy does not
    // touch the master, and vice versa (no ongoing sync).
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/products/{$masterProductId}/toggle")
        ->assertOk();
    expect($branchSection->products()->first()->fresh()->is_active)->toBeTrue();
});

it('rejects cloning the same master to the same branch twice', function () {
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])
        ->assertCreated();

    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'FLOATING_SECTION_OPERATION_NOT_ALLOWED');
});

it('rejects cloning a branch clone (only masters can be cloned)', function () {
    $clone = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])
        ->json('data');

    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$clone['id']}/clone-to-branch", [
            'branch_id' => $otherBranch->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'FLOATING_SECTION_OPERATION_NOT_ALLOWED');
});

it('rejects cloning to a branch outside the organization', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $foreignBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
    ]);

    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/clone-to-branch", [
            'branch_id' => $foreignBranch->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_id']);
});

it('rejects cloning to another brand in the same organization', function () {
    $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $otherBrand->console_brand_id,
    ]);

    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/clone-to-branch", [
            'branch_id' => $otherBranch->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('branch_id');
});

it('HQ list only shows master sections, not branch clones', function () {
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])
        ->assertCreated();

    $response = $this->actingAs($this->admin)->getJson("{$this->baseUrl}/floating-sections");
    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.id'))->toBe($this->master->id);
});

it('duplicates a master section into an independent copy, carrying schedules/products/SKU pricing', function () {
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/schedules", [
            'start_time' => '17:00', 'end_time' => '19:00', 'days_of_week' => 127,
        ])
        ->assertCreated();

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'is_active' => true]);
    $addResponse = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/products", [
            'product_ids' => [$product->id],
        ])
        ->assertCreated();
    $masterProductId = $addResponse->json('data.0.id');
    $masterProduct = FloatingSection::find($this->master->id)->products()->firstOrFail();
    $masterSku = $masterProduct->skus()->where('product_sku_id', $sku->id)->firstOrFail();
    $this->actingAs($this->admin)
        ->patchJson("{$this->baseUrl}/floating-sections/{$this->master->id}/products/{$masterProductId}/skus/{$masterSku->id}/price", [
            'selling_price' => 4999,
        ])
        ->assertOk();

    $response = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/duplicate");

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Happy Hour (Copy)')
        ->assertJsonPath('data.branch_id', null)
        ->assertJsonPath('data.master_section_id', null);

    $copyId = $response->json('data.id');
    $copy = FloatingSection::findOrFail($copyId);
    expect($copy->schedules()->count())->toBe(1);
    expect($copy->schedules()->first()->getRawOriginal('start_time'))->toBe('17:00:00');

    expect($copy->products()->count())->toBe(1);
    $copiedProduct = $copy->products()->first();
    $copiedSku = $copiedProduct->skus()->where('product_sku_id', $sku->id)->firstOrFail();
    expect((float) $copiedSku->selling_price)->toBe(4999.0);
    expect($copiedSku->is_price_overridden)->toBeTrue();

    // Independent — editing the source master never touches the copy.
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/products/{$masterProductId}/toggle")
        ->assertOk();
    expect($copiedProduct->fresh()->is_active)->toBeTrue();

    // Both the source and the copy show up as separate master rows.
    $this->actingAs($this->admin)
        ->getJson("{$this->baseUrl}/floating-sections")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('rejects duplicating a branch clone (only masters can be duplicated)', function () {
    $clone = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])
        ->json('data');

    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$clone['id']}/duplicate")
        ->assertUnprocessable()
        ->assertJsonPath('error', 'FLOATING_SECTION_OPERATION_NOT_ALLOWED');
});

// =========================================================================
//  HQ "variant off" mirrors to a cloned floating section ON SYNC — same
//  contract as the main menu: shop stays untouched until it syncs, and the
//  shop's price override survives the deactivation.
// =========================================================================

it('deactivates the branch floating SKU on sync when HQ disables the ProductSku, keeping the shop override', function () {
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'is_active' => true]);

    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/products", [
            'product_ids' => [$product->id],
        ])->assertCreated();

    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])->assertCreated();

    $branchSection = FloatingSection::where('master_section_id', $this->master->id)->firstOrFail();
    $branchSku = $branchSection->products()->first()->skus()->where('product_sku_id', $sku->id)->firstOrFail();
    // Shop sets its own price on the branch SKU.
    $branchSku->update(['selling_price' => 9999, 'is_price_overridden' => true, 'is_active' => true]);

    // HQ disables the variant. Before the shop syncs, the branch row is untouched.
    app(ProductSkuService::class)->toggleStatus($sku);
    expect($branchSku->fresh()->is_active)->toBeTrue();

    // Shop runs "Đồng bộ từ HQ".
    app(FloatingSectionService::class)->syncFromMaster($branchSection->fresh());

    $fresh = $branchSku->fresh();
    expect($fresh->is_active)->toBeFalse()                    // now hidden
        ->and((float) $fresh->selling_price)->toBe(9999.0)   // override kept
        ->and($fresh->is_price_overridden)->toBeTrue();
});

it('lands a product added at HQ after clone as INACTIVE on the floating section after sync', function () {
    // Clone with product A (active — clone contract = ready to sell).
    $productA = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
    ]);
    ProductSku::factory()->create(['product_id' => $productA->id, 'is_active' => true]);
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/products", [
            'product_ids' => [$productA->id],
        ])->assertCreated();
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])->assertCreated();

    $branchSection = FloatingSection::where('master_section_id', $this->master->id)->firstOrFail();
    $branchA = $branchSection->products()->where('product_id', $productA->id)->firstOrFail();
    expect($branchA->is_active)->toBeTrue(); // clone = active

    // HQ adds product B, then the shop syncs.
    $productB = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
    ]);
    ProductSku::factory()->create(['product_id' => $productB->id, 'is_active' => true]);
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/products", [
            'product_ids' => [$productB->id],
        ])->assertCreated();

    app(FloatingSectionService::class)->syncFromMaster($branchSection->fresh());

    // B arrived via SYNC → inactive (product + its SKU); A (cloned) stays active.
    $branchB = $branchSection->products()->where('product_id', $productB->id)->firstOrFail();
    expect($branchB->is_active)->toBeFalse()
        ->and($branchB->skus()->firstOrFail()->is_active)->toBeFalse()
        ->and($branchSection->products()->where('product_id', $productA->id)->firstOrFail()->is_active)->toBeTrue();
});

// =========================================================================
//  #1233 — the per-item tax tier must survive every copy path
// =========================================================================

/**
 * `FloatingSectionProduct.tax_type_id` is not decorative: CustomerMenuService
 * collapses it into the customer + workstation feed
 * (`$sectionProduct->tax_type_id ?? $product->tax_type_id`), so it is the rate
 * the till actually charges. Three of the four copy paths dropped it, which
 * meant a 軽減税率 8% promo section came back charging the standard rate the
 * moment it was cloned to a shop, duplicated at HQ, or picked up by sync.
 *
 * Tax MIRRORS the master — unlike `is_active`, which is the shop's own call.
 * The shop has no tax editor for floating sections (the only route is under
 * `/hq/`), so mirroring is the sole way a rate retired at HQ leaves the shops.
 */
function fsTaxSetup($test): array
{
    $reduced = TaxType::factory()->create([
        'organization_id' => $test->orgId,
        'brand_id' => $test->brand->id,
        'code' => 'RED-1233',
        'rate' => 8,
    ]);

    $product = Product::factory()->create([
        'organization_id' => $test->orgId,
        'brand_id' => $test->brand->id,
        'product_type_id' => $test->productType->id,
    ]);
    ProductSku::factory()->create(['product_id' => $product->id, 'is_active' => true]);

    $added = $test->actingAs($test->admin)
        ->postJson("{$test->baseUrl}/floating-sections/{$test->master->id}/products", [
            'product_ids' => [$product->id],
        ])->assertCreated();

    $test->actingAs($test->admin)
        ->patchJson("{$test->baseUrl}/floating-sections/{$test->master->id}/products/{$added->json('data.0.id')}/tax-type", [
            'tax_type_id' => $reduced->id,
        ])->assertOk();

    return [$reduced, $product];
}

it('carries the item tax tier onto a branch clone', function () {
    [$reduced] = fsTaxSetup($this);

    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])->assertCreated();

    $branchSection = FloatingSection::where('master_section_id', $this->master->id)->firstOrFail();

    expect($branchSection->products()->value('tax_type_id'))->toBe($reduced->id);
});

it('carries the item tax tier onto a duplicated master', function () {
    [$reduced] = fsTaxSetup($this);

    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/duplicate")
        ->assertCreated();

    $copy = FloatingSection::where('id', '!=', $this->master->id)
        ->whereNull('branch_id')->where('brand_id', $this->brand->id)
        ->latest('created_at')->firstOrFail();

    expect($copy->products()->value('tax_type_id'))->toBe($reduced->id);
});

it('carries the item tax tier onto a product that arrives by sync', function () {
    // Clone first with nothing on it, then add the taxed product at HQ so it
    // reaches the branch through syncFromMaster rather than the clone path.
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])->assertCreated();

    [$reduced] = fsTaxSetup($this);

    app(FloatingSectionService::class)->syncFromMaster(
        FloatingSection::where('master_section_id', $this->master->id)->firstOrFail()
    );

    $branchProduct = FloatingSection::where('master_section_id', $this->master->id)
        ->firstOrFail()->products()->firstOrFail();

    // is_active stays the shop's call; only the rate follows down.
    expect($branchProduct->tax_type_id)->toBe($reduced->id)
        ->and((bool) $branchProduct->is_active)->toBeFalse();
});

it('clears the item tax tier at the branch when HQ retires it', function () {
    // The mirror direction that matters most: without it, the only way to drop
    // a rate from a shop would be to delete the section and clone it again.
    [$reduced] = fsTaxSetup($this);

    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])->assertCreated();

    $branchSection = FloatingSection::where('master_section_id', $this->master->id)->firstOrFail();
    expect($branchSection->products()->value('tax_type_id'))->toBe($reduced->id);

    $masterProduct = FloatingSection::find($this->master->id)->products()->firstOrFail();
    $this->actingAs($this->admin)
        ->patchJson("{$this->baseUrl}/floating-sections/{$this->master->id}/products/{$masterProduct->id}/tax-type", [
            'tax_type_id' => null,
        ])->assertOk();

    app(FloatingSectionService::class)->syncFromMaster($branchSection);

    expect($branchSection->fresh()->products()->value('tax_type_id'))->toBeNull();
});
