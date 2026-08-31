<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Device;
use App\Models\MenuPromotion;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Workstation menu-promotion feed (Plan: workstation full promo parity).
 *
 * Must emit:
 *   - applies_to enum, stacking_mode enum, description, created_at —
 *     Phase B schema parity fields.
 *   - product_ids[] — flattened from products + categories.products so
 *     workstation engine matches by membership without needing a local
 *     pos_product_categories pivot (decision Q-B2).
 *   - schedules[] expanded from weekdays + daily_time_from/to (existing
 *     behaviour preserved).
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

it('emits applies_to + stacking_mode + description + created_at', function () {
    MenuPromotion::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'name' => 'PARITY',
        'description' => 'parity test',
        'discount_percent' => 25,
        'applies_to' => 'all_items',
        'stacking_mode' => 'exclusive_with_coupons',
        'is_active' => true,
        // ISO 8601 weekdays, 1=Mon .. 7=Sun (see MenuPromotion.yaml). The old
        // [0..6] carried an invalid 0 and omitted 7, so this fixture silently
        // stopped matching every Sunday.
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addMonth(),
    ]);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu-promotions')
        ->assertOk();

    $row = collect($resp->json('data'))->firstWhere('name', 'PARITY');
    expect($row)->not->toBeNull();
    expect($row['applies_to'])->toBe('all_items');
    expect($row['stacking_mode'])->toBe('exclusive_with_coupons');
    expect($row['description'])->toBe('parity test');
    expect($row['exclusive_with_coupons'])->toBe(true);
    expect($row['created_at'])->not->toBe('');
    // all_items + no products attached → empty product_ids[]
    expect($row['product_ids'])->toBe([]);
});

it('flattens products + categories.products into product_ids[]', function () {
    $directProduct = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $categoryProductA = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $categoryProductB = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $category = Category::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $category->products()->attach([$categoryProductA->id, $categoryProductB->id]);

    $promo = MenuPromotion::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'name' => 'MIXED',
        'discount_percent' => 15,
        'applies_to' => 'mixed',
        'is_active' => true,
        // ISO 8601 weekdays, 1=Mon .. 7=Sun (see MenuPromotion.yaml). The old
        // [0..6] carried an invalid 0 and omitted 7, so this fixture silently
        // stopped matching every Sunday.
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addMonth(),
    ]);
    $promo->products()->attach($directProduct->id);
    $promo->categories()->attach($category->id);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu-promotions')
        ->assertOk();

    $row = collect($resp->json('data'))->firstWhere('name', 'MIXED');
    expect($row['product_ids'])->toHaveCount(3);
    expect($row['product_ids'])->toContain($directProduct->id);
    expect($row['product_ids'])->toContain($categoryProductA->id);
    expect($row['product_ids'])->toContain($categoryProductB->id);
});
