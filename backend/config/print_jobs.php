<?php

/*
|--------------------------------------------------------------------------
| Print job registry — plan-052 (#1166)
|--------------------------------------------------------------------------
|
| The retry matrix (P-05) and the TTL table (P-06), per KIND. These are the
| two rules that keep a print pipeline from hurting a shop, so they live in
| ONE registry with a test locking every cell (PrintRetryMatrixTest) rather
| than scattered across whichever handler happened to need them.
|
| Reading the matrix:
|
|   auto_retry = false  → a machine may NEVER re-send this on its own. Money
|                         documents (receipt / red_invoice / debt_slip) are all
|                         false, because ACK-lost is indistinguishable from
|                         printed and a wrong guess means two originals of one
|                         インボイス exist (RISKS PR1). A human decides, and the
|                         reprint they order carries 「Bản in #N」 so even a
|                         wrong decision cannot forge a second original.
|   max_attempts        → total delivery attempts INCLUDING the first, applied
|                         only by the tier that owns the queue (DESIGN §1b).
|   backoff_seconds     → per-attempt waits; the last value repeats if the
|                         retry budget outlives the list.
|   ttl_seconds         → after this the job is `expired` and must not print.
|                         A kitchen ticket from the previous shift is not a
|                         late ticket, it is a wrong one — the food is cold and
|                         the table has left. A receipt is still meaningful the
|                         next morning, hence 24h.
|
*/

return [

    'kinds' => [

        'kitchen' => [
            'auto_retry' => true,
            'max_attempts' => 4,
            'backoff_seconds' => [5, 15, 60],
            // 15 min — a ticket older than this belongs to a service that is over.
            'ttl_seconds' => 900,
        ],

        'bar' => [
            'auto_retry' => true,
            'max_attempts' => 4,
            'backoff_seconds' => [5, 15, 60],
            'ttl_seconds' => 900,
        ],

        'label' => [
            'auto_retry' => true,
            'max_attempts' => 3,
            'backoff_seconds' => [5, 30],
            'ttl_seconds' => 3600,
        ],

        // ── Money documents — auto_retry is false and stays false ──────────
        'receipt' => [
            'auto_retry' => false,
            'max_attempts' => 1,
            'backoff_seconds' => [],
            'ttl_seconds' => 86400,
        ],

        'red_invoice' => [
            'auto_retry' => false,
            'max_attempts' => 1,
            'backoff_seconds' => [],
            'ttl_seconds' => 86400,
        ],

        'debt_slip' => [
            'auto_retry' => false,
            'max_attempts' => 1,
            'backoff_seconds' => [],
            'ttl_seconds' => 86400,
        ],
        // ──────────────────────────────────────────────────────────────────

        // A 精算/Z report is worth exactly one more try: it is not money
        // leaving the till, but a duplicate is noise in the shift binder.
        'report' => [
            'auto_retry' => true,
            'max_attempts' => 2,
            'backoff_seconds' => [10],
            'ttl_seconds' => 7200,
        ],

        // P-41 — the setup wizard's diagnostic sheet. Freely retryable, short
        // lived, never part of invoice audit.
        'diagnostic' => [
            'auto_retry' => true,
            'max_attempts' => 3,
            'backoff_seconds' => [3, 10],
            'ttl_seconds' => 600,
        ],
    ],

    /*
    | Fallback for a kind that reaches the registry without an entry. Deliberately
    | the SAFE end of every axis: no auto-retry, one attempt, short TTL. A new
    | document kind must earn its retries by being added above, never by default.
    */
    'default' => [
        'auto_retry' => false,
        'max_attempts' => 1,
        'backoff_seconds' => [],
        'ttl_seconds' => 900,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation sweep — plan-052 M2 / T2.1
    |--------------------------------------------------------------------------
    |
    | `print-jobs:reconcile` reads these. NOTHING here changes what a shop
    | prints: the sweep only DETECTS on the ws_lan side (the workstation owns
    | that queue — DESIGN §1b) and only ever expires / flags on the
    | Cloud-owned side. It never dispatches and never retries, in either mode.
    |
    */
    'reconcile' => [
        // A job that has been `delivering` this long with no result is the
        // ACK-lost state (P-03): we sent it and nobody said anything back.
        // Someone has to look, so it moves to `needs_attention` — on the
        // Cloud-owned side only; a ws_lan row is merely REPORTED.
        'stale_delivering_seconds' => (int) env('PRINT_RECONCILE_STALE_DELIVERING_SECONDS', 300),

        // A journal row whose sync landed this long after the paper came out
        // means the shop's uplink was down, not its printer. Worth reporting
        // so nobody debugs the wrong box (printing.md §10).
        'late_journal_seconds' => (int) env('PRINT_RECONCILE_LATE_JOURNAL_SECONDS', 3600),

        // How far back the DETECTION scan reads. Bounds the query on a table
        // that grows with every slip in every shop (RISKS PR5); anything older
        // is history, not an open operational item.
        'lookback_hours' => (int) env('PRINT_RECONCILE_LOOKBACK_HOURS', 168),
    ],

    /*
    |--------------------------------------------------------------------------
    | Aging report — plan-052 M2 / T2.3
    |--------------------------------------------------------------------------
    */
    'aging' => [
        // Upper edges, in DAYS, of each bucket. Age is a DURATION (now minus
        // the job's real event time), never a calendar subtraction, so the
        // report reads the same from Tokyo and from Hanoi (#1091).
        'buckets_days' => [1, 2, 7],

        // Only OPEN work ages. A printed slip is done and an expired kitchen
        // ticket is a decision already taken; counting them would bury the
        // four rows an operator can actually act on.
        'statuses' => ['queued', 'delivering', 'needs_attention', 'failed'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts — plan-052 M2 / T2.3
    |--------------------------------------------------------------------------
    |
    | Thresholds are CONFIG, never constants: the noise floor of a 3-printer
    | ramen bar is not the noise floor of a 40-printer food court, and an alert
    | that cries wolf gets muted, which is strictly worse than no alert.
    |
    */
    'alerts' => [
        'enabled' => (bool) env('PRINT_ALERTS_ENABLED', true),

        // Silence, not failure, is what a printer reports when its power strip
        // is off. Floor for every printer; a profile that declares a slower
        // health cadence (interval_s × offline_after_misses) wins if it is
        // longer, because that machine is simply expected to speak less often.
        'printer_silence_minutes' => (int) env('PRINT_ALERT_PRINTER_SILENCE_MINUTES', 60),

        // How many `needs_attention` jobs make a backlog worth waking someone.
        'needs_attention_threshold' => (int) env('PRINT_ALERT_NEEDS_ATTENTION_THRESHOLD', 5),

        // A money document that did not come out is worth ONE from the very
        // first occurrence — there is no acceptable number of receipts a shop
        // silently fails to print (RISKS PR1).
        'money_document_threshold' => (int) env('PRINT_ALERT_MONEY_DOCUMENT_THRESHOLD', 1),

        // Only money-doc failures from the recent past are actionable; a
        // fortnight-old one is an audit item, not an alert.
        'money_document_lookback_hours' => (int) env('PRINT_ALERT_MONEY_DOCUMENT_LOOKBACK_HOURS', 24),

        // Debounce window (G4, plan-050). One alert per condition per window;
        // the cooldown is RELEASED as soon as the condition clears, so a
        // problem that comes back after being fixed alerts again immediately
        // instead of hiding behind a cooldown it did not earn.
        'debounce_minutes' => (int) env('PRINT_ALERT_DEBOUNCE_MINUTES', 120),
    ],

];
