<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 (7b) — Ordering công bố: *"bàn này có đang dính đơn CHƯA ĐÓNG không?"*
 *
 * Song sinh với {@see CustomerOrderPresence} (khách) và {@see OpenOrderSkuUsage}
 * (SKU). Cùng một hình dạng vì cùng một nhu cầu: `TableService::delete()` và
 * `ZoneService::delete()` (Organization) chỉ cần **chặn hay cho qua** trước khi
 * xoá mềm, chứ không cần biết đơn nào.
 *
 * Trước bản vá, hai chỗ đó tự `CustomerOrder::open()->whereIn('table_id', …)`,
 * tức Organization tự đọc model của Ordering **và** tự quyết định trạng thái nào
 * là "chưa đóng". Cái thứ hai mới là chỗ đau: định nghĩa "mở" nằm ở scope
 * `CustomerOrder::open()`, và một bản sao thứ hai ở Organization sẽ lệch âm thầm
 * khi Ordering thêm/bớt trạng thái.
 *
 * **Nhận danh sách, không nhận một id.** Cả hai chỗ gọi đều đã hỏi theo lô
 * (`ZoneService` hỏi mọi bàn trong khu vực), nên nhận `list` giữ nguyên **một**
 * truy vấn; ép chúng gọi từng bàn một sẽ biến một câu `WHERE IN` thành N câu
 * ngay trên đường xoá khu vực của nhà hàng lớn.
 */
interface OpenOrderTableUsage
{
    /**
     * Có đơn CHƯA ĐÓNG nào đang gắn với một trong các bàn này không?
     *
     * Danh sách rỗng ⇒ `false` mà không chạm DB — người gọi vốn đã tự bỏ qua ca
     * đó, giữ nguyên để cổng không âm thầm thêm một truy vấn `WHERE IN ()`.
     *
     * @param  list<string>  $tableIds
     */
    public function anyOpenOrderUsesTables(array $tableIds): bool;
}
