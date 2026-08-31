<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 · 7a-7 — "cặp (topping, SKU) này có thật trong catalog không?".
 *
 * Do Ordering khai, Catalog hiện thực (xem {@see OrderMenuLineDirectory} về chiều
 * khai báo).
 *
 * ## Đây là cổng ĐỌC-THAM-CHIẾU, không phải cổng hợp lệ hoá
 *
 * Nó chỉ trả lời "hàng có tồn tại không" cho đường **replay đơn workstation**: máy
 * trạm gửi lên một dòng topping trỏ vào id mà Cloud không còn (menu đã sửa sau khi
 * máy trạm chụp catalog). Đường đó **bỏ qua** dòng lỗi và ghi `Log::warning` chứ
 * không ném — đơn đã bán rồi, chặn cả đơn vì một topping mồ côi là làm mất doanh
 * thu thật. Đừng biến nó thành 422.
 *
 * Toàn bộ luật topping thật (nhóm bắt buộc, min/max, giá snapshot) sống ở
 * `App\Services\Order\Internal\ToppingSelectionPricer`, KHÔNG ở đây.
 */
interface ToppingSelectionExistence
{
    /**
     * `true` khi CẢ HAI id đều trỏ vào hàng còn tồn tại (soft-delete tính là không
     * tồn tại — đó là ngữ nghĩa của cả hai model).
     */
    public function selectionExists(string $toppingGroupItemId, string $productSkuId): bool;
}
