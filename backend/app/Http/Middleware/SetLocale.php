<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const SUPPORTED_LOCALES = ['ja', 'en', 'vi'];

    private const COOKIE_NAME = 'app_locale';

    private const COOKIE_TTL = 60 * 24 * 365; // 1 year

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        app()->setLocale($locale);

        $response = $next($request);

        // Make locale negotiation explicit to clients and intermediary caches.
        // Without Vary, a CDN could serve a Japanese representation to an
        // English request that shares the same URL.
        $response->headers->set('Content-Language', $locale);
        $response->headers->set('Vary', 'Accept-Language, Cookie', false);
        $fallbackCount = $request->attributes->get('localization_fallback_count');
        if (is_int($fallbackCount) && $fallbackCount > 0) {
            $response->headers->set('X-Localization-Fallback-Count', (string) $fallbackCount);
        }

        // Set cookie if locale came from query param. Locale is a client-side
        // display preference, not a secret, so JavaScript must be able to keep
        // it synchronized with the active UI locale.
        if ($request->query('locale')) {
            $response->withCookie(cookie(
                name: self::COOKIE_NAME,
                value: $locale,
                minutes: self::COOKIE_TTL,
                httpOnly: false,
                sameSite: 'lax',
            ));
        }

        return $response;
    }

    private function resolveLocale(Request $request): string
    {
        // 1. Query param (highest priority — explicit override)
        $locale = $request->query('locale');
        if ($locale && in_array($locale, self::SUPPORTED_LOCALES, true)) {
            return $locale;
        }

        // 2. Accept-Language header — apiFetch stamps this from the current
        //    in-memory UI locale on every call. It must beat the persisted
        //    cookie because a locale-switch request can race the preference
        //    update that refreshes that cookie.
        //
        //    getLanguages() returns the header parsed per RFC 7231:
        //    sorted by q-value, regions normalized as `vi_VN`. We take the
        //    first entry whose primary subtag is supported — so
        //    `en;q=0.5,vi;q=0.9` resolves to `vi`, not `en`.
        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(explode('_', $language)[0]);
            if (in_array($primary, self::SUPPORTED_LOCALES, true)) {
                return $primary;
            }
        }

        // 3. app_locale cookie — SSR and clients without an explicit
        // Accept-Language header still retain their last preference.
        $locale = $request->cookie(self::COOKIE_NAME);
        if ($locale && in_array($locale, self::SUPPORTED_LOCALES, true)) {
            return $locale;
        }

        // 4. Authenticated user preference (sanctum guard — API default is
        //    `web`, so $request->user() resolves null here without the
        //    explicit guard).
        $user = Auth::guard('sanctum')->user();
        if ($user && $user->locale && in_array($user->locale, self::SUPPORTED_LOCALES, true)) {
            return $user->locale;
        }

        // 5. Config default
        return config('app.locale', 'en');
    }
}
