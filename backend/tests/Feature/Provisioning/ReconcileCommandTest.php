<?php

use App\Models\Brand;
use App\Models\ProductType;
use App\Models\TaxType;

function reconcileBrand(string $slug): Brand
{
    $brand = Brand::factory()->create([
        'slug' => $slug,
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    $brand->forceFill([
        'reverb_app_id' => null,
        'reverb_app_key' => null,
        'reverb_app_secret' => null,
        'reverb_provisioned_at' => null,
    ])->saveQuietly();
    ProductType::query()->where('brand_id', $brand->id)->forceDelete();

    return $brand->fresh();
}

it('--dry-run báo cái đang thiếu mà không ghi gì', function () {
    $brand = reconcileBrand('rc-dry');

    $this->artisan('provisioning:reconcile', ['--brand' => 'rc-dry', '--dry-run' => true])
        ->assertSuccessful();

    expect(TaxType::where('brand_id', $brand->id)->count())->toBe(0)
        ->and($brand->fresh()->reverb_app_id)->toBeNull();
});

it('vá brand cũ đang thiếu, và lượt hai không còn gì để làm', function () {
    $brand = reconcileBrand('rc-fix');

    $this->artisan('provisioning:reconcile', ['--brand' => 'rc-fix'])->assertSuccessful();

    expect(TaxType::where('brand_id', $brand->id)->count())->toBe(3);

    $this->artisan('provisioning:reconcile', ['--brand' => 'rc-fix'])
        ->expectsOutputToContain('Baseline đầy đủ')
        ->assertSuccessful();
});

it('--brand giới hạn đúng phạm vi, không đụng brand khác', function () {
    $target = reconcileBrand('rc-target');
    $other = reconcileBrand('rc-other');

    $this->artisan('provisioning:reconcile', ['--brand' => 'rc-target'])->assertSuccessful();

    expect(TaxType::where('brand_id', $target->id)->count())->toBe(3)
        ->and(TaxType::where('brand_id', $other->id)->count())->toBe(0);
});
