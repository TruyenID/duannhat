<?php

use App\Models\Branch;
use App\Models\Organization;
use App\Models\PeripheralDevice;
use Database\Seeders\NingyochoPeripheralDeviceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('seeds the ningyocho branch with the Glory cash changer at the shop LAN address', function () {
    $org = Organization::factory()->create(['slug' => 'betoya']);
    Branch::factory()->create([
        'slug' => 'ningyocho',
        'name' => '人形町店',
        'console_organization_id' => $org->console_organization_id ?? (string) Str::uuid(),
    ]);

    (new NingyochoPeripheralDeviceSeeder)->run();

    $branch = Branch::where('slug', 'ningyocho')->first();
    expect($branch)->not->toBeNull();

    $devices = PeripheralDevice::where('branch_id', $branch->id)->get();
    expect($devices)->toHaveCount(1)
        ->and($devices->first()->type)->toBe('coin_changer')
        ->and($devices->first()->name)->toBe('Glory 釣銭機 01')
        ->and($devices->first()->is_active)->toBeTrue()
        ->and($devices->first()->metadata['host'] ?? null)->toBe('192.168.251.120')
        ->and($devices->first()->metadata['port'] ?? null)->toBe(80)
        ->and($devices->first()->metadata['model'] ?? null)->toBe('RT-R08');
});

it('re-runs idempotently without duplicating rows or rotating secrets', function () {
    $org = Organization::factory()->create(['slug' => 'betoya']);
    Branch::factory()->create([
        'slug' => 'ningyocho',
        'console_organization_id' => $org->console_organization_id ?? (string) Str::uuid(),
    ]);

    (new NingyochoPeripheralDeviceSeeder)->run();
    $branch = Branch::where('slug', 'ningyocho')->first();
    $before = PeripheralDevice::where('branch_id', $branch->id)
        ->get()
        ->mapWithKeys(fn (PeripheralDevice $d) => [$d->name => $d->makeVisible('secret')->secret]);

    (new NingyochoPeripheralDeviceSeeder)->run();

    expect(PeripheralDevice::where('branch_id', $branch->id)->count())->toBe(1);

    PeripheralDevice::where('branch_id', $branch->id)->get()->each(
        fn (PeripheralDevice $d) => expect($d->makeVisible('secret')->secret)->toBe($before[$d->name]),
    );
});

it('skips gracefully when the ningyocho branch is missing', function () {
    Organization::factory()->create(['slug' => 'betoya']);

    (new NingyochoPeripheralDeviceSeeder)->run();

    expect(PeripheralDevice::count())->toBe(0);
});

it('skips gracefully when no organization exists yet', function () {
    DB::table('organizations')->delete();

    (new NingyochoPeripheralDeviceSeeder)->run();

    expect(PeripheralDevice::count())->toBe(0);
});
