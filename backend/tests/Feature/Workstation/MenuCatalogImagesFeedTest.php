<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\File;
use App\Models\Menu;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Omnify\Enums\FileStatusEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Workstation menu-catalog feed must surface product.image_url,
 * product.product_type_code, sku.image_url, and the full
 * product_galleries[] so the LAN-mode MenuCatalog can render real
 * thumbnails + carousels instead of placeholders. Without these fields
 * pos-web's MenuProductTile + cart line images fall back to blank
 * even when the menu owner has uploaded a gallery.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

it('emits product_type_code, image_url, sku image_url and gallery rows', function () {
    $productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'code' => 'COMBO',
        'name' => 'Combo',
    ]);

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $productType->id,
        'status' => 'active',
        'is_hidden' => false,
    ]);

    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'selling_price' => 1000,
    ]);

    // Two product gallery files in distinct sort_order.
    $gallery1 = File::factory()->create([
        'organization_id' => $this->orgId,
        // Production stores the morph alias ("Product"), not the FQCN,
        // because OmnifyServiceProvider calls Relation::enforceMorphMap.
        // Tests must mirror that data shape so the replica controller's
        // `where('fileable_type', (new Product)->getMorphClass())` matches.
        'fileable_type' => (new Product)->getMorphClass(),
        'fileable_id' => $product->id,
        'status' => FileStatusEnum::Permanent,
        'collection' => 'gallery',
        'sort_order' => 1,
    ]);
    $gallery2 = File::factory()->create([
        'organization_id' => $this->orgId,
        // Production stores the morph alias ("Product"), not the FQCN,
        // because OmnifyServiceProvider calls Relation::enforceMorphMap.
        // Tests must mirror that data shape so the replica controller's
        // `where('fileable_type', (new Product)->getMorphClass())` matches.
        'fileable_type' => (new Product)->getMorphClass(),
        'fileable_id' => $product->id,
        'status' => FileStatusEnum::Permanent,
        'collection' => 'gallery',
        'sort_order' => 2,
    ]);

    // Per-SKU image — backend ProductSkuResource resolves to image_url
    // via galleryFirst on attachable_type=ProductSku.
    $skuImage = File::factory()->create([
        'organization_id' => $this->orgId,
        'fileable_type' => (new ProductSku)->getMorphClass(),
        'fileable_id' => $sku->id,
        'status' => FileStatusEnum::Permanent,
        'collection' => 'gallery',
        'sort_order' => 1,
    ]);

    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);

    // Raw DB rows so we don't fight per-factory ad-hoc column mismatches.
    $mpId = (string) Str::uuid();
    DB::table('menu_products')->insert([
        'id' => $mpId,
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);
    DB::table('menu_product_skus')->insert([
        'id' => (string) Str::uuid(),
        'menu_product_id' => $mpId,
        'product_sku_id' => $sku->id,
        'selling_price' => 1000,
        'is_active' => true,
    ]);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu-catalog')
        ->assertOk();

    $payload = $resp->json('data');

    // product_type_code is lower-cased.
    $productOut = collect($payload['products'])->firstWhere('id', $product->id);
    expect($productOut)->not->toBeNull()
        ->and($productOut['product_type_code'])->toBe('combo')
        ->and($productOut['image_url'])->toBe($gallery1->getUrl()); // first by sort_order

    // Both gallery rows present in sort_order.
    $galleryOut = collect($payload['product_galleries'])
        ->where('product_id', $product->id)
        ->sortBy('sort_order')
        ->values();
    expect($galleryOut)->toHaveCount(2)
        ->and($galleryOut[0]['url'])->toBe($gallery1->getUrl())
        ->and($galleryOut[1]['url'])->toBe($gallery2->getUrl());

    // SKU image surfaces in skus[].
    $skuOut = collect($payload['skus'])->firstWhere('id', $sku->id);
    expect($skuOut['image_url'])->toBe($skuImage->getUrl());
});

it('returns null image_url and empty galleries when the product has no files', function () {
    $productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $productType->id,
        'status' => 'active',
        'is_hidden' => false,
    ]);
    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
    ]);
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);
    $mpId = (string) Str::uuid();
    DB::table('menu_products')->insert([
        'id' => $mpId,
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);
    DB::table('menu_product_skus')->insert([
        'id' => (string) Str::uuid(),
        'menu_product_id' => $mpId,
        'product_sku_id' => $sku->id,
        'selling_price' => 100,
        'is_active' => true,
    ]);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu-catalog')
        ->assertOk();

    $productOut = collect($resp->json('data.products'))->firstWhere('id', $product->id);
    expect($productOut['image_url'])->toBeNull();

    expect($resp->json('data.product_galleries'))->toBe([]);
});
