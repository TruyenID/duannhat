<?php

namespace App\Services\Payment\Orchestration\Support;

use App\Services\DomainMutation\MutationContext;

final class PaymentOrchestrationLogContext
{
    private const REDACTED_KEY_PATTERN = '/(?:secret|password|token|pan|card_number|cvv|cvc|api[_-]?key|authorization|bearer|whsec_|sk_(?:live|test)_|pk_(?:live|test)_)/i';

    /** @param array<string, mixed> $fields @return array<string, mixed> */
    public static function enrich(MutationContext $context, array $fields = []): array
    {
        return self::redact(array_merge([
            'correlation_id' => $context->correlationId,
            'organization_id' => $context->organizationId,
            'actor_id' => $context->actorId,
            'idempotency_key_hash' => $context->idempotencyKeyHash,
            'expected_version' => $context->expectedVersion,
        ], $fields));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public static function redact(array $payload): array
    {
        $redacted = [];
        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (preg_match(self::REDACTED_KEY_PATTERN, $key) === 1) {
                $redacted[$key] = '[REDACTED]';

                continue;
            }
            if (is_string($value) && preg_match('/(?:sk|pk)_(?:live|test)_[A-Za-z0-9]+|whsec_[A-Za-z0-9]+/', $value) === 1) {
                $redacted[$key] = '[REDACTED]';

                continue;
            }
            $redacted[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $redacted;
    }
}
