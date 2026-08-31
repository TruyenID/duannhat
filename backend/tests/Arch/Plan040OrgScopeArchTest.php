<?php

use App\Services\Omnify\MaterialBatchService;
use App\Services\Omnify\MaterialSubstitutionRuleService;
use App\Services\Omnify\ProductionOrderService;
use App\Services\Omnify\StockAlertService;
use App\Services\Omnify\StockCountService;
use App\Services\Omnify\StockTransactionService;
use App\Services\Omnify\StockTransferService;
use App\Services\Omnify\WarehouseService;

/**
 * plan-040 M9 (Arch) — every inventory Omnify service whose model carries
 * `organization_id` must override the generated base `list()` in the EDITABLE
 * layer (its own method or a trait), so the tenant boundary is never inherited
 * from the unscoped `*ServiceBase::list()`. (Pure reflection — no DB.)
 */
dataset('inventory_omnify_services', [
    MaterialBatchService::class,
    ProductionOrderService::class,
    StockAlertService::class,
    StockCountService::class,
    StockTransactionService::class,
    StockTransferService::class,
    WarehouseService::class,
    MaterialSubstitutionRuleService::class,
]);

it('overrides list() in the editable layer for org-scoped inventory services', function (string $serviceClass) {
    $declaring = (new ReflectionMethod($serviceClass, 'list'))->getDeclaringClass()->getName();

    expect($declaring)->not->toStartWith('App\\Omnify\\',
        "{$serviceClass}::list() is inherited from the generated base — it must be org-scoped in the editable layer.");
})->with('inventory_omnify_services');
