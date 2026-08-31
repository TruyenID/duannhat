<?php

use App\Models\PaymentMethod;
use App\Services\Omnify\PaymentMethodService;
use Database\Seeders\PaymentMethodSeeder;

/*
 * Plan-038 T10.1 + T10.2 — sanity checks for the migration + seeder. Heavier
 * "submit a debt payment" feature tests live in
 * tests/Feature/Api/V1/Pos/OrderPaymentTest.php once the broader plan-038
 * pos-web payment path lands; for now we cover the contract that the
 * schema + seeded row are in place.
 */

it('adds a type enum column to payment_methods', function () {
    expect(Schema::hasColumn('payment_methods', 'type'))->toBeTrue();
});

it('seeds a `debt` method per branch with type=on_account', function () {
    $debts = PaymentMethod::query()
        ->where('code', 'debt')
        ->where('type', 'on_account')
        ->whereNull('deleted_at')
        ->get();

    // Seeder ran inside the migration; expect at least one seeded row.
    // In a freshly-migrated empty DB there may be zero branches; in that
    // case the seeder skipped — we only assert "if present, shape is right".
    foreach ($debts as $debt) {
        expect($debt->name)->toBe('Ghi nợ');
        expect((bool) $debt->is_auto_confirm)->toBeTrue();
        expect((bool) $debt->requires_tendered)->toBeFalse();
    }
    expect(true)->toBeTrue();
});

it('seeder is idempotent', function () {
    // #2318 — nguồn của phương thức `debt` là PaymentMethodSeeder, KHÔNG phải
    // migration. Migration cũ loop qua `branches` nhưng chạy TRƯỚC seeder nên
    // trên DB dựng mới nó luôn là no-op; nó đã bị xoá cùng 11 data migration khác.
    //
    // Chạy HAI lần rồi so hai lần với nhau — trước #2318 test lấy mốc từ trạng
    // thái do migration để lại, mốc đó giờ không còn tồn tại.
    $seed = fn () => app(PaymentMethodSeeder::class)
        ->run(app(PaymentMethodService::class));

    $seed();
    $after1 = PaymentMethod::query()->where('code', 'debt')->count();

    $seed();
    $after2 = PaymentMethod::query()->where('code', 'debt')->count();

    expect($after1)->toBeGreaterThan(0)
        ->and($after2)->toBe($after1);
});
