<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\TaxType;
use App\Services\Tax\TaxTypeService;
use Database\Seeders\BaselineProvisioningSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Audit fix 1.2 (2026-07-14) — a brand minted post-plan-043 must be
 * provisioned with the three standard tax types. The production brand
 * Platform provisioning entrypoint calls
 * TaxTypeService::ensureStandardTypesForBrand on first sight; before this fix
 * a fresh brand had ZERO tax types, so TaxResolver returned 0% for every line
 * silently. (Deliberately NOT wired into `Brand::created` hook — test factories seed
 * their own TaxType fixtures and would clash on unique [brand_id, code].)
 */
function seedingBrand(): Brand
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);

    return Brand::factory()->create(['console_organization_id' => $orgId]);
}

it('provisions the three standard tax types for a fresh brand', function () {
    $brand = seedingBrand();
    expect(TaxType::query()->where('brand_id', $brand->id)->count())->toBe(0);

    $created = app(TaxTypeService::class)->ensureStandardTypesForBrand($brand);

    $types = TaxType::query()->where('brand_id', $brand->id)->get();
    expect($created)->toBe(3)
        ->and($types)->toHaveCount(3)
        ->and($types->pluck('code')->sort()->values()->all())->toBe(['EXEMPT', 'REDUCED', 'STANDARD'])
        ->and($types->where('is_default', true))->toHaveCount(1)
        ->and($types->firstWhere('code', 'STANDARD')->is_default)->toBeTrue();

    $standard = $types->firstWhere('code', 'STANDARD');
    $reduced = $types->firstWhere('code', 'REDUCED');
    // Japanese legal standard: eat-in 10 / takeaway 8 on the reduced type.
    expect((float) $standard->rate)->toBe(10.0)
        ->and((float) $reduced->rate)->toBe(8.0);
});

it('is idempotent — re-running never duplicates nor steals an admin default', function () {
    $brand = seedingBrand();
    $service = app(TaxTypeService::class);
    $service->ensureStandardTypesForBrand($brand);

    // Admin re-points the default to REDUCED after provisioning.
    $reduced = TaxType::query()->where('brand_id', $brand->id)->where('code', 'REDUCED')->firstOrFail();
    $service->update($reduced, ['is_default' => true]);

    // Re-fire (re-sync / manual reseed).
    expect($service->ensureStandardTypesForBrand($brand))->toBe(0);

    $types = TaxType::query()->where('brand_id', $brand->id)->get();
    expect($types)->toHaveCount(3)
        ->and($types->where('is_default', true))->toHaveCount(1)
        ->and($types->firstWhere('is_default', true)->code)->toBe('REDUCED');
});

it('backfills only the missing codes for a partially-seeded brand', function () {
    $brand = seedingBrand();
    $service = app(TaxTypeService::class);
    $service->ensureStandardTypesForBrand($brand);

    // Simulate a pre-fix brand that only had the STANDARD type.
    TaxType::query()->where('brand_id', $brand->id)->where('code', '!=', 'STANDARD')->forceDelete();
    expect(TaxType::query()->where('brand_id', $brand->id)->count())->toBe(1);

    $created = $service->ensureStandardTypesForBrand($brand);

    expect($created)->toBe(2)
        ->and(TaxType::query()->where('brand_id', $brand->id)->count())->toBe(3)
        // STANDARD already carried the default — untouched, not duplicated.
        ->and(TaxType::query()->where('brand_id', $brand->id)->where('is_default', true)->count())->toBe(1);
});

it('the BaselineProvisioningSeeder sweep provisions existing brands through the same method', function () {
    $brand = seedingBrand();
    expect(TaxType::query()->where('brand_id', $brand->id)->count())->toBe(0);

    (new BaselineProvisioningSeeder)->run();

    expect(TaxType::query()->where('brand_id', $brand->id)->count())->toBe(3);
});

it('B6 — promotes a default when the brand would otherwise end the sweep with none', function () {
    $brand = seedingBrand();
    $service = app(TaxTypeService::class);

    // Pre-fix state: STANDARD exists WITHOUT the default flag, others missing.
    TaxType::factory()->create([
        'organization_id' => Organization::where('console_organization_id', $brand->console_organization_id)->value('id'),
        'brand_id' => $brand->id, 'code' => 'STANDARD',
        'rate' => 10,
        'is_default' => false, 'is_active' => true,
    ]);

    $service->ensureStandardTypesForBrand($brand);

    $types = TaxType::query()->where('brand_id', $brand->id)->get();
    expect($types)->toHaveCount(3)
        ->and($types->where('is_default', true))->toHaveCount(1)
        // STANDARD is preferred for the promotion.
        ->and($types->firstWhere('is_default', true)->code)->toBe('STANDARD');
});

it('B3 — bulkDelete reports a trashed id per-row instead of aborting the batch', function () {
    $brand = seedingBrand();
    $service = app(TaxTypeService::class);
    $service->ensureStandardTypesForBrand($brand);

    $exempt = TaxType::query()->where('brand_id', $brand->id)->where('code', 'EXEMPT')->firstOrFail();
    $reduced = TaxType::query()->where('brand_id', $brand->id)->where('code', 'REDUCED')->firstOrFail();
    // Trash EXEMPT out-of-band (simulates the concurrent-delete race).
    $exempt->delete();

    $result = $service->bulkDelete([$exempt->id, $reduced->id]);

    // The trashed id surfaces as an error row; REDUCED (unused) still deletes.
    expect($result['deleted'])->toBe(1)
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0]['id'])->toBe($exempt->id);

    // And the locked re-fetch path itself: delete() on a stale instance whose
    // row is already trashed throws ModelNotFoundException (caught per-row by
    // bulkDelete) rather than double-deleting.
    $stale = TaxType::query()->where('brand_id', $brand->id)->where('code', 'STANDARD')->firstOrFail();
    TaxType::query()->whereKey($stale->id)->delete();
    expect(fn () => $service->delete($stale))
        ->toThrow(ModelNotFoundException::class);
});
