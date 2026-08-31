<?php

namespace App\Concerns;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\User;
use App\Omnify\Enums\ApprovalStatusEnum;

/**
 * Generic 4-state approval workflow (draft → pending → approved|rejected).
 *
 * Requires the consuming model to implement `App\Contracts\Approvable` —
 * `getApprovalStatus()` / `setApprovalStatus()` abstract away the model's
 * own status column so Product (status enum with lifecycle extras) and
 * Recipe (pure approval_status column) can share the same trait.
 *
 * Standardized columns used by this trait:
 *   approved_by_id, approved_at, rejected_by_id, rejected_at, rejection_reason
 *
 * Service-level policies (e.g. "cannot approve own submission", "must have
 * at least one SKU before submitting") stay in the service — the trait
 * is a state-machine primitive, not a business-rule layer.
 */
trait HasApprovalWorkflow
{
    /**
     * Throw InvalidStatusTransitionException if the current approval status
     * is not in the allowed set for $action.
     *
     * @param  array<ApprovalStatusEnum>  $allowed
     */
    public function assertApprovalStatus(array $allowed, string $action): void
    {
        $current = $this->getApprovalStatus();

        if (! in_array($current, $allowed, true)) {
            $allowedValues = array_map(fn (ApprovalStatusEnum $s) => $s->value, $allowed);

            throw new InvalidStatusTransitionException(
                from: $current->value,
                to: null,
                action: $action,
                allowed: $allowedValues,
                subject: strtolower(class_basename($this)),
            );
        }
    }

    /**
     * Transition to `pending`. Clears prior rejection metadata so the next
     * reviewer sees a clean approval slate. Caller is expected to have
     * already asserted the transition is legal.
     */
    public function markAsPending(): void
    {
        $this->setApprovalStatus(ApprovalStatusEnum::Pending);
        $this->rejected_by_id = null;
        $this->rejected_at = null;
        // `rejection_reason` intentionally preserved — kept for history
        // across a subsequent approval cycle, cleared only on explicit edit.
        $this->save();
    }

    /**
     * Transition to `approved`. Sets approver + timestamp. Caller enforces
     * "cannot approve own submission" at the service layer.
     */
    public function markAsApproved(User $approver): void
    {
        $this->setApprovalStatus(ApprovalStatusEnum::Approved);
        $this->approved_by_id = $approver->getKey();
        $this->approved_at = now();
        $this->rejected_by_id = null;
        $this->rejected_at = null;
        $this->save();
    }

    /**
     * Transition to `rejected`. Stores reviewer, timestamp and reason.
     */
    public function markAsRejected(User $reviewer, string $reason): void
    {
        $this->setApprovalStatus(ApprovalStatusEnum::Rejected);
        $this->rejected_by_id = $reviewer->getKey();
        $this->rejected_at = now();
        $this->rejection_reason = $reason;
        $this->save();
    }
}
