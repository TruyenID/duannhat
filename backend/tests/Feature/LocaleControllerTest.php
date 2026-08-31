<?php

/**
 * #130 P2 — LocaleController coverage
 *
 * Endpoints under test:
 *   POST /api/v1/locale     — update user locale (ja/en/vi)
 *   POST /api/v1/timezone   — update user timezone (any IANA TZ)
 */

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
        'locale' => 'ja',
        'timezone' => 'Asia/Tokyo',
    ]);
    grantOrgAccess($this->user, $this->orgId);
});

// =============================================================================
// /locale
// =============================================================================

it('updates locale to a supported value and persists on the user row', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/locale', ['locale' => 'vi'])
        ->assertOk()
        ->assertJson(['locale' => 'vi']);

    expect($this->user->fresh()->locale)->toBe('vi');
});

it('sets the app_locale cookie on locale update', function () {
    // Cookie is in the EncryptCookies "except" list (frontend reads it raw),
    // so assertPlainCookie checks the unencrypted value.
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/locale', ['locale' => 'en'])
        ->assertOk()
        ->assertPlainCookie('app_locale', 'en');

    $cookie = collect($response->baseResponse->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'app_locale');

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeFalse();
});

it('rejects unsupported locale values', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/locale', ['locale' => 'fr'])
        ->assertUnprocessable()->assertJsonValidationErrors(['locale']);
});

it('rejects missing locale field', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/locale', [])
        ->assertUnprocessable()->assertJsonValidationErrors(['locale']);
});

it('returns 401 without auth (route is sso.auth-protected)', function () {
    $this->postJson('/api/v1/locale', ['locale' => 'vi'])->assertUnauthorized();
});

// =============================================================================
// /timezone
// =============================================================================

it('updates timezone to a valid IANA value and persists on user', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/timezone', ['timezone' => 'Asia/Ho_Chi_Minh'])
        ->assertOk();

    expect($this->user->fresh()->timezone)->toBe('Asia/Ho_Chi_Minh');
});

it('rejects an invalid timezone string', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/timezone', ['timezone' => 'Mars/Olympus_Mons'])
        ->assertUnprocessable()->assertJsonValidationErrors(['timezone']);
});

it('rejects missing timezone field', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/timezone', [])
        ->assertUnprocessable()->assertJsonValidationErrors(['timezone']);
});

it('returns 401 without auth on timezone update', function () {
    $this->postJson('/api/v1/timezone', ['timezone' => 'UTC'])->assertUnauthorized();
});
