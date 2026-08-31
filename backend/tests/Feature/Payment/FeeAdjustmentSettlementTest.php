<?php

declare(strict_types=1);

/**
 * #2981 — điều chỉnh PHÍ của Stripe không được nằm mãi trong hàng đợi `orphan`.
 *
 * ## Vì sao issue này tồn tại
 *
 * Đo trên production 2026-08-16, đúng MỘT dòng orphan còn lại:
 *
 *     txn_1U1OVOCUZcB5vP8By7q0Y9gM   ¥178
 *     type=adjustment  reporting_category=fee  source=NULL
 *     "JCT adjustment for invoice number ZCB5VP8B-2026-07."
 *
 * JCT là 消費税 trên hoá đơn phí **Stripe gửi cho merchant**. Không có đơn hàng
 * nào ở bất kỳ đầu nào, nên không payment nào sẽ tới nhận nó — mà `orphan`
 * được giữ mãi CHÍNH VÌ giả định ngược lại (S-05/S-19).
 *
 * Nó nằm im được suốt nhiều đêm chỉ vì một lỗi KHÁC: cảnh báo
 * `settlement.orphan_overdue` gom theo connection, và dòng này còn trỏ
 * connection Stripe toàn cục đã nghỉ hưu (#2893) — org của connection ấy cố ý
 * không có thành viên nào, nên cảnh báo hỏng 6 đêm liền với
 * "requires at least one recipient". Backfill #2893 nối nó về connection thật,
 * và connection thật CÓ 4 người nhận. Tức là bản vá kia biến một cảnh báo chết
 * thành một cảnh báo kêu vào mặt bốn người, mỗi đêm, về ¥178 không ai làm gì
 * được. Một kênh cảnh báo TIỀN kêu oan hàng đêm sẽ bị tắt, rồi lần kêu thật
 * không ai đọc.
 *
 * ## Hai chiều, và chiều thứ hai mới là chiều giữ được lòng tin
 *
 * Bài này phải chứng minh cả "nhận ra đúng loại" lẫn "KHÔNG đoán khi chưa
 * chắc". `ForeignSettlementMarker` là lớp ghi vào SỔ TIỀN: đánh dấu nhầm một
 * hàng của Tempo là giấu mất một khoản tiền thật khỏi đối soát, và cái đó tệ
 * hơn hẳn việc bỏ sót một dòng rác.
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentSettlement;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use App\Services\Payment\Settlement\ForeignSettlementMarker;
use App\Services\Payment\Settlement\Stripe\StripeSettlementClient;
use Tests\Fakes\Payment\FakeStripeSettlementClient;

beforeEach(function () {
    $org = Organization::factory()->create();
    $brand = Brand::factory()->create(['console_organization_id' => $org->console_organization_id]);

    $this->connection = PaymentGatewayConnection::factory()->create([
        'organization_id' => $org->id,
        'brand_id' => $brand->id,
    ]);

    $this->stripe = new FakeStripeSettlementClient;
    $this->app->instance(StripeSettlementClient::class, $this->stripe);

    // Dòng đúng hình dạng production: ingest từ payout listing, KHÔNG có
    // `provider_object_id` vì một adjustment không gắn với PaymentIntent nào.
    $this->adjustment = fn (string $ref = 'txn_fee_1') => PaymentSettlement::factory()->orphan()->create([
        'connection_id' => $this->connection->id,
        'external_ref' => $ref,
        'gross_minor' => 178,
        'fee_minor' => 0,
        'metadata' => ['raw_type' => 'adjustment', 'backfilled_from_payout_listing' => true],
    ]);
});

it('#2981 đánh dấu fee_adjustment cho dòng adjustment mà Stripe khai reporting_category=fee', function () {
    $row = ($this->adjustment)();

    $this->stripe->withBalanceTransaction([
        'id' => 'txn_fee_1',
        'type' => 'adjustment',
        'reporting_category' => 'fee',
        'amount' => 178,
        'currency' => 'jpy',
        'source' => null,
    ]);

    $result = app(ForeignSettlementMarker::class)->sweep(null, 50, apply: true);

    expect($result['fee_adjustment'])->toBe(1)
        ->and($result['foreign'])->toBe(0)
        ->and($result['unknown'])->toBe(0);

    expect($row->refresh()->status)->toBe(SettlementStatus::FeeAdjustment);
});

it('#2981 dry-run KHÔNG ghi — báo cáo trước, sửa sổ tiền sau', function () {
    $row = ($this->adjustment)();

    $this->stripe->withBalanceTransaction([
        'id' => 'txn_fee_1',
        'type' => 'adjustment',
        'reporting_category' => 'fee',
        'amount' => 178,
        'currency' => 'jpy',
    ]);

    $result = app(ForeignSettlementMarker::class)->sweep(null, 50, apply: false);

    expect($result['fee_adjustment'])->toBe(1)
        ->and($row->refresh()->status)->toBe(SettlementStatus::Orphan);
});

it('#2981 reporting_category KHÁC `fee` thì giữ nguyên orphan — không đoán', function () {
    // `charge` là tiền hàng thật. Một dòng như thế mà bị nuốt vào
    // `fee_adjustment` là giấu tiền của quán khỏi đối soát.
    $row = ($this->adjustment)();

    $this->stripe->withBalanceTransaction([
        'id' => 'txn_fee_1',
        'type' => 'charge',
        'reporting_category' => 'charge',
        'amount' => 5_000,
        'currency' => 'jpy',
    ]);

    $result = app(ForeignSettlementMarker::class)->sweep(null, 50, apply: true);

    expect($result['fee_adjustment'])->toBe(0)
        ->and($result['unknown'])->toBe(1)
        ->and($row->refresh()->status)->toBe(SettlementStatus::Orphan);
});

it('#2981 Stripe gọi hỏng KHÔNG biến thành một kết luận', function () {
    // Fake ném khi không khai balance transaction — đúng ca "mạng hỏng / khoá
    // sai / Stripe 500". Sổ tiền phải đứng yên.
    $row = ($this->adjustment)();

    $result = app(ForeignSettlementMarker::class)->sweep(null, 50, apply: true);

    expect($result['errors'])->toBe(1)
        ->and($result['fee_adjustment'])->toBe(0)
        ->and($row->refresh()->status)->toBe(SettlementStatus::Orphan);
});

it('#2981 external_ref không phải balance transaction thì không hỏi Stripe', function () {
    // Hàng ingest đường khác có thể mang ref dạng `po_`/`ch_`. Không có gì để
    // hỏi bằng API này, nên đừng gọi — và tuyệt đối đừng đoán.
    $row = ($this->adjustment)('po_not_a_txn');

    $result = app(ForeignSettlementMarker::class)->sweep(null, 50, apply: true);

    expect($result['unknown'])->toBe(1)
        ->and($result['errors'])->toBe(0)
        ->and($this->stripe->calls['retrieveBalanceTransaction'] ?? 0)->toBe(0)
        ->and($row->refresh()->status)->toBe(SettlementStatus::Orphan);
});

it('#2981 command in đúng balance transaction và trạng thái mới', function () {
    $row = ($this->adjustment)();

    $this->stripe->withBalanceTransaction([
        'id' => 'txn_fee_1',
        'type' => 'adjustment',
        'reporting_category' => 'fee',
        'amount' => 178,
        'currency' => 'jpy',
    ]);

    $this->artisan('settlements:mark-foreign', ['--apply' => true])
        ->expectsOutputToContain('txn_fee_1')
        ->expectsOutputToContain('fee_adjustment')
        ->assertSuccessful();

    expect($row->refresh()->status)->toBe(SettlementStatus::FeeAdjustment);
});

it('#2981 dòng fee_adjustment rời khỏi hàng đợi cảnh báo orphan', function () {
    // Đây là điểm của cả issue: sau khi phân loại, cảnh báo hàng đêm không còn
    // nhìn thấy nó nữa. Đo bằng chính truy vấn mà `openOrphans()` dùng.
    $row = ($this->adjustment)();

    $this->stripe->withBalanceTransaction([
        'id' => 'txn_fee_1',
        'type' => 'adjustment',
        'reporting_category' => 'fee',
        'amount' => 178,
        'currency' => 'jpy',
    ]);

    expect(PaymentSettlement::query()->where('status', SettlementStatus::Orphan->value)->count())->toBe(1);

    app(ForeignSettlementMarker::class)->sweep(null, 50, apply: true);

    expect(PaymentSettlement::query()->where('status', SettlementStatus::Orphan->value)->count())->toBe(0)
        ->and($row->refresh()->status)->toBe(SettlementStatus::FeeAdjustment);
});
