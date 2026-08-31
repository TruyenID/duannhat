<?php

declare(strict_types=1);

namespace App\Services\Product\Contracts;

/**
 * #962 — cổng đọc ĐỒ THỊ NGUYÊN LIỆU của công thức, do Catalog công bố cho
 * Inventory.
 *
 * Khác {@see RecipeDirectory} ở CÂU HỎI, không ở dữ liệu: `RecipeDirectory`
 * hỏi *"công thức hiện hành của nguyên liệu này là cái nào"* (một công thức),
 * còn đây hỏi *"nguyên liệu nào ăn nguyên liệu nào"* — một quan hệ, đọc từ
 * `Recipe.ingredients` (nguồn BOM chuẩn từ plan-022 T4.3, thay cho cột
 * `Material.components` đã bỏ). Gộp hai cổng lại sẽ làm mất đúng sự phân biệt đó.
 *
 * Ba method này tồn tại vì `MaterialService` — vòng đời CRUD của `Material` —
 * về với Inventory ở #962, và ba câu hỏi nó cần đều nằm trong bảng `recipes`
 * của Catalog. Chúng trả về ID và SỐ ĐẾM, không trả model, nên hợp đồng thoả
 * luật "chỉ phụ thuộc hai kernel".
 */
interface RecipeGraph
{
    /**
     * Số nguyên liệu trong công thức ĐANG HIỆU LỰC của từng nguyên liệu đầu ra.
     *
     * Nguyên liệu không có công thức active KHÔNG xuất hiện trong kết quả —
     * chỗ gọi hiểu là 0 (và UI hiện "tạo công thức trước khi đóng lô").
     *
     * @param  list<string>  $materialIds
     * @return array<string, int> khoá theo material id
     */
    public function activeIngredientCounts(array $materialIds): array;

    /**
     * Nguyên liệu này có công thức ĐANG HIỆU LỰC kèm danh sách nguyên liệu không.
     *
     * Đây là tín hiệu DỰ PHÒNG của phép nhận diện "produced" (plan-022 T2/T19):
     * nguồn chân lý là `yield_unit`, và method này chỉ được hỏi khi `yield_unit`
     * còn trống.
     */
    public function hasActiveRecipeWithIngredients(string $materialId): bool;

    /**
     * Id của những nguyên liệu ĐẦU RA mà công thức đang hiệu lực của chúng
     * TIÊU THỤ nguyên liệu đã cho. Không gồm chính nó.
     *
     * @return list<string>
     */
    public function producedMaterialIdsConsuming(string $materialId): array;
}
