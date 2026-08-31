<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
        'slug' => 'by-day-shop',
        'is_active' => true,
    ]);

    // Master menu — clones point at one; standalone branch menus leave master_menu_id
    // null. The by-day list gates on is_master=false, so both kinds appear.
    $this->masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Active',
        'master_menu_id' => null,
    ]);

    // Shop manager.
    $user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $role = Role::firstOrCreate(
        ['slug' => 'shop-manager'],
        ['name' => 'Shop Manager', 'level' => 50]
    );
    DB::table('role_user_pivots')->insert([
        'user_id' => $user->id, 'role_id' => $role->id, 'organization_id' => $this->orgId, 'branch_id' => $this->shop->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->manager = $user;

    $this->base = "/api/v1/shops/{$this->shop->slug}/menus/by-day";
});

function makeBranchMenu(array $attrs = []): Menu
{
    /** @var TestCase $test */
    $test = test();

    return Menu::factory()->create(array_merge([
        'organization_id' => $test->orgId,
        'brand_id' => $test->brand->id,
        'branch_id' => $test->shop->id,
        'is_master' => false,
        'master_menu_id' => $test->masterMenu->id,
        'status' => 'Active',
    ], $attrs));
}

it('excludes menus with no schedules — endpoint is strictly schedule-driven', function () {
    makeBranchMenu(['name' => 'No Schedule']);

    $response = $this->actingAs($this->manager)->getJson("{$this->base}/0");

    $response->assertSuccessful()->assertJsonCount(0, 'data');
});

it('returns scheduled menu only on days the bitmask covers, with that schedule\'s times', function () {
    // bit1 = Monday only (1 << 1 = 2)
    $monMenu = makeBranchMenu(['name' => 'Mon Lunch']);
    MenuSchedule::factory()->create([
        'menu_id' => $monMenu->id,
        'start_time' => '11:00:00',
        'end_time' => '14:00:00',
        'days_of_week' => 2,
        'is_active' => true,
    ]);

    $monResp = $this->actingAs($this->manager)->getJson("{$this->base}/1");
    $monResp->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $monMenu->id)
        ->assertJsonPath('data.0.start_time', '11:00:00')
        ->assertJsonPath('data.0.end_time', '14:00:00');

    $tueResp = $this->actingAs($this->manager)->getJson("{$this->base}/2");
    $tueResp->assertSuccessful()->assertJsonCount(0, 'data');
});

it('forces status=Active — Inactive menus are excluded even when schedule matches', function () {
    $inactive = makeBranchMenu(['name' => 'Inactive', 'status' => 'Inactive']);
    MenuSchedule::factory()->create([
        'menu_id' => $inactive->id,
        'start_time' => '06:00:00',
        'end_time' => '23:00:00',
        'days_of_week' => 127,
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->manager)->getJson("{$this->base}/3");
    $response->assertSuccessful()->assertJsonCount(0, 'data');
});

it('honors Mon-Fri bitmask (62): visible on weekdays, hidden on weekends', function () {
    $weekday = makeBranchMenu(['name' => 'Mon-Fri']);
    MenuSchedule::factory()->create([
        'menu_id' => $weekday->id,
        'start_time' => '07:00:00',
        'end_time' => '10:30:00',
        'days_of_week' => 62,
        'is_active' => true,
    ]);

    // Mon (1) → visible
    $this->actingAs($this->manager)->getJson("{$this->base}/1")
        ->assertSuccessful()->assertJsonCount(1, 'data');

    // Sun (0) → hidden
    $this->actingAs($this->manager)->getJson("{$this->base}/0")
        ->assertSuccessful()->assertJsonCount(0, 'data');

    // Sat (6) → hidden
    $this->actingAs($this->manager)->getJson("{$this->base}/6")
        ->assertSuccessful()->assertJsonCount(0, 'data');
});

it('returns 404 for out-of-range dayOfWeek (route regex constraint)', function () {
    $this->actingAs($this->manager)->getJson("{$this->base}/7")->assertNotFound();
    $this->actingAs($this->manager)->getJson("{$this->base}/-1")->assertNotFound();
});

it('includes a standalone branch menu (no master) when its schedule covers the day (#878)', function () {
    // Menu created directly on the branch: is_master=false, master_menu_id=null.
    // Pre-fix, the by-day list gated on whereNotNull(master_menu_id) and dropped it.
    $standalone = makeBranchMenu(['name' => 'Standalone Lunch', 'master_menu_id' => null]);
    MenuSchedule::factory()->create([
        'menu_id' => $standalone->id,
        'start_time' => '11:00:00',
        'end_time' => '14:00:00',
        'days_of_week' => 2, // Monday only
        'is_active' => true,
    ]);

    $this->actingAs($this->manager)->getJson("{$this->base}/1")
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $standalone->id);
});
