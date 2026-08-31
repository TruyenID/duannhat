-- #1099 single-rate TaxType: a tax type is ONE number. Consumption context
-- (店内/持ち帰り) is a MENU concern — the takeaway menu carries REDUCED
-- overrides on its items, so the per-order-type rate pair dies here too.
--
-- Backfill BEFORE dropping: under the Japanese seed the takeaway slot equals
-- the type's true single rate (STANDARD 10/10→10, REDUCED 10/8→8, EXEMPT 0).
-- This mirror is repopulated by every menu pull anyway, so the backfill only
-- protects the offline window right after the upgrade.
ALTER TABLE tax_types ADD COLUMN rate REAL NOT NULL DEFAULT 0;
UPDATE tax_types SET rate = rate_takeaway;
ALTER TABLE tax_types DROP COLUMN rate_dine_in;
ALTER TABLE tax_types DROP COLUMN rate_takeaway;
