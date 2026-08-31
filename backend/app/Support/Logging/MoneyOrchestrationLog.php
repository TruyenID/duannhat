<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Support\Facades\Log;

/**
 * The only way a payment-orchestration failure may be reported (#1244).
 *
 * Nineteen ERROR-level money events wrote to the `payment_orchestration`
 * channel, which is a standalone daily file and is NOT part of `stack`. The one
 * piece of documentation in this repo describing the alerting path — the
 * docblock on CheckTillsSchedulerFreshness, verified 2026-06-10 against
 * plan-032 NOTES (đã xoá #2188 — git history) — says DevOps routes ERROR entries tagged `[...]`
 * **from `LOG_CHANNEL=stack`**. So none of the nineteen could reach alerting,
 * and twelve carried no tag to be matched on either.
 *
 * That is not a cosmetic gap. ProviderRetrievalRecoveryService says outright
 * that its log line IS the mechanism: "PayPay refunds are deliberately unwired
 * (plan-054 D5), so this log is the hand-off to the operator, who reverses it in
 * the merchant portal." The entire recovery procedure for money a customer has
 * already paid was one line in a file nobody was reading.
 *
 * Whether infrastructure ships `payment-orchestration.log` to the alerting
 * backend is not recorded anywhere and could not be checked from here. This
 * writes to BOTH the dedicated channel and the default one, which is correct
 * under either answer: if the file is shipped, the duplicate costs one line per
 * rare failure; if it is not, the duplicate is the only thing that reaches
 * anyone. The unknown decides whether this is necessary, not whether it is safe.
 *
 * The dedicated channel is kept because it is worth keeping: it holds this
 * domain's full context without the rest of the application's traffic
 * interleaved.
 *
 * Enforced by tests/Unit/Arch/MoneyFailOpenLogsAreAlertableTest.php — an
 * ERROR on that channel written any other way fails the build, so the twentieth
 * site cannot quietly reintroduce the gap.
 */
final class MoneyOrchestrationLog
{
    /**
     * Tags DevOps can build alert rules on. Kept as a closed set rather than a
     * free string: a typo in a tag is invisible at runtime and silently
     * unmatchable, which is the exact failure this class exists to remove.
     */
    public const TAG_STRANDED = 'payments.stranded';

    public const TAG_PAYPAY = 'payments.paypay';

    public const TAG_RECONCILE = 'payments.reconcile';

    public const TAG_SETTLEMENT = 'payments.settlement';

    /**
     * @param  string  $tag  One of the TAG_* constants.
     * @param  string  $event  Snake_case event name, e.g. `paypay_qr_create_failed`.
     * @param  array<string, mixed>  $context
     */
    public static function error(string $tag, string $event, array $context = []): void
    {
        $message = '['.$tag.'] '.$event;

        // Domain channel first: on the (unlikely) chance the second write throws,
        // the detailed record still exists.
        Log::channel('payment_orchestration')->error($message, $context);

        // Default channel — whatever LOG_CHANNEL resolves to, which is precisely
        // what the documented alerting contract reads. Deliberately not a
        // hardcoded path or a hand-assembled stack: a deployment using stderr or
        // a hosted collector must work without an edit here.
        Log::error($message, $context);
    }
}
