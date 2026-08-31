<?php

declare(strict_types=1);

namespace App\Services\Product\Contracts;

/**
 * #962 — cổng LAN TRUYỀN DỊ NGUYÊN, do Catalog công bố cho Inventory.
 *
 * Khi bộ dị nguyên (allergen) của một nguyên liệu đổi, mọi công thức ở HẠ NGUỒN
 * phải tính lại `Recipe.allergen_rollup`, và công thức nào ĐANG DUYỆT mà rollup
 * thật sự đổi thì bị đẩy về `pending` (luật hai tầng — sửa không đổi delta thì
 * KHÔNG đẩy).
 *
 * Cổng chỉ có MỘT method, và đó là điểm: `MaterialService` (Inventory) trước
 * đây tự chạy vòng lặp *"lấy danh sách công thức đổi → xét trạng thái duyệt →
 * markAsPending → logAudit"*. Cả bốn bước đó là quy trình DUYỆT CÔNG THỨC, tức
 * luật của Catalog. Một cổng trả về "danh sách công thức đã đổi" sẽ để nguyên
 * vòng lặp ấy bên Inventory — đảo chiều cạnh mà không dời quyết định. Cổng này
 * nhận nguyên câu hỏi người gọi thật sự hỏi: *"dị nguyên của nguyên liệu này
 * vừa đổi, hãy xử lý hệ quả trong danh mục"*.
 */
interface MaterialAllergenPropagation
{
    public function propagateAllergenChange(string $materialId, string $organizationId): void;
}
