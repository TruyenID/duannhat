<?php

namespace App\Services\Payment\Policy;

use App\Omnify\Enums\PaymentConnectionHealthEnum;
use App\Omnify\Enums\PaymentPolicyPreferenceEnum;
use App\Services\Payment\Gateway\Enums\CapabilityVerificationState;
use App\Services\Payment\Policy\Contracts\BranchManagementProjectionSource;
use App\Services\Payment\Policy\Enums\BranchManagementModel;
use App\Services\Payment\Policy\Enums\ConnectionCapabilityVerification;
use App\Services\Payment\Policy\Enums\DevicePolicyPreference;
use App\Services\Payment\Policy\Enums\PolicyDecision;
use App\Services\Payment\Policy\Enums\PolicyLayer;
use App\Services\Payment\Policy\Enums\PolicyReasonCode;
use App\Services\Payment\Policy\Enums\UpstreamPolicyState;
use App\Services\Payment\Policy\ValueObjects\BranchManagementProjection;
use App\Services\Payment\Policy\ValueObjects\EffectivePaymentOption;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicyCandidate;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicyRequest;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicyTraceEntry;
use Throwable;

/**
 * Resolves the intersection of authoritative ownership, provider, connection, capability, and
 * owner/shop/device/runtime policy. Every missing, conflicting, or unverified input denies access.
 */
final readonly class PaymentPolicyResolver
{
    public function __construct(
        private BranchManagementProjectionSource $ownershipSource,
    ) {}

    /**
     * @param  list<PaymentPolicyCandidate>  $candidates
     */
    public function resolve(PaymentPolicyRequest $request, array $candidates): EffectivePaymentOption
    {
        $trace = [];
        $ownership = $this->ownership($request);

        if (! $ownership->matches($request->ownershipLookup())) {
            return $this->deny(
                $request,
                null,
                $trace,
                PolicyLayer::Ownership,
                PolicyReasonCode::OwnershipScopeMismatch,
                PolicyDecision::Unresolved,
            );
        }

        if ($ownership->managementModel === BranchManagementModel::Unresolved) {
            $reason = $ownership->reason === 'ownership_source_unavailable'
                ? PolicyReasonCode::OwnershipSourceUnavailable
                : PolicyReasonCode::OwnershipUnresolved;

            return $this->deny(
                $request,
                null,
                $trace,
                PolicyLayer::Ownership,
                $reason,
                PolicyDecision::Unresolved,
            );
        }

        $trace[] = $this->entry(
            PolicyLayer::Ownership,
            PolicyDecision::Allowed,
            $ownership->managementModel === BranchManagementModel::HqManaged
                ? PolicyReasonCode::OwnershipResolvedHq
                : PolicyReasonCode::OwnershipResolvedFranchise,
        );

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof PaymentPolicyCandidate) {
                return $this->deny(
                    $request,
                    null,
                    $trace,
                    PolicyLayer::Connection,
                    PolicyReasonCode::ConnectionRequired,
                    PolicyDecision::Unresolved,
                );
            }
        }

        $scoped = array_values(array_filter(
            $candidates,
            static fn (PaymentPolicyCandidate $candidate): bool => $candidate->isInRequestScope($request),
        ));
        $selectedInScope = array_values(array_filter(
            $scoped,
            static fn (PaymentPolicyCandidate $candidate): bool => $candidate->selectedForShop,
        ));

        if (count($selectedInScope) > 1) {
            return $this->deny(
                $request,
                null,
                $trace,
                PolicyLayer::Connection,
                PolicyReasonCode::ConnectionAmbiguous,
                PolicyDecision::Unresolved,
            );
        }

        $owned = array_values(array_filter(
            $scoped,
            static fn (PaymentPolicyCandidate $candidate): bool => $candidate->matchesOwnership($request, $ownership),
        ));

        if ($selectedInScope !== [] && ! $selectedInScope[0]->matchesOwnership($request, $ownership)) {
            return $this->deny(
                $request,
                null,
                $trace,
                PolicyLayer::Connection,
                PolicyReasonCode::ConnectionRequired,
            );
        }

        if ($owned === []) {
            return $this->deny(
                $request,
                null,
                $trace,
                PolicyLayer::Connection,
                PolicyReasonCode::ConnectionRequired,
            );
        }

        $environmentMatched = array_values(array_filter(
            $owned,
            static fn (PaymentPolicyCandidate $candidate): bool => $candidate->environment === $request->environment,
        ));

        if ($selectedInScope !== [] && $selectedInScope[0]->environment !== $request->environment) {
            return $this->deny(
                $request,
                null,
                $trace,
                PolicyLayer::Connection,
                PolicyReasonCode::EnvironmentMismatch,
            );
        }

        if ($environmentMatched === []) {
            return $this->deny(
                $request,
                null,
                $trace,
                PolicyLayer::Connection,
                PolicyReasonCode::EnvironmentMismatch,
            );
        }

        $candidate = $selectedInScope[0] ?? $this->selectCandidate($environmentMatched);

        if ($candidate === null) {
            return $this->deny(
                $request,
                null,
                $trace,
                PolicyLayer::Connection,
                PolicyReasonCode::ConnectionAmbiguous,
                PolicyDecision::Unresolved,
            );
        }

        if (! $candidate->providerActive) {
            return $this->deny($request, $candidate, $trace, PolicyLayer::Provider, PolicyReasonCode::ProviderInactive);
        }

        $trace[] = $this->entry(PolicyLayer::Provider, PolicyDecision::Allowed, PolicyReasonCode::ProviderAvailable);

        $connectionFailure = $this->connectionFailure($candidate);
        if ($connectionFailure !== null) {
            return $this->deny($request, $candidate, $trace, PolicyLayer::Connection, $connectionFailure);
        }

        $trace[] = $this->entry(PolicyLayer::Connection, PolicyDecision::Allowed, PolicyReasonCode::ConnectionReady);

        $capabilityFailure = $this->capabilityFailure($request, $candidate);
        if ($capabilityFailure !== null) {
            return $this->deny($request, $candidate, $trace, PolicyLayer::Capability, $capabilityFailure);
        }

        $trace[] = $this->entry(PolicyLayer::Capability, PolicyDecision::Allowed, PolicyReasonCode::CapabilityAvailable);

        if ($candidate->ownerPolicy !== UpstreamPolicyState::Allowed) {
            return $this->deny(
                $request,
                $candidate,
                $trace,
                PolicyLayer::OwnerPolicy,
                $candidate->ownerPolicy === UpstreamPolicyState::Denied
                    ? PolicyReasonCode::OwnerPolicyBlocked
                    : PolicyReasonCode::OwnerPolicyUnresolved,
                $candidate->ownerPolicy === UpstreamPolicyState::Denied
                    ? PolicyDecision::Denied
                    : PolicyDecision::Unresolved,
            );
        }

        $trace[] = $this->entry(PolicyLayer::OwnerPolicy, PolicyDecision::Allowed, PolicyReasonCode::OwnerPolicyAllowed);

        $shopFailure = match ($candidate->shopPreference) {
            PaymentPolicyPreferenceEnum::Disabled => PolicyReasonCode::ShopDisabled,
            PaymentPolicyPreferenceEnum::Blocked => PolicyReasonCode::ShopBlocked,
            default => null,
        };
        if ($shopFailure !== null) {
            return $this->deny($request, $candidate, $trace, PolicyLayer::Shop, $shopFailure);
        }

        $trace[] = $this->entry(
            PolicyLayer::Shop,
            PolicyDecision::Allowed,
            $candidate->shopPreference === PaymentPolicyPreferenceEnum::Enabled
                ? PolicyReasonCode::ShopEnabled
                : PolicyReasonCode::ShopInherited,
        );

        if ($candidate->devicePreference === DevicePolicyPreference::Disabled) {
            return $this->deny($request, $candidate, $trace, PolicyLayer::Device, PolicyReasonCode::DeviceDisabled);
        }

        $trace[] = $this->entry(PolicyLayer::Device, PolicyDecision::Allowed, PolicyReasonCode::DeviceInherited);

        if (! $candidate->runtimeAvailable) {
            return $this->deny($request, $candidate, $trace, PolicyLayer::Runtime, PolicyReasonCode::RuntimeUnavailable);
        }

        $trace[] = $this->entry(PolicyLayer::Runtime, PolicyDecision::Allowed, PolicyReasonCode::RuntimeAvailable);

        return $this->result($request, $candidate, true, PolicyReasonCode::Effective, $trace);
    }

    private function ownership(PaymentPolicyRequest $request): BranchManagementProjection
    {
        $lookup = $request->ownershipLookup();

        try {
            return $this->ownershipSource->resolve($lookup);
        } catch (Throwable) {
            return BranchManagementProjection::unresolved($lookup, 'ownership_source_unavailable');
        }
    }

    /** @param list<PaymentPolicyCandidate> $candidates */
    private function selectCandidate(array $candidates): ?PaymentPolicyCandidate
    {
        $selected = array_values(array_filter(
            $candidates,
            static fn (PaymentPolicyCandidate $candidate): bool => $candidate->selectedForShop,
        ));

        if (count($selected) === 1) {
            return $selected[0];
        }

        if ($selected !== []) {
            return null;
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function connectionFailure(PaymentPolicyCandidate $candidate): ?PolicyReasonCode
    {
        if (! $candidate->connectionActive) {
            return PolicyReasonCode::ConnectionInactive;
        }

        return match ($candidate->connectionHealth) {
            PaymentConnectionHealthEnum::Ready => null,
            PaymentConnectionHealthEnum::PendingVerification => PolicyReasonCode::ConnectionPendingVerification,
            PaymentConnectionHealthEnum::Degraded => PolicyReasonCode::ConnectionDegraded,
            PaymentConnectionHealthEnum::Unavailable => PolicyReasonCode::ConnectionUnavailable,
            PaymentConnectionHealthEnum::Restricted => PolicyReasonCode::ConnectionRestricted,
            PaymentConnectionHealthEnum::Revoked => PolicyReasonCode::ConnectionRevoked,
        };
    }

    private function capabilityFailure(
        PaymentPolicyRequest $request,
        PaymentPolicyCandidate $candidate,
    ): ?PolicyReasonCode {
        $capability = $candidate->catalogCapability;

        if (! $candidate->optionActive) {
            return PolicyReasonCode::CapabilityInactive;
        }

        if ($capability->provider !== $candidate->connectionProvider) {
            return PolicyReasonCode::CapabilityUnverified;
        }

        if ($capability->environment !== $request->environment) {
            return PolicyReasonCode::EnvironmentMismatch;
        }

        if (! $capability->appliesAt($request->operationStartedAt)
            || ! $candidate->connectionCapability->appliesAt($request->operationStartedAt)) {
            return PolicyReasonCode::CapabilityExpired;
        }

        if ($capability->verification->state !== CapabilityVerificationState::Verified
            || ! $capability->verification->hasApplicableEvidence($request->operationStartedAt)
            || ! $candidate->connectionCapability->matchesCatalog($capability, $request->paymentBrand)
            || $candidate->connectionCapability->verification !== ConnectionCapabilityVerification::Verified
            || ! $candidate->connectionCapability->enabled
            || $candidate->connectionCapability->evidenceReference === null) {
            return PolicyReasonCode::CapabilityUnverified;
        }

        if ($capability->currency($request->currency) === null
            || ! in_array($request->currency, $candidate->connectionCapability->approvedCurrencies, true)) {
            return PolicyReasonCode::CurrencyUnsupported;
        }

        if (! in_array($request->channel, $capability->channels, true)
            || ! in_array($request->channel, $candidate->connectionCapability->approvedChannels, true)) {
            return PolicyReasonCode::ChannelUnsupported;
        }

        if (! in_array($request->deviceClass, $capability->deviceClasses, true)) {
            return PolicyReasonCode::DeviceClassUnsupported;
        }

        if (! in_array($request->deviceClass, $candidate->connectionCapability->approvedDeviceClasses, true)) {
            return PolicyReasonCode::DeviceClassUnsupported;
        }

        if (! $candidate->connectionCapability->supportsOperation($request->operation, $request->capabilityFacts)
            || ! $capability->supports(
                $request->operation,
                $request->operationStartedAt,
                $request->capabilityFacts,
            )) {
            return PolicyReasonCode::OperationUnsupported;
        }

        return null;
    }

    /** @param list<PaymentPolicyTraceEntry> $trace */
    private function deny(
        PaymentPolicyRequest $request,
        ?PaymentPolicyCandidate $candidate,
        array $trace,
        PolicyLayer $layer,
        PolicyReasonCode $reason,
        PolicyDecision $decision = PolicyDecision::Denied,
    ): EffectivePaymentOption {
        $trace[] = $this->entry($layer, $decision, $reason);

        return $this->result($request, $candidate, false, $reason, $trace);
    }

    /** @param list<PaymentPolicyTraceEntry> $trace */
    private function result(
        PaymentPolicyRequest $request,
        ?PaymentPolicyCandidate $candidate,
        bool $effective,
        PolicyReasonCode $reason,
        array $trace,
    ): EffectivePaymentOption {
        return new EffectivePaymentOption(
            $request->optionId,
            $request->correlationId,
            $effective,
            $reason,
            $candidate?->connectionId,
            $candidate?->connectionOptionId,
            $candidate?->shopOptionId,
            $candidate?->ownerScope,
            $candidate?->operatorOrgUnitId,
            $candidate?->ownershipRevision,
            $trace,
        );
    }

    private function entry(
        PolicyLayer $layer,
        PolicyDecision $decision,
        PolicyReasonCode $reason,
    ): PaymentPolicyTraceEntry {
        return new PaymentPolicyTraceEntry($layer, $decision, $reason);
    }
}
