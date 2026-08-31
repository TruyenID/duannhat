<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Domain exception for menu operations that are well-formed but cannot be
 * performed in the current state (e.g. cloning a master menu twice, syncing
 * a non-cloned branch, deleting an active menu).
 *
 * Laravel auto-detects the `render()` method and returns a 422 JSON response
 * to the client instead of a generic 500.
 */
class MenuOperationException extends \RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'MENU_OPERATION_NOT_ALLOWED',
        ], 422);
    }
}
