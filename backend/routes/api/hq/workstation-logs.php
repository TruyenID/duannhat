<?php

use App\Http\Controllers\Api\V1\HQ\WorkstationLogController;
use Illuminate\Support\Facades\Route;

/*
| #2901 — điều tra sự cố máy trạm từ xa.
|
| Trước file này, muốn xem log của một máy trạm phải ngồi trước chính máy đó
| tại quán: `slog` ra stderr, không ghi file, và trong 42 endpoint máy trạm gọi
| không cái nào nhận log. Fleet production là hai máy Windows ở hai quán.
|
| Ba route, tất cả đều ở tầng HQ vì đây là việc của người quản lý chứ không
| phải của một ca bán hàng:
|
|   POST workstation-log-requests   — bấm "lấy log": ghi một yêu cầu TREO, máy
|                                     trạm sẽ tự nhận ở nhịp sync kế tiếp
|   GET  workstation-log-requests   — yêu cầu nào đã trả lời, yêu cầu nào hết hạn
|   GET  workstation-log-records    — đọc những dòng đã về
|
| KHÔNG có route xoá, và đó là ràng buộc chứ không phải thiếu sót: hạn giữ 14
| ngày do `workstation-logs:prune` cưỡng chế đều đặn, còn một nút xoá tay trên
| một bề mặt điều tra là cách để dấu vết biến mất đúng lúc nó quan trọng.
|
| Quyền: `shop.manage` qua policy — cùng cổng vai đã có (`RoleTemplateMatrix`),
| không phát minh permission slug mới.
*/

Route::post('workstation-log-requests', [WorkstationLogController::class, 'store'])
    ->name('api.v1.hq.workstation-log-requests.store');

Route::get('workstation-log-requests', [WorkstationLogController::class, 'indexRequests'])
    ->name('api.v1.hq.workstation-log-requests.index');

Route::get('workstation-log-records', [WorkstationLogController::class, 'indexRecords'])
    ->name('api.v1.hq.workstation-log-records.index');
