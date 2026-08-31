<?php

// #2537 — a SKU created at HQ joins every menu that already sells its product.
//
// Production: 本郷店's own menu (master_menu_id = null, built 2026-07-17) sold a
// product whose second variant, created 2026-08-11, never appeared — the shop
// saw one variant for three weeks. Menus carrying a master_menu_id only looked
// right because clone/sync reads product->skus at clone time; a menu older than
// the SKU had no repair path at all, and a shop-owned menu has no master to
// sync from in the first place.

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-0000000000d4';
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

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
    $this->product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
    ]);

    // The shop's own menu — no master_menu_id, so sync-from-master throws and
    // clone never runs. This is the menu production left one variant short.
    $this->shopMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_master' => false,
        'status' => 'Active',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);
    $this->shopMp = MenuProduct::factory()->create([
        'menu_id' => $this->shopMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);
});

it('attaches a newly created SKU to a shop-owned menu that predates it', function () {
    $sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'selling_price' => 45000.00,
        'is_active' => true,
    ]);

    $row = MenuProductSku::where('menu_product_id', $this->shopMp->id)
        ->where('product_sku_id', $sku->id)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->is_price_overridden)->toBeFalse();
    expect((string) $row->selling_price)->toBe('45000.00');

    // Inactive on arrival, same as the option-expand path: the row exists so the
    // shop can see and enable the variant, but HQ does not put it on sale for
    // them ("HQ thêm ≠ shop bán ngay").
    expect($row->is_active)->toBeFalse();
});

it('leaves master menus without SKU rows', function () {
    $masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Active',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
    ]);
    $masterMp = MenuProduct::factory()->create([
        'menu_id' => $masterMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'is_active' => true,
    ]);

    expect(MenuProductSku::where('menu_product_id', $masterMp->id)->count())->toBe(0);
});

it('prices the row from the column default when the SKU was built without a price', function () {
    // Option-expand fills a SKU without selling_price: the model carries null,
    // the row carries the default. Copying the null into menu_product_skus
    // (NOT NULL) used to blow up the whole expand request.
    $sku = new ProductSku;
    $sku->fill(['sku' => 'EXPANDED-1', 'is_active' => true]);
    $sku->forceFill(['product_id' => $this->product->id]);
    $sku->save();

    expect($sku->getAttributes()['selling_price'] ?? null)->toBeNull();

    $row = MenuProductSku::where('menu_product_id', $this->shopMp->id)
        ->where('product_sku_id', $sku->id)
        ->first();

    expect($row)->not->toBeNull();
    expect((string) $row->selling_price)->toBe('0.00');
});

it('leaves menus of other products alone', function () {
    $otherProduct = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
    ]);
    $otherMp = MenuProduct::factory()->create([
        'menu_id' => $this->shopMenu->id,
        'product_id' => $otherProduct->id,
        'is_active' => true,
        'display_order' => 2,
    ]);

    ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'is_active' => true,
    ]);

    expect(MenuProductSku::where('menu_product_id', $otherMp->id)->count())->toBe(0);
});

it('does not resurrect a soft-deleted menu_product', function () {
    $this->shopMp->delete();

    $sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'is_active' => true,
    ]);

    expect(
        MenuProductSku::withTrashed()
            ->where('menu_product_id', $this->shopMp->id)
            ->where('product_sku_id', $sku->id)
            ->exists()
    )->toBeFalse();
});
