<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Thrown when a ProductOption (or its values) cannot be modified or deleted
 * because one or more ProductSku rows reference it through option_value{N}_id.
 *
 * Renders as 409 Conflict with the list of blocking SKU codes so the client
 * can show the user exactly which SKUs need to be removed first.
 */
class OptionInUseException extends \RuntimeException
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
            'error' => 'OPTION_IN_USE',
            'blocking_skus' => $this->blockingSkus,
        ], 409);
    }
}
