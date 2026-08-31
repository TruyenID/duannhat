-- #1806 / #1807 — the alert centre.
--
-- The workstation had 143 `slog.Warn` call sites and no alert surface at all, so
-- every one of them shouted into the log of a background process on a PC in the
-- back of a shop. Among them: "the machine is still holding the customer's
-- cash", "the kitchen has no printer", "the print history could not be written".
-- Nobody reads that log. This table is where the ones a human must act on live.
--
-- Four things this schema must NOT become, each of which turns the alert centre
-- back into a log nobody reads:
--
--   1. NOT one row per occurrence. The unique index is on (kind, subject) among
--      OPEN rows, so a printer that is offline for an hour is ONE row with a
--      rising `count` and a moving `last_seen_at` — not 3600 rows. Without that
--      the screen is a log with extra steps, and the one alert that mattered is
--      pushed off it by the one that repeats.
--
--   2. NOT a place every warning lands. Membership is decided by an allowlist of
--      `kind` in Go (alert_kinds.go), default-deny, mirroring pos-web's
--      offline-cache-policy allowlist. A bell that rings all day gets muted in
--      the first week, and then the real alert rings into a muted bell.
--
--   3. NOT memory. An alert saying the machine is holding a customer's cash must
--      survive the workstation restarting — that is precisely the moment it is
--      most likely to restart, and the cash does not disappear with the process.
--
--   4. NOT one axis. `severity` is how urgent, `audience` is who acts, and they
--      genuinely cross: "this machine runs a binary older than dev" is
--      audience=dev but can be severity=critical when the old build carries a
--      known money bug. Collapsing them loses exactly that cell.
--
-- `resolution` records HOW an alert ended, because the two ways are not
-- interchangeable: `auto` means the workstation re-measured and the condition
-- was gone; `ack` means a human asserted it. Conditions with no probe — cash
-- physically inside a machine — may only ever end by `ack`, since the only thing
-- that proves it is someone opening the machine and counting.

CREATE TABLE IF NOT EXISTS alerts (
    id             TEXT PRIMARY KEY,
    kind           TEXT NOT NULL,
    -- What the alert is ABOUT, scoped inside the kind: a printer role, an order
    -- id, a device id. Empty string for kinds that can only have one instance
    -- (the workstation is not paired). Part of the dedup key, never NULL, so the
    -- unique index below cannot be defeated by NULL != NULL.
    subject        TEXT NOT NULL DEFAULT '',
    severity       TEXT NOT NULL,              -- critical | warning | info
    audience       TEXT NOT NULL,              -- shop | dev
    title          TEXT NOT NULL,
    detail         TEXT NOT NULL DEFAULT '{}', -- JSON
    first_seen_at  TEXT NOT NULL,              -- RFC3339 UTC
    last_seen_at   TEXT NOT NULL,              -- RFC3339 UTC
    count          INTEGER NOT NULL DEFAULT 1,
    resolved_at    TEXT,                       -- NULL = still open
    resolved_by    TEXT,
    resolution     TEXT                        -- auto | ack
);

-- The dedup key applies to OPEN rows only. A resolved alert stays as history,
-- and the same condition recurring later is a NEW row rather than a resurrected
-- one — "the printer dropped again this evening" is a different fact from "the
-- printer dropped this morning", and merging them hides the second.
CREATE UNIQUE INDEX IF NOT EXISTS idx_alerts_open_unique
    ON alerts (kind, subject) WHERE resolved_at IS NULL;

-- The read the panel actually does: open alerts, worst and freshest first.
CREATE INDEX IF NOT EXISTS idx_alerts_open ON alerts (resolved_at, last_seen_at);
