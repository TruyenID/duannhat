<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Customer Web
    |--------------------------------------------------------------------------
    |
    | #1680 — nơi Customer Web đang chạy. Backend cần biết địa chỉ này vì link
    | xác nhận email đi tới API (route có chữ ký) rồi phải TRẢ KHÁCH VỀ giao
    | diện: bấm link trong Gmail mà nhận lại một cục JSON là ngõ cụt.
    |
    | Bỏ trống là hợp lệ và có chủ đích — máy dev / test không cắm Customer Web
    | thì `verify` trả JSON như trước thay vì chuyển hướng vào một URL rỗng.
    | Không có giá trị mặc định trỏ localhost: một URL sai mà "chạy được" sẽ
    | lặng lẽ đưa khách thật ra ngoài hệ thống.
    |
    */

    'web_url' => rtrim((string) env('CUSTOMER_WEB_URL', ''), '/'),

    /*
    |--------------------------------------------------------------------------
    | Xác nhận email
    |--------------------------------------------------------------------------
    |
    | Mã 6 chữ số gửi vào hộp thư khách sau khi đăng ký. 10 phút là khoảng đủ
    | để mở Gmail trên chính chiếc điện thoại đang mở trang đăng ký, và đủ ngắn
    | để việc dò một không gian 10^6 không kịp đi tới đâu. Đừng nới rộng nó để
    | "cho khách thoải mái" — nút gửi lại mới là câu trả lời cho khách chậm.
    |
    */

    'verification' => [
        'code_ttl_minutes' => (int) env('CUSTOMER_VERIFICATION_CODE_TTL_MINUTES', 10),
    ],

];
