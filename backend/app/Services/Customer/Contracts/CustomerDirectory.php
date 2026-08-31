<?php

declare(strict_types=1);

namespace App\Services\Customer\Contracts;

/**
 * #1993 — CustomerEngagement công bố "gọi tên những khách này ra sao".
 *
 * Hẹp đúng bằng câu hỏi đó. Nó KHÔNG phải cổng đọc khách (đã có
 * {@see CustomerQueryPort}); `CustomerSnapshot` cố ý chỉ mang id/tổ chức/chi
 * nhánh/trạng thái, tức không trả lời được câu "hiện gì trên màn hình".
 *
 * ## Cố ý KHÔNG lọc khách đã xoá mềm
 *
 * Sổ nợ hỏi cổng này sau khi đã biết những khoản nợ nào còn mở. Một khách bị xoá
 * mềm **không xoá món nợ họ đang thiếu** — và vì đây chỉ là dữ liệu hiển thị,
 * lọc ở đây sẽ không làm rụng khoản nợ mà làm nó mất tên: một khoản nợ không
 * biết của ai còn tệ hơn một khoản nợ mang tên một hồ sơ đã xoá. Ghim bằng test
 * ở `DebtDetailFeedTest` — nếu một lần dọn dẹp sau này "sửa cho đồng bộ" thì nó
 * đỏ.
 *
 * Người gọi cần phân biệt khách còn sống hay không thì hỏi `CustomerQueryPort`;
 * đó là câu hỏi khác và không phải việc của cổng này.
 */
interface CustomerDirectory
{
    /**
     * @param  list<string>  $customerIds
     * @return array<string, CustomerDirectoryEntry> khoá là id khách; thiếu khoá = không tra được
     */
    public function entriesByIds(array $customerIds): array;
}
