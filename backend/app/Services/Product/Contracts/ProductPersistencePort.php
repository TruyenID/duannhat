<?php

namespace App\Services\Product\Contracts;

use App\Services\DomainMutation\MutationResult;
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
use App\Services\Product\Results\ProductImportResult;
use App\Services\Product\Results\ProductOptionExpansionResult;
use App\Services\Product\Results\ProductOptionSyncResult;
use App\Services\Product\Results\ProductSkuGenerationResult;

interface ProductPersistencePort
{
    public function insertOption(CreateProductOptionCommand $command): MutationResult;

    public function applyOptionRevision(ReviseProductOptionCommand $command): MutationResult;

    public function markOptionArchived(ProductOptionLifecycleCommand $command): MutationResult;

    public function expandOption(ExpandProductOptionCommand $command): ProductOptionExpansionResult;

    public function syncOptionValues(SyncProductOptionValuesCommand $command): ProductOptionSyncResult;

    public function insertOptionValue(CreateProductOptionValueCommand $command): MutationResult;

    public function applyOptionValueRevision(ReviseProductOptionValueCommand $command): MutationResult;

    public function markOptionValueArchived(ProductOptionValueLifecycleCommand $command): MutationResult;

    public function insertSku(CreateProductSkuCommand $command): MutationResult;

    public function applySkuRevision(ReviseProductSkuCommand $command): MutationResult;

    public function markSkuArchived(ProductSkuLifecycleCommand $command): MutationResult;

    public function markSkuRestored(ProductSkuLifecycleCommand $command): MutationResult;

    public function toggleSkuActiveStatus(ProductSkuLifecycleCommand $command): MutationResult;

    public function generateSkuCombinations(GenerateProductSkuCombinationsCommand $command): ProductSkuGenerationResult;

    public function insertProductType(CreateProductTypeCommand $command): MutationResult;

    public function applyProductTypeRevision(ReviseProductTypeCommand $command): MutationResult;

    public function markProductTypeArchived(ProductTypeLifecycleCommand $command): MutationResult;

    public function markProductTypeRestored(ProductTypeLifecycleCommand $command): MutationResult;

    public function toggleProductTypeActiveStatus(ProductTypeLifecycleCommand $command): MutationResult;

    public function insertCategory(CreateCategoryCommand $command): MutationResult;

    public function applyCategoryRevision(ReviseCategoryCommand $command): MutationResult;

    public function markCategoryArchived(CategoryLifecycleCommand $command): MutationResult;

    public function markCategoryRestored(CategoryLifecycleCommand $command): MutationResult;

    public function insertProduct(CreateProductCommand $command): MutationResult;

    public function applyRevision(ReviseProductCommand $command): MutationResult;

    public function importCatalog(ImportProductsCommand $command): ProductImportResult;

    public function markSubmitted(ProductLifecycleCommand $command): MutationResult;

    public function markApproved(ProductLifecycleCommand $command): MutationResult;

    public function markRejected(ProductLifecycleCommand $command): MutationResult;

    public function markActive(ProductLifecycleCommand $command): MutationResult;

    public function markInactive(ProductLifecycleCommand $command): MutationResult;

    public function markArchived(ProductLifecycleCommand $command): MutationResult;

    public function markRestored(ProductLifecycleCommand $command): MutationResult;

    public function insertVariantUnit(CreateVariantUnitCommand $command): MutationResult;

    public function applyVariantUnitRevision(ReviseVariantUnitCommand $command): MutationResult;

    public function removeVariantUnit(VariantUnitLifecycleCommand $command): MutationResult;

    public function makeBaseVariantUnit(VariantUnitLifecycleCommand $command): MutationResult;
}
