<?php

declare(strict_types=1);

return [
    // Browser-facing origin when Laravel is mounted behind the Amplify BFF.
    // Force relative callback/logout/failure redirects back to the SPA rather
    // than leaking the internal XServer backend origin to the browser.
    'public_url' => env('SSO_PUBLIC_URL'),

    'issuer' => env('SSO_ISSUER', 'https://platform.test'),
    'service_slug' => env('SSO_SERVICE_SLUG', ''),
    'client_id' => env('SSO_CLIENT_ID', ''),
    'client_secret' => env('SSO_CLIENT_SECRET', ''),
    'redirect_uri' => env('SSO_REDIRECT_URI', env('APP_URL').'/auth/callback'),
    'scopes' => env('SSO_SCOPES', 'openid profile email offline_access'),

    'organization_context_id' => env('SSO_ORGANIZATION_CONTEXT_ID', ''),
    'organization_id' => env('SSO_ORGANIZATION_ID', ''),
    'directory_organization_slug' => env('SSO_DIRECTORY_ORGANIZATION_SLUG', 'betoya'),
    'allow_organization_switching' => (bool) env('SSO_ALLOW_ORGANIZATION_SWITCHING', true),

    'after_login' => env('SSO_AFTER_LOGIN', '/select-context'),
    'after_logout' => env('SSO_AFTER_LOGOUT', '/login'),
    'failure_redirect' => env('SSO_FAILURE_REDIRECT', '/login'),
    'failure_query_parameter' => env('SSO_FAILURE_QUERY_PARAMETER', 'error'),
    'token_cookie' => env('SSO_TOKEN_COOKIE', 'token'),

    'transaction_ttl' => (int) env('SSO_TRANSACTION_TTL', 600),
    'discovery_ttl' => (int) env('SSO_DISCOVERY_TTL', 3600),
    'leeway' => (int) env('SSO_LEEWAY', 30),
    'http_timeout' => (int) env('SSO_TIMEOUT', 5),

    'cache' => [
        'store' => env('SSO_CACHE_STORE'),
        'prefix' => env('SSO_CACHE_PREFIX', 'tempo-sso'),
        'jwks_ttl' => (int) env('SSO_JWKS_TTL', 0),
    ],

    'permissions_path' => env('SSO_PERMISSIONS_PATH', 'api/sso/me/permissions'),
    'permissions_ttl' => (int) env('SSO_PERMISSIONS_TTL', 300),
    'permissions' => [
        'strict' => (bool) env('SSO_PERMISSIONS_STRICT', false),
        'gate_enabled' => true,
    ],

    'context_paths' => [
        'organizations' => env('SSO_ORGANIZATIONS_PATH', 'api/sso/organizations'),
        'access' => env('SSO_ACCESS_PATH', 'api/sso/access'),
        'branches' => env('SSO_BRANCHES_PATH', 'api/sso/branches'),
        'brands' => env('SSO_BRANDS_PATH', 'api/sso/brands'),
        'teams' => env('SSO_TEAMS_PATH', 'api/sso/teams'),
    ],

    'service_id' => env('SSO_SERVICE_ID', ''),
    'admin_token' => env('SSO_ADMIN_TOKEN', ''),
    'authz_path' => env('SSO_AUTHZ_PATH', 'api/admin/catalog/{service}/authz'),
    'sync' => [
        'authz' => [
            'auto' => (bool) env('SSO_SYNC_AUTHZ_AUTO', false),
            'schedule' => env('SSO_SYNC_AUTHZ_SCHEDULE', 'daily'),
        ],
    ],

    'refresh' => [
        'enabled' => (bool) env('SSO_REFRESH_ENABLED', true),
        'leeway' => (int) env('SSO_REFRESH_LEEWAY', 60),
    ],
    'dev_bypass' => [
        'enabled' => (bool) env('SSO_DEV_BYPASS', false),
        'environments' => ['local', 'testing'],
        'token_prefix' => env('SSO_DEV_BYPASS_PREFIX', 'dev:'),
        'subjects' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SSO_DEV_BYPASS_SUBJECTS', '')),
        ))),
    ],
    'routes' => [
        'enabled' => true,
        'prefix' => 'auth',
        'login_redirect' => false,
        'middleware' => ['web'],
    ],
];
