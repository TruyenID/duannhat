<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

class InsufficientStockException extends \RuntimeException
{
    /**
     * @param  array<int, array{product_sku_id?: string, material_id?: string, requested: float, available: float}>  $shortages
     */
    public function __construct(
        public readonly string $warehouseId,
        public readonly array $shortages,
        string $message = 'Insufficient stock for one or more items.',
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'INSUFFICIENT_STOCK',
            'warehouse_id' => $this->warehouseId,
            'shortages' => $this->shortages,
        ], 422);
    }
}
