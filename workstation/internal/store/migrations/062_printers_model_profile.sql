-- plan-052 T1.4b (#1166) — the printer capability profile, mirrored DOWN.
--
-- DESIGN §3b: everything that differs between machines (kanji ROM or not, which
-- cut command, whether the drawer can be kicked and on which pin, how the
-- machine reports errors, how to health-check it) lives in this ONE json blob,
-- as DATA. The renderer reads it to choose the way out; there is no per-model
-- branch in any formatter, and adding a machine never needs a new binary.
--
-- NULL / empty is a valid, working state: it resolves to `escpos_generic`
-- (P-29), so a printer a shop has never described still prints.
ALTER TABLE printers ADD COLUMN model_profile TEXT;

-- The transport this printer is driven over. `ws_lan` for everything today —
-- the column exists so the Cloud value round-trips DOWN without a schema change
-- when M3/M4 land.
ALTER TABLE printers ADD COLUMN transport TEXT NOT NULL DEFAULT 'ws_lan';
