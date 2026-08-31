<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Plan-019 — MenuPromotion runtime configuration.
    |--------------------------------------------------------------------------
    |
    | cache_enabled  — when true, MenuPromotionService::candidatesForBranch
    |                  uses a per-branch+date Cache::remember (60s TTL) and
    |                  invalidates on every CRUD/toggle. When false, every
    |                  resolve hits the DB. Default off so unit/feature
    |                  tests don't fight a stale cache between assertions;
    |                  enable in production via PROMOTION_CACHE_ENABLED=true.
    |
    | Timezone fallback: app.default_branch_timezone (see config/app.php).
    */

    'cache_enabled' => env('PROMOTION_CACHE_ENABLED', false),
];
