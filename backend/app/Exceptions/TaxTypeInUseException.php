<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Thrown when a tax type is still referenced by a product, a menu product
 * override, or a branch default and a delete is attempted (plan-043 Decision 5 —
 * deactivate over delete; RESTRICT FKs are the DB backstop).
 *
 * Carries the per-reference usage counts so the client can render the
 * "Deactivate instead" alert with exact numbers.
 */
class TaxTypeInUseException extends \RuntimeException
{
    /**
     * @param  array<string, int>  $usage  reference counts keyed by kind
     *                                     (products / menu_products / branch_defaults / brand_default)
     */
    public function __construct(
        string $message,
        public readonly array $usage,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'TAX_TYPE_IN_USE',
            'message' => $this->getMessage(),
            // Merge so extra kinds (e.g. brand_default from the deactivation
            // guard — audit fix 3.8) survive while the three classic keys stay
            // always-present for existing consumers.
            'meta' => array_merge(
                ['products' => 0, 'menu_products' => 0, 'branch_defaults' => 0],
                $this->usage,
            ),
        ], 409);
    }
}
