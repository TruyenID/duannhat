<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * RFC 7807 — Problem Details for HTTP APIs.
 *
 * Thrown by KDS business rule guards (e.g. KdsBusinessRules).
 * Renders as a structured JSON response matching the kds-errors catalog
 * entry for the given code.
 *
 * Throw:
 *     throw new KdsRuleViolation('KDS_E001', 'Đơn đã đóng', ['order_id' => $id]);
 *
 * Custom remediation (overrides catalog default):
 *     throw new KdsRuleViolation('KDS_E001', 'Đơn đã đóng', $ctx, 'Liên hệ quản lý');
 */
class KdsRuleViolation extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $detail,
        public readonly array $context = [],
        public readonly string $remediation = '',
    ) {
        parent::__construct($detail);
    }

    public function render(): JsonResponse
    {
        $catalog = config('kds-errors');
        $entry = $catalog[$this->errorCode] ?? $catalog['DEFAULT'];

        return response()->json([
            'type' => $entry['type'],
            'title' => $entry['title'],
            'status' => $entry['status'],
            'code' => $this->errorCode,
            'detail' => $this->getMessage(),
            'context' => (object) $this->context,  // ensure JSON object, not array, even when empty
            'remediation' => $this->remediation !== '' ? $this->remediation : $entry['remediation'],
        ], $entry['status']);
    }
}
