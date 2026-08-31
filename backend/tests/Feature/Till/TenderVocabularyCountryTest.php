<?php

use App\Models\Organization;
use App\Models\TillTenderType;
use Database\Seeders\TillTenderTypeSeeder;
use Illuminate\Support\Facades\DB;

function runTenderSeeder(): void
{
    test()->artisan('db:seed', ['--class' => TillTenderTypeSeeder::class, '--force' => true]);
}

function orgTenderKeys(Organization $org): array
{
    return TillTenderType::query()
        ->where('organization_id', $org->id)
        ->whereNull('branch_id')
        ->orderBy('sort_order')
        ->pluck('tender_key')
        ->all();
}

it('seeds the JP vocabulary for a JP organization', function () {
    DB::table('organizations')->delete();
    $org = Organization::factory()->create(['operating_country' => 'JP']);

    runTenderSeeder();

    $keys = orgTenderKeys($org);
    expect($keys)->toHaveCount(17)
        ->and($keys)->toContain('paypay', 'rakuten_pay', 'waon', 'quicpay')
        ->and($keys)->not->toContain('momo', 'vietqr');

    expect(TillTenderType::where('organization_id', $org->id)->pluck('currency_code')->unique()->all())
        ->toBe(['JPY']);
});

it('seeds the VN vocabulary for a VN organization', function () {
    DB::table('organizations')->delete();
    $org = Organization::factory()->create(['operating_country' => 'VN']);

    runTenderSeeder();

    $keys = orgTenderKeys($org);
    expect($keys)->toBe(['cash', 'credit', 'vietqr', 'momo', 'zalopay', 'vnpay', 'shopeepay'])
        ->and($keys)->not->toContain('paypay', 'waon');

    expect(TillTenderType::where('organization_id', $org->id)->pluck('currency_code')->unique()->all())
        ->toBe(['VND']);

    // VietQR anchors the transfer method — expected at close comes from
    // transfer payments, no terminal slip required.
    $vietqr = TillTenderType::where('organization_id', $org->id)->where('tender_key', 'vietqr')->first();
    expect($vietqr->payment_method_code)->toBe('transfer')
        ->and((bool) $vietqr->is_expected_anchor)->toBeTrue()
        ->and((bool) $vietqr->requires_terminal_total)->toBeFalse();
});

it('seeds each organization with its own country vocabulary', function () {
    DB::table('organizations')->delete();
    $jp = Organization::factory()->create(['operating_country' => 'JP']);
    $vn = Organization::factory()->create(['operating_country' => 'VN']);

    runTenderSeeder();

    expect(orgTenderKeys($jp))->toHaveCount(17)
        ->and(orgTenderKeys($vn))->toHaveCount(7);
});

it('falls back to the JP vocabulary for an unknown country', function () {
    DB::table('organizations')->delete();
    $org = Organization::factory()->create(['operating_country' => 'XX']);

    runTenderSeeder();

    expect(orgTenderKeys($org))->toHaveCount(17)
        ->and(orgTenderKeys($org))->toContain('paypay');
});

it('re-runs idempotently without duplicating rows', function () {
    DB::table('organizations')->delete();
    $org = Organization::factory()->create(['operating_country' => 'VN']);

    runTenderSeeder();
    runTenderSeeder();

    expect(orgTenderKeys($org))->toHaveCount(7);
});
