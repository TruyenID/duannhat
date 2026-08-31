<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * plan-045 — order-item refund guard failures. Refunds APPEND a negative-value
 * line (never mutate the original); these guards protect the accumulator and the
 * refundable state. Structured error_code so pos-web / workstation can branch.
 */
class RefundException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 422,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }

    public static function exceedsQuantity(float $requested, float $refundable): self
    {
        return new self(
            "Refund quantity {$requested} exceeds the refundable {$refundable}.",
            'REFUND_EXCEEDS_QUANTITY',
            422,
            ['requested' => $requested, 'refundable' => $refundable],
        );
    }

    public static function cannotRefundRefundLine(): self
    {
        return new self(
            'A refund line cannot itself be refunded.',
            'CANNOT_REFUND_REFUND_LINE',
            422,
        );
    }

    public static function orderNotRefundable(string $status): self
    {
        return new self(
            "Order in status [{$status}] cannot be refunded.",
            'ORDER_NOT_REFUNDABLE',
            409,
            ['status' => $status],
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
            'meta' => $this->meta,
        ], $this->status);
    }
}
