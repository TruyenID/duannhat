<?php

/**
 * GET /hq/{brand}/readiness (#2344) — checklist baseline, CHỈ ĐỌC.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\ProductType;
use App\Models\TaxType;
use App\Models\User;
use App\Models\Zone;
use App\Services\Provisioning\BranchBaselineProvisioner;
use App\Services\Provisioning\BrandBaselineProvisioner;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'rdy-'.Str::random(4),
        'is_active' => true,
    ]);

    // Brand TRẦN: hook `Brand::created` đã cấp Reverb + combo, gỡ ra để bài
    // test bắt đầu từ đúng trạng thái mà seed / đồng bộ Platform tạo ra.
    $this->brand->forceFill([
        'reverb_app_id' => null,
        'reverb_app_key' => null,
        'reverb_app_secret' => null,
        'reverb_provisioned_at' => null,
    ])->saveQuietly();
    ProductType::query()->where('brand_id', $this->brand->id)->forceDelete();
    $this->brand->refresh();

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);
});

it('báo chưa sẵn sàng và liệt kê đúng mục đang thiếu', function () {
    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/hq/{$this->brand->slug}/readiness")
        ->assertOk();

    expect($response->json('data.ready'))->toBeFalse();

    $missing = collect($response->json('data.checks'))
        ->where('state', 'missing')
        ->pluck('key')
        ->all();

    expect($missing)->toContain('brand.tax_types')
        ->and($missing)->toContain('brand.reverb')
        ->and($missing)->toContain('brand.combo_catalog');
});

it('KHÔNG ghi gì — gọi xong brand vẫn trần', function () {
    $this->actingAs($this->user)
        ->getJson("/api/v1/hq/{$this->brand->slug}/readiness")
        ->assertOk();

    expect(TaxType::where('brand_id', $this->brand->id)->count())->toBe(0)
        ->and($this->brand->fresh()->reverb_app_id)->toBeNull()
        ->and(ProductType::where('brand_id', $this->brand->id)->where('code', 'combo')->exists())->toBeFalse();
});

it('sau khi reconcile thì báo sẵn sàng', function () {
    app(BrandBaselineProvisioner::class)->ensure($this->brand);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/hq/{$this->brand->slug}/readiness")
        ->assertOk();

    expect($response->json('data.ready'))->toBeTrue()
        ->and(collect($response->json('data.checks'))->where('state', 'missing'))->toBeEmpty();
});

it('gộp cả chi nhánh — một shop thiếu cài đặt là cả brand chưa sẵn sàng', function () {
    app(BrandBaselineProvisioner::class)->ensure($this->brand);

    $branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    Zone::factory()->for($branch, 'branch')->create(['organization_id' => $this->orgId]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/hq/{$this->brand->slug}/readiness")
        ->assertOk();

    expect($response->json('data.ready'))->toBeFalse();

    $branchCheck = collect($response->json('data.checks'))
        ->firstWhere('key', 'branch.order_settings');

    expect($branchCheck['state'])->toBe('missing')
        ->and($branchCheck['subject'])->toBe("branch:{$branch->slug}");

    // Và sau khi dựng baseline cho chi nhánh thì sạch.
    app(BranchBaselineProvisioner::class)->ensure($branch);

    expect($this->actingAs($this->user)
        ->getJson("/api/v1/hq/{$this->brand->slug}/readiness")
        ->json('data.ready'))->toBeTrue();
});

it('brand của tổ chức khác thì không xem được', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $outsider = User::factory()->create(['console_organization_id' => $otherOrgId]);
    grantOrgAccess($outsider, $otherOrgId);

    $this->actingAs($outsider)
        ->getJson("/api/v1/hq/{$this->brand->slug}/readiness")
        ->assertForbidden();
});
