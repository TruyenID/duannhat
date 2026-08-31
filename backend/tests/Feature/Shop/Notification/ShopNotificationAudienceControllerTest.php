<?php

/**
 * Plan-023 M6 T6.6 — shop audience admin API contract.
 *
 * Scenarios:
 *   M6-1: create writes branch_id = current shop (request body ignored)
 *   M6-2: list returns only branch-scoped rows
 *   M6-3: cross-shop access returns 404
 *   M6-4: brand-level audience (branch_id=null) NOT listed under shop
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\NotificationAudience;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'shop-aud-brand-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'shop-aud-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/shops/{$this->shop->slug}/notifications/audiences";
});

it('M6-1: store pins branch_id to current shop regardless of body', function () {
    $response = $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Shop-A audience',
        'description' => 'Pinned to shop A',
        'rule' => ['combinator' => 'or', 'rules' => [['type' => 'role', 'role' => 'shop-manager']]],
        // attempt at cross-tenant pollution:
        'branch_id' => (string) Str::uuid(),
        'brand_id' => (string) Str::uuid(),
        'is_system' => true,
    ]);

    $response->assertCreated();
    $row = NotificationAudience::query()->where('name', 'Shop-A audience')->first();
    expect($row->branch_id)->toBe($this->shop->id);
    expect($row->is_system)->toBeFalse();
});

it('M6-2: list returns only rows scoped to this shop', function () {
    // shop-scoped row
    DB::table('notification_audiences')->insert([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'name' => 'Mine',
        'rule' => json_encode(['combinator' => 'or', 'rules' => []]),
        'is_system' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    // brand-level row (no branch_id) — should NOT show on shop list
    DB::table('notification_audiences')->insert([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'name' => 'Brand-wide',
        'rule' => json_encode(['combinator' => 'or', 'rules' => []]),
        'is_system' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Mine');
});

it('M6-3: cross-shop access returns 404', function () {
    $otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'other-'.Str::random(4),
        'is_active' => true,
    ]);
    $stranger = NotificationAudience::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $otherShop->id,
        'name' => 'Stranger',
        'rule' => ['combinator' => 'or', 'rules' => []],
        'is_system' => false,
    ]);

    $this->actingAs($this->user)
        ->getJson("{$this->base}/{$stranger->id}")
        ->assertNotFound();
});

it('M6-4: brand-wide audience does not appear on shop list', function () {
    NotificationAudience::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'name' => 'Brand wide',
        'rule' => ['combinator' => 'or', 'rules' => []],
        'is_system' => false,
    ]);

    $this->actingAs($this->user)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
