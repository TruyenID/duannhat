<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Thrown by CustomerTableSessionService when a table's current status forbids
 * the requested transition (occupy/join/release). Carries the user-facing
 * message, the offending status, and the HTTP code (409 for occupy/release
 * conflicts, 423 Locked for join blockers) so the render matches what the
 * customer-table controller returned inline.
 */
class TableStateConflictException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $tableStatus,
        public readonly int $httpStatus = 409,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'status' => $this->tableStatus,
        ], $this->httpStatus);
    }
}
