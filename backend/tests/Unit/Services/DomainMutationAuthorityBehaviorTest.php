<?php

use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\Customer\Commands\ChangeCustomerCredentialsCommand;
use App\Services\Customer\Commands\CustomerLifecycleCommand;
use App\Services\Customer\Commands\IssueCustomerAccessTokenCommand;
use App\Services\Customer\Commands\LinkCustomerScopeCommand;
use App\Services\Customer\Commands\MergeCustomersCommand;
use App\Services\Customer\Commands\RegisterCustomerAccountCommand;
use App\Services\Customer\Commands\RegisterCustomerCommand;
use App\Services\Customer\Commands\ReviseGlobalCustomerProfileCommand;
use App\Services\Customer\Commands\ReviseTenantCustomerProfileCommand;
use App\Services\Customer\Commands\RevokeCustomerAccessTokenCommand;
use App\Services\Customer\Commands\UnlinkCustomerScopeCommand;
use App\Services\Customer\Commands\VerifyCustomerEmailCommand;
use App\Services\Customer\Contracts\CustomerAuthenticationPort;
use App\Services\Customer\Contracts\CustomerAuthorityVerificationPort;
use App\Services\Customer\Contracts\CustomerPersistencePort;
use App\Services\Customer\Contracts\CustomerSnapshot;
use App\Services\Customer\Enums\CustomerAuthorityOperation;
use App\Services\Customer\Enums\CustomerLifecycleAction;
use App\Services\Customer\Enums\CustomerScopeKind;
use App\Services\Customer\Results\CustomerMutationResult;
use App\Services\Customer\Results\CustomerResolvedResult;
use App\Services\Customer\ValueObjects\CustomerCredentialPayload;
use App\Services\Customer\ValueObjects\CustomerMergePlan;
use App\Services\Customer\ValueObjects\CustomerScopeEvidence;
use App\Services\Customer\ValueObjects\GlobalCustomerProfilePatch;
use App\Services\Customer\ValueObjects\GlobalCustomerProfilePayload;
use App\Services\Customer\ValueObjects\OptionalProfileField;
use App\Services\Customer\ValueObjects\TenantCustomerProfilePayload;
use App\Services\Customer\ValueObjects\VerifiedCustomerMutation;
use App\Services\DomainMutation\MutationContext;
use App\Services\DomainMutation\SupportedLocale;
use App\Services\DomainMutation\VerificationAuthority;
use App\Services\Menu\Commands\ApplyShopMenuOverrideCommand;
use App\Services\Menu\Commands\CreateFloatingMenuSectionCommand;
use App\Services\Menu\Commands\MenuLifecycleCommand;
use App\Services\Menu\Commands\PlaceFloatingMenuProductCommand;
use App\Services\Menu\Commands\ReplaceMenuLayoutCommand;
use App\Services\Menu\Enums\MenuLayoutMutation;
use App\Services\Menu\Enums\MenuLifecycleAction;
use App\Services\Menu\Enums\MenuOverrideMode;
use App\Services\Menu\ValueObjects\FloatingMenuSectionPayload;
use App\Services\Menu\ValueObjects\MenuDefinitionPayload;
use App\Services\Menu\ValueObjects\MenuItemPayload;
use App\Services\Menu\ValueObjects\MenuLayoutPayload;
use App\Services\Menu\ValueObjects\ShopMenuOverridePayload;
use App\Services\Order\Commands\AdvanceOrderItemKitchenCommand;
use App\Services\Order\Commands\ChangeOrderItemsCommand;
use App\Services\Order\Commands\CreateOrderCommand;
use App\Services\Order\Commands\PersistOfflineReplayOrderCommand;
use App\Services\Order\Commands\PersistOnlineOrderCommand;
use App\Services\Order\Commands\PersistResolvedOrderCommand;
use App\Services\Order\Commands\PersistResolvedOrderItemsCommand;
use App\Services\Order\Commands\ReplayOfflineOrderCommand;
use App\Services\Order\Contracts\OrderEvidenceVerificationPort;
use App\Services\Order\Contracts\OrderPersistencePort;
use App\Services\Order\Contracts\OrderPricingResolutionPort;
use App\Services\Order\Enums\OrderChannel;
use App\Services\Order\Enums\OrderItemMutation;
use App\Services\Order\Enums\OrderSplitMode;
use App\Services\Order\ValueObjects\OfflineOrderEvidence;
use App\Services\Order\ValueObjects\OrderDraftPayload;
use App\Services\Order\ValueObjects\OrderLineEvidence;
use App\Services\Order\ValueObjects\OrderLinePayload;
use App\Services\Order\ValueObjects\OrderLineSelectionPayload;
use App\Services\Order\ValueObjects\OrderPricingEvidence;
use App\Services\Order\ValueObjects\OrderSelectionPayload;
use App\Services\Order\ValueObjects\OrderToppingSelectionPayload;
use App\Services\Order\ValueObjects\TrustedOrderSnapshot;
use App\Services\Payment\Orchestration\Commands\PreparePaymentCommand;
use App\Services\Payment\Orchestration\Commands\RecordPaymentTenderCommand;
use App\Services\Payment\Orchestration\Commands\RecordResolvedPaymentTenderCommand;
use App\Services\Payment\Orchestration\Commands\RequestPaymentRefundCommand;
use App\Services\Payment\Orchestration\Commands\ReserveVerifiedPaymentAttemptCommand;
use App\Services\Payment\Orchestration\Commands\ReserveVerifiedRefundCommand;
use App\Services\Payment\Orchestration\Contracts\PaymentAuthorityVerificationPort;
use App\Services\Payment\Orchestration\Contracts\PaymentPersistencePort;
use App\Services\Payment\Orchestration\Enums\PaymentObligation;
use App\Services\Payment\Orchestration\Enums\RefundReason;
use App\Services\Payment\Orchestration\Enums\TenderKind;
use App\Services\Payment\Orchestration\ValueObjects\PaymentAllocationPayload;
use App\Services\Payment\Orchestration\ValueObjects\PaymentSplitPlan;
use App\Services\Payment\Orchestration\ValueObjects\PaymentTenderPayload;
use App\Services\Payment\Orchestration\ValueObjects\RefundRequestPayload;
use App\Services\Payment\Orchestration\ValueObjects\RefundVerificationEvidence;
use App\Services\Payment\Orchestration\ValueObjects\ResolvedPaymentMethodEvidence;
use App\Services\Payment\Orchestration\ValueObjects\VerifiedPaymentPreparation;
use App\Services\Payment\Orchestration\ValueObjects\VerifiedRefundIntent;
use App\Services\Product\Commands\CreateVariantUnitCommand;
use App\Services\Product\Commands\ProductLifecycleCommand;
use App\Services\Product\Commands\VariantUnitLifecycleCommand;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\Enums\ProductLifecycleAction;
use App\Services\Product\Enums\VariantUnitLifecycleAction;
use App\Services\Product\ValueObjects\ProductOptionValuePayload;
use App\Services\Product\ValueObjects\VariantUnitPayload;
use Illuminate\Config\Repository;

function authorityUuid(int $suffix): string
{
    return sprintf('00000000-0000-4000-8000-%012d', $suffix);
}

function tenantContext(int $version = 1, string $idempotency = 'idem-1'): MutationContext
{
    return new MutationContext(authorityUuid(1), authorityUuid(2), 'correlation-1', $idempotency, $version);
}

function issuanceConfig(): Repository
{
    if (! app()->bound('config')) {
        app()->instance('config', new Repository(['app' => ['key' => 'unit-test-key'], 'domain_mutation' => ['issuance_adapters' => []]]));
    }

    return app('config');
}

final class FinalPaymentAuthorityAdapterFixture implements PaymentAuthorityVerificationPort
{
    public ?VerificationAuthority $authority = null;

    public function verifyPreparation(PreparePaymentCommand $command): VerifiedPaymentPreparation
    {
        $authority = $this->authority ?? throw new LogicException('missing grant');
        $method = ResolvedPaymentMethodEvidence::issueForPreparation($this, $authority, $command, $command->tender?->paymentMethodId ?? authorityUuid(999), 1, false, false, false);

        return VerifiedPaymentPreparation::issue($this, $authority, $command, 'test-provider', $command->optionId, $method);
    }

    public function resolveTenderMethod(RecordPaymentTenderCommand $command): ResolvedPaymentMethodEvidence
    {
        return ResolvedPaymentMethodEvidence::issue($this, $this->authority ?? throw new LogicException('missing grant'), $command, 4, true, true, false);
    }

    public function verifyRefund(RequestPaymentRefundCommand $command): VerifiedRefundIntent
    {
        return VerifiedRefundIntent::issue(
            $this,
            $this->authority ?? throw new LogicException('missing grant'),
            $command,
            'provider-payment-1',
            'provider-connection-1',
            new RefundVerificationEvidence($command->context->actorId, 'authorization-event-1', '2026-07-22T12:00:00+07:00'),
        );
    }
}

final class FinalCustomerAuthorityAdapterFixture implements CustomerAuthorityVerificationPort
{
    public ?VerificationAuthority $authority = null;

    public function verifyLifecycleAuthority(CustomerLifecycleCommand $command): VerifiedCustomerMutation
    {
        $operation = $command->action === CustomerLifecycleAction::Archive ? CustomerAuthorityOperation::Archive : CustomerAuthorityOperation::Restore;

        return VerifiedCustomerMutation::issue($this, $this->authority ?? throw new LogicException('missing grant'), $command, $operation);
    }

    public function verifyTokenIssueAuthority(IssueCustomerAccessTokenCommand $command): VerifiedCustomerMutation
    {
        throw new LogicException('unused');
    }

    public function verifyTokenRevokeAuthority(RevokeCustomerAccessTokenCommand $command): VerifiedCustomerMutation
    {
        throw new LogicException('unused');
    }

    public function verifyGlobalProfileAuthority(ReviseGlobalCustomerProfileCommand $command): VerifiedCustomerMutation
    {
        throw new LogicException('unused');
    }

    public function verifyTenantProfileAuthority(ReviseTenantCustomerProfileCommand $command): VerifiedCustomerMutation
    {
        throw new LogicException('unused');
    }

    public function verifyEmailAuthority(VerifyCustomerEmailCommand $command): VerifiedCustomerMutation
    {
        throw new LogicException('unused');
    }

    public function verifyLinkAuthority(LinkCustomerScopeCommand $command): VerifiedCustomerMutation
    {
        throw new LogicException('unused');
    }

    public function verifyUnlinkAuthority(UnlinkCustomerScopeCommand $command): VerifiedCustomerMutation
    {
        throw new LogicException('unused');
    }

    public function verifyMergeAuthority(MergeCustomersCommand $command): VerifiedCustomerMutation
    {
        throw new LogicException('unused');
    }

    public function verifyCredentialAuthority(ChangeCustomerCredentialsCommand $command): VerifiedCustomerMutation
    {
        throw new LogicException('unused');
    }
}

final class FinalOrderPricingAdapterFixture implements OrderPricingResolutionPort
{
    public function resolveOrder(CreateOrderCommand $command): TrustedOrderSnapshot
    {
        throw new LogicException('unused');
    }

    public function resolveLine(ChangeOrderItemsCommand $command): OrderLinePayload
    {
        throw new LogicException('unused');
    }
}

final class FinalOfflineOrderAdapterFixture implements OrderEvidenceVerificationPort
{
    public function verifyOfflineReplay(ReplayOfflineOrderCommand $command): TrustedOrderSnapshot
    {
        throw new LogicException('unused');
    }
}

function paymentVerificationGrant(FinalPaymentAuthorityAdapterFixture $adapter): VerificationAuthority
{
    $scopes = ['payment.resolved_method', 'payment.verified_preparation', 'payment.verified_refund'];
    $config = issuanceConfig();
    $adapters = $config->get('domain_mutation.issuance_adapters', []);
    $adapters[PaymentAuthorityVerificationPort::class] = array_fill_keys($scopes, FinalPaymentAuthorityAdapterFixture::class);
    $config->set('domain_mutation.issuance_adapters', $adapters);

    return VerificationAuthority::forConfiguredAdapter($adapter, PaymentAuthorityVerificationPort::class, $scopes);
}

function customerVerificationGrant(FinalCustomerAuthorityAdapterFixture $adapter): VerificationAuthority
{
    $config = issuanceConfig();
    $adapters = $config->get('domain_mutation.issuance_adapters', []);
    $adapters[CustomerAuthorityVerificationPort::class] = ['customer.verified_mutation' => FinalCustomerAuthorityAdapterFixture::class];
    $config->set('domain_mutation.issuance_adapters', $adapters);

    return VerificationAuthority::forConfiguredAdapter($adapter, CustomerAuthorityVerificationPort::class, ['customer.verified_mutation']);
}

function onlineOrderVerificationGrant(FinalOrderPricingAdapterFixture $adapter): VerificationAuthority
{
    $config = issuanceConfig();
    $adapters = $config->get('domain_mutation.issuance_adapters', []);
    $adapters[OrderPricingResolutionPort::class] = ['order.trusted_snapshot' => FinalOrderPricingAdapterFixture::class];
    $config->set('domain_mutation.issuance_adapters', $adapters);

    return VerificationAuthority::forConfiguredAdapter($adapter, OrderPricingResolutionPort::class, ['order.trusted_snapshot']);
}

function offlineOrderVerificationGrant(FinalOfflineOrderAdapterFixture $adapter): VerificationAuthority
{
    $config = issuanceConfig();
    $adapters = $config->get('domain_mutation.issuance_adapters', []);
    $adapters[OrderEvidenceVerificationPort::class] = ['order.trusted_snapshot' => FinalOfflineOrderAdapterFixture::class];
    $config->set('domain_mutation.issuance_adapters', $adapters);

    return VerificationAuthority::forConfiguredAdapter($adapter, OrderEvidenceVerificationPort::class, ['order.trusted_snapshot']);
}

function resolvedOrderDraft(?string $deviceId = null): OrderDraftPayload
{
    $lineEvidence = new OrderLineEvidence(null, null, authorityUuid(203), null, 1000, 0, 0, lineSubtotalMinor: 1000);
    $line = new OrderLinePayload(authorityUuid(201), authorityUuid(202), authorityUuid(203), 1, 1000, [], $lineEvidence);
    $pricing = new OrderPricingEvidence(1000, 0, 0, 0, 1000, false, 'none', null);

    return new OrderDraftPayload([$line], deviceId: $deviceId, status: CustomerOrderStatusEnum::Open, pricingEvidence: $pricing);
}

it('keeps every public post-create order line mutation selection-only', function () {
    $topping = new OrderToppingSelectionPayload(authorityUuid(10), authorityUuid(11), 2);
    $selection = new OrderLineSelectionPayload(authorityUuid(12), authorityUuid(13), 1, [$topping]);
    $command = new ChangeOrderItemsCommand(tenantContext(), authorityUuid(14), OrderItemMutation::Add, $selection->fingerprint(), $selection);

    expect($command->payload)->toBeInstanceOf(OrderLineSelectionPayload::class)
        ->and((new ReflectionClass(OrderLineSelectionPayload::class))->hasProperty('unitPriceMinor'))->toBeFalse()
        ->and((new ReflectionClass(OrderLineSelectionPayload::class))->hasProperty('status'))->toBeFalse()
        ->and((new ReflectionClass(OrderLineSelectionPayload::class))->hasProperty('evidence'))->toBeFalse()
        ->and($topping->toppingGroupItemId)->toBe(authorityUuid(10))
        ->and($topping->productSkuId)->toBe(authorityUuid(11))
        ->and((new ReflectionClass(OrderPricingResolutionPort::class))->hasMethod('resolveLine'))->toBeTrue()
        ->and((new ReflectionMethod(OrderPersistencePort::class, 'applyItemChange'))->getParameters()[0]->getType()->getName())->toBe(PersistResolvedOrderItemsCommand::class);
});

it('requires signed offline evidence bound to device issuer revision and expiry', function () {
    $line = new OrderLineSelectionPayload(authorityUuid(20), authorityUuid(21), 1);
    $selection = new OrderSelectionPayload([$line], channel: OrderChannel::Workstation, deviceId: authorityUuid(22));
    $evidence = new OfflineOrderEvidence(authorityUuid(22), authorityUuid(23), 7, '2026-07-22T00:00:00Z', '2026-07-22T00:05:00Z', 'key-1', 'mac-1');

    expect(new ReplayOfflineOrderCommand(tenantContext(), authorityUuid(24), authorityUuid(25), $selection, $selection->fingerprint(), $evidence))->toBeInstanceOf(ReplayOfflineOrderCommand::class)
        ->and((new ReflectionClass(OrderEvidenceVerificationPort::class))->hasMethod('verifyOfflineReplay'))->toBeTrue()
        ->and(fn () => new ReplayOfflineOrderCommand(new MutationContext(null, null, 'offline-correlation', 'offline-idempotency'), authorityUuid(24), authorityUuid(25), $selection, $selection->fingerprint(), $evidence))->toThrow(InvalidArgumentException::class, 'organization tenant')
        ->and(fn () => new OfflineOrderEvidence(authorityUuid(22), authorityUuid(23), 7, '2026-07-22T00:05:00Z', '2026-07-22T00:00:00Z', 'key-1', 'mac-1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ReplayOfflineOrderCommand(tenantContext(), authorityUuid(24), authorityUuid(25), $selection, $selection->fingerprint(), new OfflineOrderEvidence(authorityUuid(99), authorityUuid(23), 7, '2026-07-22T00:00:00Z', '2026-07-22T00:05:00Z', 'key-1', 'mac-1')))->toThrow(InvalidArgumentException::class, 'device');
});

it('separates global accounts from tenant CRM and models true PATCH semantics', function () {
    $globalContext = new MutationContext(null, null, 'correlation-1', 'global-register');
    $global = new GlobalCustomerProfilePayload('Ada', null, 'ada@example.test', null, SupportedLocale::Japanese);
    $account = new RegisterCustomerAccountCommand($globalContext, authorityUuid(30), $global, $global->fingerprint(), new CustomerCredentialPayload('secret-password'), 'iphone');
    $tenant = new TenantCustomerProfilePayload('Guest', null, null, '0900000000');
    $scope = new CustomerScopeEvidence(CustomerScopeKind::TenantCrm, authorityUuid(1), authorityUuid(31), authorityUuid(32), authorityUuid(1), authorityUuid(31));

    $omitted = OptionalProfileField::omitted();
    $patch = new GlobalCustomerProfilePatch($omitted, OptionalProfileField::clear(), $omitted, $omitted, $omitted, $omitted);
    $revision = new ReviseGlobalCustomerProfileCommand(new MutationContext(null, authorityUuid(30), 'correlation-1', 'profile-revise'), authorityUuid(30), $patch, $patch->fingerprint(), 'session-1');

    expect($account)->toBeInstanceOf(RegisterCustomerAccountCommand::class)
        ->and(new RegisterCustomerCommand(tenantContext(), authorityUuid(33), authorityUuid(32), authorityUuid(31), $tenant, $tenant->fingerprint(), $scope))->toBeInstanceOf(RegisterCustomerCommand::class)
        ->and($revision->payload->familyName->provided)->toBeTrue()
        ->and($revision->payload->familyName->value)->toBeNull()
        ->and($revision->payload->email->provided)->toBeFalse()
        ->and((new ReflectionClass(CustomerAuthenticationPort::class))->hasMethod('authenticate'))->toBeTrue()
        ->and((new ReflectionClass(CustomerSnapshot::class))->hasMethod('version'))->toBeFalse()
        ->and((new ReflectionClass(CustomerMutationResult::class))->hasProperty('version'))->toBeFalse()
        ->and((new ReflectionClass(CustomerResolvedResult::class))->hasProperty('version'))->toBeFalse()
        ->and(fn () => new CustomerScopeEvidence(CustomerScopeKind::GlobalAccount, authorityUuid(1), null, null, null, null))->toThrow(InvalidArgumentException::class, 'Global');
});

it('requires an authority port for customer verification linkage and merge and fixes lock order', function () {
    $context = tenantContext();
    $merge = new MergeCustomersCommand($context, authorityUuid(42), authorityUuid(41), 'admin-approval-1');
    $plan = new CustomerMergePlan($merge->sourceCustomerId, $merge->targetCustomerId, ['orders', 'reviews', 'coupon_redemptions', 'invoices']);

    expect((new ReflectionClass(CustomerAuthorityVerificationPort::class))->getMethods())->toHaveCount(10)
        ->and($plan->lockOrder)->toBe([authorityUuid(41), authorityUuid(42)])
        ->and(fn () => new MergeCustomersCommand(new MutationContext(authorityUuid(1), null, 'c', 'i'), authorityUuid(41), authorityUuid(42), 'admin-approval-1'))->toThrow(InvalidArgumentException::class, 'authorization');
});

it('supports unbounded option positions and publishes VariantUnit commands', function () {
    $value = new ProductOptionValuePayload(authorityUuid(50), 'Large', 'large', 100);
    $variant = new VariantUnitPayload('case', '12.0000', 'CASE-12', null, '1200.00', false, true);
    $command = new CreateVariantUnitCommand(tenantContext(), authorityUuid(51), authorityUuid(52), authorityUuid(53), $variant, $variant->fingerprint());

    expect($value->position)->toBe(100)
        ->and($command->payload)->toBeInstanceOf(VariantUnitPayload::class)
        ->and((new ReflectionClass(ProductMutationFacade::class))->hasMethod('setBaseVariantUnit'))->toBeTrue();
});

it('models inherited menu service type and tri-state shop overrides', function () {
    $definition = new MenuDefinitionPayload('Menu', null, new MenuLayoutPayload([]));
    $override = new ShopMenuOverridePayload(authorityUuid(60), MenuOverrideMode::Inherit, null, MenuOverrideMode::Clear, null, authorityUuid(61));

    expect($definition->serviceType)->toBeNull()
        ->and($override->visibleMode)->toBe(MenuOverrideMode::Inherit)
        ->and($override->priceMode)->toBe(MenuOverrideMode::Clear)
        ->and(fn () => new ShopMenuOverridePayload(authorityUuid(60), MenuOverrideMode::Set, null, MenuOverrideMode::Inherit, null, authorityUuid(61)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ApplyShopMenuOverrideCommand(tenantContext(), authorityUuid(62), authorityUuid(99), $override, $override->fingerprint()))->toThrow(InvalidArgumentException::class, 'branchId');
});

it('distinguishes new debt from debt settlement and supports exact split strategies', function () {
    $newDebt = new PaymentTenderPayload(authorityUuid(70), TenderKind::OnAccount, obligation: PaymentObligation::Debt);
    $settlement = new PaymentTenderPayload(authorityUuid(70), TenderKind::OnAccount, obligation: PaymentObligation::DebtSettlement, debtPaymentId: authorityUuid(71));
    $itemAllocation = new PaymentAllocationPayload(authorityUuid(72), 'Items', 1000, orderItemIds: [authorityUuid(73)]);
    $itemPlan = new PaymentSplitPlan(OrderSplitMode::ByItems, 900, 1000, [$itemAllocation]);
    $person = new PaymentAllocationPayload(authorityUuid(74), 'Guest 1', 1000, 1);

    expect($newDebt->debtPaymentId)->toBeNull()
        ->and($settlement->debtPaymentId)->toBe(authorityUuid(71))
        ->and($itemPlan->strategy)->toBe(OrderSplitMode::ByItems)
        ->and(new PaymentSplitPlan(OrderSplitMode::Even, 900, 1000, [$person]))->toBeInstanceOf(PaymentSplitPlan::class)
        ->and(new PaymentSplitPlan(OrderSplitMode::Even, 900, 1000, [$person]))->toBeInstanceOf(PaymentSplitPlan::class)
        ->and(new PaymentSplitPlan(OrderSplitMode::ByAmount, 900, 1000, [new PaymentAllocationPayload(authorityUuid(75), 'Custom', 1000)]))->toBeInstanceOf(PaymentSplitPlan::class)
        ->and(fn () => new PaymentTenderPayload(authorityUuid(70), TenderKind::OnAccount, obligation: PaymentObligation::DebtSettlement))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PaymentTenderPayload(authorityUuid(70), TenderKind::Cash, reference: str_repeat('x', 256)))->toThrow(InvalidArgumentException::class, 'reference')
        ->and(fn () => new PaymentTenderPayload(authorityUuid(70), TenderKind::Cash, reference: "bad\nreference"))->toThrow(InvalidArgumentException::class, 'reference');
});

it('binds payment and refund intent to tenant actor branch and verifier ports', function () {
    $prepare = new PreparePaymentCommand(tenantContext(), authorityUuid(80), authorityUuid(81), authorityUuid(82), authorityUuid(83), authorityUuid(84), 1000, 'JPY', 4, 900, 1000, authorizationReference: 'policy-proof-1');
    $tender = new PaymentTenderPayload(authorityUuid(85), TenderKind::Cash, tenderedMinor: 1200);
    $record = new RecordPaymentTenderCommand(tenantContext(), authorityUuid(86), authorityUuid(81), authorityUuid(82), 1000, 'JPY', $tender, authorizationReference: 'method-proof-1');
    $refund = new RefundRequestPayload(authorityUuid(81), authorityUuid(86), authorityUuid(80), 500, 'JPY', RefundReason::CustomerRequest);
    $request = new RequestPaymentRefundCommand(tenantContext(idempotency: 'refund-1'), authorityUuid(87), authorityUuid(82), authorityUuid(88), $refund, $refund->fingerprint(), 'refund-proof-1');

    expect($prepare->branchId)->toBe(authorityUuid(82))
        ->and((new ReflectionClass(RecordPaymentTenderCommand::class))->hasProperty('changeMinor'))->toBeFalse()
        ->and($record->authorizationReference)->toBe('method-proof-1')
        ->and($request->payload)->toBeInstanceOf(RefundRequestPayload::class)
        ->and((new ReflectionClass(PaymentAuthorityVerificationPort::class))->getMethods())->toHaveCount(3)
        ->and((new ReflectionMethod(PaymentPersistencePort::class, 'reserveAttempt'))->getParameters()[0]->getType()->getName())->toBe(ReserveVerifiedPaymentAttemptCommand::class)
        ->and((new ReflectionMethod(PaymentPersistencePort::class, 'reserveRefund'))->getParameters()[0]->getType()->getName())->toBe(ReserveVerifiedRefundCommand::class)
        ->and(fn () => new PreparePaymentCommand(new MutationContext(authorityUuid(1), null, 'c', 'i', 1), authorityUuid(80), authorityUuid(81), authorityUuid(82), authorityUuid(83), authorityUuid(84), 1000, 'JPY', 4, 900, 1000, authorizationReference: 'proof'))->toThrow(InvalidArgumentException::class, 'actor');
});

it('rejects deserialized trusted values that were never issued by a verification port', function () {
    $forge = static function (string $class): object {
        return unserialize('O:'.strlen($class).':"'.$class.'":0:{}');
    };

    expect((new ReflectionClass(TrustedOrderSnapshot::class))->getConstructor()->isPrivate())->toBeTrue()
        ->and((new ReflectionClass(VerifiedPaymentPreparation::class))->getConstructor()->isPrivate())->toBeTrue()
        ->and((new ReflectionClass(ResolvedPaymentMethodEvidence::class))->getConstructor()->isPrivate())->toBeTrue()
        ->and((new ReflectionClass(VerifiedRefundIntent::class))->getConstructor()->isPrivate())->toBeTrue()
        ->and((new ReflectionClass(PersistResolvedOrderCommand::class))->getConstructor()->isPrivate())->toBeTrue()
        ->and((new ReflectionClass(PersistOnlineOrderCommand::class))->getConstructor()->isPrivate())->toBeTrue()
        ->and((new ReflectionClass(PersistOfflineReplayOrderCommand::class))->getConstructor()->isPrivate())->toBeTrue()
        ->and((new ReflectionClass(PersistResolvedOrderItemsCommand::class))->getConstructor()->isPrivate())->toBeTrue()
        ->and((new ReflectionClass(ReserveVerifiedPaymentAttemptCommand::class))->getConstructor()->isPrivate())->toBeTrue()
        ->and((new ReflectionClass(ReserveVerifiedRefundCommand::class))->getConstructor()->isPrivate())->toBeTrue()
        ->and((new ReflectionClass(RecordResolvedPaymentTenderCommand::class))->getConstructor()->isPrivate())->toBeTrue()
        ->and(fn () => $forge(TrustedOrderSnapshot::class)->assertTrusted())->toThrow(LogicException::class, 'Unverified')
        ->and(fn () => $forge(VerifiedPaymentPreparation::class)->assertTrusted())->toThrow(LogicException::class, 'Unverified')
        ->and(fn () => $forge(ResolvedPaymentMethodEvidence::class)->assertTrusted())->toThrow(LogicException::class, 'Unverified')
        ->and(fn () => $forge(VerifiedRefundIntent::class)->assertTrusted())->toThrow(LogicException::class, 'Unverified')
        ->and(fn () => $forge(PersistResolvedOrderCommand::class)->assertTrusted())->toThrow(LogicException::class, 'Unverified')
        ->and(fn () => $forge(PersistOnlineOrderCommand::class)->assertTrusted())->toThrow(LogicException::class, 'Unverified')
        ->and(fn () => $forge(PersistOfflineReplayOrderCommand::class)->assertTrusted())->toThrow(LogicException::class, 'Unverified')
        ->and(fn () => $forge(PersistResolvedOrderItemsCommand::class)->assertTrusted())->toThrow(LogicException::class, 'Unverified')
        ->and(fn () => $forge(ReserveVerifiedPaymentAttemptCommand::class)->assertTrusted())->toThrow(LogicException::class, 'Unverified')
        ->and(fn () => $forge(ReserveVerifiedRefundCommand::class)->assertTrusted())->toThrow(LogicException::class, 'Unverified')
        ->and(fn () => $forge(RecordResolvedPaymentTenderCommand::class)->assertTrusted())->toThrow(LogicException::class, 'Unverified');
});

it('uses distinct persistence types for online resolution and offline replay', function () {
    $onlineType = (new ReflectionMethod(OrderPersistencePort::class, 'insertResolvedOrder'))->getParameters()[0]->getType()->getName();
    $offlineType = (new ReflectionMethod(OrderPersistencePort::class, 'insertOfflineReplay'))->getParameters()[0]->getType()->getName();
    $onlineBoundary = static function (PersistOnlineOrderCommand $command): void {};
    $offlineClass = PersistOfflineReplayOrderCommand::class;
    $forgedOffline = unserialize('O:'.strlen($offlineClass).':"'.$offlineClass.'":0:{}');

    $context = tenantContext();
    $selectionLine = new OrderLineSelectionPayload(authorityUuid(204), authorityUuid(205), 1);
    $onlineSelection = new OrderSelectionPayload([$selectionLine]);
    $onlineRequest = new CreateOrderCommand($context, authorityUuid(206), authorityUuid(207), $onlineSelection, $onlineSelection->fingerprint());
    $onlineResolver = new FinalOrderPricingAdapterFixture;
    $onlineSnapshot = TrustedOrderSnapshot::fromPricingResolver($onlineResolver, onlineOrderVerificationGrant($onlineResolver), $onlineRequest, resolvedOrderDraft(), CustomerOrderStatusEnum::Open, 'JPY', hash('sha256', 'online-resolution'), '2026-07-22T12:00:00+07:00');
    $onlineResolved = PersistResolvedOrderCommand::fromTrustedSnapshot($context, $onlineRequest->orderId, $onlineRequest->branchId, $onlineSnapshot, $onlineSnapshot->fingerprint());
    $online = PersistOnlineOrderCommand::fromResolved($onlineResolved);

    $deviceId = authorityUuid(208);
    $offlineSelection = new OrderSelectionPayload([$selectionLine], channel: OrderChannel::Workstation, deviceId: $deviceId);
    $offlineEvidence = new OfflineOrderEvidence($deviceId, authorityUuid(209), 1, '2026-07-22T00:00:00Z', '2026-07-22T00:05:00Z', 'key-2', 'mac-2');
    $offlineRequest = new ReplayOfflineOrderCommand($context, authorityUuid(210), authorityUuid(211), $offlineSelection, $offlineSelection->fingerprint(), $offlineEvidence);
    $offlineVerifier = new FinalOfflineOrderAdapterFixture;
    $offlineSnapshot = TrustedOrderSnapshot::fromOfflineVerifier($offlineVerifier, offlineOrderVerificationGrant($offlineVerifier), $offlineRequest, resolvedOrderDraft($deviceId), CustomerOrderStatusEnum::Open, 'JPY', hash('sha256', 'offline-resolution'), '2026-07-22T12:00:00+07:00');
    $offlineResolved = PersistResolvedOrderCommand::fromTrustedSnapshot($context, $offlineRequest->orderId, $offlineRequest->branchId, $offlineSnapshot, $offlineSnapshot->fingerprint());
    $offline = PersistOfflineReplayOrderCommand::fromResolved($offlineResolved);

    expect($onlineType)->toBe(PersistOnlineOrderCommand::class)
        ->and($offlineType)->toBe(PersistOfflineReplayOrderCommand::class)
        ->and(fn () => $onlineBoundary($forgedOffline))->toThrow(TypeError::class)
        ->and(fn () => $online->assertTrusted())->not->toThrow(LogicException::class)
        ->and(fn () => $offline->assertTrusted())->not->toThrow(LogicException::class)
        ->and($online->mutationFingerprint())->toBeString()->toHaveLength(64)
        ->and($offline->mutationFingerprint())->toBeString()->toHaveLength(64)
        ->and($online->mutationFingerprint())->not->toBe($offline->mutationFingerprint())
        ->and(fn () => PersistOfflineReplayOrderCommand::fromResolved($onlineResolved))->toThrow(InvalidArgumentException::class, 'Online resolution')
        ->and(fn () => PersistOnlineOrderCommand::fromResolved($offlineResolved))->toThrow(InvalidArgumentException::class, 'Offline replay');
});

it('binds trusted order snapshots to tenant branch order and complete request context', function () {
    $context = tenantContext();
    $selection = new OrderSelectionPayload([new OrderLineSelectionPayload(authorityUuid(212), authorityUuid(213), 1)]);
    $request = new CreateOrderCommand($context, authorityUuid(214), authorityUuid(215), $selection, $selection->fingerprint());
    $resolver = new FinalOrderPricingAdapterFixture;
    $snapshot = TrustedOrderSnapshot::fromPricingResolver($resolver, onlineOrderVerificationGrant($resolver), $request, resolvedOrderDraft(), CustomerOrderStatusEnum::Open, 'JPY', hash('sha256', 'bound-resolution'), '2026-07-22T12:00:00+07:00');

    expect($snapshot->requestFingerprint)->toBe($request->mutationFingerprint())
        ->and(fn () => new CreateOrderCommand(new MutationContext(null, null, 'create-correlation', 'create-idempotency'), $request->orderId, $request->branchId, $selection, $selection->fingerprint()))->toThrow(InvalidArgumentException::class, 'organization tenant')
        ->and(fn () => PersistResolvedOrderCommand::fromTrustedSnapshot($context, $request->orderId, $request->branchId, $snapshot, $snapshot->fingerprint()))->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => PersistResolvedOrderCommand::fromTrustedSnapshot(new MutationContext(authorityUuid(99), $context->actorId, $context->correlationId, 'idem-1', 1), $request->orderId, $request->branchId, $snapshot, $snapshot->fingerprint()))->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => PersistResolvedOrderCommand::fromTrustedSnapshot($context, $request->orderId, authorityUuid(99), $snapshot, $snapshot->fingerprint()))->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => PersistResolvedOrderCommand::fromTrustedSnapshot($context, authorityUuid(99), $request->branchId, $snapshot, $snapshot->fingerprint()))->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => PersistResolvedOrderCommand::fromTrustedSnapshot(new MutationContext($context->organizationId, authorityUuid(99), $context->correlationId, 'idem-1', 1), $request->orderId, $request->branchId, $snapshot, $snapshot->fingerprint()))->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => PersistResolvedOrderCommand::fromTrustedSnapshot(new MutationContext($context->organizationId, $context->actorId, 'changed-correlation', 'idem-1', 1), $request->orderId, $request->branchId, $snapshot, $snapshot->fingerprint()))->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => PersistResolvedOrderCommand::fromTrustedSnapshot(new MutationContext($context->organizationId, $context->actorId, $context->correlationId, 'changed-idempotency', 1), $request->orderId, $request->branchId, $snapshot, $snapshot->fingerprint()))->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => PersistResolvedOrderCommand::fromTrustedSnapshot(new MutationContext($context->organizationId, $context->actorId, $context->correlationId, 'idem-1', 2), $request->orderId, $request->branchId, $snapshot, $snapshot->fingerprint()))->toThrow(InvalidArgumentException::class, 'does not match');
});

it('binds resolved tender money and change to authority-issued payment method evidence', function () {
    $tender = new PaymentTenderPayload(authorityUuid(90), TenderKind::Cash, tenderedMinor: 1200);
    $request = new RecordPaymentTenderCommand(tenantContext(), authorityUuid(91), authorityUuid(92), authorityUuid(93), 1000, 'JPY', $tender, authorizationReference: 'method-proof');
    $verifier = new FinalPaymentAuthorityAdapterFixture;
    $verifier->authority = paymentVerificationGrant($verifier);
    $method = $verifier->resolveTenderMethod($request);
    $resolved = RecordResolvedPaymentTenderCommand::fromVerifiedMethod($request, $method, 200);

    expect($resolved->amount->minorAmount)->toBe(1000)
        ->and($resolved->amount->currency)->toBe('JPY')
        ->and(fn () => $resolved->assertTrusted())->not->toThrow(LogicException::class)
        ->and(fn () => RecordResolvedPaymentTenderCommand::fromVerifiedMethod($request, $method, 199))->toThrow(InvalidArgumentException::class, 'reconcile');
});

it('does not let reserve attempt change preparation correlation or idempotency identity', function () {
    $verifier = new FinalPaymentAuthorityAdapterFixture;
    $verifier->authority = paymentVerificationGrant($verifier);
    $context = tenantContext(idempotency: 'prepare-idempotency');
    $tender = new PaymentTenderPayload(authorityUuid(170), TenderKind::Card);
    $prepare = new PreparePaymentCommand($context, authorityUuid(171), authorityUuid(172), authorityUuid(173), authorityUuid(174), authorityUuid(175), 1000, 'JPY', 1, 1000, 1000, $tender, authorizationReference: 'prepare-proof');
    $verified = $verifier->verifyPreparation($prepare);
    $reserved = ReserveVerifiedPaymentAttemptCommand::fromVerifiedPreparation($context, $prepare->attemptId, $verified, $tender);

    expect(fn () => $reserved->assertTrusted())->not->toThrow(LogicException::class)
        ->and(fn () => ReserveVerifiedPaymentAttemptCommand::fromVerifiedPreparation(new MutationContext($context->organizationId, $context->actorId, 'different-correlation', 'prepare-idempotency', 1), $prepare->attemptId, $verified, $tender))->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => ReserveVerifiedPaymentAttemptCommand::fromVerifiedPreparation(new MutationContext($context->organizationId, $context->actorId, $context->correlationId, 'different-idempotency', 1), $prepare->attemptId, $verified, $tender))->toThrow(InvalidArgumentException::class, 'does not match');
});

it('does not let an arbitrary interface implementation mint trusted payment evidence', function () {
    $fake = new class implements PaymentAuthorityVerificationPort
    {
        public function verifyPreparation(PreparePaymentCommand $command): VerifiedPaymentPreparation
        {
            throw new LogicException('unused');
        }

        public function resolveTenderMethod(RecordPaymentTenderCommand $command): ResolvedPaymentMethodEvidence
        {
            throw new LogicException('unused');
        }

        public function verifyRefund(RequestPaymentRefundCommand $command): VerifiedRefundIntent
        {
            throw new LogicException('unused');
        }
    };
    $tender = new PaymentTenderPayload(authorityUuid(120), TenderKind::Cash, tenderedMinor: 1000);
    $request = new RecordPaymentTenderCommand(tenantContext(), authorityUuid(121), authorityUuid(122), authorityUuid(123), 1000, 'JPY', $tender, authorizationReference: 'proof');
    $scopes = ['payment.resolved_method'];
    $appKeyMac = hash_hmac('sha256', $fake::class, issuanceConfig()->get('app.key'));
    $class = VerificationAuthority::class;
    $forged = unserialize('O:'.strlen($class).':"'.$class.'":0:{}');

    expect($appKeyMac)->toHaveLength(64)
        ->and(fn () => VerificationAuthority::forConfiguredAdapter($fake, PaymentAuthorityVerificationPort::class, $scopes))
        ->toThrow(LogicException::class, 'named final class')
        ->and(fn () => ResolvedPaymentMethodEvidence::issue($fake, $forged, $request, 1, true, true, false))
        ->toThrow(LogicException::class, 'does not authorize');
});

it('binds verified refund reservation to every request and context identity', function () {
    $verifier = new FinalPaymentAuthorityAdapterFixture;
    $verifier->authority = paymentVerificationGrant($verifier);
    $context = tenantContext(version: 7, idempotency: 'refund-idempotency');
    $payload = new RefundRequestPayload(authorityUuid(130), authorityUuid(131), authorityUuid(132), 500, 'JPY', RefundReason::CustomerRequest);
    $request = new RequestPaymentRefundCommand($context, authorityUuid(133), authorityUuid(134), authorityUuid(135), $payload, $payload->fingerprint(), 'refund-authorization');
    $intent = $verifier->verifyRefund($request);
    $reserved = ReserveVerifiedRefundCommand::fromVerifiedIntent($context, $intent);

    expect($reserved->refundId)->toBe($request->refundId)
        ->and($reserved->refundRequestId)->toBe($request->refundRequestId)
        ->and(fn () => $reserved->assertTrusted())->not->toThrow(LogicException::class)
        ->and(fn () => ReserveVerifiedRefundCommand::fromVerifiedIntent(new MutationContext(authorityUuid(999), $context->actorId, $context->correlationId, 'refund-idempotency', 7), $intent))->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => ReserveVerifiedRefundCommand::fromVerifiedIntent(new MutationContext($context->organizationId, authorityUuid(999), $context->correlationId, 'refund-idempotency', 7), $intent))->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => ReserveVerifiedRefundCommand::fromVerifiedIntent(new MutationContext($context->organizationId, $context->actorId, 'different-correlation', 'refund-idempotency', 7), $intent))->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => ReserveVerifiedRefundCommand::fromVerifiedIntent(new MutationContext($context->organizationId, $context->actorId, $context->correlationId, 'different-idempotency', 7), $intent))->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => ReserveVerifiedRefundCommand::fromVerifiedIntent(new MutationContext($context->organizationId, $context->actorId, $context->correlationId, 'refund-idempotency', 8), $intent))->toThrow(InvalidArgumentException::class, 'does not match');
});

it('changes refund request proof for every materially distinct request field', function () {
    $payload = new RefundRequestPayload(authorityUuid(160), authorityUuid(161), authorityUuid(162), 500, 'JPY', RefundReason::CustomerRequest);
    $make = static fn (
        string $refundId = '00000000-0000-4000-8000-000000000163',
        string $branchId = '00000000-0000-4000-8000-000000000164',
        string $organizationId = '00000000-0000-4000-8000-000000000001',
        string $actorId = '00000000-0000-4000-8000-000000000002',
        string $correlationId = 'refund-correlation',
        string $idempotency = 'refund-idempotency',
        int $version = 3,
        string $authorization = 'refund-authorization',
    ): RequestPaymentRefundCommand => new RequestPaymentRefundCommand(
        new MutationContext($organizationId, $actorId, $correlationId, $idempotency, $version),
        $refundId,
        $branchId,
        authorityUuid(165),
        $payload,
        $payload->fingerprint(),
        $authorization,
    );
    $base = $make()->requestFingerprint;

    expect($make(refundId: authorityUuid(166))->requestFingerprint)->not->toBe($base)
        ->and($make(branchId: authorityUuid(167))->requestFingerprint)->not->toBe($base)
        ->and($make(organizationId: authorityUuid(168))->requestFingerprint)->not->toBe($base)
        ->and($make(actorId: authorityUuid(169))->requestFingerprint)->not->toBe($base)
        ->and($make(correlationId: 'different-correlation')->requestFingerprint)->not->toBe($base)
        ->and($make(idempotency: 'different-idempotency')->requestFingerprint)->not->toBe($base)
        ->and($make(version: 4)->requestFingerprint)->not->toBe($base)
        ->and($make(authorization: 'different-authorization')->requestFingerprint)->not->toBe($base);
});

it('computes canonical mutation identity for primitive commands and all context proof fields', function () {
    $base = new AdvanceOrderItemKitchenCommand(tenantContext(idempotency: 'one'), authorityUuid(140), authorityUuid(141), OrderItemStatusEnum::Pending, OrderItemStatusEnum::Preparing, '2026-07-22T12:00:00+07:00');
    $same = new AdvanceOrderItemKitchenCommand(tenantContext(idempotency: 'one'), authorityUuid(140), authorityUuid(141), OrderItemStatusEnum::Pending, OrderItemStatusEnum::Preparing, '2026-07-22T12:00:00+07:00');
    $differentIdempotency = new AdvanceOrderItemKitchenCommand(tenantContext(idempotency: 'two'), authorityUuid(140), authorityUuid(141), OrderItemStatusEnum::Pending, OrderItemStatusEnum::Preparing, '2026-07-22T12:00:00+07:00');
    $differentTarget = new AdvanceOrderItemKitchenCommand(tenantContext(idempotency: 'one'), authorityUuid(142), authorityUuid(141), OrderItemStatusEnum::Pending, OrderItemStatusEnum::Preparing, '2026-07-22T12:00:00+07:00');

    expect($base->mutationFingerprint())->toBe($same->mutationFingerprint())
        ->and($base->mutationFingerprint())->not->toBe($differentIdempotency->mutationFingerprint())
        ->and($base->mutationFingerprint())->not->toBe($differentTarget->mutationFingerprint())
        ->and(fn () => $base->assertMutationFingerprint(str_repeat('a', 64)))->toThrow(InvalidArgumentException::class, 'does not match');
});

it('keeps idempotency secrets out of serialization dumps and public properties', function () {
    $context = tenantContext(idempotency: 'raw-secret-never-log');
    ob_start();
    var_dump($context);
    $dump = (string) ob_get_clean();

    expect(serialize($context))->not->toContain('raw-secret-never-log')
        ->and($dump)->not->toContain('raw-secret-never-log')
        ->and((new ReflectionClass(MutationContext::class))->hasProperty('idempotencyKey'))->toBeFalse()
        ->and($context->revealIdempotencyKey())->toBe('raw-secret-never-log');
});

it('enforces authenticated customer self-service and explicit tenant lifecycle authority', function () {
    expect(fn () => new IssueCustomerAccessTokenCommand(new MutationContext(null, null, 'c', 'i'), authorityUuid(100), 'phone', 'session-1'))->toThrow(InvalidArgumentException::class, 'authenticated')
        ->and(fn () => new CustomerLifecycleCommand(new MutationContext(null, authorityUuid(101), 'c', 'i', 1), authorityUuid(100), CustomerScopeKind::GlobalAccount, CustomerLifecycleAction::Archive, 'session-1'))->toThrow(InvalidArgumentException::class, 'authenticated customer')
        ->and(new CustomerLifecycleCommand(tenantContext(), authorityUuid(100), CustomerScopeKind::TenantCrm, CustomerLifecycleAction::Archive, 'admin-grant'))->toBeInstanceOf(CustomerLifecycleCommand::class);
});

it('requires a typed verified customer capability before authority-sensitive persistence', function () {
    foreach (['issueAccessToken', 'revokeAccessToken', 'applyGlobalProfileRevision', 'applyTenantProfileRevision', 'recordEmailVerification', 'linkScope', 'unlinkScope', 'replaceCredentials', 'mergeCustomers', 'markArchived', 'markRestored'] as $method) {
        expect((new ReflectionMethod(CustomerPersistencePort::class, $method))->getParameters()[0]->getType()->getName())
            ->toBe(VerifiedCustomerMutation::class);
    }

    $raw = new CustomerLifecycleCommand(tenantContext(), authorityUuid(150), CustomerScopeKind::TenantCrm, CustomerLifecycleAction::Archive, 'raw-attacker-reference');
    $persistenceBoundary = static function (VerifiedCustomerMutation $verified): void {};
    $class = VerifiedCustomerMutation::class;
    $forged = unserialize('O:'.strlen($class).':"'.$class.'":0:{}');
    $authorityClass = VerificationAuthority::class;
    $forgedAuthority = unserialize('O:'.strlen($authorityClass).':"'.$authorityClass.'":0:{}');
    $verifier = Mockery::mock(CustomerAuthorityVerificationPort::class);
    $wrongDomainCommand = new PreparePaymentCommand(tenantContext(), authorityUuid(151), authorityUuid(152), authorityUuid(153), authorityUuid(154), authorityUuid(155), 1000, 'JPY', 1, 1000, 1000, authorizationReference: 'proof');

    expect(fn () => $persistenceBoundary($raw))->toThrow(TypeError::class)
        ->and(fn () => $forged->assertTrusted(CustomerAuthorityOperation::Archive))->toThrow(LogicException::class, 'Unverified')
        ->and(fn () => VerifiedCustomerMutation::issue($verifier, $forgedAuthority, $wrongDomainCommand, CustomerAuthorityOperation::Archive))->toThrow(InvalidArgumentException::class, 'cannot wrap');
});

it('prevents replaying customer lifecycle authority across archive and restore routes', function () {
    $verifier = new FinalCustomerAuthorityAdapterFixture;
    $verifier->authority = customerVerificationGrant($verifier);
    $archive = new CustomerLifecycleCommand(tenantContext(), authorityUuid(180), CustomerScopeKind::TenantCrm, CustomerLifecycleAction::Archive, 'admin-grant');
    $restore = new CustomerLifecycleCommand(tenantContext(), authorityUuid(180), CustomerScopeKind::TenantCrm, CustomerLifecycleAction::Restore, 'admin-grant');
    $verifiedArchive = $verifier->verifyLifecycleAuthority($archive);

    expect(fn () => $verifiedArchive->assertTrusted(CustomerAuthorityOperation::Archive))->not->toThrow(LogicException::class)
        ->and(fn () => $verifiedArchive->assertTrusted(CustomerAuthorityOperation::Restore))->toThrow(LogicException::class, 'Expected verified restore')
        ->and($archive->mutationFingerprint())->not->toBe($restore->mutationFingerprint());
});

it('allows HQ floating masters and rejects fingerprint or illegal kitchen transitions', function () {
    $master = new FloatingMenuSectionPayload('Seasonal', 0, true, null, '2026-07-01', '2026-08-31');
    $section = new CreateFloatingMenuSectionCommand(tenantContext(), authorityUuid(110), null, $master, $master->fingerprint());
    $item = new MenuItemPayload(authorityUuid(111), authorityUuid(112), 0);

    expect($section->branchId)->toBeNull()
        ->and(new PlaceFloatingMenuProductCommand(tenantContext(), authorityUuid(110), $item, $item->fingerprint()))->toBeInstanceOf(PlaceFloatingMenuProductCommand::class)
        ->and(fn () => new PlaceFloatingMenuProductCommand(tenantContext(), authorityUuid(110), $item, str_repeat('a', 64)))->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(new AdvanceOrderItemKitchenCommand(tenantContext(), authorityUuid(113), authorityUuid(114), OrderItemStatusEnum::Pending, OrderItemStatusEnum::Preparing, '2026-07-22T12:00:00+07:00'))->toBeInstanceOf(AdvanceOrderItemKitchenCommand::class)
        ->and(fn () => new AdvanceOrderItemKitchenCommand(tenantContext(), authorityUuid(113), authorityUuid(114), OrderItemStatusEnum::Pending, OrderItemStatusEnum::Ready, '2026-07-22T12:00:00+07:00'))->toThrow(InvalidArgumentException::class, 'one legal forward')
        ->and(fn () => new AdvanceOrderItemKitchenCommand(tenantContext(), authorityUuid(113), authorityUuid(114), OrderItemStatusEnum::Pending, OrderItemStatusEnum::Preparing, 'tomorrow'))->toThrow(InvalidArgumentException::class, 'ISO-8601');
});

it('binds every shared lifecycle and layout command to one explicit route action', function () {
    $product = new ProductLifecycleCommand(tenantContext(), authorityUuid(190), authorityUuid(192), ProductLifecycleAction::Submit);
    $variant = new VariantUnitLifecycleCommand(tenantContext(), authorityUuid(191), authorityUuid(193), VariantUnitLifecycleAction::Remove);
    $menu = new MenuLifecycleCommand(tenantContext(), authorityUuid(192), MenuLifecycleAction::Archive);
    $layoutPayload = new MenuLayoutPayload([]);
    $layout = new ReplaceMenuLayoutCommand(tenantContext(), authorityUuid(192), $layoutPayload, $layoutPayload->fingerprint(), MenuLayoutMutation::ReorderSections);

    expect(fn () => $product->assertAction(ProductLifecycleAction::Submit))->not->toThrow(LogicException::class)
        ->and(fn () => $product->assertAction(ProductLifecycleAction::Approve))->toThrow(LogicException::class, 'does not match')
        ->and(fn () => $variant->assertAction(VariantUnitLifecycleAction::MakeBase))->toThrow(LogicException::class, 'does not match')
        ->and(fn () => $menu->assertAction(MenuLifecycleAction::Restore))->toThrow(LogicException::class, 'does not match')
        ->and(fn () => $layout->assertOperation(MenuLayoutMutation::ReorderProducts))->toThrow(LogicException::class, 'does not match');
});
