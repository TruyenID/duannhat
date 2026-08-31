<?php

declare(strict_types=1);

namespace App\Services\Menu\Internal;

use App\Services\Menu\Contracts\ShopMenuSections;
use Illuminate\Support\Facades\DB;

/**
 * #1622 — hiện thực {@see ShopMenuSections}.
 *
 * Bốn truy vấn chép NGUYÊN từ `PosRevenueService`, kể cả những chỗ trông như chi
 * tiết vụn nhưng là quyết định:
 *
 * - **`whereNull('deleted_at')`** trên `menus` và `menu_sections`: bỏ đi thì mục
 *   đã xoá mềm quay lại dropdown.
 * - **`where('brand_id', $brandId)` trước** mệnh đề chi nhánh: không có nó, một
 *   cửa hàng nhặt phải menu của **thương hiệu khác** cũng ghim vào đúng
 *   `branch_id` đó — dữ liệu seed vẫn có ca này (ghi trong comment bản cũ).
 * - **`MIN(ms.id)` + `groupBy('ms.name')`**: gộp theo tên, id nhỏ nhất làm đại
 *   diện, nên danh sách ổn định giữa các request.
 *
 * Vẫn dùng query builder thô: bản cũ như vậy, và đổi sang Eloquent là đổi số
 * truy vấn trên đường báo cáo — việc riêng, không gộp vào PR dời ranh giới.
 */
final class EloquentShopMenuSections implements ShopMenuSections
{
    public function menuIdsForShop(string $branchId, ?string $brandId): array
    {
        $query = DB::table('menus')->whereNull('deleted_at');

        if ($brandId !== null) {
            $query->where('brand_id', $brandId);
        }

        $query->where(function ($q) use ($branchId, $brandId) {
            $q->where('branch_id', $branchId);
            if ($brandId !== null) {
                // menu chung của thương hiệu (không ghim chi nhánh nào)
                $q->orWhereNull('branch_id');
            }
        });

        return $query->pluck('id')->map(fn ($v): string => (string) $v)->all();
    }

    public function sectionsForShop(string $branchId, ?string $brandId): array
    {
        $menuIds = $this->menuIdsForShop($branchId, $brandId);
        if ($menuIds === []) {
            return [];
        }

        return DB::table('menu_sections as ms')
            ->join('menu_menu_sections as mms', 'mms.menu_section_id', '=', 'ms.id')
            ->whereIn('mms.menu_id', $menuIds)
            ->whereNull('ms.deleted_at')
            ->selectRaw('MIN(ms.id) as id, ms.name as name')
            ->groupBy('ms.name')
            ->orderBy('ms.name')
            ->get()
            ->map(fn ($r): array => [
                'id' => (string) $r->id,
                'name' => (string) ($r->name ?? ''),
            ])
            ->all();
    }

    public function sectionName(string $sectionId): ?string
    {
        $name = DB::table('menu_sections')->where('id', $sectionId)->value('name');

        return $name === null ? null : (string) $name;
    }

    public function sectionIdsSharingName(string $sectionId, array $menuIds): array
    {
        if ($menuIds === []) {
            return [$sectionId];
        }

        $name = $this->sectionName($sectionId);
        if ($name === null) {
            return [$sectionId];
        }

        $ids = DB::table('menu_sections as ms')
            ->join('menu_menu_sections as mms', 'mms.menu_section_id', '=', 'ms.id')
            ->whereIn('mms.menu_id', $menuIds)
            ->whereNull('ms.deleted_at')
            ->where('ms.name', $name)
            ->pluck('ms.id')
            ->map(fn ($v): string => (string) $v)
            ->all();

        return $ids === [] ? [$sectionId] : array_values(array_unique($ids));
    }
}
