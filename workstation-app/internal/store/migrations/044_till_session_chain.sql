-- Plan-046 — shift handover / chain-of-shifts (引き継ぎ / 精算).
--
-- A "chain" = an ordered run of shifts on one till, linked by handovers and
-- terminated by a final close. The workstation OWNS the shift lifecycle in
-- SQLite (handleLocalPosTillClose/Open/Handover), so it mirrors the four Cloud
-- columns locally. No new table — same shape as the Cloud till_sessions alter.
--
--   chain_id            groups a chain; the first shift generates it (uuid), a
--                       continued shift inherits the prior handover's chain_id.
--   chain_sequence      1-based position within the chain (open→1, continue→+1).
--   settlement_kind     'handover' keeps the chain open, 'final' closes it; NULL
--                       while open/abandoned/expired. Only handover()/close()=final
--                       set it — abandon/expire never do (R5).
--   settlement_snapshot immutable settle-time snapshot as a JSON TEXT string
--                       (cash/tenders/revenue + per-rate tax_breakdown). The
--                       workstation writes a PROVISIONAL value at offline settle
--                       and adopts Cloud's authoritative snapshot from the sync-UP
--                       response (R7). Read by FormatChainReport (aggregate slip).
--
-- Highest prior migration is 043_payments_rehomed_at.sql; 040 is duplicated
-- (see repair.go) so this file takes 044. repairLegacySchema() heals DBs that
-- recorded v044 from an older layout (idempotent, every Open).
ALTER TABLE till_sessions ADD COLUMN chain_id TEXT;
ALTER TABLE till_sessions ADD COLUMN chain_sequence INTEGER NOT NULL DEFAULT 1;
ALTER TABLE till_sessions ADD COLUMN settlement_kind TEXT;
ALTER TABLE till_sessions ADD COLUMN settlement_snapshot TEXT;

CREATE INDEX IF NOT EXISTS idx_till_sessions_chain ON till_sessions(chain_id, chain_sequence);
