-- Per-locale names for the rest of the product-options dialog so the workstation
-- can serve it in the operator's language (Accept-Language) offline: SKU variant
-- names, topping group names, product option names, and option value labels.
-- All nullable — a missing locale falls back to the base column (name / label).
ALTER TABLE pos_product_skus ADD COLUMN name_ja TEXT;
ALTER TABLE pos_product_skus ADD COLUMN name_en TEXT;
ALTER TABLE pos_product_skus ADD COLUMN name_vi TEXT;

ALTER TABLE pos_topping_groups ADD COLUMN name_ja TEXT;
ALTER TABLE pos_topping_groups ADD COLUMN name_en TEXT;
ALTER TABLE pos_topping_groups ADD COLUMN name_vi TEXT;

ALTER TABLE pos_product_options ADD COLUMN name_ja TEXT;
ALTER TABLE pos_product_options ADD COLUMN name_en TEXT;
ALTER TABLE pos_product_options ADD COLUMN name_vi TEXT;

-- Option VALUES localize the `label` column (not `name`), matching Cloud's
-- product_option_value_translations.label.
ALTER TABLE pos_product_option_values ADD COLUMN label_ja TEXT;
ALTER TABLE pos_product_option_values ADD COLUMN label_en TEXT;
ALTER TABLE pos_product_option_values ADD COLUMN label_vi TEXT;
