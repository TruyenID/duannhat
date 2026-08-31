-- Daily kitchen-ticket counter — independent of order_number.
-- Counts how many times "fire to kitchen" has been triggered today.
-- Resets each calendar day (date PRIMARY KEY).
CREATE TABLE IF NOT EXISTS kitchen_ticket_counters (
    date    TEXT PRIMARY KEY,   -- YYYY-MM-DD
    counter INTEGER NOT NULL DEFAULT 0
);
