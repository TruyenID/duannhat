-- plan-038 follow-up — delta kitchen printing.
--
-- Track how many units of each order line have already been sent to the
-- kitchen. The old model was a binary `print_status` per item, so bumping the
-- quantity of an already-fired line (2 → 3) could not print only the new unit:
-- the item was still `sent_to_kitchen`, so a re-fire printed nothing.
--
-- `printed_quantity` makes the unprinted amount a delta:
--   unprinted = quantity - printed_quantity   (clamped at 0)
-- A line needs (re)printing whenever unprinted > 0, and the kitchen ticket
-- prints only those delta units.
ALTER TABLE order_items ADD COLUMN printed_quantity INTEGER NOT NULL DEFAULT 0;

-- Backfill so the upgrade does NOT re-print every open order. Lines previously
-- marked fully sent map to printed_quantity = quantity (delta 0). Lines still
-- `pending`/`failed` keep printed_quantity = 0 (their whole quantity prints).
UPDATE order_items SET printed_quantity = quantity WHERE print_status = 'sent_to_kitchen';
