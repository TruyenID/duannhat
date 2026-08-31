<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1662 (#962 · 7a-6) — Ordering hỏi "ca thu ngân nào đang mở ở chi nhánh này".
 *
 * ## Vì sao cổng này do ORDERING khai, không phải Pos
 *
 * Ordering được publish theo **namespace** (`published_contract_namespaces` chứa
 * `App\Services\Order\Contracts`), còn Pos/Payments publish **theo từng class** ⇒ để
 * Pos khai cổng thì phải sửa `config/modules.php`. Đảo chiều khai báo — consumer khai
 * interface, provider hiện thực — thì cạnh biến mất mà không đụng file đang bị PR khác
 * giữ. Đây là dependency inversion dùng đúng chỗ nó có lợi thật.
 *
 * ## Cái này KHÔNG phải "lấy ca hiện tại của thu ngân"
 *
 * Nó là **đóng dấu tiền vào ca nào** lúc tạo đơn: `customer_orders.till_session_id`.
 * Trả `null` là hợp lệ và thường gặp — đơn tạo ngoài giờ mở ca (khách tự đặt qua QR,
 * kiosk) thì không thuộc ca nào, và plan-044 R2 gọi đó là "gap payment", được đối soát
 * tay ở lần mở ca sau. **Đừng** biến `null` thành lỗi.
 */
interface OpenTillSessionLookup
{
    /**
     * Id ca đang mở của chi nhánh, hoặc `null` nếu không có ca nào mở.
     */
    public function openSessionIdForBranch(string $branchId): ?string;
}
