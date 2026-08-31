<?php

namespace App\Services\Printing;

use App\Services\Printing\Enums\PrintJobKind;

/**
 * plan-052 §4 / P-10 (#1166) — the answer to "I am about to reprint this".
 *
 * It is an ADVICE, never a verdict. `allowed` is a constant `true` and there is
 * no constructor path that can make it false, because the owner ruling of
 * 2026-07-28 is absolute: **the system never blocks a print command**. A
 * cashier is standing at the counter with a customer waiting and a machine that
 * just ate the paper; software that answers "no" there is not protecting
 * anything, it is breaking the business.
 *
 * What replaces the refusal is a TRAIL, in three parts (DESIGN §4):
 *
 *   1. the COUNT — `reprint_no`, still minted by `AppendPrintHistory` (P-12);
 *   2. WHO — the actor + channel, written to the ledger on EVERY print;
 *   3. the MARK ON THE PAPER — copy N ≥ 2 carries 「再印刷 #N」 from a `locked`
 *      block nobody can switch off (P-10b). That, not a 422, is what stops two
 *      sheets from both looking like an original.
 *
 * `requires_reason_prompt` asks the POS to show the dialog. Dismissing it is a
 * supported outcome: the ledger then records `reprint_reason: null` and
 * `warned_without_reason: true`, and the M2 reconcile report surfaces it for a
 * manager. A recorded gap is worth more than a refused print.
 */
readonly class ReprintAdvice
{
    /**
     * @param  list<array{code: string, severity: string, message: string, params?: array<string, mixed>}>  $warnings
     */
    public function __construct(
        public PrintJobKind $kind,
        public int $reprintNo,
        public bool $isMoneyDocument,
        public bool $requiresReasonPrompt,
        public bool $markerWillPrint,
        public bool $warnedWithoutReason,
        public bool $actorIsManager,
        public ?string $actorUserId,
        public ?string $reprintReason,
        public array $warnings,
    ) {}

    /**
     * Always true. Kept as an explicit field rather than an implication so that
     * a client reading the payload sees the ruling stated, and so that any
     * future attempt to make it conditional has to change a method that says
     * why it cannot be.
     */
    public function allowed(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'allowed' => true,
            // Legacy keys, kept so an older pos-web build reading
            // `data.authorized` / `data.gated` keeps working unchanged. They no
            // longer decide anything — nothing here can be false.
            'authorized' => true,
            'gated' => $this->requiresReasonPrompt,

            'kind' => $this->kind->value,
            'reprint_no' => $this->reprintNo,
            'is_money_document' => $this->isMoneyDocument,
            'requires_reason_prompt' => $this->requiresReasonPrompt,
            'marker_will_print' => $this->markerWillPrint,
            'warned_without_reason' => $this->warnedWithoutReason,
            'actor_is_manager' => $this->actorIsManager,
            'actor_user_id' => $this->actorUserId,
            'reprint_reason' => $this->reprintReason,
            'warnings' => $this->warnings,
        ];
    }
}
