<?php

/**
 * Plan 048 Gate 1 (T1.1/T1.2/T1.5) — cloud-only POS internal tenders.
 *
 * Acceptance rows P048-A (plans/plan-048/TESTS.md):
 *   A1 cash auto-confirm via recordTender, tendered/change/ledger correct
 *   A2 card_terminal auto-confirm without provider call
 *   A3 kill switch OFF → legacy path still succeeds (rollback compat)
 *   A4 internal.cash.v1 present in catalog (migration-seeded, not just db:seed)
 */

use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentMethod;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Customer\OrderPaymentService;
use Database\Seeders\PaymentGatewayCatalogSeeder;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

uses()->group('payment', 'pos');

function plan048InternalOption(string $code): ?PaymentGatewayOption
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
});

it('A4: migration alone seeds the internal ledger catalog without db:seed', function () {
    // RefreshDatabase runs migrations only — no seeder. The rows must exist
    // because 2026_07_26_100000_seed_payment_gateway_catalog ran.
    $cash = plan048InternalOption(PaymentGatewayCatalogSeeder::INTERNAL_CASH_OPTION_CODE);
    $terminal = plan048InternalOption(PaymentGatewayCatalogSeeder::INTERNAL_CARD_TERMINAL_OPTION_CODE);

    expect($cash)->not->toBeNull()
        ->and($cash->rail instanceof BackedEnum ? $cash->rail->value : (string) $cash->rail)->toBe('cash')
        ->and((string) $cash->method_type)->toBe('cash')
        ->and($cash->channels)->toContain('pos')
        ->and($cash->operations)->toBe(['create', 'refund'])
        ->and($terminal)->not->toBeNull()
        ->and((string) $terminal->method_type)->toBe('card_terminal')
        ->and($terminal->channels)->not->toContain('kiosk');
});

it('A4: catalog seeder is idempotent — re-running converges on the same rows', function () {
    (new PaymentGatewayCatalogSeeder)->run();
    (new PaymentGatewayCatalogSeeder)->run();

    expect(
        PaymentGatewayOption::query()
            ->where('code', PaymentGatewayCatalogSeeder::INTERNAL_CASH_OPTION_CODE)
            ->count(),
    )->toBe(1)
        ->and(
            PaymentGatewayProvider::query()
                ->where('code', PaymentGatewayProviderCodeEnum::Internal->value)
                ->count(),
        )->toBe(1);
});

describe('T1.2 effective options bridge', function () {
    beforeEach(function () {
        $this->fixtures = new PaymentPolicyApiFixtures;
        $this->fixtures->bind();
    });

    it('lists internal cash as an effective POS option with legacy identity and no connection', function () {
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

        $options = $this->withHeaders([
            'Authorization' => 'Bearer '.$device->device_token,
            'X-Shop-Slug' => $this->fixtures->shop->slug,
        ])->getJson('/api/v1/pos/effective-payment-options')
            ->assertOk()
            ->json('data.options');

        $cash = collect($options)->firstWhere('id', plan048InternalOption(
            PaymentGatewayCatalogSeeder::INTERNAL_CASH_OPTION_CODE,
        )->id);

        expect($cash)->not->toBeNull()
            ->and($cash['provider'])->toBe('internal')
            ->and($cash['effective'])->toBeTrue()
            ->and($cash['source'])->toBe('internal_catalog')
            ->and($cash['connection_id'])->toBeNull()
            ->and($cash['legacy_payment_method_code'])->toBe('cash')
            ->and($cash['legacy_payment_method_id'])->not->toBeNull()
            ->and($cash['client']['requires_tendered'])->toBeTrue()
            ->and($cash['client']['immediate_settlement'])->toBeTrue()
            ->and($cash['client']['supports_pos_checkout'])->toBeTrue();
    });

    it('marks an internal option ineffective when its legacy payment method is missing', function () {
        // No card_terminal PaymentMethod seeded for this org.
        $device = $this->fixtures->seedDevice('pos');

        $options = $this->withHeaders([
            'Authorization' => 'Bearer '.$device->device_token,
            'X-Shop-Slug' => $this->fixtures->shop->slug,
        ])->getJson('/api/v1/pos/effective-payment-options')
            ->assertOk()
            ->json('data.options');

        $terminal = collect($options)->firstWhere('id', plan048InternalOption(
            PaymentGatewayCatalogSeeder::INTERNAL_CARD_TERMINAL_OPTION_CODE,
        )->id);

        expect($terminal)->not->toBeNull()
            ->and($terminal['effective'])->toBeFalse()
            ->and($terminal['reason'])->toBe('internal_tender_method_missing')
            ->and($terminal['client']['supports_pos_checkout'])->toBeFalse();
    });
});

describe('P048-A payment paths', function () {
    beforeEach(function () {
        $this->fixtures = new PaymentPolicyApiFixtures;
        $this->fixtures->bind();

        $this->operator = $this->fixtures->manager;

        config([
            'payments.orchestrator_runtime.enabled' => true,
            'payments.orchestrator_runtime.transports' => ['pos'],
            'payments.orchestrator_runtime.transport_switches' => ['pos' => true],
        ]);
    });

    function plan048Order(PaymentPolicyApiFixtures $fixtures, float $total): CustomerOrder
    {
        return CustomerOrder::factory()->create([
            'organization_id' => $fixtures->organization->id,
            'brand_id' => $fixtures->brand->id,
            'branch_id' => $fixtures->shop->id,
            'status' => 'checkout',
            'total_amount' => $total,
            'paid_amount' => 0,
        ]);
    }

    it('A1: cash records via orchestrator recordTender with change and without gateway identity', function () {
        $cash = $this->fixtures->seedCashPaymentMethod();
        $order = plan048Order($this->fixtures, 700.0);

        $payment = app(OrderPaymentService::class)->create([
            'customer_order_id' => $order->id,
            'payment_method_id' => $cash->id,
            'amount' => 700,
            'tendered_amount' => 1000,
            'received_by_id' => $this->operator->id,
            'organization_id' => $this->fixtures->organization->id,
            'brand_id' => $this->fixtures->brand->id,
            'branch_id' => $this->fixtures->shop->id,
            'orchestrator_transport' => 'pos',
            'idempotency_key' => 'plan048-a1-cash',
        ]);

        expect($payment->status->value)->toBe('succeeded')
            ->and((float) $payment->amount)->toBe(700.0)
            ->and((float) $payment->tendered_amount)->toBe(1000.0)
            ->and($payment->gateway_option_id)->toBeNull()
            ->and($payment->gateway_connection_id)->toBeNull()
            ->and((float) $order->fresh()->paid_amount)->toBe(700.0);
    });

    it('A2: card_terminal auto-confirms without any provider call', function () {
        Http::preventStrayRequests();

        $terminal = PaymentMethod::factory()->create([
            'organization_id' => $this->fixtures->organization->id,
            'branch_id' => $this->fixtures->shop->id,
            'code' => 'card_terminal',
            'type' => 'card_terminal',
            'is_active' => true,
            'is_auto_confirm' => true,
            'requires_tendered' => false,
        ]);
        $order = plan048Order($this->fixtures, 1200.0);

        $payment = app(OrderPaymentService::class)->create([
            'customer_order_id' => $order->id,
            'payment_method_id' => $terminal->id,
            'amount' => 1200,
            'received_by_id' => $this->operator->id,
            'organization_id' => $this->fixtures->organization->id,
            'brand_id' => $this->fixtures->brand->id,
            'branch_id' => $this->fixtures->shop->id,
            'orchestrator_transport' => 'pos',
            'idempotency_key' => 'plan048-a2-terminal',
        ]);

        expect($payment->status->value)->toBe('succeeded')
            ->and($payment->gateway_option_id)->toBeNull()
            ->and($payment->gateway_connection_id)->toBeNull()
            ->and((float) $order->fresh()->paid_amount)->toBe(1200.0);
    });

    it('#1111: rejects a gateway_connection_id belonging to another organization', function () {
        $cash = $this->fixtures->seedCashPaymentMethod();
        $order = plan048Order($this->fixtures, 400.0);

        // A connection in a DIFFERENT org — the exists:… request rule alone
        // would have accepted this uuid.
        $foreignOrg = Organization::factory()->create();
        $foreignBrand = Brand::factory()->create([
            'console_organization_id' => $foreignOrg->console_organization_id,
        ]);
        $foreignProvider = PaymentGatewayProvider::query()->firstOrCreate(
            ['code' => 'paypay'],
            ['is_active' => true, 'sort_order' => 99],
        );
        $foreignConnection = PaymentGatewayConnection::factory()->create([
            'provider_id' => $foreignProvider->id,
            'organization_id' => $foreignOrg->id,
            'brand_id' => $foreignBrand->id,
            'owner_branch_id' => null,
            'merchant_account_id' => 'foreign_org_connection',
            'is_active' => true,
        ]);

        expect(fn () => app(OrderPaymentService::class)->create([
            'customer_order_id' => $order->id,
            'payment_method_id' => $cash->id,
            'amount' => 400,
            'tendered_amount' => 400,
            'received_by_id' => $this->operator->id,
            'organization_id' => $this->fixtures->organization->id,
            'brand_id' => $this->fixtures->brand->id,
            'branch_id' => $this->fixtures->shop->id,
            'orchestrator_transport' => 'pos',
            'gateway_connection_id' => (string) $foreignConnection->id,
            'idempotency_key' => 'plan048-foreign-connection',
        ]))->toThrow(InvalidArgumentException::class, 'Gateway connection does not belong to this organization.');

        expect(OrderPayment::query()->where('customer_order_id', $order->id)->count())->toBe(0);
    });

    it('A3: pos transport kill switch OFF still succeeds through the legacy path', function () {
        config(['payments.orchestrator_runtime.transport_switches' => ['pos' => false]]);

        $cash = $this->fixtures->seedCashPaymentMethod();
        $order = plan048Order($this->fixtures, 500.0);

        $payment = app(OrderPaymentService::class)->create([
            'customer_order_id' => $order->id,
            'payment_method_id' => $cash->id,
            'amount' => 500,
            'tendered_amount' => 500,
            'received_by_id' => $this->operator->id,
            'organization_id' => $this->fixtures->organization->id,
            'brand_id' => $this->fixtures->brand->id,
            'branch_id' => $this->fixtures->shop->id,
            'orchestrator_transport' => 'pos',
            'idempotency_key' => 'plan048-a3-rollback',
        ]);

        expect($payment->status->value)->toBe('succeeded')
            ->and((float) $order->fresh()->paid_amount)->toBe(500.0);
    });
});
