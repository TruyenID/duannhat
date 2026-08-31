-- Add sku_variant_name to order_items so handy can display the selected variant
-- (e.g. "Regular", "Large") separately from the product name.
ALTER TABLE order_items ADD COLUMN sku_variant_name TEXT;
