<?php

namespace App\Services\Payment\Orchestration\Support;

use Illuminate\Support\Facades\Log;

final class PaymentOrchestrationMetrics
{
    /** @param array<string, mixed> $context */
    public static function incrementAttemptState(string $state, array $context = []): void
    {
        Log::channel('payment_orchestration')->info('payment_attempt_state', array_merge([
            'metric' => 'payment_attempt_state_total',
            'state' => $state,
            'value' => 1,
        ], PaymentOrchestrationLogContext::redact($context)));
    }

    /** @param array<string, mixed> $context */
    public static function incrementRefundState(string $state, array $context = []): void
    {
        Log::channel('payment_orchestration')->info('payment_refund_state', array_merge([
            'metric' => 'payment_refund_state_total',
            'state' => $state,
            'value' => 1,
        ], PaymentOrchestrationLogContext::redact($context)));
    }

    /** @param array<string, mixed> $context */
    public static function incrementLegacyPath(string $transport, string $operation, array $context = []): void
    {
        Log::channel('payment_orchestration')->info('orchestrator_legacy_path', array_merge([
            'metric' => 'orchestrator_legacy_path_total',
            'transport' => $transport,
            'operation' => $operation,
            'reason' => 'transport_kill_switch',
            'value' => 1,
        ], PaymentOrchestrationLogContext::redact($context)));
    }
}
