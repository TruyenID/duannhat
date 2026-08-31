<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Material;
use App\Services\Inventory\Contracts\ActiveMaterialDirectory;
use Illuminate\Database\Eloquent\Collection;

/**
 * #962 — hiện thực Eloquent của {@see ActiveMaterialDirectory}. Một câu
 * truy vấn, chép nguyên từ `ProductSkuService::checkUsage`.
 */
final class EloquentActiveMaterialDirectory implements ActiveMaterialDirectory
{
    public function activeByIds(array $materialIds): Collection
    {
        if ($materialIds === []) {
            return new Collection;
        }

        return Material::query()
            ->whereIn('id', $materialIds)
            ->where('is_active', true)
            ->get();
    }
}
