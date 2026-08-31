<?php

use App\Models\PaymentMethod;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

uses()->group('payment', 'pos');

beforeEach(function () {
    $this->fixtures = new PaymentPolicyApiFixtures;
    $this->fixtures->bind();
});

it('pos effective-payment-options returns enriched client capabilities without secrets', function () {
    $this->fixtures->seedConnection();
    PaymentMethod::factory()->create([
        'organization_id' => $this->fixtures->organization->id,
        'branch_id' => null,
        'code' => 'cash',
        'type' => 'cash',
        'is_active' => true,
        'is_auto_confirm' => true,
        'requires_tendered' => true,
    ]);

    $device = $this->fixtures->seedDevice('pos');

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$device->device_token,
        'X-Shop-Slug' => $this->fixtures->shop->slug,
    ])->getJson('/api/v1/pos/effective-payment-options')
        ->assertOk()
        ->json('data');

    expect($response)->toHaveKeys(['revision', 'snapshot_hash', 'ownership_revision', 'options'])
        ->and($response['options'])->not->toBeEmpty();

    $option = collect($response['options'])->firstWhere('effective', true);

    expect($option)->toHaveKeys([
        'id', 'display_name', 'provider', 'rail', 'method_type', 'effective',
        'client', 'legacy_payment_method_id', 'legacy_payment_method_code',
        'connection_id', 'shop_option_id',
    ])
        ->and($option['client'])->toHaveKeys([
            'requires_tendered', 'immediate_settlement', 'supports_pos_checkout',
        ])
        ->and($option)->not->toHaveKey('api_key')
        ->and($option)->not->toHaveKey('secret');
});

it('pos effective-payment-options marks non-immediate options as unsupported for checkout', function () {
    $this->fixtures->seedConnection();
    PaymentMethod::factory()->create([
        'organization_id' => $this->fixtures->organization->id,
        'branch_id' => null,
        'code' => 'transfer',
        'type' => 'transfer',
        'is_active' => true,
        'is_auto_confirm' => false,
        'requires_tendered' => false,
    ]);

    $device = $this->fixtures->seedDevice('pos');

    $options = $this->withHeaders([
        'Authorization' => 'Bearer '.$device->device_token,
        'X-Shop-Slug' => $this->fixtures->shop->slug,
    ])->getJson('/api/v1/pos/effective-payment-options')
        ->assertOk()
        ->json('data.options');

    $transferLike = collect($options)->first(
        fn (array $row): bool => ($row['legacy_payment_method_code'] ?? null) === 'transfer',
    );

    if ($transferLike !== null) {
        expect($transferLike['client']['immediate_settlement'])->toBeFalse()
            ->and($transferLike['client']['supports_pos_checkout'])->toBeFalse();
    }
});
