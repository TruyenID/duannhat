<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Bản phát hành mong đợi (#1806 S4)
    |--------------------------------------------------------------------------
    |
    | "Máy trạm đang chạy bản nào, và bản đó có cũ không" là câu hỏi mà HQ trả
    | lời, KHÔNG phải máy trạm tự khám phá. Ruling #1806 S4 (2026-08-05) chọn
    | hướng HQ-đẩy-xuống, bác hai hướng kia:
    |
    |   - nhét số hiệu bản vào feed `branch` trộn vòng đời CẤU HÌNH QUÁN với
    |     vòng đời PHÁT HÀNH; chúng sẽ kéo nhau invalidate cache vô cớ;
    |   - để máy trạm tự hỏi tag GitHub bắt mọi máy ở mọi quán gọi ra Internet
    |     tới một dịch vụ ta không kiểm soát — trong khi "mất Internet nhưng
    |     LAN vẫn chạy" là trạng thái BÌNH THƯỜNG ở đây.
    |
    | Phiên bản mong đợi là một QUYẾT ĐỊNH VẬN HÀNH: chỉ HQ biết đã kiểm thử
    | tới đâu, quán nào nâng trước, và khi nào cần giữ một quán ở bản cũ vì lý
    | do nghiệp vụ. Đẩy từ trung tâm cho phép rollout theo nhóm và dừng khẩn —
    | hai thứ mà cả hai hướng kia đều không làm được.
    |
    | ĐỂ TRỐNG = KHÔNG cảnh báo gì. Fail-safe có chủ đích: một quán không nhận
    | được thông tin phiên bản vẫn phải bán được hàng. Biến "không biết mình có
    | cũ không" thành "không bán được" là tự tạo sự cố tệ hơn thứ đang cảnh báo.
    |
    | HÔM NAY đây là giá trị TOÀN NỀN TẢNG. Rollout theo nhóm cần một giá trị
    | per-org; chỗ nối đã sẵn (`ExpectedBuildController` đọc config qua một hàm
    | duy nhất), nhưng đừng nhầm ý định với hiện trạng: hiện chưa có per-org.
    |
    */

    'expected_build' => [

        // Tên tag của bản mong đợi, ví dụ `v0.3.0`. Trống = không cảnh báo.
        'version' => env('WORKSTATION_EXPECTED_VERSION'),

        /*
         * Mức nghiêm trọng do NỘI DUNG bản vá quyết định, KHÔNG phải khoảng
         * cách phiên bản.
         *
         * Trễ ba bản vá lỗi chính tả là `info`; trễ một bản vá lỗi tiền là
         * `critical`. Suy mức từ số bản trễ nghe có vẻ khách quan nhưng nó đo
         * sai thứ — và nó sẽ dạy người vận hành bỏ qua cảnh báo đúng vào lần
         * cảnh báo quan trọng.
         */
        'severity' => env('WORKSTATION_EXPECTED_SEVERITY', 'info'),

        // Câu giải thích cho người đọc alert: bản này vá cái gì, vì sao nên nâng.
        'reason' => env('WORKSTATION_EXPECTED_REASON'),

        /*
         * #2635 — cờ RIÊNG cho tự-cài không người trông (02:00–04:00 giờ QUÁN,
         * không còn ca mở). KHÔNG phải một mức severity, và cố ý không suy từ
         * severity: severity trả lời "bản vá tệ đến mức nào", cờ này trả lời
         * "máy có được khởi động lại khi không ai đứng đó không". Gộp hai câu
         * đó nghĩa là mọi bản `critical` thành một lần khởi động lại lúc 2h
         * sáng — rộng hơn nhiều điều được chốt (ngoại lệ thu hẹp của ruling
         * #1806 S4). Mặc định `false`; HQ bật đích danh cho từng bản.
         */
        'auto_apply' => env('WORKSTATION_EXPECTED_AUTO_APPLY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Catalog bản phát hành máy trạm (#2428)
    |--------------------------------------------------------------------------
    |
    | ĐƯỜNG ĐỌC manifest — không phải đường phục vụ. URL tải vẫn luôn là
    | `/downloads/workstation/...` do Apache trả từ `public/`; chỗ này chỉ nói
    | đọc file mô tả ở đâu.
    |
    | Có mặt vì `manifest.json` là file ĐANG ĐƯỢC TRACK (`.gitignore` loại cả
    | thư mục nhưng un-ignore đúng file này). Test từng ghi đè nó tại chỗ rồi
    | khôi phục trong `finally` — một lượt chạy bị Ctrl-C hay bị kill để lại
    | manifest giả trong cây làm việc, và ở repo này nhiều session chạy song
    | song nên người tiếp theo thấy một thay đổi mình không tạo ra.
    |
    | Trống = mặc định sản xuất (`public/downloads/workstation/manifest.json`).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Trang tải máy trạm — nay là một trang Next, không còn Blade
    |--------------------------------------------------------------------------
    |
    | `page_url` là địa chỉ ĐẦY ĐỦ của trang tải trên admin-web (ví dụ
    | `https://tempo.godx.jp/downloads`). `GET /downloads` ở đây chỉ còn 301 tới
    | nó — các link đã phát ra ngoài (máy trạm tự nhắc "tải bản mới ở trang
    | /downloads", tài liệu, tin nhắn cho quán) vẫn phải tới đúng chỗ.
    |
    | KHÔNG có giá trị mặc định, cùng lý do với `customer.web_url`: một URL sai
    | mà "chạy được" sẽ lặng lẽ đẩy người cài đi nơi khác, và 301 thì trình
    | duyệt nhớ rất lâu. Trống ⇒ route trả 503 kèm đường dẫn file trực tiếp,
    | chứ không đoán.
    |
    | FILE KHÔNG ĐỔI CHỖ: `/downloads/workstation/...` vẫn do Apache trả từ
    | `public/`. Chỉ TRANG chuyển đi.
    |
    */

    'downloads' => [
        'manifest_path' => env('WORKSTATION_DOWNLOADS_MANIFEST_PATH'),
        'page_url' => env('WORKSTATION_DOWNLOADS_PAGE_URL'),
    ],

];
