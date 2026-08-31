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
    'failed' => 'ログイン情報が正しくありません。',
    'password' => 'パスワードが正しくありません。',
    'throttle' => '試行回数が多すぎます。:seconds秒後にもう一度お試しください。',

    /*
     * `customers.phone` KHÔNG unique nên một số có thể ứng với nhiều tài khoản.
     * Không bao giờ xác thực một định danh mơ hồ; câu này nói THẲNG việc cần
     * làm, vì nếu chỉ báo "sai mật khẩu" thì nhóm khách này không đời nào đoán
     * ra lý do.
     */
    'phone_ambiguous' => 'この電話番号は複数のアカウントに登録されています。メールアドレスでログインしてください。',

    // #1784 — đăng nhập Google. Thông điệp cố ý KHÔNG phân biệt được
    // nguyên nhân kỹ thuật (aud sai / chữ ký hỏng / hết hạn): chi tiết vào log.
    'google_disabled' => 'Googleログインは現在ご利用いただけません。',
    'google_failed' => 'Googleアカウントを確認できませんでした。もう一度お試しください。',
    'google_email_unverified' => 'このGoogleアカウントのメールアドレスは確認されていないため、ログインにご利用いただけません。',
    'google_ambiguous' => 'このメールアドレスは複数のアカウントに登録されています。メールアドレスとパスワードでログインしてください。',
];
