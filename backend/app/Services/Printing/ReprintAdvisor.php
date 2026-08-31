<?php

namespace App\Services\Printing;

use App\Models\User;
use App\Services\Printing\Enums\PrintJobKind;

/**
 * plan-052 §4 / P-10 (#1166) — WARN, NEVER BLOCK.
 *
 * This class used to be `ReprintAuthorizer` and it used to throw a 422 at a
 * cashier who reprinted a receipt without typing a reason. The owner reversed
 * that on 2026-07-28, and the reasoning is worth keeping in front of whoever
 * reads this next: the moment that refusal fires, a real customer is standing
 * at a real counter next to a printer that has genuinely just jammed. Refusing
 * to print there costs the shop more than every fraud the gate could prevent —
 * and it prevents very little, because a determined person simply reprints with
 * the word "khách yêu cầu" typed in.
 *
 * So there is no `assert()` any more and nothing in this file throws. What the
 * shop gets instead is a trail that cannot be dodged (DESIGN §4):
 *
 *   1. **COUNT** — `reprint_no` from `AppendPrintHistory`, unchanged (P-12).
 *   2. **WHO** — actor + channel on EVERY ledger row, first print included.
 *   3. **THE MARK ON THE PAPER** — copy N ≥ 2 prints 「再印刷 #N」 from a
 *      `locked` block (P-10b). Two sheets can never both look like an original.
 *
 * Role no longer decides anything. It only changes the SEVERITY of the nudge:
 * a manager reprinting without a reason is a notice ("manager did it" is itself
 * the audit answer, #1124), a cashier doing it is a warning the reconcile
 * report will list. Neither is a refusal.
 *
 * Unchanged by this ruling: a money document is still never **auto**-retried
 * (PR1). A machine deciding on its own to emit a second original is a different
 * thing entirely from a human choosing to press print.
 */
class ReprintAdvisor
{
    /**
     * Never throws. There is no input for which this returns "no".
     */
    public function advise(
        PrintJobKind $kind,
        int $reprintNo,
        ?User $actor,
        ?string $reason,
        string $organizationId,
        string $branchId,
    ): ReprintAdvice {
        $reprintNo = max(1, $reprintNo);
        $reason = $this->normaliseReason($reason);

        $isManager = $this->isManager($actor, $organizationId, $branchId);
        $promptForReason = $this->requiresReasonPrompt($kind, $reprintNo);
        $markerWillPrint = $this->markerWillPrint($reprintNo);
        $warnedWithoutReason = $promptForReason && $reason === null;

        $warnings = [];

        if ($warnedWithoutReason) {
            $warnings[] = [
                'code' => 'reprint_reason_missing',
                // The only thing role changes. Both print.
                'severity' => $isManager ? 'notice' : 'warning',
                'message' => $isManager
                    ? 'Reprinting a money document without a reason. A manager may do this; '
                        .'the ledger records who, and that no reason was given.'
                    : 'Reprinting a money document without a reason. This still prints — '
                        .'the ledger records who did it and flags the missing reason for a manager to review.',
                'params' => ['reprint_no' => $reprintNo, 'kind' => $kind->value],
            ];
        }

        if ($markerWillPrint) {
            $warnings[] = [
                'code' => 'reprint_marker_will_print',
                'severity' => 'info',
                'message' => 'This sheet will carry the 「再印刷 #'.$reprintNo.'」 mark. '
                    .'It cannot be switched off (the block is locked).',
                'params' => ['reprint_no' => $reprintNo],
            ];
        }

        return new ReprintAdvice(
            kind: $kind,
            reprintNo: $reprintNo,
            isMoneyDocument: $kind->isMoneyDocument(),
            requiresReasonPrompt: $promptForReason,
            markerWillPrint: $markerWillPrint,
            warnedWithoutReason: $warnedWithoutReason,
            actorIsManager: $isManager,
            actorUserId: $actor?->id,
            reprintReason: $reason,
            warnings: $warnings,
        );
    }

    /**
     * Should the POS ask "why?" before printing — a question, not a gate.
     *
     * P-11: job state is deliberately NOT consulted. Reprinting while the first
     * copy is still `delivering` is allowed, because the operator watching a
     * half-fed slip come out of the machine knows more about that piece of
     * paper than any status column does.
     */
    public function requiresReasonPrompt(PrintJobKind $kind, int $reprintNo): bool
    {
        // The first print of anything is not a reprint. Kitchen/bar/label/
        // report/diagnostic are never asked about at any copy number: holding a
        // bếp ticket hostage for a written justification is operationally
        // absurd, and no money moves.
        return $kind->isMoneyDocument() && $reprintNo >= 2;
    }

    /**
     * P-10b [HARD] — the mark on the paper, the one control that actually
     * prevents two originals. It is not conditional on role or reason: every
     * slip whose template carries the `reprint_marker` block prints it from
     * copy 2, and the block is `locked` so no brand can remove it.
     */
    public function markerWillPrint(int $reprintNo): bool
    {
        return $reprintNo >= 2;
    }

    /**
     * The ledger flag, computed the same way wherever it is needed (the journal
     * ingest derives it for offline prints that never got to ask Cloud
     * anything). Role-independent on purpose: "no reason was recorded" is a
     * fact about the document, not about the person.
     */
    public function warnedWithoutReason(PrintJobKind $kind, int $reprintNo, ?string $reason): bool
    {
        return $this->requiresReasonPrompt($kind, $reprintNo) && $this->normaliseReason($reason) === null;
    }

    private function normaliseReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : $reason;
    }

    private function isManager(?User $actor, string $organizationId, string $branchId): bool
    {
        if ($actor === null) {
            return false;
        }

        return $actor->hasRoleInContext('shop-manager', $organizationId, $branchId)
            || $actor->hasRoleInContext('org-manager', $organizationId, $branchId)
            || $actor->hasRoleInContext('org-admin', $organizationId);
    }
}
