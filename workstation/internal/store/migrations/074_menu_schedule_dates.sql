-- Calendar window (campaign start/end dates) for menu schedules — #1970.
--
-- These columns were deliberately ABSENT before. Cloud's POS surface ignored
-- the window (ruled 2026-07-30, #1237) while its customer surface applied it,
-- so mirroring the columns here alone would have made the offline LAN POS
-- STRICTER than the online one: a shop losing the ability to sell the moment
-- its internet drops.
--
-- #1970 settled the Cloud side the other way — every surface now applies the
-- window — which flips the risk. Without these columns the LAN POS is the
-- LOOSER of the two, and an expired campaign menu stays sellable exactly when
-- the shop is offline and nobody can see it happening.
--
-- NULL (or '') means unbounded, matching Cloud's NULL semantics on both
-- `menu_schedules` and `branch_schedule_overrides`. The feed sends the already
-- COALESCEd effective value (shop override over HQ), so there is no second
-- tier-walk in Go to drift out of sync.

ALTER TABLE menu_schedules ADD COLUMN start_date TEXT;
ALTER TABLE menu_schedules ADD COLUMN end_date   TEXT;
