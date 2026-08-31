<?php

declare(strict_types=1);

namespace App\Services\Menu\Internal;

use App\Models\Menu;
use App\Models\MenuMenuSection;
use App\Services\Tax\Contracts\MenuTaxTypeAnchors;

/**
 * #962 — Catalog hiện thực cổng tra loại thuế của menu/mục mà Pricing khai.
 *
 * Hai truy vấn dưới đây được **chép nguyên** từ `TaxResolver::menuDefault()` và
 * `::sectionDefault()`, cố ý không "dọn" gì: đây là PR ranh giới, đổi chỗ code chứ
 * không đổi tỉ lệ nào được chọn. Đổi một điều kiện ở đây là đổi **thuế đóng lên
 * đơn**.
 *
 * `->with('taxType')` + `?->taxType?->id` chứ không phải `value('tax_type_id')`:
 * xem {@see MenuTaxTypeAnchors} — quan hệ đi qua `SoftDeletingScope`, cột thì không,
 * và khác nhau ở đúng ca "loại thuế đã bị xoá mềm".
 *
 * Memo hoá KHÔNG nằm ở đây. `TaxResolver` giữ memo theo vòng đời của chính nó (một
 * resolver cho mỗi thao tác đơn) và đó là điều kiện để memo không cũ giữa hai
 * request; một cache trong adapter singleton sẽ sống lâu hơn thế.
 */
final class EloquentMenuTaxTypeAnchors implements MenuTaxTypeAnchors
{
    public function taxTypeIdForMenu(string $menuId): ?string
    {
        $type = Menu::query()
            ->whereKey($menuId)
            ->with('taxType')
            ->first()?->taxType;

        return $type?->id === null ? null : (string) $type->id;
    }

    public function taxTypeIdForMenuSection(string $menuId, string $menuSectionId): ?string
    {
        $type = MenuMenuSection::query()
            ->where('menu_id', $menuId)
            ->where('menu_section_id', $menuSectionId)
            ->with('taxType')
            ->first()?->taxType;

        return $type?->id === null ? null : (string) $type->id;
    }
}
