<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Thrown when a product type still has products attached and a delete is attempted.
 */
class ProductTypeInUseException extends \RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'PRODUCT_TYPE_IN_USE',
        ], 409);
    }
}
