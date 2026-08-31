<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\TableStatusChange;
use App\Services\Order\Contracts\TableStatusJournal;

/**
 * #962 (7b) — hiện thực {@see TableStatusJournal}.
 *
 * Chép NGUYÊN lệnh `TableStatusChange::create()` của `TableStatusService`, kể cả
 * `changed_at => now()`: đó là dấu thời gian máy chủ của lần chuyển, không phải
 * business date, nên nó KHÔNG đi qua `BusinessClock` (#1091) — đổi sang giờ chi
 * nhánh ở đây sẽ làm lệch mọi lịch sử đã ghi.
 */
final class EloquentTableStatusJournal implements TableStatusJournal
{
    public function record(
        string $tableId,
        ?string $fromStatus,
        string $toStatus,
        string $changedById,
        ?string $note = null,
    ): void {
        TableStatusChange::create([
            'table_id' => $tableId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_by_id' => $changedById,
            'changed_at' => now(),
            'note' => $note,
        ]);
    }
}
