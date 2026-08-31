<?php

declare(strict_types=1);

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;
use App\Services\Order\Internal\EloquentOrderPersistence;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * #2173 — một khoản HOÀN đã phát hành không được xoá dấu vết.
 *
 * Void một dòng hoàn khiến chứng từ tự mâu thuẫn: dòng âm biến khỏi tổng đơn
 * (đơn **thu lại** khoản đã trả cho khách), nhưng `refunded_quantity` trên dòng
 * gốc chỉ được CỘNG, không bao giờ trừ — nên hạn mức hoàn còn lại vẫn bị trừ.
 * Hai sổ nói hai chuyện và không có gì đối chiếu chúng.
 *
 * ## Câu hỏi mở của issue, nay đã trả lời
 *
 * Issue để ngỏ: đường này có với tới được qua API không, hay chỉ bằng SQL trực
 * tiếp? Đã đọc mã: **với tới được, và KHÔNG cần shop bật gì**.
 *
 *	POST /api/v1/workstation/orders/{order}/items/{item}/void
 *	  → OrderLifecycleController::voidItem
 *	  → EloquentOrderPersistence::voidWorkstationItem   (chỉ findOrFail)
 *	  → transportWorkstationVoidItem                    (chỉ bỏ qua nếu ĐÃ voided)
 *
 * Đường ấy **không** đi qua `voidItem`, nên không có ma trận
 * `item_voidable_statuses` lẫn guard trạng thái đơn. Đường POS/khách thì có ma
 * trận, nhưng dòng hoàn mang `status = served` — một shop bật `served` là mở.
 *
 * Hai ca dưới đây đi qua **hai đường khác nhau** vì chúng là hai guard riêng
 * biệt: một guard chung đặt ở `voidItem` sẽ KHÔNG che đường replay.
 */
function refundLineOrder(): array
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
            // Ma trận cho phép void món ĐÃ PHỤC VỤ — đúng cấu hình khiến đường
            // POS chạm được dòng hoàn (dòng hoàn mang `status = served`).
            'item_voidable_statuses' => ['pending', 'preparing', 'ready', 'served'],
        ]
    );

    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 3,
        'unit_price' => 100,
        'topping_subtotal' => 0,
        'subtotal' => 300,
        'tax_rate' => 10,
        'tax_amount' => 30,
        'status' => 'served',
    ]);

    $order = $order->fresh('items');
    $original = $order->items->first();

    $updated = app(CustomerOrderService::class)->refundItem($order, $original->id, 1.0, 'test');
    $refundLine = $updated->items->firstWhere('refund_of_item_id', $original->id);

    expect($refundLine)->not->toBeNull();

    return [$updated->fresh('items'), $original->fresh(), $refundLine];
}

it('#2173: đường POS TỪ CHỐI void một dòng hoàn, kể cả khi shop bật void món đã phục vụ', function () {
    [$order, , $refundLine] = refundLineOrder();

    $call = fn () => app(CustomerOrderService::class)->voidItem($order, (string) $refundLine->id, [
        'void_reason' => 'thu ngân bấm nhầm',
    ]);

    expect($call)->toThrow(HttpResponseException::class);

    try {
        $call();
    } catch (HttpResponseException $e) {
        $payload = json_decode((string) $e->getResponse()->getContent(), true);
        expect($e->getResponse()->getStatusCode())->toBe(409);
        expect($payload['code'] ?? null)->toBe('CANNOT_VOID_REFUND_LINE');
    }

    // Dòng hoàn còn nguyên ⇒ chứng từ vẫn nói đúng một chuyện.
    // `status` là enum cast — phải so `.value`, nếu không `not->toBe('voided')`
    // luôn đúng kể cả khi dòng ĐÃ bị void (một enum không bao giờ === chuỗi).
    expect(statusValue($refundLine->fresh()))->not->toBe('voided');
});

it('#2173: đường REPLAY của máy trạm cũng từ chối — nó không đi qua ma trận trạng thái', function () {
    [, , $refundLine] = refundLineOrder();

    // Gọi thẳng transport, đúng chỗ mà `voidWorkstationItem` gọi tới. Đây là
    // đường KHÔNG cần shop bật gì, nên nếu chỉ guard ở `voidItem` thì ca này
    // vẫn lọt.
    $call = fn () => app(EloquentOrderPersistence::class)
        ->transportWorkstationVoidItem($refundLine->fresh(), 'sync-up void', null);

    expect($call)->toThrow(HttpResponseException::class);
    expect(statusValue($refundLine->fresh()))->not->toBe('voided');
});

it('#2173: dòng THƯỜNG vẫn void được — guard không siết nhầm', function () {
    // Mặt kia của bánh cóc: một guard quá tay sẽ chặn cả void hợp lệ, và triệu
    // chứng lúc đó (không huỷ được món) khó truy hơn hẳn cái đang chữa.
    //
    // #2200 — đối chứng đổi sang một dòng KHÔNG dính khoản hoàn nào: bản cũ
    // void chính dòng GỐC đã hoàn, và từ #2200 thao tác đó bị 409 có chủ đích
    // (`CANNOT_VOID_REFUNDED_ORIGIN` — xem `RefundTraceProtectionTest`).
    [$order] = refundLineOrder();

    $untouched = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => 500,
        'topping_subtotal' => 0,
        'subtotal' => 500,
        'tax_rate' => 10,
        'tax_amount' => 50,
        'status' => 'served',
    ]);

    app(CustomerOrderService::class)->voidItem($order->fresh('items'), (string) $untouched->id, [
        'void_reason' => 'khách đổi ý',
    ]);

    expect(statusValue($untouched->fresh()))->toBe('voided');
});
