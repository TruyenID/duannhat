<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 · 7a-7 — Ordering hỏi Catalog "dòng menu nào, và nó khai thuế gì".
 *
 * ## Vì sao cổng này do ORDERING khai, không phải Catalog
 *
 * Giống {@see OpenTillSessionLookup}: Ordering được publish theo **namespace**
 * (`App\Services\Order\Contracts` nằm trong `published_contract_namespaces`), còn
 * Catalog publish **theo từng class** ⇒ để Catalog khai cổng thì phải sửa
 * `config/modules.php`, file mà nhiều PR khác đang giữ. Đảo chiều khai báo —
 * consumer khai interface, owner hiện thực — thì cạnh biến mất mà không đụng vào đó.
 *
 * ## Hai phép tra này KHÁC NHAU và KHÔNG suy ra được từ nhau
 *
 * - `taxContextForMenuProduct` — đã biết ĐÍCH DANH dòng menu (`menu_product_id` đi
 *   kèm cái giá vừa tính). Dùng ở đường thêm món.
 * - `taxContextForBranchProduct` — CHƯA biết dòng nào, vì `customer_order_items`
 *   không có cột `menu_product_id`. Phải suy lại từ (chi nhánh, sản phẩm). Dùng ở
 *   đường re-resolve và đường sửa topping.
 *
 * Gộp hai cái làm một là mời gọi đúng lỗi #1180: tính giá từ dòng menu này mà đóng
 * thuế theo dòng menu khác.
 */
interface OrderMenuLineDirectory
{
    /**
     * Ngữ cảnh thuế của MỘT dòng menu đã biết id.
     *
     * `$menuProductId === null` ⇒ {@see OrderMenuLineTaxContext::none()} (dòng
     * off-menu), không phải lỗi.
     *
     * Người gọi PHẢI truyền đúng dòng menu mà GIÁ của dòng đơn đến từ đó. Đóng thuế
     * theo menu này mà tính tiền theo menu khác chính là vết nứt hiển-thị-vs-tính-
     * tiền của #1180, lệch sang một cột.
     *
     * **`taxTypeId` ở đây là tầng 1 và người gọi không được bỏ qua nó (#1420).**
     * Nhánh dự phòng của `addItems` từng truyền menu + section của dòng này nhưng để
     * tầng 1 rỗng, nên tỉ lệ của cả MENU (tầng 3) thắng override của chính DÒNG
     * (tầng 1) — đảo ngược đúng thứ tự đã ghi. Customer-web không bao giờ gửi
     * `menu_product_sku_id` nên mọi đơn của nó đi vào nhánh đó: một dòng override
     * 10% nằm trong menu 8% được hiển thị 10% mà tính tiền 8%.
     */
    public function taxContextForMenuProduct(?string $menuProductId): OrderMenuLineTaxContext;

    /**
     * Dòng menu mà một sản phẩm rơi vào trong phạm vi một chi nhánh — nguồn của CẢ
     * override tầng 1 LẪN cặp menu/section mà dòng đó thừa kế (#1218).
     *
     * Không có dòng nào đang bật ⇒ {@see OrderMenuLineTaxContext::none()}.
     */
    public function taxContextForBranchProduct(string $branchId, string $productId): OrderMenuLineTaxContext;
}
