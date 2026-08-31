-- Schedule times + priority so workstation can mirror Cloud's
-- `GET /api/v1/shops/{slug}/menus/by-day/{dow}` response.
--
-- Pre-024 the local `menu_schedules` table only carried (menu_id,
-- day_of_week, is_active) — workstation's by-day handler always emitted
-- `start_time: ""` / `end_time: ""`, so pos-web's menu picker rendered
-- the schedule chip as "— – —" instead of the real window. Cloud's
-- `ShopMenuByDayResource` surfaces the highest-priority schedule's
-- times via a correlated subquery; mirror the columns locally so the
-- workstation handler picks the same row by `priority ASC` and emits
-- the same string.

ALTER TABLE menu_schedules ADD COLUMN start_time TEXT;
ALTER TABLE menu_schedules ADD COLUMN end_time   TEXT;
ALTER TABLE menu_schedules ADD COLUMN priority   INTEGER NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_menu_schedules_priority
    ON menu_schedules(menu_id, day_of_week, priority);
