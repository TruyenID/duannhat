<?php

declare(strict_types=1);

namespace App\Services\Inventory\Internal;

use App\Models\Material;
use App\Services\Inventory\Contracts\MaterialDirectory;
use App\Services\Inventory\Contracts\MaterialSnapshot;

/**
 * #962 — bản cài Eloquent của {@see MaterialDirectory}. Thuộc Inventory (module
 * sở hữu `Material` / `MaterialUnit`), nên nó ĐƯỢC PHÉP chạm model.
 *
 * `adoptRecipeYield` là bản chuyển nguyên vẹn của
 * `RecipeService::syncOutputMaterialFromRecipe()` — cùng thứ tự kiểm, cùng
 * điều kiện idempotent, cùng cách tạo đơn vị gốc. Ranh giới không phải chỗ để
 * sửa hành vi; chỉ chỗ ĐẶT luật đổi.
 */
final class EloquentMaterialDirectory implements MaterialDirectory
{
    public function find(string $materialId): ?MaterialSnapshot
    {
        $material = Material::query()->find($materialId, ['id', 'sku', 'brand_id']);

        return $material === null ? null : $this->toSnapshot($material);
    }

    public function registeredUnits(string $materialId): array
    {
        $material = Material::query()->find($materialId, ['id']);

        if ($material === null) {
            return [];
        }

        return $material->materialUnits()->pluck('unit')->all();
    }

    public function indexBySkuForBrand(string $organizationId, string $brandId): array
    {
        return Material::query()
            ->where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->get(['id', 'sku', 'brand_id'])
            ->mapWithKeys(fn (Material $material) => [
                strtoupper(trim((string) $material->sku)) => $this->toSnapshot($material),
            ])
            ->all();
    }

    public function adoptRecipeYield(string $materialId, string $outputUnit, ?float $outputQuantity): void
    {
        if ($outputUnit === '') {
            return;
        }

        $material = Material::query()->find($materialId);

        // Idempotent: chỉ nguyên liệu CHƯA có sản lượng mới được nâng cấp. Lần
        // duyệt sau không bao giờ ghi đè giá trị người vận hành đã sửa tay.
        if ($material === null || $material->yield_unit !== null) {
            return;
        }

        $material->update([
            'yield_unit' => $outputUnit,
            'yield_quantity' => $outputQuantity ?? $material->yield_quantity,
        ]);

        // Đăng ký `output_unit` làm đơn vị GỐC nếu nguyên liệu chưa có đơn vị
        // nào — điều kiện để `MaterialBatchService::complete()` phân giải được
        // đơn vị gốc lúc đúc lô sản xuất đầu ra.
        if (! $material->materialUnits()->exists()) {
            $material->materialUnits()->create([
                'unit' => $outputUnit,
                'ratio' => 1.0,
                'is_base' => true,
            ]);
        }
    }

    private function toSnapshot(Material $material): MaterialSnapshot
    {
        return new MaterialSnapshot(
            (string) $material->getKey(),
            $material->sku === null ? null : (string) $material->sku,
            $material->brand_id === null ? null : (string) $material->brand_id,
        );
    }
}
