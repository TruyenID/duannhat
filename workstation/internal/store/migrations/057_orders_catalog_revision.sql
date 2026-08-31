-- #1114 — stamp the catalog gate onto each order AT CREATE TIME.
--
-- The offline signer must claim the revision the order was actually priced
-- from. Signing happens at sync-drain time (the selection must be FINAL —
-- dine-in orders grow items after create), but by then the device may have
-- re-pulled a newer revision; claiming that one would re-price the customer's
-- paid bill. 0 = created before this migration / before any menu pull —
-- such orders are never signed and take the legacy sync path.
ALTER TABLE orders ADD COLUMN catalog_revision INTEGER NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN catalog_has_toppings INTEGER NOT NULL DEFAULT 0;
