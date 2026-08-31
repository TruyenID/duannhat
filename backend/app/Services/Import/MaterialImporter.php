<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Material;
use App\Services\Product\MaterialService;
use Illuminate\Support\Collection;

class MaterialImporter extends CsvImporter
{
    private Collection $existingBySku;

    private Collection $existingById;

    // plan-040 TI.2 (H9): writes go through the canonical MaterialService so an
    // import row is held to the same validation (validateYieldShape, etc.) the
    // CRUD API enforces — invalid rows are rejected, not silently written.
    //
    // #962 — không còn `= new MaterialService`: service đó giờ nhận hai hợp
    // đồng công bố, và PHP chỉ dựng giá trị mặc định khi container phản chiếu
    // tham số, nên một mặc định không dựng được sẽ vỡ lúc CHẠY chứ không lúc
    // boot. Cùng cái bẫy `RecipeImporter` đã ghi lại.
    public function __construct(
        private readonly MaterialService $service,
    ) {}

    protected function getRequiredColumns(): array
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

    protected function beforeImport(string $organizationId, string $brandId): void
    {
        $all = Material::withTrashed()
            ->where('organization_id', $organizationId)
            ->get();

        $this->existingBySku = $all->keyBy(fn ($m) => strtoupper(trim($m->sku ?? '')));
        $this->existingById = $all->keyBy('id');
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

        // plan-040 TI.7 (L8): strict numeric parsing — "1,5" / negatives are
        // rejected instead of silently coerced to 1.0 / a negative cost.
        $yieldQuantity = $this->parseStrictNumeric((string) ($row['yield_quantity'] ?? ''), 'yield_quantity', $errors, 1.0);
        $calculatedCost = $this->parseStrictNumeric((string) ($row['calculated_cost'] ?? ''), 'calculated_cost', $errors, 0.0);
        if (! empty($errors)) {
            return null;
        }

        $attributes = [
            'name' => $name,
            'description' => $row['description'] ?? null,
            'yield_quantity' => $yieldQuantity,
            'yield_unit' => $row['yield_unit'] ?? 'PORTION',
            'calculated_cost' => $calculatedCost,
            'is_active' => $this->parseBoolean($row['is_active'] ?? '', true),
        ];

        // plan-040 TI.2 (H9): route through MaterialService so the row is held
        // to the same validation the CRUD API enforces. A ValidationException
        // bubbles up to CsvImporter, which records it as a per-row error.
        if ($action === 'update') {
            $material = $id ? $this->existingById->get($id) : $this->existingBySku->get($sku);
            $attributes['sku'] = $sku ?: $material->sku;
            $material = $this->service->update($material, $attributes);

            $this->existingBySku->put(strtoupper(trim($material->sku ?? '')), $material);
            $this->existingById->put($material->id, $material);

            return 'updated';
        }

        // Create
        if (empty($sku)) {
            $sku = $this->generateSku($organizationId);
        }

        $material = $this->service->create([
            'organization_id' => $organizationId,
            'brand_id' => $brandId,
            'sku' => $sku,
            ...$attributes,
        ]);

        $this->existingBySku->put($sku, $material);
        $this->existingById->put($material->id, $material);

        return 'created';
    }

    protected function getSampleRows(?string $organizationId = null, ?string $brandId = null): array
    {
        return [
            ['', 'MA-001', 'Espresso Shot', 'Single shot of espresso', '1', 'SHOT', '15.00', 'true'],
            ['', 'MA-002', 'Steamed Milk', 'Steamed whole milk', '240', 'ML', '10.00', 'true'],
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

    private function generateSku(string $organizationId): string
    {
        // plan-040 TI.5 (M15): align the import prefix with MaterialService's
        // 'MA' SKU stem (was 'M-'). Numeric zero-padded sort is kept so
        // sequential imports don't collide or restart.
        // plan-040 L1: lock the per-org SKU rows so concurrent imports (each
        // running inside CsvImporter's transaction) serialize on the max read
        // and cannot mint duplicate material SKUs.
        $lastMaterial = Material::where('organization_id', $organizationId)
            ->where('sku', 'like', 'MA-%')
            ->orderBy('sku', 'desc')
            ->lockForUpdate()
            ->first();

        $nextNumber = $lastMaterial
            ? (int) substr($lastMaterial->sku, 3) + 1
            : 1;

        return sprintf('MA-%03d', $nextNumber);
    }
}
