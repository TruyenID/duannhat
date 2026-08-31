<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Category;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\Commands\CreateCategoryCommand;
use App\Services\Product\Commands\ReviseCategoryCommand;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\ValueObjects\CategoryPayload;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CategoryImporter extends CsvImporter
{
    public function __construct(private readonly ProductMutationFacade $mutations) {}

    private Collection $existingBySku;

    private Collection $existingById;

    /** @var array<array{category: Category, parent_sku: string}> */
    private array $processedCategories = [];

    protected function getRequiredColumns(): array
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

    protected function beforeImport(string $organizationId, string $brandId): void
    {
        $all = Category::withTrashed()
            ->where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->get();

        $this->existingBySku = $all->keyBy(fn ($c) => strtoupper(trim($c->sku ?? '')));
        $this->existingById = $all->keyBy('id');
        $this->processedCategories = [];
    }

    protected function afterImport(string $organizationId, string $brandId): void
    {
        foreach ($this->processedCategories as $item) {
            if (! empty($item['parent_sku'])) {
                $parent = $this->existingBySku->get($item['parent_sku']);
                if ($parent) {
                    $category = $item['category'];
                    $payload = $this->payloadFromCategory($category, parentId: $parent->id);
                    $this->mutations->reviseCategory(new ReviseCategoryCommand(
                        $this->context($organizationId, "category-import:parent:{$category->id}", $category),
                        $category->id,
                        $brandId,
                        $payload,
                        $payload->fingerprint(),
                    ));
                }
            }
        }
    }

    protected function processRow(array $row, int $rowNumber, string $organizationId, string $brandId, array &$errors): ?string
    {
        $id = trim($row['id'] ?? '');
        $sku = strtoupper(trim($row['sku'] ?? ''));
        $name = trim($row['name'] ?? '');

        if (empty($name)) {
            $errors[] = 'name is required';

            return null;
        }

        $action = $this->resolveAction($id, $sku, $errors);
        if ($action === null) {
            return null;
        }

        $slug = trim($row['slug'] ?? '');
        $parentSku = strtoupper(trim($row['parent_sku'] ?? ''));

        if ($action === 'update') {
            $category = $id ? $this->existingById->get($id) : $this->existingBySku->get($sku);
            $payload = new CategoryPayload($name, $category->sku, $slug ?: $category->slug, $row['description'] ?? null, $category->parent_id, $this->parseBoolean($row['is_active'] ?? '', true), (bool) $category->is_featured, [], $category->image_url);
            $this->mutations->reviseCategory(new ReviseCategoryCommand(
                $this->context($organizationId, "category-import:revise:{$category->id}:{$rowNumber}", $category),
                $category->id,
                $brandId,
                $payload,
                $payload->fingerprint(),
            ));
            $category->refresh();

            $this->processedCategories[] = ['category' => $category, 'parent_sku' => $parentSku];

            return 'updated';
        }

        // Create
        if (empty($sku)) {
            $sku = $this->generateSku($organizationId);
        }

        $categoryId = (string) Str::uuid();
        $payload = new CategoryPayload($name, $sku, $slug ?: Str::slug($name), $row['description'] ?? null, null, $this->parseBoolean($row['is_active'] ?? '', true));
        $this->mutations->createCategory(new CreateCategoryCommand(
            $this->context($organizationId, "category-import:create:{$categoryId}:{$rowNumber}"),
            $categoryId,
            $brandId,
            $payload,
            $payload->fingerprint(),
        ));
        $category = Category::where('organization_id', $organizationId)->where('brand_id', $brandId)->findOrFail($categoryId);

        $this->existingBySku->put($sku, $category);
        $this->existingById->put($category->id, $category);
        $this->processedCategories[] = ['category' => $category, 'parent_sku' => $parentSku];

        return 'created';
    }

    protected function getSampleRows(?string $organizationId = null, ?string $brandId = null): array
    {
        return [
            ['', 'C-001', 'Beverages', 'beverages', 'All kinds of drinks', '', 'true'],
            ['', 'C-002', 'Hot Drinks', 'hot-drinks', 'Hot beverages', 'C-001', 'true'],
            ['', 'C-003', 'Food', 'food', 'All kinds of food', '', 'true'],
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
                    ? 'sku is used by a deleted record. Use a different sku.'
                    : 'sku already exists';

                return null;
            }

            return 'create';
        }

        $existingId = $this->existingById->get($id);
        if (! $existingId) {
            $errors[] = 'id not found';

            return null;
        }

        if (empty($sku)) {
            $errors[] = 'sku is required';

            return null;
        }

        if ($existingId->sku && strtoupper(trim($existingId->sku)) !== $sku) {
            $errors[] = 'sku cannot be changed';

            return null;
        }

        return 'update';
    }

    private function generateSku(string $organizationId): string
    {
        $lastCategory = Category::where('organization_id', $organizationId)
            ->where('sku', 'like', 'C-%')
            ->orderBy('sku', 'desc')
            ->first();

        $nextNumber = $lastCategory
            ? (int) substr($lastCategory->sku, 2) + 1
            : 1;

        return sprintf('C-%03d', $nextNumber);
    }

    private function payloadFromCategory(Category $category, ?string $parentId = null): CategoryPayload
    {
        return new CategoryPayload(
            $category->name,
            $category->sku,
            $category->slug,
            $category->description,
            $parentId ?? $category->parent_id,
            (bool) $category->is_active,
            (bool) $category->is_featured,
            [],
            $category->image_url,
        );
    }

    private function context(string $organizationId, string $key, ?Category $current = null): MutationContext
    {
        return new MutationContext(
            $organizationId,
            null,
            (string) Str::uuid(),
            $key,
        );
    }
}
