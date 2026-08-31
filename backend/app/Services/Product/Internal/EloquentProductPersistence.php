<?php

namespace App\Services\Product\Internal;

use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\ProductIdempotencyConflict;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\ToppingGroup;
use App\Models\User;
use App\Models\VariantUnit;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Omnify\Enums\ProductStatusEnum;
use App\Omnify\Enums\ReviewSentimentEnum;
use App\Services\DomainMutation\MutationResult;
use App\Services\Iam\UserWorkspaceAccess;
use App\Services\Product\CategoryService;
use App\Services\Product\Commands\AssignCategoryTaxTypeCommand;
use App\Services\Product\Commands\CategoryLifecycleCommand;
use App\Services\Product\Commands\CreateCategoryCommand;
use App\Services\Product\Commands\CreateProductCommand;
use App\Services\Product\Commands\CreateProductOptionCommand;
use App\Services\Product\Commands\CreateProductOptionValueCommand;
use App\Services\Product\Commands\CreateProductSkuCommand;
use App\Services\Product\Commands\CreateProductTypeCommand;
use App\Services\Product\Commands\CreateVariantUnitCommand;
use App\Services\Product\Commands\ExpandProductOptionCommand;
use App\Services\Product\Commands\GenerateProductSkuCombinationsCommand;
use App\Services\Product\Commands\ImportProductsCommand;
use App\Services\Product\Commands\ProductLifecycleCommand;
use App\Services\Product\Commands\ProductOptionLifecycleCommand;
use App\Services\Product\Commands\ProductOptionValueLifecycleCommand;
use App\Services\Product\Commands\ProductSkuLifecycleCommand;
use App\Services\Product\Commands\ProductTypeLifecycleCommand;
use App\Services\Product\Commands\ReviseCategoryCommand;
use App\Services\Product\Commands\ReviseProductCommand;
use App\Services\Product\Commands\ReviseProductOptionCommand;
use App\Services\Product\Commands\ReviseProductOptionValueCommand;
use App\Services\Product\Commands\ReviseProductSkuCommand;
use App\Services\Product\Commands\ReviseProductTypeCommand;
use App\Services\Product\Commands\ReviseVariantUnitCommand;
use App\Services\Product\Commands\SyncProductOptionValuesCommand;
use App\Services\Product\Commands\VariantUnitLifecycleCommand;
use App\Services\Product\Contracts\ProductCatalogProjectionPort;
use App\Services\Product\Contracts\ProductPersistencePort;
use App\Services\Product\Enums\CategoryLifecycleAction;
use App\Services\Product\Enums\ProductLifecycleAction;
use App\Services\Product\Enums\ProductOptionLifecycleAction;
use App\Services\Product\Enums\ProductOptionValueLifecycleAction;
use App\Services\Product\Enums\ProductSkuLifecycleAction;
use App\Services\Product\Enums\ProductTypeLifecycleAction;
use App\Services\Product\Enums\VariantUnitLifecycleAction;
use App\Services\Product\ProductOptionService;
use App\Services\Product\ProductOptionValueService;
use App\Services\Product\ProductPayloadFactory;
use App\Services\Product\ProductSkuService;
use App\Services\Product\ProductTypeService;
use App\Services\Product\Results\CategoryTaxTypeAssignmentResult;
use App\Services\Product\Results\ProductImportResult;
use App\Services\Product\Results\ProductImportRowResult;
use App\Services\Product\Results\ProductOptionExpansionResult;
use App\Services\Product\Results\ProductOptionSyncResult;
use App\Services\Product\Results\ProductSkuGenerationResult;
use App\Services\Product\ValueObjects\CatalogSkuProjection;
use App\Services\Product\ValueObjects\CategoryPayload;
use App\Services\Product\ValueObjects\ProductOptionPayload;
use App\Services\Product\ValueObjects\ProductOptionValuePayload;
use App\Services\Product\ValueObjects\ProductPayload;
use App\Services\Product\ValueObjects\ProductSkuPayload;
use App\Services\Product\ValueObjects\ProductToppingGroupPayload;
use App\Services\Product\ValueObjects\ProductTypePayload;
use App\Services\Product\ValueObjects\VariantUnitPayload;
use App\Services\Tax\Contracts\TaxTypeDirectory;
use App\Services\Topping\ProductToppingGroupService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EloquentProductPersistence implements ProductPersistencePort
{
    public function __construct(
        private readonly ProductSkuService $skuService,
        private readonly ProductOptionService $optionService,
        private readonly ProductOptionValueService $optionValueService,
        private readonly ProductCatalogProjectionPort $catalogProjection,
        private readonly CategoryService $categoryService,
        private readonly ProductTypeService $productTypeService,
        private readonly UserWorkspaceAccess $workspaceAccess,
        private readonly ProductToppingGroupService $toppings,
        private readonly ProductPayloadFactory $payloadFactory,
        // #962 — loại thuế thuộc Pricing; Catalog chỉ hỏi "gán được không".
        private readonly TaxTypeDirectory $taxTypes,
    ) {}

    public function insertSku(CreateProductSkuCommand $command): MutationResult
    {
        return DB::transaction(function () use ($command): MutationResult {
            $product = $this->scopedProduct($command->productId, $command->context->organizationId, $command->brandId);
            $existing = ProductSku::query()
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->find($command->payload->skuId);

            if ($existing !== null) {
                $this->assertSkuReplayMatches($existing, $command->payload);

                return new MutationResult($existing->id, null, false);
            }

            $sku = $this->skuService->create(['id' => $command->payload->skuId, 'product_id' => $product->id, ...$this->skuPayloadData($command->payload)]);

            return new MutationResult($sku->id, null, true);
        });
    }

    public function applySkuRevision(ReviseProductSkuCommand $command): MutationResult
    {
        $sku = $this->scopedSku($command->payload->skuId, $command->context->organizationId, $command->brandId);
        $currentOptionIds = [$sku->option_value1_id, $sku->option_value2_id, $sku->option_value3_id];
        if ($command->payload->optionValueIds !== $currentOptionIds) {
            throw ValidationException::withMessages(['option_value_ids' => ['An existing SKU option combination is immutable.']]);
        }
        DB::transaction(function () use ($sku, $command): void {
            $this->skuService->update($sku, $this->skuPayloadData($command->payload));
            if ($command->payload->clearedLocales !== []) {
                $sku->translations()->whereIn('locale', $command->payload->clearedLocales)->delete();
            }
        });

        return new MutationResult($sku->id, null, true);
    }

    public function markSkuArchived(ProductSkuLifecycleCommand $command): MutationResult
    {
        $command->assertAction(ProductSkuLifecycleAction::Archive);
        $sku = $this->scopedSku($command->skuId, $command->context->organizationId, $command->brandId);
        $this->skuService->delete($sku);

        return new MutationResult($sku->id, null, true);
    }

    public function markSkuRestored(ProductSkuLifecycleCommand $command): MutationResult
    {
        $command->assertAction(ProductSkuLifecycleAction::Restore);
        $sku = ProductSku::withTrashed()->whereHas('product', fn ($q) => $q->where('organization_id', $command->context->organizationId)->where('brand_id', $command->brandId))->findOrFail($command->skuId);
        $this->skuService->restore($sku);

        return new MutationResult($sku->id, null, true);
    }

    public function toggleSkuActiveStatus(ProductSkuLifecycleCommand $command): MutationResult
    {
        $command->assertAction(ProductSkuLifecycleAction::ToggleStatus);
        $sku = $this->scopedSku($command->skuId, $command->context->organizationId, $command->brandId);
        $this->skuService->toggleStatus($sku);

        return new MutationResult($sku->id, null, true);
    }

    public function generateSkuCombinations(GenerateProductSkuCombinationsCommand $command): ProductSkuGenerationResult
    {
        return DB::transaction(function () use ($command): ProductSkuGenerationResult {
            $product = $this->scopedProduct($command->productId, $command->context->organizationId, $command->brandId);
            $created = $this->skuService->generateMissingCombinations($product);
            if ($created->isNotEmpty()) {
                $this->catalogProjection->syncNewSkusToMenuBranches($product->id, $this->catalogSkuProjections($created));
            }

            return new ProductSkuGenerationResult($created->pluck('id')->all());
        });
    }

    public function insertProductType(CreateProductTypeCommand $command): MutationResult
    {
        return DB::transaction(function () use ($command): MutationResult {
            $data = $this->productTypePayloadData($command->payload);
            $existing = ProductType::withTrashed()
                ->where('organization_id', $command->context->organizationId)
                ->where('brand_id', $command->brandId)
                ->lockForUpdate()
                ->find($command->productTypeId);
            if ($existing !== null) {
                $this->assertScalarReplayMatches($existing, $data);

                return new MutationResult($existing->id, null, false);
            }

            $productType = $this->productTypeService->create([
                'id' => $command->productTypeId,
                'organization_id' => $command->context->organizationId,
                'brand_id' => $command->brandId,
                ...$data,
            ]);

            return new MutationResult($productType->id, null, true);
        });
    }

    public function applyProductTypeRevision(ReviseProductTypeCommand $command): MutationResult
    {
        $productType = $this->scopedProductType($command->productTypeId, $command->context->organizationId, $command->brandId);
        DB::transaction(function () use ($productType, $command): void {
            $this->productTypeService->update($productType, $this->productTypePayloadData($command->payload));
            if ($command->payload->clearedLocales !== []) {
                $productType->translations()->whereIn('locale', $command->payload->clearedLocales)->delete();
                $productType->unsetRelation('translations');
            }
        });

        return new MutationResult($productType->id, null, true);
    }

    public function markProductTypeArchived(ProductTypeLifecycleCommand $command): MutationResult
    {
        $command->assertAction(ProductTypeLifecycleAction::Archive);
        $productType = $this->scopedProductType($command->productTypeId, $command->context->organizationId, $command->brandId);
        $this->productTypeService->delete($productType);

        return new MutationResult($productType->id, null, true);
    }

    public function markProductTypeRestored(ProductTypeLifecycleCommand $command): MutationResult
    {
        $command->assertAction(ProductTypeLifecycleAction::Restore);
        $productType = ProductType::withTrashed()->where('organization_id', $command->context->organizationId)->where('brand_id', $command->brandId)->findOrFail($command->productTypeId);
        $this->productTypeService->restore($productType);

        return new MutationResult($productType->id, null, true);
    }

    public function toggleProductTypeActiveStatus(ProductTypeLifecycleCommand $command): MutationResult
    {
        $command->assertAction(ProductTypeLifecycleAction::ToggleStatus);
        $productType = $this->scopedProductType($command->productTypeId, $command->context->organizationId, $command->brandId);
        $this->productTypeService->toggleStatus($productType);

        return new MutationResult($productType->id, null, true);
    }

    public function insertCategory(CreateCategoryCommand $command): MutationResult
    {
        return DB::transaction(function () use ($command): MutationResult {
            $data = $this->categoryPayloadData($command->payload);
            $existing = Category::withTrashed()
                ->where('organization_id', $command->context->organizationId)
                ->where('brand_id', $command->brandId)
                ->lockForUpdate()
                ->find($command->categoryId);
            if ($existing !== null) {
                $this->assertScalarReplayMatches($existing, $data);

                return new MutationResult($existing->id, null, false);
            }

            $category = $this->categoryService->create([
                'id' => $command->categoryId,
                'organization_id' => $command->context->organizationId,
                'brand_id' => $command->brandId,
                ...$data,
            ]);

            return new MutationResult($category->id, null, true);
        });
    }

    public function applyCategoryRevision(ReviseCategoryCommand $command): MutationResult
    {
        $category = $this->scopedCategory($command->categoryId, $command->context->organizationId, $command->brandId);
        DB::transaction(function () use ($category, $command): void {
            $this->categoryService->update($category, $this->categoryPayloadData($command->payload));
            if ($command->payload->clearedLocales !== []) {
                $category->translations()->whereIn('locale', $command->payload->clearedLocales)->delete();
                $category->unsetRelation('translations');
            }
        });

        return new MutationResult($category->id, null, true);
    }

    public function markCategoryArchived(CategoryLifecycleCommand $command): MutationResult
    {
        $command->assertAction(CategoryLifecycleAction::Archive);
        $category = $this->scopedCategory($command->categoryId, $command->context->organizationId, $command->brandId);
        $this->categoryService->delete($category);

        return new MutationResult($category->id, null, true);
    }

    public function markCategoryRestored(CategoryLifecycleCommand $command): MutationResult
    {
        $command->assertAction(CategoryLifecycleAction::Restore);
        $category = Category::withTrashed()->where('organization_id', $command->context->organizationId)->where('brand_id', $command->brandId)->findOrFail($command->categoryId);
        $this->categoryService->restore($category);

        return new MutationResult($category->id, null, true);
    }

    /**
     * #1074 — bulk-assign a tax type to every product in a category.
     *
     * Single-rate world (#1099): the configured tax type governs the product —
     * alcohol included, by operator decision. This is how an operator fixes the
     * "beer inherited REDUCED" class of catalog bug in one action: assign
     * STANDARD to the alcohol category. `taxTypeId = null` clears the
     * per-product override so the products fall back to inheritance.
     */
    public function assignCategoryTaxType(AssignCategoryTaxTypeCommand $command): CategoryTaxTypeAssignmentResult
    {
        $category = $this->scopedCategory($command->categoryId, $command->context->organizationId, $command->brandId);

        // Fail loud on a cross-brand (or cross-org) tax type: silently
        // stamping another brand's type would corrupt that brand's
        // reporting buckets.
        if ($command->taxTypeId !== null && ! $this->taxTypes->belongsToBrand(
            $command->taxTypeId,
            (string) $category->organization_id,
            (string) $category->brand_id,
        )) {
            throw ValidationException::withMessages([
                'tax_type_id' => [__('The tax type does not belong to this brand.')],
            ]);
        }

        $updated = $category->products()
            ->where('products.organization_id', $category->organization_id)
            ->where('products.brand_id', $category->brand_id)
            ->update(['products.tax_type_id' => $command->taxTypeId]);

        return new CategoryTaxTypeAssignmentResult($category->id, $command->taxTypeId, (int) $updated);
    }

    public function insertProduct(CreateProductCommand $command): MutationResult
    {
        $this->assertActorContext($command->context->actorId, $command->context->organizationId);
        $this->assertProductPayloadReferences($command->payload, $command->context->organizationId, $command->brandId);

        try {
            return DB::transaction(function () use ($command): MutationResult {
                $existing = Product::withTrashed()
                    ->where('organization_id', $command->context->organizationId)
                    ->where('brand_id', $command->brandId)
                    ->lockForUpdate()
                    ->find($command->productId);
                if ($existing !== null) {
                    $this->assertProductReplayMatches($existing, $command->payload);

                    return $this->mutationResult($existing, false);
                }

                $product = Product::withAuditActor($command->context->actorId, fn () => $this->create(['id' => $command->productId, 'organization_id' => $command->context->organizationId, 'brand_id' => $command->brandId, 'created_by_id' => $command->context->actorId, ...$this->productPayloadData($command->payload)]));

                return $this->mutationResult($product, true);
            });
        } catch (QueryException $exception) {
            // A lock cannot cover an absent row. The deterministic aggregate
            // primary key is the race arbiter: after the losing transaction
            // rolls back, load the winner and validate it as a normal replay.
            $winner = Product::withTrashed()
                ->where('organization_id', $command->context->organizationId)
                ->where('brand_id', $command->brandId)
                ->find($command->productId);
            if (! in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true) || $winner === null) {
                throw $exception;
            }

            $this->assertProductReplayMatches($winner, $command->payload);

            return $this->mutationResult($winner, false);
        }
    }

    public function applyRevision(ReviseProductCommand $command): MutationResult
    {
        $this->assertActorContext($command->context->actorId, $command->context->organizationId);
        $this->assertProductPayloadReferences($command->payload, $command->context->organizationId, $command->brandId);
        $product = $this->scopedProduct($command->productId, $command->context->organizationId, $command->brandId);
        Product::withAuditActor($command->context->actorId, fn () => $this->update($product, $this->productPayloadData($command->payload)));

        return $this->mutationResult($product, true);
    }

    public function importCatalog(ImportProductsCommand $command): ProductImportResult
    {
        $this->assertActorContext($command->context->actorId, $command->context->organizationId);
        $this->assertProductBrandContext($command->context->organizationId, $command->brandId);

        DB::beginTransaction();
        try {
            $results = [];
            foreach ($command->payload->rows as $row) {
                try {
                    DB::transaction(function () use ($command, $row): void {
                        $this->assertProductPayloadReferences($row->payload, $command->context->organizationId, $command->brandId);
                        Product::withAuditActor($command->context->actorId, function () use ($command, $row): void {
                            $existing = Product::where('organization_id', $command->context->organizationId)
                                ->where('brand_id', $command->brandId)
                                ->lockForUpdate()
                                ->find($row->productId);
                            if ($existing) {
                                $this->assertProductNestedPayloadMatches($existing, $row->payload);
                                $this->update($existing, $this->productPayloadData($row->payload));
                            } else {
                                $this->create(['id' => $row->productId, 'organization_id' => $command->context->organizationId, 'brand_id' => $command->brandId, 'created_by_id' => $command->context->actorId, ...$this->productPayloadData($row->payload)]);
                            }
                        });
                    });
                    $results[] = new ProductImportRowResult($row->rowNumber, $row->productId, true, null);
                } catch (ValidationException $exception) {
                    $field = (string) array_key_first($exception->errors());
                    $errorCode = strtoupper((string) preg_replace('/[^a-zA-Z0-9]+/', '_', trim($field, '.'))).'_INVALID';
                    $results[] = new ProductImportRowResult($row->rowNumber, null, false, $errorCode);
                }
            }

            $result = new ProductImportResult($results);
            $command->dryRun ? DB::rollBack() : DB::commit();

            return $result;
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    public function markSubmitted(ProductLifecycleCommand $command): MutationResult
    {
        $command->assertAction(ProductLifecycleAction::Submit);
        $this->assertActorContext($command->context->actorId, $command->context->organizationId);
        $product = Product::withAuditActor($command->context->actorId, fn () => $this->submitForApproval($this->scopedProduct($command->productId, $command->context->organizationId, $command->brandId)));

        return $this->mutationResult($product, true);
    }

    public function markApproved(ProductLifecycleCommand $command): MutationResult
    {
        $command->assertAction(ProductLifecycleAction::Approve);
        $product = $this->scopedProduct($command->productId, $command->context->organizationId, $command->brandId);
        $actor = User::findOrFail($command->context->actorId);
        $this->assertActorOrganization($actor, $command->context->organizationId);

        return $this->mutationResult(Product::withAuditActor($command->context->actorId, fn () => $this->approve($product, $actor)), true);
    }

    public function markRejected(ProductLifecycleCommand $command): MutationResult
    {
        $command->assertAction(ProductLifecycleAction::Reject);
        $product = $this->scopedProduct($command->productId, $command->context->organizationId, $command->brandId);
        $actor = User::findOrFail($command->context->actorId);
        $this->assertActorOrganization($actor, $command->context->organizationId);

        return $this->mutationResult(Product::withAuditActor($command->context->actorId, fn () => $this->reject($product, $actor, $command->reason ?? throw new \LogicException('Validated rejection reason is missing.'))), true);
    }

    public function markActive(ProductLifecycleCommand $command): MutationResult
    {
        $command->assertAction(ProductLifecycleAction::Activate);

        $this->assertActorContext($command->context->actorId, $command->context->organizationId);

        return $this->mutationResult(Product::withAuditActor($command->context->actorId, fn () => $this->activate($this->scopedProduct($command->productId, $command->context->organizationId, $command->brandId))), true);
    }

    public function markInactive(ProductLifecycleCommand $command): MutationResult
    {
        $command->assertAction(ProductLifecycleAction::Deactivate);

        $this->assertActorContext($command->context->actorId, $command->context->organizationId);

        return $this->mutationResult(Product::withAuditActor($command->context->actorId, fn () => $this->deactivate($this->scopedProduct($command->productId, $command->context->organizationId, $command->brandId))), true);
    }

    public function markArchived(ProductLifecycleCommand $command): MutationResult
    {
        $command->assertAction(ProductLifecycleAction::Archive);
        $this->assertActorContext($command->context->actorId, $command->context->organizationId);
        $product = $this->scopedProduct($command->productId, $command->context->organizationId, $command->brandId);
        Product::withAuditActor($command->context->actorId, fn () => $this->delete($product));

        return $this->mutationResult($product, true);
    }

    public function markRestored(ProductLifecycleCommand $command): MutationResult
    {
        $command->assertAction(ProductLifecycleAction::Restore);
        $this->assertActorContext($command->context->actorId, $command->context->organizationId);
        $product = Product::withTrashed()->where('organization_id', $command->context->organizationId)->where('brand_id', $command->brandId)->findOrFail($command->productId);

        return $this->mutationResult(Product::withAuditActor($command->context->actorId, fn () => $this->restore($product)), true);
    }

    public function insertVariantUnit(CreateVariantUnitCommand $command): MutationResult
    {
        $unit = DB::transaction(function () use ($command): VariantUnit {
            $sku = ProductSku::whereHas('product', fn ($query) => $query->where('organization_id', $command->context->organizationId)->where('brand_id', $command->brandId))->lockForUpdate()->findOrFail($command->productSkuId);
            $siblings = VariantUnit::where('product_sku_id', $sku->id)->lockForUpdate()->get();

            // #1744 — lượt gửi lại. Tra TRƯỚC khi hạ cờ `is_base` của anh em:
            // lượt hai của cùng một lệnh không được phép đổi trạng thái gì cả,
            // mà `update(['is_base' => false])` ở dưới thì có.
            $replay = $siblings->firstWhere('id', $command->unitId);
            if ($replay !== null) {
                // `is_base` KHÔNG so được: lệnh tạo ghi đè
                // `$siblings->isEmpty() || $payload->base`, nên đơn vị đầu tiên
                // của một SKU luôn thành cơ sở dù payload nói `false`. So nó là
                // báo xung đột cho đúng lệnh vừa chạy xong.
                $this->assertScalarReplayMatches($replay, $this->variantUnitPayloadData($command->payload), ['is_base']);

                return $replay;
            }

            if ($command->payload->base) {
                VariantUnit::where('product_sku_id', $sku->id)->where('is_base', true)->update(['is_base' => false]);
            }
            $unit = new VariantUnit;
            $unit->forceFill([
                'id' => $command->unitId,
                'product_sku_id' => $sku->id,
                ...$this->variantUnitPayloadData($command->payload),
                'is_base' => $siblings->isEmpty() || $command->payload->base,
            ])->save();

            return $unit;
        });

        return new MutationResult($unit->id, null, $unit->wasRecentlyCreated);
    }

    public function applyVariantUnitRevision(ReviseVariantUnitCommand $command): MutationResult
    {
        $unit = VariantUnit::whereHas('productSku.product', fn ($query) => $query->where('organization_id', $command->context->organizationId)->where('brand_id', $command->brandId))->findOrFail($command->unitId);
        DB::transaction(function () use ($unit, $command): void {
            ProductSku::whereKey($unit->product_sku_id)->lockForUpdate()->firstOrFail();
            VariantUnit::where('product_sku_id', $unit->product_sku_id)->lockForUpdate()->get();
            $lockedUnit = VariantUnit::whereKey($unit->id)->firstOrFail();
            if ($lockedUnit->is_base && ! $command->payload->base) {
                throw ValidationException::withMessages(['is_base' => ['Set another unit as base before unsetting the current base unit.']]);
            }
            if ($command->payload->base) {
                VariantUnit::where('product_sku_id', $lockedUnit->product_sku_id)->where('id', '!=', $lockedUnit->id)->where('is_base', true)->update(['is_base' => false]);
            }
            $this->updateVariantUnit($lockedUnit, $this->variantUnitPayloadData($command->payload));
        });

        return new MutationResult($unit->id, null, true);
    }

    public function removeVariantUnit(VariantUnitLifecycleCommand $command): MutationResult
    {
        $command->assertAction(VariantUnitLifecycleAction::Remove);
        $unit = VariantUnit::whereHas('productSku.product', fn ($query) => $query->where('organization_id', $command->context->organizationId)->where('brand_id', $command->brandId))->findOrFail($command->unitId);
        DB::transaction(function () use ($unit): void {
            ProductSku::whereKey($unit->product_sku_id)->lockForUpdate()->firstOrFail();
            VariantUnit::where('product_sku_id', $unit->product_sku_id)->lockForUpdate()->get();
            $lockedUnit = VariantUnit::whereKey($unit->id)->firstOrFail();
            if ($lockedUnit->is_base) {
                throw ValidationException::withMessages(['unit' => ['The base unit cannot be deleted. Set another unit as base first.']]);
            }
            $this->deleteVariantUnit($lockedUnit);
        });

        return new MutationResult($unit->id, null, true);
    }

    public function makeBaseVariantUnit(VariantUnitLifecycleCommand $command): MutationResult
    {
        $command->assertAction(VariantUnitLifecycleAction::MakeBase);
        $unit = VariantUnit::whereHas('productSku.product', fn ($query) => $query->where('organization_id', $command->context->organizationId)->where('brand_id', $command->brandId))->findOrFail($command->unitId);
        $this->setBaseVariantUnit($unit);

        return new MutationResult($unit->id, null, true);
    }

    // =========================================================================
    //  Create
    // =========================================================================

    private function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            // Derive top-level mirror columns (name, description) from locale
            // priority (ja→en→vi) when the caller sent only nested locale keys.
            // Mirrors CategoryService::deriveTranslatableTopLevels(). Must run
            // BEFORE slug generation which depends on $data['name'].
            foreach (['name', 'description'] as $field) {
                $top = $data[$field] ?? null;
                if (is_string($top) && trim($top) !== '') {
                    continue;
                }
                foreach (['ja', 'en', 'vi'] as $locale) {
                    $val = $data[$locale][$field] ?? null;
                    if (is_string($val) && trim($val) !== '') {
                        $data[$field] = $val;
                        break;
                    }
                }
            }

            // Auto-derive slug from name if the client didn't supply one,
            // disambiguating with a numeric suffix when it collides within
            // the same organization. Product has no `sku` column — slug is
            // the user-facing unique identifier within a brand.
            if (empty($data['slug']) && ! empty($data['name'])) {
                $data['slug'] = $this->generateUniqueSlug(
                    Str::slug($data['name']),
                    $data['organization_id'] ?? null,
                );
            }

            if (! isset($data['status'])) {
                $data['status'] = ProductStatusEnum::Draft->value;
            }

            $categoryIds = $data['category_ids'] ?? [];
            $optionsData = $data['options'] ?? [];
            $skusData = $data['skus'] ?? [];
            $galleryFileIds = $data['gallery_file_ids'] ?? [];
            $thumbnailFileId = $data['thumbnail_file_id'] ?? null;
            // `cleared_locales` and `topping_groups` are payload-only keys with
            // no matching products column — update() already strips both, but
            // create() did not, so `$product->fill($data)` carried them into the
            // INSERT and died on "Unknown column 'cleared_locales'".
            //
            // `topping_groups` is captured first because syncToppings() below
            // still needs it; `cleared_locales` is simply dropped, since a
            // product being created has no existing translations to clear.
            $toppingGroups = $data['topping_groups'] ?? [];
            unset(
                $data['category_ids'],
                $data['options'],
                $data['skus'],
                $data['gallery_file_ids'],
                $data['thumbnail_file_id'],
                $data['cleared_locales'],
                $data['topping_groups'],
            );

            $product = new Product;
            $product->fill($data);
            $product->forceFill(array_filter([
                'id' => $data['id'] ?? null,
                'created_by_id' => $data['created_by_id'] ?? null,
            ], static fn (mixed $value): bool => $value !== null));
            $product->save();

            if (! empty($categoryIds)) {
                $product->categories()->attach($categoryIds);
            }

            // Attach staged gallery images (temp → permanent). Order of the
            // array = sort_order. syncFiles() handles make-permanent + reorder
            // in one go; idempotent if re-posted with the same IDs.
            if (! empty($galleryFileIds)) {
                $product->syncFiles($galleryFileIds, 'gallery');
            }
            $product->syncFiles($thumbnailFileId === null ? [] : [$thumbnailFileId], 'thumbnail');

            if (! empty($optionsData)) {
                // Shopify-style nested create: options + values + indexed SKUs.
                // The SKUs reference option values via positional `value_indices`
                // (one entry per option, in input order) which the helper
                // resolves to `option_value{N}_id` columns after the values
                // have been persisted.
                $this->createOptionsValuesAndIndexedSkus($product, $optionsData, $skusData);
            } elseif (! empty($skusData)) {
                // Quick-create / API flow: SKUs without options. Each SKU is
                // self-contained and uses the existing field shape. Strip
                // `value_indices` since it is meaningless here.
                foreach ($skusData as $skuData) {
                    unset($skuData['value_indices']);
                    $this->createSku($product, $skuData);
                }
            }

            $this->syncToppings($product, $toppingGroups);

            return $product->load([
                'productType', 'categories', 'options.values',
                'skus.units', 'skus.optionValue1.option',
                'skus.optionValue2.option', 'skus.optionValue3.option',
                'gallery',
            ])->loadCount('skus');
        });
    }

    // =========================================================================
    //  Update
    // =========================================================================

    private function update(Product $product, array $data): Product
    {
        // #124 — the generic update path must not let `status` bypass the
        // approval workflow. Lifecycle states (active/inactive) are only
        // reachable once the product is approved, and only through
        // activate()/deactivate(); a draft/pending/rejected product may not
        // jump straight to active/inactive via a plain PUT.
        if (array_key_exists('status', $data)) {
            $this->assertUpdatableStatus($product, $data['status']);
        }

        return DB::transaction(function () use ($product, $data) {
            $categoryIds = $data['category_ids'] ?? null;
            $galleryFileIds = $data['gallery_file_ids'] ?? null;
            $thumbnailFileId = $data['thumbnail_file_id'] ?? null;
            $clearedLocales = $data['cleared_locales'] ?? [];
            $toppingGroups = $data['topping_groups'] ?? null;
            unset($data['category_ids'], $data['gallery_file_ids'], $data['thumbnail_file_id'], $data['cleared_locales'], $data['options'], $data['skus'], $data['topping_groups']);

            $product->update($data);

            if ($clearedLocales !== []) {
                $product->translations()->whereIn('locale', $clearedLocales)->delete();
            }

            if ($categoryIds !== null) {
                $product->categories()->sync($categoryIds);
            }
            if ($galleryFileIds !== null) {
                $product->syncFiles($galleryFileIds, 'gallery');
            }
            $product->syncFiles($thumbnailFileId === null ? [] : [$thumbnailFileId], 'thumbnail');
            if ($toppingGroups !== null) {
                $this->syncToppings($product, $toppingGroups);
            }

            return $product->load([
                'productType', 'categories', 'options.values', 'skus.units',
            ])->loadCount('skus');
        });
    }

    // =========================================================================
    //  Delete & Restore
    // =========================================================================

    private function delete(Product $product): bool
    {
        // plan-042: block deleting a product when any of its SKUs is referenced
        // by an open order — checked BEFORE the cascade to SKUs below.
        $skuIds = $product->skus()->pluck('id')->all();
        if (ProductSkuService::skuInOpenOrder($skuIds)) {
            abort(response()->json([
                'message' => "Cannot delete product '{$product->name}': one of its SKUs is used by an open order.",
                'code' => 'PRODUCT_DELETE_BLOCKED_OPEN_ORDER',
            ], 409));
        }

        return DB::transaction(function () use ($product) {
            // Cascade soft-delete to SKUs, options, and option values so they
            // all share `deleted_at` and can be restored together.
            foreach ($product->options as $option) {
                $option->values()->delete();
            }
            $product->options()->delete();
            $product->skus()->delete();

            return $product->delete();
        });
    }

    private function restore(Product $product): Product
    {
        return DB::transaction(function () use ($product) {
            $deletedAt = $product->deleted_at;

            $product->restore();

            // Restore only rows that were soft-deleted as part of THIS exact
            // cascade (matching `deleted_at` to the millisecond, per DESIGN
            // Decision 5). Pre-existing trash from earlier delete cycles must
            // stay trashed — using `=` not `>=` keeps that invariant.
            $product->skus()->withTrashed()
                ->where('deleted_at', $deletedAt)
                ->restore();

            $optionIds = $product->options()->withTrashed()
                ->where('deleted_at', $deletedAt)
                ->pluck('id');

            if ($optionIds->isNotEmpty()) {
                ProductOptionValue::withTrashed()
                    ->whereIn('option_id', $optionIds)
                    ->where('deleted_at', $deletedAt)
                    ->restore();

                $product->options()->withTrashed()
                    ->whereIn('id', $optionIds)
                    ->restore();
            }

            return $product->load([
                'productType', 'categories', 'options.values', 'skus',
            ]);
        });
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    private function submitForApproval(Product $product): Product
    {
        $product->assertApprovalStatus([
            ApprovalStatusEnum::Draft,
            ApprovalStatusEnum::Rejected,
        ], 'submit for approval');

        if ($product->skus()->count() === 0) {
            throw new \InvalidArgumentException(
                'Product must have at least one SKU before submitting.'
            );
        }

        $product->markAsPending();
        $product->logAudit('submitted_for_approval');

        return $product->load(['productType', 'categories', 'skus']);
    }

    private function approve(Product $product, User $approver): Product
    {
        $product->assertApprovalStatus([ApprovalStatusEnum::Pending], 'approve');

        // HQ workflow allows self-approval on Product — the creator can
        // approve their own product. Maker/checker enforcement lives at the
        // tenant level, not here. (Recipe DOES enforce cannot-approve-own
        // in RecipeService::approve per plan-003 DESIGN — different domain
        // requirement, food-safety review gate.)

        $product->markAsApproved($approver);
        $product->logAudit('approved', ['approved_by_id' => $approver->getKey()]);

        return $product->load(['productType', 'categories', 'skus']);
    }

    private function reject(Product $product, User $reviewer, string $reason): Product
    {
        $product->assertApprovalStatus([ApprovalStatusEnum::Pending], 'reject');

        $product->markAsRejected($reviewer, $reason);
        $product->logAudit('rejected', [
            'rejected_by_id' => $reviewer->getKey(),
            'rejection_reason' => $reason,
        ]);

        return $product->load(['productType', 'categories', 'skus']);
    }

    private function activate(Product $product): Product
    {
        $this->assertStatus($product, [
            ProductStatusEnum::Approved,
            ProductStatusEnum::Inactive,
        ], 'activate');

        if ($product->skus()->where('is_active', true)->doesntExist()) {
            throw new \InvalidArgumentException(
                'Product must have at least one active SKU before activating.'
            );
        }

        $product->update(['status' => ProductStatusEnum::Active->value]);
        $product->logAudit('activated');

        return $product->load(['productType', 'categories', 'skus']);
    }

    private function deactivate(Product $product): Product
    {
        $this->assertStatus($product, [ProductStatusEnum::Active], 'deactivate');

        $product->update(['status' => ProductStatusEnum::Inactive->value]);
        $product->logAudit('deactivated');

        return $product->load(['productType', 'categories', 'skus']);
    }

    // =========================================================================
    //  Option/value mutation boundary
    // =========================================================================

    public function insertOption(CreateProductOptionCommand $command): MutationResult
    {
        return DB::transaction(function () use ($command): MutationResult {
            $product = $this->scopedProduct($command->productId, $command->context->organizationId, $command->brandId);
            $existing = ProductOption::withTrashed()->where('product_id', $product->id)->lockForUpdate()->find($command->payload->optionId);
            if ($existing !== null) {
                if ($existing->trashed()) {
                    if ($existing->key !== $command->payload->key) {
                        throw ValidationException::withMessages(['option_id' => ['The option command ID belongs to a different natural key.']]);
                    }
                    $existing->restore();
                    $this->optionService->update($existing, $this->optionPayloadData($command->payload));

                    return new MutationResult($existing->id, null, true);
                }
                $same = $existing->key === $command->payload->key
                    && $existing->name === $command->payload->name
                    && (int) $existing->position === $command->payload->position
                    && (bool) $existing->is_active === $command->payload->active;
                if (! $same) {
                    throw ValidationException::withMessages(['option_id' => ['The option command ID has already been used with a different payload.']]);
                }

                return new MutationResult($existing->id, null, false);
            }
            $option = $this->optionService->create(['id' => $command->payload->optionId, 'product_id' => $product->id, ...$this->optionPayloadData($command->payload)]);

            return new MutationResult($option->id, null, true);
        });
    }

    public function applyOptionRevision(ReviseProductOptionCommand $command): MutationResult
    {
        $option = $this->scopedOption($command->payload->optionId, $command->context->organizationId, $command->brandId);
        DB::transaction(function () use ($option, $command): void {
            $this->optionService->update($option, $this->optionPayloadData($command->payload));
            $option->translations()->whereIn('locale', $command->payload->clearedLocales)->delete();
        });

        return new MutationResult($option->id, null, true);
    }

    public function markOptionArchived(ProductOptionLifecycleCommand $command): MutationResult
    {
        $command->assertAction(ProductOptionLifecycleAction::Archive);
        $option = $this->scopedOption($command->optionId, $command->context->organizationId, $command->brandId);
        $this->optionService->delete($option);

        return new MutationResult($option->id, null, true);
    }

    public function expandOption(ExpandProductOptionCommand $command): ProductOptionExpansionResult
    {
        return DB::transaction(function () use ($command): ProductOptionExpansionResult {
            $product = $this->scopedProduct($command->productId, $command->context->organizationId, $command->brandId);
            $values = collect($command->payload->values)->sortBy('position')->values();
            $defaultIndex = $values->search(fn ($value) => $value->valueId === $command->defaultValueId);
            if ($defaultIndex === false) {
                throw ValidationException::withMessages(['default_value_id' => ['Default value must belong to the option payload.']]);
            }
            $data = ['id' => $command->payload->optionId, 'product_id' => $product->id, ...$this->optionPayloadData($command->payload), 'values' => $values->map(fn ($value) => ['id' => $value->valueId, ...$this->optionValuePayloadData($value)])->all(), 'default_value_index' => $defaultIndex, 'generate_combinations' => $command->generateCombinations];
            $result = $this->optionService->expandOption($data, $this->skuService);
            if ($result['created_skus']->isNotEmpty()) {
                $this->catalogProjection->syncNewSkusToMenuBranches($data['product_id'], $this->catalogSkuProjections($result['created_skus']));
            }

            return new ProductOptionExpansionResult($result['option']->id, $result['updated_skus'], $result['created_skus']->pluck('id')->all());
        });
    }

    public function syncOptionValues(SyncProductOptionValuesCommand $command): ProductOptionSyncResult
    {
        return DB::transaction(function () use ($command): ProductOptionSyncResult {
            $option = $this->scopedOption($command->payload->optionId, $command->context->organizationId, $command->brandId);
            $data = ['name' => $command->payload->name, 'values' => collect($command->payload->values)->sortBy('position')->map(function ($value) use ($option): array {
                $claimed = ProductOptionValue::withTrashed()->whereKey($value->valueId)->lockForUpdate()->first();
                if ($claimed !== null && ($claimed->option_id !== $option->id || $claimed->value !== $value->value)) {
                    throw ValidationException::withMessages([
                        'values' => ['An option value ID is already owned by a different aggregate or slug.'],
                    ]);
                }

                return [
                    'id' => $claimed !== null && ! $claimed->trashed() ? $value->valueId : null,
                    'new_id' => $value->valueId,
                    ...$this->optionValuePayloadData($value),
                ];
            })->values()->all()];
            $result = $this->optionService->syncValues($option, $data, $this->skuService);
            if ($result['created_skus']->isNotEmpty()) {
                $this->catalogProjection->syncNewSkusToMenuBranches($option->product_id, $this->catalogSkuProjections($result['created_skus']));
            }

            return new ProductOptionSyncResult($result['option']->id, $result['created_skus']->pluck('id')->all());
        });
    }

    public function insertOptionValue(CreateProductOptionValueCommand $command): MutationResult
    {
        return DB::transaction(function () use ($command): MutationResult {
            $option = $this->scopedOption($command->optionId, $command->context->organizationId, $command->brandId);
            $existing = ProductOptionValue::withTrashed()->where('option_id', $option->id)->lockForUpdate()->find($command->payload->valueId);
            if ($existing !== null) {
                if ($existing->trashed()) {
                    if ($existing->value !== $command->payload->value) {
                        throw ValidationException::withMessages(['value_id' => ['The option value command ID belongs to a different natural slug.']]);
                    }
                    $existing->restore();
                    $this->optionValueService->update($existing, $this->optionValuePayloadData($command->payload));

                    return new MutationResult($existing->id, null, true);
                }
                $same = $existing->value === $command->payload->value
                    && $existing->label === $command->payload->label
                    && (int) $existing->position === $command->payload->position
                    && (bool) $existing->is_active === $command->payload->active;
                if (! $same) {
                    throw ValidationException::withMessages(['value_id' => ['The option value command ID has already been used with a different payload.']]);
                }

                return new MutationResult($existing->id, null, false);
            }
            $value = $this->optionValueService->create(['id' => $command->payload->valueId, 'option_id' => $option->id, ...$this->optionValuePayloadData($command->payload)]);

            return new MutationResult($value->id, null, true);
        });
    }

    public function applyOptionValueRevision(ReviseProductOptionValueCommand $command): MutationResult
    {
        $value = $this->scopedOptionValue($command->payload->valueId, $command->context->organizationId, $command->brandId);
        DB::transaction(function () use ($value, $command): void {
            $this->optionValueService->update($value, $this->optionValuePayloadData($command->payload));
            $value->translations()->whereIn('locale', $command->payload->clearedLocales)->delete();
        });

        return new MutationResult($value->id, null, true);
    }

    public function markOptionValueArchived(ProductOptionValueLifecycleCommand $command): MutationResult
    {
        $value = $this->scopedOptionValue($command->valueId, $command->context->organizationId, $command->brandId);
        if ($command->action === ProductOptionValueLifecycleAction::ForceArchive) {
            $this->optionValueService->forceDelete($value);
        } else {
            $command->assertAction(ProductOptionValueLifecycleAction::Archive);
            $this->optionValueService->delete($value);
        }

        return new MutationResult($value->id, null, true);
    }

    public function incrementReviewAggregates(Product $product, int $rating, ReviewSentimentEnum $sentiment): void
    {
        $product->increment('review_total_count');
        $product->increment('review_rating_sum', $rating);
        if ($sentiment === ReviewSentimentEnum::Up) {
            $product->increment('review_up_count');
        }
    }

    /**
     * #962 — nhận id + hai giá trị thay vì `App\Models\ProductReview`.
     *
     * `product_reviews` thuộc CustomerEngagement; lớp này thuộc Catalog và chỉ
     * cập nhật BỘ ĐẾM trên `products`. Nó chưa bao giờ cần cái model — nó đọc
     * đúng ba thuộc tính, và nhận nguyên model là cách để một Eloquent model
     * của module khác đi lọt vào chữ ký công khai của Catalog.
     */
    public function decrementReviewAggregates(string $productId, int $rating, ?ReviewSentimentEnum $sentiment): void
    {
        $product = Product::whereKey($productId)->lockForUpdate()->first();
        if ($product === null) {
            return;
        }

        $product->decrement('review_total_count');
        $product->decrement('review_rating_sum', $rating);

        if ($sentiment === ReviewSentimentEnum::Up) {
            $product->decrement('review_up_count');
        }
    }

    /** @param  array<string, mixed>  $data */
    private function updateVariantUnit(VariantUnit $unit, array $data): VariantUnit
    {
        $unit->update($data);

        return $unit->fresh();
    }

    private function deleteVariantUnit(VariantUnit $unit): bool
    {
        return $unit->delete();
    }

    private function setBaseVariantUnit(VariantUnit $unit): VariantUnit
    {
        return DB::transaction(function () use ($unit): VariantUnit {
            ProductSku::whereKey($unit->product_sku_id)->lockForUpdate()->firstOrFail();
            VariantUnit::where('product_sku_id', $unit->product_sku_id)->lockForUpdate()->get();
            $lockedUnit = VariantUnit::whereKey($unit->id)->firstOrFail();
            VariantUnit::where('product_sku_id', $lockedUnit->product_sku_id)
                ->where('id', '!=', $lockedUnit->id)
                ->where('is_base', true)
                ->update(['is_base' => false]);

            $lockedUnit->update(['is_base' => true]);

            return $lockedUnit->fresh();
        });
    }

    /** @return array<string, mixed> */
    private function productPayloadData(ProductPayload $payload): array
    {
        $data = [
            'name' => $payload->name,
            'description' => $payload->description,
            'product_type_id' => $payload->productTypeId,
            'category_ids' => $payload->categoryIds,
            'is_hidden' => $payload->hidden,
            'slug' => $payload->slug,
            'tax_type_id' => $payload->taxTypeId,
            'gallery_file_ids' => $payload->galleryFileIds,
            'thumbnail_file_id' => $payload->thumbnailFileId,
            'status' => $payload->status->value,
            'cleared_locales' => $payload->clearedLocales,
            'topping_groups' => $payload->toppingGroups,
        ];
        foreach ($payload->translations as $translation) {
            $data[$translation->locale->value] = ['name' => $translation->name, 'description' => $translation->description];
        }

        $options = collect($payload->options)->sortBy('position')->values();
        $data['options'] = $options->map(fn (ProductOptionPayload $option): array => [
            'id' => $option->optionId,
            'key' => $option->key,
            'name' => $option->name,
            'position' => $option->position,
            'is_active' => $option->active,
            'translations' => $option->translations,
            'values' => collect($option->values)->sortBy('position')->values()->map(fn (ProductOptionValuePayload $value): array => [
                'id' => $value->valueId,
                'value' => $value->value,
                'label' => $value->label,
                'position' => $value->position,
                'is_active' => $value->active,
                'translations' => $value->translations,
            ])->all(),
        ])->all();
        $data['skus'] = array_map(fn (ProductSkuPayload $sku): array => [
            'id' => $sku->skuId,
            ...$this->skuPayloadData($sku),
        ], $payload->skus);

        return $data;
    }

    /** @return array<string, mixed> */
    private function categoryPayloadData(CategoryPayload $payload): array
    {
        $data = [
            'name' => $payload->name,
            'sku' => $payload->sku,
            'slug' => $payload->slug,
            'description' => $payload->description,
            'parent_id' => $payload->parentId,
            'is_active' => $payload->active,
            'is_featured' => $payload->featured,
            'image_url' => $payload->imageUrl,
        ];
        foreach (['ja', 'en', 'vi'] as $locale) {
            foreach ($payload->translations as $translation) {
                if ($translation->locale->value === $locale) {
                    $data[$locale] = ['name' => $translation->name, 'description' => $translation->description];
                    break;
                }
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function productTypePayloadData(ProductTypePayload $payload): array
    {
        $data = [
            'name' => $payload->name,
            'code' => $payload->code,
            'description' => $payload->description,
            'product_form' => $payload->productForm,
            'has_recipe' => $payload->hasRecipe,
            'is_inventory_tracked' => $payload->inventoryTracked,
            'icon' => $payload->icon,
            'is_active' => $payload->active,
        ];
        foreach ($payload->translations as $translation) {
            $data[$translation->locale->value] = ['name' => $translation->name, 'description' => $translation->description];
        }

        return $data;
    }

    private function scopedProductType(string $productTypeId, ?string $organizationId, string $brandId): ProductType
    {
        return ProductType::where('organization_id', $organizationId)->where('brand_id', $brandId)->findOrFail($productTypeId);
    }

    private function assertActorContext(?string $actorId, ?string $organizationId): void
    {
        if ($actorId === null || $organizationId === null) {
            return;
        }

        $this->assertActorOrganization(User::findOrFail($actorId), $organizationId);
    }

    private function assertProductPayloadReferences(ProductPayload $payload, ?string $organizationId, string $brandId): void
    {
        if ($organizationId === null) {
            throw ValidationException::withMessages(['organization_id' => ['The mutation organization is required.']]);
        }
        $this->assertProductBrandContext($organizationId, $brandId);
        if ($payload->productTypeId !== null && ! ProductType::where('organization_id', $organizationId)->where('brand_id', $brandId)->whereKey($payload->productTypeId)->exists()) {
            throw ValidationException::withMessages(['product_type_id' => ['The product type must belong to the command brand.']]);
        }
        if ($payload->categoryIds !== [] && Category::where('organization_id', $organizationId)->where('brand_id', $brandId)->whereIn('id', $payload->categoryIds)->count() !== count($payload->categoryIds)) {
            throw ValidationException::withMessages(['category_ids' => ['Every category must belong to the command brand.']]);
        }
        if ($payload->taxTypeId !== null && ! $this->taxTypes->belongsToBrand($payload->taxTypeId, $organizationId, $brandId)) {
            throw ValidationException::withMessages(['tax_type_id' => ['The tax type must belong to the command brand.']]);
        }
        $toppingGroupIds = collect($payload->toppingGroups)->pluck('toppingGroupId')->unique()->values()->all();
        if (count($toppingGroupIds) !== count($payload->toppingGroups)
            || ($toppingGroupIds !== [] && ToppingGroup::where('organization_id', $organizationId)->where('brand_id', $brandId)->whereIn('id', $toppingGroupIds)->count() !== count($toppingGroupIds))) {
            throw ValidationException::withMessages(['topping_groups' => ['Every topping group must be unique and belong to the command brand.']]);
        }

        $valuesById = [];
        $seenPositions = [];
        foreach ($payload->options as $option) {
            if (isset($seenPositions[$option->position])) {
                throw ValidationException::withMessages(['options' => ['Option positions must be unique.']]);
            }
            $seenPositions[$option->position] = true;
            foreach ($option->values as $value) {
                $valuesById[$value->valueId] = $option->position;
            }
        }
        foreach ($payload->skus as $sku) {
            foreach ($sku->optionValueIds as $slot => $valueId) {
                if ($valueId === null) {
                    continue;
                }
                if (($valuesById[$valueId] ?? null) !== $slot + 1) {
                    throw ValidationException::withMessages(['skus' => ['Every SKU option value must belong to the payload option at the matching position.']]);
                }
            }
            if ($sku->recipeId !== null && ! Recipe::where('organization_id', $organizationId)->where('brand_id', $brandId)->whereKey($sku->recipeId)->exists()) {
                throw ValidationException::withMessages(['skus' => ['Every SKU recipe must belong to the command brand.']]);
            }
        }
    }

    /**
     * Assert the command's brand belongs to the mutation organization.
     *
     * Two different id spaces meet here, which is what made the original
     * version reject every legitimate call:
     *
     *   - `MutationContext::$organizationId` is the LOCAL `organizations.id`
     *     PK. `HasOrganizationContext::getOrganizationId()` resolves the SSO
     *     `console_organization_id` down to this local PK before handing it to
     *     `new MutationContext(...)` (see HQ\ProductController), and every
     *     other guard in this class compares it against `organization_id`
     *     columns.
     *   - `brands` has NO local `organization_id` column — it is keyed by
     *     `console_organization_id` only.
     *
     * Comparing the local PK directly against `brands.console_organization_id`
     * therefore never matched, failing both the HQ product API and
     * ProductSeeder with "The command brand must belong to the mutation
     * organization." Resolve through the Organization row instead.
     */
    private function assertProductBrandContext(?string $organizationId, string $brandId): void
    {
        if ($organizationId === null) {
            throw ValidationException::withMessages(['brand_id' => ['The command brand must belong to the mutation organization.']]);
        }

        $consoleOrgId = Organization::whereKey($organizationId)->value('console_organization_id');

        if ($consoleOrgId === null || ! Brand::whereKey($brandId)->where('console_organization_id', $consoleOrgId)->exists()) {
            throw ValidationException::withMessages(['brand_id' => ['The command brand must belong to the mutation organization.']]);
        }
    }

    /**
     * #1744 — lượt GỬI LẠI của một lệnh CREATE phải trúng hàng cũ.
     *
     * Id-do-người-gọi-cấp tồn tại để chống trùng: client sinh uuid, gửi lệnh, và
     * nếu mạng đứt thì gửi LẠI cùng uuid ấy. `insertProduct` (và `insertSku`)
     * vốn đã làm đúng — tra hàng cũ, so khớp payload, trả `changed = false`.
     * Ba lệnh product type / category / variant unit thì KHÔNG: chúng ghi thẳng
     * và lượt hai ném `UniqueConstraintViolationException`, đo được ở
     * `SuppliedIdIsHonouredTest`.
     *
     * Ném hay ghi trùng đều sai, nhưng ném là kiểu sai TỆ HƠN cho client offline:
     * nó không phân biệt được "đã ghi rồi" với "ghi hỏng", nên đường thử-lại
     * không có cách nào tự thoát.
     *
     * So khớp payload chứ không im lặng chấp nhận: cùng id mà khác nội dung là
     * lỗi phía gọi, và nuốt nó đi thì lượt sửa của client biến mất không dấu vết.
     * Chỉ so các khoá là CỘT — payload còn mang bản dịch theo locale, ghi ở bảng
     * khác và không so được kiểu này.
     *
     * @param  array<string, mixed>  $payloadData
     * @param  list<string>  $serverOwned  cột do LỆNH TẠO tự quyết, payload không nói được
     */
    private function assertScalarReplayMatches(Model $existing, array $payloadData, array $serverOwned = []): void
    {
        foreach ($payloadData as $column => $value) {
            if (is_array($value) || in_array($column, $serverOwned, true)) {
                continue; // bản dịch theo locale, hoặc cột máy chủ tự quyết
            }

            // `null` trong payload nghĩa là "để máy chủ tự quyết", không phải
            // "phải là NULL": `ProductTypeService` sinh `code`, `CategoryService`
            // sinh `slug`. So chúng với giá trị đã sinh thì MỌI lượt gửi lại đều
            // thành xung đột — đo được, đây là bản so khớp thứ hai sau khi bản
            // đầu ngã đúng chỗ này.
            if ($value === null) {
                continue;
            }

            $stored = $existing->getAttribute($column);
            if (is_bool($value)) {
                $stored = (bool) $stored;
            } elseif (is_numeric($value) && is_numeric($stored)) {
                // Cột decimal đọc ra `'24.00'` còn payload mang `'24'`. So bằng
                // chuỗi ở đây là báo xung đột cho hai giá trị bằng nhau.
                if (bccomp((string) $stored, (string) $value, 6) !== 0) {
                    throw new ProductIdempotencyConflict;
                }

                continue;
            } elseif ($stored !== null) {
                $stored = (string) $stored;
            }

            if ($stored !== $value) {
                throw new ProductIdempotencyConflict;
            }
        }
    }

    private function assertProductReplayMatches(Product $product, ProductPayload $payload): void
    {
        $expected = json_decode($payload->canonicalJson(), true, flags: JSON_THROW_ON_ERROR);
        $stored = $this->normalizedStoredProductPayload($product, $expected);
        $same = hash_equals(hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR)), hash('sha256', json_encode($stored, JSON_THROW_ON_ERROR)));

        if (! $same) {
            throw new ProductIdempotencyConflict;
        }
    }

    private function assertProductNestedPayloadMatches(Product $product, ProductPayload $payload): void
    {
        $expected = json_decode($payload->canonicalJson(), true, flags: JSON_THROW_ON_ERROR);
        $stored = $this->normalizedStoredProductPayload($product, $expected);
        $same = hash_equals(
            hash('sha256', json_encode([$expected['options'], $expected['skus']], JSON_THROW_ON_ERROR)),
            hash('sha256', json_encode([$stored['options'], $stored['skus']], JSON_THROW_ON_ERROR)),
        );

        if (! $same) {
            throw ValidationException::withMessages([
                'rows' => ['Imported Product revisions must use option and SKU commands for nested graph changes.'],
            ]);
        }
    }

    /** @param array<string, mixed> $expected @return array<string, mixed> */
    private function normalizedStoredProductPayload(Product $product, array $expected): array
    {
        $stored = json_decode($this->payloadFactory->forRevision($product, [])->canonicalJson(), true, flags: JSON_THROW_ON_ERROR);
        if ($expected['slug'] === null) {
            $stored['slug'] = null;
        }
        if ($expected['translations'] === []) {
            $stored['translations'] = [];
        }
        $storedSkus = collect($stored['skus'])->keyBy('sku_id');
        foreach ($expected['skus'] as $sku) {
            if (! $storedSkus->has($sku['sku_id'])) {
                continue;
            }
            $storedSku = $storedSkus->get($sku['sku_id']);
            if ($sku['code'] === null) {
                $storedSku['code'] = null;
            }
            if ($sku['translations'] === []) {
                $storedSku['translations'] = [];
            }
            $storedSkus->put($sku['sku_id'], $storedSku);
        }
        $storedOptions = collect($stored['options'])->keyBy('option_id');
        foreach ($expected['options'] as $option) {
            if (! $storedOptions->has($option['option_id'])) {
                continue;
            }
            $storedOption = $storedOptions->get($option['option_id']);
            if ($option['translations'] === []) {
                $storedOption['translations'] = [];
            }
            $storedValues = collect($storedOption['values'])->keyBy('value_id');
            foreach ($option['values'] as $value) {
                if ($storedValues->has($value['value_id']) && $value['translations'] === []) {
                    $storedValue = $storedValues->get($value['value_id']);
                    $storedValue['translations'] = [];
                    $storedValues->put($value['value_id'], $storedValue);
                }
            }
            $storedOption['values'] = $storedValues->sortKeys()->values()->all();
            $storedOptions->put($option['option_id'], $storedOption);
        }
        $stored['skus'] = $storedSkus->sortKeys()->values()->all();
        $stored['options'] = $storedOptions->sortKeys()->values()->all();

        return $stored;
    }

    /** @param array<int, ProductToppingGroupPayload> $groups */
    private function syncToppings(Product $product, array $groups): void
    {
        $active = collect($groups)->filter->active->values();
        $ids = $active->pluck('toppingGroupId')->all();
        $sortOrders = $active->mapWithKeys(fn ($group) => [$group->toppingGroupId => $group->position])->all();
        $bounds = $active->mapWithKeys(fn ($group) => [$group->toppingGroupId => ['min' => $group->minimumSelections, 'max' => $group->maximumSelections]])->all();
        $this->toppings->syncForProduct($product, $ids, $sortOrders, $bounds);
        foreach ($active as $group) {
            $model = ToppingGroup::findOrFail($group->toppingGroupId);
            $this->toppings->syncOverrides($product, $model, array_map(fn ($override) => [
                'topping_group_item_id' => $override->itemId,
                'product_sku_id' => $override->skuId,
                'is_hidden' => $override->hidden,
                'override_price' => $override->priceOverrideMinor,
            ], $group->itemOverrides));
        }
    }

    /** @return array<string, mixed> */
    private function skuPayloadData(ProductSkuPayload $payload): array
    {
        $ids = array_pad($payload->optionValueIds, 3, null);
        $data = [
            'sku' => $payload->code,
            'name' => $payload->name,
            'selling_price' => $payload->sellingPrice,
            'cost_price' => $payload->costPrice,
            'cost_price_auto' => $payload->costPriceAuto,
            'is_cost_override' => $payload->costOverride,
            'recipe_id' => $payload->recipeId,
            'recipe_multiplier' => $payload->recipeMultiplier,
            'inventory_mode' => $payload->inventoryMode->value,
            'is_active' => $payload->active,
            'option_value1_id' => $ids[0],
            'option_value2_id' => $ids[1],
            'option_value3_id' => $ids[2],
        ];
        foreach ($payload->translations as $translation) {
            $data[$translation->locale->value] = ['name' => $translation->name];
        }

        return $data;
    }

    private function optionPayloadData(ProductOptionPayload $payload): array
    {
        $data = ['key' => $payload->key, 'name' => $payload->name, 'position' => $payload->position, 'is_active' => $payload->active];
        foreach ($payload->translations as $translation) {
            $data[$translation->locale->value] = ['name' => $translation->name];
        }

        return $data;
    }

    private function optionValuePayloadData(ProductOptionValuePayload $payload): array
    {
        $data = ['value' => $payload->value, 'label' => $payload->label, 'position' => $payload->position, 'is_active' => $payload->active];
        foreach ($payload->translations as $translation) {
            $data[$translation->locale->value] = ['label' => $translation->name];
        }

        return $data;
    }

    private function scopedOption(string $id, ?string $organizationId, string $brandId): ProductOption
    {
        return ProductOption::whereHas('product', fn ($query) => $query->where('organization_id', $organizationId)->where('brand_id', $brandId))->findOrFail($id);
    }

    private function scopedOptionValue(string $id, ?string $organizationId, string $brandId): ProductOptionValue
    {
        return ProductOptionValue::whereHas('option.product', fn ($query) => $query->where('organization_id', $organizationId)->where('brand_id', $brandId))->findOrFail($id);
    }

    private function assertSkuReplayMatches(ProductSku $sku, ProductSkuPayload $payload): void
    {
        $ids = array_pad($payload->optionValueIds, 3, null);
        $same = ($payload->code === null || $sku->sku === $payload->code)
            && ($payload->name === null || $sku->name === $payload->name)
            && $this->canonicalStoredDecimal($sku->selling_price) === $payload->sellingPrice
            && $this->canonicalStoredDecimal($sku->cost_price) === $payload->costPrice
            && $this->canonicalStoredDecimal($sku->cost_price_auto) === $payload->costPriceAuto
            && (bool) $sku->is_cost_override === $payload->costOverride
            && $sku->recipe_id === $payload->recipeId
            && $this->canonicalStoredDecimal($sku->recipe_multiplier) === $payload->recipeMultiplier
            && $sku->inventory_mode === $payload->inventoryMode
            && (bool) $sku->is_active === $payload->active
            && $sku->option_value1_id === $ids[0]
            && $sku->option_value2_id === $ids[1]
            && $sku->option_value3_id === $ids[2];

        if (! $same) {
            throw ValidationException::withMessages([
                'sku_id' => ['The SKU command ID has already been used with a different payload.'],
            ]);
        }
    }

    private function canonicalStoredDecimal(string|int|float $value): string
    {
        $raw = (string) $value;
        $canonical = str_contains($raw, '.') ? rtrim(rtrim($raw, '0'), '.') : $raw;

        return $canonical === '' ? '0' : $canonical;
    }

    private function scopedSku(string $skuId, ?string $organizationId, string $brandId): ProductSku
    {
        return ProductSku::whereHas('product', fn ($q) => $q->where('organization_id', $organizationId)->where('brand_id', $brandId))->findOrFail($skuId);
    }

    private function scopedCategory(string $categoryId, ?string $organizationId, string $brandId): Category
    {
        return Category::where('organization_id', $organizationId)->where('brand_id', $brandId)->findOrFail($categoryId);
    }

    /** @return array<string, mixed> */
    private function variantUnitPayloadData(VariantUnitPayload $payload): array
    {
        return [
            'unit' => $payload->unit,
            'ratio' => $payload->ratio,
            'sku' => $payload->sku,
            'barcode' => $payload->barcode,
            'price' => $payload->price,
            'is_base' => $payload->base,
            'is_sellable' => $payload->sellable,
        ];
    }

    private function scopedProduct(string $productId, ?string $organizationId, string $brandId): Product
    {
        return Product::where('organization_id', $organizationId)->where('brand_id', $brandId)->findOrFail($productId);
    }

    private function mutationResult(Product $product, bool $changed): MutationResult
    {
        return new MutationResult($product->id, null, $changed);
    }

    /** @return list<CatalogSkuProjection> */
    private function catalogSkuProjections(iterable $skus): array
    {
        $projections = [];

        foreach ($skus as $sku) {
            $projections[] = new CatalogSkuProjection($sku->id, (string) ($sku->selling_price ?? 0));
        }

        return $projections;
    }

    private function assertActorOrganization(User $actor, ?string $organizationId): void
    {
        if ($organizationId === null || ! $this->workspaceAccess->canAccessHeadquarters($actor, $organizationId)) {
            throw new AuthorizationException('Actor is not authorized for this organization.');
        }
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    /**
     * Guard for status writes coming through the generic update() path (#124).
     *
     * The two status groups are independent: approval (draft→pending→
     * approved/rejected, via submit/approve/reject) and lifecycle
     * (active/inactive, via activate/deactivate — only after approval). A
     * plain PUT may only perform a legal lifecycle move — approved/active/
     * inactive → active/inactive — everything else (draft→active,
     * approved→draft, …) must go through the dedicated workflow endpoints and
     * is rejected here with a 422.
     */
    private function assertUpdatableStatus(Product $product, string|ProductStatusEnum $requested): void
    {
        $requested = $requested instanceof ProductStatusEnum
            ? $requested
            : ProductStatusEnum::from($requested);

        $current = $product->status instanceof ProductStatusEnum
            ? $product->status
            : ProductStatusEnum::from($product->status);

        // No-op writes (status unchanged) are always fine.
        if ($requested === $current) {
            return;
        }

        $lifecycleTargets = [ProductStatusEnum::Active, ProductStatusEnum::Inactive];
        $postApprovalFrom = [ProductStatusEnum::Approved, ProductStatusEnum::Active, ProductStatusEnum::Inactive];

        $legal = in_array($requested, $lifecycleTargets, true)
            && in_array($current, $postApprovalFrom, true);

        if (! $legal) {
            throw new InvalidStatusTransitionException(
                from: $current->value,
                to: $requested->value,
                action: 'change status to '.$requested->value,
                allowed: array_map(fn (ProductStatusEnum $s) => $s->value, $lifecycleTargets),
                subject: 'product',
            );
        }
    }

    /**
     * Lifecycle-only assertion (active/inactive). Approval transitions go
     * through `$product->assertApprovalStatus(...)` via the trait.
     *
     * @param  ProductStatusEnum[]  $allowedStatuses
     */
    private function assertStatus(Product $product, array $allowedStatuses, string $action): void
    {
        $currentStatus = $product->status instanceof ProductStatusEnum
            ? $product->status
            : ProductStatusEnum::from($product->status);

        if (! in_array($currentStatus, $allowedStatuses, true)) {
            $allowedValues = array_map(fn (ProductStatusEnum $s) => $s->value, $allowedStatuses);

            throw new InvalidStatusTransitionException(
                from: $currentStatus->value,
                action: $action,
                allowed: $allowedValues,
                subject: 'product',
            );
        }
    }

    private function createSku(Product $product, array $data): void
    {
        $units = $data['units'] ?? [];
        unset($data['units']);

        $data['product_id'] = $product->id;

        if (! isset($data['selling_price']) && isset($data['cost_price'])) {
            $data['selling_price'] = $data['cost_price'];
        }

        if (empty($data['sku'])) {
            $skuService = new ProductSkuService;
            $data['sku'] = $skuService->generateUniqueSku(
                additionalWhere: ['product_id' => $product->id]
            );
        }

        $optionSignature = ProductSku::computeOptionSignature(
            $data['option_value1_id'] ?? null,
            $data['option_value2_id'] ?? null,
            $data['option_value3_id'] ?? null,
        );

        if ($product->skus()->where('option_signature', $optionSignature)->exists()) {
            throw ValidationException::withMessages([
                'skus' => 'This variant combination already exists.',
            ]);
        }

        // option_signature is computed by ProductSkuObserver::saving()
        $sku = new ProductSku;
        $sku->fill($data);
        $sku->forceFill(array_filter(['id' => $data['id'] ?? null, 'product_id' => $product->id], static fn (mixed $value): bool => $value !== null));
        $sku->save();

        foreach ($units as $unitData) {
            $sku->units()->create($unitData);
        }
    }

    /**
     * Persist nested options + values + SKUs as part of a Shopify-style
     * full create. Called from {@see create()} when the request payload
     * includes an `options` array.
     *
     * @param  array<int, array{key: string, name: string, position: int, is_active?: bool, values: array<int, array{value: string, label: string, position?: int, is_active?: bool}>}>  $optionsData
     * @param  array<int, array{value_indices: array<int, int>, sku?: string|null, name?: string|null, selling_price?: float|int|null, cost_price?: float|int|null, is_cost_override?: bool, is_active?: bool}>  $skusData
     */
    private function createOptionsValuesAndIndexedSkus(
        Product $product,
        array $optionsData,
        array $skusData,
    ): void {
        // Step 1 — persist options + their values, recording the created
        // ProductOptionValue models in input order so that SKU
        // `value_indices` can address them later.

        /** @var array<int, array{position: int, values: array<int, ProductOptionValue>}> $orderedOptions */
        $orderedOptions = [];

        foreach ($optionsData as $optionInputIndex => $optionInput) {
            $option = new ProductOption;
            $option->fill([
                'key' => $optionInput['key'],
                'name' => $optionInput['name'],
                'position' => $optionInput['position'],
                'is_active' => $optionInput['is_active'] ?? true,
            ]);
            $option->forceFill(['id' => $optionInput['id'] ?? (string) Str::uuid(), 'product_id' => $product->id]);
            $option->save();
            foreach ($optionInput['translations'] ?? [] as $translation) {
                $option->translateOrNew($translation->locale->value)->fill(['name' => $translation->name]);
            }
            $option->save();

            $createdValues = [];
            foreach ($optionInput['values'] as $valueIndex => $valueInput) {
                $value = new ProductOptionValue;
                $value->fill([
                    'value' => $valueInput['value'],
                    'label' => $valueInput['label'],
                    'position' => $valueInput['position'] ?? ($valueIndex + 1),
                    'is_active' => $valueInput['is_active'] ?? true,
                ]);
                $value->forceFill(['id' => $valueInput['id'] ?? (string) Str::uuid(), 'option_id' => $option->id]);
                $value->save();
                foreach ($valueInput['translations'] ?? [] as $translation) {
                    $value->translateOrNew($translation->locale->value)->fill(['label' => $translation->name]);
                }
                $value->save();
                $createdValues[] = $value;
            }

            $orderedOptions[$optionInputIndex] = [
                'position' => (int) $optionInput['position'],
                'values' => $createdValues,
            ];
        }

        // Step 2 — for each SKU input, resolve `value_indices` into the
        // positional `option_value{N}_id` columns. The N comes from the
        // matching option's `position` field, NOT the input order.

        foreach ($skusData as $skuInputIndex => $skuInput) {
            if (! array_key_exists('value_indices', $skuInput)) {
                $this->createSku($product, $skuInput);

                continue;
            }
            $valueIndices = $skuInput['value_indices'] ?? [];

            if (count($valueIndices) !== count($orderedOptions)) {
                throw new \InvalidArgumentException(sprintf(
                    'SKU at index %d has %d value_indices but the product has %d options',
                    $skuInputIndex,
                    count($valueIndices),
                    count($orderedOptions),
                ));
            }

            $skuData = [
                'name' => $skuInput['name'] ?? null,
                'sku' => $skuInput['sku'] ?? null,
                // selling_price is the menu price (issue #875). Thread it through
                // explicitly — this builder cherry-picks keys, so an unlisted
                // field is dropped even after it passes request validation. When
                // null, createSku() mirrors it from cost_price as a fallback.
                'selling_price' => $skuInput['selling_price'] ?? null,
                'cost_price' => $skuInput['cost_price'] ?? 0,
                'is_cost_override' => $skuInput['is_cost_override'] ?? false,
                'is_active' => $skuInput['is_active'] ?? true,
            ];

            foreach ($valueIndices as $optionInputIndex => $valueIndex) {
                if (! isset($orderedOptions[$optionInputIndex])) {
                    throw new \InvalidArgumentException(sprintf(
                        'SKU at index %d references unknown option input index %d',
                        $skuInputIndex,
                        $optionInputIndex,
                    ));
                }

                $option = $orderedOptions[$optionInputIndex];

                if (! isset($option['values'][$valueIndex])) {
                    throw new \InvalidArgumentException(sprintf(
                        'SKU at index %d references value_index %d for option at input index %d, which only has %d values',
                        $skuInputIndex,
                        $valueIndex,
                        $optionInputIndex,
                        count($option['values']),
                    ));
                }

                $columnPosition = $option['position'];
                $skuData["option_value{$columnPosition}_id"] = $option['values'][$valueIndex]->id;
            }

            $this->createSku($product, $skuData);
        }
    }

    /**
     * Generate a slug that is unique within an organization, falling back to
     * "{base}-2", "{base}-3", ... when the base collides.
     */
    private function generateUniqueSlug(string $base, ?string $organizationId): string
    {
        if ($base === '') {
            $base = 'product';
        }

        $candidate = $base;
        $suffix = 2;

        $query = fn (string $value) => Product::query()
            ->where('slug', $value)
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId));

        while ($query($candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /*
     * #962 — `recordReviewAggregates()` đã dời sang
     * `EloquentReviewedSkuDirectory` (hiện thực `ProductReviewAggregates`).
     *
     * Nó là điểm với tay DUY NHẤT của CustomerEngagement vào phần Internal của
     * Catalog, và class này thì nhận 9 dependency của đường ghi catalog — kéo cả
     * chùm đó vào một cổng chỉ cộng ba cột là đổi một cạnh ranh giới lấy chín
     * cạnh khởi tạo.
     */
}
