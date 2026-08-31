<?php

use App\Models\Branch;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Models\Zone;
use App\Omnify\Enums\TableStatusEnum;
use Database\Seeders\HongoShopConfigSeeder;
use Illuminate\Support\Str;

it('pins hongo SOS and soft-deletes junk tables/zones without touching A/B/C qr_token', function () {
    $org = Organization::factory()->create(['slug' => 'betoya']);
    $branch = Branch::factory()->create([
        'slug' => 'hongo',
        'name' => '本郷店',
        'console_organization_id' => $org->console_organization_id ?? (string) Str::uuid(),
    ]);
    $hall = Zone::factory()->create([
        'branch_id' => $branch->id,
        'organization_id' => $org->id,
        'code' => 'HALL',
        'name' => 'ホール',
    ]);
    $terrace = Zone::factory()->create([
        'branch_id' => $branch->id,
        'organization_id' => $org->id,
        'code' => 'TERRACE',
        'name' => 'テラス',
    ]);
    $real = Table::factory()->create([
        'branch_id' => $branch->id,
        'organization_id' => $org->id,
        'zone_id' => $hall->id,
        'code' => 'A-1',
        'qr_token' => 'printed-a1-token',
        'status' => 'free',
    ]);
    Table::factory()->create([
        'branch_id' => $branch->id,
        'organization_id' => $org->id,
        'zone_id' => $hall->id,
        'code' => 'HALL-01',
        'status' => 'free',
        'current_order_id' => null,
    ]);
    Table::factory()->create([
        'branch_id' => $branch->id,
        'organization_id' => $org->id,
        'zone_id' => $terrace->id,
        'code' => 'TERRACE-01',
        'status' => 'free',
        'current_order_id' => null,
    ]);

    (new HongoShopConfigSeeder)->run();

    $sos = ShopOrderSetting::where('branch_id', $branch->id)->first();
    expect($sos)->not->toBeNull()
        ->and($sos->tax_rounding_mode)->toBe('floor')
        ->and($sos->default_order_item_status)->toBe('served')
        ->and($sos->currency_code)->toBe('JPY');

    expect(Table::where('branch_id', $branch->id)->where('code', 'A-1')->exists())->toBeTrue()
        ->and(Table::find($real->id)?->qr_token)->toBe('printed-a1-token')
        ->and(Table::where('branch_id', $branch->id)->where('code', 'HALL-01')->exists())->toBeFalse()
        ->and(Table::withTrashed()->where('branch_id', $branch->id)->where('code', 'HALL-01')->exists())->toBeTrue()
        ->and(Table::where('branch_id', $branch->id)->where('code', 'TERRACE-01')->exists())->toBeFalse()
        ->and(Zone::where('branch_id', $branch->id)->where('code', 'TERRACE')->exists())->toBeFalse()
        ->and(Zone::where('branch_id', $branch->id)->where('code', 'HALL')->exists())->toBeTrue();
});

it('enables the full Hongo menu and disables thin time-slot menus', function () {
    $org = Organization::factory()->create(['slug' => 'betoya']);
    $branch = Branch::factory()->create([
        'slug' => 'hongo',
        'console_organization_id' => $org->console_organization_id ?? (string) Str::uuid(),
    ]);

    $full = Menu::factory()->create([
        'id' => '019f6efa-2f8f-7279-8e05-1da70a2a725c',
        'branch_id' => $branch->id,
        'organization_id' => $org->id,
        'name' => '本郷店 メニュー',
        'priority' => 205,
    ]);
    $thin = Menu::factory()->create([
        'id' => '019fd5da-7963-7145-bcc4-1271a876e0e2',
        'branch_id' => $branch->id,
        'organization_id' => $org->id,
        'name' => 'Bữa tối & cuối tuần/ngày lễ',
        'priority' => 210,
    ]);

    MenuSchedule::factory()->create([
        'menu_id' => $full->id,
        'is_active' => false,
        'priority' => 10,
        'start_time' => '07:00:00',
        'end_time' => '22:00:00',
        'days_of_week' => 127,
    ]);
    MenuSchedule::factory()->create([
        'menu_id' => $thin->id,
        'is_active' => true,
        'priority' => 2,
        'start_time' => '16:00:00',
        'end_time' => '22:00:00',
        'days_of_week' => 62,
    ]);

    (new HongoShopConfigSeeder)->run();

    expect(Menu::find($full->id)?->priority)->toBe(300)
        ->and(MenuSchedule::where('menu_id', $full->id)->value('is_active'))->toBeTruthy()
        ->and((int) MenuSchedule::where('menu_id', $full->id)->value('priority'))->toBe(300)
        ->and(MenuSchedule::where('menu_id', $thin->id)->value('is_active'))->toBeFalsy();
});

it('does not soft-delete an occupied junk-coded table', function () {
    $org = Organization::factory()->create(['slug' => 'betoya']);
    $branch = Branch::factory()->create([
        'slug' => 'hongo',
        'console_organization_id' => $org->console_organization_id ?? (string) Str::uuid(),
    ]);
    $hall = Zone::factory()->create([
        'branch_id' => $branch->id,
        'organization_id' => $org->id,
        'code' => 'HALL',
    ]);
    Table::factory()->create([
        'branch_id' => $branch->id,
        'organization_id' => $org->id,
        'zone_id' => $hall->id,
        'code' => 'HALL-01',
        'status' => 'occupied',
        'current_order_id' => null,
    ]);

    (new HongoShopConfigSeeder)->run();

    expect(Table::where('branch_id', $branch->id)->where('code', 'HALL-01')->exists())->toBeTrue()
        ->and(Table::where('code', 'HALL-01')->value('status'))->toBe(TableStatusEnum::Occupied);
});

it('skips gracefully when the hongo branch is missing', function () {
    Organization::factory()->create(['slug' => 'betoya']);

    (new HongoShopConfigSeeder)->run();

    expect(ShopOrderSetting::count())->toBe(0);
});
