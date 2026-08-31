<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Product;
use App\Omnify\Enums\ProductStatusEnum;
use Illuminate\Database\Eloquent\Builder;

class ProductExporter extends CsvExporter
{
    protected function getQuery(string $organizationId, array $filters = []): Builder
    {
        // plan-043 T2.6 — eager-load taxType so mapRow() can emit its code
        // without an N+1 lookup per product row.
        $query = Product::where('organization_id', $organizationId)
            ->with(['productType', 'categories', 'taxType']);

        // Brand scope is required for HQ exports — a single org can host
        // multiple brands and the list endpoint always scopes by brand_id
        // (resolved from the {brandSlug} URL segment). Without the same
        // scope here, the CSV leaks rows from sibling brands.
        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        // Hard scope: when the FE sends `ids[]` (export-selected-rows flow),
        // it takes absolute precedence — all other filters are ignored.
        // This is the only path that requires the IDs to belong to this org;
        // organization_id above already enforces that.
        if (! empty($filters['ids']) && is_array($filters['ids'])) {
            return $query->whereIn('id', $filters['ids']);
        }

        // Mirror the filters supported by `GET /products` so the CSV stays
        // in sync with whatever the user is looking at on the list page.
        // Anything not in this whitelist is intentionally ignored — adding
        // unfiltered query params shouldn't change the export.
        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            // Match against name/slug on products + sku on related SKUs.
            // `sku` is not a column on the products table — it lives on
            // product_skus, so search the relation via whereHas.
            $query->where(function (Builder $q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%")
                    ->orWhereHas('skus', fn (Builder $s) => $s->where('sku', 'like', "%{$term}%"));
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['product_type_id'])) {
            $query->where('product_type_id', $filters['product_type_id']);
        }

        if (! empty($filters['category_id'])) {
            $query->whereHas(
                'categories',
                fn (Builder $q) => $q->where('categories.id', $filters['category_id']),
            );
        }

        if (array_key_exists('is_hidden', $filters) && $filters['is_hidden'] !== '') {
            $query->where('is_hidden', filter_var($filters['is_hidden'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['with_trashed']) && filter_var($filters['with_trashed'], FILTER_VALIDATE_BOOLEAN)) {
            $query->withTrashed();
        }

        // Pagination — "export current page" scope. Matches the list page's
        // ordering (-updated_at) so the CSV mirrors what's on screen.
        if (! empty($filters['page']) || ! empty($filters['per_page'])) {
            $perPage = max(1, min(500, (int) ($filters['per_page'] ?? 25)));
            $page = max(1, (int) ($filters['page'] ?? 1));
            $query->orderByDesc('updated_at')
                ->limit($perPage)
                ->offset(($page - 1) * $perPage);
        }

        return $query;
    }

    protected function getHeaders(): array
    {
        // No `sku` column — a product can have many SKUs (Size × Color × …),
        // so flattening into one CSV row would lose data. SKU-level exports
        // belong to the dedicated /skus/export endpoint.
        return [
            'id',
            'name',
            'product_type_code',
            'category_skus',
            'description',
            'is_sellable',
            'is_hidden',
            // plan-043 T2.6 — tax_type_code (empty = inherit/null).
            'tax_type_code',
            'status',
        ];
    }

    protected function mapRow(mixed $model): array
    {
        // Cast model->status (ProductStatusEnum) to its backing string value;
        // fputcsv can't stringify an enum directly.
        $status = $model->status instanceof ProductStatusEnum
            ? $model->status->value
            : ($model->status ?? ProductStatusEnum::Draft->value);

        return [
            $model->id,
            $model->name,
            $model->productType?->code ?? '',
            $model->categories->pluck('sku')->join(','),
            $model->description ?? '',
            $model->is_sellable ? 'true' : 'false',
            $model->is_hidden ? 'true' : 'false',
            // plan-043 T2.6 — tax_type_code is empty for inherit/null products;
            $model->taxType?->code ?? '',
            $status,
        ];
    }

    protected function getFilenamePrefix(): string
    {
        return 'products_export';
    }
}
