-- plan-052 T1.2 (#1166) — the LOCAL print journal.
--
-- Every print this workstation performs writes one row here, on the workstation's
-- own clock, BEFORE anything is said to Cloud. The row is then synced UP as a
-- print_jobs ledger row (DESIGN §1b journal mode).
--
-- Why local-first and not "call Cloud when you print":
--   * a shop must keep printing with the internet down — that is the whole
--     reason the workstation exists (RISKS PR2), so the print path may never
--     wait on, or ask permission from, Cloud;
--   * `printed_at` is the workstation's real clock at the moment the paper came
--     out. Cloud stores exactly this value, never the sync time, so an evening
--     of offline sales does not land on tomorrow's business day (#1091, P-07).
--
-- `id` is generated HERE (client-generated, same pattern as #1092 offline
-- evidence) and is the primary key on BOTH sides. That is what makes a replayed
-- sync a no-op at the database level rather than an application-level guess
-- (P-09).
CREATE TABLE IF NOT EXISTS print_jobs (
    id              TEXT PRIMARY KEY,
    kind            TEXT NOT NULL,
    printer_id      TEXT,
    order_id        TEXT,
    payment_id      TEXT,
    reprint_no      INTEGER NOT NULL DEFAULT 1,
    requested_by_id TEXT,
    requested_via   TEXT,
    reprint_reason  TEXT,
    -- IPP vocabulary, same words Cloud uses: printed | failed | needs_attention.
    status          TEXT NOT NULL,
    -- P-33: 'sent_only' unless the machine can genuinely confirm the sheet.
    confidence      TEXT NOT NULL DEFAULT 'sent_only',
    attempts        INTEGER NOT NULL DEFAULT 1,
    last_error      TEXT,
    payload         TEXT,
    -- T1.3: what the machine looked like around this print, normalised to the
    -- UnifiedPOS vocabulary (ok / cover_open / paper_end / paper_near_end /
    -- offline / error). Only the tier that OWNS the queue can observe this
    -- (P-38), which for ws_lan is this workstation.
    printer_status  TEXT,
    printed_at      TEXT NOT NULL,
    created_at      TEXT NOT NULL,
    synced_at       TEXT
);

-- The drain reads "what has not gone UP yet", oldest first.
CREATE INDEX IF NOT EXISTS idx_print_jobs_unsynced ON print_jobs (synced_at, created_at);
