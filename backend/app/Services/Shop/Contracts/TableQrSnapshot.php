<?php

declare(strict_types=1);

namespace App\Services\Shop\Contracts;

/**
 * #962 — ảnh chụp một bàn NHÌN TỪ MÃ QR của khách.
 *
 * Vì sao không nhét thêm cột vào {@see TableOccupancySnapshot}: cái đó cố ý chỉ
 * mang `current_order_id` + `status`, và docblock của nó nói thẳng lý do (16 chỗ
 * Ordering chạm `tables` đều chỉ đọc/ghi hai cột ấy). Luồng QR là ca KHÁC: nó
 * phải mở một `TableSession`, nên cần `organization_id` + `branch_id`, và nó trả
 * bàn ra cho customer-web nên cần tên hiển thị + `qr_token`. Gộp hai nhu cầu vào
 * một DTO là cách êm nhất để cái snapshot hẹp kia phình dần thành bản sao của
 * model.
 *
 * `rawStatus` là giá trị THÔ của cột, không đi qua enum cast. Đây là quyết định
 * có sẵn từ plan-034 (edge case 6.1) và phải giữ: một hàng còn kẹt `paid` không
 * có case enum nào, và rehydrate nó sẽ ném `ValueError` TRƯỚC khi máy trạng thái
 * kịp rẽ nhánh.
 */
final class TableQrSnapshot
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $code,
        public readonly ?string $name,
        public readonly ?string $qrToken,
        public readonly string $rawStatus,
        public readonly ?string $organizationId,
        public readonly ?string $branchId,
        public readonly ?string $currentOrderId,
    ) {}

    /** Tên bàn hiển thị cho khách: mã bàn, thiếu thì tên. */
    public function displayNumber(): ?string
    {
        return $this->code ?? $this->name;
    }
}
