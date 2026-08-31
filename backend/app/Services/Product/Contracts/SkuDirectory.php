<?php

declare(strict_types=1);

namespace App\Services\Product\Contracts;

use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * #1567 — cổng đọc BIẾN THỂ SẢN PHẨM (kèm công thức) mà Catalog công bố cho Inventory.
 *
 * Production/Batch của Inventory tự truy vấn model `ProductSku` 10 lần.
 * Cùng mẫu "tiêu thụ kết quả" như {@see RecipeDirectory} (#1609): Catalog trả
 * ảnh chụp, Inventory thôi tự truy vấn.
 *
 * **Phạm vi tổ chức là THAM SỐ, không phải tuỳ chọn.** Ba trong bốn method bắt
 * buộc `$organizationId` vì bản cũ lọc bằng `whereHas('product', …)` — và
 * plan-040 C1 (TH.1) ghi rõ vì sao: *"org-scope the variant lookup so a recipe
 * referencing another tenant's SKU can't leak its name/stock"*. Một công thức
 * ĐƯỢC PHÉP tham chiếu SKU khác, nên bỏ phạm vi ở đây là rò dữ liệu tenant,
 * không phải nới lỏng vô hại.
 *
 * `findWithRecipe()` KHÔNG có phạm vi vì chỗ gọi nó (`ProductionOrderService`)
 * vốn đã không có — giữ nguyên hành vi, không "sửa" kèm trong một PR ranh giới.
 */
interface SkuDirectory
{
    /** Không giới hạn tổ chức — giữ nguyên hành vi chỗ gọi hiện tại. */
    public function findWithRecipe(string $skuId): ?SkuSnapshot;

    public function findWithRecipeForOrganization(string $skuId, string $organizationId): ?SkuSnapshot;

    /**
     * Như trên nhưng KHÔNG tìm thấy là lỗi — ném `ModelNotFoundException`.
     *
     * #962: `ProductionCalculatorService` (Inventory) đang tự dựng ngoại lệ đó và
     * phải nêu tên `App\Models\ProductSku` để điền vào thông điệp — một cạnh
     * Inventory → Catalog sinh ra chỉ vì một chuỗi trong câu báo lỗi. Chủ sở hữu
     * model là chủ sở hữu câu "không tìm thấy nó", nên cái ném nằm ở đây.
     *
     * Ngoại lệ giữ nguyên kiểu cũ để tầng HTTP vẫn dịch thành 404 y như trước —
     * đây là dời chỗ ném, không phải đổi hợp đồng lỗi.
     *
     * @throws ModelNotFoundException
     */
    public function getWithRecipeForOrganization(string $skuId, string $organizationId): SkuSnapshot;

    /**
     * Mọi SKU đang hoạt động CÓ công thức trong một tổ chức (lọc thêm theo brand nếu có).
     *
     * @return list<SkuSnapshot>
     */
    public function activeWithRecipeForOrganization(string $organizationId, ?string $brandId = null): array;

    /**
     * Tra nhiều SKU theo id, giới hạn trong tổ chức.
     *
     * @param  list<string>  $skuIds
     * @return array<string, SkuSnapshot> khoá theo id
     */
    public function byIdsForOrganization(array $skuIds, string $organizationId): array;

    /**
     * Như trên nhưng KHÔNG giới hạn tổ chức — cho đường TRỪ KHO (#1731).
     *
     * Nghe như một lỗ hổng, nên đây là lý do nó không phải:
     *
     * Phạm vi tổ chức ở các method kia bảo vệ một ca THẬT — plan-040 C1 (TH.1):
     * một **công thức được phép tham chiếu SKU bất kỳ**, kể cả của tenant khác,
     * nên tra theo id lấy từ công thức mà không giới hạn là rò dữ liệu.
     *
     * Ở đường trừ kho thì id **không tới từ công thức**: nó tới từ
     * `customer_order_items.product_sku_id` của một đơn ĐÃ thuộc tổ chức đó.
     * Lọc thêm một lần nữa không thêm cách ly nào — nhưng nó thêm một chế độ
     * hỏng mới: SKU lệch tổ chức (dữ liệu hỏng) sẽ **im lặng không trừ kho**,
     * tức bán hàng xong mà tồn kho không nhúc nhích và chỉ lộ ra lúc kiểm kê.
     * Đường này thà trừ theo dữ liệu đang có còn hơn lặng lẽ không trừ.
     *
     * Đó cũng đúng là lý do `findWithRecipe()` ở trên không có phạm vi. Không
     * dùng cái này cho đường nào mà id tới từ dữ liệu người dùng nhập.
     *
     * @param  list<string>  $skuIds
     * @return array<string, SkuSnapshot> khoá theo id
     */
    public function byIds(array $skuIds): array;
}
