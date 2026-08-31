<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Development login (SSO bypass mint endpoint)
    |--------------------------------------------------------------------------
    |
    | `POST /api/dev/test-login` mints a `dev:<console_user_id>` bearer for an
    | allowlisted seeded persona so the apps stay usable when the IdP is
    | unreachable (local dev, the staging box behind a quick tunnel).
    |
    | It is inert unless BOTH gates are open:
    |   - the app runs in one of `sso.dev_bypass.environments` (local/testing)
    |   - `dev_login.enabled` is true (DEV_LOGIN=true)
    | and the minted bearer is only accepted when `SSO_DEV_BYPASS=true` too —
    | see Dxs\Auth\Http\Middleware\AuthenticateSso.
    |
    */

    'enabled' => (bool) env('DEV_LOGIN', false),

    /** Emails that may be minted a bypass bearer. Anything else → 403. */
    'emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'DEV_LOGIN_EMAILS',
            'admin@famgia.com,shop-manager-sjk@famgia.com,sby-manager@famgia.com,sjk-manager@famgia.com',
        )),
    ))),

];
