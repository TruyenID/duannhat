-- #1114 — offline-evidence selection anchoring.
--
-- Cloud's offline verifier prices a signed order line by its
-- menu_product_sku_id (the EXACT menu line, #514) — the same id the menu
-- payload has carried since #514 but the workstation never stored. The
-- offline signer needs it to build the selection it signs, so mirror it
-- onto the flat menu_items cache. NULL = not yet re-pulled (old Cloud or
-- pre-migration row); lines resolved from such rows cannot be signed and
-- keep taking the legacy sync path.
ALTER TABLE menu_items ADD COLUMN menu_product_sku_id TEXT;
