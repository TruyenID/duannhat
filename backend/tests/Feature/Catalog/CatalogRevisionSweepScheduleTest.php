<?php

declare(strict_types=1);

/**
 * A branch whose revision rebuild failed keeps serving the OLD immutable price
 * map — stale prices, and `catalog_revision_has_toppings` false, which drops
 * that branch's workstations onto the legacy unsigned path for topping orders.
 * Safe, but it discards the offline evidence epic #1092 exists to produce.
 *
 * `RebuildCatalogRevisionJob::failed()` names the recovery itself: the branch
 * stays stuck "until its next catalog edit or a `catalog:rebuild-revisions`
 * sweep". The sweep was a deploy step and nothing else, so for a branch that
 * rarely edits its menu the recovery was tied to a deploy cadence rather than a
 * schedule (#1255).
 *
 * Scheduling it alone would not be enough: per-branch failures inside the sweep
 * went to `$this->error()`, which reaches a terminal. On a cron run that is a
 * log nobody reads, so a branch could fail every night indefinitely. DevOps
 * alerting matches ERROR entries by their `[...]` tag (see
 * CheckTillsSchedulerFreshness's docblock), so both halves are pinned here.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\Organization;
use App\Services\Catalog\CatalogRevisionService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

it('runs the catalog revision sweep on a schedule, not only at deploy time', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'catalog:rebuild-revisions'));

    expect($event)->not->toBeNull('catalog:rebuild-revisions is not scheduled')
        // The sweep walks every branch and rebuilds a snapshot each time. If a
        // real fleet makes that slower than the gap between runs, a second copy
        // starting on top of the first helps nobody.
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('reports a branch it could not rebuild where alerting can see it', function () {
    // The sweep resolves CatalogRevisionService out of the container, so a bound
    // double is the honest way to produce the one condition that matters: a
    // branch that throws. Faking a broken menu row instead would test whichever
    // failure the fixture happened to trigger.
    // A branch must exist for the sweep to have anything to walk; without one it
    // exits early with "nothing to rebuild" and the test would pass vacuously.
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
    ]);
    Menu::factory()->create(['branch_id' => $branch->id]);

    // Mocked AFTER the fixture: creating a Menu fires CatalogRevisionObserver,
    // which calls markDirty() on this same service. Binding the double first
    // would make the test fail inside its own setup, on a method the sweep
    // never reaches.
    $this->mock(CatalogRevisionService::class, function ($mock) {
        $mock->shouldReceive('currentFor')->andThrow(new RuntimeException('snapshot build failed'));
    });

    $tagged = false;
    Log::shouldReceive('error')
        ->withArgs(function (string $message, array $context = []) use (&$tagged): bool {
            if (str_starts_with($message, '[catalog.revision_stale]')) {
                $tagged = true;
            }

            return true;
        })
        ->zeroOrMoreTimes();
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $this->artisan('catalog:rebuild-revisions')->assertExitCode(1);

    expect($tagged)->toBeTrue(
        'a branch failed to rebuild but nothing carried the [catalog.revision_stale] tag, so alerting cannot match it',
    );
});
