<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Recipe;
use App\Omnify\Enums\ProductSkuInventoryModeEnum;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\Commands\CreateProductSkuCommand;
use App\Services\Product\Commands\ReviseProductSkuCommand;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\ValueObjects\ProductSkuPayload;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductSkuImporter extends CsvImporter
{
    public function __construct(private readonly ProductMutationFacade $mutations) {}

    private Collection $products;

    private Collection $recipes;

    private Collection $existingBySku;

    private Collection $existingById;

    protected function getRequiredColumns(): array
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

    protected function beforeImport(string $organizationId, string $brandId): void
    {
        $this->products = Product::where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->get()
            ->keyBy(fn ($p) => strtoupper($p->slug));

        $this->recipes = Recipe::where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->with('material')
            ->get()
            ->keyBy(fn ($r) => strtoupper($r->sku));

        $variants = ProductSku::withTrashed()
            ->whereHas('product', fn ($q) => $q->withTrashed()
                ->where('organization_id', $organizationId)
                ->where('brand_id', $brandId))
            ->with(['product' => fn ($q) => $q->withTrashed()])
            ->get();

        $this->existingBySku = $variants->keyBy(fn ($v) => strtoupper($v->sku));
        $this->existingById = $variants->keyBy('id');
    }

    protected function processRow(array $row, int $rowNumber, string $organizationId, string $brandId, array &$errors): ?string
    {
        $id = trim($row['id'] ?? '');
        $sku = strtoupper(trim($row['sku'] ?? ''));

        // Validate required fields
        $productSku = strtoupper(trim($row['product_sku'] ?? ''));
        if (empty($productSku)) {
            $errors[] = 'product_sku is required';
        } elseif (! $this->products->has($productSku)) {
            $errors[] = "product_sku '{$productSku}' not found";
        }

        if (empty(trim($row['name'] ?? ''))) {
            $errors[] = 'name is required';
        }

        // Validate recipe_sku
        $recipeSku = strtoupper(trim($row['recipe_sku'] ?? ''));
        if (! empty($recipeSku) && ! $this->recipes->has($recipeSku)) {
            $errors[] = "recipe_sku '{$recipeSku}' not found";
        }

        if (! empty($errors)) {
            return null;
        }

        $action = $this->resolveAction($id, $sku, $errors);
        if ($action === null) {
            return null;
        }

        $product = $this->products->get($productSku);
        $recipe = ! empty($recipeSku) ? $this->recipes->get($recipeSku) : null;

        $data = [
            'sku' => $sku ?: null,
            'name' => trim($row['name']),
            'option1' => trim($row['option1'] ?? '') ?: null,
            'value1' => trim($row['value1'] ?? '') ?: null,
            'option2' => trim($row['option2'] ?? '') ?: null,
            'value2' => trim($row['value2'] ?? '') ?: null,
            'option3' => trim($row['option3'] ?? '') ?: null,
            'value3' => trim($row['value3'] ?? '') ?: null,
            'recipe_id' => $recipe?->id,
            'recipe_multiplier' => trim((string) ($row['recipe_multiplier'] ?? '')) ?: '1',
            // selling_price is the menu price (issue #875). cost_price defaults to
            // 0 and is later auto-derived from recipe/material below when the SKU
            // is not a manual cost override.
            'selling_price' => trim((string) ($row['selling_price'] ?? '')) ?: '0',
            'cost_price' => trim((string) ($row['cost_price'] ?? '')) ?: '0',
            'is_cost_override' => $this->parseBoolean($row['is_cost_override'] ?? '', false),
            'is_active' => $this->parseBoolean($row['is_active'] ?? '', true),
        ];

        // Calculate auto cost from recipe material
        if ($recipe && $recipe->material) {
            $data['cost_price_auto'] = (string) ($recipe->material->calculated_cost * (float) $data['recipe_multiplier']);
            if (! $data['is_cost_override']) {
                $data['cost_price'] = $data['cost_price_auto'];
            }
        } else {
            $data['cost_price_auto'] = '0';
        }

        if ($action === 'update') {
            $variant = $this->existingById->get($id) ?? $this->existingBySku->get($sku);
            $payload = $this->payload($data, $variant->id, $variant);
            $this->mutations->reviseSku(new ReviseProductSkuCommand($this->context($organizationId, "sku-import:revise:{$variant->id}:{$rowNumber}", $variant), $brandId, $payload, $payload->fingerprint()));

            return 'updated';
        }

        // Create
        $data['product_id'] = $product->id;
        if (empty($data['sku'])) {
            $data['sku'] = $this->generateSku($product);
        }

        $skuId = (string) Str::uuid();
        $payload = $this->payload($data, $skuId);
        $this->mutations->createSku(new CreateProductSkuCommand($this->context($organizationId, "sku-import:create:{$skuId}:{$rowNumber}"), $product->id, $brandId, $payload, $payload->fingerprint()));
        $variant = ProductSku::whereHas('product', fn ($q) => $q->where('organization_id', $organizationId)->where('brand_id', $brandId))->findOrFail($skuId);
        $this->existingBySku->put(strtoupper($variant->sku), $variant);
        $this->existingById->put($variant->id, $variant);

        return 'created';
    }

    protected function getSampleRows(?string $organizationId = null, ?string $brandId = null): array
    {
        return [
            // Columns: id, product_sku, sku, name, option1, value1, option2, value2,
            // option3, value3, recipe_sku, recipe_multiplier, selling_price,
            // cost_price, is_cost_override, is_active. Operators set the menu price
            // (selling_price); cost_price stays 0 (auto-derived from recipe later).
            ['', 'P-001', 'P-001-S', 'Coca Cola Small', 'Size', 'Small', '', '', '', '', '', '1.0', '1500', '0', 'false', 'true'],
            ['', 'P-001', 'P-001-M', 'Coca Cola Medium', 'Size', 'Medium', '', '', '', '', '', '1.0', '2000', '0', 'false', 'true'],
        ];
    }

    private function resolveAction(string $id, string $sku, array &$errors): ?string
    {
        if (empty($id)) {
            if (empty($sku)) {
                return 'create';
            }

            $existing = $this->existingBySku->get($sku);
            if ($existing) {
                $errors[] = $existing->trashed()
                    ? "SKU {$sku} is used by a deleted record. Use a different SKU."
                    : "SKU {$sku} already exists. Include the ID column to update.";

                return null;
            }

            return 'create';
        }

        $existingId = $this->existingById->get($id);
        if (! $existingId) {
            $errors[] = "ID {$id} not found";

            return null;
        }

        if (empty($sku)) {
            $errors[] = 'sku is required';

            return null;
        }

        $existingSku = $this->existingBySku->get($sku);
        if ($existingSku && $existingSku->id !== $existingId->id) {
            $errors[] = "SKU {$sku} is used by another record";

            return null;
        }

        return 'update';
    }

    private function generateSku(Product $product): string
    {
        $counter = 1;

        do {
            $sku = sprintf('%s-V%02d', $product->sku, $counter);
            $exists = ProductSku::where('sku', $sku)->exists();
            $counter++;
        } while ($exists);

        return $sku;
    }

    /** @param array<string, mixed> $data */
    private function payload(array $data, string $skuId, ?ProductSku $current = null): ProductSkuPayload
    {
        return new ProductSkuPayload(
            $skuId,
            $data['sku'] ?? $current?->sku,
            $data['selling_price'] ?? $current?->selling_price ?? '0',
            [$current?->option_value1_id, $current?->option_value2_id, $current?->option_value3_id],
            (bool) ($data['is_active'] ?? $current?->is_active ?? true),
            $data['name'] ?? $current?->name,
            array_key_exists('recipe_id', $data) ? $data['recipe_id'] : $current?->recipe_id,
            $data['recipe_multiplier'] ?? $current?->recipe_multiplier ?? '1',
            $data['cost_price'] ?? $current?->cost_price ?? '0',
            $data['cost_price_auto'] ?? $current?->cost_price_auto ?? '0',
            (bool) ($data['is_cost_override'] ?? $current?->is_cost_override ?? false),
            $current?->inventory_mode ?? ProductSkuInventoryModeEnum::MadeToOrder,
        );
    }

    private function context(string $organizationId, string $key, ?ProductSku $current = null): MutationContext
    {
        return new MutationContext($organizationId, null, (string) Str::uuid(), $key);
    }
}
