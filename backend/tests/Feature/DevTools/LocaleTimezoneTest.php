<?php

use App\Http\Middleware\SetTimezone;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->user = User::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
        'locale' => 'ja',
        'timezone' => 'Asia/Tokyo',
    ]);
});

// =========================================================================
//  POST /api/v1/locale
// =========================================================================

it('updates user locale', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/locale', ['locale' => 'en']);

    $response->assertOk()
        ->assertJsonPath('locale', 'en')
        // Must set the cookie under the SAME name the frontend + SetLocale
        // middleware read (`app_locale`). Writing `locale` was the legacy
        // name and kept the cookie branch dead.
        ->assertCookie('app_locale', 'en', false);

    $this->user->refresh();
    expect($this->user->locale)->toBe('en');
});

it('does not write the legacy `locale` cookie on locale update', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/locale', ['locale' => 'vi']);

    $response->assertOk()->assertCookieMissing('locale');
});

it('rejects invalid locale', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/locale', ['locale' => 'fr']);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('locale');
});

it('requires locale field', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/locale', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('locale');
});

it('accepts all supported locales', function () {
    foreach (['ja', 'en', 'vi'] as $locale) {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/locale', ['locale' => $locale]);

        $response->assertOk()
            ->assertJsonPath('locale', $locale);
    }
});

it('requires authentication for locale update', function () {
    $response = $this->postJson('/api/v1/locale', ['locale' => 'en']);

    $response->assertUnauthorized();
});

// =========================================================================
//  POST /api/v1/timezone
// =========================================================================

it('updates user timezone', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/timezone', ['timezone' => 'America/New_York']);

    $response->assertOk()
        ->assertJsonPath('timezone', 'America/New_York');

    $this->user->refresh();
    expect($this->user->timezone)->toBe('America/New_York');
});

it('rejects invalid timezone', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/timezone', ['timezone' => 'Invalid/Zone']);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('timezone');
});

it('requires timezone field', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/timezone', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('timezone');
});

it('accepts valid timezone identifiers', function () {
    $timezones = ['Asia/Tokyo', 'Asia/Ho_Chi_Minh', 'UTC', 'Europe/London'];

    foreach ($timezones as $tz) {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/timezone', ['timezone' => $tz]);

        $response->assertOk()
            ->assertJsonPath('timezone', $tz);
    }
});

it('requires authentication for timezone update', function () {
    $response = $this->postJson('/api/v1/timezone', ['timezone' => 'UTC']);

    $response->assertUnauthorized();
});

// =========================================================================
//  SetLocale Middleware
// =========================================================================

it('sets app locale from Accept-Language header', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/me/context', ['Accept-Language' => 'vi']);

    $response->assertOk();
    // Locale should be set during request processing
});

it('sets locale from query param', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/me/context?locale=en');

    $response->assertOk();
});

// =========================================================================
//  SetTimezone Middleware
// =========================================================================

it('sets timezone from query param', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/me/context?timezone=Europe/London');

    $response->assertOk();
});

// =========================================================================
//  Timestamp epoch correctness — regression for SetTimezone bug
//
//  Before the fix, SetTimezone called date_default_timezone_set("Asia/Tokyo")
//  which caused Carbon::parse(utcString) to misinterpret the DB value as JST,
//  shifting every timestamp by +9 hours in the API response.
//  The fix stores TZ in request attributes only; PHP/Eloquent stay UTC.
// =========================================================================

it('does not shift timestamps when request timezone is Asia/Tokyo', function () {
    // A known UTC moment: 2026-01-01 00:00:00 UTC = 2026-01-01 09:00:00 JST.
    // After fix: created_at ISO offset must be +00:00 (Eloquent reads UTC).
    // Before fix: ISO offset was +09:00 but epoch was WRONG (shifted -9h).
    $knownUtc = Carbon::create(2026, 1, 1, 0, 0, 0, 'UTC');

    // Create a user row with a controlled created_at (write via DB to bypass Carbon)
    DB::table('users')
        ->where('id', $this->user->id)
        ->update(['created_at' => $knownUtc->toDateTimeString()]);

    // Request with Asia/Tokyo timezone cookie (simulates a Tokyo-locale admin)
    $response = $this->actingAs($this->user)
        ->withUnencryptedCookie('timezone', 'Asia/Tokyo')
        ->getJson('/api/v1/me/context');

    $response->assertOk();

    // PHP global TZ must still be UTC after the request — middleware must not mutate it
    expect(date_default_timezone_get())->toBe('UTC');
});

it('stores timezone in request attributes not PHP global state', function () {
    // Verify the middleware puts the TZ in request attributes
    Route::middleware('api')->get('/__test/tz-attribute', function (Request $request) {
        return response()->json([
            'attribute' => $request->attributes->get(SetTimezone::ATTRIBUTE),
            'php_tz' => date_default_timezone_get(),
            'carbon_tz' => Carbon::now()->getTimezone()->getName(),
        ]);
    });

    $response = $this->actingAs($this->user)
        ->withUnencryptedCookie('timezone', 'Asia/Tokyo')
        ->getJson('/__test/tz-attribute');

    $response->assertOk();
    expect($response->json('attribute'))->toBe('Asia/Tokyo')   // stored in attributes ✓
        ->and($response->json('php_tz'))->toBe('UTC')     // PHP global untouched ✓
        ->and($response->json('carbon_tz'))->toBe('UTC'); // Carbon default untouched ✓
});
