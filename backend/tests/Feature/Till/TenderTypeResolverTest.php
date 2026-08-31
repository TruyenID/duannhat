<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\TillTenderType;
use App\Services\Till\TenderTypeResolver;
use Illuminate\Support\Str;

/*
 * #1156 — TenderTypeResolver::effectiveForBranch override matrix.
 *
 *   org row, no override            → passes through (when active)
 *   org row + override active=true  → override row wins (no duplicate)
 *   org row + override active=false → hidden for the branch
 *   branch-only custom row          → included when active
 *
 * Plus: org scoping, other-branch isolation, deterministic ordering.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $brand->console_brand_id,
        'slug' => 'resolver-shop',
    ]);
    $this->otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $brand->console_brand_id,
        'slug' => 'resolver-other-shop',
    ]);

    $this->resolver = app(TenderTypeResolver::class);
});

function tender(array $attrs): TillTenderType
{
    return TillTenderType::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'branch_id' => null,
        'category' => 'qr',
        'is_active' => true,
        'sort_order' => 0,
    ], $attrs));
}

it('passes org rows through when no override exists, excluding inactive org rows', function () {
    tender(['tender_key' => 'credit', 'sort_order' => 1]);
    tender(['tender_key' => 'paypay', 'sort_order' => 2]);
    tender(['tender_key' => 'globally_off', 'sort_order' => 3, 'is_active' => false]);

    $keys = $this->resolver->effectiveForBranch($this->orgId, $this->shop->id)
        ->pluck('tender_key')->all();

    expect($keys)->toBe(['credit', 'paypay']);
});

it('branch override with is_active=false hides the tender for that branch only', function () {
    tender(['tender_key' => 'credit', 'sort_order' => 1]);
    tender(['tender_key' => 'paypay', 'sort_order' => 2]);
    tender(['tender_key' => 'paypay', 'branch_id' => $this->shop->id, 'sort_order' => 2, 'is_active' => false]);

    $shopKeys = $this->resolver->effectiveForBranch($this->orgId, $this->shop->id)
        ->pluck('tender_key')->all();
    $otherKeys = $this->resolver->effectiveForBranch($this->orgId, $this->otherShop->id)
        ->pluck('tender_key')->all();

    expect($shopKeys)->toBe(['credit'])
        ->and($otherKeys)->toBe(['credit', 'paypay']);
});

it('branch override with is_active=true re-activates a tender the org keeps off', function () {
    tender(['tender_key' => 'wechat_pay', 'is_active' => false]);
    tender(['tender_key' => 'wechat_pay', 'branch_id' => $this->shop->id, 'is_active' => true]);

    $shopKeys = $this->resolver->effectiveForBranch($this->orgId, $this->shop->id)
        ->pluck('tender_key')->all();
    $otherKeys = $this->resolver->effectiveForBranch($this->orgId, $this->otherShop->id)
        ->pluck('tender_key')->all();

    expect($shopKeys)->toBe(['wechat_pay'])
        ->and($otherKeys)->toBe([]);
});

it('an active override REPLACES its org row — never a duplicate key', function () {
    tender(['tender_key' => 'credit', 'sort_order' => 5]);
    $override = tender(['tender_key' => 'credit', 'branch_id' => $this->shop->id, 'sort_order' => 5]);

    $rows = $this->resolver->effectiveForBranch($this->orgId, $this->shop->id);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($override->id)
        ->and((string) $rows->first()->branch_id)->toBe((string) $this->shop->id);
});

it('includes branch-only custom tenders when active, excludes them when inactive', function () {
    tender(['tender_key' => 'credit', 'sort_order' => 1]);
    tender(['tender_key' => 'shop_voucher', 'branch_id' => $this->shop->id, 'sort_order' => 2]);
    tender(['tender_key' => 'dead_voucher', 'branch_id' => $this->shop->id, 'sort_order' => 3, 'is_active' => false]);

    $keys = $this->resolver->effectiveForBranch($this->orgId, $this->shop->id)
        ->pluck('tender_key')->all();

    expect($keys)->toBe(['credit', 'shop_voucher']);
});

it('never leaks another organization vocabulary', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    tender(['tender_key' => 'credit']);
    TillTenderType::factory()->create([
        'organization_id' => $otherOrgId,
        'branch_id' => null,
        'tender_key' => 'foreign_pay',
        'category' => 'qr',
        'is_active' => true,
    ]);

    $keys = $this->resolver->effectiveForBranch($this->orgId, $this->shop->id)
        ->pluck('tender_key')->all();

    expect($keys)->toBe(['credit']);
});

it('orders deterministically by sort_order then tender_key', function () {
    tender(['tender_key' => 'zeta_pay', 'sort_order' => 1]);
    tender(['tender_key' => 'alpha_pay', 'sort_order' => 1]);
    tender(['tender_key' => 'omega_pay', 'sort_order' => 0]);
    // Override carries its own sort_order — the effective row's order wins.
    tender(['tender_key' => 'omega_pay', 'branch_id' => $this->shop->id, 'sort_order' => 9]);

    $keys = $this->resolver->effectiveForBranch($this->orgId, $this->shop->id)
        ->pluck('tender_key')->all();

    expect($keys)->toBe(['alpha_pay', 'zeta_pay', 'omega_pay']);
});
