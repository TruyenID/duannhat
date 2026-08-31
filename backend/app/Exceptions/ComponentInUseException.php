<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

class ComponentInUseException extends \RuntimeException
{
    /** @var array<int, array{id: string, name: string}> */
    public array $usedBy;

    /**
     * @param  array<int, array{id: string, name: string}>  $usedBy
     */
    public function __construct(string $message, array $usedBy = [])
    {
        parent::__construct($message);
        $this->usedBy = $usedBy;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'COMPONENT_IN_USE',
            'used_by' => $this->usedBy,
        ], 422);
    }
}
