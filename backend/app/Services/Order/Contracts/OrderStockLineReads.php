<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1731 — Ordering công bố quyền ĐỌC dòng đơn cho động cơ trừ kho, **kèm khoá**.
 *
 * Đây là nửa còn thiếu của #962. #1567 đã cho Inventory đọc SKU + công thức qua
 * `App\Services\Product\Contracts\SkuDirectory` — viết dạng chuỗi chứ KHÔNG
 * `{@see}`, vì `{@see}` sẽ bị pint kéo thành một `use` thật và dựng ra đúng một
 * cạnh Ordering → Catalog trong chính file công bố ranh giới. Nhưng
 * `StockDeductionService` vẫn phải giữ `App\Models\CustomerOrderItem` vì một lý
 * do mà một cổng chỉ-đọc không thay được: nó `lockForUpdate()` dòng đơn **trong
 * cùng transaction** với việc ghi kho. Mất khoá là mất chống trừ kho hai lần.
 *
 * ## Khoá đi xuyên qua cổng — vì transaction là của CHỖ GỌI
 *
 * `SELECT … FOR UPDATE` khoá dòng cho tới hết **transaction hiện hành**, và
 * transaction là trạng thái của kết nối chứ không phải của class phát ra câu
 * lệnh. Nên adapter khoá bên trong `DB::transaction` mà Inventory mở, rồi trả về
 * một ảnh chụp bất biến: khoá vẫn do transaction đó giữ tới lúc commit. Cổng trả
 * VO không hề làm yếu khoá — điều làm yếu khoá là gọi nó ngoài transaction.
 *
 * Vì thế hai method `lock*` **ném** khi không có transaction nào đang mở, thay
 * vì tự mở một cái. Cùng phán quyết đã ghi ở {@see OrderRowLock}: tự mở
 * transaction riêng sẽ nhả khoá ngay khi method trả về và để lại đúng cái ảo
 * giác an toàn mà khoá sinh ra để chặn. Khác {@see OrderRowLock} ở chỗ nó chỉ
 * *ghi chú* luật này còn ở đây luật được **cưỡng chế** — đường trừ kho là chỗ
 * gọi sai thì mất tiền hàng, không phải mất một dòng log.
 *
 * ## Không tìm thấy ⇒ `null` / danh sách rỗng, KHÔNG ném
 *
 * Cùng lựa chọn với {@see OrderStockContextReads}: chỗ gọi đang ở giữa một
 * giao dịch bán hàng, nên ném vì một dòng vừa bị xoá song song sẽ cuộn ngược cả
 * việc bán.
 */
interface OrderStockLineReads
{
    /**
     * Đơn nào chứa dòng này — KHÔNG nạp cả dòng.
     *
     * Tồn tại riêng vì chỗ gọi cần biết đơn **trước khi** mở transaction, và
     * chính dòng đó sẽ được đọc lại DƯỚI KHOÁ ngay sau đó. Dùng `find()` ở đây
     * là nạp topping cho một dòng sắp bị đọc lại.
     */
    public function orderIdOf(string $orderItemId): ?string;

    public function find(string $orderItemId): ?OrderLineStockSnapshot;

    /**
     * Khoá dòng rồi trả ảnh chụp. Không lọc gì — chỗ gọi (đường sửa số lượng)
     * đã tự kiểm dấu đã-trừ trước đó.
     *
     * @throws \LogicException khi gọi ngoài transaction
     */
    public function lockLine(string $orderItemId): ?OrderLineStockSnapshot;

    /**
     * Khoá dòng và trả ảnh chụp **chỉ khi** nó còn trừ kho được: chưa có dấu
     * đã-trừ, chưa bị huỷ, không phải dòng hoàn tiền.
     *
     * Ba điều kiện nằm TRONG câu truy vấn khoá chứ không phải kiểm sau khi đọc:
     * đó là thứ làm hai hook đồng thời (bấm hai lần, sync phát lại) tuần tự hoá
     * trên chính dòng đó, và kẻ thua nhìn thấy dấu của kẻ thắng.
     *
     * @throws \LogicException khi gọi ngoài transaction
     */
    public function lockUndeductedLine(string $orderItemId): ?OrderLineStockSnapshot;

    /**
     * Mọi dòng của đơn còn trừ kho được (lượt quét lúc đóng đơn).
     *
     * @return list<OrderLineStockSnapshot>
     */
    public function undeductedLinesOfOrder(string $orderId): array;

    /**
     * Mọi dòng CHƯA HUỶ của đơn — không lọc theo dấu đã-trừ, không lọc dòng
     * hoàn tiền. Đây là tập của phả hệ bán hàng, và bộ lọc hẹp hơn ở đây sẽ làm
     * mất cạnh phả hệ của những dòng đã trừ kho từ trước.
     *
     * @return list<OrderLineStockSnapshot>
     */
    public function activeLinesOfOrder(string $orderId): array;

    /**
     * @param  list<string>  $orderItemIds
     * @return list<OrderLineStockSnapshot>
     */
    public function byIds(array $orderItemIds): array;
}
