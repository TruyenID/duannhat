<?php

declare(strict_types=1);

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;
use App\Services\Order\Internal\EloquentOrderPersistence;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * #2200 — nguyên tắc #2173 ("dấu vết một khoản hoàn đã phát hành không được
 * xoá") cưỡng chế ĐỦ PHẠM VI, không chỉ ở thao tác void-dòng-hoàn:
 *
 * 1. sync-items không được LẬT một dòng hoàn thành dương (upsert theo id giữ
 *    `refund_of_item_id` sống sót ⇒ khoản hoàn bị tính thành khoản THU; đo:
 *    330 → hoàn 1 → 220 → đè dòng hoàn ⇒ 390).
 * 2. void dòng GỐC mà bút toán đảo đang trỏ vào cũng xoá dấu vết: dòng gốc rời
 *    tổng trong khi dòng hoàn vẫn được gộp ⇒ tổng ÂM (220 → void gốc ⇒ −110).
 * 3. (cùng dòng find() của sync-items) id thuộc ĐƠN KHÁC bị 404 — trước đó
 *    `CustomerOrderItem::find($id)` không scope nên payload dị dạng sửa được
 *    item xuyên đơn/chi nhánh.
 */
function refundOriginOrder(): array
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

    $updated = app(CustomerOrderService::class)->refundItem($order, (string) $original->id, 1.0, 'test #2200');
    $refundLine = $updated->items->firstWhere('refund_of_item_id', $original->id);

    return [$updated->fresh('items'), $original->fresh(), $refundLine];
}

/** Trích payload từ HttpResponseException để so code + status. */
function refundTraceAbortPayload(callable $call): array
{
    try {
        $call();
    } catch (HttpResponseException $e) {
        return [
            $e->getResponse()->getStatusCode(),
            json_decode((string) $e->getResponse()->getContent(), true)['code'] ?? null,
        ];
    }

    return [0, null];
}

it('#2200: void dòng GỐC của khoản hoàn đã phát hành bị 409 — đường POS', function () {
    [$order, $original] = refundOriginOrder();
    $totalBefore = (float) $order->total_amount;
    expect($totalBefore)->toBe(220.0);

    [$status, $code] = refundTraceAbortPayload(
        fn () => app(CustomerOrderService::class)->voidItem($order, (string) $original->id, [
            'void_reason' => 'thu ngân muốn bỏ món',
        ]),
    );

    expect($status)->toBe(409)
        ->and($code)->toBe('CANNOT_VOID_REFUNDED_ORIGIN')
        ->and((float) $order->fresh()->total_amount)->toBe($totalBefore)
        ->and(statusValue($original->fresh()))->not->toBe('voided');
});

it('#2200: đường REPLAY máy trạm cũng từ chối void dòng gốc đã hoàn', function () {
    [, $original] = refundOriginOrder();

    $call = fn () => app(EloquentOrderPersistence::class)
        ->transportWorkstationVoidItem($original->fresh(), 'sync-up void', null);

    expect($call)->toThrow(HttpResponseException::class);
    expect(statusValue($original->fresh()))->not->toBe('voided');
});

it('#2200: sync-items từ chối ĐÈ một dòng hoàn — khoản hoàn không lật thành khoản thu', function () {
    [$order, , $refundLine] = refundOriginOrder();
    $totalBefore = (float) $order->total_amount;

    [$status, $code] = refundTraceAbortPayload(
        fn () => app(EloquentOrderPersistence::class)->transportWorkstationSyncItems(
            $order,
            [[
                'id' => (string) $refundLine->id,
                'product_sku_id' => $refundLine->product_sku_id,
                'quantity' => 1,
            ]],
            [100.0],
        ),
    );

    $refundLine = $refundLine->fresh();
    expect($status)->toBe(409)
        ->and($code)->toBe('CANNOT_MODIFY_REFUND_LINE')
        ->and((float) $refundLine->quantity)->toBe(-1.0)
        ->and((float) $refundLine->subtotal)->toBe(-100.0)
        ->and((float) $order->fresh()->total_amount)->toBe($totalBefore);
});

it('#2200: sync-items từ chối id thuộc ĐƠN KHÁC — 404, không sửa xuyên đơn', function () {
    [$orderA] = refundOriginOrder();
    [$orderB] = refundOriginOrder();
    $foreignItem = $orderB->items->whereNull('refund_of_item_id')->first();
    $subtotalBefore = (float) $foreignItem->subtotal;

    [$status, $code] = refundTraceAbortPayload(
        fn () => app(EloquentOrderPersistence::class)->transportWorkstationSyncItems(
            $orderA,
            [[
                'id' => (string) $foreignItem->id,
                'product_sku_id' => $foreignItem->product_sku_id,
                'quantity' => 9,
            ]],
            [100.0],
        ),
    );

    expect($status)->toBe(404)
        ->and($code)->toBe('ITEM_NOT_IN_ORDER')
        ->and((float) $foreignItem->fresh()->subtotal)->toBe($subtotalBefore)
        ->and((string) $foreignItem->fresh()->customer_order_id)->toBe((string) $orderB->id);
});

it('#2200: sync-items vẫn cập nhật được dòng thường của chính đơn — guard không siết nhầm', function () {
    [$order] = refundOriginOrder();
    $normal = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => 100,
        'topping_subtotal' => 0,
        'subtotal' => 100,
        'tax_rate' => 10,
        'tax_amount' => 10,
        'status' => 'pending',
    ]);

    app(EloquentOrderPersistence::class)->transportWorkstationSyncItems(
        $order->fresh('items'),
        [[
            'id' => (string) $normal->id,
            'product_sku_id' => $normal->product_sku_id,
            'quantity' => 2,
        ]],
        [100.0],
    );

    expect((float) $normal->fresh()->quantity)->toBe(2.0)
        ->and((float) $normal->fresh()->subtotal)->toBe(200.0);
});
