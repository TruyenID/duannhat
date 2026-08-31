<?php

namespace App\Services\Product;

use App\Services\DomainMutation\MutationResult;
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
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\Internal\EloquentProductPersistence;
use App\Services\Product\Results\CategoryTaxTypeAssignmentResult;
use App\Services\Product\Results\ProductImportResult;
use App\Services\Product\Results\ProductOptionExpansionResult;
use App\Services\Product\Results\ProductOptionSyncResult;
use App\Services\Product\Results\ProductSkuGenerationResult;

/**
 * Sole public Product mutation facade.
 *
 * All consumers mutate Product aggregates through typed commands on this facade.
 */
final class ProductService implements ProductMutationFacade
{
    public function __construct(private readonly EloquentProductPersistence $persistence) {}

    public function create(CreateProductCommand $command): MutationResult
    {
        return $this->persistence->insertProduct($command);
    }

    public function createSku(CreateProductSkuCommand $command): MutationResult
    {
        return $this->persistence->insertSku($command);
    }

    public function reviseSku(ReviseProductSkuCommand $command): MutationResult
    {
        return $this->persistence->applySkuRevision($command);
    }

    public function archiveSku(ProductSkuLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markSkuArchived($command);
    }

    public function restoreSku(ProductSkuLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markSkuRestored($command);
    }

    public function toggleSkuStatus(ProductSkuLifecycleCommand $command): MutationResult
    {
        return $this->persistence->toggleSkuActiveStatus($command);
    }

    public function generateSkuCombinations(GenerateProductSkuCombinationsCommand $command): ProductSkuGenerationResult
    {
        return $this->persistence->generateSkuCombinations($command);
    }

    public function createProductType(CreateProductTypeCommand $command): MutationResult
    {
        return $this->persistence->insertProductType($command);
    }

    public function reviseProductType(ReviseProductTypeCommand $command): MutationResult
    {
        return $this->persistence->applyProductTypeRevision($command);
    }

    public function archiveProductType(ProductTypeLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markProductTypeArchived($command);
    }

    public function restoreProductType(ProductTypeLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markProductTypeRestored($command);
    }

    public function toggleProductTypeStatus(ProductTypeLifecycleCommand $command): MutationResult
    {
        return $this->persistence->toggleProductTypeActiveStatus($command);
    }

    public function revise(ReviseProductCommand $command): MutationResult
    {
        return $this->persistence->applyRevision($command);
    }

    public function import(ImportProductsCommand $command): ProductImportResult
    {
        return $this->persistence->importCatalog($command);
    }

    public function submit(ProductLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markSubmitted($command);
    }

    public function approve(ProductLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markApproved($command);
    }

    public function reject(ProductLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markRejected($command);
    }

    public function activate(ProductLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markActive($command);
    }

    public function deactivate(ProductLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markInactive($command);
    }

    public function archive(ProductLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markArchived($command);
    }

    public function restore(ProductLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markRestored($command);
    }

    public function createVariantUnit(CreateVariantUnitCommand $command): MutationResult
    {
        return $this->persistence->insertVariantUnit($command);
    }

    public function reviseVariantUnit(ReviseVariantUnitCommand $command): MutationResult
    {
        return $this->persistence->applyVariantUnitRevision($command);
    }

    public function removeVariantUnit(VariantUnitLifecycleCommand $command): MutationResult
    {
        return $this->persistence->removeVariantUnit($command);
    }

    public function setBaseVariantUnit(VariantUnitLifecycleCommand $command): MutationResult
    {
        return $this->persistence->makeBaseVariantUnit($command);
    }

    public function createCategory(CreateCategoryCommand $command): MutationResult
    {
        return $this->persistence->insertCategory($command);
    }

    public function reviseCategory(ReviseCategoryCommand $command): MutationResult
    {
        return $this->persistence->applyCategoryRevision($command);
    }

    public function archiveCategory(CategoryLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markCategoryArchived($command);
    }

    public function restoreCategory(CategoryLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markCategoryRestored($command);
    }

    public function assignCategoryTaxType(AssignCategoryTaxTypeCommand $command): CategoryTaxTypeAssignmentResult
    {
        return $this->persistence->assignCategoryTaxType($command);
    }

    public function createOption(CreateProductOptionCommand $command): MutationResult
    {
        return $this->persistence->insertOption($command);
    }

    public function reviseOption(ReviseProductOptionCommand $command): MutationResult
    {
        return $this->persistence->applyOptionRevision($command);
    }

    public function archiveOption(ProductOptionLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markOptionArchived($command);
    }

    public function expandOption(ExpandProductOptionCommand $command): ProductOptionExpansionResult
    {
        return $this->persistence->expandOption($command);
    }

    public function syncOptionValues(SyncProductOptionValuesCommand $command): ProductOptionSyncResult
    {
        return $this->persistence->syncOptionValues($command);
    }

    public function createOptionValue(CreateProductOptionValueCommand $command): MutationResult
    {
        return $this->persistence->insertOptionValue($command);
    }

    public function reviseOptionValue(ReviseProductOptionValueCommand $command): MutationResult
    {
        return $this->persistence->applyOptionValueRevision($command);
    }

    public function archiveOptionValue(ProductOptionValueLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markOptionValueArchived($command);
    }
}
