<?php

declare(strict_types=1);

namespace App\Services\Topping\Contracts;

/**
 * #962 — cổng Catalog công bố: **bao nhiêu dòng topping của các nhóm này đang
 * trỏ vào sản phẩm không dùng được**.
 *
 * `MenuLocalizationIntegrityReporter` (CustomerEngagement) tự truy vấn
 * `App\Models\ToppingGroupItem` để đếm con số này rồi ghi log cảnh báo. Câu hỏi
 * thì hợp lệ — thực đơn khách phải loại các dòng đó — nhưng "dòng topping thế
 * nào là hỏng" là hiểu biết của Catalog: nó gồm cả liên kết MỒ CÔI (sản phẩm đã
 * biến mất) lẫn liên kết trỏ vào sản phẩm không còn `active`.
 *
 * Một con số duy nhất, cố ý không tách "mồ côi" khỏi "ngưng bán": chỗ gọi ghi
 * chúng vào CÙNG một dòng log (`invalid_topping_relation`), nên tách ra ở đây là
 * dựng sẵn một khác biệt chưa ai dùng.
 */
interface ToppingGroupItemIntegrity
{
    /**
     * `0` khi danh sách nhóm rỗng — không truy vấn.
     *
     * @param  list<string>  $toppingGroupIds
     */
    public function unusableItemCountForGroups(array $toppingGroupIds): int;
}
