<?php

use App\Models\Branch;
use App\Models\BranchScheduleOverride;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Models\Organization;
use Illuminate\Support\Str;

/**
 * Workstation menu-schedule feed must carry start_time + end_time +
 * priority so the LAN by-day endpoint can mirror Cloud's
 * `ShopMenuByDayResource`. Pre-fix the controller only emitted
 * (id, menu_id, menu_name, day_of_week, is_active), leaving the
 * workstation picker chip without a time window.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

it('emits start_time, end_time, priority on each expanded row', function () {
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);

    // Bitmask covers Mon (bit 1) + Tue (bit 2) = 6
    MenuSchedule::factory()->create([
        'menu_id' => $menu->id,
        'days_of_week' => 6,
        'start_time' => '11:00:00',
        'end_time' => '14:00:00',
        'priority' => 3,
        'is_active' => true,
    ]);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu-schedules')
        ->assertOk();

    $rows = collect($resp->json('data'))->where('menu_id', $menu->id)->values();
    expect($rows)->toHaveCount(2); // Mon + Tue

    foreach ($rows as $r) {
        expect($r['start_time'])->toBe('11:00:00')
            ->and($r['end_time'])->toBe('14:00:00')
            ->and($r['priority'])->toBe(3)
            ->and($r['is_active'])->toBeTrue();
    }
    expect($rows->pluck('day_of_week')->sort()->values()->all())->toBe([1, 2]);
});

it('orders by priority and surfaces all matching rows', function () {
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);
    // Two schedules covering Mon (bit 1 = 2 in mask). Lower priority
    // = higher precedence; the workstation handler picks `priority`
    // ASC.
    MenuSchedule::factory()->create([
        'menu_id' => $menu->id,
        'days_of_week' => 2,
        'start_time' => '11:00:00',
        'end_time' => '14:00:00',
        'priority' => 1,
        'is_active' => true,
    ]);
    MenuSchedule::factory()->create([
        'menu_id' => $menu->id,
        'days_of_week' => 2,
        'start_time' => '09:00:00',
        'end_time' => '22:00:00',
        'priority' => 9,
        'is_active' => true,
    ]);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu-schedules')
        ->assertOk();

    $rows = collect($resp->json('data'))->where('menu_id', $menu->id)->values();
    expect($rows)->toHaveCount(2);

    // Both rows must carry priority field — workstation uses it to
    // pick the highest-priority match for the day in its by-day handler.
    expect($rows->pluck('priority')->sort()->values()->all())->toBe([1, 9]);
});

it('folds in branch schedule overrides so the offline feed matches Cloud reads', function () {
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);

    // HQ default window: Mon (bit 1) 11:00–14:00.
    $schedule = MenuSchedule::factory()->create([
        'menu_id' => $menu->id,
        'days_of_week' => 2,
        'start_time' => '11:00:00',
        'end_time' => '14:00:00',
        'priority' => 0,
        'is_active' => true,
    ]);

    // Branch narrows its lunch to 12:00–13:00. Cloud getCurrentMenu /
    // listActiveBranchMenusForShopByDay COALESCE this over the HQ default;
    // the offline replica feed must do the same.
    BranchScheduleOverride::factory()->create([
        'menu_schedule_id' => $schedule->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'start_time' => '12:00:00',
        'end_time' => '13:00:00',
    ]);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu-schedules')
        ->assertOk();

    $rows = collect($resp->json('data'))->where('menu_id', $menu->id)->values();
    expect($rows)->toHaveCount(1); // Mon only

    expect($rows[0]['start_time'])->toBe('12:00:00')
        ->and($rows[0]['end_time'])->toBe('13:00:00');
});

it('falls back to the HQ time per-column when the override leaves it null', function () {
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);

    $schedule = MenuSchedule::factory()->create([
        'menu_id' => $menu->id,
        'days_of_week' => 2, // Mon
        'start_time' => '11:00:00',
        'end_time' => '14:00:00',
        'priority' => 0,
        'is_active' => true,
    ]);

    // Override only the end_time; start_time null → HQ 11:00 stands (matches
    // Cloud's per-column COALESCE(override, HQ)).
    BranchScheduleOverride::factory()->create([
        'menu_schedule_id' => $schedule->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'start_time' => null,
        'end_time' => '15:30:00',
    ]);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu-schedules')
        ->assertOk();

    $rows = collect($resp->json('data'))->where('menu_id', $menu->id)->values();
    expect($rows)->toHaveCount(1);
    expect($rows[0]['start_time'])->toBe('11:00:00')
        ->and($rows[0]['end_time'])->toBe('15:30:00');
});
