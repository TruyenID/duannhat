<?php

declare(strict_types=1);

/**
 * #1595 — cổng `OrderLineStockDeduction` nhận ID, không nhận model.
 *
 * Chữ ký đổi từ model sang id kéo theo một hành vi MỚI mà trước đây không tồn
 * tại: id trỏ vào một dòng/lý-do không còn nữa. Adapter chọn **no-op im lặng**,
 * và đó là quyết định cần ghim — caller là Ordering đang ở giữa transaction bán
 * hàng, ném ở đây sẽ cuộn ngược cả việc bán vì một dòng vừa bị xoá song song.
 *
 * Ghim luôn cả việc binding có thật: một cổng công bố mà không ai bind là cái
 * bẫy #1544 đã bắt được (`OrderQueryPort` từng có 0 hiện thực, 0 binding, và
 * vẫn qua cổng vì cổng chỉ soi chữ ký).
 */

use App\Omnify\Enums\StockDeductionTimingEnum;
use App\Services\Inventory\Contracts\OrderLineStockDeduction;
use App\Services\Order\Contracts\OrderStockMarker;

it('cả hai cổng đều có binding thật, không phải interface rỗng', function () {
    expect(app(OrderLineStockDeduction::class))->toBeInstanceOf(OrderLineStockDeduction::class)
        ->and(app(OrderStockMarker::class))->toBeInstanceOf(OrderStockMarker::class);
});

it('id không tồn tại là no-op, KHÔNG ném — caller đang ở giữa transaction bán hàng', function () {
    $port = app(OrderLineStockDeduction::class);
    $marker = app(OrderStockMarker::class);
    $ghost = '00000000-0000-4000-8000-000000000000';

    $port->deductLine($ghost, 'on_add');
    $port->adjustDeductedLineQuantity($ghost, 1.0);
    $port->compensateVoid($ghost, null);
    $port->compensateVoid($ghost, $ghost);
    $marker->stampOrderStockOutTransaction($ghost, 'tx-1');
    $marker->markLineDeducted($ghost, now(), 'tx-1');

    // #1666 — hai method của pha đóng đơn đi theo ĐƠN, không theo dòng, nên
    // chúng có đường "đơn không còn" riêng và phải im lặng y hệt.
    $port->sweepUndeductedLinesAtClose($ghost);
    $port->recordSalesGenealogy($ghost, 'tx-1');
    $port->recordSalesGenealogy($ghost, 'tx-1', []);
    $port->recordSalesGenealogy($ghost, 'tx-1', [$ghost]);

    // Không có assertion nào mạnh hơn "không ném" ở đây — đó CHÍNH LÀ hợp đồng.
    expect(true)->toBeTrue();
});

it('chi nhánh chưa cấu hình thì hạ về on_close, không nổ', function () {
    expect(app(OrderLineStockDeduction::class)->timingForBranch('00000000-0000-4000-8000-000000000001'))
        ->toBe(StockDeductionTimingEnum::OnClose);
});

it('hasReachedPreparing giữ nguyên ngưỡng của StockDeductionService', function () {
    $port = app(OrderLineStockDeduction::class);

    // Ordering từng gọi `StockDeductionService::statusHasReachedPreparing()` dạng
    // STATIC. Nếu ngưỡng ở cổng lệch khỏi bản static, một dòng sẽ trừ kho sai
    // thời điểm — sai lặng lẽ, vì cả hai đều trả bool hợp lệ.
    expect($port->hasReachedPreparing('pending'))->toBeFalse()
        ->and($port->hasReachedPreparing('preparing'))->toBeTrue()
        ->and($port->hasReachedPreparing('ready'))->toBeTrue()
        ->and($port->hasReachedPreparing('served'))->toBeTrue();
});
