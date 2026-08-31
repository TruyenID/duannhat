<?php

declare(strict_types=1);

namespace App\Services\Inventory\Contracts;

/**
 * #962 — cổng DANH MỤC NGUYÊN VẬT LIỆU mà Inventory công bố cho Catalog.
 *
 * Inventory mới chỉ công bố `OrderLineStockDeduction` (trừ kho theo dòng đơn),
 * nên mọi câu hỏi về BẢN THÂN nguyên liệu vẫn phải đi thẳng vào
 * `App\Models\Material`. Catalog hỏi hai câu, và chỉ hai:
 *
 *   `RecipeService`   "nguyên liệu này có thật không, thuộc brand nào, và
 *                     đơn vị nào đã đăng ký cho nó" — A1/A3/B2/B5/C1 của
 *                     plan-022, tức toàn bộ phần kiểm công thức chạm nguyên liệu.
 *   `RecipeImporter`  "mã SKU trong file CSV là nguyên liệu nào".
 *
 * **`adoptRecipeYield` là GHI, và nó nằm ở đây có chủ đích.** Trước đây
 * `RecipeService::syncOutputMaterialFromRecipe()` tự `$material->update([...])`
 * và tự tạo `material_units` — tức Catalog viết thẳng vào aggregate của
 * Inventory. Luật "chỉ khi `yield_unit` đang NULL", và luật "đăng ký đơn vị gốc
 * nếu chưa có đơn vị nào" (điều kiện để `MaterialBatchService::complete()` phân
 * giải được đơn vị gốc lúc đúc lô sản xuất) đều là luật của INVENTORY. Cổng
 * nhận đúng ba dữ kiện của công thức và tự quyết định phần còn lại; Catalog
 * không được biết luật đó, và vì thế không thể làm nó lệch.
 *
 * Không có method nào tạo / sửa / xoá nguyên liệu: vòng đời CRUD của `Material`
 * do `MaterialService` giữ, và #962 đưa class đó về Inventory thay vì bọc nó
 * sau một interface — cổng hẹp bằng đúng câu hỏi người gọi hỏi, không phải
 * một model bê nguyên ra sau interface.
 */
interface MaterialDirectory
{
    /** `null` = không tồn tại. */
    public function find(string $materialId): ?MaterialSnapshot;

    /**
     * Đơn vị đã đăng ký cho một nguyên liệu (`material_units.unit`).
     *
     * Mảng RỖNG mang nghĩa nghiệp vụ: nguyên liệu chưa được khai đơn vị nào,
     * và plan-022 B5 nói rõ trường hợp đó BỎ QUA kiểm tra đơn vị (sẽ khai ở
     * bước sau). Nguyên liệu không tồn tại cũng trả rỗng — chỗ gọi đã kiểm tồn
     * tại bằng {@see find()} trước đó.
     *
     * @return list<string>
     */
    public function registeredUnits(string $materialId): array;

    /**
     * Mọi nguyên liệu của một (org, brand), khoá theo `sku` VIẾT HOA.
     *
     * @return array<string, MaterialSnapshot>
     */
    public function indexBySkuForBrand(string $organizationId, string $brandId): array;

    /**
     * Nhận sản lượng từ một công thức VỪA ĐƯỢC DUYỆT LẦN ĐẦU — nâng nguyên
     * liệu từ RAW lên PRODUCED (plan-022 T18 / A4).
     *
     * Idempotent theo `yield_unit`: nguyên liệu đã có `yield_unit` thì mọi lần
     * gọi sau là no-op, nên một lần duyệt lại về sau KHÔNG bao giờ ghi đè giá
     * trị người vận hành đã sửa tay.
     */
    public function adoptRecipeYield(string $materialId, string $outputUnit, ?float $outputQuantity): void;
}
