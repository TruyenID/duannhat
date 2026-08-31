<?php

declare(strict_types=1);

namespace App\Services\Tax\Contracts;

/**
 * #962 — cổng TRA LOẠI THUẾ mà Pricing công bố cho Catalog.
 *
 * Bốn class của Catalog tự truy vấn `App\Models\TaxType` (7 cạnh):
 * `MenuService` (tier 1/2/3 của #1218), `FloatingSectionService`,
 * `EloquentProductPersistence` (gán theo danh mục + tạo/sửa sản phẩm) và
 * `ProductImporter` (CSV). Tất cả hỏi CÙNG MỘT LOẠI câu hỏi — *"loại thuế này
 * có gán được cho brand này không"* — nhưng mỗi chỗ tự viết lại điều kiện lọc.
 *
 * **Ba method vì có ba câu hỏi khác nhau, không phải một câu hỏi ba biến thể.**
 * Gộp chúng thành một `find()` "linh hoạt" sẽ đánh mất đúng phần khác biệt có
 * ý nghĩa nghiệp vụ:
 *
 *   - `findAssignable`  gán một TIER (menu-line / section / menu / floating
 *                       section). Chỉ loại ĐANG BẬT mới gán được, và phạm vi
 *                       brand là TUỲ CHỌN vì chỗ gọi cũ dùng `when($brandId)`
 *                       — bỏ qua khi caller không biết brand.
 *   - `belongsToBrand`  gán vào SẢN PHẨM / DANH MỤC. Xét cả org lẫn brand và
 *                       **KHÔNG** xét `is_active`: `EloquentProductPersistence`
 *                       cố ý cho phép giữ nguyên một loại đã tắt trên sản phẩm
 *                       cũ, chỉ chặn loại của brand khác (nếu chặn cả loại tắt
 *                       thì mọi lần sửa sản phẩm cũ sẽ 422).
 *   - `activeByCodeForBrand` / `firstActiveCodeForBrand`
 *                       nhập CSV: mã người dùng gõ → id, và một mã mẫu thật để
 *                       hàng mẫu tải về là copy-paste-import được.
 *
 * **Cổng KHÔNG trả mức thuế.** Xem `TaxTypeIdentity` — đó là ranh giới giữa
 * "danh mục gán nhãn thuế" (Catalog) và "tính tiền theo nhãn đó" (Pricing).
 */
interface TaxTypeDirectory
{
    /**
     * Loại thuế ĐANG BẬT gán được cho một tier. `null` = không gán được
     * (không tồn tại, đã tắt, hoặc thuộc brand khác).
     *
     * `$brandId` null nghĩa là KHÔNG lọc theo brand — giữ nguyên hành vi
     * `when($brandId, …)` của bốn chỗ gọi, không siết thêm trong một PR ranh giới.
     */
    public function findAssignable(string $taxTypeId, ?string $brandId = null): ?TaxTypeIdentity;

    /**
     * Loại thuế này có thuộc đúng (org, brand) đó không — KHÔNG xét `is_active`.
     */
    public function belongsToBrand(string $taxTypeId, string $organizationId, string $brandId): bool;

    /**
     * Mọi loại ĐANG BẬT của một brand, khoá theo `code` VIẾT HOA (mã là duy
     * nhất trong một brand — plan-043 T2.6).
     *
     * @return array<string, TaxTypeIdentity>
     */
    public function activeByCodeForBrand(string $organizationId, string $brandId): array;

    /**
     * Mã của loại ĐANG BẬT lâu đời nhất trong brand (theo `created_at`), dùng
     * làm giá trị mẫu trong file CSV tải về. `null` khi brand chưa có loại nào
     * — chỗ gọi hiểu là "kế thừa mặc định", không phải lỗi.
     */
    public function firstActiveCodeForBrand(string $organizationId, ?string $brandId = null): ?string;
}
