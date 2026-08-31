<?php

declare(strict_types=1);

namespace App\Services\Payment\Reconciliation;

use App\Models\MoneyReconciliationTask;
use App\Support\Logging\MoneyOrchestrationLog;
use Ramsey\Uuid\Uuid;

/**
 * The write half of the reconciliation outbox (#1204 + #1206).
 *
 * Callers enqueue from INSIDE the transaction that decided the money, which is
 * the whole point of the pattern: the intent and the business write commit
 * together or not at all. A row enqueued outside that transaction can describe
 * a reversal that rolled back.
 *
 * Two properties this class exists to hold:
 *
 * 1. **It never throws.** Every call site is a fail-open path — a refund that
 *    already succeeded, a charge already taken. Bookkeeping must not be able to
 *    turn a completed money movement into an exception. A failure to enqueue is
 *    reported through the alertable channel and swallowed.
 *
 * 2. **It never rewrites a settled payload.** `payload` holds the figures the
 *    ORIGINAL transaction computed. A retry of the same request re-drives the
 *    same row (UNIQUE task_type+subject_type+subject_id) and deliberately
 *    leaves those numbers alone: the tip split and "how much was already
 *    reversed" depend on live state, so a second computation can legitimately
 *    differ, and the first one is the one that matches what actually happened.
 *    Issuing a tax document for a recomputed amount is the failure this design
 *    exists to prevent.
 */
final class MoneyReconciliationQueue
{
    /** A 適格返還請求書 was owed for a completed reversal and did not issue. */
    public const TYPE_RETURN_INVOICE = 'return_invoice';

    /** Money is held at the gateway with no automatic path back. */
    public const TYPE_STRANDED_CHARGE = 'stranded_charge';

    /** The ledger refused an overpayment that had already been collected. */
    public const TYPE_OVERPAYMENT_REJECTED = 'overpayment_rejected';

    public const SUBJECT_ORDER_PAYMENT = 'order_payment';

    public const SUBJECT_CUSTOMER_ORDER = 'customer_order';

    /**
     * A gateway charge that never became a ledger row — there IS no local id to
     * key on, which is the whole problem with a stranded charge.
     */
    public const SUBJECT_PAYMENT_INTENT = 'payment_intent';

    /**
     * Stable UUID for a gateway id, so `subject_id` stays a real UUID and the
     * unique key still means one task per CHARGE.
     *
     * Keying a stranded charge on its order instead would collapse two separate
     * charges on one order into a single row, and the second amount — real money
     * held at the gateway — would be dropped by the "never rewrite a settled
     * payload" rule. Derived rather than random so a webhook replay lands on the
     * same row.
     */
    public static function subjectIdForGatewayReference(string $gatewayReference): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'gateway-reference:'.$gatewayReference);
    }

    /**
     * Record work that must happen but could not happen here.
     *
     * @param  array<string, mixed>  $payload  Figures ALREADY settled by the
     *                                         calling transaction. The redrive
     *                                         reads these; it never recomputes.
     */
    public function enqueue(
        string $taskType,
        string $subjectType,
        string $subjectId,
        array $payload,
        string $organizationId,
        ?string $branchId = null,
        ?string $lastError = null,
    ): ?MoneyReconciliationTask {
        try {
            $existing = MoneyReconciliationTask::query()
                ->where('task_type', $taskType)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->first();

            if ($existing !== null) {
                // Already done — a duplicate request must not reopen finished
                // work and hand it to the redrive a second time.
                if ($existing->status === 'resolved') {
                    return $existing;
                }

                // Open row: refresh only what describes the LATEST failure.
                // payload stays as first written — see the class docblock.
                $existing->update(['last_error' => $lastError]);

                return $existing;
            }

            return MoneyReconciliationTask::create([
                'task_type' => $taskType,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'branch_id' => $branchId,
                'organization_id' => $organizationId,
                'payload' => $payload,
                'status' => 'pending',
                'attempts' => 0,
                'last_error' => $lastError,
                'next_retry_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // The queue is the safety net; it must not become the hazard. If
            // even this write fails, the money movement still stands and the
            // operator hears about it through the documented alerting path.
            MoneyOrchestrationLog::error(
                MoneyOrchestrationLog::TAG_RECONCILE,
                'reconciliation_enqueue_failed',
                [
                    'task_type' => $taskType,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'payload' => $payload,
                    'original_error' => $lastError,
                    'error' => $e->getMessage(),
                ],
            );

            return null;
        }
    }
}
