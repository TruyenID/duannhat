<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Default Branch Timezone
    |--------------------------------------------------------------------------
    |
    | Fallback timezone used when a Branch or User record has no timezone set.
    | All business-logic defaults (menu schedules, promotions, notifications,
    | shop creation) read from this single key so the value stays in sync.
    |
    */

    'default_branch_timezone' => env('APP_DEFAULT_BRANCH_TIMEZONE', 'Asia/Tokyo'),

    /*
    | Timezone the OPERATIONS schedule runs on (#1091).
    |
    | One cron cannot fire per-branch, so daily sweeps and digests need a single
    | wall clock — this is the head-office rhythm they follow. It is NOT a
    | business-time source: business dates always resolve per branch through
    | BusinessClock. A VN-first deployment sets this to Asia/Ho_Chi_Minh without
    | touching any business logic.
    */
    'operations_timezone' => env('APP_OPERATIONS_TIMEZONE', env('APP_DEFAULT_BRANCH_TIMEZONE', 'Asia/Tokyo')),

    /*
    | Head-office timezone PER OPERATING COUNTRY (#1161, #2838).
    |
    | One backend serves JP and VN organizations at once. A branch's own IANA
    | timezone always wins; this map is only the fallback when that field is
    | absent or invalid. Countries spanning several zones belong on the branch,
    | not in this map. `operations_timezone` remains the default for unmapped
    | countries and contexts with no branch.
    */
    'operations_timezones' => [
        'JP' => env('APP_OPERATIONS_TIMEZONE_JP', 'Asia/Tokyo'),
        'VN' => env('APP_OPERATIONS_TIMEZONE_VN', 'Asia/Ho_Chi_Minh'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
