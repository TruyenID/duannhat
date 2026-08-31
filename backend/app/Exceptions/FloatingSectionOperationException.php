<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Domain exception for floating-section operations that are well-formed but
 * cannot be performed in the current state (e.g. cloning a branch clone,
 * cloning to a branch that already has a clone). Mirrors MenuOperationException.
 */
class FloatingSectionOperationException extends \RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'FLOATING_SECTION_OPERATION_NOT_ALLOWED',
        ], 422);
    }
}
