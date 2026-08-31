<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

use App\Services\Customer\TaxResolution;

/**
 * #962 · 7a-7 — một lô giải thuế đang mở. Xem {@see OrderLineTaxPricing} về vòng đời.
 *
 * ## Cạm bẫy tiền — hợp đồng này CHUYỂN TIẾP, không tính lại
 *
 * `resolveForLine` PHẢI đi qua đúng chuỗi tầng của
 * `App\Services\Customer\TaxResolver`. **Không** hiện thực nào được tự viết lại thứ
 * tự tầng, tự chọn "tầng nào thắng", hay tự làm tròn. plan-043 chốt tầng theo phán
 * quyết sản phẩm (#1218: section và cả-menu nằm TRÊN sản phẩm), và tỉ lệ giải ra
 * được đóng dấu **bất biến** lên từng dòng đơn — sai một tầng là sai hoá đơn
 * 適格請求書 đã in, sai Z-report, và sai cả tháng doanh thu.
 *
 * Bug #816 sinh ra từ đúng loại "tính lại cho tương đương" này ở một cổng tiền khác.
 *
 * ## Vì sao truyền `id` thay vì model
 *
 * Layer `PublishedContracts` không được phụ thuộc module nào, nên chữ ký không thể
 * mang `Product` (Catalog) hay `TaxType` (Pricing). Truyền id **không** đổi kết quả:
 * `$product->taxType` là `belongsTo(TaxType::class, 'tax_type_id')` trơn, không
 * scope riêng, và `TaxType` dùng `SoftDeletes` — tra lại bằng id cũng ăn đúng
 * `SoftDeletingScope` đó, nên một `TaxType` đã xoá mềm vẫn cho `null` ở cả hai lối
 * và tầng sau vẫn nhận việc y như cũ.
 */
interface OrderLineTaxBatch
{
    /**
     * Giải + đóng dấu tỉ lệ thuế cho MỘT dòng đơn.
     *
     * @param  string  $productId  chỉ dùng để ghi log khi không tầng nào giải ra
     * @param  string|null  $productTaxTypeId  tầng 4 — `products.tax_type_id`
     * @param  string|null  $menuLineTaxTypeId  tầng 1 — override của dòng menu, hoặc
     *                                          type đã đóng dấu sẵn trên dòng đơn
     * @param  string|null  $menuId  tầng 3 (#1218)
     * @param  string|null  $menuSectionId  tầng 2, đọc ở pivot (#1218)
     */
    public function resolveForLine(
        string $productId,
        ?string $productTaxTypeId,
        ?string $menuLineTaxTypeId,
        string $branchId,
        string $brandId,
        ?string $menuId = null,
        ?string $menuSectionId = null,
    ): TaxResolution;
}
