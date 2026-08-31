<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\ProductType;
use App\Services\Product\BrandCoreCatalogService;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    // Pest.php auto-creates org with id 00000000-0000-0000-0000-000000000001
    // matching console_organization_id; reuse it for these tests.
    $this->orgConsoleId = '00000000-0000-0000-0000-000000000001';
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->service = app(BrandCoreCatalogService::class);
});

it('creates the combo ProductType with translations on first run', function () {
    $brand = Brand::factory()->create([
        'console_organization_id' => $this->orgConsoleId,
    ]);

    $this->service->ensureCombo($brand);

    $type = ProductType::where('brand_id', $brand->id)->where('code', 'combo')->first();

    expect($type)->not->toBeNull();
    expect($type->organization_id)->toBe($this->orgId);
    expect($type->product_form)->toBe('physical');
    expect($type->has_recipe)->toBeFalse();
    expect($type->is_inventory_tracked)->toBeFalse();
    expect($type->is_active)->toBeTrue();
    expect($type->translate('ja')->name)->toBe('コンボ');
    expect($type->translate('en')->name)->toBe('Combo');
    expect($type->translate('vi')->name)->toBe('Combo');
});

it('is idempotent — re-running does not duplicate the ProductType', function () {
    $brand = Brand::factory()->create([
        'console_organization_id' => $this->orgConsoleId,
    ]);

    $this->service->ensureCombo($brand);
    $this->service->ensureCombo($brand);
    $this->service->ensureCombo($brand);

    expect(ProductType::where('brand_id', $brand->id)->where('code', 'combo')->count())->toBe(1);
});

it('logs a warning and skips when the Organization is not synced yet', function () {
    // Brand::create() fires `Brand::created` hook → ensureCombo, which logs the same
    // warning. Spy AFTER brand creation so we only capture the explicit call.
    $brand = Brand::factory()->create([
        // Unknown console_organization_id — Organization not synced yet.
        'console_organization_id' => '99999999-9999-9999-9999-999999999999',
    ]);

    Log::spy();

    $this->service->ensureCombo($brand);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg) => str_contains($msg, 'organization not synced yet'))
        ->once();

    expect(ProductType::where('brand_id', $brand->id)->count())->toBe(0);
});

it('fires automatically via `Brand::created` hook when a Brand is created', function () {
    Organization::factory()->create([
        'id' => '00000000-0000-0000-0000-000000000002',
        'console_organization_id' => '00000000-0000-0000-0000-000000000002',
    ]);

    $brand = Brand::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000002',
    ]);

    expect(ProductType::where('brand_id', $brand->id)->where('code', 'combo')->exists())->toBeTrue();
});
