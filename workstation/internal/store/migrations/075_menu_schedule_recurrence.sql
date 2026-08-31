-- Recurrence kind for menu schedules — #1979.
--
-- Before this, a schedule row could only repeat by WEEKDAY, and the mirror
-- flattened it into one row per (schedule, day_of_week). Two more kinds now
-- exist Cloud-side: `Monthly` (a 31-bit day-of-month mask) and `SpecificDates`
-- (an explicit list). A mirror that does not understand them would let those
-- menus through unfiltered — LOOSER than the online POS, which is the exact
-- shape #1970 was fixed to remove, only pointing the other way.
--
-- The feed sends the RULE, not a pre-expanded list of dates: an expansion needs
-- a horizon, and a till that stays offline past that horizon would quietly go
-- blank days after its last sync with nothing on screen to explain it.
--
-- `specific_dates` is a comma-joined 'YYYY-MM-DD,YYYY-MM-DD' string rather than
-- a child table. These lists are a handful of entries per schedule, and a child
-- table here would need its own feed, its own pull and its own delete-orphans
-- pass for no behavioural gain.
--
-- Rows written before this migration have recurrence_kind NULL; every read
-- treats NULL as 'Weekly', which is exactly what they were.

ALTER TABLE menu_schedules ADD COLUMN recurrence_kind TEXT;
ALTER TABLE menu_schedules ADD COLUMN days_of_month   INTEGER NOT NULL DEFAULT 0;
ALTER TABLE menu_schedules ADD COLUMN specific_dates  TEXT;
