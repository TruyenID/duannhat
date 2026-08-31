<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Thrown when a status transition is not allowed for the current state.
 *
 * Backward compatible with the legacy constructor signature
 * `new InvalidStatusTransitionException($message)` — existing call sites
 * (MenuService, StockTransactionService, ProductionOrderService, etc.)
 * continue to work unchanged while new call sites (HasApprovalWorkflow
 * trait) pass structured fields.
 */
class InvalidStatusTransitionException extends \RuntimeException
{
    public function __construct(
        ?string $message = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?string $action = null,
        /** @var array<string>|null */
        public readonly ?array $allowed = null,
        public readonly ?string $subject = null,
    ) {
        parent::__construct($message ?? $this->buildMessage());
    }

    private function buildMessage(): string
    {
        $subjectLabel = $this->subject ?? 'record';
        $action = $this->action ?? 'perform this action';
        $currentLabel = $this->from !== null ? "'{$this->from}'" : 'the current state';

        $message = "Cannot {$action}: {$subjectLabel} status is {$currentLabel}";

        if (! empty($this->allowed)) {
            $message .= ', expected one of: '.implode(', ', $this->allowed);
        }

        return $message.'.';
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'error' => 'INVALID_STATUS_TRANSITION',
            'from' => $this->from,
            'to' => $this->to,
            'action' => $this->action,
            'allowed' => $this->allowed,
        ], fn ($v) => $v !== null), 422);
    }
}
