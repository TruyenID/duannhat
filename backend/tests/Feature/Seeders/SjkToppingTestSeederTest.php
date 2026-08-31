<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductType;
use Database\Seeders\ProductSeeder;
use Database\Seeders\SjkToppingTestSeeder;

it('attaches toppings by stable slug when the product name is Japanese', function () {
    $organization = Organization::query()->firstOrFail();
    $brand = Brand::factory()->create([
        'console_organization_id' => $organization->console_organization_id,
        'is_active' => true,
    ]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $organization->console_organization_id,
        'console_brand_id' => $brand->console_brand_id,
        'slug' => 'sjk',
        'name' => '新宿店',
    ]);
    $menu = Menu::factory()->create([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'status' => 'Active',
    ]);
    $product = Product::factory()->active()->create([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'slug' => 'pho-bo',
        'name' => 'フォー・ボー',
    ]);

    MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'is_active' => true,
    ]);

    $this->seed(SjkToppingTestSeeder::class);
    $this->seed(SjkToppingTestSeeder::class);

    expect($product->toppingGroups()->pluck('name')->all())
        ->toEqualCanonicalizing([
            'Spice level',
            'Add-ons',
            'Remove ingredients',
        ])
        ->and($product->toppingGroups()->count())
        ->toBe(3);
});

it('backfills translations for an existing seeded product', function () {
    $organization = Organization::query()->firstOrFail();
    $brand = Brand::factory()->create([
        'console_organization_id' => $organization->console_organization_id,
        'is_active' => true,
    ]);
    $foodType = ProductType::factory()->create([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'code' => 'FOOD',
    ]);
    ProductType::factory()->create([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'code' => 'DRINK',
    ]);
    $product = Product::factory()->active()->create([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'product_type_id' => $foodType->id,
        'slug' => 'pho-bo',
        'name' => 'フォー・ボー',
    ]);

    $this->seed(ProductSeeder::class);

    expect($product->translations()->pluck('name', 'locale')->all())
        ->toMatchArray([
            'ja' => 'フォー・ボー',
            'en' => 'Beef Pho',
            'vi' => 'Phở Bò',
        ]);
});
