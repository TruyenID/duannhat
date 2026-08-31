-- Takeaway pickup time — mirrored from Cloud customer_orders.scheduled_pickup_time
-- so the kitchen + serving slips can print "when the customer comes to collect".
-- Nullable: only takeaway orders carry it; dine-in/spot leave it NULL. Stored as
-- the ISO-8601 string Cloud sends (CustomerOrderResourceBase → toISOString), same
-- as opened_at and the other timestamp mirrors on this table.
ALTER TABLE orders ADD COLUMN scheduled_pickup_time TEXT;
