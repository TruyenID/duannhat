<?php

use App\Http\Controllers\Api\V1\HQ\FaqController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  HQ - FAQ (#1504 — màn hình quản trị Câu hỏi thường gặp)
//
//  FAQ KHÔNG phải bảng riêng: mỗi câu hỏi là một dòng `posts` thuộc
//  PostCategory slug `faq` — đúng nguồn mà `/api/v1/customer/posts?category=faq`
//  đọc (#1441, #1486). Controller dịch giữa từ vựng FAQ
//  (question/answer/is_published) và cột thật, nên phía admin không phải biết
//  gì về post/category/tag.
//
//  Lọc theo `organization_id`, KHÔNG theo brand: bảng `posts` không có
//  `brand_id` và API khách cũng không lọc theo brand. Route vẫn nằm dưới
//  `/hq/{brandSlug}/` cho khớp convention và để admin-web dùng chung context
//  — đây là chủ ý, không phải quên.
// =========================================================================

Route::prefix('faqs')->name('api.v1.hq.faqs.')->group(function () {
    Route::get('/', [FaqController::class, 'index'])->name('index');
    Route::post('/', [FaqController::class, 'store'])->name('store');
    Route::patch('{faq}', [FaqController::class, 'update'])->name('update');
    Route::delete('{faq}', [FaqController::class, 'destroy'])->name('destroy');
});
