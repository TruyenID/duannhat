<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\TableSession;
use App\Services\Order\Contracts\TableSessionCloser;

/**
 * #2610 — hiện thực {@see TableSessionCloser}.
 *
 * Chép NGUYÊN khuôn đóng phiên của `OrderClosingService` (plan-034), kể cả điều
 * kiện `where('status', STATUS_OPEN)`. Điều kiện ấy KHÔNG thừa: nó làm phép ghi
 * idempotent và không giẫm lên phiên vừa được đóng bởi đường khác — đơn thanh
 * toán xong, hoặc reaper `dine-in:expire-stale-sessions` chạy đúng lúc đó.
 *
 * ## `closed`, không phải `expired` — quyết định của chủ dự án 2026-08-12
 *
 * Hai giá trị đều mô tả được "phiên này hết", nhưng chúng phải nói ĐƯỢC hai
 * nguyên nhân khác nhau khi đọc dữ liệu về sau:
 *
 *   `closed`   — có người quyết định kết thúc: khách trả tiền
 *                (`OrderClosingService`), hoặc nhân viên trả bàn về trống (ở đây).
 *   `expired`  — không ai quyết định gì; phiên chỉ hết hạn sau 4 giờ
 *                (`ExpireStaleTableSessions`).
 *
 * Dùng `expired` ở đây sẽ làm "nhân viên dọn bàn" trông y hệt "khách bỏ đi mà
 * không ai để ý" trong `table_sessions`, và không truy vấn nào tách chúng ra
 * được nữa.
 */
final class EloquentTableSessionCloser implements TableSessionCloser
{
    public function closeOpenSessionsForTable(string $tableId): int
    {
        return TableSession::query()
            ->where('table_id', $tableId)
            ->where('status', TableSession::STATUS_OPEN)
            ->update([
                'status' => TableSession::STATUS_CLOSED,
                'closed_at' => now(),
            ]);
    }
}
