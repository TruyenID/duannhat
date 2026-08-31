<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Plan-023 M4 — thrown by `MailWebhookContract::verifySignature` when
 * the signature is invalid, missing, or the payload is older than the
 * provider's anti-replay window.
 *
 * Rendered as HTTP 401 with a stable `error` code so the provider's
 * retry logic can decide whether to back off (Postmark + SES both treat
 * any non-2xx as a retry signal — 401 from us means "give up, this is
 * a config mismatch", not "we're temporarily down").
 */
class WebhookVerificationException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        ?string $message = null,
    ) {
        parent::__construct($message ?? "Webhook verification failed: {$reason}");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => "webhook_{$this->reason}",
        ], 401);
    }
}
