<?php

declare(strict_types=1);

use App\Models\Coupon;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;
use Illuminate\Support\Facades\DB;

/**
 * #2240 — đơn NHIỀU RATE + giảm giá, hoàn MỘT PHẦN: phần giảm từng ngồi trên
 * nhóm bị hoàn phải DI CƯ sang nhóm còn sống, và tax_breakdown khách nhìn thấy
 * không được mang nhóm thuế ÂM.
 *
 * Mô hình đánh-giá-lại (#2079/#550/#2114/#2182): coupon không đi theo món được
 * trả — nó dồn sang phần hàng khách giữ. A ¥1.000 @10% + B ¥1.000 @8%, coupon
 * cố định ¥500, hoàn A ⇒ giỏ còn B − 500 = nền 500 @8% ⇒ thuế 40, khách nợ 540.
 *
 * Trước bản sửa: share 250 của coupon KẸT lại trên nhóm 10% đã rỗng ⇒ thuế 35,
 * tổng 535 (thiếu 5), và nhóm 10% xuống `taxable −250 · tax −25` trên chứng từ.
 *
 * Cơ chế: mẫu số pro-rata của khoản giảm đổi từ gross THÔ sang gross CÒN SỐNG
 * (`survivingGrossByRate` — trừ `refunded_quantity`), dùng MỘT nguồn ở cả mức
 * nhóm (`priceGroups`) lẫn mức dòng (`allocateLineTaxes`).
 */
function multiRateOrder(array $lines, float $couponValue): array
{
    $order = CustomerOrder::factory()->create([
        'status' => 'open',
        'is_tax_included' => false,
        'discount_amount' => 0,
        'coupon_id' => null,
        'tax_rounding_mode' => 'round',
        'tax_rounding_decimals' => 0,
    ]);

    ShopOrderSetting::query()->firstOrCreate(
        ['branch_id' => $order->branch_id],
        [
            'organization_id' => $order->organization_id,
            'service_charge_rate' => 0,
            'service_charge_tax_rate' => 0,
            'currency_code' => 'JPY',
            'tax_rounding_mode' => 'round',
        ]
    );

    $coupon = Coupon::factory()->create([
        'brand_id' => $order->brand_id,
        'organization_id' => $order->organization_id,
        'code' => 'MR'.substr((string) $order->id, 0, 6),
        'discount_type' => 'fixed',
        'discount_value' => $couponValue,
        'max_discount_cap' => null,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDay(),
        'status' => 'draft',
    ]);
    $order->update([
        'coupon_id' => $coupon->id,
        'coupon_code_snapshot' => $coupon->code,
        'discount_amount' => $couponValue,
    ]);

    $ids = [];
    foreach ($lines as [$price, $rate]) {
        $ids[] = CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'quantity' => 1,
            'unit_price' => $price,
            'topping_subtotal' => 0,
            'subtotal' => $price,
            'tax_rate' => $rate,
            'tax_amount' => 0,
            'status' => 'served',
        ])->id;
    }

    app(CustomerOrderService::class)->refreshOrderTotals($order->fresh('items'));

    return [$order->fresh('items'), $ids, app(CustomerOrderService::class)];
}

/** amount của các dòng sổ `tax` trên ĐƠN — cái tax_breakdown khách đọc. */
function taxConditionAmounts(CustomerOrder $order): array
{
    return DB::table('order_conditions')
        ->where('conditionable_type', $order->getMorphClass())
        ->where('conditionable_id', (string) $order->id)
        ->where('type', 'tax')
        ->pluck('amount')
        ->map(fn ($a) => (float) $a)
        ->all();
}

it('#2240: A@10% + B@8% + coupon 500, hoàn A ⇒ thuế 40 · tổng 540, không nhóm thuế âm', function () {
    [$order, $ids, $service] = multiRateOrder([[1000, 10], [1000, 8]], 500);

    // Trước hoàn: share 250/250 ⇒ thuế 75 + 60 = 135, tổng 1.635.
    expect((float) $order->fresh()->tax_amount)->toBe(135.0)
        ->and((float) $order->fresh()->total_amount)->toBe(1635.0);

    $service->refundItem($order->fresh('items'), (string) $ids[0], 1.0, 'test #2240');
    $fresh = $order->fresh();

    expect((float) $fresh->subtotal)->toBe(1000.0)
        ->and((float) $fresh->tax_amount)->toBe(40.0, 'coupon phải dồn hết sang nhóm 8% còn sống: (1000−500)×8% = 40')
        ->and((float) $fresh->total_amount)->toBe(540.0);

    $amounts = taxConditionAmounts($fresh);
    expect($amounts)->not->toBeEmpty()
        ->and(min($amounts))->toBeGreaterThanOrEqual(0.0, 'tax_breakdown khách đọc không được mang nhóm thuế ÂM');
});

it('#2240: BA rate + coupon 600, hoàn nhóm 10% ⇒ khoản giảm chia lại cho HAI nhóm còn sống', function () {
    [$order, $ids, $service] = multiRateOrder([[1000, 10], [1000, 8], [1000, 5]], 600);

    // Trước hoàn: share 200/200/200 ⇒ 80 + 64 + 40 = 184, tổng 2.584.
    expect((float) $order->fresh()->tax_amount)->toBe(184.0)
        ->and((float) $order->fresh()->total_amount)->toBe(2584.0);

    $service->refundItem($order->fresh('items'), (string) $ids[0], 1.0, 'test #2240');
    $fresh = $order->fresh();

    // Còn B@8% + C@5%, coupon 600 chia 300/300:
    // (1000−300)×8% = 56 · (1000−300)×5% = 35 ⇒ thuế 91, khách nợ 1.400+91 = 1.491.
    expect((float) $fresh->subtotal)->toBe(2000.0)
        ->and((float) $fresh->tax_amount)->toBe(91.0)
        ->and((float) $fresh->total_amount)->toBe(1491.0);

    $amounts = taxConditionAmounts($fresh);
    expect(min($amounts))->toBeGreaterThanOrEqual(0.0);
});

it('#2240: tax_breakdown sau hoàn KHÔNG mang nhóm âm — bài riêng, assertion nhóm đứng ĐẦU', function () {
    // Suggestion review vòng 1: ở hai bài trên, `min($amounts) >= 0` đứng SAU
    // các assertion tổng nên ở chiều ngược nó không bao giờ được chạy — hình
    // dạng chứng từ khách đọc phải có bài ghim riêng, nơi nó là assertion đầu.
    [$order, $ids, $service] = multiRateOrder([[1000, 10], [1000, 8]], 500);
    $service->refundItem($order->fresh('items'), (string) $ids[0], 1.0, 'test #2240');

    $amounts = taxConditionAmounts($order->fresh());
    expect($amounts)->not->toBeEmpty()
        ->and(min($amounts))->toBeGreaterThanOrEqual(
            0.0,
            'nhóm thuế ÂM trên chứng từ khách (trước bản sửa: 10% mang tax −25)',
        );
});

it('#2240: coupon LỚN HƠN giỏ còn sống (1900/2000), hoàn một món ⇒ 0/0, không tổng âm', function () {
    // Ca B của review vòng 1 — hồi quy đắt nhất bản sửa này đóng, nền cũ ra
    // tax −10 · total −10 (đơn khẳng định quán nợ ngược khách, họ #2114/#2182).
    // Đóng theo cấu trúc: appliedDiscount ≤ liveSubtotal = Σ gross còn sống,
    // và share nhóm = D×S_g/ΣS ≤ S_g ⇒ nền mọi nhóm ≥ 0.
    [$order, $ids, $service] = multiRateOrder([[1000, 10], [1000, 8]], 1900);

    $service->refundItem($order->fresh('items'), (string) $ids[0], 1.0, 'test #2240');
    $fresh = $order->fresh();

    expect((float) $fresh->tax_amount)->toBe(0.0, 'coupon 1900 nuốt trọn nền 1000 còn sống ⇒ thuế 0')
        ->and((float) $fresh->total_amount)->toBe(0.0, 'tổng ÂM = quán nợ ngược khách khoản chưa từng trả');

    $amounts = taxConditionAmounts($fresh);
    expect($amounts === [] || min($amounts) >= 0.0)->toBeTrue('không nhóm thuế âm');
});

it('#2240: hoàn HẾT vẫn về 0/0 — không phá tiêu đề #2182', function () {
    [$order, $ids, $service] = multiRateOrder([[1000, 10], [1000, 8]], 500);

    foreach ($ids as $id) {
        $service->refundItem($order->fresh('items'), (string) $id, 1.0, 'test');
    }
    $fresh = $order->fresh();

    expect((float) $fresh->subtotal)->toBe(0.0)
        ->and((float) $fresh->tax_amount)->toBe(0.0)
        ->and((float) $fresh->total_amount)->toBe(0.0);
});

it('#2240 vòng 2: nâng giá dòng ĐÃ HOÀN không phá "Σ thuế dòng == tax_amount" — kẹp D bằng Σ gross còn sống', function () {
    // Blocking vòng 2: cận kẹp của appliedDiscount từng là liveSubtotal (cộng
    // subtotal ĐÓNG BĂNG của dòng hoàn) trong khi mẫu số phân bổ là Σ gross
    // CÒN SỐNG (đơn giá HIỆN TẠI × (qty − refunded)). Hai nguồn lệch được qua
    // đường nghiệp vụ thật — downgradeExclusivePromotions nâng lại giá dòng đã
    // hoàn, sync-UP ghi đè giá sau khi HQ đổi menu — và khi D lọt vào khoảng
    // (ΣW, liveSubtotal] thì share dòng bị kẹp max(0,…) mà mức nhóm không kẹp
    // cùng lượng ⇒ Σ thuế dòng ≠ header.
    //
    // Hình dạng bắt buộc: HAI dòng CÙNG nhóm rate (đơn một dòng mỗi nhóm thì
    // hai vế kẹp giống hệt, không lộ), giảm giá TAY (coupon bị đánh giá lại
    // trên liveSubtotal nên không giữ được D trong khoảng lệch).
    $order = CustomerOrder::factory()->create([
        'status' => 'open',
        'is_tax_included' => false,
        'discount_amount' => 1200,
        'coupon_id' => null,
        'tax_rounding_mode' => 'round',
        'tax_rounding_decimals' => 0,
    ]);
    ShopOrderSetting::query()->firstOrCreate(
        ['branch_id' => $order->branch_id],
        [
            'organization_id' => $order->organization_id,
            'service_charge_rate' => 0,
            'service_charge_tax_rate' => 0,
            'currency_code' => 'JPY',
            'tax_rounding_mode' => 'round',
        ]
    );
    $ids = [];
    foreach ([[1000, 10], [1000, 10]] as [$price, $rate]) {
        $ids[] = CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'quantity' => 1,
            'unit_price' => $price,
            'topping_subtotal' => 0,
            'subtotal' => $price,
            'tax_rate' => $rate,
            'tax_amount' => 0,
            'status' => 'served',
        ])->id;
    }
    $service = app(CustomerOrderService::class);
    $service->refreshOrderTotals($order->fresh('items'));

    $service->refundItem($order->fresh('items'), (string) $ids[0], 1.0, 'test #2240');

    // Nâng giá dòng GỐC đã hoàn trọn (điều patchOrderItemUnchecked /
    // transportWorkstationSyncItems làm vô điều kiện): liveSubtotal phồng lên
    // 1.500, Σ gross còn sống vẫn 1.000 ⇒ D=1.200 nằm đúng khoảng lệch.
    CustomerOrderItem::query()->whereKey($ids[0])->update(['unit_price' => 1500, 'subtotal' => 1500]);
    $service->refreshOrderTotals($order->fresh('items'));

    $fresh = $order->fresh('items');
    $lineTaxSum = round($fresh->items->sum(fn ($i) => (float) $i->tax_amount), 2);
    expect($lineTaxSum)->toBe(
        (float) $fresh->tax_amount,
        'Σ thuế từng dòng phải bằng tax_amount của đơn — bất biến mọi báo cáo cộng dòng dựa vào (trước bản sửa: header 30, Σ dòng 50)',
    );
});

it('#2240 vòng 4: HẠ giá dòng ĐÃ HOÀN không cho total ÂM — kẹp D bằng CẢ liveSubtotal lẫn Σ gross còn sống', function () {
    // Blocking vòng 3: bản vá vòng 2 THAY cận liveSubtotal bằng ΣW thay vì SIẾT
    // cả hai — đóng chiều tăng nhưng mở chiều giảm, và chiều giảm nặng hơn:
    // liveSubtotal co về 300 trong khi ΣW vẫn 1.000 ⇒ appliedDiscount = 1.000
    // trên một giỏ 300 ⇒ total = 300 − 1.000 + (−70) = −770 — đơn khẳng định
    // quán NỢ khách một khoản khách chưa từng trả (đúng triệu chứng #2114).
    // Cùng hình dạng với ca vòng 2, chỉ đảo CHIỀU đổi giá: 1.000 → 300.
    $order = CustomerOrder::factory()->create([
        'status' => 'open',
        'is_tax_included' => false,
        'discount_amount' => 1200,
        'coupon_id' => null,
        'tax_rounding_mode' => 'round',
        'tax_rounding_decimals' => 0,
    ]);
    ShopOrderSetting::query()->firstOrCreate(
        ['branch_id' => $order->branch_id],
        [
            'organization_id' => $order->organization_id,
            'service_charge_rate' => 0,
            'service_charge_tax_rate' => 0,
            'currency_code' => 'JPY',
            'tax_rounding_mode' => 'round',
        ]
    );
    $ids = [];
    foreach ([[1000, 10], [1000, 10]] as [$price, $rate]) {
        $ids[] = CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'quantity' => 1,
            'unit_price' => $price,
            'topping_subtotal' => 0,
            'subtotal' => $price,
            'tax_rate' => $rate,
            'tax_amount' => 0,
            'status' => 'served',
        ])->id;
    }
    $service = app(CustomerOrderService::class);
    $service->refreshOrderTotals($order->fresh('items'));

    $service->refundItem($order->fresh('items'), (string) $ids[0], 1.0, 'test #2240');

    // Hạ giá dòng GỐC đã hoàn trọn: liveSubtotal co về 300, Σ gross còn sống
    // vẫn 1.000 ⇒ D=1.200 kẹp phải ra 300 (cận liveSubtotal), không phải 1.000.
    CustomerOrderItem::query()->whereKey($ids[0])->update(['unit_price' => 300, 'subtotal' => 300]);
    $service->refreshOrderTotals($order->fresh('items'));

    $fresh = $order->fresh('items');
    expect((float) $fresh->total_amount)->toBeGreaterThanOrEqual(
        0.0,
        'total ÂM — khoản giảm áp dụng vượt giỏ sống (trước bản sửa: 300 − 1.000 − 70 = −770)',
    );
    $lineTaxSum = round($fresh->items->sum(fn ($i) => (float) $i->tax_amount), 2);
    expect($lineTaxSum)->toBe(
        (float) $fresh->tax_amount,
        'Σ thuế từng dòng phải bằng tax_amount của đơn — cận mới không được phá bất biến của vòng 2/3',
    );
});

it('#2240: đơn KHÔNG có dòng hoàn — trọng số còn-sống trùng gross, hành vi y cũ', function () {
    [$order] = multiRateOrder([[1005, 10], [1005, 10], [1005, 10]], 0);

    // Ba dòng ¥1.005 @10%, không giảm: nhóm 3.015 ⇒ thuế 302 (một lần mỗi nhóm),
    // phân bổ largest-remainder 101/101/100 — đúng ca RefundReversesTaxExactlyTest.
    $fresh = $order->fresh('items');
    expect((float) $fresh->tax_amount)->toBe(302.0)
        ->and($fresh->items->pluck('tax_amount')->map(fn ($t) => (float) $t)->sort()->values()->all())
        ->toBe([100.0, 101.0, 101.0]);
});
