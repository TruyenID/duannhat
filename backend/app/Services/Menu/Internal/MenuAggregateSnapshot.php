<?php

declare(strict_types=1);

namespace App\Services\Menu\Internal;

use App\Models\Menu;
use App\Services\Menu\Contracts\MenuSnapshot;

/**
 * Ảnh chụp bất biến của một hàng `menus` cho người đọc ngoài Catalog (#1550).
 *
 * Dựng theo đúng khuôn `Order\Internal\CustomerOrderSnapshot` — không sáng chế
 * hình dạng mới cho một thứ repo đã có tiền lệ.
 *
 * `version()` trả **0**, và đó là sự thật chứ không phải chỗ trống chờ điền:
 * bảng `menus` KHÔNG có cột `version` (kiểm migration `create_menus_table`).
 * `AggregateSnapshot` đòi method đó vì đường ĐƠN HÀNG có khoá lạc quan; menu
 * thì chưa từng có. Trả một số giả tăng dần ở đây sẽ mời người sau xây kiểm tra
 * xung đột trên một con số không ai ghi.
 */
final class MenuAggregateSnapshot implements MenuSnapshot
{
    private function __construct(
        private readonly string $id,
        private readonly string $organizationId,
        private readonly string $brandId,
        private readonly ?string $branchId,
        private readonly string $status,
    ) {}

    public static function fromModel(Menu $menu): self
    {
        return new self(
            (string) $menu->id,
            (string) $menu->organization_id,
            (string) $menu->brand_id,
            $menu->branch_id === null ? null : (string) $menu->branch_id,
            (string) ($menu->status instanceof \BackedEnum ? $menu->status->value : $menu->status),
        );
    }

    public function aggregateId(): string
    {
        return $this->id;
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }

    public function brandId(): string
    {
        return $this->brandId;
    }

    public function branchId(): ?string
    {
        return $this->branchId;
    }

    public function status(): string
    {
        return $this->status;
    }

    /** Xem docblock của class: `menus` không có cột version. */
    public function version(): int
    {
        return 0;
    }
}
