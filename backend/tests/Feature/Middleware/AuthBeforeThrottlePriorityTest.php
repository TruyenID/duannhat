<?php

use Dxs\Auth\Http\Middleware\AuthenticateSso;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Guards the bootstrap/app.php priority rule that makes Platform SSO run
 * before rate limiting.
 *
 * If ThrottleRequests runs first, `$request->user()` is still null when the
 * limiter computes its signature, so numeric `throttle:N,1` routes fall back to
 * keying by client IP. Every authenticated user behind one IP then shares a
 * single bucket — in dev the browser, kiosk, customer-web and the
 * workstation-app (throttle:300,1) all drain the same counter and starve
 * low-cap routes like /hq/{brand}/trace (30/min) into permanent 429s. Keeping
 * AuthenticateSso ahead of ThrottleRequests restores per-user keying.
 *
 * This can't be asserted through a normal HTTP test: `actingAs()` populates the
 * guard up front, so the user is always available regardless of order. We assert
 * the resolved priority list directly instead.
 */
it('runs Platform SSO authentication before the rate limiter', function () {
    $priority = app(Kernel::class)->getMiddlewarePriority();

    $authIndex = array_search(AuthenticateSso::class, $priority, true);
    $throttleIndex = array_search(ThrottleRequests::class, $priority, true);

    // array_search returns the int index when present, false when missing.
    expect($authIndex)->toBeInt('AuthenticateSso is missing from the middleware priority list')
        ->and($throttleIndex)->toBeInt('ThrottleRequests is missing from the middleware priority list')
        ->and($authIndex)->toBeLessThan(
            $throttleIndex,
            'AuthenticateSso must run before ThrottleRequests so authenticated throttles key per user, not per IP'
        );
});
