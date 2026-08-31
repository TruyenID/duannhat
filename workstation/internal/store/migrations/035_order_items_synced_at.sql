-- Item sync-UP tracking.
--
-- Before this, items added to an order in LAN mode were written to local SQLite
-- and broadcast over the WS hub but NEVER pushed to Cloud (there was no
-- order.item_add sync op). Cloud — and therefore shop/HQ order views — showed
-- the order with "Chưa có món" / ¥0 even though the POS cart was full.
--
-- `synced_at` marks a line as confirmed present on Cloud. The sync engine's
-- reconciler enqueues an order.item_add for any line still NULL here whose
-- parent order already has a cloud_id, so:
--   * lines added at order-creation time sync,
--   * lines that failed to enqueue (older builds) heal on the next tick,
--   * the push is idempotent (Cloud upserts by item id), so a re-send is a
--     no-op.
ALTER TABLE order_items ADD COLUMN synced_at TEXT;

-- Deliberately NOT backfilled: every existing line predates item sync, so it
-- must be treated as unsynced (synced_at = NULL) and pushed up once. The
-- reconciler + Cloud's idempotent upsert make that safe.
