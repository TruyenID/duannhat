<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\ProductType;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\Commands\CreateProductTypeCommand;
use App\Services\Product\Commands\ReviseProductTypeCommand;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\ValueObjects\ProductTypePayload;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductTypeImporter extends CsvImporter
{
    public function __construct(private readonly ProductMutationFacade $mutations) {}

    private Collection $existingByCode;

    private Collection $existingById;

    protected function getRequiredColumns(): array
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

    protected function beforeImport(string $organizationId, string $brandId): void
    {
        $all = ProductType::withTrashed()
            ->where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->get();

        $this->existingByCode = $all->keyBy(fn ($t) => strtoupper(trim($t->code ?? '')));
        $this->existingById = $all->keyBy('id');
    }

    protected function processRow(array $row, int $rowNumber, string $organizationId, string $brandId, array &$errors): ?string
    {
        $id = trim($row['id'] ?? '');
        $code = strtoupper(trim($row['code'] ?? ''));
        $name = trim($row['name'] ?? '');

        if (empty($name)) {
            $errors[] = 'name is required';

            return null;
        }

        $action = $this->resolveAction($id, $code, $errors);
        if ($action === null) {
            return null;
        }

        if ($action === 'update') {
            $productType = $id ? $this->existingById->get($id) : $this->existingByCode->get($code);
            $payload = $this->payload($row, $name, $code ?: $productType->code);
            $this->mutations->reviseProductType(new ReviseProductTypeCommand($this->context($organizationId, "product-type-import:revise:{$productType->id}:{$rowNumber}", $productType), $productType->id, $brandId, $payload, $payload->fingerprint()));

            return 'updated';
        }

        // Create
        if (empty($code)) {
            $code = $this->generateCode($organizationId, $brandId);
        }

        $productTypeId = (string) Str::uuid();
        $payload = $this->payload($row, $name, $code);
        $this->mutations->createProductType(new CreateProductTypeCommand($this->context($organizationId, "product-type-import:create:{$productTypeId}:{$rowNumber}"), $productTypeId, $brandId, $payload, $payload->fingerprint()));
        $productType = ProductType::where('organization_id', $organizationId)->where('brand_id', $brandId)->findOrFail($productTypeId);

        $this->existingByCode->put($code, $productType);
        $this->existingById->put($productType->id, $productType);

        return 'created';
    }

    protected function getSampleRows(?string $organizationId = null, ?string $brandId = null): array
    {
        return [
            ['', 'BEV', 'Beverage', 'Drinks and beverages', 'physical', 'true', 'true', 'coffee', 'true'],
            ['', 'FOOD', 'Food', 'Food items', 'physical', 'true', 'true', 'utensils', 'true'],
        ];
    }

    /**
     * Resolve whether to create, update, or fail.
     *
     * @return string|null 'create'|'update' or null on error
     */
    private function resolveAction(string $id, string $code, array &$errors): ?string
    {
        if (empty($id)) {
            if (empty($code)) {
                return 'create';
            }

            $existing = $this->existingByCode->get($code);
            if ($existing) {
                $errors[] = $existing->trashed()
                    ? 'code is used by a deleted record. Use a different code.'
                    : 'code already exists';

                return null;
            }

            return 'create';
        }

        $existingId = $this->existingById->get($id);
        if (! $existingId) {
            $errors[] = 'id not found';

            return null;
        }

        if (empty($code)) {
            $errors[] = 'code is required';

            return null;
        }

        $existingCode = $this->existingByCode->get($code);
        if ($existingCode && $existingCode->id !== $existingId->id) {
            $errors[] = 'code cannot be changed';

            return null;
        }

        return 'update';
    }

    private function generateCode(string $organizationId, string $brandId): string
    {
        $lastType = ProductType::where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->where('code', 'like', 'PT-%')
            ->orderBy('code', 'desc')
            ->first();

        $nextNumber = $lastType
            ? (int) substr($lastType->code, 3) + 1
            : 1;

        return sprintf('PT-%03d', $nextNumber);
    }

    /** @param array<string, string> $row */
    private function payload(array $row, string $name, string $code): ProductTypePayload
    {
        return new ProductTypePayload(
            $name,
            $code,
            $row['description'] ?: null,
            $row['product_form'] ?: 'physical',
            $this->parseBoolean($row['has_recipe'] ?? '', false),
            $this->parseBoolean($row['is_inventory_tracked'] ?? '', true),
            $row['icon'] ?: null,
            $this->parseBoolean($row['is_active'] ?? '', true),
        );
    }

    private function context(string $organizationId, string $key, ?ProductType $current = null): MutationContext
    {
        return new MutationContext($organizationId, null, (string) Str::uuid(), $key);
    }
}
