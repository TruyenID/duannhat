<?php

/**
 * POST /hq/{brand}/settings/reverb/rotate tests (plan-012 T4.7).
 */

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
        'slug' => 'rot-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->brand->refresh();
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
});

it('rotates the reverb_app_secret and reverb_app_key while keeping app_id stable', function () {
    $appId = $this->brand->reverb_app_id;
    $originalSecret = $this->brand->reverb_app_secret;
    $originalKey = $this->brand->reverb_app_key;

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/hq/{$this->brand->slug}/settings/reverb/rotate")
        ->assertOk();

    $this->brand->refresh();
    expect($this->brand->reverb_app_id)->toBe($appId);
    expect($this->brand->reverb_app_secret)->not->toBe($originalSecret);
    expect($this->brand->reverb_app_key)->not->toBe($originalKey);
    expect($response->json('data.app_id'))->toBe($appId);
});

it('forbids users outside the brand org from rotating', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $outsider = User::factory()->create(['console_organization_id' => $otherOrgId]);

    $this->actingAs($outsider)
        ->postJson("/api/v1/hq/{$this->brand->slug}/settings/reverb/rotate")
        ->assertForbidden();
});

// =============================================================================
//  TC-SET-REV01 — show connection config (app_key + host/port; secret omitted)
// =============================================================================

it('shows the reverb connection config without exposing app_secret', function () {
    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/hq/{$this->brand->slug}/settings/reverb")
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['app_id', 'app_key', 'allowed_origins', 'host', 'port', 'scheme', 'cluster'],
        ]);

    expect($response->json('data.app_key'))->toBe($this->brand->reverb_app_key);
    // app_secret must never be in the config response (TC-SET-REV04).
    expect($response->json('data'))->not->toHaveKey('app_secret');
});

it('forbids users outside the brand org from viewing config', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $outsider = User::factory()->create(['console_organization_id' => $otherOrgId]);

    $this->actingAs($outsider)
        ->getJson("/api/v1/hq/{$this->brand->slug}/settings/reverb")
        ->assertForbidden();
});

// =============================================================================
//  TC-SET-REV02 — test connection
// =============================================================================

it('returns a structured connection-test result', function () {
    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/hq/{$this->brand->slug}/settings/reverb/test")
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['ok', 'host', 'port', 'message'],
        ]);

    expect($response->json('data.ok'))->toBeBool();
});

it('forbids users outside the brand org from testing the connection', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $outsider = User::factory()->create(['console_organization_id' => $otherOrgId]);

    $this->actingAs($outsider)
        ->postJson("/api/v1/hq/{$this->brand->slug}/settings/reverb/test")
        ->assertForbidden();
});
