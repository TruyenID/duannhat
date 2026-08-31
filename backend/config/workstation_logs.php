<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Log máy trạm kéo về Cloud (#2901) — hạn giữ và các trần
|--------------------------------------------------------------------------
|
| Allowlist nằm ở file riêng (`workstation_log_allowlist.php`) vì nó là HỢP
| ĐỒNG hai đầu, còn đây là các số vận hành của riêng Cloud.
*/

return [

    /*
     * 14 ngày, chủ dự án chốt 2026-08-16.
     *
     * Log vận hành KHÔNG phải chứng từ kế toán: giữ vô hạn là tích luỹ rủi ro
     * PII mà không có lợi ích tương ứng. Cố ý khác `audit.retention_days`
     * (400 ngày, sàn PCI DSS v4.0 Req 10.5.1) — `audit_logs` là sổ tuân thủ,
     * bảng này là bộ đệm chẩn đoán.
     *
     * Mốc đếm là `logged_at` (lúc dòng ra đời TRÊN MÁY TRẠM), KHÔNG phải
     * `created_at`. Một quán mất mạng 10 ngày rồi mới đẩy được sẽ làm mọi dòng
     * "trẻ lại" 10 ngày nếu đếm theo lúc nhận — hạn 14 ngày lặng lẽ thành 24.
     */
    'retention_days' => (int) env('WORKSTATION_LOG_RETENTION_DAYS', 14),

    /*
     * Trần cứng của hạn giữ. Nâng `retention_days` lên trên mốc này là mở rộng
     * cửa sổ PII, nên nó phải là một quyết định có người ký chứ không phải một
     * biến môi trường ai cũng sửa được — lệnh `workstation-logs:prune` TỪ CHỐI
     * chạy khi vượt, và từ chối chứ không tự cắt xuống: im lặng "sửa hộ" một
     * cấu hình sai là cách chắc nhất để không ai biết nó sai.
     *
     * Ảnh gương của `audit.pci_floor_days` — bên kia là SÀN (giữ đủ lâu), bên
     * này là TRẦN (đừng giữ quá lâu). Hai bảng, hai nghĩa vụ ngược nhau.
     */
    'retention_max_days' => 30,

    'prune' => [
        // Xoá theo lô, khoá chính, đúng khuôn `audit:prune`: một
        // `DELETE ... WHERE logged_at < ?` không chặn trên bảng chỉ có lớn lên
        // sẽ giữ khoá suốt một khoảng không giới hạn.
        'chunk_size' => 500,
        // 0 = không trần. Bảng này nhỏ hơn `audit_logs` nhiều bậc (chỉ có hàng
        // khi ai đó bấm điều tra), nên mặc định để nó chạy tới cạn.
        'max_rows' => 0,
        'max_seconds' => 60,
        'pause_ms' => 50,
    ],

    /*
     * Trần MỘT LÔ trên đường `POST /workstation/log-records`. Hợp đồng wire
     * #2901 ghi 500; con số nằm ở đây để test đọc được một nguồn duy nhất.
     */
    'batch_max' => 500,

    /*
     * Trần cho TOÀN BỘ một yêu cầu, cộng qua mọi lô. Máy trạm phải tôn trọng
     * nó, và Cloud cũng cưỡng chế lại — cùng lý lẽ "không tin đầu kia lọc
     * đúng" của allowlist.
     */
    'request_max_records' => 2000,

    /*
     * Hạn mặc định của một yêu cầu khi HQ không nói rõ.
     *
     * Đủ dài để một máy trạm đang bận vẫn kịp nhận ở nhịp sync kế, đủ ngắn để
     * một yêu cầu bị quên không thành lệnh thường trực chuyển PII.
     */
    'request_ttl_hours' => 24,
];
