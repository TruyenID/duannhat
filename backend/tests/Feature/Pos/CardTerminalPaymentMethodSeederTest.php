<?php

use App\Models\Organization;
use App\Services\Omnify\PaymentMethodService;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The `card_terminal` payment method — the row that makes pos-web drop the
 * tendered/change keypad.
 *
 * #2318 xoá 12 data migration, trong đó có
 * `2026_07_13_150000_seeder_card_terminal_payment_method`. Nguồn DUY NHẤT của
 * hàng này bây giờ là `PaymentMethodSeeder` (đã nằm trong `DatabaseSeeder`), nên
 * các test dưới đây drive seeder chứ không require file migration nào.
 *
 * `RefreshDatabase` chỉ chạy `migrate`, không chạy seeder — mỗi test phải tự gọi
 * `$this->seed(PaymentMethodSeeder::class)`.
 */

function seedOrg(): Organization
{
    $orgId = (string) Str::uuid();

    return Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);
}

/**
 * Count must always be scoped to ONE org: tests/Pest.php seeds a default org
 * into every test, and the seeder correctly emits one row per org — an
 * unscoped count would read that as a duplicate.
 */
function cardTerminalRowsFor(string $orgId): int
{
    return DB::table('payment_methods')
        ->where('organization_id', $orgId)
        ->where('code', 'card_terminal')
        ->count();
}

/**
 * The org's card_terminal translations, keyed by locale and sorted, so two
 * sources can be compared for equality regardless of insertion order.
 *
 * @return array<string, string>
 */
function cardTerminalNamesFor(string $orgId): array
{
    $id = DB::table('payment_methods')
        ->where('organization_id', $orgId)
        ->where('code', 'card_terminal')
        ->value('id');

    $names = DB::table('payment_method_translations')
        ->where('payment_method_id', $id)
        ->pluck('name', 'locale')
        ->all();

    ksort($names);

    return $names;
}

it('seeds card_terminal for an existing organization', function () {
    $org = seedOrg();

    $this->seed(PaymentMethodSeeder::class);

    $row = DB::table('payment_methods')
        ->where('organization_id', $org->id)
        ->where('code', 'card_terminal')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->type)->toBe('card');
    // Without auto-confirm the payment would sit `pending` forever: the POS
    // namespace has no payments/{payment}/confirm route to ever settle it.
    expect((bool) $row->is_auto_confirm)->toBeTrue();
    // This flag IS the feature — it is what makes pos-web drop the keypad.
    expect((bool) $row->requires_tendered)->toBeFalse();
    expect((bool) $row->is_active)->toBeTrue();
    // Org-wide, matching the key PaymentMethodSeeder dedupes on.
    expect($row->branch_id)->toBeNull();
});

it('writes all three translations — the bug the debt migration shipped', function () {
    $org = seedOrg();

    $this->seed(PaymentMethodSeeder::class);

    // The deleted debt migration used a raw insert that set only the base
    // `name` column, which is why "Ghi nợ" had no ja/en name. The seeder goes
    // through PaymentMethodService so every locale persists.
    expect(cardTerminalNamesFor($org->id))->toBe([
        'en' => 'Payment terminal',
        'ja' => '決済端末',
        'vi' => 'Thiết bị thanh toán',
    ]);
});

it('syncs a drifted row back to the canonical values instead of adding a second one', function () {
    // #2318 — trước đây test này so seeder với migration để bắt drift giữa HAI
    // nguồn. Chỉ còn một nguồn, nên phép đo tương đương là: một hàng lệch (do
    // tay, do dữ liệu cũ) phải được seeder kéo về đúng chuẩn, không nhân bản.
    $org = seedOrg();

    app(PaymentMethodService::class)->create([
        'organization_id' => $org->id,
        'branch_id' => null,
        'code' => 'card_terminal',
        'type' => 'card',
        'name:ja' => 'ドリフト',
        'name:en' => 'Drifted',
        'name:vi' => 'Lệch',
        'is_auto_confirm' => false,
        'requires_tendered' => true,
        'is_active' => false,
        'sort_order' => 99,
    ]);

    $this->seed(PaymentMethodSeeder::class);

    expect(cardTerminalRowsFor($org->id))->toBe(1);
    expect(cardTerminalNamesFor($org->id))->toBe([
        'en' => 'Payment terminal',
        'ja' => '決済端末',
        'vi' => 'Thiết bị thanh toán',
    ]);

    $row = DB::table('payment_methods')
        ->where('organization_id', $org->id)
        ->where('code', 'card_terminal')
        ->first();

    expect((bool) $row->is_auto_confirm)->toBeTrue();
    expect((bool) $row->requires_tendered)->toBeFalse();
    expect((bool) $row->is_active)->toBeTrue();
});

it('is idempotent — re-running never duplicates the row', function () {
    $org = seedOrg();

    $this->seed(PaymentMethodSeeder::class);
    $this->seed(PaymentMethodSeeder::class);
    $this->seed(PaymentMethodSeeder::class);

    expect(cardTerminalRowsFor($org->id))->toBe(1);
});

it('emits exactly one row per organization — never zero, never two', function () {
    // The seeder keys on (organization_id, code, branch_id IS NULL). The
    // deleted migration got this wrong — it inserted PER BRANCH, so it could
    // duplicate the org-level row.
    seedOrg();
    seedOrg();

    $this->seed(PaymentMethodSeeder::class);

    // tests/Pest.php seeds a default org, so more than one org is live here.
    $orgIds = DB::table('organizations')->pluck('id');
    expect($orgIds)->not->toBeEmpty();

    foreach ($orgIds as $orgId) {
        expect(cardTerminalRowsFor($orgId))->toBe(1);
    }
});

it('skips soft-deleted organizations', function () {
    $org = seedOrg();
    $org->delete();

    $this->seed(PaymentMethodSeeder::class);

    expect(cardTerminalRowsFor($org->id))->toBe(0);
});
