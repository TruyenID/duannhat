<?php

namespace App\Services\Payment\Policy\Enums;

enum PolicyReasonCode: string
{
    case OwnershipResolvedHq = 'ownership_resolved_hq';
    case OwnershipResolvedFranchise = 'ownership_resolved_franchise';
    case OwnershipUnresolved = 'ownership_unresolved';
    case OwnershipSourceUnavailable = 'ownership_source_unavailable';
    case OwnershipScopeMismatch = 'ownership_scope_mismatch';
    case ProviderAvailable = 'provider_available';
    case ProviderInactive = 'provider_inactive';
    case ConnectionReady = 'connection_ready';
    case ConnectionRequired = 'connection_required';
    case ConnectionAmbiguous = 'connection_ambiguous';
    case ConnectionInactive = 'connection_inactive';
    case ConnectionPendingVerification = 'connection_pending_verification';
    case ConnectionDegraded = 'connection_degraded';
    case ConnectionUnavailable = 'connection_unavailable';
    case ConnectionRestricted = 'connection_restricted';
    case ConnectionRevoked = 'connection_revoked';
    case EnvironmentMismatch = 'environment_mismatch';
    case CapabilityAvailable = 'capability_available';
    case CapabilityInactive = 'capability_inactive';
    case CapabilityUnverified = 'capability_unverified';
    case CapabilityExpired = 'capability_expired';
    case CurrencyUnsupported = 'currency_unsupported';
    case ChannelUnsupported = 'channel_unsupported';
    case DeviceClassUnsupported = 'device_class_unsupported';
    case OperationUnsupported = 'operation_unsupported';
    case OwnerPolicyAllowed = 'owner_policy_allowed';
    case OwnerPolicyBlocked = 'owner_policy_blocked';
    case OwnerPolicyUnresolved = 'owner_policy_unresolved';
    case ShopInherited = 'shop_inherited';
    case ShopEnabled = 'shop_enabled';
    case ShopDisabled = 'shop_disabled';
    case ShopBlocked = 'shop_blocked';
    case DeviceInherited = 'device_inherited';
    case DeviceDisabled = 'device_disabled';
    case RuntimeAvailable = 'runtime_available';
    case RuntimeUnavailable = 'runtime_unavailable';
    case Effective = 'effective';

    public function publicErrorCode(): ?string
    {
        return match ($this) {
            self::OwnershipUnresolved,
            self::OwnershipSourceUnavailable,
            self::OwnershipScopeMismatch => 'PAYMENT_OWNERSHIP_UNRESOLVED',
            self::ConnectionRequired,
            self::ConnectionAmbiguous,
            self::ConnectionInactive,
            self::ConnectionPendingVerification,
            self::ConnectionRevoked => 'PAYMENT_CONNECTION_REQUIRED',
            self::ConnectionDegraded,
            self::ConnectionUnavailable,
            self::RuntimeUnavailable => 'PAYMENT_CONNECTION_UNAVAILABLE',
            self::ConnectionRestricted => 'PAYMENT_CONNECTION_RESTRICTED',
            self::EnvironmentMismatch => 'PAYMENT_ENVIRONMENT_MISMATCH',
            self::CurrencyUnsupported => 'PAYMENT_CURRENCY_UNSUPPORTED',
            self::ChannelUnsupported,
            self::DeviceClassUnsupported => 'PAYMENT_CHANNEL_UNSUPPORTED',
            self::OperationUnsupported => 'PAYMENT_OPERATION_UNSUPPORTED',
            self::ProviderInactive,
            self::CapabilityInactive,
            self::CapabilityUnverified,
            self::CapabilityExpired => 'PAYMENT_CAPABILITY_UNAVAILABLE',
            self::OwnerPolicyBlocked,
            self::OwnerPolicyUnresolved,
            self::ShopDisabled,
            self::ShopBlocked,
            self::DeviceDisabled => 'PAYMENT_OPTION_DISABLED',
            default => null,
        };
    }
}
