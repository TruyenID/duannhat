<?php

use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Services\Customer\Commands\ChangeCustomerCredentialsCommand;
use App\Services\Customer\Commands\MergeCustomersCommand;
use App\Services\Customer\Commands\RegisterCustomerCommand;
use App\Services\Customer\Commands\ReviseGlobalCustomerProfileCommand;
use App\Services\Customer\Contracts\CustomerMutationFacade;
use App\Services\Customer\Contracts\CustomerPersistencePort;
use App\Services\Customer\Contracts\CustomerQueryPort;
use App\Services\Customer\Results\CustomerMergeResult;
use App\Services\Customer\ValueObjects\CustomerCredentialPayload;
use App\Services\Customer\ValueObjects\CustomerLinkagePayload;
use App\Services\Customer\ValueObjects\CustomerVerificationPayload;
use App\Services\Customer\ValueObjects\GlobalCustomerProfilePatch;
use App\Services\Customer\ValueObjects\OptionalProfileField;
use App\Services\Customer\ValueObjects\TenantCustomerProfilePayload;
use App\Services\Customer\ValueObjects\VerifiedCustomerMutation;
use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\DomainMutation\MutationResult;
use App\Services\Menu\Commands\ApplyShopMenuOverrideCommand;
use App\Services\Menu\Commands\CreateMenuCommand;
use App\Services\Menu\Commands\ReplaceMenuLayoutCommand;
use App\Services\Menu\Commands\ReviseMenuCommand;
use App\Services\Menu\Contracts\MenuMutationFacade;
use App\Services\Menu\Contracts\MenuPersistencePort;
use App\Services\Menu\Contracts\MenuQueryPort;
use App\Services\Menu\Enums\MenuLayoutMutation;
use App\Services\Menu\ValueObjects\MenuDefinitionPayload;
use App\Services\Menu\ValueObjects\MenuItemPayload;
use App\Services\Menu\ValueObjects\MenuLayoutPayload;
use App\Services\Menu\ValueObjects\MenuSchedulePayload;
use App\Services\Menu\ValueObjects\MenuSkuOverridePayload;
use App\Services\Menu\ValueObjects\MenuToppingOverridePayload;
use App\Services\Menu\ValueObjects\ShopMenuOverridePayload;
use App\Services\Order\Commands\ChangeOrderItemsCommand;
use App\Services\Order\Commands\CheckoutOrderCommand;
use App\Services\Order\Commands\CreateOrderCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Contracts\OrderPersistencePort;
use App\Services\Order\Contracts\OrderQueryPort;
use App\Services\Order\Enums\OrderItemMutation;
use App\Services\Order\Results\OrderCreatedResult;
use App\Services\Order\Results\OrderSettlementResult;
use App\Services\Order\ValueObjects\OrderLineEvidence;
use App\Services\Order\ValueObjects\OrderLinePayload;
use App\Services\Order\ValueObjects\OrderLineSelectionPayload;
use App\Services\Order\ValueObjects\OrderPricingEvidence;
use App\Services\Order\ValueObjects\OrderSelectionPayload;
use App\Services\Order\ValueObjects\OrderTableSetPayload;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\Results\GatewayRefundResult;
use App\Services\Payment\Gateway\Results\VerifiedGatewayEvent;
use App\Services\Payment\Orchestration\Commands\FinalizePaymentCommand;
use App\Services\Payment\Orchestration\Commands\PreparePaymentCommand;
use App\Services\Payment\Orchestration\Commands\ProcessProviderEventCommand;
use App\Services\Payment\Orchestration\Commands\ReconcilePaymentCommand;
use App\Services\Payment\Orchestration\Commands\ReconcilePaymentRefundCommand;
use App\Services\Payment\Orchestration\Commands\RecordPaymentTenderCommand;
use App\Services\Payment\Orchestration\Commands\RequestPaymentRefundCommand;
use App\Services\Payment\Orchestration\Contracts\PaymentMutationFacade;
use App\Services\Payment\Orchestration\Contracts\PaymentPersistencePort;
use App\Services\Payment\Orchestration\Contracts\PaymentQueryPort;
use App\Services\Payment\Orchestration\Results\PaymentFinalizeResult;
use App\Services\Payment\Orchestration\Results\PaymentPrepareResult;
use App\Services\Payment\Orchestration\Results\PaymentRefundResult;
use App\Services\Payment\Orchestration\Results\ProviderEventResult;
use App\Services\Payment\Orchestration\ValueObjects\PaymentTenderPayload;
use App\Services\Payment\Orchestration\ValueObjects\RefundRequestPayload;
use App\Services\Payment\Orchestration\ValueObjects\VerifiedRefundIntent;
use App\Services\Product\Commands\CreateProductCommand;
use App\Services\Product\Commands\ImportProductsCommand;
use App\Services\Product\Commands\ReviseProductCommand;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\Contracts\ProductPersistencePort;
use App\Services\Product\Contracts\ProductQueryPort;
use App\Services\Product\Results\ProductImportResult;
use App\Services\Product\ValueObjects\ProductImportPayload;
use App\Services\Product\ValueObjects\ProductOptionPayload;
use App\Services\Product\ValueObjects\ProductOptionValuePayload;
use App\Services\Product\ValueObjects\ProductPayload;
use App\Services\Product\ValueObjects\ProductSkuPayload;
use App\Services\Product\ValueObjects\ProductToppingGroupPayload;
use App\Services\Product\ValueObjects\ProductToppingItemOverridePayload;

function publicMethodNames(string $contract): array
{
    $names = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass($contract))->getMethods(ReflectionMethod::IS_PUBLIC),
    );
    sort($names);

    return $names;
}

dataset('canonical mutation facades', [
    'order' => [OrderMutationFacade::class, ['advanceKitchenItem', 'applyCoupon', 'applyWorkstationCoupon', 'approveItemRefund', 'assignTableSession', 'beginPaying', 'bindCoupon', 'bumpKitchenItemStatus', 'cancel', 'changeItems', 'changeItemsBatch', 'changeSplitMode', 'changeTable', 'checkout', 'checkoutWorkstationOrder', 'claimGuestOrders', 'close', 'commitConfirmation', 'confirm', 'continueTable', 'create', 'downgradeExclusivePromotions', 'expire', 'ghostCreateWorkstationItem', 'initialize', 'mergeTables', 'patchWorkstationItem', 'patchWorkstationOrder', 'promoteForPayment', 'refreshPaymentCache', 'refreshPricing', 'releaseWorkstationCoupon', 'removeCoupon', 'removeItem', 'reopen', 'replayOffline', 'revertKitchenItem', 'reviseHeader', 'runKitchenBatch', 'setStaffEditLock', 'settleIfPaid', 'softDeleteWorkstationItem', 'softDeleteWorkstationOrder', 'stampKitchenTimestamp', 'stampStripeIntent', 'syncWorkstationItems', 'unmergeTable', 'void', 'voidAwaitingConfirmation', 'voidKitchenItem', 'voidWorkstationItem', 'voidWorkstationOrder']],
    'product' => [ProductMutationFacade::class, ['activate', 'approve', 'archive', 'archiveCategory', 'archiveOption', 'archiveOptionValue', 'archiveProductType', 'archiveSku', 'assignCategoryTaxType', 'create', 'createCategory', 'createOption', 'createOptionValue', 'createProductType', 'createSku', 'createVariantUnit', 'deactivate', 'expandOption', 'generateSkuCombinations', 'import', 'reject', 'removeVariantUnit', 'restore', 'restoreCategory', 'restoreProductType', 'restoreSku', 'revise', 'reviseCategory', 'reviseOption', 'reviseOptionValue', 'reviseProductType', 'reviseSku', 'reviseVariantUnit', 'setBaseVariantUnit', 'submit', 'syncOptionValues', 'toggleProductTypeStatus', 'toggleSkuStatus']],
    'menu' => [MenuMutationFacade::class, ['activate', 'applyShopOverride', 'approve', 'archive', 'backfillSkuPlacements', 'clearShopOverride', 'cloneFloatingSectionToBranch', 'cloneToBranch', 'create', 'createFloatingSchedule', 'createFloatingSection', 'createSchedule', 'createSection', 'deactivate', 'deleteSchedule', 'duplicateFloatingSection', 'duplicateStandalone', 'overrideFloatingScheduleTime', 'overrideFloatingSkuPrice', 'placeFloatingProduct', 'placeProduct', 'promoteApprovedMenus', 'reject', 'removeFloatingProduct', 'removeFloatingSchedule', 'removeFloatingSection', 'removeProduct', 'removeSection', 'reorderFloatingProducts', 'reorderFloatingSchedules', 'reorderLayout', 'reorderProducts', 'reorderSections', 'replaceLayout', 'resetBranchScheduleOverride', 'resetFloatingScheduleTime', 'resetFloatingSkuPrice', 'resetSkuPrice', 'restore', 'revise', 'reviseFloatingSchedule', 'reviseFloatingSection', 'reviseSection', 'submit', 'syncFloatingSectionFromMaster', 'syncFromMaster', 'syncToppings', 'toggleFloatingProduct', 'toggleFloatingSchedule', 'toggleFloatingSku', 'toggleProduct', 'toggleSku', 'updateSchedule', 'upsertBranchScheduleOverride']],
    'customer' => [CustomerMutationFacade::class, ['archive', 'changeCredentials', 'findOrCreate', 'issueAccessToken', 'linkScope', 'login', 'merge', 'register', 'registerAccount', 'restore', 'reviseGlobalProfile', 'reviseTenantProfile', 'revokeAccessToken', 'unlinkScope', 'verifyEmail']],
    'payment' => [PaymentMutationFacade::class, ['applyInboxEvent', 'finalize', 'prepare', 'processProviderEvent', 'reconcile', 'reconcileRefund', 'recordTender', 'requestRefund']],
]);

dataset('canonical boundary triplets', [
    'order' => [OrderMutationFacade::class, OrderPersistencePort::class, OrderQueryPort::class],
    'product' => [ProductMutationFacade::class, ProductPersistencePort::class, ProductQueryPort::class],
    'menu' => [MenuMutationFacade::class, MenuPersistencePort::class, MenuQueryPort::class],
    'customer' => [CustomerMutationFacade::class, CustomerPersistencePort::class, CustomerQueryPort::class],
    'payment' => [PaymentMutationFacade::class, PaymentPersistencePort::class, PaymentQueryPort::class],
]);

dataset('operation-specific facade results', [
    'order create' => [OrderMutationFacade::class, 'create', OrderCreatedResult::class],
    'order settlement' => [OrderMutationFacade::class, 'settleIfPaid', OrderSettlementResult::class],
    'product import' => [ProductMutationFacade::class, 'import', ProductImportResult::class],
    'customer merge' => [CustomerMutationFacade::class, 'merge', CustomerMergeResult::class],
    'payment prepare' => [PaymentMutationFacade::class, 'prepare', PaymentPrepareResult::class],
    'payment finalize' => [PaymentMutationFacade::class, 'finalize', PaymentFinalizeResult::class],
    'payment tender' => [PaymentMutationFacade::class, 'recordTender', PaymentFinalizeResult::class],
    'payment reconcile' => [PaymentMutationFacade::class, 'reconcile', PaymentFinalizeResult::class],
    'payment refund request' => [PaymentMutationFacade::class, 'requestRefund', PaymentRefundResult::class],
    'payment refund reconcile' => [PaymentMutationFacade::class, 'reconcileRefund', PaymentRefundResult::class],
    'provider event' => [PaymentMutationFacade::class, 'processProviderEvent', ProviderEventResult::class],
]);

dataset('self-contained payload commands', [
    CreateProductCommand::class => [CreateProductCommand::class, ProductPayload::class],
    ReviseProductCommand::class => [ReviseProductCommand::class, ProductPayload::class],
    ImportProductsCommand::class => [ImportProductsCommand::class, ProductImportPayload::class],
    CreateMenuCommand::class => [CreateMenuCommand::class, MenuDefinitionPayload::class],
    ReviseMenuCommand::class => [ReviseMenuCommand::class, MenuDefinitionPayload::class],
    ReplaceMenuLayoutCommand::class => [ReplaceMenuLayoutCommand::class, MenuLayoutPayload::class],
    ApplyShopMenuOverrideCommand::class => [ApplyShopMenuOverrideCommand::class, ShopMenuOverridePayload::class],
    RegisterCustomerCommand::class => [RegisterCustomerCommand::class, TenantCustomerProfilePayload::class],
    ReviseGlobalCustomerProfileCommand::class => [ReviseGlobalCustomerProfileCommand::class, GlobalCustomerProfilePatch::class],
    ChangeCustomerCredentialsCommand::class => [ChangeCustomerCredentialsCommand::class, CustomerCredentialPayload::class],
    CreateOrderCommand::class => [CreateOrderCommand::class, OrderSelectionPayload::class],
    ChangeOrderItemsCommand::class => [ChangeOrderItemsCommand::class, OrderLineSelectionPayload::class],
]);

dataset('self-contained payment evidence commands', [
    FinalizePaymentCommand::class => [FinalizePaymentCommand::class, 'evidence', GatewayPaymentResult::class],
    ReconcilePaymentCommand::class => [ReconcilePaymentCommand::class, 'evidence', GatewayPaymentResult::class],
    ReconcilePaymentRefundCommand::class => [ReconcilePaymentRefundCommand::class, 'evidence', GatewayRefundResult::class],
    ProcessProviderEventCommand::class => [ProcessProviderEventCommand::class, 'event', VerifiedGatewayEvent::class],
    RequestPaymentRefundCommand::class => [RequestPaymentRefundCommand::class, 'payload', RefundRequestPayload::class],
]);

it('threads typed tender and untrusted refund intent through public payment commands', function () {
    $prepareTender = (new ReflectionClass(PreparePaymentCommand::class))->getProperty('tender')->getType();
    $refundIntent = (new ReflectionClass(RequestPaymentRefundCommand::class))->getProperty('payload')->getType();

    expect($prepareTender)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($prepareTender->getName())->toBe(PaymentTenderPayload::class)
        ->and($refundIntent)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($refundIntent->allowsNull())->toBeFalse()
        ->and($refundIntent->getName())->toBe(RefundRequestPayload::class)
        ->and((new ReflectionClass(RequestPaymentRefundCommand::class))->hasProperty('authorizationReference'))->toBeTrue()
        ->and((new ReflectionClass(RequestPaymentRefundCommand::class))->hasProperty('requestFingerprint'))->toBeTrue();

    $recordTender = (new ReflectionClass(RecordPaymentTenderCommand::class))->getProperty('tender')->getType();
    expect($recordTender)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($recordTender->getName())->toBe(PaymentTenderPayload::class);
});

dataset('current mutation inventory parity', [
    'product root' => [ProductPayload::class, ['slug', 'translations', 'productTypeId', 'taxTypeId', 'thumbnailFileId', 'galleryFileIds', 'toppingGroups', 'options', 'skus']],
    'product sku' => [ProductSkuPayload::class, ['name', 'optionValueIds', 'recipeId', 'recipeMultiplier', 'costPrice', 'costPriceAuto', 'costOverride', 'sellingPrice', 'inventoryMode', 'translations', 'galleryFileIds']],
    'product option' => [ProductOptionPayload::class, ['key', 'name', 'position', 'active', 'translations', 'values']],
    'product option value' => [ProductOptionValuePayload::class, ['value', 'label', 'position', 'active', 'translations']],
    'product topping' => [ProductToppingGroupPayload::class, ['toppingGroupId', 'skuId', 'position', 'active', 'minimumSelections', 'maximumSelections', 'itemOverrides']],
    'product topping item override' => [ProductToppingItemOverridePayload::class, ['itemId', 'skuId', 'hidden', 'priceOverrideMinor']],
    'menu root' => [MenuDefinitionPayload::class, ['validFrom', 'validTo', 'priority', 'cartTimeoutMinutes', 'serviceType', 'master', 'masterMenuId', 'transitionGraceMinutes', 'translations', 'layout', 'schedules']],
    'menu schedule' => [MenuSchedulePayload::class, ['startDate', 'endDate', 'daysOfWeek', 'startTime', 'endTime', 'active', 'priority', 'masterScheduleId']],
    'menu product' => [MenuItemPayload::class, ['productId', 'skuId', 'taxTypeId', 'active', 'position', 'masterMenuProductId', 'skuOverrides', 'toppingOverrides']],
    'menu sku price' => [MenuSkuOverridePayload::class, ['skuId', 'sellingPriceMinor', 'priceOverridden', 'active']],
    'menu topping override' => [MenuToppingOverridePayload::class, ['toppingGroupId', 'itemId', 'skuId', 'hidden', 'priceOverrideMinor']],
    'menu shop override' => [ShopMenuOverridePayload::class, ['branchId', 'menuItemId', 'visible', 'priceOverrideMinor', 'taxTypeId', 'translations', 'skuOverrides', 'locale']],
    'order header' => [OrderSelectionPayload::class, ['orderType', 'pickupType', 'scheduledPickupAt', 'contact', 'customerId', 'guestCount', 'tableIds', 'locale', 'channel', 'deviceId', 'couponCode', 'splitMode', 'splitPeopleCount']],
    'order totals' => [OrderPricingEvidence::class, ['subtotalMinor', 'discountMinor', 'serviceChargeMinor', 'taxMinor', 'totalMinor', 'taxIncluded', 'taxRoundingMode', 'taxRoundingDecimals']],
    'order price evidence' => [OrderLineEvidence::class, ['menuId', 'menuProductId', 'menuProductSkuId', 'originalUnitPriceMinor', 'taxTypeId', 'taxRateBasisPoints', 'taxAmountMinor', 'promotionId', 'promotionCode', 'promotionDiscountMinor', 'lineSubtotalMinor', 'toppingSubtotalMinor']],
    'order kitchen line' => [OrderLinePayload::class, ['status', 'startedPreparingAt', 'readyAt', 'servedAt', 'voidedAt', 'voidReason', 'refundedQuantity', 'evidence']],
    'order multi table' => [OrderTableSetPayload::class, ['tableIds', 'tableSessionId']],
    'payment tender' => [PaymentTenderPayload::class, ['paymentMethodId', 'method', 'tipMinor', 'tenderedMinor', 'reference', 'tillSessionId', 'obligation', 'allocationId', 'debtPaymentId']],
    'verified refund intent' => [VerifiedRefundIntent::class, ['orderId', 'orderPaymentId', 'attemptId', 'amountMinor', 'currencyCode', 'reason', 'providerPaymentReference', 'providerConnectionIdentity', 'verification', 'requestFingerprint']],
    'tenant customer profile' => [TenantCustomerProfilePayload::class, ['address', 'taxCode', 'note']],
    'customer verification' => [CustomerVerificationPayload::class, ['emailVerifiedAt', 'verificationEventId', 'source']],
    'customer linkage' => [CustomerLinkagePayload::class, ['brandId', 'branchId']],
]);

it('covers every current writer inventory field with an explicit typed shape', function (string $type, array $properties) {
    $reflection = new ReflectionClass($type);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();

    foreach ($properties as $property) {
        expect($reflection->hasProperty($property))->toBeTrue("{$type} is missing {$property}");
        $declaredType = $reflection->getProperty($property)->getType();
        expect($declaredType)->not->toBeNull("{$type}::\${$property} must be typed");
    }
})->with('current mutation inventory parity');

it('publishes only explicit typed operations on each canonical mutation facade', function (string $facade, array $expectedMethods) {
    $reflection = new ReflectionClass($facade);

    expect($reflection->isInterface())->toBeTrue()
        ->and(publicMethodNames($facade))->toBe($expectedMethods);

    foreach ($reflection->getMethods() as $method) {
        $parameters = $method->getParameters();
        $returnType = $method->getReturnType();

        expect($parameters)->toHaveCount(1)
            ->and($parameters[0]->getType())->toBeInstanceOf(ReflectionNamedType::class)
            ->and(is_subclass_of($parameters[0]->getType()->getName(), MutationCommand::class) || $parameters[0]->getType()->getName() === VerifiedCustomerMutation::class)->toBeTrue()
            ->and($returnType)->toBeInstanceOf(ReflectionNamedType::class);

        $result = new ReflectionClass($returnType->getName());
        expect($result->isFinal())->toBeTrue()
            ->and($result->isReadOnly())->toBeTrue();
    }
})->with('canonical mutation facades');

it('keeps mutation, persistence, and query contracts separate', function (string $facade, string $persistence, string $query) {
    foreach ([$facade, $persistence, $query] as $contract) {
        expect((new ReflectionClass($contract))->isInterface())->toBeTrue();
    }

    $mutationNames = publicMethodNames($facade);
    $persistenceNames = publicMethodNames($persistence);
    $queryNames = publicMethodNames($query);
    $forbidden = ['delete', 'forceState', 'mutate', 'save', 'setStatus', 'transition', 'update'];

    expect(array_intersect($mutationNames, $forbidden))->toBe([])
        ->and(array_intersect($persistenceNames, $forbidden))->toBe([])
        ->and(array_intersect($queryNames, array_merge($mutationNames, $persistenceNames)))->toBe([]);

    foreach ((new ReflectionClass($persistence))->getMethods() as $method) {
        $parameters = $method->getParameters();
        $returnType = $method->getReturnType();

        expect($parameters)->toHaveCount(1)
            ->and($parameters[0]->getType())->toBeInstanceOf(ReflectionNamedType::class)
            ->and(
                is_subclass_of($parameters[0]->getType()->getName(), MutationCommand::class)
                || $parameters[0]->getType()->getName() === VerifiedCustomerMutation::class
            )->toBeTrue()
            ->and($returnType)->toBeInstanceOf(ReflectionNamedType::class);

        $result = new ReflectionClass($returnType->getName());
        expect($result->isFinal())->toBeTrue()
            ->and($result->isReadOnly())->toBeTrue();
    }

    foreach ((new ReflectionClass($query))->getMethods() as $method) {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            expect($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), MutationCommand::class))->toBeFalse();
        }
    }
})->with('canonical boundary triplets');

it('uses operation-specific results where mutation semantics differ', function (string $facade, string $method, string $expectedResult) {
    $returnType = (new ReflectionMethod($facade, $method))->getReturnType();

    expect($returnType)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($returnType->getName())->toBe($expectedResult)
        ->and($expectedResult)->not->toBe(MutationResult::class);
})->with('operation-specific facade results');

it('makes payload-bearing commands executable without generic or out-of-band data', function (string $command, string $payload) {
    $commandReflection = new ReflectionClass($command);
    $property = $commandReflection->getProperty('payload');
    $type = $property->getType();
    $payloadReflection = new ReflectionClass($payload);

    expect($type)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($type->allowsNull())->toBeFalse()
        ->and($type->getName())->toBe($payload)
        ->and($payloadReflection->isFinal())->toBeTrue()
        ->and($payloadReflection->isReadOnly() || $payload === CustomerCredentialPayload::class)->toBeTrue();

    if ($payload !== CustomerCredentialPayload::class) {
        expect($payloadReflection->implementsInterface(CanonicalMutationPayload::class))->toBeTrue();
    }

    foreach ($commandReflection->getProperties(ReflectionProperty::IS_PUBLIC) as $commandProperty) {
        $propertyType = $commandProperty->getType();
        expect($propertyType instanceof ReflectionNamedType && in_array($propertyType->getName(), ['array', 'mixed'], true))->toBeFalse();
    }
})->with('self-contained payload commands');

it('carries normalized provider-neutral evidence needed to persist payment mutations', function (string $command, string $propertyName, string $evidence) {
    $propertyType = (new ReflectionClass($command))->getProperty($propertyName)->getType();

    expect($propertyType)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($propertyType->allowsNull())->toBeFalse()
        ->and($propertyType->getName())->toBe($evidence)
        ->and((new ReflectionClass($evidence))->isFinal())->toBeTrue()
        ->and((new ReflectionClass($evidence))->isReadOnly())->toBeTrue();
})->with('self-contained payment evidence commands');

it('uses concrete immutable command value objects with validated mutation identity', function () {
    $backend = dirname(__DIR__, 3);
    $files = [];

    foreach (['Customer/Commands', 'Menu/Commands', 'Order/Commands', 'Payment/Orchestration/Commands', 'Product/Commands'] as $directory) {
        $files = [...$files, ...glob("{$backend}/app/Services/{$directory}/*Command.php")];
    }

    foreach ($files as $file) {
        $source = file_get_contents($file);
        preg_match('/namespace\s+([^;]+);/', $source, $namespace);
        preg_match('/final readonly class\s+(\w+)/', $source, $class);
        $className = $namespace[1].'\\'.$class[1];
        $reflection = new ReflectionClass($className);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->isSubclassOf(MutationCommand::class))->toBeTrue();
    }

    expect($files)->not->toBeEmpty();
});

it('does not advertise optimistic concurrency for product commands without persisted CAS', function () {
    $context = new MutationContext(
        '00000000-0000-4000-8000-000000000001',
        null,
        'correlation-1',
        'idempotency-1',
    );

    $payload = new ProductPayload('Coffee', null, []);

    $command = new ReviseProductCommand($context, '00000000-0000-4000-8000-000000000002', '00000000-0000-4000-8000-000000000003', $payload, $payload->fingerprint());

    expect($command->context->expectedVersion)->toBeNull();
});

it('derives deterministic canonical fingerprints and rejects payload mismatch in every domain', function () {
    $context = new MutationContext(
        '00000000-0000-4000-8000-000000000001',
        null,
        'correlation-1',
        'idempotency-1',
        1,
    );
    $aggregateId = '00000000-0000-4000-8000-000000000002';
    $product = new ProductPayload('Coffee', null, [], categoryIds: [
        '00000000-0000-4000-8000-000000000011',
        '00000000-0000-4000-8000-000000000010',
    ]);
    $sameProduct = new ProductPayload('Coffee', null, [], categoryIds: [
        '00000000-0000-4000-8000-000000000010',
        '00000000-0000-4000-8000-000000000011',
    ]);
    $menu = new MenuLayoutPayload([]);
    $omitted = OptionalProfileField::omitted();
    $customer = new GlobalCustomerProfilePatch(OptionalProfileField::replace('Ada'), $omitted, $omitted, $omitted, $omitted, $omitted);
    $line = new OrderLineSelectionPayload('00000000-0000-4000-8000-000000000003', '00000000-0000-4000-8000-000000000004', 1);

    expect($product->canonicalJson())->toBe($sameProduct->canonicalJson())
        ->and($product->fingerprint())->toBe($sameProduct->fingerprint())
        ->and(fn () => new ReviseProductCommand($context, $aggregateId, '00000000-0000-4000-8000-000000000099', $product, str_repeat('a', 64)))
        ->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => new ReplaceMenuLayoutCommand($context, $aggregateId, $menu, str_repeat('a', 64), MenuLayoutMutation::ReplaceLayout))
        ->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => new ReviseGlobalCustomerProfileCommand(new MutationContext(null, $aggregateId, 'correlation-1', 'idempotency-1'), $aggregateId, $customer, str_repeat('a', 64), 'session-1'))
        ->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => new ChangeOrderItemsCommand(
            $context,
            $aggregateId,
            OrderItemMutation::Add,
            str_repeat('a', 64),
            $line,
        ))->toThrow(InvalidArgumentException::class, 'does not match');
});

it('verifies every non-secret command payload fingerprint at construction', function () {
    $backend = dirname(__DIR__, 3);
    $commands = [
        'Product/Commands/CreateProductCommand.php',
        'Product/Commands/ReviseProductCommand.php',
        'Product/Commands/ImportProductsCommand.php',
        'Menu/Commands/CreateMenuCommand.php',
        'Menu/Commands/ReviseMenuCommand.php',
        'Menu/Commands/ReplaceMenuLayoutCommand.php',
        'Menu/Commands/ApplyShopMenuOverrideCommand.php',
        'Customer/Commands/RegisterCustomerCommand.php',
        'Customer/Commands/ReviseGlobalCustomerProfileCommand.php',
        'Order/Commands/CreateOrderCommand.php',
        'Order/Commands/ChangeOrderItemsCommand.php',
    ];

    foreach ($commands as $command) {
        expect(file_get_contents("{$backend}/app/Services/{$command}"))->toContain('verifiedFingerprint(');
    }
});

it('requires revision identity for lifecycle and payment-finalization commands', function (string $command) {
    $context = new MutationContext(
        '00000000-0000-4000-8000-000000000001',
        null,
        'correlation-1',
        'idempotency-1',
    );
    $aggregateId = '00000000-0000-4000-8000-000000000002';

    $factory = $command === CheckoutOrderCommand::class
        ? fn () => new CheckoutOrderCommand($context, $aggregateId)
        : fn () => new FinalizePaymentCommand(
            $context,
            $aggregateId,
            new GatewayPaymentResult(PaymentAttemptStateEnum::Failed, 'failed'),
        );

    expect($factory)->toThrow(InvalidArgumentException::class, 'expectedVersion');
})->with([
    CheckoutOrderCommand::class,
    FinalizePaymentCommand::class,
]);

it('validates payment invariants and does not serialize the raw idempotency key', function () {
    $context = new MutationContext(
        '00000000-0000-4000-8000-000000000001',
        '00000000-0000-4000-8000-000000000002',
        'correlation-1',
        'raw-idempotency-key',
        1,
    );

    expect(json_encode($context, JSON_THROW_ON_ERROR))->not->toContain('raw-idempotency-key')
        ->and(fn () => new PreparePaymentCommand(
            $context,
            '00000000-0000-4000-8000-000000000003',
            '00000000-0000-4000-8000-000000000004',
            '00000000-0000-4000-8000-000000000005',
            '00000000-0000-4000-8000-000000000006',
            '00000000-0000-4000-8000-000000000007',
            0,
            'JPY',
            1,
            0,
            1,
            authorizationReference: 'policy-auth-1',
        ))->toThrow(InvalidArgumentException::class);
});

it('rejects merging a customer into the same identity', function () {
    $context = new MutationContext(
        '00000000-0000-4000-8000-000000000001',
        null,
        'correlation-1',
        'idempotency-1',
        1,
    );
    $customerId = '00000000-0000-4000-8000-000000000002';

    expect(fn () => new MergeCustomersCommand($context, $customerId, $customerId, 'merge-auth-1'))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps credential material process-local and absent from serialization and debug output', function () {
    $payload = new CustomerCredentialPayload('never-log-this-password');
    $context = new MutationContext(
        null,
        '00000000-0000-4000-8000-000000000002',
        'correlation-1',
        'idempotency-1',
        1,
    );
    $command = new ChangeCustomerCredentialsCommand(
        $context,
        '00000000-0000-4000-8000-000000000002',
        'password',
        $payload,
    );

    ob_start();
    var_dump($command);
    $debug = ob_get_clean();

    $reusableDigest = hash('sha256', 'never-log-this-password');
    $otherCommand = new ChangeCustomerCredentialsCommand(
        $context,
        '00000000-0000-4000-8000-000000000002',
        'password',
        new CustomerCredentialPayload('different-never-log-password'),
    );

    expect($payload->reveal())->toBe('never-log-this-password')
        ->and($debug)->not->toContain('never-log-this-password')
        ->and($debug)->not->toContain($reusableDigest)
        ->and((new ReflectionClass($command))->hasProperty('credentialFingerprint'))->toBeFalse()
        ->and($command->mutationFingerprint())->not->toBe($otherCommand->mutationFingerprint())
        ->and($command->mutationFingerprint())->not->toContain($reusableDigest)
        ->and(fn () => serialize($command))->toThrow(LogicException::class);
});

it('fails closed when canonical mutation identity encounters unsupported mixed public and private state', function () {
    $context = new MutationContext(null, '00000000-0000-4000-8000-000000000002', 'correlation-1', 'idempotency-1', 1);
    $command = new readonly class($context, new class
    {
        public string $visible = 'partial';

        private string $hidden = 'unsupported';
    }) extends MutationCommand
    {

        public function __construct(MutationContext $context, public object $opaque)
        {
            parent::__construct($context);
        }
    };

    expect(fn () => $command->mutationFingerprint())->toThrow(LogicException::class, 'without explicit canonical mutation identity');
});

it('keeps framework, model, provider SDK, and cross-domain persistence types out of boundaries', function () {
    $backend = dirname(__DIR__, 3);
    $directories = [
        'app/Services/Customer/Commands',
        'app/Services/Customer/Contracts',
        'app/Services/Customer/Results',
        'app/Services/Customer/ValueObjects',
        'app/Services/DomainMutation',
        'app/Services/Menu/Commands',
        'app/Services/Menu/Contracts',
        'app/Services/Menu/ValueObjects',
        'app/Services/Order/Commands',
        'app/Services/Order/Contracts',
        'app/Services/Order/Results',
        'app/Services/Order/ValueObjects',
        'app/Services/Payment/Orchestration/Commands',
        'app/Services/Payment/Orchestration/Contracts',
        'app/Services/Payment/Orchestration/Results',
        'app/Services/Product/Commands',
        'app/Services/Product/Contracts',
        'app/Services/Product/Results',
        'app/Services/Product/ValueObjects',
    ];

    /*
     * #962 — quét MÃ, không quét văn xuôi.
     *
     * Bài này từng `file_get_contents` rồi so regex trên nguyên văn bản, nên một
     * docblock GIẢI THÍCH vì sao `App\Models\TaxType` KHÔNG được xuất hiện trong
     * chữ ký cũng làm nó đỏ. Đó là hình phạt đặt đúng vào hành vi mà nó muốn
     * khuyến khích: cổng nào ghi rõ lý do ranh giới thì bị phạt, cổng nào im lặng
     * thì qua. Đo được: 7 file đỏ, cả 7 đều chỉ nhắc tên trong lời giải thích.
     *
     * `token_get_all` bỏ comment và docblock rồi mới so — `use`, type hint, tên
     * lớp trong mã vẫn bị bắt y như trước, nên rào KHÔNG bị nới.
     */
    $stripComments = static function (string $source): string {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    };

    /*
     * #962 — MIỄN TRỪ hẹp, ghi rõ từng file và lý do. KHÔNG phải nới rào chung.
     *
     * `BranchOpeningWindow` nhận `App\Models\Branch` có chủ ý: `Branch` là
     * TenancyKernel — mọi module đều được phép chạm, nên nó không rò model của
     * một module nào (deptrac đồng ý: `Violations 0`). Đổi sang `branchId` rồi
     * nạp lại là ĐỔI HÀNH VI, không phải dọn dẹp: người gọi
     * (`CustomerTakeawayOrderService`) đang cầm sẵn instance và phán xét chính
     * hàng đó; nạp lại là phán xét một hàng KHÁC.
     *
     * Bài này viết từ plan-047, trước khi có khái niệm TenancyKernel, nên nó cấm
     * `App\Models\` không phân biệt. Miễn trừ theo TÊN FILE để mọi cổng khác vẫn
     * bị cấm y như cũ, và để lần sau ai thêm tên vào đây phải viết lý do.
     */
    $modelBanExempt = [
        'app/Services/Order/Contracts/BranchOpeningWindow.php',
    ];

    foreach ($directories as $directory) {
        foreach (glob("{$backend}/{$directory}/*.php") as $file) {
            $relative = str_replace($backend.'/', '', $file);
            $modelBan = in_array($relative, $modelBanExempt, true)
                ? '/Stripe\\\\|PayPay|SBPayment/'
                : '/App\\\\Models|Stripe\\\\|PayPay|SBPayment/';

            expect($stripComments(file_get_contents($file)))
                ->not->toMatch($modelBan, $file)
                /*
                 * `Illuminate\Database\Eloquent\ModelNotFoundException` là NGOẠI LỆ
                 * của framework, không phải một đường vào persistence: cổng ném nó
                 * để nói "không tìm thấy", và người gọi bắt nó mà không chạm DB.
                 * Ghim phần còn lại của `Illuminate\Database` (Eloquent\Builder,
                 * Query\Builder, Eloquent\Model, Capsule…) — đó mới là thứ kéo
                 * tầng lưu trữ vào hợp đồng.
                 */
                ->not->toMatch('/Illuminate\\\\Database\\\\(?!Eloquent\\\\ModelNotFoundException)/', $file);
        }
    }

    foreach (glob("{$backend}/app/Services/Payment/Orchestration/{Commands,Contracts}/*.php", GLOB_BRACE) as $file) {
        expect(file_get_contents($file))->not->toContain('OrderPersistencePort');
    }

    $provider = file_get_contents("{$backend}/app/Providers/AppServiceProvider.php");
    expect($provider)->not->toContain('MutationFacade')
        ->not->toContain('PersistencePort')
        ->not->toContain('QueryPort');
});

it('gives every mutation command one final canonical identity implementation', function () {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/app/Services'));
    $count = 0;

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || basename($file->getPath()) !== 'Commands') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        if (! preg_match('/namespace\s+([^;]+);/', $source, $namespace) || ! preg_match('/(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/', $source, $className)) {
            continue;
        }
        $class = $namespace[1].'\\'.$className[1];
        $reflection = new ReflectionClass($class);
        if (! $reflection->isSubclassOf(MutationCommand::class)) {
            continue;
        }
        $count++;
        $method = $reflection->getMethod('mutationFingerprint');
        expect($method->isFinal())->toBeTrue("{$class} must use the final canonical mutation identity")
            ->and($method->getDeclaringClass()->getName())->toBe(MutationCommand::class);
    }

    expect($count)->toBeGreaterThan(50);
});
