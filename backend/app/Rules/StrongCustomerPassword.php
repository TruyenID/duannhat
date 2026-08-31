<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Chính sách mật khẩu của tài khoản khách (#1780).
 *
 * Bốn điều kiện dưới đây là BẢN SAO CHÍNH XÁC của checklist mà customer-web
 * vẽ dưới ô mật khẩu (`components/password-checklist.tsx`). Hai bên phải khớp
 * từng điều kiện một: FE nghiêm hơn BE thì khách gõ đủ mà nút vẫn xám; BE
 * nghiêm hơn FE thì checklist xanh hết rồi submit ăn 422 — cả hai đều đọc ra
 * như "trang đăng ký hỏng", không ai nghĩ tới hai bộ luật lệch nhau.
 *
 * Cố ý KHÔNG dùng `Password::min(10)->mixedCase()`: `mixedCase` đòi cả chữ
 * thường lẫn chữ hoa, tức một điều kiện thứ năm mà checklist không hề nói ra.
 *
 * Trả về TẤT CẢ điều kiện trượt trong một lần, không dừng ở cái đầu tiên —
 * khách sửa một lỗi rồi ăn tiếp lỗi khác là cách nhanh nhất để họ bỏ cuộc.
 *
 * Mật khẩu CŨ (đặt hồi rule còn là `min:8`) không bị ảnh hưởng: đăng nhập chỉ
 * so hash, không validate lại. Rule này chỉ chặn mật khẩu MỚI.
 */
class StrongCustomerPassword implements ValidationRule
{
    public const MIN_LENGTH = 10;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('validation.custom.password.strong.min_length')->translate(['min' => self::MIN_LENGTH]);

            return;
        }

        // `mb_strlen` chứ không phải `strlen`: mật khẩu có dấu tiếng Việt hoặc
        // kana đếm theo byte sẽ dài gấp 2-3 lần số ký tự khách thực sự gõ, nên
        // `strlen` cho qua những mật khẩu ngắn hơn hạn mức.
        if (mb_strlen($value) < self::MIN_LENGTH) {
            $fail('validation.custom.password.strong.min_length')->translate(['min' => self::MIN_LENGTH]);
        }

        if (preg_match('/\p{Lu}/u', $value) !== 1) {
            $fail('validation.custom.password.strong.uppercase')->translate();
        }

        // "Có chữ và số" là MỘT điều kiện gồm hai vế, đúng như checklist hiển
        // thị — không tách thành hai dòng lỗi.
        if (preg_match('/\p{L}/u', $value) !== 1 || preg_match('/\p{N}/u', $value) !== 1) {
            $fail('validation.custom.password.strong.letters_and_numbers')->translate();
        }

        // Ký tự đặc biệt = mọi thứ không phải chữ, không phải số. Khoảng trắng
        // tính là đặc biệt: nó hợp lệ trong mật khẩu và người dùng passphrase
        // sẽ ngạc nhiên nếu không.
        if (preg_match('/[^\p{L}\p{N}]/u', $value) !== 1) {
            $fail('validation.custom.password.strong.symbol')->translate();
        }
    }
}
