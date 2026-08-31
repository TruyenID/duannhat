<?php

/**
 * #130 P2 — Shop/ShopInfoController coverage
 *
 * Endpoint: GET /api/v1/shops/{shopSlug}/info — shop metadata for the
 * shop-admin dashboard header (name, address, hours, etc.). Auth via SSO.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'si-shop-'.Str::random(4),
        'is_active' => true,
        'name' => 'Test Shop',
        'address' => '123 Lê Lợi',
        'phone' => '028-555-1234',
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
});

it('returns shop metadata via /info', function () {
    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/shops/{$this->shop->slug}");

    $response->assertOk()
        ->assertJsonPath('data.id', $this->shop->id)
        ->assertJsonPath('data.name', 'Test Shop')
        ->assertJsonPath('data.address', '123 Lê Lợi')
        ->assertJsonPath('data.phone', '028-555-1234');
});

it('response shape includes the expected keys', function () {
    $this->actingAs($this->user)
        ->getJson("/api/v1/shops/{$this->shop->slug}")
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'id', 'slug', 'name', 'code', 'is_headquarters',
            'console_brand_id', 'address', 'phone', 'seat_capacity',
            'business_hours', 'weekly_hours',
        ]]);
});

it('returns 404 for non-existent shop slug', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/shops/does-not-exist')
        ->assertNotFound();
});

it('returns 404 for inactive shop slug', function () {
    Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => false,
        'slug' => 'closed-info-shop',
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/shops/closed-info-shop')
        ->assertNotFound();
});

it('returns 401 without auth', function () {
    $this->getJson("/api/v1/shops/{$this->shop->slug}")->assertUnauthorized();
});
