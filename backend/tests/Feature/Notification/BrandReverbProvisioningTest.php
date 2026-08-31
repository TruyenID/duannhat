<?php

/**
 * Per-brand Reverb app provisioning tests (plan-012 T4.3 + T4.4).
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Services\Notification\BrandReverbAppService;
use Database\Seeders\BaselineProvisioningSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
});

describe('`Brand::created` hook', function () {
    it('provisions Reverb creds when a Brand is created', function () {
        $brand = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'slug' => 'rv-'.Str::random(4),
            'is_active' => true,
        ]);

        $brand->refresh();
        expect($brand->reverb_app_id)->not->toBeNull();
        expect($brand->reverb_app_key)->not->toBeNull();
        expect($brand->reverb_app_secret)->not->toBeNull();
        expect($brand->reverb_provisioned_at)->not->toBeNull();
    });

    it('is idempotent — re-running provision on an already-provisioned Brand is a no-op', function () {
        $brand = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'slug' => 'rv-'.Str::random(4),
            'is_active' => true,
        ]);
        $brand->refresh();
        $originalAppId = $brand->reverb_app_id;
        $originalSecret = $brand->reverb_app_secret;

        app(BrandReverbAppService::class)->provision($brand);
        $brand->refresh();

        expect($brand->reverb_app_id)->toBe($originalAppId);
        expect($brand->reverb_app_secret)->toBe($originalSecret);
    });
});

describe('BrandReverbAppService::rotate', function () {
    it('regenerates key+secret while keeping the app_id stable', function () {
        $brand = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'slug' => 'rv-'.Str::random(4),
            'is_active' => true,
        ]);
        $brand->refresh();

        $appId = $brand->reverb_app_id;
        $originalSecret = $brand->reverb_app_secret;
        $originalKey = $brand->reverb_app_key;

        app(BrandReverbAppService::class)->rotate($brand);
        $brand->refresh();

        expect($brand->reverb_app_id)->toBe($appId);
        expect($brand->reverb_app_secret)->not->toBe($originalSecret);
        expect($brand->reverb_app_key)->not->toBe($originalKey);
    });
});

describe('BaselineProvisioningSeeder — Reverb', function () {
    it('populates null rows without touching already-provisioned rows', function () {
        $brandWithCreds = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'slug' => 'rv-'.Str::random(4),
            'is_active' => true,
        ]);
        $brandWithCreds->refresh();
        $existingAppId = $brandWithCreds->reverb_app_id;

        // Manually null out another brand to simulate pre-plan-012 state.
        $stripped = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'slug' => 'rv-'.Str::random(4),
            'is_active' => true,
        ]);
        $stripped->forceFill([
            'reverb_app_id' => null,
            'reverb_app_key' => null,
            'reverb_app_secret' => null,
            'reverb_provisioned_at' => null,
        ])->saveQuietly();

        (new BaselineProvisioningSeeder)->run();

        $brandWithCreds->refresh();
        $stripped->refresh();
        expect($brandWithCreds->reverb_app_id)->toBe($existingAppId);
        expect($stripped->reverb_app_id)->not->toBeNull();
    });
});

it('every Brand row has non-null reverb_app_id after default factory', function () {
    Brand::factory()->count(3)->create([
        'console_organization_id' => $this->orgId,
    ]);

    $nullCount = Brand::query()->whereNull('reverb_app_id')->count();
    expect($nullCount)->toBe(0);
});
