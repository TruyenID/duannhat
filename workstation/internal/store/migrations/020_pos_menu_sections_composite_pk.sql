-- Composite PK fix for pos_menu_sections.
--
-- Cloud's MenuCatalogReplicaController emits one section row per
-- (menu, section) pivot entry — so a section shared between two menus
-- arrives twice with the same `id` but different `menu_id`. Migration
-- 018 created the table with `id TEXT PRIMARY KEY`, which collides on
-- the second emission:
--
--   WARN sync_pull menu_catalog
--        err="UNIQUE constraint failed: pos_menu_sections.id (1555)"
--
-- The natural key is (id, menu_id). Recreating the table with a
-- composite PK matches both the producer's row shape and the reader's
-- access pattern (loadMenuSections WHERE menu_id = ?). The
-- pos_menu_products.menu_section_id reference is plain TEXT (no FK), so
-- no foreign-key cascade is broken.

PRAGMA foreign_keys = OFF;

CREATE TABLE pos_menu_sections_new (
    id               TEXT NOT NULL,
    menu_id          TEXT NOT NULL,
    name             TEXT NOT NULL,
    sort_order       INTEGER NOT NULL DEFAULT 0,
    is_active        INTEGER NOT NULL DEFAULT 1,
    cloud_updated_at TEXT,
    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (id, menu_id),
    FOREIGN KEY (menu_id) REFERENCES pos_menus(id) ON DELETE CASCADE
);

-- Copy existing rows. The old PK guaranteed (id) was unique, so the
-- composite PK can't collide here.
INSERT INTO pos_menu_sections_new (id, menu_id, name, sort_order, is_active,
    cloud_updated_at, local_synced_at)
SELECT id, menu_id, name, sort_order, is_active, cloud_updated_at, local_synced_at
FROM pos_menu_sections;

DROP TABLE pos_menu_sections;
ALTER TABLE pos_menu_sections_new RENAME TO pos_menu_sections;

CREATE INDEX IF NOT EXISTS idx_pos_menu_sections_menu
    ON pos_menu_sections(menu_id, sort_order);

PRAGMA foreign_keys = ON;
