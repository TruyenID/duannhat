<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 (7b) — sổ chuyển trạng thái bàn (`table_status_changes`), do Ordering
 * công bố cho Organization ghi vào.
 *
 * Bảng này thuộc **Ordering** theo bản đồ module (nó là dấu vết vòng đời phục vụ,
 * đọc bởi báo cáo đơn), nhưng người GHI là `TableStatusService` ở Organization —
 * nơi sở hữu `Table` và luật "bàn ngừng hoạt động thì không đổi trạng thái".
 *
 * **Chỉ ghi, cố ý.** Không có method đọc: chỗ đọc duy nhất
 * (`TableStatusService::history()`) đi qua quan hệ `Table::statusChanges()` của
 * chính model Organization, nên nó không cần cổng. Thêm `list()` vào đây sẽ mời
 * người sau đọc lịch sử đơn hàng từ Organization — đúng thứ cổng này chặn.
 *
 * **Không mở transaction riêng.** Chỗ gọi ghi dòng này BÊN TRONG transaction đã
 * khoá `tables` (BR-T03, tuần tự hoá hai lần đổi trạng thái đồng thời); một
 * transaction lồng ở đây sẽ chỉ là savepoint thừa, còn một transaction TÁCH RỜI
 * sẽ phá đúng bất biến ấy — dòng nhật ký commit được trong khi bàn thì không.
 */
interface TableStatusJournal
{
    /**
     * Ghi một lần chuyển trạng thái (append-only).
     *
     * `$fromStatus` nhận `null` cho dòng đầu tiên của một bàn chưa từng có trạng
     * thái — giữ nguyên hành vi cũ, vốn ghi thẳng giá trị đọc được từ bàn.
     */
    public function record(
        string $tableId,
        ?string $fromStatus,
        string $toStatus,
        string $changedById,
        ?string $note = null,
    ): void;
}
