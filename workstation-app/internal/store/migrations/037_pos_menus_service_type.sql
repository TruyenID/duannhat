-- POS menu service-type gate (#481).
--
-- The LAN menu list (GET /api/v1/pos/menus + /pos/menus/by-day) is served from
-- the `pos_menus` mirror. After the #463 Takeaway/DineIn menu split, POS must
-- only surface menus valid for the current order's service type — but the
-- mirror carried no such column, so the workstation ignored the ?service_type=
-- filter pos-web now sends and showed every menu regardless of order type.
--
-- Cloud's MenuCatalogReplicaController now emits the ALREADY-RESOLVED effective
-- service type per menu (own value, else the master menu's, else 'Both'), so
-- the workstation just stores it verbatim and gates on it — no inherit logic
-- needed locally.
ALTER TABLE pos_menus ADD COLUMN service_type TEXT;

-- Existing rows (synced before this column existed) get NULL. The list/by-day
-- handlers treat NULL as "always show" (same as 'Both') so a mirror that hasn't
-- re-synced yet degrades safely to the old show-everything behaviour until the
-- next menu pull backfills the real value.
