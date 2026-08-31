<?php

/**
 * #1241 — the dev-login route must be fenced by APP_ENV, not by a runtime flag.
 *
 * `routes/api.php` wraps `POST dev/test-login` in
 * `app()->environment(['local', 'testing'])`, so a production build does not
 * register the route at all. That fence is the outermost of the three gates,
 * and the only one that cannot be opened by an env var on a running server.
 *
 * It had NO test. DevLoginTest pins the DEV_LOGIN flag (404 when closed) and
 * the email allowlist, but every one of its cases runs in `testing`, where the
 * route exists either way — so deleting the fence entirely would have left the
 * whole suite green. The umbrella CLAUDE.md records that this exact softening
 * ("a dev-only code path fenced by APP_ENV downgraded into a runtime flag")
 * shipped three times in one PR and had to be reverted, and that an orphan
 * dev/test-login route once killed every HTTP request and artisan command on
 * `dev`. A protection with that history and no test is the worst combination.
 *
 * This asserts the SOURCE, deliberately. The invariant is not "the route 404s
 * under some configuration" — a runtime assertion would pass for a flag-based
 * gate too, which is the very downgrade being guarded against. The invariant is
 * "the build removes this code outside local/testing", and that lives in the
 * registration itself.
 */
/**
 * Paths are computed from __DIR__ rather than base_path(): tests/Unit/Arch is
 * not bound to TestCase, so no application is booted here — and an assertion
 * about source text has no business needing one.
 */
function backendPath(string $relative): string
{
    return dirname(__DIR__, 3).'/'.$relative;
}

it('registers dev/test-login only inside an APP_ENV fence', function () {
    $source = (string) file_get_contents(backendPath('routes/api.php'));

    expect($source)->toContain('dev/test-login');

    // The registration must sit inside an environment check naming exactly
    // local and testing. Whitespace-tolerant, but the environments are not.
    expect($source)->toMatch(
        '/if\s*\(\s*app\(\)->environment\(\s*\[\s*[\'"]local[\'"]\s*,\s*[\'"]testing[\'"]\s*\]\s*\)\s*\)\s*\{[^}]*dev\/test-login/s'
    );

    // And it must not be reachable through anything a production server could
    // simply switch on. `config(...)`/`env(...)` in this position is precisely
    // the downgrade CLAUDE.md forbids.
    $fenceBlock = preg_match(
        '/if\s*\(\s*(.{0,120}?)\s*\)\s*\{[^}]*dev\/test-login/s',
        $source,
        $m,
    ) ? $m[1] : '';

    expect($fenceBlock)->not->toContain('env(')
        ->and($fenceBlock)->not->toContain('config(')
        ->and($fenceBlock)->not->toContain('production');
});

it('keeps the dev-login controller behind its own flag as well', function () {
    // The inner gate. Belt and braces is the documented design — the fence
    // removes the route, the flag makes the controller inert if the route is
    // ever registered by other means (a package, a test harness, a future
    // refactor that moves the registration).
    $source = (string) file_get_contents(backendPath('app/Http/Controllers/Api/Dev/DevLoginController.php'));

    expect($source)->toContain("config('dev_login.enabled', false)");
});

it('defaults the dev-login flag to off in committed config', function () {
    // A committed default of `true` would make the flag useless: anything that
    // registered the route would then have a live minting endpoint.
    $source = (string) file_get_contents(backendPath('config/dev_login.php'));

    expect($source)->toContain("env('DEV_LOGIN', false)")
        ->and($source)->not->toMatch("/'enabled'\s*=>\s*true/");
});
