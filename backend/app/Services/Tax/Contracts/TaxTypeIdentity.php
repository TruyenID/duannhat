<?php

declare(strict_types=1);

namespace App\Services\Tax\Contracts;

/**
 * #962 — DANH TÍNH của một loại thuế, do Pricing công bố cho Catalog.
 *
 * Hai trường, và việc thiếu trường thứ ba là NỘI DUNG của quyết định:
 * **không có `rate`**. Bốn chỗ gọi (`MenuService`, `FloatingSectionService`,
 * `EloquentProductPersistence`, `ProductImporter`) đều chỉ hỏi *"id này gán
 * được không"* và *"mã này là id nào"* — không chỗ nào đọc mức thuế. Thêm
 * `rate` vào đây là mời Catalog tự diễn giải thuế, đúng thứ plan-043 cấm: mức
 * thuế được phân giải bởi `TaxResolver` rồi **snapshot bất biến** lên từng dòng
 * đơn, làm tròn một lần theo NHÓM cùng mức. Một mức thuế đọc rời ở tầng danh
 * mục không có ngữ cảnh làm tròn nào cả, nên nó chỉ có thể sai.
 *
 * Cũng không có `isActive`: mọi method trả về `TaxTypeIdentity` đã LỌC theo
 * trạng thái mà chỗ gọi cần, nên trả cờ ra ngoài chỉ tạo cơ hội cho người sau
 * lọc lại lần nữa bằng một luật khác.
 */
final readonly class TaxTypeIdentity
{
    public function __construct(
        public string $id,
        public string $code,
    ) {}
}
