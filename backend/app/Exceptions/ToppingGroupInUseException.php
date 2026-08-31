<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Thrown when a ToppingGroup is referenced by ≥1 Product via the
 * product_topping_groups pivot and a delete is attempted. Surfaces the
 * product list so the admin can detach upstream before retrying.
 */
class ToppingGroupInUseException extends \RuntimeException
{
    /**
     * @param  array<int, array{id: string, name: string|null}>  $usedBy
     */
    public function __construct(string $message, public readonly array $usedBy = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'TOPPING_GROUP_IN_USE',
            'used_by' => $this->usedBy,
        ], 409);
    }
}
