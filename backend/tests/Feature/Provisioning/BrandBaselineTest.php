<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\TaxType;
use App\Services\Provisioning\BrandBaselineProvisioner;
use App\Services\Tax\TaxTypeService;

/**
 * #2320 — baseline của brand: reconcile idempotent, không bao giờ ghi đè lựa
 * chọn đã có của người vận hành.
 */
function baselineBrand(): Brand
{
    return Brand::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);
}

function strippedBrand(): Brand
{
    $brand = baselineBrand();

    // Hook `Brand::created` đã cấp Reverb + combo; gỡ ra để bài test bắt đầu từ
    // một brand TRẦN, đúng trạng thái mà seed hoặc đồng bộ Platform tạo ra.
    $brand->forceFill([
        'reverb_app_id' => null,
        'reverb_app_key' => null,
        'reverb_app_secret' => null,
        'reverb_provisioned_at' => null,
    ])->saveQuietly();

    ProductType::query()->where('brand_id', $brand->id)->forceDelete();

    return $brand->fresh();
}

it('dựng đủ baseline cho một brand trần', function () {
    $brand = strippedBrand();
    expect(TaxType::where('brand_id', $brand->id)->count())->toBe(0);

    $report = app(BrandBaselineProvisioner::class)->ensure($brand);

    $types = TaxType::where('brand_id', $brand->id)->get();
    expect($types)->toHaveCount(3)
        ->and($types->pluck('code')->sort()->values()->all())->toBe(['EXEMPT', 'REDUCED', 'STANDARD'])
        ->and($types->where('is_default', true))->toHaveCount(1)
        ->and($brand->fresh()->reverb_app_id)->not->toBeNull()
        ->and(ProductType::where('brand_id', $brand->id)->where('code', 'combo')->exists())->toBeTrue()
        ->and($report->changed())->toBeTrue();
});

it('idempotent — lượt hai không ghi gì và báo sẵn sàng', function () {
    $brand = strippedBrand();
    $provisioner = app(BrandBaselineProvisioner::class);
    $provisioner->ensure($brand);

    $second = $provisioner->ensure($brand->fresh());

    expect($second->changed())->toBeFalse()
        ->and($second->isReady())->toBeTrue()
        ->and($second->entriesInState('applied'))->toBe([]);
});

it('không cướp mặc định mà người vận hành đã đổi', function () {
    $brand = strippedBrand();
    $provisioner = app(BrandBaselineProvisioner::class);
    $provisioner->ensure($brand);

    // Người vận hành trỏ mặc định sang 軽減税率.
    $reduced = TaxType::where('brand_id', $brand->id)->where('code', 'REDUCED')->firstOrFail();
    app(TaxTypeService::class)->update($reduced, ['is_default' => true]);

    $provisioner->ensure($brand->fresh());

    $types = TaxType::where('brand_id', $brand->id)->get();
    expect($types->where('is_default', true))->toHaveCount(1)
        ->and($types->firstWhere('is_default', true)->code)->toBe('REDUCED');
});

it('đóng dấu loại thuế mặc định CHỈ lên product chưa gắn gì', function () {
    $brand = strippedBrand();
    $provisioner = app(BrandBaselineProvisioner::class);
    $provisioner->ensure($brand);

    $pt = ProductType::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $brand->id,
    ]);
    $standard = TaxType::where('brand_id', $brand->id)->where('code', 'STANDARD')->firstOrFail();
    $reduced = TaxType::where('brand_id', $brand->id)->where('code', 'REDUCED')->firstOrFail();

    $unstamped = Product::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $brand->id, 'product_type_id' => $pt->id, 'tax_type_id' => null,
    ]);
    // Món 軽減税率 do người vận hành gán — ĐÂY là hàng mà seeder cũ dán đè.
    $reducedProduct = Product::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $brand->id, 'product_type_id' => $pt->id, 'tax_type_id' => $reduced->id,
    ]);

    $provisioner->ensure($brand->fresh());

    expect($unstamped->fresh()->tax_type_id)->toBe($standard->id)
        ->and($reducedProduct->fresh()->tax_type_id)->toBe($reduced->id);
});

it('brand chưa có organization thì báo CHƯA BIẾT, không báo sẵn sàng', function () {
    $brand = Brand::factory()->create(['console_organization_id' => 'org-khong-ton-tai']);

    $report = app(BrandBaselineProvisioner::class)->plan($brand);

    expect($report->isReady())->toBeFalse()
        ->and($report->entriesInState('skipped'))->toHaveCount(1)
        ->and(TaxType::where('brand_id', $brand->id)->count())->toBe(0);
});

it('plan() không ghi gì xuống DB', function () {
    $brand = strippedBrand();

    $report = app(BrandBaselineProvisioner::class)->plan($brand);

    expect($report->isReady())->toBeFalse()
        ->and($report->entriesInState('missing'))->not->toBe([])
        ->and(TaxType::where('brand_id', $brand->id)->count())->toBe(0)
        ->and($brand->fresh()->reverb_app_id)->toBeNull();
});
