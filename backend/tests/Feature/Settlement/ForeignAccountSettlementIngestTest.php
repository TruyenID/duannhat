<?php

use App\Models\CustomerOrder;
use App\Models\PaymentAttempt;
use App\Models\PaymentSettlement;
use App\Services\Payment\ProviderEvent\StripeIntentOrigin;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use App\Services\Payment\Settlement\Stripe\StripeSettlementClient;
use App\Services\Payment\Settlement\Stripe\StripeSettlementRecorder;
use Tests\Fakes\Payment\FakeStripeSettlementClient;
use Tests\Support\Payment\SettlementTestFactory;

/**
 * #2864 — sổ đối soát của Tempo KHÔNG được nuốt doanh thu của trang đặt món
 * WooCommerce dùng chung tài khoản Stripe.
 *
 * Đo 2026-08-14 trên production: 202 hàng `status='orphan'`, ¥366.643 gross,
 * ¥13.194 phí, tăng ~35–50 hàng/ngày. Tra ngược 5 khoản mới nhất qua Stripe
 * API: 5/5 mang `partner: PaymentPlugins` và `metadata.order_id` dạng SỐ
 * (`"177370"`, `"177361"`…).
 *
 * Test này phải chứng minh CẢ HAI CHIỀU. Chiều "chặn" một mình là bằng chứng
 * rỗng: một bản vá chặn TẤT CẢ cũng làm nó xanh, trong khi đang nuốt tiền
 * thật. Chiều "vẫn ghi" mới là chiều đắt.
 */
it('không tạo hàng settlement cho intent của hệ khác — metadata.order_id là id SỐ của WooCommerce', function () {
    $connection = SettlementTestFactory::stripeConnection();
    $fake = new FakeStripeSettlementClient;
    app()->instance(StripeSettlementClient::class, $fake);

    $event = SettlementTestFactory::stripeEvent($connection, 'payment_intent.succeeded', [
        'intent_snapshot' => [
            'id' => 'pi_woo_177370',
            'latest_charge' => 'ch_woo_177370',
            'metadata' => ['order_id' => '177370', 'partner' => 'PaymentPlugins'],
        ],
    ], 'pi_woo_177370');

    expect(app(StripeSettlementRecorder::class)->applyProviderEvent($event))
        ->toBe('settlement_skipped_foreign_account')
        ->and(PaymentSettlement::query()->count())->toBe(0)
        // Chặn TRƯỚC khi gọi Stripe: 202 hàng/tháng cũng là 202 lượt gọi API thừa.
        ->and($fake->calls)->toBe([]);
});

it('không tạo hàng settlement cho hoàn tiền của hệ khác — charge thừa hưởng metadata của intent', function () {
    $connection = SettlementTestFactory::stripeConnection();
    $fake = new FakeStripeSettlementClient;
    app()->instance(StripeSettlementClient::class, $fake);

    $event = SettlementTestFactory::stripeEvent($connection, 'charge.refunded', [
        'charge_snapshot' => [
            'id' => 'ch_woo_177361',
            'payment_intent' => 'pi_woo_177361',
            'metadata' => ['order_id' => '177361', 'partner' => 'PaymentPlugins'],
            'refunds' => ['data' => [
                ['id' => 're_woo_001', 'balance_transaction' => 'txn_woo_refund_001'],
            ]],
        ],
    ], 'pi_woo_177361');

    expect(app(StripeSettlementRecorder::class)->applyProviderEvent($event))
        ->toBe('settlement_skipped_foreign_account')
        ->and(PaymentSettlement::query()->count())->toBe(0);
});

it('VẪN tạo hàng settlement cho tiền của Tempo — metadata.order_id là UUID customer_orders', function () {
    $connection = SettlementTestFactory::stripeConnection();
    $order = CustomerOrder::factory()->create();

    $attempt = PaymentAttempt::factory()->create([
        'connection_id' => $connection->id,
        'provider_object_id' => 'pi_tempo_2864',
        'provider' => 'stripe',
        'state' => 'succeeded',
        'currency' => 'JPY',
        'amount_minor' => 10_000,
        'finalized_at' => now(),
    ]);

    $fake = new FakeStripeSettlementClient;
    app()->instance(StripeSettlementClient::class, $fake);
    $fake->withCharge(['id' => 'ch_tempo_2864', 'balance_transaction' => 'txn_tempo_2864'])
        ->withBalanceTransaction([
            'id' => 'txn_tempo_2864', 'type' => 'charge', 'amount' => 10_000, 'fee' => 360,
            'net' => 9_640, 'currency' => 'jpy', 'created' => 1785000000, 'fee_details' => [],
        ]);

    $event = SettlementTestFactory::stripeEvent($connection, 'payment_intent.succeeded', [
        'intent_snapshot' => [
            'id' => 'pi_tempo_2864',
            'latest_charge' => 'ch_tempo_2864',
            'metadata' => ['order_id' => (string) $order->id],
        ],
    ], 'pi_tempo_2864');

    expect(app(StripeSettlementRecorder::class)->applyProviderEvent($event))
        ->toBe('settlement_payment_recorded');

    $row = PaymentSettlement::query()->where('external_ref', 'txn_tempo_2864')->firstOrFail();
    expect($row->gross_minor)->toBe(10_000)
        ->and($row->fee_minor)->toBe(360)
        ->and($row->net_minor)->toBe(9_640)
        ->and($row->payment_attempt_id)->toBe($attempt->id)
        ->and($row->status)->toBe(SettlementStatus::PendingPayout);
});

it('VẪN tạo hàng orphan khi snapshot không mang metadata.order_id — không chắc thì coi là của TA (S-19)', function () {
    $connection = SettlementTestFactory::stripeConnection();
    $fake = new FakeStripeSettlementClient;
    app()->instance(StripeSettlementClient::class, $fake);
    $fake->withCharge(['id' => 'ch_unknown_2864', 'balance_transaction' => 'txn_unknown_2864'])
        ->withBalanceTransaction([
            'id' => 'txn_unknown_2864', 'type' => 'charge', 'amount' => 3_000, 'fee' => 108,
            'net' => 2_892, 'currency' => 'jpy', 'created' => 1785000000, 'fee_details' => [],
        ]);

    // Không có attempt, không có order payment, và snapshot không có metadata:
    // đây đúng là hình dạng của một đơn bán offline đồng bộ muộn (#1092). Bỏ
    // sót một khoản của hệ khác là rác dọn được; bỏ sót một khoản của TA là
    // mất dấu tiền thật.
    $event = SettlementTestFactory::stripeEvent($connection, 'payment_intent.succeeded', [
        'intent_snapshot' => ['id' => 'pi_unknown_2864', 'latest_charge' => 'ch_unknown_2864'],
    ], 'pi_unknown_2864');

    expect(app(StripeSettlementRecorder::class)->applyProviderEvent($event))
        ->toBe('settlement_payment_recorded');

    $row = PaymentSettlement::query()->where('external_ref', 'txn_unknown_2864')->firstOrFail();
    expect($row->status)->toBe(SettlementStatus::Orphan)
        ->and($row->gross_minor)->toBe(3_000);
});

it('dùng CHUNG một phép phân biệt với tầng webhook (#2851) — không có bản chép thứ hai', function () {
    // Ba trạng thái, đúng như tầng webhook phân giải chúng.
    expect(StripeIntentOrigin::fromOrderIdMetadata('177370'))->toBe(StripeIntentOrigin::ForeignAccount)
        ->and(StripeIntentOrigin::fromOrderIdMetadata('9d3e5b7a-1f2c-4a8b-9c0d-1e2f3a4b5c6d'))->toBe(StripeIntentOrigin::Tempo)
        ->and(StripeIntentOrigin::fromOrderIdMetadata(null))->toBe(StripeIntentOrigin::Unknown)
        ->and(StripeIntentOrigin::fromOrderIdMetadata(''))->toBe(StripeIntentOrigin::Unknown)
        ->and(StripeIntentOrigin::fromOrderIdMetadata('  '))->toBe(StripeIntentOrigin::Unknown)
        // Stripe trả metadata dạng chuỗi, nhưng một snapshot đã decode có thể
        // mang số nguyên — vẫn là id của hệ khác.
        ->and(StripeIntentOrigin::fromOrderIdMetadata(177370))->toBe(StripeIntentOrigin::ForeignAccount);
});
