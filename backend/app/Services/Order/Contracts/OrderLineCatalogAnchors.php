<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * #962 · 7a-8 — Ordering hỏi Catalog "dòng đơn này neo vào SKU nào và dòng menu nào".
 *
 * Cùng chiều khai báo đảo như {@see OrderMenuLineDirectory}: Ordering publish theo
 * **namespace** nên khai cổng ở đây là dùng được ngay, còn để Catalog khai thì phải
 * sửa `config/modules.php` — file mà nhiều PR khác đang giữ.
 *
 * ## Bốn phép tra, KHÔNG suy ra được từ nhau
 *
 * | method | phạm vi | dùng ở |
 * |---|---|---|
 * | `sku` / `requireSku` | không | mọi đường ghi đơn |
 * | `activeMenuLine` | chi nhánh + `is_active` (+ SKU nếu truyền) | dòng có `menu_product_sku_id` đích danh |
 * | `cheapestActiveMenuLine` | chi nhánh + `is_active`, rẻ nhất, hoà thì theo id | quy tắc #514 |
 * | `menuLine` | KHÔNG có | replay đơn offline (#1092) |
 * | `requireProductSkuIdForMenuLine` / `brandIdForMenu` | KHÔNG có | hai phép tra thô của đường persist |
 *
 * Hai phép cuối cố tình **không** có phạm vi chi nhánh: chỗ gọi (`EloquentOrderPersistence`)
 * vốn không có, và đây là PR ranh giới — thêm phạm vi vào là đổi hành vi kèm theo,
 * đúng thứ mà "chuyển tiếp, không tính lại" cấm.
 *
 * ## `require*` ném `ModelNotFoundException`, và điều đó là cố ý
 *
 * Ba chỗ gọi cũ dùng `findOrFail()`, tức tầng HTTP dịch thành **404**. Chuyển thành
 * `null` rồi để Ordering tự ném một ngoại lệ khác là đổi mã trạng thái của một API
 * đang chạy. Chủ sở hữu model là chủ sở hữu câu "không tìm thấy nó" — cùng ruling
 * mà `SkuDirectory::getWithRecipeForOrganization` đã chốt ở #1567.
 */
interface OrderLineCatalogAnchors
{
    /**
     * `null` = SKU không tồn tại. Chỗ gọi phân biệt được với "tồn tại nhưng không
     * bán được" ({@see OrderLineSkuAnchor::$sellable}) vì hai ca đó là hai thông
     * điệp lỗi khác nhau cho người dùng.
     */
    public function sku(string $productSkuId): ?OrderLineSkuAnchor;

    /**
     * Như trên nhưng không tìm thấy là lỗi — giữ nguyên 404 của `findOrFail()`.
     *
     * @throws ModelNotFoundException
     */
    public function requireSku(string $productSkuId): OrderLineSkuAnchor;

    /**
     * Dòng menu ĐÍCH DANH, chỉ khi nó đang bật và thuộc một menu của chi nhánh này.
     *
     * `$productSkuId` khác null thì thêm điều kiện "dòng menu này đúng là của SKU
     * đó" — `addItems` cần nó (client gửi cả hai id, không được để lệch nhau), còn
     * đường typed thì lấy SKU TỪ dòng menu nên không có gì để đối chiếu.
     *
     * `null` = không có hàng nào thoả. Chỗ gọi tự quyết ném 422 (`addItems`) hay
     * `InvalidArgumentException` (đường typed) — hai hợp đồng lỗi khác nhau, và cổng
     * không được chọn hộ.
     */
    public function activeMenuLine(string $menuProductSkuId, string $branchId, ?string $productSkuId = null): ?OrderLineMenuAnchor;

    /**
     * Quy tắc #514: trong các menu ĐANG BẬT của chi nhánh có chứa SKU này, lấy dòng
     * có `selling_price` THẤP NHẤT, hoà thì theo `id`.
     *
     * Cả ba mảnh đều load-bearing. Không có phạm vi chi nhánh thì menu của chi nhánh
     * khác lọt vào (một SKU nằm trên 16+ menu ở staging). Không có `orderBy` thì DB
     * trả hàng nào tuỳ hứng — ORD-2026-4216 đã thu 3.471đ trên một đơn ghi 3.667đ
     * đúng vì thế. "Thấp nhất" cũng là bảo đảm không bao giờ tính cao hơn cái mà một
     * menu nào đó đã trưng ra.
     */
    public function cheapestActiveMenuLine(string $branchId, string $productSkuId): ?OrderLineMenuAnchor;

    /**
     * Dòng menu theo id, KHÔNG phạm vi chi nhánh và KHÔNG lọc `is_active`.
     *
     * Dùng bởi đường replay đơn OFFLINE (#1092): giá đã được chốt trong
     * `catalog_revisions` từ lúc bán, nên câu hỏi ở đây KHÔNG phải "dòng này còn
     * bán được không" mà là "định danh của dòng này còn phục hồi được không". Lọc
     * `is_active` hay phạm vi chi nhánh vào đây sẽ từ chối một đơn ngay tình chỉ vì
     * cửa hàng đã tắt món đó sau khi khách trả tiền.
     *
     * `null` = hàng `menu_product_skus` không còn tồn tại; chỗ gọi phân biệt ca đó
     * (`offline_menu_line_deleted`) với ca dòng menu bị trỏ sang SKU khác
     * (`offline_menu_line_repointed`) — hai lý do từ chối khác nhau.
     */
    public function menuLine(string $menuProductSkuId): ?OrderLineMenuAnchor;

    /**
     * SKU mà một dòng menu trỏ vào. Không phạm vi, không lọc `is_active`.
     *
     * @throws ModelNotFoundException
     */
    public function requireProductSkuIdForMenuLine(string $menuProductSkuId): string;

    /**
     * Brand sở hữu một menu — đường persist neo tenant vào đây khi snapshot đã nêu
     * tên menu. `null` = menu không tồn tại; chỗ gọi từ chối ghi một đơn không brand.
     */
    public function brandIdForMenu(string $menuId): ?string;
}
