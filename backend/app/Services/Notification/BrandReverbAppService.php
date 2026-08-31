<?php

namespace App\Services\Notification;

use App\Models\Brand;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Per-brand Reverb app provisioner (plan-012 T4.3).
 *
 * Each `Brand` row carries its own `reverb_app_id` + `reverb_app_key`
 * + `reverb_app_secret` so cross-brand subscribers can't listen to each
 * other's channels (plan-008 Decision 16). This service is the only
 * writer of those three columns — everywhere else reads them.
 *
 * Lifecycle:
 *   - `provision()` fires on `Brand::created` (hook in AppServiceProvider) to set
 *     first-time creds. Idempotent: skip if `reverb_app_id` is already set.
 *   - `rotate()` regenerates key + secret; `app_id` stays put so existing
 *     config cache entries aren't invalidated — clients reconnect on next
 *     heartbeat. Called from the HQ rotation endpoint.
 *   - `allowedOrigins()` resolves origin whitelist for the brand (falls
 *     back to config `reverb.apps.default.allowed_origins`).
 *
 * The companion that makes these credentials mean anything is
 * `BrandApplicationProvider` — the `database` Reverb application driver,
 * selected by `config/reverb.php`. It is what the running server reads to
 * verify incoming connections, and it queries this table. Until #1208 it was
 * only ever a sentence in this docblock: the server ran the `config` driver,
 * so every key provisioned here was a key it rejected.
 *
 * The `.env` app remains valid — the database driver answers from the config
 * apps first — so a brand with a null `reverb_app_id` is a bug to heal, not a
 * fallback to rely on.
 */
class BrandReverbAppService
{
    /**
     * Populate `reverb_app_id`, `reverb_app_key`, `reverb_app_secret`,
     * `reverb_allowed_origins`, `reverb_provisioned_at` on the brand row.
     * Idempotent — a no-op when `reverb_app_id` is already set.
     */
    public function provision(Brand $brand): void
    {
        if (! empty($brand->reverb_app_id)) {
            return;
        }

        $brand->reverb_app_id = 'brand-'.(string) Str::uuid();
        $brand->reverb_app_key = Str::random(40);
        $brand->reverb_app_secret = Str::random(40);
        $brand->reverb_allowed_origins = $brand->reverb_allowed_origins ?? ['*'];
        $brand->reverb_provisioned_at = now();
        $brand->save();

        Log::info('notification.reverb.provisioned', [
            'brand_id' => $brand->id,
            'app_id' => $brand->reverb_app_id,
        ]);
    }

    /**
     * Regenerate `reverb_app_key` + `reverb_app_secret` for a brand. Connected
     * clients disconnect on next heartbeat — callers should return the new
     * creds once to the admin UI (and not persist them client-side).
     */
    public function rotate(Brand $brand): void
    {
        $brand->reverb_app_key = Str::random(40);
        $brand->reverb_app_secret = Str::random(40);
        $brand->reverb_provisioned_at = now();
        $brand->save();

        Log::info('notification.reverb.rotated', [
            'brand_id' => $brand->id,
            'app_id' => $brand->reverb_app_id,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function allowedOrigins(Brand $brand): array
    {
        $stored = $brand->reverb_allowed_origins;
        if (is_array($stored) && $stored !== []) {
            return $stored;
        }

        return (array) config('reverb.apps.apps.0.allowed_origins', ['*']);
    }
}
