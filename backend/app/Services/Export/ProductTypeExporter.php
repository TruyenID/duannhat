<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\ProductType;
use Illuminate\Database\Eloquent\Builder;

class ProductTypeExporter extends CsvExporter
{
    protected function getQuery(string $organizationId, array $filters = []): Builder
    {
        return ProductType::where('organization_id', $organizationId)
            ->orderBy('code');
    }

    protected function getHeaders(): array
    {
        return [
            'id',
            'code',
            'name',
            'description',
            'product_form',
            'has_recipe',
            'is_inventory_tracked',
            'icon',
            'is_active',
        ];
    }

    protected function mapRow(mixed $model): array
    {
        return [
            $model->id,
            $model->code,
            $model->name,
            $model->description ?? '',
            $model->product_form,
            $model->has_recipe ? 'true' : 'false',
            $model->is_inventory_tracked ? 'true' : 'false',
            $model->icon ?? '',
            $model->is_active ? 'true' : 'false',
        ];
    }

    protected function getFilenamePrefix(): string
    {
        return 'product_types_export';
    }
}
