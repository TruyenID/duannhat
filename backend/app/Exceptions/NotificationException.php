<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Exception for business-rule violations in `NotificationService::dispatch` —
 * empty recipients, cross-org fan-out, unregistered morph types, etc.
 *
 * Rendered as HTTP 422 with a stable `error` code so callers can branch on
 * the reason programmatically.
 */
class NotificationException extends \RuntimeException
{
    /**
     * @param  string  $errorCode  Stable machine-readable code (snake_case).
     * @param  string  $message  Human-readable message.
     * @param  int  $statusCode  HTTP status when rendered directly (default 422).
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 422,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => $this->errorCode,
        ], $this->statusCode);
    }

    public static function recipientsEmpty(): self
    {
        return new self('recipients_empty', 'Notification dispatch requires at least one recipient.');
    }

    public static function notNotifiable(string $class): self
    {
        return new self(
            'not_notifiable',
            "Recipient class [{$class}] does not implement App\\Contracts\\Notifiable.",
        );
    }

    public static function unregisteredMorphType(string $class): self
    {
        return new self(
            'unregistered_morph_type',
            "Class [{$class}] is not registered in the morph map (enforceMorphMap).",
        );
    }

    public static function crossOrgRecipient(string $recipientId, string $organizationId): self
    {
        return new self(
            'cross_org_recipient',
            "Recipient [{$recipientId}] does not belong to organization [{$organizationId}].",
        );
    }

    public static function actorColumnMismatch(): self
    {
        return new self(
            'actor_column_mismatch',
            'actor_type and actor_id must be both null or both non-null.',
        );
    }

    public static function subjectColumnMismatch(): self
    {
        return new self(
            'subject_column_mismatch',
            'subject_type and subject_id must be both null or both non-null.',
        );
    }

    public static function bulkOperationMismatch(): self
    {
        return new self(
            'bulk_operation_mismatch',
            'Bulk operation requires either a non-empty ids list OR an all-flag — not both, not neither.',
        );
    }

    public static function bulkIdNotForCaller(): self
    {
        return new self(
            'bulk_id_not_for_caller',
            'Bulk operation rejected: one or more ids are not recipient rows for the caller.',
        );
    }

    public static function audienceTooLarge(int $resolved, int $max): self
    {
        return new self(
            'audience_too_large',
            "Audience resolved to {$resolved} recipients; maximum is {$max}. Narrow the rule before dispatching.",
        );
    }

    public static function unknownResolverType(string $type): self
    {
        return new self(
            'unknown_resolver_type',
            "Audience rule uses unknown sub-rule type [{$type}]. Register a matching AudienceResolver.",
        );
    }

    public static function audienceRequiresBrand(): self
    {
        return new self(
            'audience_requires_brand',
            'Dispatching with an Audience recipient requires a `brand` key (Brand model) in the dispatch input.',
        );
    }

    /**
     * Plan-023 M3 T3.9 — admin tried to cancel a schedule within 60s of
     * its next occurrence; the tick worker may already be materialising
     * it and the dispatched Notification can't be pulled back from
     * recipient inboxes.
     */
    public static function withinFreezeWindow(int $remainingSeconds): self
    {
        return new self(
            'within_freeze_window',
            "Cannot cancel — next occurrence fires in {$remainingSeconds}s, inside the 60-second freeze window.",
        );
    }

    /**
     * Admin tried to delete an audience that is still referenced by one
     * or more notification schedules. The DB FK is RESTRICT, so the
     * delete would otherwise surface as a PDO 23000 constraint error.
     * Detach the schedules first (cancel or reassign) before retrying.
     */
    public static function audienceInUse(int $scheduleCount): self
    {
        return new self(
            'audience_in_use',
            "Cannot delete — audience is still referenced by {$scheduleCount} active or paused schedule(s). Cancel them first.",
        );
    }
}
