<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * An offline-order replay whose evidence Cloud refuses to trust (#1096).
 *
 * Every rejection names its own reason so an operator can tell a CLOCK problem
 * from a REVOKED KEY from a FORGED signature without reading logs — and so
 * dashboards can separate "device needs re-pairing" from "possible tampering".
 * Rendering deliberately returns 422 rather than 4xx-generic: the request was
 * well-formed, the CLAIM was not acceptable.
 */
class OfflineEvidenceRejected extends \RuntimeException
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $meta */
    public static function because(string $reasonCode, string $message, array $meta = []): self
    {
        return new self($reasonCode, $message, $meta);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => 'OFFLINE_EVIDENCE_REJECTED',
            'reason_code' => $this->reasonCode,
            'meta' => $this->meta,
        ], 422);
    }
}
