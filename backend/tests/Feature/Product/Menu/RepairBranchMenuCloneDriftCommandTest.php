<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * #1234 + #1235 — the ops half. Both bugs are fixed forward, but a fix in
 * `cloneToBranch` cannot reach a menu cloned last month, and nothing repairs
 * one automatically: `syncFromMaster` would, but nothing calls sync on a
 * schedule — it waits for someone in the shop to press a button.
 *
 * These tests build the BROKEN shape on purpose (raw writes that bypass the
 * fixed clone path) because that is what production actually holds today. A
 * test that cloned through the repaired code would prove nothing about the rows
 * this command exists to fix.
 */
beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->master = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Approved',
        'created_by_id' => $this->user->id,
        'master_menu_id' => null,
        'brand_id' => $this->brand->id,
        'service_type' => 'Takeaway',
    ]);

    $this->masterSchedule = MenuSchedule::factory()->create([
        'menu_id' => $this->master->id,
        'start_time' => '17:00',
        'end_time' => '19:00',
        'days_of_week' => 127,
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-15',
        'is_active' => true,
    ]);

    // The broken clone, exactly as it lands in production before the fixes:
    // service_type widened to the DB default, schedule dates lost.
    $this->clone = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_master' => false,
        'status' => 'Active',
        'created_by_id' => $this->user->id,
        'master_menu_id' => $this->master->id,
        'brand_id' => $this->brand->id,
        'service_type' => 'Both',
    ]);

    $this->cloneSchedule = MenuSchedule::factory()->create([
        'menu_id' => $this->clone->id,
        'master_schedule_id' => $this->masterSchedule->id,
        'start_time' => '17:00',
        'end_time' => '19:00',
        'days_of_week' => 127,
        'start_date' => null,
        'end_date' => null,
        'is_active' => true,
    ]);
});

it('writes nothing on a dry run', function () {
    // The default has to be safe: an operator who runs this to see the damage
    // must not have changed anything by looking.
    $this->artisan('menus:repair-clone-drift')
        ->expectsOutputToContain('service_type=1 schedule_dates=1 (dry run)')
        ->assertExitCode(0);

    expect($this->clone->fresh()->getRawOriginal('service_type'))->toBe('Both')
        ->and($this->cloneSchedule->fresh()->start_date)->toBeNull();
});

it('restores the routing and the campaign window with --apply', function () {
    $this->artisan('menus:repair-clone-drift', ['--apply' => true])
        ->assertExitCode(0);

    $schedule = $this->cloneSchedule->fresh();

    expect($this->clone->fresh()->getRawOriginal('service_type'))->toBe('Takeaway')
        ->and($schedule->start_date?->toDateString())->toBe('2026-02-01')
        ->and($schedule->end_date?->toDateString())->toBe('2026-02-15');
});

it('is idempotent — a second run finds nothing left to do', function () {
    $this->artisan('menus:repair-clone-drift', ['--apply' => true])->assertExitCode(0);

    $this->artisan('menus:repair-clone-drift')
        ->expectsOutputToContain('touched=0')
        ->assertExitCode(0);
});

it('clears a window HQ removed, rather than only filling blanks', function () {
    // The mirror direction that a fill-the-blanks repair would miss entirely.
    // Without it, a campaign HQ retired could never be lifted from the shops.
    $this->masterSchedule->update(['start_date' => null, 'end_date' => null]);
    $this->cloneSchedule->update(['start_date' => '2026-02-01', 'end_date' => '2026-02-15']);

    $this->artisan('menus:repair-clone-drift', ['--apply' => true])->assertExitCode(0);

    $schedule = $this->cloneSchedule->fresh();
    expect($schedule->start_date)->toBeNull()->and($schedule->end_date)->toBeNull();
});

it('leaves a shop-created schedule alone', function () {
    // No master_schedule_id means the shop made it and HQ has no window to
    // mirror. Overwriting it would delete a shop's own decision.
    $ownSchedule = MenuSchedule::factory()->create([
        'menu_id' => $this->clone->id,
        'master_schedule_id' => null,
        'start_time' => '11:00',
        'end_time' => '14:00',
        'days_of_week' => 127,
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-31',
        'is_active' => true,
    ]);

    $this->artisan('menus:repair-clone-drift', ['--apply' => true])->assertExitCode(0);

    $fresh = $ownSchedule->fresh();
    expect($fresh->start_date?->toDateString())->toBe('2026-03-01')
        ->and($fresh->end_date?->toDateString())->toBe('2026-03-31');
});

it('scopes to one branch when asked', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $otherClone = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $otherBranch->id,
        'is_master' => false,
        'status' => 'Active',
        'created_by_id' => $this->user->id,
        'master_menu_id' => $this->master->id,
        'brand_id' => $this->brand->id,
        'service_type' => 'Both',
    ]);

    $this->artisan('menus:repair-clone-drift', ['--apply' => true, '--branch' => $this->branch->id])
        ->assertExitCode(0);

    expect($this->clone->fresh()->getRawOriginal('service_type'))->toBe('Takeaway')
        ->and($otherClone->fresh()->getRawOriginal('service_type'))->toBe('Both');
});

it('reports a dangling master instead of failing or skipping silently', function () {
    // A branch menu whose master was deleted can never be repaired or synced
    // again. Saying so is the whole value — a silent skip reads as "clean".
    DB::table('menus')->where('id', $this->clone->id)
        ->update(['master_menu_id' => '00000000-0000-0000-0000-0000000000ff']);

    $this->artisan('menus:repair-clone-drift')
        ->expectsOutputToContain('is gone — cannot mirror')
        ->assertExitCode(0);
});
