<?php

use App\Http\Controllers\Api\V1\Shop\ShopFaqController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-domain routes — FAQ riêng của chi nhánh (#1673).
 *
 * Mounted from routes/api.php inside the shops/{shopSlug} prefix.
 *
 * Khác `/hq/{brand}/faqs` ở đúng một điểm: mọi câu hỏi ở đây mang
 * `posts.branch_id = <chi nhánh trên URL>`. Câu của HQ (`branch_id IS NULL`)
 * hiện trong `index` dưới dạng CHỈ ĐỌC khi `branches.faq_inherit_hq` bật, và
 * mọi thao tác ghi lên chúng đều trả 404 — chi nhánh không sửa được thứ nó chỉ
 * kế thừa.
 *
 * Công tắc kế thừa nằm ở `PATCH /shops/{shopSlug}/settings/branch`
 * (`faq_inherit_hq`), cùng chỗ với các cài đặt chi nhánh khác — không dựng
 * endpoint riêng cho một cái boolean.
 */

Route::prefix('faqs')->name('api.v1.shops.faqs.')->group(function () {
    Route::get('/', [ShopFaqController::class, 'index'])->name('index');
    Route::post('/', [ShopFaqController::class, 'store'])->name('store');
    Route::patch('{faq}', [ShopFaqController::class, 'update'])->name('update');
    // #1684 — ẩn/hiện MỘT câu kế thừa từ HQ, cho riêng chi nhánh này.
    //
    // `{faq}` ở trên KHÔNG nuốt đường này: hai mẫu khác số đoạn nên thứ tự khai
    // báo không ảnh hưởng. Điều đáng nói là `{faq}` ở đây mang nghĩa NGƯỢC với
    // ba route trên — trên là câu RIÊNG của chi nhánh, dưới là câu của HQ mà
    // chi nhánh chỉ mượn (xem `visibility()`, BR-FB04).
    Route::patch('{faq}/visibility', [ShopFaqController::class, 'visibility'])->name('visibility');
    Route::delete('{faq}', [ShopFaqController::class, 'destroy'])->name('destroy');
});
