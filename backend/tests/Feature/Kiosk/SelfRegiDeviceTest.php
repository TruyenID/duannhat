<?php

/**
 * #1085 (Option A) — `self_regi` is a first-class device type + payment
 * channel. A self-checkout register SHARES the kiosk surface (routes,
 * pairing, order flow) but resolves its OWN capability channel: the
 * internal money-collection tenders (cash via 釣銭機, card_terminal) are
 * certified for `self_regi` in the gateway catalog while an order-taking
 * kiosk stays cashless/policy-only.
 */

use App\Models\Device;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentMethod;
use App\Omnify\Enums\DeviceTypeEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use Database\Seeders\PaymentGatewayCatalogSeeder;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

uses()->group('payment');

function selfRegiInternalOption(string $code): ?PaymentGatewayOption
{
    return PaymentGatewayOption::query()
        ->where('code', $code)
        ->whereHas('provider', fn ($query) => $query->where('code', PaymentGatewayProviderCodeEnum::Internal->value))
        ->first();
}

beforeEach(function () {
    // #2318 — catalog internal trước đây do một data migration seed, nên
    // RefreshDatabase (chỉ migrate) là đủ. Migration đó đã bị xoá; nguồn duy
    // nhất bây giờ là seeder, nên test phải gọi nó.
    app(PaymentGatewayCatalogSeeder::class)->seedInternal();

    $this->fixtures = new PaymentPolicyApiFixtures;
    $this->fixtures->bind();
});

it('enum carries self_regi on both DeviceType and PaymentChannel', function () {
    expect(DeviceTypeEnum::SelfRegi->value)->toBe('self_regi')
        ->and(PaymentChannelEnum::SelfRegi->value)->toBe('self_regi');
});

it('admits a self_regi device on the kiosk surface', function () {
    $device = $this->fixtures->seedDevice('self_regi');

    $this->withHeaders(['Authorization' => 'Bearer '.$device->device_token])
        ->getJson('/api/v1/kiosk/me')
        ->assertOk()
        ->assertJsonPath('data.branch_id', $this->fixtures->shop->id);
});

it('still rejects non-kiosk-surface device types with DEVICE_TYPE_NOT_ALLOWED', function () {
    $device = $this->fixtures->seedDevice('pos');

    $this->withHeaders(['Authorization' => 'Bearer '.$device->device_token])
        ->getJson('/api/v1/kiosk/me')
        ->assertForbidden()
        ->assertJsonPath('code', 'DEVICE_TYPE_NOT_ALLOWED');
});

it('seeder puts self_regi into the internal catalog channels (kiosk stays excluded from card_terminal)', function () {
    // #2318 — kênh `self_regi` từng đến từ một data migration; giờ nó nằm sẵn
    // trong PaymentGatewayCatalogSeeder (beforeEach đã chạy seedInternal).
    $cash = selfRegiInternalOption(PaymentGatewayCatalogSeeder::INTERNAL_CASH_OPTION_CODE);
    $terminal = selfRegiInternalOption(PaymentGatewayCatalogSeeder::INTERNAL_CARD_TERMINAL_OPTION_CODE);

    expect($cash->channels)->toContain('self_regi')
        ->and($terminal->channels)->toContain('self_regi')
        ->and($terminal->channels)->not->toContain('kiosk');
});

it('seeding the internal catalog is idempotent (re-run appends nothing)', function () {
    // #2318 — trước đây test này `require` thẳng file migration. File đã bị
    // xoá; chạy lại chính seeder là phép đo tương đương và là đường thật.
    app(PaymentGatewayCatalogSeeder::class)->seedInternal();
    app(PaymentGatewayCatalogSeeder::class)->seedInternal();

    $cash = selfRegiInternalOption(PaymentGatewayCatalogSeeder::INTERNAL_CASH_OPTION_CODE);

    expect(array_count_values($cash->channels)['self_regi'] ?? 0)->toBe(1);
});

describe('kiosk effective-payment-options channel split', function () {
    beforeEach(function () {
        // Legacy tender identities so internal options resolve effective.
        PaymentMethod::factory()->create([
            'organization_id' => $this->fixtures->organization->id,
            'branch_id' => null,
            'code' => 'cash',
            'type' => 'cash',
            'is_active' => true,
            'is_auto_confirm' => true,
            'requires_tendered' => true,
        ]);
        PaymentMethod::factory()->create([
            'organization_id' => $this->fixtures->organization->id,
            'branch_id' => null,
            'code' => 'card_terminal',
            'type' => 'card_terminal',
            'is_active' => true,
            'is_auto_confirm' => true,
            'requires_tendered' => false,
        ]);
    });

    it('lists internal cash + card_terminal tenders for a self_regi device', function () {
        $device = $this->fixtures->seedDevice('self_regi');

        $options = $this->withHeaders(['Authorization' => 'Bearer '.$device->device_token])
            ->getJson('/api/v1/kiosk/effective-payment-options')
            ->assertOk()
            ->json('data.options');

        $byId = collect($options);
        $cash = $byId->firstWhere('id', selfRegiInternalOption(PaymentGatewayCatalogSeeder::INTERNAL_CASH_OPTION_CODE)->id);
        $terminal = $byId->firstWhere('id', selfRegiInternalOption(PaymentGatewayCatalogSeeder::INTERNAL_CARD_TERMINAL_OPTION_CODE)->id);

        expect($cash)->not->toBeNull()
            ->and($cash['source'])->toBe('internal_catalog')
            ->and($cash['effective'])->toBeTrue()
            ->and($cash['client']['requires_tendered'])->toBeTrue()
            ->and($terminal)->not->toBeNull()
            ->and($terminal['effective'])->toBeTrue();
    });

    it('gives an order-taking kiosk the internal tenders the CATALOG certifies for it — cash yes, card_terminal no', function () {
        /*
         * #1449 — this test used to assert the opposite ("kiosk stays
         * policy-only"). That rule lived in the controller as a hard-coded
         * `channel === SelfRegi`, and it contradicted the catalog, which lists
         * `kiosk` in `internal.cash.v1.channels`. Because an order-taking kiosk
         * has since moved entirely to this endpoint (plan-047 T6.2), the
         * contradiction left it with ZERO usable payment options on every
         * device and branch, and a disabled pay button.
         *
         * The catalog is now the only rule. Note what that buys: cash appears
         * for kiosk, card_terminal does NOT — not because of a second branch in
         * PHP, but because kiosk is absent from THAT option's `channels`. A
         * brand that wants no cash at its kiosks edits data, not code.
         */
        $device = $this->fixtures->seedDevice('kiosk');

        $options = $this->withHeaders(['Authorization' => 'Bearer '.$device->device_token])
            ->getJson('/api/v1/kiosk/effective-payment-options')
            ->assertOk()
            ->json('data.options');

        $byId = collect($options);
        $cash = $byId->firstWhere('id', selfRegiInternalOption(PaymentGatewayCatalogSeeder::INTERNAL_CASH_OPTION_CODE)->id);
        $terminal = $byId->firstWhere('id', selfRegiInternalOption(PaymentGatewayCatalogSeeder::INTERNAL_CARD_TERMINAL_OPTION_CODE)->id);

        expect($cash)->not->toBeNull()
            ->and($cash['source'])->toBe('internal_catalog')
            ->and($cash['effective'])->toBeTrue()
            ->and($terminal)->toBeNull();
    });

    it('the kiosk catalog gate is DATA, not a branch in PHP', function () {
        // Ghim đúng hai giá trị mà quyết định trên dựa vào. Nếu ai đó đổi
        // catalog, test này đỏ ở đây — chỗ giải thích được — thay vì đỏ ở một
        // assertion về response JSON, chỗ không nói lên nguyên nhân.
        $cash = selfRegiInternalOption(PaymentGatewayCatalogSeeder::INTERNAL_CASH_OPTION_CODE);
        $terminal = selfRegiInternalOption(PaymentGatewayCatalogSeeder::INTERNAL_CARD_TERMINAL_OPTION_CODE);

        expect($cash->channels)->toContain('kiosk')
            ->and($terminal->channels)->not->toContain('kiosk');
    });
});
