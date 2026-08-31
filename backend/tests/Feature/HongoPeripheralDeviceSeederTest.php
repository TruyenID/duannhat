<?php

use App\Models\Branch;
use App\Models\Organization;
use App\Models\PeripheralDevice;
use Database\Seeders\HongoPeripheralDeviceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('seeds the hongo branch with the Glory cash changer at the shop LAN address', function () {
    $org = Organization::factory()->create(['slug' => 'betoya']);
    Branch::factory()->create([
        'slug' => 'hongo',
        'name' => '本郷店',
        'console_organization_id' => $org->console_organization_id ?? (string) Str::uuid(),
    ]);

    (new HongoPeripheralDeviceSeeder)->run();

    $branch = Branch::where('slug', 'hongo')->first();
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
        'slug' => 'hongo',
        'console_organization_id' => $org->console_organization_id ?? (string) Str::uuid(),
    ]);

    (new HongoPeripheralDeviceSeeder)->run();
    $branch = Branch::where('slug', 'hongo')->first();
    $before = PeripheralDevice::where('branch_id', $branch->id)
        ->get()
        ->mapWithKeys(fn (PeripheralDevice $d) => [$d->name => $d->makeVisible('secret')->secret]);

    (new HongoPeripheralDeviceSeeder)->run();

    expect(PeripheralDevice::where('branch_id', $branch->id)->count())->toBe(1);

    PeripheralDevice::where('branch_id', $branch->id)->get()->each(
        fn (PeripheralDevice $d) => expect($d->makeVisible('secret')->secret)->toBe($before[$d->name]),
    );
});

it('skips gracefully when the hongo branch is missing', function () {
    Organization::factory()->create(['slug' => 'betoya']);

    (new HongoPeripheralDeviceSeeder)->run();

    expect(PeripheralDevice::count())->toBe(0);
});

it('skips gracefully when no organization exists yet', function () {
    DB::table('organizations')->delete();

    (new HongoPeripheralDeviceSeeder)->run();

    expect(PeripheralDevice::count())->toBe(0);
});
