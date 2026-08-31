<?php

namespace App\Services\Shop\Contracts;

/**
 * #1590 — ảnh chụp phần CHIẾM DỤNG của một bàn, và chỉ phần đó.
 *
 * Cố ý KHÔNG mang `zone_id`, sức chứa, template hay bất cứ thứ gì thuộc sơ đồ
 * mặt bằng. Đo từ code trước khi dựng: 16 chỗ Ordering chạm `App\Models\Table`
 * đều chỉ đọc/ghi `current_order_id` + `status`, không chỗ nào đọc bố cục. `code`
 * có mặt vì đúng một thông điệp lỗi 422 in nó ra cho người dùng.
 */
final class TableOccupancySnapshot
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $code,
        public readonly ?string $currentOrderId,
        public readonly ?string $status,
    ) {}

    public function isHeld(): bool
    {
        return $this->currentOrderId !== null;
    }
}
