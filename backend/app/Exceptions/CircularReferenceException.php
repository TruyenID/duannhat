<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

class CircularReferenceException extends \RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'CIRCULAR_REFERENCE',
        ], 422);
    }
}
