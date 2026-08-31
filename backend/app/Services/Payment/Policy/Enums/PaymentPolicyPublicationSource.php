<?php

namespace App\Services\Payment\Policy\Enums;

/** Closed, secret-free causes for branch-level effective-policy publication. */
enum PaymentPolicyPublicationSource: string
{
    case Initial = 'initial';
    case OwnershipChanged = 'ownership_changed';
    case CapabilityChanged = 'capability_changed';
    case ConnectionChanged = 'connection_changed';
    case OwnerPolicyChanged = 'owner_policy_changed';
    case ShopPolicyChanged = 'shop_policy_changed';
    case DevicePolicyChanged = 'device_policy_changed';
    case RuntimeHealthChanged = 'runtime_health_changed';
    case ManualRepublish = 'manual_republish';
}
