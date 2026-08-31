<?php

namespace App\Services\Payment\Configuration\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class PaymentConfigurationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly bool $retryable = false,
        public readonly ?string $action = null,
        public readonly array $details = [],
        public readonly ?string $correlationId = null,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'correlation_id' => $this->correlationId ?? (string) request()->header('X-Correlation-Id', ''),
            'retryable' => $this->retryable,
            'action' => $this->action,
            'details' => $this->details,
        ], $this->status);
    }
}
