<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Material;
use Illuminate\Database\Eloquent\Builder;

class MaterialExporter extends CsvExporter
{
    protected function getQuery(string $organizationId, array $filters = []): Builder
    {
        $query = Material::where('organization_id', $organizationId);

        // plan-040 TI.4 (H11): scope export to the caller's brand so a brand A
        // admin can never stream brand B's materials.
        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        return $query;
    }

    protected function getHeaders(): array
    {
        return [
            'id',
            'sku',
            'name',
            'description',
            'yield_quantity',
            'yield_unit',
            'calculated_cost',
            'is_active',
        ];
    }

    protected function mapRow(mixed $model): array
    {
        return [
            $model->id,
            $model->sku ?? '',
            $model->name,
            $model->description ?? '',
            $model->yield_quantity,
            $model->yield_unit ?? '',
            $model->calculated_cost,
            $model->is_active ? 'true' : 'false',
        ];
    }

    protected function getFilenamePrefix(): string
    {
        return 'materials_export';
    }
}
