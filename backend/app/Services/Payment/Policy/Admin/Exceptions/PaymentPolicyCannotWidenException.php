<?php

namespace App\Services\Payment\Policy\Admin\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

final class PaymentPolicyCannotWidenException extends Exception
{
    public function __construct(
        string $message = 'Payment policy cannot be widened beyond upstream constraints.',
        public readonly string $errorCode = 'PAYMENT_POLICY_CANNOT_WIDEN',
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
        ], 409);
    }
}
