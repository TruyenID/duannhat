<?php

declare(strict_types=1);

/**
 * #2864 phần 2 — đánh dấu hàng settlement của merchant KHÁC, và **chỉ** hàng đó.
 *
 * Chiều "phải đánh dấu" là chiều dễ; chiều quyết định là **phải CHỪA**: một
 * hàng của Tempo bị đánh nhầm thành `foreign` sẽ biến mất khỏi
 * `settlement.orphan_overdue`, tức một khoản tiền thật chưa khớp về đơn bị
 * giấu khỏi đối soát. Bỏ sót một hàng của hệ khác thì chỉ là rác trong sổ.
 *
 * Vì thế lệnh chỉ đánh dấu `ForeignAccount`; `Unknown` và mọi lượt gọi Stripe
 * hỏng đều để nguyên `orphan`.
 */

use App\Models\PaymentSettlement;
use App\Services\Customer\StripePaymentService;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;

beforeEach(function () {
    $this->connectionId = (string) Str::uuid();

    $this->orphan = function (string $intentId, int $gross = 1700, int $fee = 61): PaymentSettlement {
        return PaymentSettlement::factory()->create([
            'connection_id' => $this->connectionId,
            'provider' => 'stripe',
            'kind' => 'payment',
            'status' => SettlementStatus::Orphan->value,
            'gross_minor' => $gross,
            'fee_minor' => $fee,
            'currency' => 'JPY',
            'external_ref' => 'txn_'.Str::random(12),
            'metadata' => ['provider_object_id' => $intentId, 'charge_id' => 'ch_'.Str::random(12)],
        ]);
    };

    // Bản giả trả metadata theo id — đúng hình dạng đo được trên production.
    $this->fakeStripe = function (array $byIntentId): void {
        $fake = Mockery::mock(StripePaymentService::class);
        $fake->shouldReceive('retrieveIntent')->andReturnUsing(function (string $id) use ($byIntentId) {
            if (! array_key_exists($id, $byIntentId)) {
                throw new RuntimeException("Stripe không trả lời cho {$id}");
            }

            return PaymentIntent::constructFrom(['id' => $id, 'metadata' => $byIntentId[$id]]);
        });
        app()->instance(StripePaymentService::class, $fake);
    };
});

// =========================================================================
//  PHẢI ĐÁNH DẤU
// =========================================================================

it('#2864 đánh dấu hàng của WooCommerce — order_id dạng SỐ', function () {
    // Hình dạng thật: partner PaymentPlugins, order_id là id số của WooCommerce.
    $row = ($this->orphan)('pi_foreign_1');
    ($this->fakeStripe)(['pi_foreign_1' => ['order_id' => '177370', 'partner' => 'PaymentPlugins']]);

    $this->artisan('settlements:mark-foreign', ['--apply' => true])->assertSuccessful();

    expect($row->fresh()->status)->toBe(SettlementStatus::Foreign);
});

it('#2864 dry-run là mặc định — không truyền --apply thì KHÔNG ghi', function () {
    $row = ($this->orphan)('pi_foreign_2');
    ($this->fakeStripe)(['pi_foreign_2' => ['order_id' => '177361', 'partner' => 'PaymentPlugins']]);

    $this->artisan('settlements:mark-foreign')->assertSuccessful();

    expect($row->fresh()->status)->toBe(SettlementStatus::Orphan);
});

// =========================================================================
//  PHẢI CHỪA — chiều quyết định
// =========================================================================

it('#2864 KHÔNG đụng hàng của Tempo — order_id là UUID', function () {
    $row = ($this->orphan)('pi_tempo_1');
    ($this->fakeStripe)(['pi_tempo_1' => ['order_id' => (string) Str::uuid(), 'order_code' => 'ORD-2026-0018']]);

    $this->artisan('settlements:mark-foreign', ['--apply' => true])->assertSuccessful();

    // Đây là tiền THẬT chưa khớp về đơn. Đánh dấu foreign là giấu nó đi.
    expect($row->fresh()->status)->toBe(SettlementStatus::Orphan);
});

it('#2864 intent KHÔNG có order_id thì giữ nguyên orphan, không đoán', function () {
    $row = ($this->orphan)('pi_unknown_1');
    ($this->fakeStripe)(['pi_unknown_1' => []]);

    $this->artisan('settlements:mark-foreign', ['--apply' => true])->assertSuccessful();

    expect($row->fresh()->status)->toBe(SettlementStatus::Orphan);
});

it('#2864 gọi Stripe hỏng KHÔNG biến thành một kết luận', function () {
    $row = ($this->orphan)('pi_boom');
    ($this->fakeStripe)([]); // mọi id đều ném

    $this->artisan('settlements:mark-foreign', ['--apply' => true])->assertSuccessful();

    expect($row->fresh()->status)->toBe(SettlementStatus::Orphan);
});

it('#2864 hàng đã reconciled không bị quét lại', function () {
    $row = PaymentSettlement::factory()->create([
        'connection_id' => $this->connectionId,
        'provider' => 'stripe',
        'kind' => 'payment',
        'status' => SettlementStatus::Reconciled->value,
        'currency' => 'JPY',
        'external_ref' => 'txn_'.Str::random(12),
        'metadata' => ['provider_object_id' => 'pi_done'],
    ]);
    ($this->fakeStripe)(['pi_done' => ['order_id' => '999999', 'partner' => 'PaymentPlugins']]);

    $this->artisan('settlements:mark-foreign', ['--apply' => true])->assertSuccessful();

    expect($row->fresh()->status)->toBe(SettlementStatus::Reconciled);
});

// =========================================================================
//  Hệ quả: hết nuôi cảnh báo đêm
// =========================================================================

it('#2864 hàng foreign rơi khỏi feed orphan_overdue', function () {
    $foreign = ($this->orphan)('pi_foreign_3');
    $mine = ($this->orphan)('pi_tempo_2');

    ($this->fakeStripe)([
        'pi_foreign_3' => ['order_id' => '177351', 'partner' => 'PaymentPlugins'],
        'pi_tempo_2' => ['order_id' => (string) Str::uuid()],
    ]);

    $this->artisan('settlements:mark-foreign', ['--apply' => true])->assertSuccessful();

    // `openOrphans()` lọc `status = orphan`, nên đánh dấu xong là hàng foreign
    // tự rời feed — không cần sửa truy vấn nào. Dòng của TA vẫn ở lại, đúng ý.
    $stillOrphan = PaymentSettlement::query()
        ->where('status', SettlementStatus::Orphan->value)
        ->pluck('id')
        ->all();

    expect($stillOrphan)->toContain($mine->id)
        ->and($stillOrphan)->not->toContain($foreign->id);
});

// =========================================================================
//  #2864 — MẪU SỐ: tiền foreign không được lọt vào bất kỳ phép cộng nào
// =========================================================================

/*
 * Chặn feed cảnh báo mới chỉ chặn TIẾNG ỒN. ¥366.643 vẫn nằm trong bảng, nên
 * mọi phép cộng trên `payment_settlements` cũng phải loại nó ra — nhất là bản
 * CSV mà kế toán mở lên rồi `SUM(gross)`.
 *
 * Bẫy nằm ở thứ tự: hôm nay `settlement_report_batches` = 0 hàng nên bỏ sót
 * KHÔNG đỏ ở đâu cả; người viết báo cáo đối soát sau này sẽ cộng một cách hoàn
 * toàn hợp lý và ra một con số trông đúng dạng, gồm cả doanh thu WooCommerce.
 *
 * Nên bài này đo MẪU SỐ (tổng tiền), không đo hành vi của một endpoint. Gỡ
 * điều kiện `foreign` ra thì nó phải đỏ — nếu không, nó chỉ đang canh một con
 * số tình cờ.
 */
it('#2864 tổng tiền mặc định KHÔNG gồm hàng foreign', function () {
    $mine = collect([1000, 2000, 3000])->map(fn (int $g) => PaymentSettlement::factory()->create([
        'connection_id' => $this->connectionId,
        'provider' => 'stripe', 'kind' => 'payment', 'currency' => 'JPY',
        'status' => SettlementStatus::Reconciled->value,
        'gross_minor' => $g, 'net_minor' => $g,
        'external_ref' => 'txn_'.Str::random(12),
    ]));

    collect([84190, 63420])->each(fn (int $g) => PaymentSettlement::factory()->create([
        'connection_id' => $this->connectionId,
        'provider' => 'stripe', 'kind' => 'payment', 'currency' => 'JPY',
        'status' => SettlementStatus::Foreign->value,
        'gross_minor' => $g, 'net_minor' => $g,
        'external_ref' => 'txn_'.Str::random(12),
    ]));

    $expected = $mine->sum('gross_minor');

    $defaultSum = (int) PaymentSettlement::query()
        ->where('connection_id', $this->connectionId)
        ->where('status', '!=', SettlementStatus::Foreign->value)
        ->sum('gross_minor');

    $everything = (int) PaymentSettlement::query()
        ->where('connection_id', $this->connectionId)
        ->sum('gross_minor');

    expect($defaultSum)->toBe($expected)
        // Và khác biệt phải THẤY ĐƯỢC — nếu hai con số bằng nhau thì fixture
        // chưa dựng được tình huống, và bài test không chứng minh gì.
        ->and($everything)->toBeGreaterThan($defaultSum)
        ->and($everything - $defaultSum)->toBe(84190 + 63420);
});
