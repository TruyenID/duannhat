<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Allowlist log máy trạm (#2901) — bản Cloud CHẠY
|--------------------------------------------------------------------------
|
| Bản KHAI là `docs/reference/workstation-log-allowlist.md` ở gốc repo — hai
| đầu (máy trạm Go + Cloud) cùng đọc bảng đó. File này là bản Cloud thật sự
| dùng lúc chạy, và `tests/Feature/Architecture/WorkstationLogAllowlistMatchesDocTest.php`
| phân tích chính file markdown ấy rồi so từng message + từng attr với mảng
| dưới đây. Lệch một ký tự là đỏ, nên hai file không trôi ra khỏi nhau được.
|
| VÌ SAO KHÔNG ĐỌC THẲNG FILE MARKDOWN LÚC CHẠY. Đường deploy
| (`.github/workflows/deploy-xserver.yml`) rsync **chỉ thư mục `backend/`** lên
| máy chủ; `docs/` ở gốc repo KHÔNG tồn tại trên production. Một allowlist đọc
| từ file không có sẽ rỗng — và vì cơ chế là fail-closed, rỗng nghĩa là Cloud
| từ chối MỌI dòng, im lặng, đúng lúc người ta cần đọc log nhất. Đây chính là
| loại lỗi mà repo này gọi tên: một bản vá trông như đã ship (#2777).
|
| LUẬT (giống hệt bản khai):
|   - `message` không có khoá ở đây  ⇒ BỎ DÒNG, đếm `rejected`, lô vẫn 202.
|   - attr không có trong danh sách  ⇒ BỎ ATTR, dòng vẫn lưu.
|   - danh sách rỗng `[]`            ⇒ message hợp lệ nhưng KHÔNG mang attr nào.
|
| KHÔNG khai `err` / `error`: chuỗi lỗi là văn bản tự do của bên thứ ba (thân
| phản hồi HTTP của feed `customers` chở tên/điện thoại/email khách; lỗi SQLite
| nhắc lại giá trị cột). Khai nó là nhét một lỗ blocklist vào giữa một cơ chế
| allowlist. Lý lẽ đầy đủ ở bản khai.
*/

return [

    /*
     * Khoá = `message` NGUYÊN VĂN của `slog`. Giá trị = danh sách attr được
     * phép đi kèm ĐÚNG message đó. Không có ký tự đại diện: `sync_pull <feed>`
     * là chuỗi ghép trong Go nên mỗi feed phải có một dòng riêng, để một feed
     * thêm sau này không tự được nhận.
     */
    'messages' => [

        // ── Nhóm 1 — đồng bộ (sync) ─────────────────────────────────────────
        'sync engine started' => [],
        'sync engine stopped' => [],
        'sync puller started' => [],
        'sync puller stopped' => [],
        'sync poke connected' => [],
        'sync poke disconnected — pull ticks unaffected, reconnecting with backoff' => [],
        'sync manifest available — manifest-driven pull active' => [],
        'sync manifest unavailable — falling back to legacy full pull (old Cloud?)' => [],
        'sync push failed' => ['id', 'entity', 'retryable'],
        'sync row dead-lettered' => ['id', 'reason'],
        'sync throttled — backing off' => ['retry_after', 'cooldown_until'],
        'sync: no handler' => ['key', 'entity_id'],
        'sync: invalid payload' => ['key'],
        'sync: non-retryable failure' => ['key', 'entity_id'],
        'sync_queue purged' => ['rows'],
        'device token cleared after cloud 401 — sync stopped, workstation must re-pair' => [],
        'cascade dead-lettered order children' => ['order_id', 'rows'],
        'cascade dead-lettered till children' => ['session_id', 'rows'],
        'upsert order failed' => ['order_id'],
        "pull cursor was ahead of Cloud's clock — healing" => ['key', 'was', 'now', 'ahead_by'],
        'heal cursor failed' => ['key'],
        'customer_orders cursor stalled on a full page — stepping past it; rows sharing this second may be skipped' => ['cursor', 'stepped_to', 'rows', 'limit'],
        'bulk order pull — auto-print suppressed (likely Cloud re-seed/backfill)' => ['firing', 'tick', 'max'],
        'stamp feed version' => ['feed'],
        'stamp manifest version' => [],
        'sync_pull menu_catalog' => [],
        'sync_pull menu_schedules' => [],
        'sync_pull promotions' => [],
        'sync_pull coupons' => [],
        'sync_pull customers' => [],
        'sync_pull staff' => [],
        'sync_pull printers' => [],
        'sync_pull peripheral_devices' => [],
        'sync_pull payment_methods' => [],
        'sync_pull tender_types' => [],
        'sync_pull tender_categories' => [],
        'sync_pull denominations' => [],
        'sync_pull effective_payment_options' => [],
        'sync_pull till' => [],
        'sync_pull till_sessions' => [],

        // ── Nhóm 2 — cảnh báo (alert) ───────────────────────────────────────
        // `subject` an toàn: mọi chỗ gọi `Raise()` truyền định danh MÁY (id
        // đơn, mã giao dịch Glory, `receipt_printer`, `pairing`, `build`).
        // Đã kiểm toàn bộ chỗ gọi 2026-08-16.
        'alert raised' => ['kind', 'subject', 'severity', 'audience'],
        'alerts purged (closed rows past retention)' => ['rows'],

        // ── Nhóm 3 — thu tiền mặt (釣銭機 / Glory) ──────────────────────────
        'cash drawer: opened for cash payment' => ['order'],
        'cash drawer: kick failed' => ['order', 'reason'],
        'cash drawer: could not read payment methods' => ['order'],
        'phục hồi lượt thu tiền mặt sau restart' => ['session', 'order', 'glory_txn', 'payment'],
        'không ghi được phiên thu tiền mặt — lượt này sẽ không phục hồi được nếu máy trạm tắt' => ['session', 'order'],
        'không đóng được phiên thu tiền mặt' => ['session'],
        'không ghi được sổ máy thu tiền' => ['session'],
        // `title` là từ vựng NGUYÊN VĂN của adapter Glory (`empty`,
        // `billRejectFull`, `needPullOut`, `notReady`, `forbidden`…), không
        // phải văn bản tự do — cùng lý lẽ với `CashDeviceErrorEvent.error_title`.
        'không ghi được sự cố máy thu tiền' => ['title'],
        'không đóng được sự cố máy thu tiền' => ['title'],
        'không đóng dấu được mã giao dịch 釣銭機 — lượt này sẽ không hỏi lại được máy nếu máy trạm tắt' => ['session', 'glory_txn'],
        'đã đối soát phiên thu tiền mặt còn dở từ lần chạy trước' => ['count'],
        'đối soát phiên 釣銭機 thất bại (không chặn khởi động)' => [],
        'enqueue payment.create (cash_changer)' => ['payment'],

        // ── Nhóm 4 — in ấn ──────────────────────────────────────────────────
        'printer dispatcher: unclassified printer_group, defaulting to kitchen_printer' => ['printer_group'],
        'printer dispatcher: no device configured for role, not rerouting' => ['printer_group', 'role'],
        'printer dispatcher: receipt_printer not configured, falling back to kitchen_printer' => [],
        // `device` là TÊN MÁY IN do quán đặt trong cấu hình, không phải dữ
        // liệu khách.
        'device connected' => ['device'],
        'connect device failed' => ['device'],
        'scan device' => [],
        'auto-print payment receipt failed' => ['order'],
        'table-paid slip: order not found locally' => ['order'],
        'table-paid slip: no printer configured' => ['order'],
        'table-paid slip: printer connect failed' => ['order'],
        'table-paid slip: print failed' => ['order'],
        'kitchen-ticket force-pull failed' => ['order_id'],
        'reprintKitchenForOrder: print failed' => ['printer_group'],
        'print counts failed (non-fatal)' => ['kind'],
        'print: tax row omitted — the order carries no tax fact to print' => ['kind', 'order_id', 'order_code'],
        'print: order has no positive total — the slip prints 0, not a recomputed figure' => ['order_id', 'order_code'],
        'print journal reserve failed (non-fatal)' => ['kind', 'order', 'payment'],
        'print journal confirm failed (non-fatal)' => ['job', 'kind'],
        'print reservation sweep failed (non-fatal)' => [],
        'print reservations abandoned by a previous run' => ['rows', 'action'],
        'refresh before print failed (non-fatal)' => ['order', 'status'],
    ],
];
