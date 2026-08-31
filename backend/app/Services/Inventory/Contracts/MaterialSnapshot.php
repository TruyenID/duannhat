<?php

declare(strict_types=1);

namespace App\Services\Inventory\Contracts;

/**
 * #962 — ảnh chụp một NGUYÊN VẬT LIỆU, do Inventory công bố cho Catalog.
 *
 * Ba trường là TOÀN BỘ những gì hai chỗ gọi đọc — đo bằng cách quét thân
 * method, không đọc lướt:
 *
 *     RecipeService   id · sku (chỉ để in vào thông báo lỗi) · brandId (chặn
 *                     công thức trỏ sang nguyên liệu của brand khác)
 *     RecipeImporter  id
 *
 * **`registeredUnits` cố ý KHÔNG nằm ở đây** dù `RecipeService` cần nó. Danh
 * sách đơn vị là một truy vấn RIÊNG (`material_units`), nên nhét vào snapshot
 * buộc `indexBySkuForBrand` — chỗ không cần đơn vị — hoặc phải nạp thừa, hoặc
 * phải trả mảng rỗng. Mảng rỗng ở đó là một LỜI NÓI DỐI đọc được: "nguyên liệu
 * này chưa đăng ký đơn vị nào" chính là điều kiện `RecipeService` dùng để BỎ
 * QUA kiểm tra đơn vị (B5), nên một snapshot rỗng giả sẽ âm thầm tắt một luật
 * kiểm. Vì thế đơn vị đi qua {@see MaterialDirectory::registeredUnits()}.
 */
final readonly class MaterialSnapshot
{
    public function __construct(
        public string $id,
        public ?string $sku,
        public ?string $brandId,
    ) {}
}
