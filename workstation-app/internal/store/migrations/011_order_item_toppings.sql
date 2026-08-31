-- Topping selections snapshotted at order-item creation time.
-- Mirrors the cloud order_item_toppings shape so handy/POS can display
-- the chosen options on the order detail screen.
CREATE TABLE IF NOT EXISTS order_item_toppings (
    id                    TEXT PRIMARY KEY,
    order_item_id         TEXT NOT NULL REFERENCES order_items(id) ON DELETE CASCADE,
    topping_group_item_id TEXT NOT NULL,
    product_sku_id        TEXT NOT NULL,
    name                  TEXT,               -- snapshot of display name at order time
    modifier_type         TEXT NOT NULL DEFAULT 'add',   -- 'add' | 'remove'
    topping_group_id      TEXT,
    topping_group_name    TEXT,
    quantity              INTEGER NOT NULL DEFAULT 1,
    unit_price            INTEGER NOT NULL DEFAULT 0,    -- extra price in minor unit
    note                  TEXT,
    created_at            TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_order_item_toppings_item ON order_item_toppings(order_item_id);
