<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Models\Zone;
use App\Services\Provisioning\BranchBaselineProvisioner;
use App\Services\Provisioning\BrandBaselineProvisioner;
use App\Services\Shop\BranchProvisioningService;

const BASELINE_ORG = '00000000-0000-0000-0000-000000000001';

function baselineShop(?Brand $brand = null): Branch
{
    $brand ??= Brand::factory()->create(['console_organization_id' => BASELINE_ORG]);

    $branch = Branch::factory()->create([
        'console_organization_id' => BASELINE_ORG,
        'console_brand_id' => $brand->console_brand_id,
        'currency' => 'JPY',
    ]);
    Zone::factory()->for($branch, 'branch')->create(['organization_id' => BASELINE_ORG]);

    return $branch;
}

it('dựng shop_order_settings với tiền tệ của chính chi nhánh', function () {
    $brand = Brand::factory()->create(['console_organization_id' => BASELINE_ORG]);
    app(BrandBaselineProvisioner::class)->ensure($brand);

    $branch = Branch::factory()->create([
        'console_organization_id' => BASELINE_ORG,
        'console_brand_id' => $brand->console_brand_id,
        'currency' => 'VND',
    ]);
    Zone::factory()->for($branch, 'branch')->create(['organization_id' => BASELINE_ORG]);

    app(BranchBaselineProvisioner::class)->ensure($branch);

    $setting = ShopOrderSetting::where('branch_id', $branch->id)->firstOrFail();
    $standard = TaxType::where('brand_id', $brand->id)->where('code', 'STANDARD')->value('id');

    expect($setting->currency_code)->toBe('VND')
        ->and($setting->default_tax_type_id)->toBe($standard);
});

it('idempotent — không tạo hàng cài đặt thứ hai', function () {
    $branch = baselineShop();
    $provisioner = app(BranchBaselineProvisioner::class);

    $provisioner->ensure($branch);
    $second = $provisioner->ensure($branch);

    expect(ShopOrderSetting::where('branch_id', $branch->id)->count())->toBe(1)
        ->and($second->changed())->toBeFalse();
});

it('không đụng vào cài đặt đã có', function () {
    $branch = baselineShop();

    ShopOrderSetting::create([
        'branch_id' => $branch->id,
        'organization_id' => BASELINE_ORG,
        'currency_code' => 'USD',
        'service_charge_rate' => 12.5,
    ]);

    app(BranchBaselineProvisioner::class)->ensure($branch);

    $setting = ShopOrderSetting::where('branch_id', $branch->id)->firstOrFail();
    expect($setting->currency_code)->toBe('USD')
        ->and((float) $setting->service_charge_rate)->toBe(12.5);
});

it('plan() chỉ đọc, không dựng gì', function () {
    $branch = baselineShop();

    $report = app(BranchBaselineProvisioner::class)->plan($branch);

    expect($report->isReady())->toBeFalse()
        ->and(ShopOrderSetting::where('branch_id', $branch->id)->exists())->toBeFalse();
});

it('tạo shop qua API thì baseline nằm trong CÙNG transaction với hàng branch', function () {
    $brand = Brand::factory()->create(['console_organization_id' => BASELINE_ORG]);
    app(BrandBaselineProvisioner::class)->ensure($brand);

    $branch = app(BranchProvisioningService::class)->create(
        $brand,
        BASELINE_ORG,
        ['name' => 'Chi nhánh mới', 'slug' => 'chi-nhanh-moi', 'currency' => 'JPY'],
    );

    expect(ShopOrderSetting::where('branch_id', $branch->id)->exists())->toBeTrue();
});

it('chi nhánh chưa khai currency thì KHÔNG dựng cài đặt và readiness không xanh', function () {
    $brand = Brand::factory()->create(['console_organization_id' => BASELINE_ORG]);
    app(BrandBaselineProvisioner::class)->ensure($brand);

    $branch = Branch::factory()->create([
        'console_organization_id' => BASELINE_ORG,
        'console_brand_id' => $brand->console_brand_id,
        'currency' => null,
    ]);
    Zone::factory()->for($branch, 'branch')->create(['organization_id' => BASELINE_ORG]);

    $report = app(BranchBaselineProvisioner::class)->ensure($branch);

    expect(ShopOrderSetting::where('branch_id', $branch->id)->exists())->toBeFalse()
        ->and($report->isReady())->toBeFalse()
        ->and($report->entriesInState('skipped'))->not->toBe([]);
});
