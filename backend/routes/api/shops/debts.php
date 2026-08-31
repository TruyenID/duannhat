<?php

use App\Http\Controllers\Api\V1\Shop\DebtController;
use Illuminate\Support\Facades\Route;

/*
 * Plan-038 T10.5 — debt reporting endpoints. Mounted under
 *   /api/v1/shops/{shopSlug}/debts
 *
 * Đây là cửa cho ADMIN (Platform SSO). Cửa cho POS nằm ở `routes/api/pos.php`
 * và dùng device token — hai namespace tồn tại vì hai cách xác thực, không phải
 * vì hai tập dữ liệu.
 *
 * ⚠️ Câu cũ ở đây viết rằng nhóm route này "powers admin-web's Công nợ khách
 * hàng panel". Đo lại 2026-08-06 (#1998): màn đó **chưa bao giờ tồn tại** —
 * 0 lời gọi `/shops/{slug}/debts` trong admin-web, 0 lần chuỗi 「Công nợ khách
 * hàng」 xuất hiện trong locale. Câu ấy mô tả một dự định, không mô tả cây.
 */
Route::prefix('debts')->name('api.v1.shops.debts.')->group(function () {
    Route::get('/', [DebtController::class, 'index'])->name('index');

    // Đơn KHÁCH TRẢ CHƯA ĐỦ — tiền quán được nợ mà không sổ nợ nào thấy, vì nó
    // nằm trên `customer_orders`, không nằm trên `order_payments`.
    //
    // Cố ý KHÔNG cộng vào tổng của `/debts`: nợ trên tài khoản được cấp có chủ
    // đích ≠ đơn không ai đóng (luật #1990). Gộp hai thứ lại thì người quản lý
    // mất khả năng phân biệt "đã đồng ý cho nợ" với "sót".
    //
    // PHẢI nằm TRÊN `{customer}`: wildcard sẽ nuốt đoạn literal và "part-paid"
    // bị đọc thành một uuid khách. Cùng cái bẫy đã ghi ở `pos.php`.
    Route::get('part-paid', [DebtController::class, 'partPaid'])->name('part-paid');

    // One customer's individual debts — the rows the grouped total is built
    // from, each carrying the payment id a settlement must reference.
    Route::get('{customer}', [DebtController::class, 'show'])->name('show');
});
