<?php

namespace App\Services\Payment\Gateway\Enums;

enum CapabilityFact: string
{
    case AttemptProviderState = 'attempt_provider_state';
    case AuthorizationWindowOpen = 'authorization_window_open';
    case CaptureMethod = 'capture_method';
    case ConnectionMultipleRefundsEnabled = 'connection_multiple_refunds_enabled';
    case ConnectionPartialRefundEnabled = 'connection_partial_refund_enabled';
    case MerchantCapabilityEnabled = 'merchant_capability_enabled';
    case ProviderCancelWindowOpen = 'provider_cancel_window_open';
}
