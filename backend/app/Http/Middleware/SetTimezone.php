<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTimezone
{
    /**
     * Request attribute key where the resolved timezone is stored.
     *
     * ⚠️ DISPLAY ONLY (#1091). This is the VIEWING USER's timezone
     * (query → cookie → user preference) and must never feed a business
     * decision — business dates, shift boundaries, menu/promotion windows,
     * per-day reports all follow the BRANCH's timezone via
     * \App\Support\BusinessClock. A manager in Hanoi viewing a Tokyo shop
     * must see Tokyo business dates; reading this attribute for that
     * decision would show two different business days for the same shift.
     */
    public const ATTRIBUTE = 'app_timezone';

    private const COOKIE_NAME = 'timezone';

    private const COOKIE_TTL = 60 * 24 * 365; // 1 year

    public function handle(Request $request, Closure $next): Response
    {
        $timezone = $this->resolveTimezone($request);

        // Store in request scope only — do NOT call date_default_timezone_set()
        // or mutate config('app.timezone'). PHP/Eloquent always work in UTC
        // (app.timezone = UTC, DB session = +00:00). Changing the global TZ
        // causes Carbon::parse(dbString) to misinterpret UTC strings as local
        // time, shifting every timestamp by the UTC offset (e.g. +9h for JST).
        $request->attributes->set(self::ATTRIBUTE, $timezone);

        $response = $next($request);

        // Persist timezone preference via cookie when supplied as query param.
        if ($request->query('timezone')) {
            $response->cookie(self::COOKIE_NAME, $timezone, self::COOKIE_TTL);
        }

        return $response;
    }

    private function resolveTimezone(Request $request): string
    {
        // 1. Query param (highest priority)
        $timezone = $request->query('timezone');
        if ($timezone && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        // 2. Cookie
        $timezone = $request->cookie(self::COOKIE_NAME);
        if ($timezone && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        // 3. Authenticated user preference
        $user = $request->user();
        if ($user && $user->timezone && in_array($user->timezone, timezone_identifiers_list(), true)) {
            return $user->timezone;
        }

        // 4. Default: branch timezone or app default
        return config('app.default_branch_timezone', 'Asia/Tokyo');
    }
}
