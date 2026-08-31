<?php

declare(strict_types=1);

use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Order\Commands\ReopenOrderCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Internal\OrderMutationContextFactory;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * #2479 — `checkout` → `open`, đường ngược của checkout.
 *
 * ## Vì sao cửa này tồn tại
 *
 * #2471 gộp luồng thanh toán từ 3 chạm còn 1, nên cú chạm "Tính tiền" ĐẦU TIÊN
 * giờ đã `POST /checkout` luôn — trước đó nó chỉ mở một form đóng lại được. Chạm
 * nhầm khi khách còn đang gọi thêm món thì đường thoát duy nhất là **huỷ cả đơn
 * rồi gõ lại trước mặt khách**, và huỷ đơn còn kéo theo lý do huỷ + dấu vết cho
 * một việc vốn không phải sự cố.
 *
 * ## Ruling chủ dự án 2026-08-12
 *
 * - chỉ từ `checkout`, không phải nút "về open" vạn năng
 * - chỉ khi đơn KHÔNG còn giữ đồng nào — vị ngữ dùng chung với void
 *   (`netCollectedForOrder() > 0`), không bịa cách đếm thứ hai
 * - bắt buộc lý do + ghi audit
 * - KHÔNG bắt quyền quản lý: bắt gọi quản lý mỗi lần chạm nhầm sẽ đẩy nhân viên
 *   sang huỷ-rồi-gõ-lại, một đường để lại dấu vết TỆ HƠN
 */
function reopenTestOrder(CustomerOrderStatusEnum $status): CustomerOrder
{
    return CustomerOrder::factory()->create(['status' => $status->value]);
}

function reopenIt(CustomerOrder $order, string $reason = 'chạm nhầm Tính tiền'): void
{
    app(OrderMutationFacade::class)->reopen(new ReopenOrderCommand(
        OrderMutationContextFactory::fromOrder($order, actorId: null),
        (string) $order->id,
        $reason,
    ));
}

it('#2479 — đơn checkout CHƯA thu đồng nào thì mở lại được, và sửa được ngay sau đó', function () {
    $order = reopenTestOrder(CustomerOrderStatusEnum::Checkout);

    reopenIt($order);

    expect($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Open);
});

it('#2479 — đơn ĐANG GIỮ tiền thì KHÔNG mở lại được', function () {
    $order = reopenTestOrder(CustomerOrderStatusEnum::Checkout);

    OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'branch_id' => $order->branch_id,
        'amount' => 1000,
        'status' => 'succeeded',
    ]);

    // Cùng vị ngữ mà void dùng — một đơn đã nhận tiền thì đường ra là hoàn tiền,
    // không phải mở lại.
    expect(fn () => reopenIt($order))->toThrow(HttpException::class);

    expect($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Checkout);
});

it('#2479 — không mở lại được từ trạng thái khác checkout', function (string $status) {
    $order = reopenTestOrder(CustomerOrderStatusEnum::from($status));

    expect(fn () => reopenIt($order))->toThrow(HttpException::class);
})->with([
    'open' => [CustomerOrderStatusEnum::Open->value],
    'closed' => [CustomerOrderStatusEnum::Closed->value],
]);

it('#2479 — mỗi lần mở lại để lại audit kèm LÝ DO', function () {
    $order = reopenTestOrder(CustomerOrderStatusEnum::Checkout);

    reopenIt($order, 'khách gọi thêm món');

    $audit = DB::table('audit_logs')
        ->where('auditable_id', $order->id)
        ->where('action', 'reopened')
        ->first();

    expect($audit)->not->toBeNull('mở lại mà không để dấu vết = đường sửa bill không ai thấy');

    $meta = json_decode((string) $audit->metadata, true) ?: [];
    expect($meta['reason'] ?? null)->toBe('khách gọi thêm món')
        ->and($meta['from'] ?? null)->toBe(CustomerOrderStatusEnum::Checkout->value);
});

it('#2479 — lý do rỗng bị chặn ngay ở command, không tới được DB', function () {
    $order = reopenTestOrder(CustomerOrderStatusEnum::Checkout);

    expect(fn () => reopenIt($order, ''))->toThrow(InvalidArgumentException::class);

    expect($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Checkout);
});
