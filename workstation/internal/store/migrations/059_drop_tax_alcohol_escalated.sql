-- 059 — drop order_items.tax_alcohol_escalated (#1099 follow-up)
--
-- The 酒類 escalation rule is gone. A tax type is ONE rate and 酒類 is an
-- ordinary product: assign it the standard type in the catalog. Cloud dropped
-- its column (backend migration 2000_03_26_000002) and no longer sends the
-- field, so every row written since has been the DEFAULT 0 — a column that can
-- only ever hold one value, which the sync, the refund copy, the kiosk shape
-- and the LAN order shape were all still carrying.
--
-- Dropping it rather than leaving it at 0 is the point: a dead boolean named
-- "alcohol escalated" reads like a rule that still exists. It does not, and the
-- next person to hunt for how alcohol is taxed must not find a column implying
-- the system tracks it. 酒税法 compliance is the operator's, via catalog
-- assignment.
--
-- Historical rows lose the marker. That is intended and safe: the money is in
-- tax_rate / tax_amount / tax_type_id, which are untouched. The marker only ever
-- explained WHY a 軽減-typed line carried the standard rate, and no such line
-- can be produced any more.

ALTER TABLE order_items DROP COLUMN tax_alcohol_escalated;

-- Same removal one layer up: the resolution INPUTS the escalation rule needed.
-- menu_items.is_alcohol mirrored products.is_alcohol (dropped on Cloud by
-- 2000_03_26_000000) and pos_topping_group_items.is_alcohol mirrored the
-- component product's, so a combo offering an alcohol component could escalate
-- while offline. With no escalation rule left, both are inputs to nothing.
ALTER TABLE menu_items DROP COLUMN is_alcohol;
ALTER TABLE pos_topping_group_items DROP COLUMN is_alcohol;
