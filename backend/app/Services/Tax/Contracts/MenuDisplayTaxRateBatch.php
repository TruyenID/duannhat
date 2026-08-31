<?php

declare(strict_types=1);

namespace App\Services\Tax\Contracts;

/**
 * #1596 — một lô hiển thị đang mở. Xem `MenuDisplayTaxRates` về vòng đời (viết
 * bằng backtick CHỦ Ý: `{@see}` sẽ bị `pint` kéo thành `use` thật, và một
 * `PublishedContracts` phụ thuộc `PublishedContracts` khác là ĐỎ ngay tại rào).
 *
 * ## Cạm bẫy tiền — hợp đồng này CHUYỂN TIẾP, không tính lại
 *
 * `rateForMenuLine` PHẢI đi qua đúng chuỗi tầng của bộ giải thuế bên Pricing.
 * **Không** hiện thực nào được tự viết lại thứ tự tầng hay tự chọn "tầng nào
 * thắng". Tỉ lệ trả về ở đây là thứ khách NHÌN THẤY trước khi đặt món; tỉ lệ
 * đóng dấu lên dòng đơn đi đường khác. Hai đường lệch nhau = màn hình quảng cáo
 * 10% còn hoá đơn in 8%, im lặng, và chỉ lộ ra ở khiếu nại của khách.
 *
 * ## Vì sao truyền `id` thay vì model
 *
 * Layer `PublishedContracts` không được phụ thuộc module nào, nên chữ ký không
 * thể mang sản phẩm (Catalog) hay loại thuế (Pricing). Truyền id **không** đổi
 * kết quả: quan hệ `taxType` là `belongsTo` trơn không scope riêng, và loại thuế
 * dùng `SoftDeletes` — tra lại bằng id cũng ăn đúng `SoftDeletingScope` đó, nên
 * một loại thuế đã xoá mềm vẫn cho `null` ở cả hai lối và tầng sau vẫn nhận việc
 * y như cũ. Cùng lập luận đã chốt ở `App\Services\Order\Contracts\OrderLineTaxBatch`.
 */
interface MenuDisplayTaxRateBatch
{
    /**
     * Tỉ lệ (%) mà một dòng thực đơn sẽ được tính, hoặc `null` khi không tầng
     * nào giải ra — tức brand chưa có loại thuế nào. `null` KHÔNG được diễn dịch
     * thành 0%: phía hiển thị chọn cách trình bày, còn đường tiền có cảnh báo
     * riêng cho ca đó.
     *
     * @param  string|null  $menuLineTaxTypeId  tầng 1 — override của chính dòng thực đơn
     * @param  string|null  $productTaxTypeId  tầng 4 — `products.tax_type_id`
     * @param  string|null  $menuId  tầng 3 (#1218)
     * @param  string|null  $menuSectionId  tầng 2, đọc ở pivot (#1218)
     */
    public function rateForMenuLine(
        ?string $menuLineTaxTypeId,
        ?string $productTaxTypeId,
        string $branchId,
        string $brandId,
        ?string $menuId = null,
        ?string $menuSectionId = null,
    ): ?float;
}
