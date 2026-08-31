<?php

use App\Events\OrderPaid;
use App\Models\CustomerOrder;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

/**
 * #1208 — the dev stack has always run `BROADCAST_CONNECTION=log`, a driver
 * that cannot fail. So the day someone points it at a real Reverb is also the
 * first day a broadcast can throw, and five of these events are
 * `ShouldBroadcastNow` — pushed synchronously inside the request that just took
 * the customer's money.
 *
 * Without `ShouldRescue` that means: payment recorded, order closed, and the
 * customer still gets an HTTP 500 because a websocket frame could not be
 * delivered. A notification failing must never fail the transaction that
 * produced it.
 *
 * `rescue()` still reports the exception, so this is not swallowing — the
 * outage is visible in the logs, it just no longer rides the money path.
 */
final class ThrowingBroadcaster implements Broadcaster
{
    public static int $attempts = 0;

    public function auth($request) {}

    public function validAuthenticationResponse($request, $result) {}

    public function broadcast(array $channels, $event, array $payload = [])
    {
        self::$attempts++;

        throw new RuntimeException('reverb is not listening');
    }
}

beforeEach(function () {
    ThrowingBroadcaster::$attempts = 0;

    Broadcast::extend('throwing', fn () => new ThrowingBroadcaster);
    config(['broadcasting.connections.throwing' => ['driver' => 'throwing']]);
    config(['broadcasting.default' => 'throwing']);
});

it('does not let an unreachable broadcaster escape into the caller', function () {
    $order = new CustomerOrder([
        'id' => (string) Str::uuid(),
        'order_code' => 'ORD-1208',
    ]);

    OrderPaid::dispatch($order);

    // It genuinely tried — otherwise this test would pass on a configuration
    // where nothing broadcasts at all, which is the exact bug it guards.
    expect(ThrowingBroadcaster::$attempts)->toBe(1);
});

it('marks every broadcast event as rescuable', function () {
    // WorkstationSyncPoke is protected at each dispatch site instead, and more
    // usefully: that catch logs `workstation_sync_poke_failed` WITH the branch
    // id, so you know which shop went quiet. ShouldRescue would intercept the
    // throw first and reduce that to an anonymous reported exception.
    $rescuedAtTheCallSite = ['WorkstationSyncPoke'];

    $missing = [];

    foreach (glob(app_path('Events/*.php')) as $file) {
        $class = 'App\\Events\\'.basename($file, '.php');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->implementsInterface(ShouldBroadcast::class)) {
            continue;
        }

        if (in_array($reflection->getShortName(), $rescuedAtTheCallSite, true)) {
            continue;
        }

        if (! $reflection->implementsInterface(ShouldRescue::class)) {
            $missing[] = $reflection->getShortName();
        }
    }

    // Compared as a string so a failure names the offenders instead of
    // printing an array diff.
    expect(implode(', ', $missing))->toBe('');
});
