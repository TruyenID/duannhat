<?php

declare(strict_types=1);

namespace App\Services\Product\Contracts;

/**
 * #1567 — cổng đọc CÔNG THỨC mà Catalog công bố cho Inventory.
 *
 * `MaterialBatchService` (Inventory) phát 11 cạnh vào model `Recipe`, và
 * chúng KHÔNG phải "nhận model từ người gọi" — chúng là **truy vấn của chính
 * nó**. Nên đây không phải ca thu-hẹp-chữ-ký (#1612) mà là ca **tiêu thụ kết
 * quả** (#1609): Catalog trả về ảnh chụp, Inventory thôi tự truy vấn.
 *
 * Bốn method dưới là bốn truy vấn CÓ THẬT, chép nguyên hình dạng từ chỗ chúng
 * vừa rời đi. Ba cái sau khác nhau ở **điều kiện lọc và cột sắp xếp**, và khác
 * biệt đó có ý nghĩa nghiệp vụ — gộp lại thành một method "linh hoạt" là cách
 * đánh mất nó:
 *
 *   - đóng lô            → mới nhất, `is_active` VÀ đã duyệt, theo `updated_at`
 *   - kiểm tra khi sửa   → mới nhất, `is_active` (bất kể duyệt), theo `updated_at`
 *   - dung sai / sản lượng → mới nhất ĐÃ DUYỆT (bất kể active), theo `approved_at`
 */
interface RecipeDirectory
{
    public function find(string $recipeId): ?RecipeSnapshot;

    /** Công thức mới nhất còn hiệu lực VÀ đã duyệt của một nguyên liệu. */
    public function latestActiveApprovedForMaterial(string $materialId): ?RecipeSnapshot;

    /** Công thức mới nhất còn hiệu lực, KHÔNG xét trạng thái duyệt. */
    public function latestActiveForMaterial(string $materialId): ?RecipeSnapshot;

    /** Công thức mới nhất ĐÃ DUYỆT, KHÔNG xét `is_active`, sắp theo `approved_at`. */
    public function latestApprovedForMaterial(string $materialId): ?RecipeSnapshot;
}
