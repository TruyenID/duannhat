<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Denomination;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Till;
use App\Models\TillSession;
use Illuminate\Support\Str;

/*
 * Plan toasty-wiggling-eagle — auth matrix for
 * `PATCH /api/v1/pos/till/sessions/{session}/draft`.
 *
 * The regression this covers: pos-web autosave / manual "Save draft" was
 * booting cashiers to /pairing on a 401 whose root cause turned out to be
 * ambiguous. These tests pin the structured `code` field on every 401/403
 * emitted by AuthenticateSsoOrDevice + ResolvesShopContext, so the client
 * can safely whitelist true auth-fail codes and pass everything else to
 * the caller's error handler.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'draft-shop',
        'is_active' => true,
    ]);

    $till = Till::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);

    $this->session = TillSession::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'till_id' => $till->id,
        'status' => 'open',
    ]);

    $till->update(['current_session_id' => $this->session->id]);

    Denomination::factory()->jpy10000()->create();
});

function draftPayload(): array
{
    return [
        'closing_counts' => [],
        'tender_details' => [],
        'closing_note' => 'auto-save probe',
    ];
}

it('accepts a valid POS device token with matching branch and returns 200', function () {
    $token = Str::random(64);
    Device::factory()->create([
        'type' => 'pos',
        'status' => 'active',
        'device_token' => $token,
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $this->withHeader('X-Shop-Slug', $this->shop->slug)
        ->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/pos/till/sessions/{$this->session->id}/draft", draftPayload())
        ->assertOk();
});

it('returns 401 with TOKEN_REQUIRED when no bearer token is present', function () {
    $this->withHeader('X-Shop-Slug', $this->shop->slug)
        ->patchJson("/api/v1/pos/till/sessions/{$this->session->id}/draft", draftPayload())
        ->assertUnauthorized()
        ->assertJson([
            'message' => 'Token required.',
            'code' => 'TOKEN_REQUIRED',
        ]);
});

it('returns 401 with INVALID_TOKEN for a bearer that matches no device', function () {
    $this->withHeader('X-Shop-Slug', $this->shop->slug)
        ->withHeader('Authorization', 'Bearer '.Str::random(64))
        ->patchJson("/api/v1/pos/till/sessions/{$this->session->id}/draft", draftPayload())
        ->assertUnauthorized()
        ->assertJson([
            'message' => 'Invalid token or unauthenticated.',
            'code' => 'INVALID_TOKEN',
        ]);
});

it('returns 401 with INVALID_TOKEN for a revoked device (falls through to SSO)', function () {
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
        ->patchJson("/api/v1/pos/till/sessions/{$this->session->id}/draft", draftPayload())
        ->assertUnauthorized()
        ->assertJson([
            'message' => 'Invalid token or unauthenticated.',
            'code' => 'INVALID_TOKEN',
        ]);
});

it('returns 403 with BRANCH_MISMATCH when the device is paired to a different branch', function () {
    $otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
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
        ->patchJson("/api/v1/pos/till/sessions/{$this->session->id}/draft", draftPayload())
        ->assertForbidden()
        ->assertJson([
            'message' => 'Device not authorized for this shop.',
            'code' => 'BRANCH_MISMATCH',
        ]);
});

it('returns 403 with DEVICE_TYPE_NOT_ALLOWED for a non-pos device', function () {
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
        ->patchJson("/api/v1/pos/till/sessions/{$this->session->id}/draft", draftPayload())
        ->assertForbidden()
        ->assertJson([
            'message' => 'Device type not allowed for this endpoint.',
            'code' => 'DEVICE_TYPE_NOT_ALLOWED',
        ]);
});
