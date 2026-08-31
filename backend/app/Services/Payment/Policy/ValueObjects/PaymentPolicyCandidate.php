<?php

namespace App\Services\Payment\Policy\ValueObjects;

use App\Omnify\Enums\PaymentConnectionHealthEnum;
use App\Omnify\Enums\PaymentConnectionOwnerScopeEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentPolicyPreferenceEnum;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\GatewayValue;
use App\Services\Payment\Policy\Enums\DevicePolicyPreference;
use App\Services\Payment\Policy\Enums\UpstreamPolicyState;
use InvalidArgumentException;

/**
 * Secret-free snapshot assembled by a trusted persistence adapter.
 *
 * The resolver deliberately consumes this immutable shape instead of Eloquent models. The future
 * adapter must prove every cross-row tenant and ownership invariant before constructing it.
 */
final readonly class PaymentPolicyCandidate extends GatewayValue
{
    public string $optionId;

    public ?string $paymentBrand;

    public string $connectionId;

    public string $connectionOptionId;

    public string $shopOptionId;

    public string $organizationId;

    public string $brandId;

    public string $branchId;

    public string $identityBrandId;

    public ?string $ownerBranchId;

    public string $brandOwnerOrgUnitId;

    public string $operatorOrgUnitId;

    public string $ownershipRevision;

    public ?string $deviceId;

    public function __construct(
        string $optionId,
        ?string $paymentBrand,
        string $connectionId,
        string $connectionOptionId,
        string $shopOptionId,
        string $organizationId,
        string $brandId,
        string $branchId,
        string $identityBrandId,
        public PaymentConnectionOwnerScopeEnum $ownerScope,
        ?string $ownerBranchId,
        string $brandOwnerOrgUnitId,
        string $operatorOrgUnitId,
        string $ownershipRevision,
        public PaymentGatewayProviderCodeEnum $connectionProvider,
        public PaymentGatewayEnvironmentEnum $environment,
        public bool $providerActive,
        public bool $optionActive,
        public bool $connectionActive,
        public PaymentConnectionHealthEnum $connectionHealth,
        public CapabilitySet $catalogCapability,
        public ConnectionApprovedCapability $connectionCapability,
        public UpstreamPolicyState $ownerPolicy,
        public PaymentPolicyPreferenceEnum $shopPreference,
        ?string $deviceId,
        public DevicePolicyPreference $devicePreference,
        public bool $runtimeAvailable,
        public bool $selectedForShop = false,
    ) {
        $this->optionId = self::uuid($optionId, 'optionId');
        $this->paymentBrand = PaymentBrand::normalize($paymentBrand);
        $this->connectionId = self::uuid($connectionId, 'connectionId');
        $this->connectionOptionId = self::uuid($connectionOptionId, 'connectionOptionId');
        $this->shopOptionId = self::uuid($shopOptionId, 'shopOptionId');
        $this->organizationId = self::uuid($organizationId, 'organizationId');
        $this->brandId = self::uuid($brandId, 'brandId');
        $this->branchId = self::uuid($branchId, 'branchId');
        $this->identityBrandId = self::uuid($identityBrandId, 'identityBrandId');
        $this->ownerBranchId = $ownerBranchId === null ? null : self::uuid($ownerBranchId, 'ownerBranchId');
        $this->brandOwnerOrgUnitId = self::uuid($brandOwnerOrgUnitId, 'brandOwnerOrgUnitId');
        $this->operatorOrgUnitId = self::uuid($operatorOrgUnitId, 'operatorOrgUnitId');
        $this->ownershipRevision = OpaqueOwnershipRevision::validate($ownershipRevision);
        $this->deviceId = $deviceId === null ? null : self::uuid($deviceId, 'deviceId');

        if ($ownerScope === PaymentConnectionOwnerScopeEnum::Hq && $this->ownerBranchId !== null) {
            throw new InvalidArgumentException('An HQ-owned connection cannot name a franchise owner branch.');
        }

        if ($ownerScope === PaymentConnectionOwnerScopeEnum::Franchise && $this->ownerBranchId === null) {
            throw new InvalidArgumentException('A franchise-owned connection requires its local owner branch.');
        }

        if ($this->deviceId === null && $devicePreference !== DevicePolicyPreference::Inherit) {
            throw new InvalidArgumentException('A device restriction requires an exact device identity.');
        }
    }

    public function isInRequestScope(PaymentPolicyRequest $request): bool
    {
        return $this->optionId === $request->optionId
            && $this->paymentBrand === $request->paymentBrand
            && $this->organizationId === $request->organizationId
            && $this->brandId === $request->brandId
            && $this->branchId === $request->branchId
            && $this->identityBrandId === $request->identityBrandId
            && $this->deviceId === $request->deviceId;
    }

    public function matchesOwnership(PaymentPolicyRequest $request, BranchManagementProjection $ownership): bool
    {
        if ($ownership->ownerScope() !== $this->ownerScope
            || $ownership->brandOwnerOrgUnitId !== $this->brandOwnerOrgUnitId
            || $ownership->operatorOrgUnitId !== $this->operatorOrgUnitId
            || $ownership->ownershipRevision !== $this->ownershipRevision) {
            return false;
        }

        return match ($this->ownerScope) {
            PaymentConnectionOwnerScopeEnum::Hq => $this->ownerBranchId === null,
            PaymentConnectionOwnerScopeEnum::Franchise => $this->ownerBranchId === $request->branchId,
        };
    }
}
