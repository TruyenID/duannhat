<?php

/**
 * #1206 / #1204 — a fail-open path that loses money must log where alerting can
 * see it.
 *
 * There IS an alerting path in this project, and it is not Sentry: DevOps routes
 * ERROR-level entries to the alerting backend by the `[...]` prefix on the
 * message. That contract is documented in the docblock of
 * `CheckTillsSchedulerFreshness` (verified 2026-06-10, plan-032 NOTES — plan đã xoá #2188, git history)
 * and followed at a dozen sites — [pos.till], [pos.till.tracking],
 * [workstation.till.close], [printing].
 *
 * The money paths missed it. `refundStrandedCharge()` logged the
 * kill-switch-off branch at `warning` AND without a tag, so it failed the
 * contract twice over — and since STRIPE_LIVE_REFUNDS_ENABLED defaults off, that
 * is the DEFAULT path for a stranded customer charge, not an edge case. Its own
 * docblock had promised error level all along.
 *
 * This is a source assertion by design. A runtime test would have to provoke a
 * Stripe failure to observe the line, which is exactly why these went unchecked
 * for so long; and the invariant — "this line is shaped so alerting matches it"
 * — is a property of the call site, not of a request.
 */
function moneySource(string $relative): string
{
    return dirname(__DIR__, 3).'/'.$relative;
}

/**
 * Call sites that must stay alertable: file => list of message fragments that
 * must appear at ERROR level with a `[...]` tag.
 */
const ALERTABLE_MONEY_LOGS = [
    'app/Services/Customer/StripePaymentService.php' => [
        'Stranded charge refund SKIPPED',
        'Stranded charge auto-refund FAILED',
        'Overpayment charge stranded',
        // Money Stripe charged that the ledger then refused. Same family as the
        // three above; missed on the first pass because only the lines with
        // "stranded" in the message were looked at.
        'Stripe currency mismatch — refusing to ledger',
    ],
    // The closing path — the main deduction moment, and the same fail-open shape
    // as the four plan-051 hooks below.
    'app/Services/Customer/OrderClosingService.php' => [
        'order-close: stock deduction failed',
    ],
    // Not money either, but the job's own docblock names this line as "the log
    // line to alert on": once every attempt is spent the branch keeps serving a
    // STALE catalog revision, and a stale revision is the price map offline
    // orders are verified against. The recovery sweep (catalog:rebuild-revisions)
    // is a deploy step with no schedule, so nothing heals it in the meantime.
    'app/Jobs/Catalog/RebuildCatalogRevisionJob.php' => [
        'catalog_revision_rebuild_failed',
    ],
    // #1244 — money that arrived (or moved) and the ledger does not reflect it.
    // These write to the `payment_orchestration` channel, a separate daily file;
    // whether that file reaches alerting is an infra question tracked on #1244.
    // The tag is right either way: it is what a filter rule matches on if the
    // file IS shipped, and what the mirrored line needs if it is not.
    //
    // Scoped to STRANDED MONEY on purpose. The same channel carries operational
    // faults (paypay_qr_create_failed, paypay_prepare_refused, sweep timing
    // warnings); those are worth logging but they are not money sitting
    // somewhere unaccounted, and widening this list to them would blur what it
    // is for.
    'app/Services/Payment/ProviderEvent/ProviderRetrievalRecoveryService.php' => [
        'paypay_stranded_payment',
        'paypay_ledger_write_impossible',
        'paypay_ledger_write_failed',
        'paypay_ledger_write_order_missing',
    ],
    'app/Console/Commands/SweepStalePayPayQrAttempts.php' => [
        'paypay_qr_sweep_stranded',
    ],
    'app/Http/Controllers/Api/V1/Workstation/PaymentController.php' => [
        'workstation_payment_stranded_at_the_drawer',
    ],
    'app/Services/Payment/Settlement/Stripe/StripeSettlementRecorder.php' => [
        'settlement_payout_mismatch',
    ],
    // Stock, not money — included because the SHAPE is identical and the loss is
    // just as material. CLAUDE.md states the consequence outright: voiding a
    // cooked item leaves inventory adrift, because voided lines are dropped from
    // the ingredient deduction. `stock:repair-void-compensation` exists to
    // reconcile it, but nothing schedules that command, so the ERROR log is the
    // only signal there is anything to reconcile — and it was untagged, which
    // means alerting could not match it.
    // The repair side of the same drift. WritesCustomerOrders raises the alarm
    // when compensation fails; this command is what puts the material back, and
    // it now runs nightly (#1257). A repair that keeps failing for a real reason
    // rather than a transient one fails again every night, so its own failure
    // has to be audible too.
    'app/Console/Commands/RepairVoidStockCompensation.php' => [
        'void stock compensation repair failed',
    ],

    'app/Services/Order/Internal/Concerns/WritesCustomerOrders.php' => [
        'add-time stock deduction failed',
        'item-update stock hook failed',
        'void stock compensation failed',
        'workstation void stock compensation failed',
    ],
];

it('logs every money fail-open at ERROR with an alerting tag', function () {
    $violations = [];

    foreach (ALERTABLE_MONEY_LOGS as $file => $fragments) {
        $lines = file(moneySource($file)) ?: [];

        foreach ($fragments as $fragment) {
            $found = false;

            foreach ($lines as $number => $line) {
                // Only actual log calls. A docblock or comment that names the
                // message is not the thing being guarded, and treating it as one
                // makes the gate report phantom violations.
                if (! preg_match('/(Log::|->)(error|critical|warning|notice|info|debug)\(/', $line)) {
                    continue;
                }

                if (! str_contains($line, $fragment)) {
                    continue;
                }

                $found = true;

                // A `warning(` / `info(` call here would put the entry
                // below the alerting threshold; only error and critical are
                // routed.
                if (! preg_match('/(->|::)(error|critical)\(/', $line)) {
                    $violations[] = sprintf('%s:%d — "%s" is not logged at error/critical', $file, $number + 1, $fragment);

                    continue;
                }

                // The tag must be the START of the message, which is where the
                // alerting rules look — either written inline, or supplied by
                // MoneyOrchestrationLog, which builds the same prefix from a
                // TAG_* constant AND mirrors the entry to the default channel
                // (#1244). Accepting only the literal form would report every
                // call routed through the helper as untagged, which is what this
                // gate did the moment the helper landed.
                $taggedInline = preg_match('/(->|::)(error|critical)\(\s*\'\[[a-z0-9._]+\]/', $line) === 1;
                $taggedByHelper = preg_match('/MoneyOrchestrationLog::(error|critical)\(\s*MoneyOrchestrationLog::TAG_[A-Z_]+/', $line) === 1;

                if (! $taggedInline && ! $taggedByHelper) {
                    $violations[] = sprintf('%s:%d — "%s" has no [tag] prefix', $file, $number + 1, $fragment);
                }
            }

            if (! $found) {
                // A renamed or deleted message is not a pass. Either the path is
                // gone (update this list, deliberately) or the guard has stopped
                // guarding anything.
                $violations[] = sprintf('%s — no call site found for "%s"; update ALERTABLE_MONEY_LOGS if the path was removed', $file, $fragment);
            }
        }
    }

    expect($violations)->toBe([], implode("\n  ", ['Money fail-open logs that alerting cannot match:', ...$violations]));
});

it('keeps the payment_orchestration channel reachable by alerting', function () {
    // The tag gate above only guards messages someone remembered to list. This
    // one needs no list: the payment_orchestration channel is a standalone daily
    // file outside `stack`, so an ERROR written straight to it cannot reach
    // alerting no matter how well it is tagged (#1244). MoneyOrchestrationLog is
    // the single place allowed to do that, because it mirrors to the default
    // channel in the same call.
    //
    // This is the part that survives the twentieth call site being added by
    // someone who never read #1244.
    $violations = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(moneySource('app')));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace(moneySource(''), '', $file->getPathname());

        if (str_contains($path, 'MoneyOrchestrationLog.php')) {
            continue;
        }

        foreach (file($file->getPathname()) ?: [] as $number => $line) {
            if (preg_match('/channel\(\s*\'payment_orchestration\'\s*\)->(error|critical)\(/', $line)) {
                $violations[] = sprintf('%s:%d', $path, $number + 1);
            }
        }
    }

    expect($violations)->toBe([], implode("\n  ", [
        'These write an ERROR to payment_orchestration directly. That channel is not in `stack`,',
        'so alerting never sees it. Use MoneyOrchestrationLog::error(TAG_*, ...) instead:',
        ...$violations,
    ]));
});
