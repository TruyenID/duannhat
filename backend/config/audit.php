<?php

declare(strict_types=1);

/**
 * #2555 — retention for `audit_logs`.
 *
 * The table had no prune job at all and grew without bound, which is both a
 * PCI finding (Req 10.5.1) and the thing blocking #2554 from ever recording a
 * caller address again: personal data may not accumulate forever.
 *
 * The number is a compliance floor, not a preference. PCI DSS v4.0 Req 10.5.1
 * requires audit-log history to be retained for **at least 12 months**, with
 * **at least the most recent 3 months immediately available** for analysis.
 * The default here is 400 days — 12 months plus ~5 weeks of margin, so a
 * dispute opened on the last day of a period still finds its evidence while
 * the case is being worked, and so a scheduler outage of a few weeks cannot
 * silently drop rows below the floor. Everything stays in the live table, so
 * the "immediately available" half of the requirement is satisfied by
 * construction.
 *
 * `audit:prune` refuses to run below `pci_floor_days`. The value is tunable
 * upward (a country/contract wanting 7 years sets AUDIT_LOG_RETENTION_DAYS),
 * never downward by accident.
 *
 * The cutoff is compared against `created_at` in UTC. That is deliberate and
 * is NOT a #1091 business-time violation: this is an infrastructure storage
 * horizon measured in elapsed days, not a business date. No shop's opening
 * hours change how long a log row must be kept.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Retention window
    |--------------------------------------------------------------------------
    |
    | How many days of `audit_logs` history to keep. Rows whose `created_at`
    | is strictly older than `now() - days` are eligible for deletion.
    |
    */
    'retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 400),

    /*
    |--------------------------------------------------------------------------
    | PCI floor
    |--------------------------------------------------------------------------
    |
    | PCI DSS v4.0 Req 10.5.1 — 12 months of audit history. `audit:prune`
    | aborts without deleting anything when the effective retention window is
    | below this, so a mistyped env var fails loudly instead of quietly
    | destroying evidence.
    |
    */
    'pci_floor_days' => 365,

    /*
    |--------------------------------------------------------------------------
    | Prune batching
    |--------------------------------------------------------------------------
    |
    | `audit_logs` only ever grows, so a single unbounded DELETE would hold row
    | locks over an arbitrarily large range. The prune deletes in chunks by
    | primary key and stops on whichever bound is hit first, which is what makes
    | it safe to run at 14:30 on a busy Saturday rather than only overnight:
    |
    |   chunk_size   rows fetched and deleted per round trip
    |   max_rows     hard ceiling for one invocation
    |   max_seconds  wall-clock budget for one invocation
    |   pause_ms     breather between chunks so writers are not starved
    |
    | Hitting a bound is not an error: the run reports why it stopped and the
    | next scheduled run continues from the same cutoff.
    |
    */
    'prune' => [
        'chunk_size' => (int) env('AUDIT_LOG_PRUNE_CHUNK_SIZE', 1000),
        'max_rows' => (int) env('AUDIT_LOG_PRUNE_MAX_ROWS', 200000),
        'max_seconds' => (int) env('AUDIT_LOG_PRUNE_MAX_SECONDS', 300),
        'pause_ms' => (int) env('AUDIT_LOG_PRUNE_PAUSE_MS', 50),
    ],

];
