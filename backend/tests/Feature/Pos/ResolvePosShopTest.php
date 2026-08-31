<?php

use App\Models\Branch;
use App\Models\Device;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * Contract tests for /api/v1/pos/* middleware (ResolvePosShop).
 *
 * The POS namespace mirrors /api/v1/shops/{slug}/* but resolves shop context
 * from the X-Shop-Slug HEADER instead of a URL segment. These tests pin the
 * three rejection paths (missing header, unknown slug, IAM denial) and a
 * happy-path parity check that proves /pos/me returns the same shop record
 * the slug-based middleware would have bound.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'main-shop',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);
});

it('returns 400 when the X-Shop-Slug header is missing', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/pos/me')
        ->assertStatus(400);
});

it('returns 404 when the X-Shop-Slug header references an unknown shop', function () {
    $this->actingAs($this->user)
        ->withHeader('X-Shop-Slug', 'does-not-exist')
        ->getJson('/api/v1/pos/me')
        ->assertNotFound();
});

it('returns 403 when the authenticated user has no role in the shop org', function () {
    $stranger = User::factory()->create(['console_organization_id' => (string) Str::uuid()]);

    $this->actingAs($stranger)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/me')
        ->assertForbidden();
});

it('binds the shop and returns it from /pos/me on the happy path', function () {
    $response = $this->actingAs($this->user)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/me')
        ->assertOk();

    expect($response->json('user.id'))->toBe($this->user->id);
    expect($response->json('shop.id'))->toBe($this->shop->id);
    expect($response->json('shop.slug'))->toBe($this->shop->slug);
});

it('returns 401 when no SSO token is present', function () {
    $this->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/me')
        ->assertUnauthorized();
});

it('returns 403 when the device belongs to a different branch than the X-Shop-Slug header', function () {
    $otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'other-shop',
        'is_active' => true,
    ]);

    $token = Str::random(64);
    Device::factory()->create([
        'type' => 'pos',
        'status' => 'active',
        'device_token' => $token,
        'organization_id' => $this->orgId,
        'branch_id' => $otherShop->id,
    ]);

    $this->withHeader('X-Shop-Slug', $this->shop->slug)
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/pos/me')
        ->assertForbidden()
        ->assertJson(['message' => 'Device not authorized for this shop.']);
});

it('returns 200 when the device belongs to the branch on the X-Shop-Slug header', function () {
    $token = Str::random(64);
    $device = Device::factory()->create([
        'type' => 'pos',
        'status' => 'active',
        'device_token' => $token,
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $response = $this->withHeader('X-Shop-Slug', $this->shop->slug)
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/pos/me')
        ->assertOk();

    expect($response->json('device.id'))->toBe($device->id);
    expect($response->json('shop.id'))->toBe($this->shop->id);
});
