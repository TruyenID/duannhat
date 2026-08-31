<?php

return [
    /*
    |--------------------------------------------------------------------------
    | #1441 — Điểm tích luỹ + hạng thành viên
    |--------------------------------------------------------------------------
    |
    | Các mốc hạng sống ở config chứ không ở DB: chính sách của một nhà vận
    | hành, đổi vài lần một năm, và khi đổi thì phải đổi CÓ CHỦ Ý kèm review.
    |
    | TỈ LỆ TÍCH ĐIỂM thì KHÔNG còn thuần config nữa (#1674). Nó đã xuống
    | `brands.point_earn_amount` + `brands.point_earn_points` để HQ tự chỉnh
    | trong admin, đúng như ghi chú cũ ở đây dự liệu. Những gì còn lại dưới
    | `earn` là MẶC ĐỊNH CẢ HỆ THỐNG — brand nào chưa cấu hình thì rơi về đây.
    | Nguồn chân lý của phép quy đổi là `CustomerPointService::earnRateFor()`.
    |
    | `enabled=false` ⇒ không tích, không đổi, endpoint trả 404. Sổ cái đã có
    | KHÔNG bị xoá — tắt là ngừng phát sinh, không phải huỷ điểm của khách.
    */

    'enabled' => env('LOYALTY_POINTS_ENABLED', true),

    'earn' => [
        /*
        | Cơ sở tính điểm:
        |   subtotal — tiền hàng sau giảm giá, TRƯỚC thuế (mặc định). Khách
        |              không tích điểm trên phần thuế mình nộp hộ nhà nước.
        |   total    — tổng cuối cùng đã gồm thuế + phí phục vụ.
        */
        'basis' => env('LOYALTY_EARN_BASIS', 'subtotal'),

        /*
        | Bao nhiêu tiền đổi được 1 điểm, THEO TỪNG ĐƠN VỊ TIỀN. Một con số
        | duy nhất là sai: shop Nhật và shop Việt dùng chung backend này, mà
        | 100 JPY và 100 VND lệch nhau hai bậc độ lớn — xem
        | `docs/guide/business-time.md` cho cùng lớp lỗi ở phía thời gian.
        |
        | Đơn vị tiền lấy từ ShopOrderSetting.currency_code của chi nhánh bán
        | hàng, không phải từ locale của khách.
        |
        | #1674 — tầng này ngầm định mẫu số **1 điểm**, và CHỈ chạy khi brand
        | chưa cấu hình cặp của riêng nó. Đúng vì brand chưa cấu hình thì phải
        | nhận mặc định hợp lý cho nước nó bán hàng; brand đa quốc gia muốn
        | tỉ lệ khác nhau theo nước thì vẫn phải chỉnh ở đây (cặp trên `brands`
        | là MỘT cho cả brand — đánh đổi đã chốt ở #1674).
        */
        'amount_per_point' => [
            'JPY' => 100,
            'VND' => 10000,
            'USD' => 1,
        ],

        // Dùng khi chi nhánh chưa cấu hình currency_code.
        'default_amount_per_point' => 100,
    ],

    /*
    | Hạng thành viên — suy ra từ TỔNG ĐIỂM ĐÃ TÍCH (lifetime), không phải số
    | dư hiện tại. Tiêu điểm không làm khách tụt hạng; hoàn tiền thì có (bút
    | toán `revoke` mang dấu âm và cũng nằm trong tổng lifetime).
    |
    | `benefits` là các KHOÁ i18n, không phải câu chữ: customer-web dịch qua
    | `membership.benefits.<key>` ở messages/{ja,en,vi}.json. Câu chữ nằm cùng
    | chỗ với mọi câu chữ khác của app thay vì rải một nửa xuống config PHP.
    |
    | Bảng phải xếp theo `min_lifetime_points` TĂNG DẦN — service lấy hạng cao
    | nhất mà khách với tới.
    */
    'tiers' => [
        [
            'key' => 'bronze',
            'min_lifetime_points' => 0,
            'benefits' => ['earn_points', 'order_history'],
        ],
        [
            'key' => 'silver',
            'min_lifetime_points' => 500,
            'benefits' => ['earn_points', 'order_history', 'birthday_coupon'],
        ],
        [
            'key' => 'gold',
            'min_lifetime_points' => 2000,
            'benefits' => ['earn_points', 'order_history', 'birthday_coupon', 'priority_support'],
        ],
        [
            'key' => 'platinum',
            'min_lifetime_points' => 5000,
            'benefits' => ['earn_points', 'order_history', 'birthday_coupon', 'priority_support', 'exclusive_menu'],
        ],
    ],
];
