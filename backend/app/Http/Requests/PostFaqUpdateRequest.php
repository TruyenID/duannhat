<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

/**
 * #1504 — sửa một câu hỏi thường gặp.
 *
 * Cùng bộ luật với `PostFaqStoreRequest`, **bỏ** ràng buộc "phải có câu hỏi ở ít
 * nhất một ngôn ngữ": một PATCH hợp lệ có thể chỉ bật/tắt hiển thị hoặc ghim,
 * không đụng gì tới nội dung.
 *
 * Câu hỏi vẫn không xoá được về rỗng — `post_translations.title` là NOT NULL,
 * nên `FaqController::applyTranslations()` coi chuỗi rỗng là "không đổi". Muốn
 * bỏ hẳn một câu hỏi thì dùng DELETE.
 */
class PostFaqUpdateRequest extends PostFaqStoreRequest
{
    public function withValidator(Validator $validator): void
    {
        // Không kiểm gì thêm — xem docblock.
    }
}
