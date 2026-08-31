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
    'failed' => 'Thông tin đăng nhập không đúng.',
    'password' => 'Mật khẩu không đúng.',
    'throttle' => 'Bạn đã thử quá nhiều lần. Vui lòng thử lại sau :seconds giây.',

    /*
     * `customers.phone` KHÔNG unique nên một số có thể ứng với nhiều tài khoản.
     * Không bao giờ xác thực một định danh mơ hồ; câu này nói THẲNG việc cần
     * làm, vì nếu chỉ báo "sai mật khẩu" thì nhóm khách này không đời nào đoán
     * ra lý do.
     */
    'phone_ambiguous' => 'Số điện thoại này đang gắn với nhiều tài khoản. Vui lòng đăng nhập bằng email.',

    // #1784 — đăng nhập Google. Thông điệp cố ý KHÔNG phân biệt được
    // nguyên nhân kỹ thuật (aud sai / chữ ký hỏng / hết hạn): chi tiết vào log.
    'google_disabled' => 'Đăng nhập bằng Google chưa được bật.',
    'google_failed' => 'Không xác minh được tài khoản Google. Vui lòng thử lại.',
    'google_email_unverified' => 'Google chưa xác nhận địa chỉ email của tài khoản này, nên chúng tôi không thể dùng nó để đăng nhập.',
    'google_ambiguous' => 'Địa chỉ email này đang gắn với nhiều tài khoản. Vui lòng đăng nhập bằng email và mật khẩu.',
];
