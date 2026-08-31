<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\ProductSku;
use Illuminate\Database\Eloquent\Builder;

class ProductSkuExporter extends CsvExporter
{
    protected function getQuery(string $organizationId, array $filters = []): Builder
    {
        return ProductSku::whereHas(
            'product',
            fn ($q) => $q->where('organization_id', $organizationId)
                ->when($filters['brand_id'] ?? null, fn ($q, $brandId) => $q->where('brand_id', $brandId)),
        )->with(['product', 'recipe']);
    }

    protected function getHeaders(): array
    {
        return [
            'id',
            'product_sku',
            'sku',
            'name',
            'option1',
            'value1',
            'option2',
            'value2',
            'option3',
            'value3',
            'recipe_sku',
            'recipe_multiplier',
            'selling_price',
            'cost_price',
            'is_cost_override',
            'is_active',
        ];
    }

    protected function mapRow(mixed $model): array
    {
        return [
            $model->id,
            $model->product?->slug ?? '',
            $model->sku,
            $model->name,
            $model->option1 ?? '',
            $model->value1 ?? '',
            $model->option2 ?? '',
            $model->value2 ?? '',
            $model->option3 ?? '',
            $model->value3 ?? '',
            $model->recipe?->sku ?? '',
            $model->recipe_multiplier ?? '1.0',
            $model->selling_price ?? '',
            $model->cost_price ?? '',
            $model->is_cost_override ? 'true' : 'false',
            $model->is_active ? 'true' : 'false',
        ];
    }

    protected function getFilenamePrefix(): string
    {
        return 'product_skus_export';
    }
}
