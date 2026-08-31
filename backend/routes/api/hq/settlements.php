<?php

use App\Http\Controllers\Api\V1\HQ\SettlementController;
use Illuminate\Support\Facades\Route;

/*
| Đường đọc tầng settlement (#1370, plan-050 M5 T5.0).
|
| Phạm vi HQ/brand, không phải shop: settlement gắn với GATEWAY CONNECTION —
| pháp nhân nhận tiền về tài khoản ngân hàng — và connection là cấp brand
| (`payment_gateway_connections.brand_id`). Đây là cách Stripe/Adyen/Square
| đều phân tầng: giao dịch thì thuộc điểm bán, còn payout và phí thuộc chủ
| tài khoản. Đường shop-scope xem `docs/guide/gateway-settlement.md`.
|
| Phân quyền đi theo `PaymentGatewayConnection`, KHÔNG dựng permission mới:
| ai được xem connection thì được xem tiền của connection đó. Một trục quyền
| thứ hai cho cùng một tài sản là chỗ để hai trục lệch nhau về sau.
*/

Route::get('settlements', [SettlementController::class, 'index'])
    ->name('api.v1.hq.settlements.index');

Route::get('settlements/batches', [SettlementController::class, 'batches'])
    ->name('api.v1.hq.settlements.batches');

Route::get('settlements/payouts', [SettlementController::class, 'payouts'])
    ->name('api.v1.hq.settlements.payouts');

Route::get('settlements/aging', [SettlementController::class, 'aging'])
    ->name('api.v1.hq.settlements.aging');

Route::get('settlements/export', [SettlementController::class, 'export'])
    ->name('settlements.export');

Route::get('settlements/batches/export', [SettlementController::class, 'batchesExport'])
    ->name('settlements.batches.export');

Route::get('settlements/payouts/export', [SettlementController::class, 'payoutsExport'])
    ->name('settlements.payouts.export');
