<?php

// #1178 — GET /shops/{shopSlug}/denominations response contract.
//
// The admin-web cash-count grids (shift close, manual-settle) filter the rows
// they render on `is_active` and tell a global row from an org-scoped one by
// `organization_id`. Both fields were missing from DenominationResource, so
// every row was dropped client-side and the manual-settle grid rendered empty
// — the manager could not enter a count and settlement always failed with
// 422 closing_counts. Lock the shape here.

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Denomination;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'denom-shop',
        'is_active' => true,
    ]);
    $managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);
});

it('returns is_active and organization_id on every denomination row', function () {
    $global = Denomination::factory()->jpy10000()->create();
    $scoped = Denomination::factory()->jpy1000()->create([
        'organization_id' => $this->orgId,
        'label' => 'Quầy 1',
    ]);

    $rows = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/denominations?currency=JPY")
        ->assertOk()
        ->json('data');

    $byId = collect($rows)->keyBy('id');

    expect($byId)->toHaveKey($global->id);
    expect($byId)->toHaveKey($scoped->id);

    // The client renders on these two — a missing key reads as falsy and the
    // row silently disappears from the grid.
    expect($byId[$global->id])->toHaveKeys(['is_active', 'organization_id']);
    expect($byId[$global->id]['is_active'])->toBeTrue();
    expect($byId[$global->id]['organization_id'])->toBeNull();
    expect($byId[$scoped->id]['organization_id'])->toBe($this->orgId);
});

it('omits inactive denominations from the list', function () {
    $active = Denomination::factory()->jpy10000()->create();
    $inactive = Denomination::factory()->jpy1000()->create(['is_active' => false]);

    $ids = collect(
        $this->actingAs($this->manager)
            ->getJson("/api/v1/shops/{$this->shop->slug}/denominations?currency=JPY")
            ->assertOk()
            ->json('data')
    )->pluck('id');

    expect($ids)->toContain($active->id);
    expect($ids)->not->toContain($inactive->id);
});
