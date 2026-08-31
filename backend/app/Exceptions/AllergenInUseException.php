<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Thrown when an allergen is referenced by ≥1 Material via the
 * material_allergens pivot and a hard delete is attempted. Surfaces the
 * material list so the admin can detach upstream before retrying.
 */
class AllergenInUseException extends \RuntimeException
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
            'error' => 'ALLERGEN_IN_USE',
            'used_by' => $this->usedBy,
        ], 409);
    }
}
