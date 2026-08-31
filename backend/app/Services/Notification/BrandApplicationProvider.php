<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Brand;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Laravel\Reverb\Application;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Laravel\Reverb\Exceptions\InvalidApplication;

/**
 * The `ReverbAppProvider` BrandReverbAppService's docblock has referred to
 * since it was written, and which did not exist (#1208 item 6).
 *
 * The system had already committed to per-brand Reverb apps in every place
 * except the one that matters. The `Brand::created` hook (AppServiceProvider) provisions a key + secret on
 * `Brand::created`, a seeder backfills the old rows, HQ has a rotation
 * endpoint, and both `Api/V1/Me/ReverbConfigController` and
 * `Api/V1/Device/ReverbConfigController` hand the brand's key to admin-web and
 * KDS, which connect with it. But `config/reverb.php` said `provider =>
 * config`, so the running server only ever knew the single app built from
 * `.env`. Every brand key was therefore a key the server rejects, and a brand
 * with none at all made the endpoints answer `app_key: null`. Neither branch
 * could connect — staff realtime simply never arrived, silently.
 *
 * This driver is ADDITIVE: it answers from the config apps first and falls
 * through to the `brands` table. The `.env` app keeps working exactly as
 * before (customer-web, local tooling, anything not brand-scoped), so turning
 * this on cannot take away an application that used to resolve.
 *
 * Operational shape worth knowing before changing it:
 *
 *   - Lookups are lazy. A key that matches a config app never touches the
 *     database, which is why the test suite and single-tenant deployments run
 *     unchanged.
 *   - There is no cache. A handshake costs one indexed lookup, and rotation
 *     must take effect on the NEXT connection — a TTL here would mean a
 *     rotated-away key still opening sockets for the length of the TTL.
 *   - `reverb:start` is a long-lived process, so its database connection can
 *     be dropped by the server between handshakes. A dropped connection is
 *     retried once after reconnecting; only a second failure propagates. The
 *     failure mode this avoids is the ugly one — a whole brand unable to
 *     connect until someone restarts Reverb, because of one idle timeout.
 */
final class BrandApplicationProvider implements ApplicationProvider
{
    public function __construct(private readonly ApplicationProvider $config) {}

    /**
     * @return Collection<int, Application>
     */
    public function all(): Collection
    {
        $brands = $this->query(
            fn () => Brand::query()
                ->whereNotNull('reverb_app_id')
                ->whereNotNull('reverb_app_key')
                ->whereNotNull('reverb_app_secret')
                ->get()
        );

        return $this->config->all()->concat(
            $brands->map(fn (Brand $brand): Application => $this->toApplication($brand))->all()
        );
    }

    public function findById(string $id): Application
    {
        try {
            return $this->config->findById($id);
        } catch (InvalidApplication) {
            return $this->findBrandBy('reverb_app_id', $id);
        }
    }

    public function findByKey(string $key): Application
    {
        try {
            return $this->config->findByKey($key);
        } catch (InvalidApplication) {
            return $this->findBrandBy('reverb_app_key', $key);
        }
    }

    private function findBrandBy(string $column, string $value): Application
    {
        $brand = $this->query(
            fn () => Brand::query()
                ->where($column, $value)
                ->whereNotNull('reverb_app_key')
                ->whereNotNull('reverb_app_secret')
                ->first()
        );

        if ($brand === null) {
            // Same exception the config provider raises, so Reverb's rejection
            // path is identical whichever provider answered.
            throw new InvalidApplication;
        }

        return $this->toApplication($brand);
    }

    /**
     * Per-app operational limits stay in `config/reverb.php` — a brand row
     * carries identity (id/key/secret) and its origin whitelist, nothing that
     * an operator would want to tune globally.
     */
    private function toApplication(Brand $brand): Application
    {
        $defaults = (array) config('reverb.apps.apps.0', []);

        return new Application(
            (string) $brand->reverb_app_id,
            (string) $brand->reverb_app_key,
            (string) $brand->reverb_app_secret,
            (int) ($defaults['ping_interval'] ?? 60),
            (int) ($defaults['activity_timeout'] ?? 30),
            app(BrandReverbAppService::class)->allowedOrigins($brand),
            (int) ($defaults['max_message_size'] ?? 10_000),
            isset($defaults['max_connections']) && $defaults['max_connections'] !== null
                ? (int) $defaults['max_connections']
                : null,
            (string) ($defaults['accept_client_events_from'] ?? 'members'),
            $defaults['rate_limiting'] ?? null,
            (array) ($defaults['options'] ?? []),
        );
    }

    /**
     * Run a query, reconnecting once if the long-lived server's connection was
     * dropped while it sat idle between handshakes.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function query(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            Brand::query()->getConnection()->reconnect();

            return $callback();
        }
    }
}
