<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\FloatingSection;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Services\Promotion\FloatingSectionPriceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

it('resolves the lowest active scheduled branch price and ignores HQ masters', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'timezone' => 'Asia/Tokyo',
    ]);
    $type = ProductType::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);
    $product = Product::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'product_type_id' => $type->id,
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 1000]);
    $at = CarbonImmutable::parse('2026-07-22 18:00:00', 'Asia/Tokyo'); // Wednesday

    $makeSection = function (?string $branchId, float $price, bool $scheduleActive = true) use ($orgId, $brand, $product, $sku) {
        $section = FloatingSection::factory()->create([
            'organization_id' => $orgId,
            'brand_id' => $brand->id,
            'branch_id' => $branchId,
            'is_active' => true,
            'start_date' => null,
            'end_date' => null,
        ]);
        $section->schedules()->create([
            'start_time' => '17:00:00',
            'end_time' => '19:00:00',
            'days_of_week' => 1 << 3,
            'is_active' => $scheduleActive,
            'priority' => 0,
        ]);
        $floatingProduct = $section->products()->create([
            'product_id' => $product->id,
            'is_active' => true,
            'display_order' => 1,
        ]);
        $floatingProduct->skus()->create([
            'product_sku_id' => $sku->id,
            'is_active' => true,
            'selling_price' => $price,
            'is_price_overridden' => true,
        ]);

        return $section;
    };

    $makeSection(null, 100); // HQ master must never affect branch sales.
    $makeSection($branch->id, 700);
    $winner = $makeSection($branch->id, 500);
    $makeSection($branch->id, 300, false);

    $resolved = app(FloatingSectionPriceResolver::class)
        ->resolveForSkus($branch->id, [$sku->id], $at);

    expect($resolved[$sku->id]['price'])->toBe(500.0)
        ->and($resolved[$sku->id]['floating_section_id'])->toBe($winner->id);
});

it('requires an active matching schedule', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'timezone' => 'Asia/Tokyo',
    ]);

    expect(app(FloatingSectionPriceResolver::class)->resolveForSkus(
        $branch->id,
        [(string) Str::uuid()],
        CarbonImmutable::parse('2026-07-22 18:00:00', 'Asia/Tokyo'),
    ))->toBe([]);
});
