<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;

class CategoryExporter extends CsvExporter
{
    protected function getQuery(string $organizationId, array $filters = []): Builder
    {
        return Category::where('organization_id', $organizationId)
            ->with('parent');
    }

    protected function getHeaders(): array
    {
        return [
            'id',
            'sku',
            'name',
            'slug',
            'description',
            'parent_sku',
            'is_active',
        ];
    }

    protected function mapRow(mixed $model): array
    {
        return [
            $model->id,
            $model->sku ?? '',
            $model->name,
            $model->slug ?? '',
            $model->description ?? '',
            $model->parent?->sku ?? '',
            $model->is_active ? 'true' : 'false',
        ];
    }

    protected function getFilenamePrefix(): string
    {
        return 'categories_export';
    }
}
