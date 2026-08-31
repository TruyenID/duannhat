<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\TaxType;
use App\Services\Tax\TaxTypeService;
use Illuminate\Support\Str;

/**
 * plan-audit test-gap (plan-043) — CONCURRENCY / invariant integrity for the
 * money-config that drives every tax rate: the "exactly one default TaxType per
 * brand" invariant (Decision 9) and the RESTRICT delete guard (Decision 5),
 * plus multi-tenant isolation between brands of the same org.
 *
 * The 126-scenario suite proved these invariants for a SINGLE sequential
 * create/update; nothing hammered them under a burst of racing writes, and
 * nothing asserted that flipping brand A's default leaves brand B's untouched.
 *
 * Test-env caveat (same as CouponConcurrencyTest): phpunit.xml runs SQLite
 * in-memory, which serialises writes — we can't model true OS-thread races.
 * What we CAN prove is that the application-level logic
 * (clearBrandDefault-in-a-transaction) keeps the invariant across a rapid burst
 * and never lets two rows carry is_default = true for one brand, and never
 * bleeds across the brand/org tenant boundary. Real MySQL parallel testing is a
 * deferred QA concern.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brandA = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->brandB = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->service = app(TaxTypeService::class);
});

/** Count the rows carrying is_default for a brand. */
function defaultCountFor(string $brandId): int
{
    return TaxType::where('brand_id', $brandId)->where('is_default', true)->count();
}

it('keeps exactly one default per brand across a burst of racing default flips', function () {
    // Five candidate types in brand A, one seeded default.
    $types = collect(range(1, 5))->map(fn ($i) => TaxType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brandA->id,
        'code' => "RATE{$i}",
        'is_default' => $i === 1,
    ]));

    expect(defaultCountFor($this->brandA->id))->toBe(1);

    // 50 flips, each promoting a (round-robin) different type to default —
    // simulating retried / concurrent "make this the default" writes.
    for ($n = 0; $n < 50; $n++) {
        $target = $types[$n % $types->count()];
        $this->service->update($target->fresh(), ['is_default' => true]);

        // Invariant holds after EVERY write — never a transient double-default.
        expect(defaultCountFor($this->brandA->id))->toBe(1);
    }

    // The last promoted type is the sole survivor.
    $winner = $types[49 % $types->count()];
    expect(TaxType::where('brand_id', $this->brandA->id)->where('is_default', true)->value('id'))
        ->toBe($winner->id);
});

it('a create burst that each requests is_default still lands exactly one default', function () {
    // 30 creates, every one asking to be the brand default — clearBrandDefault
    // must demote the incumbent each time, never accumulate.
    for ($i = 0; $i < 30; $i++) {
        $this->service->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brandA->id,
            'code' => "BURST{$i}",
            'name' => "Burst {$i}",
            'rate' => 8,
            'is_default' => true,
        ]);

        expect(defaultCountFor($this->brandA->id))->toBe(1);
    }

    expect(TaxType::where('brand_id', $this->brandA->id)->count())->toBe(30);
});

it('isolates the default flip to its own brand — a sibling brand keeps its default', function () {
    // Each brand of the SAME org has its own default. clearBrandDefault is
    // scoped by brand_id; a flip in A must never demote B's row (tenant bleed).
    $aDefault = TaxType::factory()->asDefault()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brandA->id, 'code' => 'A-STD',
    ]);
    $bDefault = TaxType::factory()->asDefault()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brandB->id, 'code' => 'B-STD',
    ]);
    $aChallenger = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brandA->id, 'code' => 'A-RED', 'is_default' => false,
    ]);

    $this->service->update($aChallenger, ['is_default' => true]);

    // Brand A: default moved to the challenger, still exactly one.
    expect($aDefault->fresh()->is_default)->toBeFalse()
        ->and($aChallenger->fresh()->is_default)->toBeTrue()
        ->and(defaultCountFor($this->brandA->id))->toBe(1);

    // Brand B: completely untouched.
    expect($bDefault->fresh()->is_default)->toBeTrue()
        ->and(defaultCountFor($this->brandB->id))->toBe(1);
});

it('never soft-deletes an in-use tax type under a burst of bulk-delete attempts', function () {
    // One in-use type (referenced by a product) + two free types. A burst of
    // repeated bulk-deletes must delete the free ones and NEVER the in-use one
    // (the RESTRICT / 409 guard is the money-history protection).
    $inUse = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brandA->id, 'code' => 'INUSE',
    ]);
    $freeA = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brandA->id, 'code' => 'FREEA',
    ]);
    $freeB = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brandA->id, 'code' => 'FREEB',
    ]);
    Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brandA->id,
        'tax_type_id' => $inUse->id,
    ]);

    $ids = [$inUse->id, $freeA->id, $freeB->id];

    // Replay the same bulk-delete three times (retried sync / double-click).
    $lastResult = null;
    for ($n = 0; $n < 3; $n++) {
        $lastResult = $this->service->bulkDelete($ids);
    }

    // The in-use row survived every attempt with a 409 usage report …
    expect($inUse->fresh()->trashed())->toBeFalse();
    $errorIds = collect($lastResult['errors'])->pluck('id')->all();
    expect($errorIds)->toContain($inUse->id);
    expect(collect($lastResult['errors'])->firstWhere('id', $inUse->id)['code'])
        ->toBe('TAX_TYPE_IN_USE');

    // … and the free rows are gone (already-deleted on replay → not re-deleted).
    expect(TaxType::withTrashed()->find($freeA->id)->trashed())->toBeTrue()
        ->and(TaxType::withTrashed()->find($freeB->id)->trashed())->toBeTrue();
});
