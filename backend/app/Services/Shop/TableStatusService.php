<?php

namespace App\Services\Shop;

use App\Models\Table;
use App\Omnify\Enums\TableStatusEnum;
use App\Services\Order\Contracts\TableSessionCloser;
use App\Services\Order\Contracts\TableStatusJournal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Mutates a Table's runtime status and writes an append-only entry into
 * table_status_changes for every transition. Concurrent transitions are
 * serialized via lockForUpdate (BR-T03 / TESTS edge case "concurrent").
 */
class TableStatusService
{
    /**
     * Apply a runtime status change to a table. Returns the fresh table
     * with its newest status change loaded.
     *
     * @throws InvalidArgumentException if the table is inactive (BR-T03)
     */
    public function changeStatus(
        Table $table,
        string $toStatus,
        string $userId,
        ?string $note = null,
    ): Table {
        return DB::transaction(function () use ($table, $toStatus, $userId, $note) {
            /** @var Table $locked */
            $locked = Table::query()
                ->whereKey($table->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->is_active) {
                throw new InvalidArgumentException(
                    "Cannot change status of an inactive table ({$locked->code})."
                );
            }

            $fromStatus = $locked->status instanceof \BackedEnum
                ? $locked->status->value
                : $locked->status;

            $locked->update(['status' => $toStatus]);

            // #962 (7b) — `table_status_changes` thuộc Ordering; ghi qua cổng
            // Ordering công bố, VẪN BÊN TRONG transaction đã khoá bàn (BR-T03),
            // nên hai dòng vẫn commit/rollback cùng nhau như trước.
            app(TableStatusJournal::class)->record(
                (string) $locked->id,
                $fromStatus,
                $toStatus,
                $userId,
                $note,
            );

            // #2610 — trả bàn về trống thì PHIÊN cũng phải đóng.
            //
            // Trước bản này, bốn route đổi trạng thái (pos · shop · workstation ·
            // tms) đều chỉ ghi `tables.status`. Bàn bị khai là trống trong khi
            // khách vẫn giữ `TableSession` mở trên điện thoại; phiên mồ côi sống
            // tới 4 giờ chờ `dine-in:expire-stale-sessions`, và một lần quét QR
            // nữa trong khoảng đó mở phiên THỨ HAI cho cùng cái bàn.
            //
            // CHỈ `free`. `cleaning` / `out_of_service` không có nghĩa khách đã
            // đi — bàn có thể đang chờ dọn với phiên còn sống, và đóng phiên ở
            // đó là đoán hộ nhân viên.
            //
            // Vẫn nằm trong transaction đã khoá bàn: phiên và bàn phải
            // commit/rollback cùng nhau, nếu không ta chỉ đổi kiểu lệch này lấy
            // kiểu lệch khác.
            if ($toStatus === TableStatusEnum::Free->value) {
                app(TableSessionCloser::class)->closeOpenSessionsForTable((string) $locked->id);
            }

            return $locked->fresh(['zone:id,code,name', 'lastStatusChange']);
        });
    }

    /**
     * Paginated transition history for a table, newest first.
     */
    public function history(Table $table, int $perPage = 25): LengthAwarePaginator
    {
        return $table->statusChanges()
            ->orderByDesc('changed_at')
            ->paginate($perPage);
    }
}
