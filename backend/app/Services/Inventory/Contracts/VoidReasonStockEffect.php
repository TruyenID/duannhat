<?php

declare(strict_types=1);

namespace App\Services\Inventory\Contracts;

/**
 * #962 — ảnh chụp một LÝ DO VOID, đúng ba trường mà bảng sự thật #1149 dùng.
 *
 * Ba trường này là TOÀN BỘ những gì `StockDeductionService::compensateVoid()`
 * đọc từ `App\Models\VoidReason` — đo bằng cách quét thân method, không đọc
 * lướt: `stock_effect` (quyết định restock / waste / không bù), `id` (ghi vào
 * audit + log), `localizedLabel()` (ghép vào ghi chú của phiếu bù kho).
 *
 * **`stockEffect` là chuỗi thô, không phải enum.** Bản cũ đọc `$reason->stock_effect`
 * rồi tự chuẩn hoá `BackedEnum|string|null`, và mọi giá trị KHÔNG khớp
 * `restock`/`waste` rơi vào nhánh "không rõ lý do → KHÔNG bù + log cảnh báo".
 * Ép sang enum ở đây sẽ biến một giá trị rác thành `null` sớm hơn một tầng —
 * cùng kết quả hôm nay, nhưng nó dời chỗ quyết định "cái gì là không rõ" ra
 * khỏi Inventory. Giữ thô để nhánh cảnh báo vẫn do Inventory định nghĩa.
 *
 * `label` đã được phân giải ngôn ngữ TẠI CHỖ ĐỌC (`localizedLabel()` đọc
 * `app()->getLocale()`), giống hệt bản cũ vốn cũng gọi nó ngay trong cùng
 * request.
 */
final readonly class VoidReasonStockEffect
{
    public function __construct(
        public string $id,
        public ?string $stockEffect,
        public ?string $label,
    ) {}
}
