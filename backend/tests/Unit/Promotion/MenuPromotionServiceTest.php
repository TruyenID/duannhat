<?php

/**
 * Plan-019 — pure-function unit tests for MenuPromotionService.
 *
 * The cache-aware candidatesForBranch() needs DB; cover the pure
 * matchesScope() / matchesDailyWindow() decision logic via a small
 * test-only subclass that exposes the protected methods.
 */

use App\Models\Category;
use App\Models\MenuPromotion;
use App\Models\Product;
use App\Services\Promotion\MenuPromotionService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->service = new class extends MenuPromotionService
    {
        public function publicMatchesDailyWindow(MenuPromotion $p, string $localTime, int $weekday): bool
        {
            return $this->matchesDailyWindow($p, $localTime, $weekday);
        }

        public function publicMatchesScope(MenuPromotion $p, string $productId, array $catIds): bool
        {
            return $this->matchesScope($p, $productId, $catIds);
        }
    };
});

// ─── matchesDailyWindow ─────────────────────────────────────────────────

it('matches when no window restriction (both null)', function () {
    $p = new MenuPromotion(['daily_time_from' => null, 'daily_time_to' => null, 'weekdays' => null]);

    expect($this->service->publicMatchesDailyWindow($p, '13:00:00', 1))->toBeTrue();
    expect($this->service->publicMatchesDailyWindow($p, '23:59:59', 7))->toBeTrue();
});

it('matches inside a normal same-day window', function () {
    $p = new MenuPromotion(['daily_time_from' => '21:00:00', 'daily_time_to' => '23:00:00', 'weekdays' => null]);

    expect($this->service->publicMatchesDailyWindow($p, '21:00:00', 1))->toBeTrue();
    expect($this->service->publicMatchesDailyWindow($p, '22:30:00', 1))->toBeTrue();
    expect($this->service->publicMatchesDailyWindow($p, '23:00:00', 1))->toBeTrue();
    expect($this->service->publicMatchesDailyWindow($p, '20:59:59', 1))->toBeFalse();
    expect($this->service->publicMatchesDailyWindow($p, '23:00:01', 1))->toBeFalse();
});

it('matches across midnight when to < from', function () {
    $p = new MenuPromotion(['daily_time_from' => '21:00:00', 'daily_time_to' => '02:00:00', 'weekdays' => null]);

    // Late-evening half: still inside window.
    expect($this->service->publicMatchesDailyWindow($p, '23:30:00', 1))->toBeTrue();
    expect($this->service->publicMatchesDailyWindow($p, '21:00:00', 1))->toBeTrue();
    // Early-morning half: still inside window.
    expect($this->service->publicMatchesDailyWindow($p, '00:30:00', 1))->toBeTrue();
    expect($this->service->publicMatchesDailyWindow($p, '02:00:00', 1))->toBeTrue();
    // Outside both halves: no match.
    expect($this->service->publicMatchesDailyWindow($p, '12:00:00', 1))->toBeFalse();
    expect($this->service->publicMatchesDailyWindow($p, '20:59:59', 1))->toBeFalse();
});

it('respects weekdays array (1=Mon, 7=Sun ISO)', function () {
    $p = new MenuPromotion([
        'daily_time_from' => null,
        'daily_time_to' => null,
        'weekdays' => [1, 2, 3, 4, 5], // weekdays only
    ]);

    expect($this->service->publicMatchesDailyWindow($p, '13:00:00', 1))->toBeTrue();
    expect($this->service->publicMatchesDailyWindow($p, '13:00:00', 5))->toBeTrue();
    expect($this->service->publicMatchesDailyWindow($p, '13:00:00', 6))->toBeFalse();
    expect($this->service->publicMatchesDailyWindow($p, '13:00:00', 7))->toBeFalse();
});

it('treats null/empty weekdays as "every day"', function () {
    $pNull = new MenuPromotion(['daily_time_from' => null, 'daily_time_to' => null, 'weekdays' => null]);
    $pEmpty = new MenuPromotion(['daily_time_from' => null, 'daily_time_to' => null, 'weekdays' => []]);

    foreach ([$pNull, $pEmpty] as $p) {
        for ($d = 1; $d <= 7; $d++) {
            expect($this->service->publicMatchesDailyWindow($p, '13:00:00', $d))->toBeTrue();
        }
    }
});

// ─── matchesScope ───────────────────────────────────────────────────────

it('matches all_items unconditionally', function () {
    $p = makePromotionWithScope('all_items', categoryIds: [], productIds: []);

    expect($this->service->publicMatchesScope($p, 'product-1', []))->toBeTrue();
    expect($this->service->publicMatchesScope($p, 'product-1', ['cat-x']))->toBeTrue();
});

it('matches categories when at least one category overlaps', function () {
    $p = makePromotionWithScope('categories', categoryIds: ['cat-drinks', 'cat-dessert'], productIds: []);

    expect($this->service->publicMatchesScope($p, 'product-1', ['cat-drinks']))->toBeTrue();
    expect($this->service->publicMatchesScope($p, 'product-1', ['cat-mains', 'cat-dessert']))->toBeTrue();
    expect($this->service->publicMatchesScope($p, 'product-1', ['cat-mains']))->toBeFalse();
    expect($this->service->publicMatchesScope($p, 'product-1', []))->toBeFalse();
});

it('matches products on direct FK', function () {
    $p = makePromotionWithScope('products', categoryIds: [], productIds: ['product-premium']);

    expect($this->service->publicMatchesScope($p, 'product-premium', ['cat-x']))->toBeTrue();
    expect($this->service->publicMatchesScope($p, 'product-other', ['cat-x']))->toBeFalse();
});

it('matches mixed via either category or product', function () {
    $p = makePromotionWithScope('mixed', categoryIds: ['cat-drinks'], productIds: ['product-vip']);

    expect($this->service->publicMatchesScope($p, 'product-x', ['cat-drinks']))->toBeTrue();   // category match
    expect($this->service->publicMatchesScope($p, 'product-vip', ['cat-mains']))->toBeTrue();  // product match
    expect($this->service->publicMatchesScope($p, 'product-x', ['cat-mains']))->toBeFalse();   // neither
});

// ─── helper ─────────────────────────────────────────────────────────────

function makePromotionWithScope(string $appliesTo, array $categoryIds, array $productIds): MenuPromotion
{
    $p = new MenuPromotion([
        'applies_to' => $appliesTo,
        'discount_percent' => 10,
        'is_active' => true,
        'valid_from' => CarbonImmutable::parse('-1 day'),
        'valid_until' => CarbonImmutable::parse('+1 day'),
    ]);
    // Bypass DB by stuffing the M2M relations as Eloquent collections.
    // `id` isn't in fillable so use forceFill to bypass guarded.
    $p->setRelation('categories', collect(array_map(
        fn ($id) => (new Category)->forceFill(['id' => $id]),
        $categoryIds,
    )));
    $p->setRelation('products', collect(array_map(
        fn ($id) => (new Product)->forceFill(['id' => $id]),
        $productIds,
    )));

    return $p;
}
