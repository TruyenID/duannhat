<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Recipe;
use Illuminate\Database\Eloquent\Builder;

class RecipeExporter extends CsvExporter
{
    protected function getQuery(string $organizationId, array $filters = []): Builder
    {
        $query = Recipe::where('organization_id', $organizationId)
            ->with('material');

        // plan-040 TI.4 (H11): scope export to the caller's brand only.
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
            'material_sku',
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
            $model->material?->sku ?? '',
            $model->is_active ? 'true' : 'false',
        ];
    }

    protected function getFilenamePrefix(): string
    {
        return 'recipes_export';
    }
}
