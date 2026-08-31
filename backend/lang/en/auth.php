<?php

/*
 * #1782 — chuỗi xác thực.
 *
 * Trước bản này repo KHÔNG có file auth.php trong lang/, nên `auth.failed` dùng bản
 * tiếng Anh mặc định của framework. Tạo file mới mà chỉ chứa một khoá sẽ CHE
 * MẤT bản đó: khoá thiếu không rơi ngược về vendor, nên `__('auth.failed')` sẽ
 * trả ra đúng chuỗi `auth.failed` cho khách nhìn thấy.
 *
 * Vì vậy ba khoá của framework được chép đủ ở cả ba ngôn ngữ.
 */

return [
    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    /*
     * `customers.phone` KHÔNG unique nên một số có thể ứng với nhiều tài khoản.
     * Không bao giờ xác thực một định danh mơ hồ; câu này nói THẲNG việc cần
     * làm, vì nếu chỉ báo "sai mật khẩu" thì nhóm khách này không đời nào đoán
     * ra lý do.
     */
    'phone_ambiguous' => 'This phone number matches more than one account. Please sign in with your email address.',

    // #1784 — đăng nhập Google. Thông điệp cố ý KHÔNG phân biệt được
    // nguyên nhân kỹ thuật (aud sai / chữ ký hỏng / hết hạn): chi tiết vào log.
    'google_disabled' => 'Google sign-in is not available.',
    'google_failed' => 'We could not verify that Google account. Please try again.',
    'google_email_unverified' => 'Google has not verified the email address on this account, so we cannot use it to sign in.',
    'google_ambiguous' => 'This email address matches more than one account. Please sign in with your email and password.',
];
