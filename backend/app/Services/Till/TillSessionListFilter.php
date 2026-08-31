<?php

namespace App\Services\Till;

use Illuminate\Support\Carbon;

/**
 * #1562 — filter đã-được-giải-nghĩa cho lịch sử ca két (plan-036).
 *
 * `ShopTillTrackingService` trước đây nhận thẳng `ListTillSessionsRequest`,
 * tức tầng Payments đọc ngược lên một `FormRequest` trong `App\Http`
 * (Composition). Đó là 3 cạnh vi phạm, và quan trọng hơn: nó khoá service
 * vào một request HTTP, nên không gọi được từ command, job hay test mà
 * không dựng giả một request.
 *
 * Mọi giá trị ở đây đã **giải nghĩa xong** — mặc định ngày, mặc định
 * per-page/sort, lọc mảng rỗng đều do `ListTillSessionsRequest::toFilter()`
 * làm ở tầng delivery. Cố ý để việc đó lại bên kia: các accessor đó gọi
 * `now()`, và đồng hồ ứng dụng thô chỉ được phép dùng ở tầng trình bày
 * (#1091). Bê nó vào service là đổi một vi phạm ranh giới lấy một vi phạm
 * business-time.
 */
final class TillSessionListFilter
{
    /**
     * @param  list<string>  $tillIds
     * @param  list<string>  $statuses
     */
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly int $perPage,
        public readonly string $sort,
        public readonly array $tillIds = [],
        public readonly array $statuses = [],
        public readonly ?string $openerId = null,
        public readonly ?string $variance = null,
        public readonly ?bool $forceAbandoned = null,
    ) {}
}
