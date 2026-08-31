<?php

namespace Tests\Contracts\Payment;

enum ProviderScenario: string
{
    case CreateSucceeded = 'create_succeeded';
    case CaptureSucceeded = 'capture_succeeded';
    case CancelSucceeded = 'cancel_succeeded';
    case RefundSucceeded = 'refund_succeeded';
    case Declined = 'declined';
    case TimedOut = 'timed_out';
    case WebhookProcessing = 'webhook_processing';
    case WebhookAlternate = 'webhook_alternate';
    case CaptureDeclined = 'capture_declined';
    case CaptureTimedOut = 'capture_timed_out';
    case CancelDeclined = 'cancel_declined';
    case CancelTimedOut = 'cancel_timed_out';
    case RefundDeclined = 'refund_declined';
    case RefundTimedOut = 'refund_timed_out';
}
