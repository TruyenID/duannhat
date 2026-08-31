<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductType;
use App\Omnify\Enums\ProductStatusEnum;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\Commands\ImportProductsCommand;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\ProductPayloadFactory;
use App\Services\Product\ValueObjects\ProductImportPayload;
use App\Services\Product\ValueObjects\ProductImportRow;
use App\Services\Tax\Contracts\TaxTypeDirectory;
use App\Services\Tax\Contracts\TaxTypeIdentity;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class ProductImporter extends CsvImporter
{
    public function __construct(
        private readonly ProductMutationFacade $mutations,
        private readonly ProductPayloadFactory $payloads,
        // #962 — loại thuế thuộc Pricing; nhập CSV chỉ cần đổi MÃ sang id.
        private readonly TaxTypeDirectory $taxTypeDirectory,
    ) {}

    protected int $maxRows = 10000;

    private Collection $productTypes;

    private Collection $categories;

    /** @var array<string, TaxTypeIdentity> khoá theo `code` viết hoa */
    private array $taxTypes = [];

    private Collection $existingBySku;

    private Collection $existingById;

    /** @var list<ProductImportRow> */
    private array $preparedRows = [];

    /** @var array<int, 'created'|'updated'> */
    private array $preparedActions = [];

    /** @var array<string, true> */
    private array $preparedSlugs = [];

    /** @var array<string, true> */
    private array $preparedProductIds = [];

    protected function getRequiredColumns(): array
    {
        // Mirror the export header. No `sku` column on products (a product
        // has many SKUs); `slug` is the human-friendly identifier for upsert
        // matching. `is_sellable` lives on each SKU, not the product.
        return [
            'id',
            'name',
            'product_type_code',
            'category_skus',
            'description',
            'is_hidden',
            // plan-043 T2.6 — tax_type_code (empty = inherit/null).
            'tax_type_code',
            'status',
        ];
    }

    protected function beforeImport(string $organizationId, string $brandId): void
    {
        $this->preparedRows = [];
        $this->preparedActions = [];
        $this->preparedSlugs = [];
        $this->preparedProductIds = [];

        $this->productTypes = ProductType::where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->get()
            ->keyBy(fn ($pt) => strtoupper($pt->code));

        $this->categories = Category::where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->get()
            ->keyBy(fn ($c) => strtoupper(trim($c->sku ?? '')));

        // plan-043 T2.6 — tax types are brand-scoped (a code is unique per
        // brand). Only active types are assignable, mirroring the
        // ProductStore/UpdateRequest `is_active` scope; an inactive/unknown
        // code surfaces a row-level "not found" error below.
        $this->taxTypes = $this->taxTypeDirectory->activeByCodeForBrand($organizationId, $brandId);

        $products = Product::withTrashed()
            ->where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->get();

        // Index by both id and slug for upsert lookups.
        $this->existingBySku = $products->keyBy(fn ($p) => strtoupper($p->slug));
        $this->existingById = $products->keyBy('id');
    }

    protected function processRow(array $row, int $rowNumber, string $organizationId, string $brandId, array &$errors): ?string
    {
        $id = trim($row['id'] ?? '');
        // Reuse the existing $sku variable for slug-based upsert lookups —
        // simpler than renaming every reference downstream. The CSV column
        // is now derived from `name` on create (no separate input).
        $name = trim($row['name'] ?? '');
        $sku = strtoupper(Str::slug($name));

        // Validate required fields
        if (empty($name)) {
            $errors[] = 'name is required';
        }

        $typeCode = strtoupper(trim($row['product_type_code'] ?? ''));
        if (empty($typeCode)) {
            $errors[] = 'product_type_code is required';
        } elseif (! $this->productTypes->has($typeCode)) {
            $errors[] = "product_type_code '{$typeCode}' not found";
        }

        // Validate category_skus
        $categoryIds = [];
        $categorySkus = trim($row['category_skus'] ?? '');
        if (! empty($categorySkus)) {
            foreach (array_map('trim', explode(',', $categorySkus)) as $catSku) {
                if (empty($catSku)) {
                    continue;
                }
                $cat = $this->categories->get(strtoupper($catSku));
                if ($cat) {
                    $categoryIds[] = $cat->id;
                } else {
                    $errors[] = "category SKU '{$catSku}' not found";
                }
            }
        }

        // Validate status
        $status = strtolower(trim($row['status'] ?? ''));
        if (! empty($status) && ! in_array($status, ProductStatusEnum::values(), true)) {
            $errors[] = "status '{$status}' is invalid. Valid: ".implode(', ', ProductStatusEnum::values());
        }

        // plan-043 T2.6 — resolve tax_type_code → tax_type_id (empty = inherit
        // / null). Unknown or inactive code is a row-level validation error,
        // 404-safe like product_type_code. Tax types are pre-scoped to the
        // product's brand + org + is_active in beforeImport().
        $taxTypeId = null;
        $taxType = null;
        $taxTypeCode = strtoupper(trim($row['tax_type_code'] ?? ''));
        if ($taxTypeCode !== '') {
            $taxType = $this->taxTypes[$taxTypeCode] ?? null;
            if ($taxType) {
                $taxTypeId = $taxType->id;
            } else {
                $errors[] = "tax_type_code '{$taxTypeCode}' not found";
            }
        }

        if (! empty($errors)) {
            return null;
        }

        $productId = $id !== ''
            ? $id
            : (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "product-import:product-csv:{$organizationId}:{$brandId}:{$sku}");
        $action = $this->resolveAction($id, $productId, $sku, $errors);
        if ($action === null) {
            return null;
        }

        $productType = $this->productTypes->get($typeCode);

        $slug = Str::slug($name);

        $attributes = [
            'name' => $name,
            'slug' => $slug,
            'product_type_id' => $productType->id,
            'description' => trim((string) ($row['description'] ?? '')) ?: null,
            'is_hidden' => $this->parseBoolean($row['is_hidden'] ?? '', false),
            // plan-043 T2.6 — tax_type_id is null for inherit.
            'tax_type_id' => $taxTypeId,
            'status' => ! empty($status) ? $status : ProductStatusEnum::Draft->value,
        ];

        if ($action === 'update') {
            $product = $this->existingById->get($id) ?? $this->existingBySku->get($sku);
            if (isset($this->preparedProductIds[$product->id])) {
                $errors[] = "ID {$product->id} is duplicated in this import.";

                return null;
            }
            if (isset($this->preparedSlugs[$sku])) {
                $errors[] = "SKU {$sku} is duplicated in this import.";

                return null;
            }
            $attributes['slug'] = $slug ?: $product->slug;
            $attributes['category_ids'] = $categoryIds;
            // CSV always carries the description column. Keep an empty cell as
            // an explicit null so the revision factory never falls back to a
            // translated accessor while reconstructing the full aggregate.
            $attributes['description'] = trim((string) ($row['description'] ?? '')) ?: null;
            $productId = $product->id;
            // Empty-ID rows are source-owned upserts. Rebuild their canonical
            // create payload from the CSV itself so an exact file retry keeps
            // the same payload fingerprint instead of depending on hydrated
            // model state. Nested graph changes remain fail-closed in the
            // persistence boundary's nested-payload comparison.
            $payload = $id === ''
                ? $this->payloads->forCreate($attributes, false, $productId)
                : $this->payloads->forRevision($product, $attributes);
            $outcome = 'updated';
        } else {
            $attributes['category_ids'] = $categoryIds;
            $payload = $this->payloads->forCreate($attributes, false, $productId);
            $outcome = 'created';
        }

        $this->preparedRows[] = new ProductImportRow($rowNumber, $productId, $payload);
        $this->preparedActions[$rowNumber] = $outcome;
        $this->preparedSlugs[$sku] = true;
        $this->preparedProductIds[$productId] = true;

        return $outcome;
    }

    /**
     * Product CSV is a transport adapter: it validates and translates rows,
     * then delegates all aggregate writes to the typed bulk command.
     *
     * @param  array<int, array<string, string>>  $rows
     */
    public function importRows(array $rows, string $organizationId, string $brandId, bool $dryRun = false): ImportResult
    {
        if (count($rows) > $this->maxRows) {
            return new ImportResult(errorCount: 1, errors: [['row' => 0, 'errors' => ["CSV file exceeds maximum allowed rows ({$this->maxRows})"]]]);
        }
        if ($rows === []) {
            return new ImportResult(errorCount: 1, errors: [['row' => 0, 'errors' => ['CSV file is empty']]]);
        }
        $headerValidation = $this->validateHeaders(array_keys($rows[0]));
        if ($headerValidation !== null) {
            return new ImportResult(errorCount: 1, errors: [['row' => 0, 'errors' => [$headerValidation]]]);
        }

        $this->beforeImport($organizationId, $brandId);
        $errorCount = 0;
        $allErrors = [];
        $currentRow = 0;
        try {
            foreach ($rows as $index => $row) {
                $currentRow = $index + 2;
                $rowErrors = [];
                try {
                    $this->processRow($row, $currentRow, $organizationId, $brandId, $rowErrors);
                    if ($rowErrors !== []) {
                        $errorCount++;
                        $allErrors[] = ['row' => $currentRow, 'errors' => $rowErrors];
                    }
                } catch (ValidationException $exception) {
                    $errorCount++;
                    $allErrors[] = ['row' => $currentRow, 'errors' => collect($exception->errors())->flatten()->all()];
                }
            }

            $successCount = 0;
            $createdCount = 0;
            $updatedCount = 0;
            if ($this->preparedRows !== []) {
                $payload = new ProductImportPayload($this->preparedRows);
                $correlationId = (string) Str::uuid();
                $context = new MutationContext(
                    $organizationId,
                    auth()->id(),
                    $correlationId,
                    "product-import:bulk:{$brandId}:{$payload->fingerprint()}",
                );
                $result = $this->mutations->import(new ImportProductsCommand(
                    $context,
                    $brandId,
                    'product-csv',
                    $payload,
                    $payload->fingerprint(),
                    $dryRun,
                ));

                foreach ($result->rows as $rowResult) {
                    if ($rowResult->imported) {
                        $successCount++;
                        if (($this->preparedActions[$rowResult->rowNumber] ?? null) === 'created') {
                            $createdCount++;
                        } else {
                            $updatedCount++;
                        }
                    } else {
                        $errorCount++;
                        $allErrors[] = ['row' => $rowResult->rowNumber, 'errors' => [$rowResult->errorCode]];
                    }
                }
            }

            $this->afterImport($organizationId, $brandId);
        } catch (\Throwable $exception) {
            return new ImportResult(errorCount: 1, errors: [['row' => $currentRow, 'errors' => ['Import aborted (no rows committed): '.$exception->getMessage()]]]);
        }

        return new ImportResult($successCount, $errorCount, $createdCount, $updatedCount, $allErrors);
    }

    /**
     * Build sample rows from REAL codes in the current org so the template
     * is copy-paste-import-able. Falls back to header-only when called
     * without context (e.g. CLI / tests with no brand).
     *
     * @return array<int, array<int, string>>
     */
    protected function getSampleRows(?string $organizationId = null, ?string $brandId = null): array
    {
        if (! $organizationId) {
            return [];
        }

        $typeCode = ProductType::where('organization_id', $organizationId)
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->where('is_active', true)
            ->orderBy('created_at')
            ->value('code');

        if (! $typeCode) {
            return [];
        }

        $categorySkus = Category::where('organization_id', $organizationId)
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->whereNotNull('sku')
            ->orderBy('created_at')
            ->limit(2)
            ->pluck('sku')
            ->all();

        $catColumn = implode(',', $categorySkus);

        // plan-043 T2.6 — a real active tax_type_code makes the sample rows
        // copy-paste-import-able; empty means "inherit brand default". Fall
        // back to '' (inherit) when the brand has no active tax type yet.
        $taxTypeCode = $this->taxTypeDirectory->firstActiveCodeForBrand($organizationId, $brandId) ?? '';

        // Two illustrative rows: create + create-with-categories. User edits
        // `name` and saves — every other column is already valid for the brand.
        // Column order: id, name, product_type_code, category_skus, description,
        //               is_hidden, tax_type_code, status.
        return [
            ['', 'Sample product (rename me)', $typeCode, $catColumn, 'Edit before import', 'false', $taxTypeCode, 'false', 'draft'],
            ['', 'Another sample', $typeCode, '', 'Optional: leave category blank', 'false', '', 'false', 'draft'],
        ];
    }

    private function resolveAction(string $id, string $productId, string $sku, array &$errors): ?string
    {
        if (empty($id)) {
            if (empty($sku)) {
                return 'create';
            }

            $existing = $this->existingBySku->get($sku);
            if ($existing) {
                if (! $existing->trashed() && $existing->id === $productId) {
                    return 'update';
                }
                $errors[] = $existing->trashed()
                    ? "SKU {$sku} is used by a deleted record. Use a different SKU."
                    : "SKU {$sku} already exists. Include the ID column to update.";

                return null;
            }
            if (isset($this->preparedSlugs[$sku])) {
                $errors[] = "SKU {$sku} is duplicated in this import.";

                return null;
            }

            return 'create';
        }

        $existingId = $this->existingById->get($id);
        if (! $existingId) {
            $errors[] = "ID {$id} not found";

            return null;
        }
        if ($existingId->trashed()) {
            $errors[] = "ID {$id} belongs to a deleted record";

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
}
