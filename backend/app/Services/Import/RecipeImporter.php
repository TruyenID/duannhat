<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Recipe;
use App\Services\Inventory\Contracts\MaterialDirectory;
use App\Services\Inventory\Contracts\MaterialSnapshot;
use App\Services\Product\RecipeService;
use Illuminate\Support\Collection;

class RecipeImporter extends CsvImporter
{
    private Collection $existingBySku;

    private Collection $existingById;

    /** @var array<string, MaterialSnapshot> khoá theo `sku` viết hoa */
    private array $materialsBySku = [];

    // plan-040 TI.2 (H9): writes go through the canonical RecipeService so an
    // import row is held to the same validation (validateIngredientsAndOutput,
    // cross-brand output material, etc.) the CRUD API enforces.
    // Injected, not defaulted. The `= new RecipeService` default stopped being
    // constructible once RecipeService gained required dependencies, and PHP
    // evaluates a default only when the container reflects the parameter — so
    // the breakage surfaced as an ArgumentCountError at request time rather
    // than at boot. Every sibling importer takes its collaborators the same way.
    public function __construct(
        private readonly RecipeService $service,
        // #962 — nguyên liệu thuộc Inventory; CSV chỉ cần đổi SKU sang id.
        private readonly MaterialDirectory $materials,
    ) {}

    protected function getRequiredColumns(): array
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

    protected function beforeImport(string $organizationId, string $brandId): void
    {
        $all = Recipe::withTrashed()
            ->where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->get();

        $this->existingBySku = $all->keyBy(fn ($r) => strtoupper(trim($r->sku ?? '')));
        $this->existingById = $all->keyBy('id');

        $this->materialsBySku = $this->materials->indexBySkuForBrand($organizationId, $brandId);
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

        // Validate material_sku
        $materialSku = strtoupper(trim($row['material_sku'] ?? ''));
        $material = null;
        if (! empty($materialSku)) {
            $material = $this->materialsBySku[$materialSku] ?? null;
            if (! $material) {
                $errors[] = "material_sku '{$materialSku}' not found";

                return null;
            }
        }

        $action = $this->resolveAction($id, $sku, $errors);
        if ($action === null) {
            return null;
        }

        $attributes = [
            'name' => $name,
            'description' => $row['description'] ?? null,
            'material_id' => $material?->id,
            'is_active' => $this->parseBoolean($row['is_active'] ?? '', true),
        ];

        // plan-040 TI.2 (H9): route through RecipeService so the row is held to
        // the same validation the CRUD API enforces (e.g. a cross-brand output
        // material is rejected). A ValidationException bubbles up to CsvImporter,
        // which records it as a per-row error.
        if ($action === 'update') {
            $recipe = $id ? $this->existingById->get($id) : $this->existingBySku->get($sku);
            $attributes['sku'] = $sku ?: $recipe->sku;
            $recipe = $this->service->update($recipe, $attributes);

            $this->existingBySku->put(strtoupper(trim($recipe->sku ?? '')), $recipe);
            $this->existingById->put($recipe->id, $recipe);

            return 'updated';
        }

        // Create
        if (empty($sku)) {
            $sku = $this->generateSku($organizationId, $brandId);
        }

        $recipe = $this->service->create([
            'organization_id' => $organizationId,
            'brand_id' => $brandId,
            'sku' => $sku,
            ...$attributes,
        ]);

        $this->existingBySku->put($sku, $recipe);
        $this->existingById->put($recipe->id, $recipe);

        return 'created';
    }

    protected function getSampleRows(?string $organizationId = null, ?string $brandId = null): array
    {
        return [
            ['', 'RE-001', 'Latte Recipe', 'Standard latte', 'MA-001', 'true'],
            ['', 'RE-002', 'Cappuccino Recipe', 'Classic cappuccino', 'MA-002', 'true'],
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

        $existingSku = $this->existingBySku->get($sku);
        if ($existingSku && $existingSku->id !== $existingId->id) {
            $errors[] = 'sku cannot be changed';

            return null;
        }

        return 'update';
    }

    private function generateSku(string $organizationId, string $brandId): string
    {
        // plan-040 TI.5 (M15): align the import prefix with RecipeService's
        // 'RE' SKU stem (was 'R-'). Numeric zero-padded sort is kept so
        // sequential imports don't collide or restart.
        // plan-040 L1: lock the per-org SKU rows so concurrent imports (each
        // running inside CsvImporter's transaction) serialize on the max read
        // and cannot mint duplicate recipe SKUs.
        $lastRecipe = Recipe::where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->where('sku', 'like', 'RE-%')
            ->orderBy('sku', 'desc')
            ->lockForUpdate()
            ->first();

        $nextNumber = $lastRecipe
            ? (int) substr($lastRecipe->sku, 3) + 1
            : 1;

        return sprintf('RE-%03d', $nextNumber);
    }
}
