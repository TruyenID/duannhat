<?php

namespace App\Broadcasting;

use App\Models\Brand;
use App\Services\Notification\Contracts\BrandEventBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\HasBroadcastChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Config;

/**
 * Tenant-aware wrapper around Laravel's `BroadcastManager` — plan-012 T4.5.
 *
 * Lets callers route a broadcast through a brand's own Reverb app creds:
 *
 *   app('broadcast')->brand($brand)->broadcast(new NotificationReceived(...));
 *
 * Works by temporarily rewriting `config('broadcasting.connections.reverb.*')`
 * with the brand's `reverb_app_id` / `reverb_app_key` / `reverb_app_secret`
 * for the duration of the `broadcast()` call, then restoring the previous
 * values. This keeps every Reverb driver call using the same Laravel plumbing
 * (no custom driver needed) and makes the driver-swap to Pusher / Ably a no-op.
 *
 * Unbranded `broadcast()` calls pass straight through to the underlying
 * BroadcastManager — non-tenant features keep working.
 */
class BrandAwareBroadcastManager implements BrandEventBroadcaster
{
    private ?Brand $currentBrand = null;

    /**
     * Track every broadcast this manager has routed — keyed by `app_id` so
     * unit tests can assert per-brand isolation without spinning up Reverb.
     *
     * @var array<int, array{app_id: ?string, event: object}>
     */
    public static array $recorded = [];

    public function __construct(private readonly BroadcastManager $manager) {}

    /**
     * Scope the next `broadcast()` call to a brand's Reverb app creds.
     * The scope is single-shot — reset to null after `broadcast()` runs.
     */
    public function brand(Brand $brand): self
    {
        $this->currentBrand = $brand;

        return $this;
    }

    /**
     * Dispatch the event. When a brand is in scope, its Reverb creds override
     * `config('broadcasting.connections.reverb')` for the duration of the call.
     */
    public function broadcast(object $event): void
    {
        $appId = $this->currentBrand?->reverb_app_id;

        self::$recorded[] = [
            'app_id' => $appId,
            'event' => $event,
        ];

        if ($this->currentBrand === null) {
            $this->dispatchUnderlying($event);

            return;
        }

        $previous = Config::get('broadcasting.connections.reverb.app_id');
        $previousKey = Config::get('broadcasting.connections.reverb.key');
        $previousSecret = Config::get('broadcasting.connections.reverb.secret');

        // SECURITY NOTE (issue #173 part F): we temporarily write the brand's
        // (decrypted) reverb_app_secret into Laravel's runtime config so that
        // the standard Reverb broadcaster picks it up. The window is a single
        // synchronous broadcast call, restored in `finally`. Be EXTREMELY
        // careful adding any logging or debug-toolbar integration that
        // serializes the broadcasting config — it would capture the secret in
        // plaintext. A future refactor (per-call driver factory) would remove
        // this footgun entirely.
        try {
            Config::set('broadcasting.connections.reverb.app_id', $this->currentBrand->reverb_app_id);
            Config::set('broadcasting.connections.reverb.key', $this->currentBrand->reverb_app_key);
            Config::set('broadcasting.connections.reverb.secret', $this->currentBrand->reverb_app_secret);

            $this->dispatchUnderlying($event);
        } finally {
            Config::set('broadcasting.connections.reverb.app_id', $previous);
            Config::set('broadcasting.connections.reverb.key', $previousKey);
            Config::set('broadcasting.connections.reverb.secret', $previousSecret);
            $this->currentBrand = null;
        }
    }

    /**
     * #962 — mặt phẳng (không fluent) mà module khác gọi qua
     * {@see BrandEventBroadcaster}.
     *
     * Kiểu fluent `brand()->broadcast()` giữ trạng thái giữa hai lời gọi, nên
     * nó KHÔNG phải hình dạng đem ra khỏi tầng Composition được: quên
     * `broadcast()` là brand còn kẹt lại cho lời gọi sau. Một method là một lần
     * phát, không có khoảng hở đó.
     */
    public function broadcastForBrand(Brand $brand, object $event): void
    {
        $this->brand($brand)->broadcast($event);
    }

    public function manager(): BroadcastManager
    {
        return $this->manager;
    }

    /**
     * Forward everything else to the underlying BroadcastManager so existing
     * `Broadcast::channel(...)` / `::routes(...)` calls keep working.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->manager->{$method}(...$arguments);
    }

    private function dispatchUnderlying(object $event): void
    {
        // Short-circuit on events that aren't broadcastable — avoids TypeError
        // from the underlying queue dispatch when a non-ShouldBroadcast object
        // somehow reaches here (unit tests often pass plain stubs).
        if (! $event instanceof ShouldBroadcast && ! $event instanceof HasBroadcastChannel) {
            return;
        }

        $this->manager->queue($event);
    }
}
