<?php

declare(strict_types=1);

it('keeps removed non-product Omnify services absent and unreferenced', function () {
    $generatedModules = [
        'AuditLog',
        'Branch',
        'BranchFloatingSectionOverride',
        'BranchReview',
        'BranchScheduleOverride',
        'Brand',
        'BrandOrderPolicy',
        'CouponRedemption',
        'Customer',
        'CustomerInvoice',
        'CustomerOrder',
        'CustomerOrderItem',
        'Device',
        'DisposalRecord',
        'ExpiryAlert',
        'FloatingSection',
        'FloatingSectionProduct',
        'FloatingSectionProductSku',
        'FloatingSectionSchedule',
        'GenealogyLink',
        'Material',
        'MaterialBatchItem',
        'MaterialLot',
        'MaterialLotReservation',
        'Menu',
        'MenuProduct',
        'MenuProductSku',
        'MenuProductToppingItemOverride',
        'MenuPromotion',
        'MenuSchedule',
        'MenuSection',
        'Notification',
        'NotificationAudience',
        'NotificationChannelRoute',
        'NotificationDelivery',
        'NotificationDigestPreference',
        'NotificationEmailSuppression',
        'NotificationPreference',
        'NotificationRecipient',
        'NotificationRule',
        'NotificationRuleFiring',
        'NotificationSchedule',
        'NotificationTemplate',
        'OrderCodeCounter',
        'OrderCondition',
        'OrderItemTopping',
        'OrderPayment',
        'Organization',
        'Post',
        'PostCategory',
        'PostTag',
        'ProductionOrderItem',
        'Product',
        'ProductReview',
        'Recall',
        'RecallAffectedOrder',
        'RecallDrill',
        'Recipe',
        'Role',
        'ShopOrderSetting',
        'StockCountItem',
        'StockLevel',
        'StockMovement',
        'StockTransactionItem',
        'StockTransferItem',
        'Table',
        'TableStatusChange',
        'TableTemplate',
        'TaxType',
        'Till',
        'TillCashDenominationCount',
        'TillCashEvent',
        'TillSession',
        'TillSettlementTenderDetail',
        'User',
        'WarehouseMember',
        'Zone',
        'ZoneTemplate',
    ];
    $compatibilityFacades = [
        'AuditLog',
        'Branch',
        'Brand',
        'Customer',
        'CustomerOrder',
        'CustomerOrderItem',
        'Device',
        'DisposalRecord',
        'Material',
        'MaterialBatchItem',
        'Menu',
        'MenuProduct',
        'MenuProductSku',
        'MenuSection',
        'Notification',
        'NotificationAudience',
        'NotificationChannelRoute',
        'NotificationDelivery',
        'NotificationPreference',
        'NotificationRecipient',
        'NotificationTemplate',
        'OrderPayment',
        'Organization',
        'Post',
        'PostCategory',
        'PostTag',
        'ProductionOrderItem',
        'Recipe',
        'Role',
        'ShopOrderSetting',
        'StockCountItem',
        'StockLevel',
        'StockMovement',
        'StockTransactionItem',
        'StockTransferItem',
        'Table',
        'TableStatusChange',
        'User',
        'WarehouseMember',
        'Zone',
    ];
    $deadServices = [
        ...array_map(
            static fn (string $module): string => "App\\Omnify\\Modules\\{$module}\\Services\\{$module}ServiceBase",
            $generatedModules,
        ),
        ...array_map(
            static fn (string $module): string => "App\\Services\\Omnify\\{$module}Service",
            $compatibilityFacades,
        ),
    ];

    // A class is "absent" only if its FILE is gone. `class_exists($c, false)`
    // was the whole absence check, and it cannot see an unloaded class — proven
    // empirically: DenominationServiceBase.php exists on disk and
    // class_exists(..., false) still returns false for it. So a regen that
    // resurrected one of these service bases would have slipped straight
    // through, since nothing loads a class that nothing references.
    //
    // The class check is kept beside the file check: a class declared from some
    // other path (a stray copy, a merged file) is just as much a resurrection.
    // PSR-4: App\Foo\Bar → app/Foo/Bar.php. No regex — the class strings are
    // backslash-heavy and a pattern here is all escaping and no clarity.
    $pathFor = static function (string $class): string {
        return base_path('app'.substr(str_replace('\\', '/', $class), 3).'.php');
    };

    $unexpectedClasses = array_values(array_filter(
        $deadServices,
        static fn (string $class): bool => class_exists($class, false) || is_file($pathFor($class)),
    ));
    $references = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'tests'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory)));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            foreach ($deadServices as $service) {
                if (is_string($source) && str_contains($source, $service)) {
                    $references[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).' -> '.$service;
                }
            }
        }
    }

    expect($unexpectedClasses)->toBe([])
        ->and($references)->toBe([]);
});
