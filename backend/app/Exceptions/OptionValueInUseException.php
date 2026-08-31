<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Thrown when a ProductOptionValue cannot be modified or deleted because
 * one or more ProductSku rows reference it through option_value{N}_id.
 *
 * Renders as 409 Conflict with the list of blocking SKU codes.
 */
class OptionValueInUseException extends \RuntimeException
{
    /** @var array<int, array{id: string, sku: string}> */
    public array $blockingSkus;

    /**
     * @param  array<int, array{id: string, sku: string}>  $blockingSkus
     */
    public function __construct(string $message, array $blockingSkus = [])
    {
        parent::__construct($message);
        $this->blockingSkus = $blockingSkus;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'OPTION_VALUE_IN_USE',
            'blocking_skus' => $this->blockingSkus,
        ], 409);
    }
}
