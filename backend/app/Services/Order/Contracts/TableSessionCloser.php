<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #2610 — đóng phiên dùng bàn (`table_sessions`), do Ordering công bố cho
 * Organization gọi.
 *
 * Cùng lý lẽ với [[TableStatusJournal]]: bảng thuộc **Ordering** (nó là vòng đời
 * phục vụ, và `CustomerOrder::table_session_id` trỏ vào nó), nhưng người quyết
 * "bàn này giờ trống" là `TableStatusService` ở Organization — nơi sở hữu
 * `Table`.
 *
 * ## Vì sao cổng này phải tồn tại
 *
 * Trước #2610, đổi bàn về `free` chỉ ghi `tables.status` và **không đụng session
 * đang mở**. Bốn route đều đi qua cùng một service:
 *
 *     POST /api/v1/pos/tables/{table}/status
 *     POST .../shops/{shop}/tables/{table}/status
 *     POST /api/v1/workstation/tables/{table}/status
 *     POST /api/v1/tms/tables/{table}/status
 *
 * Hệ quả đo được trên production 2026-08-12: bàn bị khai là trống trong khi
 * khách vẫn giữ session trên điện thoại. Session mồ côi sống tới **4 giờ** (chờ
 * `dine-in:expire-stale-sessions`), và trong khoảng đó một lần quét QR nữa mở
 * session THỨ HAI cho cùng cái bàn — `tables.status` nói một đằng,
 * `table_sessions` nói một nẻo, không bên nào là nguồn sự thật.
 *
 * ## Chỉ đóng, cố ý
 *
 * Không có method mở hay đọc. Mở session là việc của luồng QR khách
 * (`CustomerTableOrderService`), và thêm đường đọc vào đây sẽ mời người sau tra
 * cứu phiên phục vụ từ Organization — đúng thứ cổng này chặn.
 *
 * ## Không mở transaction riêng
 *
 * Chỗ gọi nằm BÊN TRONG transaction đã khoá `tables` (BR-T03). Một transaction
 * tách rời sẽ phá đúng bất biến ấy: session commit được trong khi bàn thì không,
 * và ta lại có thêm một kiểu lệch mới.
 */
interface TableSessionCloser
{
    /**
     * Đóng MỌI phiên đang mở của bàn. Trả về số phiên đã đóng.
     *
     * Idempotent: gọi lại trên bàn không còn phiên mở là no-op, trả 0. Bàn có
     * thể mang nhiều phiên mở cùng lúc — đó chính là tình trạng #2610 sinh ra
     * và bản vá này dọn, nên hàm đóng tất cả chứ không giả định chỉ có một.
     */
    public function closeOpenSessionsForTable(string $tableId): int;
}
