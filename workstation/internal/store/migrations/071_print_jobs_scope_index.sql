-- #1875 — indexes for the PER-KIND, PER-SCOPE reprint counter.
--
-- Until now the copy number 「BAN IN #N」 came from `payments.metadata.print_history`,
-- a single counter SHARED by receipt + red_invoice + debt_slip on one payment. So a
-- customer whose receipt had already been printed got their FIRST red invoice stamped
-- 「BAN IN #2」 — the mark said "this is a copy" about an original.
--
-- The counter now lives in this table, which already carries `kind`, `order_id` and
-- `payment_id` and — decisively — already holds every red invoice printed since
-- #1166, so the new counter continues the real history instead of restarting at 1.
--
-- Two scopes, two predicates, one index each:
--
--   per payer (chia đều / theo tiền / theo món — one payment row per guest)
--       WHERE kind = ? AND payment_id = ?
--
--   whole order (the split-bill footer slip, and any order with no payment yet)
--       WHERE kind = ? AND order_id IN (<order family>) AND COALESCE(payment_id,'') = ''
--
-- Without these, `Reserve` scans the whole ledger on every print. The table only ever
-- grows — a shop printing 300 slips a day carries a year of them — and the scan sits
-- INSIDE the BEGIN IMMEDIATE transaction that every other print is waiting on.
CREATE INDEX IF NOT EXISTS idx_print_jobs_kind_payment ON print_jobs (kind, payment_id);
CREATE INDEX IF NOT EXISTS idx_print_jobs_kind_order ON print_jobs (kind, order_id);

-- The sweep for reservations orphaned by a crash mid-print reads exactly this.
CREATE INDEX IF NOT EXISTS idx_print_jobs_status_created ON print_jobs (status, created_at);
