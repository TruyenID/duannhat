<?php

declare(strict_types=1);

/**
 * #1594 (#962) — Payments thôi cầm `CustomerOrder` trên bốn mắt còn lại.
 *
 * Cạnh Payments → Ordering ở đây KHÔNG phải kiểu "đọc nhờ vài cột". Đường thanh
 * toán vừa đọc vừa GHI vào đơn, nên một cổng chỉ-đọc kiểu `OrderSnapshot` không
 * đủ cho mọi mắt — và đó chính là lý do lát cắt này chỉ nhận những mắt ĐO ĐƯỢC
 * là đọc:
 *
 *   · mint/poll QR PayPay      — đọc 8 vô hướng, ghi tiền qua `OrderPaymentService`
 *   · 4 mắt PayPay ở compat    — đọc ĐÚNG `organization_id`
 *   · `recordAutoConfirmTender`— đọc ĐÚNG `id`
 *   · so tổng sub-check        — cần cả `CustomerOrderItem`, nên đi qua cổng
 *                                `OrderSplitBillTotals` mà Ordering hiện thực
 *
 * Bài test ghim hai loại thứ khác nhau, vì chúng hỏng theo hai kiểu:
 *
 *  1. **Chữ ký** — đổi ngược về model thì KHÔNG test hành vi nào đỏ, chỉ
 *     deptrac-baseline phình ra. Mà baseline chỉ được co lại.
 *  2. **Phép tính không đổi** — cổng chia hoá đơn phải trả ĐÚNG con số bộ tính
 *     cũ trả. Đây là chỗ duy nhất trong lát cắt chạm vào tiền, nên nó được so
 *     trực tiếp với `SplitByItemsCalculator` chứ không tin vào việc đọc code.
 */

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Services\Customer\OrderPaymentService;
use App\Services\Customer\PayPayPaymentService;
use App\Services\Customer\SplitByItemsCalculator;
use App\Services\Order\Contracts\OrderQueryPort;
use App\Services\Order\Contracts\OrderSnapshot;
use App\Services\Order\Contracts\OrderSplitBillTotals;
use App\Services\Payment\Orchestration\OrderPaymentOrchestrationCompat;
use Illuminate\Support\Str;

uses()->group('payment');

/** @return string Tên kiểu của tham số thứ `$index` (0-based). */
function boundary1594ParamType(string $class, string $method, int $index): string
{
    $parameter = (new ReflectionMethod($class, $method))->getParameters()[$index];

    return (string) $parameter->getType();
}

it('mint và poll QR PayPay nhận ảnh chụp, không nhận model', function (string $method) {
    expect(boundary1594ParamType(PayPayPaymentService::class, $method, 0))->toBe(
        OrderSnapshot::class,
        $method.'() nhận lại `CustomerOrder` là dựng lại cạnh Payments → Ordering '
        .'mà #1594 vừa gỡ — và không một test hành vi nào sẽ đỏ vì chuyện đó.'
    );
})->with(['createQrCode', 'syncStatus']);

it('bốn mắt PayPay ở compat nhận ảnh chụp — cả bốn chỉ đọc organization_id', function (string $method) {
    expect(boundary1594ParamType(OrderPaymentOrchestrationCompat::class, $method, 0))
        ->toBe(OrderSnapshot::class);
})->with([
    'preparePayPayQrAttempt',
    'abandonPayPayQrAttempt',
    'retirePayPayQrAttempt',
    'settlePayPayQrAttempt',
]);

it('mắt ghi tender tự-xác-nhận nhận ID, không nhận model', function () {
    // Đọc đúng `$order->id` hai lần. Nhận model là trả giá một cạnh module cho
    // một chuỗi.
    expect(boundary1594ParamType(OrderPaymentOrchestrationCompat::class, 'recordAutoConfirmTender', 1))
        ->toBe('string');
});

it('phễu PayPay chỉ còn MỘT cửa, và cửa đó nhận id', function () {
    // Cái vỏ `recordPayPayPayment(CustomerOrder)` chỉ đọc `$order->id` rồi khoá
    // lại đúng hàng ấy — nó chưa bao giờ là một đường thứ hai. Để nó lại là để
    // một chỗ gọi mới lặng lẽ cầm model trở lại.
    expect(method_exists(OrderPaymentService::class, 'recordPayPayPayment'))->toBeFalse();
    expect(boundary1594ParamType(OrderPaymentService::class, 'recordPayPayPaymentByOrderId', 0))
        ->toBe('string');
});

it('Payments không còn cầm bộ tính chia hoá đơn của Ordering', function () {
    $types = array_map(
        static fn (ReflectionParameter $p): string => (string) $p->getType(),
        (new ReflectionMethod(OrderPaymentService::class, '__construct'))->getParameters(),
    );

    expect($types)->not->toContain(SplitByItemsCalculator::class)
        ->and($types)->toContain(OrderSplitBillTotals::class);
});

it('cổng chia hoá đơn bind được — cổng không có hiện thực là cổng trang trí', function () {
    expect(app(OrderSplitBillTotals::class))->toBeInstanceOf(OrderSplitBillTotals::class);
});

it('cổng chia hoá đơn trả ĐÚNG con số bộ tính cũ trả', function () {
    // Chỗ duy nhất trong lát cắt này chạm tiền. `computeBillTotal()` là thứ
    // `OrderPaymentService` so với số khách gửi lên để bật/tắt 422
    // `split_by_items_total_mismatch` — lệch một yên là hoặc chặn nhầm một lần
    // trả đúng, hoặc nhận một lần trả sai.
    $order = CustomerOrder::factory()->create([
        'subtotal' => 3000,
        'discount_amount' => 0,
        'total_amount' => 3300,
        'paid_amount' => 0,
        'guest_count' => 2,
    ]);

    $items = collect([1000.0, 2000.0])->map(fn (float $price): CustomerOrderItem => CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => $price,
        'subtotal' => $price,
        'status' => 'pending',
    ]));

    $allocations = [['item_id' => (string) $items[0]->id, 'units' => 1]];

    $viaPort = app(OrderSplitBillTotals::class)->billTotalFor(
        (string) $order->id,
        $allocations,
        0,
        'auto',
        'JPY',
        0.0,
        0.0,
        2,
    );

    $viaCalculator = app(SplitByItemsCalculator::class)->computeBillTotal(
        $order->fresh()->load('items'),
        $allocations,
        0,
        'auto',
        'JPY',
        0.0,
        0.0,
        2,
    );

    expect($viaPort)->toBe($viaCalculator)
        ->and($viaPort)->toBeGreaterThan(0.0);
});

it('đơn không tồn tại trả 0.0, không ném', function () {
    // Ngữ nghĩa cũ: `$result['bills'][$billIndex]['total'] ?? 0.0`. Ném ở đây sẽ
    // biến một đơn vừa bị xoá thành 500 giữa đường tạo thanh toán.
    expect(app(OrderSplitBillTotals::class)->billTotalFor(
        (string) Str::uuid(),
        [['item_id' => (string) Str::uuid(), 'units' => 1]],
        0,
        'auto',
        'JPY',
        0.0,
        0.0,
        1,
    ))->toBe(0.0);
});

it('ảnh chụp mang theo hạn thanh toán — nếu không, R24 mất đầu vào', function () {
    // Hai quyết định về tiền đọc cột này: từ chối mint khi cửa sổ đã đóng, và
    // không cho mã QR sống lâu hơn đơn nó thu hộ. Bỏ nó khỏi ảnh chụp thì
    // Payments phải cầm lại `CustomerOrder` chỉ để đọc một cột.
    $dueAt = now()->addMinutes(3);
    $order = CustomerOrder::factory()->create(['payment_due_at' => $dueAt]);
    $openEnded = CustomerOrder::factory()->create(['payment_due_at' => null]);

    $port = app(OrderQueryPort::class);

    expect($port->findById((string) $order->organization_id, (string) $order->id)->paymentDueAt()?->getTimestamp())
        ->toBe($dueAt->getTimestamp())
        ->and($port->findById((string) $openEnded->organization_id, (string) $openEnded->id)->paymentDueAt())
        ->toBeNull();
});

it('baseline deptrac KHÔNG còn ghi hai cạnh vừa trả', function () {
    // Baseline chỉ được co lại. Một PR sau vô tình dựng lại cạnh sẽ phải THÊM
    // dòng vào đây để deptrac xanh — bài này bắt đúng lúc đó.
    $baseline = (string) file_get_contents(base_path('deptrac-baseline.yaml'));

    expect($baseline)->not->toContain('App\Services\Customer\PayPayPaymentService:')
        ->and($baseline)->not->toContain('- App\Services\Customer\SplitByItemsCalculator');
});
