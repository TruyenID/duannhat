<?php

declare(strict_types=1);

namespace App\Services\Till\Contracts;

/**
 * #962 — PlatformIntegration hỏi Payments "tổ chức này nhận những loại
 * tiền nào".
 *
 * Máy đếm tiền / két là `PeripheralDevice` (PlatformIntegration), nhưng khoá
 * tender nó khai trong `metadata.accepts` phải nằm trong TỪ VỰNG của tổ chức —
 * `till_tender_types`, bảng của Payments. Cạnh là thật; cái sai là
 * `PeripheralDeviceService` tự truy vấn bảng đó ở hai chỗ.
 *
 * Cả hai method chỉ đọc từ vựng CẤP TỔ CHỨC (`branch_id IS NULL`) và chỉ mục còn
 * `is_active`. Đó là điều kiện của cả hai truy vấn gốc, nên nó nằm trong hợp
 * đồng chứ không phải trong tham số: một cổng cho phép đọc cả từ vựng riêng của
 * chi nhánh sẽ là một câu hỏi khác, chưa ai cần.
 */
interface OrgTenderVocabulary
{
    /** Khoá tender này có trong từ vựng cấp tổ chức đang hoạt động không. */
    public function hasActiveOrgKey(string $organizationId, string $tenderKey): bool;

    /**
     * Lọc `$tenderKeys` xuống những khoá tổ chức thật sự có (và đang hoạt động).
     * KHÔNG bảo toàn thứ tự đầu vào — caller nào cần thứ tự thì tự sắp theo
     * danh sách gốc của mình.
     *
     * @param  list<string>  $tenderKeys
     * @return list<string>
     */
    public function activeOrgKeysAmong(string $organizationId, array $tenderKeys): array;
}
