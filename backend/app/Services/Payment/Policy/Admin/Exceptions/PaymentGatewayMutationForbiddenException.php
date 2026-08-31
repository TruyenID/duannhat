<?php

namespace App\Services\Payment\Policy\Admin\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

final class PaymentGatewayMutationForbiddenException extends Exception
{
    public function __construct(
        string $message = 'HQ-managed shops cannot mutate gateway connections.',
        public readonly string $errorCode = 'PAYMENT_GATEWAY_MUTATION_FORBIDDEN',
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
        ], 403);
    }
}
