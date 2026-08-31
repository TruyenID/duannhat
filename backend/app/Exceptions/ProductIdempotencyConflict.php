<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class ProductIdempotencyConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The idempotency key has already been used with a different product payload.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'IDEMPOTENCY_PAYLOAD_MISMATCH',
        ], 409);
    }
}
