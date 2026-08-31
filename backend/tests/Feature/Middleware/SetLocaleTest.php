<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * SetLocale middleware — priority + cookie regression tests.
 *
 * Bugs this suite locks:
 *   1) Middleware previously read cookie `locale` while admin-web writes
 *      `app_locale`, so the cookie branch was dead.
 *   2) `$request->user()` returns null inside an api-group middleware
 *      (default guard is `web`), so user.locale was never read for
 *      bearer-token API clients. Must use Auth::guard('sanctum')->user().
 *   3) Accept-Language (apiFetch-stamped) must beat user.locale so the
 *      UI's current locale switcher wins over a stale DB preference.
 */
beforeEach(function () {
    Route::middleware('api')->get('/__test/locale', fn () => response()->json([
        'locale' => app()->getLocale(),
    ]));

    $this->user = User::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
        'locale' => 'ja',
        'timezone' => 'Asia/Tokyo',
    ]);
});

// =========================================================================
//  Priority 1 — query param wins over everything
// =========================================================================

it('uses the ?locale= query param over every other source', function () {
    $this->withCredentials()
        ->withUnencryptedCookie('app_locale', 'en')
        ->getJson('/__test/locale?locale=vi', ['Accept-Language' => 'ja'])
        ->assertOk()
        ->assertJsonPath('locale', 'vi');
});

it('ignores an unsupported ?locale= and falls through to the next source', function () {
    // Accept-Language is the next supported source; the stale cookie loses.
    $this->withCredentials()
        ->withUnencryptedCookie('app_locale', 'en')
        ->getJson('/__test/locale?locale=fr', ['Accept-Language' => 'vi'])
        ->assertOk()
        ->assertJsonPath('locale', 'vi');
});

// =========================================================================
//  Priority 2/3 — Accept-Language current signal, then cookie fallback
// =========================================================================

it('reads locale from the app_locale cookie', function () {
    $this->withCredentials()->withUnencryptedCookie('app_locale', 'vi')
        ->getJson('/__test/locale', ['Accept-Language' => 'fr-FR'])
        ->assertOk()
        ->assertJsonPath('locale', 'vi');
});

it('does NOT read the legacy `locale` cookie (regression lock)', function () {
    // The legacy name was a dead branch — admin-web only ever writes
    // `app_locale`. Guarantee we never silently re-add the old name.
    $this->withCredentials()->withUnencryptedCookie('locale', 'vi')
        ->getJson('/__test/locale', ['Accept-Language' => 'en'])
        ->assertOk()
        ->assertJsonPath('locale', 'en'); // Accept-Language wins, cookie ignored
});

it('prefers Accept-Language over a stale app_locale cookie', function () {
    $this->withCredentials()->withUnencryptedCookie('app_locale', 'ja')
        ->getJson('/__test/locale', ['Accept-Language' => 'vi'])
        ->assertOk()
        ->assertHeader('Content-Language', 'vi')
        ->assertJsonPath('locale', 'vi');
});

it('ignores an unsupported app_locale cookie value', function () {
    $this->withCredentials()->withUnencryptedCookie('app_locale', 'de')
        ->getJson('/__test/locale', ['Accept-Language' => 'vi'])
        ->assertOk()
        ->assertJsonPath('locale', 'vi');
});

it('uses Accept-Language when no query/cookie is set', function () {
    $this->getJson('/__test/locale', ['Accept-Language' => 'vi'])
        ->assertOk()
        ->assertJsonPath('locale', 'vi');
});

it('prefers Accept-Language over authenticated user.locale', function () {
    // BUG #3 regression: the UI locale switcher must win over the stale
    // users.locale column. apiFetch stamps Accept-Language from the current
    // AppProvider locale on every call.
    $this->user->update(['locale' => 'ja']);

    $this->actingAs($this->user)
        ->getJson('/__test/locale', ['Accept-Language' => 'vi'])
        ->assertOk()
        ->assertJsonPath('locale', 'vi');
});

it('parses Accept-Language that carries a region code (vi-VN)', function () {
    $this->getJson('/__test/locale', ['Accept-Language' => 'vi-VN,vi;q=0.9'])
        ->assertOk()
        ->assertJsonPath('locale', 'vi');
});

it('falls through when Accept-Language lists only unsupported locales', function () {
    // No auth, Accept-Language has no supported primary → config default.
    $this->getJson('/__test/locale', ['Accept-Language' => 'fr-FR,de;q=0.8'])
        ->assertOk()
        ->assertJsonPath('locale', config('app.locale', 'en'));
});

it('picks the highest q-value when primary is not supported', function () {
    // RFC 7231: `fr` unsupported, then `vi;q=0.8` beats `en;q=0.5`.
    // Regression for the old str_starts_with parser that only read the
    // first tag and would have missed `vi` entirely.
    $this->getJson('/__test/locale', ['Accept-Language' => 'fr-FR,vi;q=0.8,en;q=0.5'])
        ->assertOk()
        ->assertJsonPath('locale', 'vi');
});

it('honors explicit q-value ordering over list position', function () {
    // `en` is listed first but has lower q than `vi`.
    $this->getJson('/__test/locale', ['Accept-Language' => 'en;q=0.5,vi;q=0.9'])
        ->assertOk()
        ->assertJsonPath('locale', 'vi');
});

it('parses Accept-Language case-insensitively', function () {
    $this->getJson('/__test/locale', ['Accept-Language' => 'VI-VN'])
        ->assertOk()
        ->assertJsonPath('locale', 'vi');
});

// =========================================================================
//  Priority 4 — authenticated user.locale via the sanctum guard
// =========================================================================

it('reads user.locale for a sanctum-authenticated bearer-token client', function () {
    // BUG #2 regression: SetLocale runs in the api middleware group,
    // whose default guard is `web`. $request->user() returns null. Must
    // explicitly ask the sanctum guard.
    $user = User::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
        'locale' => 'vi',
    ]);

    $token = $user->createToken('test')->plainTextToken;

    // Accept-Language is stripped — Symfony injects `en-us,en;q=0.5` by
    // default, which would hit priority 3 and mask priority 4.
    $this->getJson('/__test/locale', [
        'Authorization' => "Bearer {$token}",
        'Accept-Language' => 'fr-FR', // unsupported → falls through
    ])
        ->assertOk()
        ->assertJsonPath('locale', 'vi');
});

it('ignores a user.locale that is not in the supported list', function () {
    $this->user->update(['locale' => 'de']);
    $token = $this->user->createToken('test')->plainTextToken;

    $this->getJson('/__test/locale', [
        'Authorization' => "Bearer {$token}",
        'Accept-Language' => 'fr-FR',
    ])
        ->assertOk()
        ->assertJsonPath('locale', config('app.locale', 'en'));
});

it('ignores a null user.locale', function () {
    $this->user->update(['locale' => null]);
    $token = $this->user->createToken('test')->plainTextToken;

    $this->getJson('/__test/locale', [
        'Authorization' => "Bearer {$token}",
        'Accept-Language' => 'fr-FR',
    ])
        ->assertOk()
        ->assertJsonPath('locale', config('app.locale', 'en'));
});

// =========================================================================
//  Priority 5 — config default
// =========================================================================

it('falls back to config(app.locale) when nothing else resolves', function () {
    // Symfony injects Accept-Language: en-us,en;q=0.5 by default, so a
    // genuinely-empty signal requires explicitly sending an unsupported
    // locale on the header.
    $this->getJson('/__test/locale', ['Accept-Language' => 'fr-FR'])
        ->assertOk()
        ->assertJsonPath('locale', config('app.locale', 'en'));
});

// =========================================================================
//  Cookie persistence on ?locale=<x>
// =========================================================================

it('writes the app_locale cookie when locale came from the query param', function () {
    // API group has no EncryptCookies middleware, so the cookie is
    // emitted plain. Pass $encrypted=false to skip assertCookie's default
    // decrypt step.
    $response = $this->getJson('/__test/locale?locale=vi')
        ->assertOk()
        ->assertCookie('app_locale', 'vi', false);

    $cookie = collect($response->baseResponse->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'app_locale');

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeFalse();
});

it('does NOT write a cookie for cookie-sourced or header-sourced locales', function () {
    $this->withCredentials()->withUnencryptedCookie('app_locale', 'vi')
        ->getJson('/__test/locale')
        ->assertOk()
        ->assertCookieMissing('app_locale');

    $this->getJson('/__test/locale', ['Accept-Language' => 'vi'])
        ->assertOk()
        ->assertCookieMissing('app_locale');
});

// =========================================================================
//  Integration — Astrotomic Translatable picks the resolved locale
// =========================================================================

it('drives Astrotomic translatable Product name selection via the middleware locale', function () {
    Route::middleware('api')->get('/__test/product-name/{id}', function (string $id) {
        return response()->json([
            'locale' => app()->getLocale(),
            'name' => Product::findOrFail($id)->name,
        ]);
    });

    $brand = Brand::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);
    $type = ProductType::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $brand->id,
    ]);

    $product = Product::create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $brand->id,
        'product_type_id' => $type->id,
        'slug' => 'set-locale-fixture-'.uniqid(),
        'status' => 'draft',
        'is_hidden' => false,
        'ja' => ['name' => '333ビール'],
        'en' => ['name' => '333 Beer'],
        'vi' => ['name' => 'Bia 333'],
    ]);

    $token = $this->user->createToken('test')->plainTextToken;
    $auth = ['Authorization' => "Bearer {$token}"];

    // Accept-Language beats the stale user.locale (ja) — the exact case
    // the user reported on /hq/beto-kitchen/products.
    $this->getJson("/__test/product-name/{$product->id}", $auth + ['Accept-Language' => 'vi'])
        ->assertOk()->assertJsonPath('name', 'Bia 333');

    $this->getJson("/__test/product-name/{$product->id}", $auth + ['Accept-Language' => 'en'])
        ->assertOk()->assertJsonPath('name', '333 Beer');

    // Unsupported Accept-Language → sanctum-guarded user.locale (ja)
    $this->getJson("/__test/product-name/{$product->id}", $auth + ['Accept-Language' => 'fr-FR'])
        ->assertOk()->assertJsonPath('name', '333ビール');

    // The current request header beats a stale persisted cookie.
    $this->withCredentials()->withUnencryptedCookie('app_locale', 'en')
        ->getJson("/__test/product-name/{$product->id}", $auth + ['Accept-Language' => 'vi'])
        ->assertOk()
        ->assertHeader('Content-Language', 'vi')
        ->assertJsonPath('name', 'Bia 333');
});
