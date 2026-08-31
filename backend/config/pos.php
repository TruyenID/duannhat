<?php

/*
 * Plan-032 — POS / cashier-shift configuration.
 *
 * Documented at docs/guide/cashier-shift-recovery.md.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Cashier shift recovery
    |--------------------------------------------------------------------------
    |
    | Tunables for the `tills:expire-stale-shifts` scheduled command introduced
    | by plan-032 (plan đã xoá #2188 — git history). See its DESIGN §Decisions §4 + §10 for the rationale
    | behind the defaults.
    |
    | - stale_timeout_hours: a session whose `opened_at` is older than this
    |   value is a candidate for expire. Default 48h survives weekend opens
    |   safely (Fri 22:00 -> Sun 04:30 izakaya scenario stays inside the
    |   activity window). 24h is reasonable for daily-business shops.
    |
    | - stale_activity_window_hours: a candidate is SKIPPED if any payment
    |   landed within this trailing window — proves the shift is still
    |   active. Default 6h is generous enough for natural lulls (3am dead
    |   hour) but tight enough that a genuinely dead session is reaped
    |   within ~54h.
    |
    | - manager_view.*: the thresholds the HUMAN-facing manager surfaces use.
    |   Distinct from stale_timeout_hours on purpose — that one is a MACHINE
    |   policy ("what will the reaper auto-kill"), these are a UX policy
    |   ("what does a manager need to look at"). plan-036 specifies a 24h
    |   warning band for the "Ca treo" KPI (docs/guide/manager-till-tracking.md
    |   §KPI; plan-036 đã xoá #2188 — git history), which is the window where a
    |   manager's force-abandon still has value; past 48h the reaper handles it.
    |
    |   Read by BOTH ShopTillTrackingService (dashboard KPI + per-till badges)
    |   AND TillSessionController::stale() (filter=open_overdue). One source —
    |   they drifted apart once (24h vs 48h) and produced a KPI that counted
    |   shifts no filter could list. Guarded by TillStaleParityTest.
    |
    |   Deliberately NOT env-tunable: the badge copy hard-codes "24h" / "40h"
    |   in ja/en/vi (till_tracking.dashboard.stale_warning / .stale_critical),
    |   so making these tunable would make that copy lie. Parameterise the copy
    |   first if you ever need them per-shop.
    */
    'shift' => [
        'stale_timeout_hours' => (int) env('POS_SHIFT_STALE_TIMEOUT_HOURS', 48),
        'stale_activity_window_hours' => (int) env('POS_SHIFT_STALE_ACTIVITY_WINDOW_HOURS', 6),

        'manager_view' => [
            'overdue_hours' => 24,
            'critical_hours' => 40,
        ],

        /*
        | Force-abandon fraud-signal threshold. The `tills:check-force-abandon-rate`
        | command emits a tagged WARNING log when a single manager's
        | `force_abandoned_by_id` count exceeds this in the trailing 7d window.
        */
        'force_abandon_rate_alert_count' => (int) env('POS_FORCE_ABANDON_RATE_ALERT', 5),
        'force_abandon_rate_window_days' => (int) env('POS_FORCE_ABANDON_RATE_WINDOW_DAYS', 7),
    ],

];
