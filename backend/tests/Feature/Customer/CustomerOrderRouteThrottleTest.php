<?php

declare(strict_types=1);

/**
 * The guest order surface is public by design — the order id IS the credential,
 * and `auth:customer` would break the counter-pay flow. Rate limiting is what
 * stands in for authentication there, and until #1256 it was applied to the
 * reads and skipped on the writes: eight state-mutating routes on the same
 * opaque token, with no ceiling at all.
 *
 * Two things are asserted, because fixing either alone leaves the hole:
 *
 * 1. Every public /customer/orders route carries a limiter. A route added later
 *    without one fails here rather than shipping unbounded, which is exactly how
 *    the original eight got in.
 * 2. The limiter is keyed per ORDER, not per IP. That is not a style preference:
 *    every phone on a shop's wifi shares one egress address, so an IP key drops
 *    a whole dining room into one bucket and the guests 429 each other off the
 *    split-bill screen. `split-mode` shipped that way.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use Illuminate\Cache\RateLimiter as RateLimiterRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Public guest-order routes, by URI. Authenticated (`/me/*`) routes are out of
 * scope: they carry a real identity and a different limiter argument.
 */
function publicCustomerOrderRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(function ($route): bool {
            $uri = $route->uri();

            return str_starts_with($uri, 'api/v1/customer/orders')
                && ! str_contains($uri, 'customer/me/');
        })
        ->values()
        ->all();
}

it('leaves no public guest-order route without a rate limiter', function () {
    $unlimited = collect(publicCustomerOrderRoutes())
        ->filter(fn ($route): bool => collect($route->gatherMiddleware())
            ->every(fn ($m): bool => ! str_starts_with((string) $m, 'throttle')))
        ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($unlimited)->toBe(
        [],
        'these public guest-order routes have no throttle; the order id is the only credential they require',
    );
});

it('rate-limits the write routes per order, so one shop cannot 429 itself', function () {
    // The per-order limiters resolve their key from the route parameter. A bare
    // `throttle:N,1` on an anonymous route keys by IP instead — the NAT trap.
    // Checking the middleware NAME is what distinguishes them; checking that a
    // throttle merely exists would have passed on the old `split-mode`.
    $ipKeyed = collect(publicCustomerOrderRoutes())
        ->filter(function ($route): bool {
            return collect($route->gatherMiddleware())->contains(
                fn ($m): bool => is_string($m)
                    && str_starts_with($m, 'throttle:')
                    // `throttle:30,1` — a literal limit, so keyed by IP.
                    // `throttle:customer-order-write` — a named limiter.
                    && preg_match('/^throttle:\d/', $m) === 1,
            );
        })
        ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    // The review routes keep their literal per-IP limits on purpose: writing a
    // review is a once-per-visit act, not a per-phone one, and abuse there is
    // spam rather than a dining room locking itself out.
    $allowedIpKeyed = [
        'POST api/v1/customer/orders/{orderId}/reviews',
        'POST api/v1/customer/orders/{orderId}/review-photos',
        'POST api/v1/customer/orders/{orderId}/branch-review',
    ];

    expect(array_values(array_diff($ipKeyed, $allowedIpKeyed)))->toBe(
        [],
        'these guest-order routes are throttled by IP, so every phone behind a shop NAT shares one bucket',
    );
});

it('keys the order limiters on the route parameter under both of its names', function () {
    // `{id}` on most routes, `{orderId}` on the review ones. A limiter that
    // knew only one name would resolve the other to an empty string — putting
    // every order in the fleet into a single bucket, with 429s appearing for
    // unrelated guests and nothing pointing at the cause.
    $limiter = app(RateLimiterRegistry::class);

    foreach (['customer-order-read', 'customer-order-write'] as $name) {
        $callback = $limiter->limiter($name);
        expect($callback)->not->toBeNull("limiter {$name} is not registered");

        foreach (['id', 'orderId'] as $parameter) {
            $request = Request::create('/', 'GET');
            $request->setRouteResolver(function () use ($parameter) {
                return new class($parameter)
                {
                    public function __construct(private string $parameter) {}

                    public function parameter(string $key, $default = null)
                    {
                        return $key === $this->parameter ? 'order-uuid-here' : $default;
                    }

                    public function getName(): string
                    {
                        return 'api.v1.customer.orders.show';
                    }
                };
            });

            $limit = $callback($request);
            // Not `toContain` — for strings that helper reads every argument as
            // another needle, so a failure message passed there is searched for
            // in the key and the test fails on its own explanation.
            expect(str_contains((string) $limit->key, 'order-uuid-here'))->toBeTrue(
                "limiter {$name} ignores the {{$parameter}} parameter, so it shares one bucket across orders",
            );
        }
    }
});

it('actually 429s a write route, and keeps two orders off each other', function () {
    // The three tests above read the route table. This one drives real requests,
    // because a limiter can be registered, named on the route, and still not
    // bite — the middleware alias has to resolve and the key has to vary. It
    // also pins the half that matters operationally: exhausting order A must
    // leave order B alone, since both come from one shop NAT.
    RateLimiter::clear('customer-order-write');

    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);

    $noisy = CustomerOrder::factory()->create(['branch_id' => $branch->id]);
    $quiet = CustomerOrder::factory()->create(['branch_id' => $branch->id]);

    // 30/min. The status of each individual call is irrelevant — a guest order
    // may legitimately refuse a split-mode change — only that the ceiling lands.
    for ($i = 0; $i < 31; $i++) {
        $this->postJson("/api/v1/customer/orders/{$noisy->id}/split-mode", ['split_mode' => 'equal']);
    }
    $this->postJson("/api/v1/customer/orders/{$noisy->id}/split-mode", ['split_mode' => 'equal'])
        ->assertStatus(429);

    expect($this->postJson("/api/v1/customer/orders/{$quiet->id}/split-mode", ['split_mode' => 'equal'])->status())
        ->not->toBe(429);
});
