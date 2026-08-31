<?php

use App\Models\Branch;
use App\Models\Device;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * Contract tests for AuthenticateSsoOrDevice — the compound auth
 * middleware that lets /api/v1/pos/* accept either a Platform SSO
 * user token OR a POS device token.
 *
 * These pin the four rejection paths (no token / bad token / inactive
 * device / non-POS device type) and the two success paths (POS device
 * token / SSO user). Success paths also assert PosMeController's
 * response shape flips between the `user` and `device` blocks —
 * that's how pos-web knows which principal type it just authenticated
 * as.
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
});

it('returns 401 when no bearer token is present', function () {
    $this->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/me')
        ->assertUnauthorized()
        ->assertJson([
            'message' => 'Token required.',
            'code' => 'TOKEN_REQUIRED',
        ]);
});

it('returns 401 when the bearer token matches no device and no SSO session', function () {
    $this->withHeader('X-Shop-Slug', $this->shop->slug)
        ->withHeader('Authorization', 'Bearer '.Str::random(64))
        ->getJson('/api/v1/pos/me')
        ->assertUnauthorized()
        ->assertJson([
            'message' => 'Invalid token or unauthenticated.',
            'code' => 'INVALID_TOKEN',
        ]);
});

it('never writes bearer token material to the rejection log', function () {
    $token = 'secret-device-token-'.Str::random(48);

    Log::shouldReceive('channel')->once()->with('pos_auth')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with(
        'pos_auth_reject',
        Mockery::on(fn (array $context): bool => $context['reason'] === 'invalid_token'
            && $context['token_present'] === true
            && ! array_key_exists('token', $context)
            && ! array_key_exists('token_head', $context)
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $token)),
    );

    $this->withHeader('X-Shop-Slug', $this->shop->slug)
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/pos/me')
        ->assertUnauthorized();
});

it('returns 401 when the device is not active (falls through to SSO check)', function () {
    $token = Str::random(64);
    Device::factory()->create([
        'type' => 'pos',
        'status' => 'revoked',
        'device_token' => $token,
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $this->withHeader('X-Shop-Slug', $this->shop->slug)
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/pos/me')
        ->assertUnauthorized()
        ->assertJson([
            'message' => 'Invalid token or unauthenticated.',
            'code' => 'INVALID_TOKEN',
        ]);
});

it('returns 403 when the device type is not pos', function () {
    $token = Str::random(64);
    Device::factory()->create([
        'type' => 'kds',
        'status' => 'active',
        'device_token' => $token,
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $this->withHeader('X-Shop-Slug', $this->shop->slug)
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/pos/me')
        ->assertForbidden()
        ->assertJson([
            'message' => 'Device type not allowed for this endpoint.',
            'code' => 'DEVICE_TYPE_NOT_ALLOWED',
        ]);
});

it('returns 403 with BRANCH_MISMATCH code when device belongs to a different branch', function () {
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
        ->assertJson([
            'message' => 'Device not authorized for this shop.',
            'code' => 'BRANCH_MISMATCH',
        ]);
});

it('returns 200 with a valid POS device token and populates the device block', function () {
    $token = Str::random(64);
    $device = Device::factory()->create([
        'type' => 'pos',
        'status' => 'active',
        'device_token' => $token,
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'name' => 'POS-Terminal-1',
    ]);

    $response = $this->withHeader('X-Shop-Slug', $this->shop->slug)
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/pos/me')
        ->assertOk();

    expect($response->json('user'))->toBeNull();
    expect($response->json('device.id'))->toBe($device->id);
    expect($response->json('device.name'))->toBe('POS-Terminal-1');
    expect($response->json('device.type'))->toBe('pos');
    expect($response->json('device.branch_id'))->toBe($this->shop->id);
    expect($response->json('shop.id'))->toBe($this->shop->id);
});

it('returns 200 with a valid SSO session and populates the user block (backward compat)', function () {
    config(['sso.dev_bypass.enabled' => true]);
    $user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($user, $this->orgId);

    $response = $this->actingAs($user)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/me')
        ->assertOk();

    expect($response->json('device'))->toBeNull();
    expect($response->json('user.id'))->toBe($user->id);
    expect($response->json('user.name'))->toBe($user->name);
});

it('preserves a downstream 401 response after valid SSO authentication', function () {
    Route::middleware('auth.sso_or_device')->get('/api/v1/test/downstream-401', fn () => response()->json([
        'message' => 'Business authorization failed.',
        'code' => 'BUSINESS_RULE_DENIED',
    ], 401));

    $user = User::factory()->create(['console_organization_id' => $this->orgId]);

    $this->actingAs($user)
        ->getJson('/api/v1/test/downstream-401')
        ->assertUnauthorized()
        ->assertExactJson([
            'message' => 'Business authorization failed.',
            'code' => 'BUSINESS_RULE_DENIED',
        ]);
});

it('bumps the device last_seen_at timestamp on successful device auth', function () {
    $token = Str::random(64);
    $device = Device::factory()->create([
        'type' => 'pos',
        'status' => 'active',
        'device_token' => $token,
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'last_seen_at' => now()->subDay(),
    ]);

    $this->withHeader('X-Shop-Slug', $this->shop->slug)
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/pos/me')
        ->assertOk();

    // Heartbeat runs fire-and-forget after auth passes.
    expect($device->fresh()->last_seen_at->diffInSeconds(now()))->toBeLessThan(5);
});
