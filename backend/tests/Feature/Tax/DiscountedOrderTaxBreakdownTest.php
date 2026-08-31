<?php

declare(strict_types=1);

use App\Models\CustomerOrder;
use App\Models\OrderCondition;
use App\Services\Customer\OrderTaxBreakdownAggregator;

/**
 * #2031 — bảng thuế theo mức trên hoá đơn phải TỰ CÂN.
 *
 * ## Lỗi bài này ghim
 *
 * `OrderTaxBreakdownAggregator` từng gom `SUM(items.subtotal)` làm `taxable` và
 * `SUM(items.tax_amount)` làm `tax`. Hai cột lấy từ hai mốc khác nhau:
 * `items.subtotal` là GỘP (không có cột giảm giá nào trên dòng món), còn
 * `items.tax_amount` đã trừ giảm giá.
 *
 * Trên đơn có khuyến mãi, hoá đơn in ra "mức 10%: đối giá 10.000, thuế 900" —
 * mà 10.000 × 10% = 1.000. Tờ giấy tự mâu thuẫn.
 *
 * Với 適格請求書, 税率ごとに区分した対価の額 phải là số tiền THỰC NHẬN
 * (売上値引き làm giảm nền đó), nên đây là một trường pháp lý sai, không phải
 * lỗi trình bày.
 *
 * ## Vì sao dựng bằng `order_conditions` trực tiếp
 *
 * Bài này đo ĐƯỜNG ĐỌC, không đo đường tính. Dựng sổ bằng tay cho phép ghim
 * đúng một câu hỏi — "hoá đơn có in ra thứ đã được lưu không" — mà không kéo
 * theo cả bộ máy checkout. Đường tính đã có cổng riêng
 * (`TaxAllocationGoldenParityTest`, fixture dùng chung với Go).
 */
function seedTaxCondition(CustomerOrder $order, float $rate, float $taxable, float $tax): void
{
    OrderCondition::query()->create([
        'conditionable_type' => $order->getMorphClass(),
        'conditionable_id' => $order->id,
        'type' => 'tax',
        'source' => 'tax_type',
        'label' => $rate.'%',
        'rate' => $rate,
        'amount' => $tax,
        'taxable_base' => $taxable,
        'currency_code' => 'JPY',
        'meta' => ['rate_group' => (string) $rate],
    ]);
}

it('đơn HAI MỨC có giảm giá: mỗi mức tự cân, đối giá × thuế suất = thuế', function () {
    $order = CustomerOrder::factory()->create();

    // Giỏ 10.000 ở mức 10% + 5.000 ở mức 8%, giảm 3.000 phân bổ pro-rata:
    //   10%: nền 10.000 − 2.000 = 8.000 → thuế 800
    //    8%: nền  5.000 − 1.000 = 4.000 → thuế 320
    seedTaxCondition($order, 10.0, 8000.0, 800.0);
    seedTaxCondition($order, 8.0, 4000.0, 320.0);

    $out = app(OrderTaxBreakdownAggregator::class)->forOrders([$order->id]);

    $byRate = collect($out['by_rate'])->keyBy(fn ($r) => (string) $r['rate']);

    expect($byRate['10']['taxable'])->toBe(8000.0)
        ->and($byRate['10']['tax'])->toBe(800.0)
        ->and($byRate['8']['taxable'])->toBe(4000.0)
        ->and($byRate['8']['tax'])->toBe(320.0);

    // Bất biến của tờ giấy: mỗi dòng phải tự cân ở chính thuế suất nó in ra.
    foreach ($out['by_rate'] as $row) {
        expect($row['taxable'] * $row['rate'] / 100.0)
            ->toBeGreaterThan($row['tax'] - 1.0)
            ->toBeLessThan($row['tax'] + 1.0, "mức {$row['rate']}% không tự cân");
    }
});

it('tổng net/tax/gross khớp Σ theo mức', function () {
    $order = CustomerOrder::factory()->create();
    seedTaxCondition($order, 10.0, 8000.0, 800.0);
    seedTaxCondition($order, 8.0, 4000.0, 320.0);

    $out = app(OrderTaxBreakdownAggregator::class)->forOrders([$order->id]);

    expect($out['net'])->toBe(12000.0)
        ->and($out['tax'])->toBe(1120.0)
        ->and($out['gross'])->toBe(13120.0);
});

it('đơn CŨ chưa có sổ vẫn ra số, không trả rỗng', function () {
    // Đơn ghi trước plan-045 không có dòng `tax` nào. Rơi về phép gom cũ là CỐ Ý:
    // nó sai trên đơn có giảm giá, nhưng một báo cáo doanh thu RỖNG còn khó phát
    // hiện hơn một con số lệch — và với đơn cũ thì dữ liệu để làm đúng không tồn
    // tại vào lúc chúng được ghi.
    $order = CustomerOrder::factory()->create();

    $out = app(OrderTaxBreakdownAggregator::class)->forOrders([$order->id]);

    expect($out)->toHaveKeys(['net', 'tax', 'gross', 'by_rate']);
});

it('KHÔNG trộn đơn của người khác: chỉ gom đúng id được hỏi', function () {
    $mine = CustomerOrder::factory()->create();
    $theirs = CustomerOrder::factory()->create();

    seedTaxCondition($mine, 10.0, 1000.0, 100.0);
    seedTaxCondition($theirs, 10.0, 9999.0, 999.0);

    $out = app(OrderTaxBreakdownAggregator::class)->forOrders([$mine->id]);

    expect($out['net'])->toBe(1000.0)->and($out['tax'])->toBe(100.0);
});

it('dòng discount và refund KHÔNG lọt vào bảng thuế', function () {
    // Sổ chung một bảng cho ba loại. Gom nhầm loại sẽ trừ thẳng vào doanh thu
    // chịu thuế, và số ra vẫn "trông hợp lý" nên không ai bắt được bằng mắt.
    $order = CustomerOrder::factory()->create();
    seedTaxCondition($order, 10.0, 1000.0, 100.0);

    foreach (['discount', 'refund'] as $type) {
        OrderCondition::query()->create([
            'conditionable_type' => $order->getMorphClass(),
            'conditionable_id' => $order->id,
            'type' => $type,
            'source' => 'manual',
            'label' => $type,
            'rate' => 10.0,
            'amount' => -500.0,
            'taxable_base' => null,
            'currency_code' => 'JPY',
            'meta' => [],
        ]);
    }

    $out = app(OrderTaxBreakdownAggregator::class)->forOrders([$order->id]);

    expect($out['tax'])->toBe(100.0)->and($out['net'])->toBe(1000.0);
});
