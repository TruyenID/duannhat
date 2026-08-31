<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Thrown by CustomerTableSessionService when a QR token resolves to no active
 * table. Rendered as the same 404 the customer-table controller returned inline.
 */
class TableNotFoundException extends \RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => 'Table not found.'], 404);
    }
}
