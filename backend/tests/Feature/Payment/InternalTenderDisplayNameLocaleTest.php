<?php

use App\Models\Device;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentMethod;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use Database\Seeders\PaymentGatewayCatalogSeeder;
use Illuminate\Support\Str;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

/**
 * The two buttons at the top of the POS payment dialog ("Tiền mặt (sổ nội bộ)",
 * "Máy quẹt thẻ (sổ nội bộ)") come from the internal-tender catalog, whose
 * `name` is translatable and already seeded ja/en/vi.
 *
 * A direct-to-Cloud POS localizes it through SetLocale and only needs
 * `display_name`. The workstation does NOT: it pulls this feed on a background
 * tick with no Accept-Language and mirrors the result for every terminal in
 * the shop, so it needs every locale — the reader's language is not known at
 * pull time. Hence `display_name_i18n` alongside the resolved name.
 */
uses()->group('payment', 'pos');

function internalCashOption(): PaymentGatewayOption
{
    return PaymentGatewayOption::query()
        ->where('code', PaymentGatewayCatalogSeeder::INTERNAL_CASH_OPTION_CODE)
        ->whereHas('provider', fn ($q) => $q->where('code', PaymentGatewayProviderCodeEnum::Internal->value))
        ->firstOrFail();
}

beforeEach(function () {
    $this->fixtures = new PaymentPolicyApiFixtures;
    $this->fixtures->bind();

    PaymentMethod::factory()->create([
        'organization_id' => $this->fixtures->organization->id,
        'branch_id' => null,
        'code' => 'cash',
        'type' => 'cash',
        'is_active' => true,
        'is_auto_confirm' => true,
        'requires_tendered' => true,
    ]);
});

beforeEach(function () {
    // #2318 — catalog internal trước đây do một data migration seed, nên
    // RefreshDatabase (chỉ migrate) là đủ. Migration đó đã bị xoá; nguồn duy
    // nhất bây giờ là seeder, nên test phải gọi nó.
    app(PaymentGatewayCatalogSeeder::class)->seedInternal();
});

it('emits every stored locale of an internal tender name', function () {
    $device = $this->fixtures->seedDevice('pos');

    $options = $this->withHeaders([
        'Authorization' => 'Bearer '.$device->device_token,
        'X-Shop-Slug' => $this->fixtures->shop->slug,
    ])->getJson('/api/v1/pos/effective-payment-options')
        ->assertOk()
        ->json('data.options');

    $cash = collect($options)->firstWhere('id', internalCashOption()->id);

    expect($cash)->not->toBeNull()
        ->and($cash['display_name_i18n']['ja'])->toBe('現金（内部台帳）')
        ->and($cash['display_name_i18n']['en'])->toBe('Cash (internal ledger)')
        ->and($cash['display_name_i18n']['vi'])->toBe('Tiền mặt (sổ nội bộ)');
});

it('still resolves display_name from Accept-Language for a direct Cloud caller', function () {
    $device = $this->fixtures->seedDevice('pos');

    $nameFor = function (string $locale) use ($device): string {
        $options = $this->withHeaders([
            'Authorization' => 'Bearer '.$device->device_token,
            'X-Shop-Slug' => $this->fixtures->shop->slug,
            'Accept-Language' => $locale,
        ])->getJson('/api/v1/pos/effective-payment-options')
            ->assertOk()
            ->json('data.options');

        return collect($options)->firstWhere('id', internalCashOption()->id)['display_name'];
    };

    // The direct path was never broken and must stay that way — the new field
    // is additive, not a replacement.
    expect($nameFor('ja'))->toBe('現金（内部台帳）')
        ->and($nameFor('en'))->toBe('Cash (internal ledger)')
        ->and($nameFor('vi'))->toBe('Tiền mặt (sổ nội bộ)');
});

it('carries the locale map through the workstation matrix feed', function () {
    $workstation = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => Str::random(64),
        'organization_id' => $this->fixtures->organization->id,
        'branch_id' => $this->fixtures->shop->id,
    ]);

    $matrix = $this->withHeaders([
        'Authorization' => 'Bearer '.$workstation->device_token,
    ])->getJson('/api/v1/workstation/effective-payment-options/matrix')
        ->assertOk()
        ->json('data');

    $cash = collect($matrix['branch']['pos']['options'] ?? [])
        ->firstWhere('id', internalCashOption()->id);

    // This is the feed that actually reaches the shop floor: the workstation
    // mirrors it and serves it to every POS terminal. If the map is missing
    // here, the LAN read has nothing to localize from no matter what the
    // cashier picks.
    expect($cash)->not->toBeNull()
        ->and($cash['display_name_i18n']['ja'])->toBe('現金（内部台帳）')
        ->and($cash['display_name_i18n']['vi'])->toBe('Tiền mặt (sổ nội bộ)');
});

it('omits the map for connection-backed options rather than inventing one', function () {
    $this->fixtures->seedConnection();
    $device = $this->fixtures->seedDevice('pos');

    $options = $this->withHeaders([
        'Authorization' => 'Bearer '.$device->device_token,
        'X-Shop-Slug' => $this->fixtures->shop->slug,
    ])->getJson('/api/v1/pos/effective-payment-options')
        ->assertOk()
        ->json('data.options');

    $external = collect($options)->firstWhere('provider', '!=', 'internal');

    // A connection-backed option's display_name is a method-type slug, not a
    // translatable label. Emitting a map of the slug would make the mirror
    // store three copies of an untranslated string and lose the ability to
    // fall back.
    expect($external)->not->toBeNull()
        ->and($external)->not->toHaveKey('display_name_i18n');
});
