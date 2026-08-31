<?php

/**
 * #2893 — `payments:migrate-stripe-attribution`.
 *
 * Chuyển quy thuộc 968 bản ghi tiền (đo trên production 2026-08-15: 747
 * settlement · 220 provider event · 1 payout) từ hàng connection tổng hợp sang
 * hàng THẬT, đóng dấu định danh PSP thật, rồi ngưng dùng hàng tổng hợp.
 *
 * Mọi test đi qua CHÍNH lệnh artisan chứ không gọi thẳng service: một lệnh có
 * service đúng mà vỏ CLI sai (cờ không truyền xuống, lỗi nuốt mất, exit code
 * 0 khi từ chối) vẫn hỏng đúng chỗ ops đứng — và test service-level sẽ xanh
 * suốt (án lệ #2622).
 */

use App\Models\Brand;
use App\Models\GatewayPayout;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentSettlement;
use App\Services\Payment\ProviderEvent\LegacyGlobalStripeConnection;
use Illuminate\Support\Str;
use Tests\Support\Payment\SettlementTestFactory;

uses()->group('payment');

const MIGRATION_PLATFORM_ACCOUNT = 'acct_2893MigrationTarget';

beforeEach(function () {
    config([
        'services.stripe.secret' => 'sk_test_dummy_secret_for_tests',
        'services.stripe.account_id' => MIGRATION_PLATFORM_ACCOUNT,
    ]);

    $this->retired = app(LegacyGlobalStripeConnection::class)->resolveModel();

    // Tổ chức/brand THẬT, khai đích danh: `PaymentGatewayConnectionFactory` bốc
    // ngẫu nhiên một org đang có, và org tổng hợp vừa được dựng ở dòng trên
    // cũng nằm trong số đó — bốc trúng nó thì test "đổi chủ" tự nó vô nghĩa.
    $organization = Organization::factory()->create();
    $brand = Brand::factory()->create(['console_organization_id' => $organization->console_organization_id]);

    $this->target = PaymentGatewayConnection::factory()->create([
        'provider_id' => SettlementTestFactory::provider('stripe')->id,
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'owner_branch_id' => null,
        'owner_scope' => 'hq',
        'environment' => 'test',
        // Nhãn NỘI BỘ — đúng thứ production đang mang, và đúng lý do đối soát
        // payout mơ hồ khi tài khoản Stripe dùng chung (#2864).
        'merchant_account_id' => 'orchestrator:customer-web:'.Str::uuid(),
        'health' => 'ready',
        'is_active' => true,
    ]);

    $this->seedMoneyOn = function (PaymentGatewayConnection $connection, int $settlements = 3, int $events = 2, int $payouts = 1): void {
        PaymentSettlement::factory()->count($settlements)->create(['connection_id' => $connection->id]);
        GatewayPayout::factory()->count($payouts)->create(['connection_id' => $connection->id]);

        for ($i = 0; $i < $events; $i++) {
            SettlementTestFactory::stripeEvent($connection, 'payment_intent.succeeded');
        }
    };
});

it('dry-run reports what WOULD move and writes nothing', function () {
    ($this->seedMoneyOn)($this->retired);

    $this->artisan('payments:migrate-stripe-attribution')
        ->assertExitCode(0)
        ->expectsOutputToContain('DRY-RUN');

    expect(PaymentSettlement::query()->where('connection_id', $this->retired->id)->count())->toBe(3)
        ->and(PaymentProviderEvent::query()->where('connection_id', $this->retired->id)->count())->toBe(2)
        ->and(GatewayPayout::query()->where('connection_id', $this->retired->id)->count())->toBe(1)
        ->and((bool) $this->retired->fresh()->is_active)->toBeTrue()
        ->and((string) $this->target->fresh()->merchant_account_id)->toStartWith('orchestrator:customer-web:');
});

it('--apply moves all three money tables onto the real connection and stamps the real PSP identity', function () {
    ($this->seedMoneyOn)($this->retired);

    $this->artisan('payments:migrate-stripe-attribution --apply')->assertExitCode(0);

    expect(PaymentSettlement::query()->where('connection_id', $this->retired->id)->count())->toBe(0)
        ->and(PaymentSettlement::query()->where('connection_id', $this->target->id)->count())->toBe(3)
        ->and(PaymentProviderEvent::query()->where('connection_id', $this->target->id)->count())->toBe(2)
        ->and(GatewayPayout::query()->where('connection_id', $this->target->id)->count())->toBe(1)
        ->and((string) $this->target->fresh()->merchant_account_id)->toBe(MIGRATION_PLATFORM_ACCOUNT);
});

it('moves the inbox rows ORGANIZATION too — a provider event that changed owner cannot keep the old org', function () {
    SettlementTestFactory::stripeEvent($this->retired, 'payment_intent.succeeded');

    $this->artisan('payments:migrate-stripe-attribution --apply')->assertExitCode(0);

    $event = PaymentProviderEvent::query()->where('connection_id', $this->target->id)->firstOrFail();

    expect((string) $event->organization_id)->toBe((string) $this->target->organization_id)
        ->and((string) $event->organization_id)->not->toBe(LegacyGlobalStripeConnection::ORGANIZATION_ID);
});

it('retires the synthetic connection instead of deleting it — it is the historical owner of money records', function () {
    $this->artisan('payments:migrate-stripe-attribution --apply')->assertExitCode(0);

    $row = PaymentGatewayConnection::query()->find(LegacyGlobalStripeConnection::CONNECTION_ID);

    expect($row)->not->toBeNull()
        ->and((bool) $row->is_active)->toBeFalse();
});

it('NEVER touches order_payments — that is the ledger, not the reconciliation book', function () {
    $payment = OrderPayment::factory()->create(['gateway_connection_id' => $this->retired->id]);

    $this->artisan('payments:migrate-stripe-attribution --apply')->assertExitCode(0);

    expect((string) $payment->fresh()->gateway_connection_id)->toBe((string) $this->retired->id);
});

it('is idempotent — a second --apply moves nothing and changes nothing', function () {
    ($this->seedMoneyOn)($this->retired);

    $this->artisan('payments:migrate-stripe-attribution --apply')->assertExitCode(0);

    $snapshot = [
        'target_settlements' => PaymentSettlement::query()->where('connection_id', $this->target->id)->count(),
        'target_events' => PaymentProviderEvent::query()->where('connection_id', $this->target->id)->count(),
        'target_payouts' => GatewayPayout::query()->where('connection_id', $this->target->id)->count(),
        'merchant' => (string) $this->target->fresh()->merchant_account_id,
    ];

    $this->artisan('payments:migrate-stripe-attribution --apply')->assertExitCode(0);

    expect(PaymentSettlement::query()->where('connection_id', $this->target->id)->count())->toBe($snapshot['target_settlements'])
        ->and(PaymentProviderEvent::query()->where('connection_id', $this->target->id)->count())->toBe($snapshot['target_events'])
        ->and(GatewayPayout::query()->where('connection_id', $this->target->id)->count())->toBe($snapshot['target_payouts'])
        ->and((string) $this->target->fresh()->merchant_account_id)->toBe($snapshot['merchant'])
        ->and(PaymentSettlement::query()->where('connection_id', $this->retired->id)->count())->toBe(0);
});

it('leaves rows that ALREADY sit on the real connection alone', function () {
    $alreadyCorrect = PaymentSettlement::factory()->create([
        'connection_id' => $this->target->id,
        'external_ref' => 'txn_already_correct',
    ]);
    ($this->seedMoneyOn)($this->retired, settlements: 2, events: 0, payouts: 0);

    $this->artisan('payments:migrate-stripe-attribution --apply')->assertExitCode(0);

    expect((string) $alreadyCorrect->fresh()->connection_id)->toBe((string) $this->target->id)
        ->and($alreadyCorrect->fresh()->updated_at->equalTo($alreadyCorrect->updated_at))->toBeTrue()
        ->and(PaymentSettlement::query()->where('connection_id', $this->target->id)->count())->toBe(3);
});

it('refuses --apply when STRIPE_ACCOUNT_ID is not configured, and writes nothing', function () {
    config(['services.stripe.account_id' => null]);
    ($this->seedMoneyOn)($this->retired, settlements: 2, events: 0, payouts: 0);

    $this->artisan('payments:migrate-stripe-attribution --apply')
        ->assertExitCode(1)
        ->expectsOutputToContain('STRIPE_ACCOUNT_ID');

    expect(PaymentSettlement::query()->where('connection_id', $this->retired->id)->count())->toBe(2)
        ->and((bool) $this->retired->fresh()->is_active)->toBeTrue();
});

it('refuses when the destination cannot be resolved uniquely — picking for the operator is picking whose money moves', function () {
    PaymentGatewayConnection::factory()->create([
        'provider_id' => SettlementTestFactory::provider('stripe')->id,
        'owner_branch_id' => null,
        'environment' => 'test',
        'merchant_account_id' => 'orchestrator:customer-web:'.Str::uuid(),
        'is_active' => true,
    ]);
    ($this->seedMoneyOn)($this->retired, settlements: 1, events: 0, payouts: 0);

    $this->artisan('payments:migrate-stripe-attribution --apply')
        ->assertExitCode(1)
        ->expectsOutputToContain('--to=');

    expect(PaymentSettlement::query()->where('connection_id', $this->retired->id)->count())->toBe(1);
});

it('accepts an explicit --to even when several connections exist', function () {
    PaymentGatewayConnection::factory()->create([
        'provider_id' => SettlementTestFactory::provider('stripe')->id,
        'owner_branch_id' => null,
        'environment' => 'test',
        'merchant_account_id' => 'orchestrator:customer-web:'.Str::uuid(),
        'is_active' => true,
    ]);
    ($this->seedMoneyOn)($this->retired, settlements: 1, events: 0, payouts: 0);

    $this->artisan('payments:migrate-stripe-attribution --apply --to='.$this->target->id)->assertExitCode(0);

    expect(PaymentSettlement::query()->where('connection_id', $this->target->id)->count())->toBe(1);
});

it('refuses to overwrite a destination that already names a DIFFERENT Stripe account', function () {
    $this->target->update(['merchant_account_id' => 'acct_someRealConnectedMerchant']);
    ($this->seedMoneyOn)($this->retired, settlements: 1, events: 0, payouts: 0);

    $this->artisan('payments:migrate-stripe-attribution --apply')
        ->assertExitCode(1)
        ->expectsOutputToContain('acct_someRealConnectedMerchant');

    expect(PaymentSettlement::query()->where('connection_id', $this->retired->id)->count())->toBe(1)
        ->and((string) $this->target->fresh()->merchant_account_id)->toBe('acct_someRealConnectedMerchant');
});

it('leaves behind — and counts — an inbox row whose provider_event_id already exists on the destination', function () {
    $duplicate = SettlementTestFactory::stripeEvent($this->retired, 'payment_intent.succeeded');
    SettlementTestFactory::stripeEvent($this->target, 'payment_intent.succeeded')
        ->update(['provider_event_id' => $duplicate->provider_event_id]);
    SettlementTestFactory::stripeEvent($this->retired, 'charge.refunded');

    $this->artisan('payments:migrate-stripe-attribution --apply')
        ->assertExitCode(0)
        ->expectsOutputToContain('1 provider event ở lại chỗ cũ');

    expect((string) $duplicate->fresh()->connection_id)->toBe((string) $this->retired->id)
        ->and(PaymentProviderEvent::query()->where('connection_id', $this->retired->id)->count())->toBe(1)
        ->and(PaymentProviderEvent::query()->where('connection_id', $this->target->id)->count())->toBe(2);
});
