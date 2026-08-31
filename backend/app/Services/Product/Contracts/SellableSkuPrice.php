<?php

declare(strict_types=1);

namespace App\Services\Product\Contracts;

/**
 * #1597 — giá bán của một SKU TẠI MỘT CHI NHÁNH, do Catalog công bố.
 *
 * Đây là "kết quả tra giá", không phải ảnh chụp SKU. Nó cố ý KHÁC
 * {@see SkuSnapshot} (#1567): cái kia mô tả biến thể + công thức cho sản xuất,
 * cái này trả lời đúng một câu hỏi của đường BÁN — *"chi nhánh này bán món này
 * bao nhiêu, và có được bán không?"*
 *
 * `branchMenuPrice` null nghĩa là SKU **ngoài menu** của chi nhánh — trạng thái
 * HỢP LỆ, không phải lỗi: đường thu ngân Cloud (`addItems`) và đường sync của
 * workstation đều rơi về `baseSellingPrice`. Trộn hai trường này làm một
 * (`price` đã fallback sẵn) sẽ xoá mất thông tin "món này ngoài menu", thứ mà
 * chỗ gọi có thể cần để ghi log hoặc đổi chính sách sau này.
 *
 * `isSellable` mang nguyên định nghĩa của `ProductSku::isSellable()` —
 * `is_active` VÀ sản phẩm cha `Active`. Nó là luật của Catalog, nên đi kèm kết
 * quả chứ không để chỗ gọi tự dựng lại từ hai cờ rời.
 *
 * `categoryIds` có mặt vì chỗ gọi cần nó để phân giải khuyến mãi, và vì trước
 * đây nó được đọc bằng `DB::table('product_category')` **thô** ngay trong
 * Ordering — một lần đọc dữ liệu Catalog mà đồ thị tầng KHÔNG NHÌN THẤY. Cạnh
 * vô hình còn tệ hơn cạnh đếm được: nó không xuất hiện trong bất kỳ phép đo nào
 * của #962.
 */
final readonly class SellableSkuPrice
{
    /**
     * @param  list<string>  $categoryIds
     */
    public function __construct(
        public string $skuId,
        public ?string $productId,
        public bool $isSellable,
        public float $baseSellingPrice,
        public ?float $branchMenuPrice,
        public array $categoryIds,
    ) {}

    /**
     * Giá dùng để tính tiền: giá menu của chi nhánh, ngoài menu thì giá gốc.
     *
     * Cùng thứ tự fallback mà `CustomerOrderService::addItems` (đường thu ngân
     * Cloud) dùng — để một món bán qua workstation và cùng món đó bán ở Cloud
     * không ra hai con số.
     */
    public function effectivePrice(): float
    {
        return $this->branchMenuPrice ?? $this->baseSellingPrice;
    }
}
