-- Per-locale product names so the workstation can serve the menu item name in
-- the pos-web operator's language (Accept-Language) offline, without re-sync.
-- Nullable — a product with no translation for a locale falls back to `name`.
ALTER TABLE pos_products ADD COLUMN name_ja TEXT;
ALTER TABLE pos_products ADD COLUMN name_en TEXT;
ALTER TABLE pos_products ADD COLUMN name_vi TEXT;
