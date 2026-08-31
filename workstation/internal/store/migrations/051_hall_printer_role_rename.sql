-- Rename the printer role `hold_printer` → `hall_printer`.
--
-- The role prints the ホール伝票: the front-of-house ticket floor staff run to
-- the table (service.FormatRunnerTicket, "HOA DON BAN"). "hold" was a
-- mis-transliteration of ホール (hall) — a different word from ホールド (hold) —
-- so both the identifier and every label derived from it read as "on hold" /
-- 保留 / "phiếu chờ", which is a different concept entirely.
--
-- Two columns carry the value: `roles` (JSON array, the real source of truth
-- since migration 013) and `type` (kept in sync as the primary role for
-- back-compat). Rewrite both. Rows are few (one per physical printer), so a
-- plain REPLACE over the JSON text is safe and avoids a JSON1 dependency.
--
-- Manager.parseRoles also normalises the old value on read, so a DB restored
-- from a pre-051 backup keeps routing correctly without re-running this.

UPDATE printers
SET roles = REPLACE(roles, '"hold_printer"', '"hall_printer"')
WHERE roles LIKE '%"hold_printer"%';

UPDATE printers
SET type = 'hall_printer'
WHERE type = 'hold_printer';
