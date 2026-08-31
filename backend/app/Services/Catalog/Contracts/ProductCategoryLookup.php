<?php

declare(strict_types=1);

namespace App\Services\Catalog\Contracts;

/**
 * #2371 — Catalog công bố quyền trả lời *"sản phẩm này nằm dưới những danh mục nào"*.
 *
 * ## Vì sao cần công bố
 *
 * Ordering cần tập `category_id` của một sản phẩm để hỏi `MenuPromotionResolver`
 * khi `applies_to` là `categories` hoặc `mixed` (plan-019). Trước đây nó tự đọc
 * `DB::table('product_category')` — bảng pivot của Catalog — ở hai chỗ.
 *
 * Hai chỗ đó **không phải nợ mới**: chúng vô hình với mọi hàng rào vì pivot
 * `product_category` chưa có model nào nhận, nên bộ quét
 * `architecture:raw-table-reads` không tra được chủ và xếp bảng vào
 * `unowned_tables`. Omnify 5.9.21 (upstream omnify-go#158) sửa `$table` của
 * `CategoryProductBaseModel` từ `category_products` — một bảng KHÔNG TỒN TẠI —
 * về `product_category`, và ngay lúc đó bộ quét nhìn thấy cả hai chỗ: R2 đỏ với
 * ngân sách 0.
 *
 * Nên đây là ca mẫu mà chính `RawTableReadsTest` mô tả: *"chỗ đọc thô chính đáng
 * thì thuộc về module SỞ HỮU bảng (adapter của chính module đó), không phải một
 * dòng ngân sách"*. Truy vấn không đổi một chữ — nó chỉ chuyển sang đứng ở
 * Catalog, nơi nó là đọc TRONG module.
 *
 * ## Không gom lô — cố ý
 *
 * Cả hai chỗ gọi đều nằm trong luồng xử lý TỪNG dòng đơn, nên đây là một N+1 có
 * sẵn. Bản này giữ nguyên chữ ký một-sản-phẩm để việc dời chỗ đọc **không kèm
 * đổi hành vi**; thêm API gom lô khi chưa có ai gọi là đoán trước nhu cầu.
 */
interface ProductCategoryLookup
{
    /**
     * Các danh mục mà một sản phẩm trực thuộc.
     *
     * @return list<string>
     */
    public function categoryIdsFor(string $productId): array;
}
