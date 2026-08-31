<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 — hai trường của `shop_order_settings` mà một ca két chụp lại lúc MỞ CA,
 * đọc dưới khoá hàng (xem {@see BranchOrderSettingsLock}).
 *
 * Cố ý KHÔNG áp mặc định ở đây. `currencyCode` trả về NGUYÊN trạng — kể cả chuỗi
 * rỗng — vì chỗ gọi rơi về `till.default_currency_code` rồi mới tới `'JPY'`, và
 * `?:` của bản cũ coi `''` là "chưa cấu hình". Nhét `?? 'JPY'` vào đây là đổi thứ
 * tự fallback ấy mà không ai thấy. Cùng lý do với {@see BranchCurrency}.
 */
final class LockedBranchOrderSettings
{
    public function __construct(
        public readonly ?string $currencyCode,
        public readonly bool $pricesIncludeTax,
    ) {}
}
