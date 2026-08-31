<?php

namespace App\Services\Product\Contracts;

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
use App\Services\Product\Results\CategoryTaxTypeAssignmentResult;
use App\Services\Product\Results\ProductImportResult;
use App\Services\Product\Results\ProductOptionExpansionResult;
use App\Services\Product\Results\ProductOptionSyncResult;
use App\Services\Product\Results\ProductSkuGenerationResult;

interface ProductMutationFacade
{
    public function createOption(CreateProductOptionCommand $command): MutationResult;

    public function reviseOption(ReviseProductOptionCommand $command): MutationResult;

    public function archiveOption(ProductOptionLifecycleCommand $command): MutationResult;

    public function expandOption(ExpandProductOptionCommand $command): ProductOptionExpansionResult;

    public function syncOptionValues(SyncProductOptionValuesCommand $command): ProductOptionSyncResult;

    public function createOptionValue(CreateProductOptionValueCommand $command): MutationResult;

    public function reviseOptionValue(ReviseProductOptionValueCommand $command): MutationResult;

    public function archiveOptionValue(ProductOptionValueLifecycleCommand $command): MutationResult;

    public function createSku(CreateProductSkuCommand $command): MutationResult;

    public function reviseSku(ReviseProductSkuCommand $command): MutationResult;

    public function archiveSku(ProductSkuLifecycleCommand $command): MutationResult;

    public function restoreSku(ProductSkuLifecycleCommand $command): MutationResult;

    public function toggleSkuStatus(ProductSkuLifecycleCommand $command): MutationResult;

    public function generateSkuCombinations(GenerateProductSkuCombinationsCommand $command): ProductSkuGenerationResult;

    public function createProductType(CreateProductTypeCommand $command): MutationResult;

    public function reviseProductType(ReviseProductTypeCommand $command): MutationResult;

    public function archiveProductType(ProductTypeLifecycleCommand $command): MutationResult;

    public function restoreProductType(ProductTypeLifecycleCommand $command): MutationResult;

    public function toggleProductTypeStatus(ProductTypeLifecycleCommand $command): MutationResult;

    public function createCategory(CreateCategoryCommand $command): MutationResult;

    public function reviseCategory(ReviseCategoryCommand $command): MutationResult;

    public function archiveCategory(CategoryLifecycleCommand $command): MutationResult;

    public function restoreCategory(CategoryLifecycleCommand $command): MutationResult;

    public function assignCategoryTaxType(AssignCategoryTaxTypeCommand $command): CategoryTaxTypeAssignmentResult;

    public function create(CreateProductCommand $command): MutationResult;

    public function revise(ReviseProductCommand $command): MutationResult;

    public function import(ImportProductsCommand $command): ProductImportResult;

    public function submit(ProductLifecycleCommand $command): MutationResult;

    public function approve(ProductLifecycleCommand $command): MutationResult;

    public function reject(ProductLifecycleCommand $command): MutationResult;

    public function activate(ProductLifecycleCommand $command): MutationResult;

    public function deactivate(ProductLifecycleCommand $command): MutationResult;

    public function archive(ProductLifecycleCommand $command): MutationResult;

    public function restore(ProductLifecycleCommand $command): MutationResult;

    public function createVariantUnit(CreateVariantUnitCommand $command): MutationResult;

    public function reviseVariantUnit(ReviseVariantUnitCommand $command): MutationResult;

    public function removeVariantUnit(VariantUnitLifecycleCommand $command): MutationResult;

    public function setBaseVariantUnit(VariantUnitLifecycleCommand $command): MutationResult;
}
