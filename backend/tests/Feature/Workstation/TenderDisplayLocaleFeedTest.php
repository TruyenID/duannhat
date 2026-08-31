<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\TillTenderType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The POS payment dialog's tender-brand chips are mirrored by the workstation
 * and served to every terminal on the shop floor. The workstation pulls this
 * feed on a background tick with NO Accept-Language, so a feed that emits one
 * resolved name pins the whole shop to `config('app.locale')` — which is how a
 * cashier who had picked 日本語 came to read "Credit" and "Transit IC".
 *
 * The feed therefore has to carry every stored locale and let the reader pick.
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

    $this->feed = fn (): array => $this->withHeaders([
        'Authorization' => 'Bearer '.$this->wsToken,
    ])->getJson('/api/v1/workstation/till-tender-types')->assertOk()->json('data');
});

/** Replace the factory's auto-created translation with an explicit ja/en/vi set. */
function seedTenderTranslations(TillTenderType $tender, array $byLocale): void
{
    DB::table('till_tender_type_translations')
        ->where('till_tender_type_id', $tender->id)
        ->delete();

    foreach ($byLocale as $locale => $name) {
        DB::table('till_tender_type_translations')->insert([
            'till_tender_type_id' => $tender->id,
            'locale' => $locale,
            'name' => $name,
        ]);
    }
}

it('emits every stored locale in name_i18n', function () {
    $tender = TillTenderType::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'tender_key' => 'credit',
        'category' => 'card',
        'is_active' => true,
    ]);
    seedTenderTranslations($tender, [
        'ja' => 'クレジット',
        'en' => 'Credit',
        'vi' => 'Tín dụng',
    ]);

    $row = collect(($this->feed)())->firstWhere('tender_key', 'credit');

    // Asserted per key, not as a whole array: the map is keyed by locale, so
    // the order rows come back in is not part of the contract.
    expect($row)->not->toBeNull()
        ->and($row['name_i18n'])->toHaveCount(3)
        ->and($row['name_i18n']['ja'])->toBe('クレジット')
        ->and($row['name_i18n']['en'])->toBe('Credit')
        ->and($row['name_i18n']['vi'])->toBe('Tín dụng');
});

it('keeps `name` a flat string so an un-upgraded workstation is unaffected', function () {
    $tender = TillTenderType::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'tender_key' => 'credit',
        'category' => 'card',
        'is_active' => true,
    ]);
    seedTenderTranslations($tender, ['ja' => 'クレジット', 'en' => 'Credit', 'vi' => 'Tín dụng']);

    $row = collect(($this->feed)())->firstWhere('tender_key', 'credit');

    // A workstation older than this change reads `name` and nothing else. It
    // must stay a string — an accidental map there would land as an empty
    // mirrored name and blank every tender chip in the shop.
    expect($row['name'])->toBeString()->not->toBe('');
});

it('omits a locale the shop never translated rather than back-filling it', function () {
    $tender = TillTenderType::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'tender_key' => 'paypay',
        'category' => 'qr',
        'is_active' => true,
    ]);
    seedTenderTranslations($tender, ['en' => 'PayPay']);

    $row = collect(($this->feed)())->firstWhere('tender_key', 'paypay');

    // Filling the gap from a fallback would make the mirror unable to tell
    // "never translated" from "translated to the same text", and the LAN
    // COALESCE fallback would then never fire.
    expect($row['name_i18n'])->toBe(['en' => 'PayPay'])
        ->and($row['name_i18n'])->not->toHaveKey('ja')
        ->and($row['name_i18n'])->not->toHaveKey('vi');
});

it('falls back to a stored translation before falling back to the key', function () {
    $tender = TillTenderType::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'tender_key' => 'momo',
        'category' => 'qr',
        'is_active' => true,
    ]);
    // Translated ONLY into a language that is neither the app locale nor its
    // fallback, so Astrotomic resolves `name` to null. The device still has to
    // mirror something a human wrote.
    seedTenderTranslations($tender, ['vi' => 'Ví MoMo']);

    $row = collect(($this->feed)())->firstWhere('tender_key', 'momo');

    expect($row['name'])->toBe('Ví MoMo')
        ->and($row['name_i18n'])->toBe(['vi' => 'Ví MoMo']);
})->skip(
    fn () => in_array(config('app.locale'), ['vi'], true)
        || in_array(config('app.fallback_locale'), ['vi'], true),
    'App locale resolves vi directly; the fallback rung under test never runs.',
);

it('never emits a null name — an unnamed tender falls back to its key', function () {
    $tender = TillTenderType::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'tender_key' => 'orphan_tender',
        'category' => 'qr',
        'is_active' => true,
    ]);
    seedTenderTranslations($tender, []);

    $row = collect(($this->feed)())->firstWhere('tender_key', 'orphan_tender');

    // The workstation column is NOT NULL and the chip has to stay tappable.
    expect($row['name'])->toBe('orphan_tender')
        ->and($row['name_i18n'])->toBe([]);
});

it('scopes to the device org and skips inactive tenders', function () {
    $other = Organization::factory()->create();
    TillTenderType::factory()->create([
        'organization_id' => $other->id,
        'branch_id' => null,
        'tender_key' => 'other_org_tender',
        'is_active' => true,
    ]);
    TillTenderType::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'tender_key' => 'retired',
        'is_active' => false,
    ]);
    TillTenderType::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'tender_key' => 'live',
        'is_active' => true,
    ]);

    $keys = collect(($this->feed)())->pluck('tender_key')->all();

    expect($keys)->toContain('live')
        ->and($keys)->not->toContain('other_org_tender')
        ->and($keys)->not->toContain('retired');
});
