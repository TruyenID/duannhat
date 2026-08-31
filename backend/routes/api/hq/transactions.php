<?php

use App\Http\Controllers\Api\V1\HQ\TransactionController;
use Illuminate\Support\Facades\Route;

/*
| T3 của #2876 (#2880) — tra cứu giao dịch toàn kênh, CHỈ ĐỌC.
|
| Tách khỏi `settlements.php` có chủ đích: file kia là quan hệ quán ↔ CỔNG
| (phí, payout, aging), file này là quan hệ quán ↔ KHÁCH. Hai sổ khác nhau
| (`docs/guide/gateway-settlement.md`), và gộp route sẽ mời người sau gộp luôn
| màn hình.
|
| Không có route ghi nào ở đây, và đó là ràng buộc chứ không phải thiếu sót:
| sửa tiền đi qua đường đã có, có lý do và có audit.
*/

Route::get('transactions', [TransactionController::class, 'index'])
    ->name('api.v1.hq.transactions.index');
