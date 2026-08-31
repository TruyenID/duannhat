<?php

declare(strict_types=1);

namespace App\Services\Payment\Internal;

use App\Services\Order\Contracts\OpenTillSessionLookup;
use App\Services\Pos\TillSessionService;

/**
 * #1662 — hiện thực cổng "ca thu ngân nào đang mở" mà Ordering khai.
 *
 * ## Vì sao file này KHÔNG nằm cạnh `TillSessionService`
 *
 * Bản đầu đặt ở `App\Services\Pos\Internal\` theo phản xạ "để cạnh cái mình bọc",
 * và deptrac báo violation ngay. Lý do nằm ở `deptrac.yaml`: **toàn bộ
 * `App\Services\Pos\` thuộc layer Ordering**, trừ đúng ba class được nêu đích danh
 * (`TillSessionService`, `PosInvoiceService`, `PosReturnInvoiceService`) là Payments.
 * Nên một file mới đặt dưới `App\Services\Pos\` sẽ rơi vào **Ordering**, và Ordering
 * thì không được phụ thuộc Payments — đúng cạnh mà PR này đang gỡ.
 *
 * Ca thu ngân (`till_sessions`) do **Payments** sở hữu theo bản đồ layer, nên adapter
 * sống ở đây. Ai định "dọn cho gọn" bằng cách chuyển nó sang `App\Services\Pos\`:
 * đừng, cạnh sẽ mọc lại.
 *
 * Mỏng có chủ ý — định nghĩa "ca đang mở" chỉ được có MỘT chỗ, và chỗ đó là
 * {@see TillSessionService::openSessionIdForBranch()}. Adapter không tự đi tìm
 * `Till`/`TillSession`.
 */
final class TillSessionOpenLookup implements OpenTillSessionLookup
{
    public function __construct(private readonly TillSessionService $tillSessions) {}

    public function openSessionIdForBranch(string $branchId): ?string
    {
        return $this->tillSessions->openSessionIdForBranch($branchId);
    }
}
