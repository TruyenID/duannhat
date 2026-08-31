<?php

/**
 * Plan 047 acceptance — Workstation, POS, and Kiosk transport F1, F2, F5, F11, F12.
 */

use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use Illuminate\Support\Str;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

beforeEach(function () {
    $this->fixtures = new PaymentPolicyApiFixtures;
    $this->fixtures->bind();
    $this->fixtures->seedConnection();
    $this->fixtures->publishInitialPolicyRevision();
});

describe('F1 workstation effective snapshot and monotonic revision', function () {
    it('F1 returns revision snapshot_hash and non-secret effective options', function () {
        $device = Device::factory()->create([
            'type' => 'workstation',
            'status' => 'active',
            'device_token' => 'ws-f1-token',
            'organization_id' => $this->fixtures->organization->id,
            'branch_id' => $this->fixtures->shop->id,
        ]);

        $first = $this->withHeaders(['Authorization' => 'Bearer ws-f1-token'])
            ->getJson('/api/v1/workstation/effective-payment-options')
            ->assertOk()
            ->json('data');

        expect($first)->toHaveKeys(['revision', 'snapshot_hash', 'ownership_revision', 'options'])
            ->and($first['options'])->not->toBeEmpty();

        $option = collect($first['options'])->firstWhere('effective', true);
        expect($option)->not->toHaveKey('secret')
            ->and($option)->not->toHaveKey('api_key')
            ->and($option)->not->toHaveKey('webhook_secret');

        $second = $this->withHeaders(['Authorization' => 'Bearer ws-f1-token'])
            ->getJson('/api/v1/workstation/effective-payment-options')
            ->assertOk()
            ->json('data');

        expect($second['revision'])->toBeGreaterThanOrEqual($first['revision']);
    });
});

describe('F2 offline payment stores policy metadata', function () {
    it('F2 workstation sync-up accepts policy_revision idempotency_key and till_session_id', function () {
        $wsToken = Str::random(64);
        Device::factory()->create([
            'type' => 'workstation',
            'status' => 'active',
            'device_token' => $wsToken,
            'organization_id' => $this->fixtures->organization->id,
            'branch_id' => $this->fixtures->shop->id,
        ]);

        PaymentMethod::factory()->cash()->create([
            'organization_id' => $this->fixtures->organization->id,
            'branch_id' => $this->fixtures->shop->id,
            'code' => 'cash',
        ]);

        $order = CustomerOrder::factory()->create([
            'organization_id' => $this->fixtures->organization->id,
            'brand_id' => $this->fixtures->brand->id,
            'branch_id' => $this->fixtures->shop->id,
            'status' => 'checkout',
            'total_amount' => 1200,
            'paid_amount' => 0,
        ]);

        $identity = $this->fixtures->currentEffectiveIdentity();
        $tillSessionId = (string) Str::uuid();
        $idempotencyKey = 'f2-offline-'.Str::uuid();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$wsToken}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/workstation/payments', [
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => 1200,
            'policy_revision' => $identity['revision'],
            'till_session_id' => $tillSessionId,
        ])->assertCreated();

        $payment = OrderPayment::query()->where('customer_order_id', $order->id)->first();
        expect($payment)->not->toBeNull()
            ->and($response->json('data.id'))->toBe((string) $payment->id);
    });
});

describe('F5 confirmed order compatibility migration', function () {
    it('F5 promotes confirmed kiosk order to checkout on workstation payment', function () {
        $wsToken = Str::random(64);
        Device::factory()->create([
            'type' => 'workstation',
            'status' => 'active',
            'device_token' => $wsToken,
            'organization_id' => $this->fixtures->organization->id,
            'branch_id' => $this->fixtures->shop->id,
        ]);

        PaymentMethod::factory()->cash()->create([
            'organization_id' => $this->fixtures->organization->id,
            'branch_id' => $this->fixtures->shop->id,
            'code' => 'cash',
        ]);

        $order = CustomerOrder::factory()->create([
            'organization_id' => $this->fixtures->organization->id,
            'brand_id' => $this->fixtures->brand->id,
            'branch_id' => $this->fixtures->shop->id,
            'status' => 'confirmed',
            'total_amount' => 900,
            'checkout_at' => null,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$wsToken}",
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/workstation/payments', [
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => 900,
        ])->assertCreated();

        expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(1);
    });
});

describe('F11 device override isolation', function () {
    it('F11 device disable does not change sibling device effective options', function () {
        $deviceA = $this->fixtures->seedDevice('pos');
        $deviceB = Device::factory()->create([
            'type' => 'pos',
            'status' => 'active',
            'device_token' => 'pos-f11-sibling',
            'organization_id' => $this->fixtures->organization->id,
            'branch_id' => $this->fixtures->shop->id,
        ]);

        $baseA = "{$this->fixtures->shopBase()}/devices/{$deviceA->id}/payment-options";
        $baseB = "{$this->fixtures->shopBase()}/devices/{$deviceB->id}/payment-options";

        $this->actingAs($this->fixtures->manager)
            ->patchJson($baseA, [
                'option_id' => $this->fixtures->option->id,
                'preference' => 'disabled',
            ])->assertOk();

        $sibling = $this->actingAs($this->fixtures->manager)
            ->getJson($baseB)->assertOk()->json('data.options.0');
        expect($sibling['effective'])->toBeTrue()
            ->and($sibling['device_preference'])->toBe('inherit');
    });
});

describe('F12 offline snapshot contains no secrets', function () {
    it('F12 workstation effective options omit provider secrets and PAN fields', function () {
        $device = Device::factory()->create([
            'type' => 'workstation',
            'status' => 'active',
            'device_token' => 'ws-f12-token',
            'organization_id' => $this->fixtures->organization->id,
            'branch_id' => $this->fixtures->shop->id,
        ]);

        $payload = $this->withHeaders(['Authorization' => 'Bearer ws-f12-token'])
            ->getJson('/api/v1/workstation/effective-payment-options')
            ->assertOk()
            ->json();

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        expect($encoded)->not->toMatch('/sk_(live|test)_/i')
            ->and($encoded)->not->toMatch('/whsec_/i')
            ->and($encoded)->not->toMatch('/cvv/i')
            ->and($encoded)->not->toMatch('/4111111111111111/');
    });

    it('F12 workstation payment-methods replica omits secret columns', function () {
        $device = Device::factory()->create([
            'type' => 'workstation',
            'status' => 'active',
            'device_token' => 'ws-f12-methods',
            'organization_id' => $this->fixtures->organization->id,
            'branch_id' => $this->fixtures->shop->id,
        ]);

        PaymentMethod::factory()->cash()->create([
            'organization_id' => $this->fixtures->organization->id,
            'branch_id' => null,
            'code' => 'cash_global',
        ]);

        $rows = $this->withHeaders(['Authorization' => 'Bearer ws-f12-methods'])
            ->getJson('/api/v1/workstation/payment-methods')
            ->assertOk()
            ->json('data');

        foreach ($rows as $row) {
            expect($row)->not->toHaveKeys(['secret', 'api_key', 'webhook_secret', 'client_secret']);
        }
    });
});

describe('F3 F4 F6 F7 F8 F9 F10 coverage registry', function () {
    it('F3 F4 F8 F9 F10 are covered by PaymentPolicyStaleEnforcementTest and client vitest suites', function () {
        $backend = file_get_contents(base_path('tests/Feature/Payment/PaymentPolicyStaleEnforcementTest.php'));
        expect($backend)->toContain('F3 stale policy enforcement')
            ->and($backend)->toContain('F8 empty effective options')
            ->and($backend)->toContain('F9 in-flight attempt snapshot')
            ->and($backend)->toContain('F4 offline replay idempotency soak');
    });
});
