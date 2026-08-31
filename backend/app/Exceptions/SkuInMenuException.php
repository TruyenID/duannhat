<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Thrown when a ProductSku cannot be deleted because one or more menus
 * still reference it through MenuProductSku rows.
 *
 * Renders as 409 Conflict with the list of blocking menu names.
 */
class SkuInMenuException extends \RuntimeException
{
    /** @var array<int, array{id: string, name: string}> */
    public array $blockingMenus;

    /**
     * @param  array<int, array{id: string, name: string}>  $blockingMenus
     */
    public function __construct(string $message, array $blockingMenus = [])
    {
        parent::__construct($message);
        $this->blockingMenus = $blockingMenus;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'SKU_IN_MENU',
            'blocking_menus' => $this->blockingMenus,
        ], 409);
    }
}
