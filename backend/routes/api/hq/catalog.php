<?php

use App\Http\Controllers\Api\V1\HQ\AllergenController;
use App\Http\Controllers\Api\V1\HQ\CategoryController;
use App\Http\Controllers\Api\V1\HQ\ProductController;
use App\Http\Controllers\Api\V1\HQ\ProductImageController;
use App\Http\Controllers\Api\V1\HQ\ProductOptionController;
use App\Http\Controllers\Api\V1\HQ\ProductOptionValueController;
use App\Http\Controllers\Api\V1\HQ\ProductSkuController;
use App\Http\Controllers\Api\V1\HQ\ProductSkuImageController;
use App\Http\Controllers\Api\V1\HQ\ProductToppingGroupController;
use App\Http\Controllers\Api\V1\HQ\ProductTypeController;
use App\Http\Controllers\Api\V1\HQ\TaxTypeController;
use App\Http\Controllers\Api\V1\HQ\ToppingGroupController;
use App\Http\Controllers\Api\V1\HQ\ToppingGroupItemController;
use App\Http\Controllers\Api\V1\HQ\ToppingGroupItemSkuController;
use App\Http\Controllers\Api\V1\HQ\VariantUnitController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  Product Types
// =========================================================================

Route::get('product-types/import/template', [ProductTypeController::class, 'importTemplate'])->name('api.v1.productTypes.importTemplate');
Route::post('product-types/import', [ProductTypeController::class, 'importCsv'])->name('api.v1.productTypes.import');
Route::get('product-types/export', [ProductTypeController::class, 'exportCsv'])->name('api.v1.productTypes.export');
Route::get('product-types/lookup', [ProductTypeController::class, 'lookup'])->name('api.v1.productTypes.lookup');
Route::get('product-types/dropdown', [ProductTypeController::class, 'lookup'])->name('api.v1.productTypes.dropdown'); // @deprecated — use /lookup
Route::post('product-types/bulk-delete', [ProductTypeController::class, 'bulkDelete'])->name('api.v1.productTypes.bulkDelete');
Route::post('product-types/{productType}/restore', [ProductTypeController::class, 'restore'])->name('api.v1.productTypes.restore');
Route::post('product-types/{productType}/toggle-status', [ProductTypeController::class, 'toggleStatus'])->name('api.v1.productTypes.toggleStatus');
Route::apiResource('product-types', ProductTypeController::class)->names('api.v1.productTypes');

// =========================================================================
//  Tax Types
// =========================================================================
//
// plan-043 — brand-scoped tax types. Cloned from the product-types block
// MINUS import/template/export (Decision 6: 3–5 rows per brand, seeded; CSV
// value is on products, not tax types). Named routes MUST precede apiResource
// so {taxType}=lookup|bulk-delete never collides.

Route::get('tax-types/lookup', [TaxTypeController::class, 'lookup'])->name('api.v1.taxTypes.lookup');
Route::post('tax-types/bulk-delete', [TaxTypeController::class, 'bulkDelete'])->name('api.v1.taxTypes.bulkDelete');
Route::post('tax-types/{taxType}/restore', [TaxTypeController::class, 'restore'])->name('api.v1.taxTypes.restore');
Route::post('tax-types/{taxType}/toggle-status', [TaxTypeController::class, 'toggleStatus'])->name('api.v1.taxTypes.toggleStatus');
Route::apiResource('tax-types', TaxTypeController::class)->names('api.v1.taxTypes');

// =========================================================================
//  Allergens
// =========================================================================

Route::post('allergens/{allergen}/restore', [AllergenController::class, 'restore'])->name('api.v1.allergens.restore')->withTrashed();
Route::post('allergens/bulk-delete', [AllergenController::class, 'bulkDelete'])->name('api.v1.allergens.bulkDelete');
Route::apiResource('allergens', AllergenController::class)->names('api.v1.allergens');

// =========================================================================
//  Categories
// =========================================================================

Route::get('categories/import/template', [CategoryController::class, 'importTemplate'])->name('api.v1.categories.importTemplate');
Route::post('categories/import', [CategoryController::class, 'importCsv'])->name('api.v1.categories.import');
Route::get('categories/export', [CategoryController::class, 'exportCsv'])->name('api.v1.categories.export');
Route::get('categories/lookup', [CategoryController::class, 'lookup'])->name('api.v1.categories.lookup');
Route::get('categories/dropdown', [CategoryController::class, 'lookup'])->name('api.v1.categories.dropdown'); // @deprecated — use /lookup
Route::post('categories/bulk-delete', [CategoryController::class, 'bulkDelete'])->name('api.v1.categories.bulkDelete');
Route::post('categories/{category}/restore', [CategoryController::class, 'restore'])->name('api.v1.categories.restore');
Route::post('categories/{category}/apply-tax-type', [CategoryController::class, 'applyTaxType'])->name('api.v1.categories.applyTaxType');
Route::apiResource('categories', CategoryController::class)->names('api.v1.categories');

// =========================================================================
//  Products
// =========================================================================

Route::prefix('products')->name('api.v1.products.')->group(function () {
    Route::get('import/template', [ProductController::class, 'importTemplate'])->name('importTemplate');
    Route::post('import', [ProductController::class, 'importCsv'])->name('import');
    Route::get('export', [ProductController::class, 'exportCsv'])->name('export');
    Route::get('lookup', [ProductController::class, 'lookup'])->name('lookup');
    Route::get('dropdown', [ProductController::class, 'lookup'])->name('dropdown'); // @deprecated — use /lookup
    Route::post('bulk-delete', [ProductController::class, 'bulkDelete'])->name('bulkDelete');

    Route::get('/', [ProductController::class, 'index'])->name('index');
    Route::post('/', [ProductController::class, 'store'])->name('store');
    Route::get('{product}', [ProductController::class, 'show'])->name('show');
    Route::put('{product}', [ProductController::class, 'update'])->name('update');
    Route::delete('{product}', [ProductController::class, 'destroy'])->name('destroy');
    Route::post('{product}/restore', [ProductController::class, 'restore'])->name('restore')->withTrashed();

    // Workflow
    Route::post('{product}/submit-for-approval', [ProductController::class, 'submitForApproval'])->name('submitForApproval');
    Route::post('{product}/approve', [ProductController::class, 'approve'])->name('approve');
    Route::post('{product}/reject', [ProductController::class, 'reject'])->name('reject');
    Route::post('{product}/activate', [ProductController::class, 'activate'])->name('activate');
    Route::post('{product}/deactivate', [ProductController::class, 'deactivate'])->name('deactivate');

    // Gallery images (nested)
    Route::post('{product}/images/sync', [ProductImageController::class, 'sync'])->name('images.sync');

    // SKUs (nested)
    Route::get('{product}/skus', [ProductSkuController::class, 'index'])->name('skus.index');
    Route::post('{product}/skus', [ProductSkuController::class, 'store'])->name('skus.store');
    Route::post('{product}/skus/generate-combinations', [ProductSkuController::class, 'generateCombinations'])
        ->name('skus.generateCombinations');

    // Options (nested under product)
    Route::get('{product}/options', [ProductOptionController::class, 'index'])->name('options.index');
    Route::post('{product}/options', [ProductOptionController::class, 'store'])->name('options.store');
    Route::post('{product}/options/expand', [ProductOptionController::class, 'expand'])->name('options.expand');
});

// =========================================================================
//  SKUs (standalone)
// =========================================================================

Route::prefix('skus')->name('api.v1.skus.')->group(function () {
    Route::get('import/template', [ProductSkuController::class, 'importTemplate'])->name('importTemplate');
    Route::post('import', [ProductSkuController::class, 'importCsv'])->name('import');
    Route::get('export', [ProductSkuController::class, 'exportCsv'])->name('export');
    Route::get('/', [ProductSkuController::class, 'indexAll'])->name('indexAll');
    Route::get('lookup', [ProductSkuController::class, 'lookup'])->name('lookup');
    Route::get('dropdown', [ProductSkuController::class, 'lookup'])->name('dropdown'); // @deprecated — use /lookup
    Route::get('{sku}', [ProductSkuController::class, 'show'])->name('show');
    Route::put('{sku}', [ProductSkuController::class, 'update'])->name('update');
    Route::delete('{sku}', [ProductSkuController::class, 'destroy'])->name('destroy');
    Route::post('{sku}/restore', [ProductSkuController::class, 'restore'])->name('restore');
    Route::post('{sku}/toggle-status', [ProductSkuController::class, 'toggleStatus'])->name('toggleStatus');
    Route::get('{sku}/check-usage', [ProductSkuController::class, 'checkUsage'])->name('checkUsage');

    // Gallery images (nested)
    Route::post('{sku}/images/sync', [ProductSkuImageController::class, 'sync'])->name('images.sync');
});

// =========================================================================
//  SKU Units
// =========================================================================

Route::prefix('sku-units')->name('api.v1.skuUnits.')->group(function () {
    Route::get('{unit}', [VariantUnitController::class, 'show'])->name('show');
    Route::put('{unit}', [VariantUnitController::class, 'update'])->name('update');
    Route::delete('{unit}', [VariantUnitController::class, 'destroy'])->name('destroy');
    Route::post('{unit}/set-base', [VariantUnitController::class, 'setBase'])->name('setBase');
});

// Nested under SKUs
Route::get('skus/{sku}/units', [VariantUnitController::class, 'index'])->name('api.v1.skus.units.index');
Route::post('skus/{sku}/units', [VariantUnitController::class, 'store'])->name('api.v1.skus.units.store');

// =========================================================================
//  Product Options (standalone)
// =========================================================================

Route::prefix('product-options')->name('api.v1.productOptions.')->group(function () {
    Route::get('{option}', [ProductOptionController::class, 'show'])->name('show');
    Route::put('{option}', [ProductOptionController::class, 'update'])->name('update');
    Route::put('{option}/sync-values', [ProductOptionController::class, 'syncValues'])->name('syncValues');
    Route::delete('{option}', [ProductOptionController::class, 'destroy'])->name('destroy');
});

// =========================================================================
//  Product Option Values
// =========================================================================

Route::prefix('product-option-values')->name('api.v1.productOptionValues.')->group(function () {
    Route::get('{value}', [ProductOptionValueController::class, 'show'])->name('show');
    Route::put('{value}', [ProductOptionValueController::class, 'update'])->name('update');
    Route::delete('{value}', [ProductOptionValueController::class, 'destroy'])->name('destroy');
});

// Nested under options
Route::get('product-options/{option}/values', [ProductOptionValueController::class, 'index'])->name('api.v1.productOptions.values.index');
Route::post('product-options/{option}/values', [ProductOptionValueController::class, 'store'])->name('api.v1.productOptions.values.store');

// =========================================================================
//  Topping Groups
// =========================================================================

// Named routes MUST be registered before apiResource to prevent {group}=lookup|restore conflicts
Route::get('topping-groups/lookup', [ToppingGroupController::class, 'lookup'])->name('api.v1.toppingGroups.lookup');
Route::put('topping-groups/reorder', [ToppingGroupController::class, 'reorder'])->name('api.v1.toppingGroups.reorder');
Route::post('topping-groups/bulk-delete', [ToppingGroupController::class, 'bulkDelete'])->name('api.v1.toppingGroups.bulkDelete');
Route::post('topping-groups/{group}/restore', [ToppingGroupController::class, 'restore'])->name('api.v1.toppingGroups.restore')->withTrashed();
Route::apiResource('topping-groups', ToppingGroupController::class)
    ->names('api.v1.toppingGroups')
    ->parameters(['topping-groups' => 'group']);

// Nested: Items
Route::put('topping-groups/{group}/items/reorder', [ToppingGroupItemController::class, 'reorder'])
    ->name('api.v1.toppingGroups.items.reorder');
// Full-sync the product panel in one save (add + reorder + remove). Declared
// before the apiResource so `items/sync` isn't captured as `items/{item}`.
Route::put('topping-groups/{group}/items/sync', [ToppingGroupItemController::class, 'sync'])
    ->name('api.v1.toppingGroups.items.sync');
Route::apiResource('topping-groups/{group}/items', ToppingGroupItemController::class)
    ->except(['show'])
    ->parameters(['items' => 'item'])
    ->names('api.v1.toppingGroups.items');

// Nested: Item SKUs
Route::apiResource('topping-groups/{group}/items/{item}/skus', ToppingGroupItemSkuController::class)
    ->except(['show'])
    ->parameters(['skus' => 'itemSku'])
    ->names('api.v1.toppingGroups.itemSkus');

// Product ↔ Topping Group assignment
Route::get('products/{product}/topping-groups', [ProductToppingGroupController::class, 'index'])->name('api.v1.products.toppingGroups.index');
Route::post('products/{product}/topping-groups/sync', [ProductToppingGroupController::class, 'sync'])->name('api.v1.products.toppingGroups.sync');

// Per-product item overrides — ⚠️ {group} here is ToppingGroup UUID, not conflicting with topping-groups/{group} above
Route::get('products/{product}/topping-groups/{group}/overrides', [ProductToppingGroupController::class, 'listOverrides'])->name('api.v1.products.toppingGroups.overrides.index');
Route::put('products/{product}/topping-groups/{group}/overrides/sync', [ProductToppingGroupController::class, 'syncOverrides'])->name('api.v1.products.toppingGroups.overrides.sync');
