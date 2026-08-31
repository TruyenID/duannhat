<?php

/**
 * #1296 — PayPay on a dine-in bill, which is the case that splits.
 *
 * The money guards are covered by Plan054RecordPayPayPaymentTest; what is new
 * here is ATTRIBUTION. A dine-in payment has to say which share of the bill it
 * settled, and until this landed a PayPay row said nothing at all. Two things
 * broke, neither of them loudly:
 *
 *  - per-dish: `OrderPayment.metadata.item_allocations` empty → `paid_quantity`
 *    stays 0 → the dishes the first payer settled never disable, and
 *    `splitByItemsPreview` refuses the NEXT payer outright with
 *    `split_by_items_mode_locked`, because the order now carries a row using
 *    "another split mode";
 *  - per-head: no `split_count` on the row → `/split-status` never promotes its
 *    soft lock, so every later guest re-picks the headcount.
 */

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\OrderPaymentService;
use App\Services\Payment\Gateway\PayPay\PayPayQrSplitIntent;

uses()->group('payment');

function dineInOrder(array $overrides = []): CustomerOrder
{
    $branch = Branch::factory()->create(['currency' => 'JPY']);

    $order = CustomerOrder::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'order_type' => 'dine_in',
        'status' => CustomerOrderStatusEnum::Open->value,
        'total_amount' => 3000,
        'paid_amount' => 0,
    ], $overrides));

    ShopOrderSetting::factory()->create([
        'branch_id' => $branch->id,
        'organization_id' => $order->organization_id,
        'currency_code' => 'JPY',
    ]);

    return $order;
}

function recordPayPay(CustomerOrder $order, string $mpid, float $amount): array
{
    return app(OrderPaymentService::class)->recordPayPayPaymentByOrderId((string) $order->id, $mpid, $amount, 'JPY');
}

it('stamps the per-dish allocation the payer declared at mint onto the ledger row', function () {
    $order = dineInOrder();

    PayPayQrSplitIntent::remember('tempoqr-items', PayPayQrSplitIntent::normalize([
        'split_type' => 'by_items',
        'item_allocations' => [
            ['item_id' => 'item-salad', 'units' => 1],
            ['item_id' => 'item-soup', 'units' => 2],
        ],
    ]));

    recordPayPay($order, 'tempoqr-items', 1200);

    $row = OrderPayment::query()->where('customer_order_id', $order->id)->sole();

    expect($row->metadata['split_mode'])->toBe('by_items')
        ->and($row->metadata['item_allocations'])->toBe([
            ['item_id' => 'item-salad', 'units' => 1],
            ['item_id' => 'item-soup', 'units' => 2],
        ]);
});

it('carries the headcount so a later guest reads a hard lock rather than re-picking it', function () {
    $order = dineInOrder([
        // Written by customer-web's /split-mode BEFORE any payment.
        'split_mode' => 'even',
        'split_people_count' => 3,
    ]);

    recordPayPay($order, 'tempoqr-people', 1000);

    $row = OrderPayment::query()->where('customer_order_id', $order->id)->sole();

    expect($row->metadata['split_count'])->toBe(3)
        // Loose compare: the json column round-trips a whole yen amount back as
        // an int, exactly as it already does for the kiosk/pos payments that
        // share this backfill.
        ->and($row->metadata['amount_per_person'])->toEqual(1000.0)
        ->and($row->metadata['split_mode'])->toBe('even');
});

it('recovers the headcount from the order even when the mint-time intent is gone', function () {
    $order = dineInOrder([
        'split_mode' => 'even',
        'split_people_count' => 4,
    ]);

    // Nothing remembered — the cache was evicted between the scan and the
    // webhook. Per-dish attribution is unrecoverable, but the headcount lives on
    // the order, so the hard lock must still form.
    recordPayPay($order, 'tempoqr-evicted', 750);

    $row = OrderPayment::query()->where('customer_order_id', $order->id)->sole();

    expect($row->metadata['split_count'])->toBe(4);
});

it('leaves metadata null when the bill was simply paid in full', function () {
    $order = dineInOrder();

    recordPayPay($order, 'tempoqr-full', 3000);

    // #1058 — no split means no metadata blob, not an empty one.
    expect(OrderPayment::query()->where('customer_order_id', $order->id)->sole()->metadata)->toBeNull();
});

it('settles the order only once the last payer has scanned', function () {
    $order = dineInOrder([
        'split_mode' => 'even',
        'split_people_count' => 3,
    ]);

    recordPayPay($order, 'tempoqr-1of3', 1000);
    expect($order->fresh()->status)->not->toBe(CustomerOrderStatusEnum::Closed);

    recordPayPay($order, 'tempoqr-2of3', 1000);
    expect($order->fresh()->status)->not->toBe(CustomerOrderStatusEnum::Closed);

    recordPayPay($order, 'tempoqr-3of3', 1000);

    expect((float) $order->fresh()->paid_amount)->toBe(3000.0)
        ->and(OrderPayment::query()->where('customer_order_id', $order->id)->count())->toBe(3);
});

it('refuses a by-items intent that allocates nothing rather than locking out the next payer', function () {
    // An empty allocation list would otherwise stamp `split_mode: by_items` with
    // nothing attributed — which credits no dish AND makes every later by-items
    // payer fail the mutual-exclusivity check.
    expect(PayPayQrSplitIntent::normalize([
        'split_type' => 'by_items',
        'item_allocations' => [],
    ]))->toBeNull();

    expect(PayPayQrSplitIntent::normalize([
        'split_type' => 'by_items',
        'item_allocations' => [['item_id' => '', 'units' => 3]],
    ]))->toBeNull();
});

it('tells two different dish selections apart even when they cost the same', function () {
    // Two ¥500 dishes: the payer who switches their pick keeps an identical
    // total, so amount alone cannot decide whether a live code may be resumed.
    $salad = PayPayQrSplitIntent::normalize([
        'split_type' => 'by_items',
        'item_allocations' => [['item_id' => 'item-salad', 'units' => 1]],
    ]);
    $soup = PayPayQrSplitIntent::normalize([
        'split_type' => 'by_items',
        'item_allocations' => [['item_id' => 'item-soup', 'units' => 1]],
    ]);

    expect(PayPayQrSplitIntent::fingerprint($salad))
        ->not->toBe(PayPayQrSplitIntent::fingerprint($soup));

    // …but request key order must not: the same selection sent twice is the same
    // intent, or a page reload would re-mint and restart the countdown.
    $reordered = PayPayQrSplitIntent::normalize([
        'item_allocations' => [['units' => 1, 'item_id' => 'item-salad']],
        'split_type' => 'by_items',
    ]);

    expect(PayPayQrSplitIntent::fingerprint($reordered))
        ->toBe(PayPayQrSplitIntent::fingerprint($salad));
});

it('#2865 intent PayPay mint bởi bundle CŨ vẫn giữ được attribution', function () {
    // Đây là đường tiền đã nuốt im lặng, và nó nuốt theo cách tệ nhất: tiền THU
    // ĐÚNG, chỉ attribution biến mất — không lỗi, không log, không ai biết.
    //
    // Kịch bản: khách dine-in mở một bundle customer-web mint TRƯỚC deploy, chọn
    // chia theo số tiền. Luật validate đã nới nên tên cũ qua được, QR mint, tiền
    // vào — nhưng `normalize()` đối chiếu với tập canonical rồi trả null, nên
    // dòng tiền không mang chế độ chia và `/split-status` không khoá cứng.
    //
    // Đường Stripe song sinh vốn đã chuẩn hoá trước khi dùng. Đây đúng là loại
    // lệch-hai-đường mà #2860 sinh ra để giết — bỏ sót một đường thì cái rào ấy
    // chỉ chứng minh được một nửa.
    $order = dineInOrder();

    PayPayQrSplitIntent::remember('tempoqr-legacy', [
        // Nguyên văn thứ một bundle cũ gửi.
        'split_type' => 'by_people',
        'split_count' => 2,
    ]);

    recordPayPay($order, 'tempoqr-legacy', 1500);

    $row = OrderPayment::query()->where('customer_order_id', $order->id)->sole();

    // Attribution còn, VÀ được lưu bằng canonical — nhận tên cũ ở biên không
    // được phép nhỏ giọt tên cũ vào cột sau khi migration đã chạy.
    expect($row->metadata)->not->toBeNull()
        ->and($row->metadata['split_count'])->toBe(2)
        ->and($row->metadata['split_mode'] ?? $row->metadata['split_type'] ?? null)->toBe('even');
});
