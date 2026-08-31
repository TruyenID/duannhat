package store

import (
	"fmt"
	"log/slog"
)

// repairLegacySchema heals DBs that pre-date commit aa436c3
// ("migration cleanup"). That commit deleted the old
// `001_initial_schema.sql` + `004_local_replica.sql` and replaced them
// with the current numbered set — but `schema_migrations` already had
// rows for versions 1..4 from the OLD files, so the migration runner
// skips the new ones with the same version. Result: existing DBs miss
// columns/tables that the rewritten migrations would have created.
//
// Observed at runtime as:
//
//	WARN sync_pull menu       err="SQL logic error: no such column: sku_id"
//	WARN sync_pull handy_menu err="SQL logic error: no such table: handy_menu_cache"
//
// This function runs once at boot, introspects the current schema via
// PRAGMA, and applies the missing bits idempotently. It's a no-op on
// fresh DBs where the new migrations created everything correctly.
//
// Every operation is a CREATE TABLE IF NOT EXISTS or an introspected
// ALTER TABLE ADD COLUMN so re-running this function is always safe.
func (db *DB) repairLegacySchema() error {
	// 1. menu_items.sku_id — added in the rewritten 004_menu.sql.
	hasSkuID, err := db.columnExists("menu_items", "sku_id")
	if err != nil {
		return fmt.Errorf("introspect menu_items.sku_id: %w", err)
	}
	if !hasSkuID {
		if _, err := db.conn.Exec(`ALTER TABLE menu_items ADD COLUMN sku_id TEXT`); err != nil {
			return fmt.Errorf("repair menu_items.sku_id: %w", err)
		}
		if _, err := db.conn.Exec(`CREATE INDEX IF NOT EXISTS idx_menu_items_sku_id ON menu_items(sku_id) WHERE sku_id IS NOT NULL`); err != nil {
			return fmt.Errorf("repair idx_menu_items_sku_id: %w", err)
		}
		slog.Info("repair: added menu_items.sku_id column")
	}

	// 2. menu_items extra columns added in the rewritten 004 that the
	// old initial-schema didn't ship. Each is checked + ALTERed
	// independently so a partial-old DB still recovers cleanly.
	for col, sqlType := range map[string]string{
		"name_ja":          "TEXT",
		"description":      "TEXT",
		"discount_price":   "INTEGER",
		"discount_pct":     "REAL",
		"printer_group":    "TEXT NOT NULL DEFAULT 'kitchen'",
		"image_url":        "TEXT",
		"cloud_updated_at": "TEXT",
	} {
		exists, err := db.columnExists("menu_items", col)
		if err != nil {
			return fmt.Errorf("introspect menu_items.%s: %w", col, err)
		}
		if exists {
			continue
		}
		stmt := fmt.Sprintf(`ALTER TABLE menu_items ADD COLUMN %s %s`, col, sqlType)
		if _, err := db.conn.Exec(stmt); err != nil {
			return fmt.Errorf("repair menu_items.%s: %w", col, err)
		}
		slog.Info("repair: added column", "table", "menu_items", "column", col)
	}

	// 3. handy_menu_cache — only created by the rewritten 004.
	if _, err := db.conn.Exec(`
		CREATE TABLE IF NOT EXISTS handy_menu_cache (
		    id          TEXT PRIMARY KEY DEFAULT 'current',
		    day_of_week INTEGER NOT NULL,
		    payload     TEXT NOT NULL,
		    fetched_at  TEXT NOT NULL DEFAULT (datetime('now'))
		)`); err != nil {
		return fmt.Errorf("repair handy_menu_cache: %w", err)
	}

	// 4. menu_meta — same root cause.
	if _, err := db.conn.Exec(`
		CREATE TABLE IF NOT EXISTS menu_meta (
		    id                   TEXT PRIMARY KEY DEFAULT 'current',
		    cloud_menu_id        TEXT,
		    cloud_menu_name      TEXT,
		    cart_timeout_minutes INTEGER,
		    cart_deadline_iso    TEXT,
		    synced_at            TEXT NOT NULL DEFAULT (datetime('now'))
		)`); err != nil {
		return fmt.Errorf("repair menu_meta: %w", err)
	}

	// 5. menu_schedules — same root cause. day_of_week CHECK matches
	// the rewritten 004 verbatim.
	if _, err := db.conn.Exec(`
		CREATE TABLE IF NOT EXISTS menu_schedules (
		    id               TEXT PRIMARY KEY,
		    menu_id          TEXT NOT NULL,
		    day_of_week      INTEGER NOT NULL CHECK (day_of_week BETWEEN 0 AND 6),
		    is_active        INTEGER NOT NULL DEFAULT 1,
		    cloud_updated_at TEXT,
		    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now'))
		)`); err != nil {
		return fmt.Errorf("repair menu_schedules: %w", err)
	}
	if _, err := db.conn.Exec(`CREATE INDEX IF NOT EXISTS idx_menu_schedules_dow  ON menu_schedules(day_of_week, is_active)`); err != nil {
		return fmt.Errorf("repair idx_menu_schedules_dow: %w", err)
	}
	if _, err := db.conn.Exec(`CREATE INDEX IF NOT EXISTS idx_menu_schedules_menu ON menu_schedules(menu_id)`); err != nil {
		return fmt.Errorf("repair idx_menu_schedules_menu: %w", err)
	}

	// 5b. menu_schedules schedule times — added in 024. When
	// `repairLegacySchema` resurrects the table above we get them via
	// the CREATE shape, but DBs that already had menu_schedules from
	// the pre-aa436c3 layout (no start_time/end_time/priority columns)
	// only get them through this introspected ALTER.
	for col, sqlType := range map[string]string{
		"start_time": "TEXT",
		"end_time":   "TEXT",
		"priority":   "INTEGER NOT NULL DEFAULT 0",
	} {
		exists, err := db.columnExists("menu_schedules", col)
		if err != nil {
			return fmt.Errorf("introspect menu_schedules.%s: %w", col, err)
		}
		if exists {
			continue
		}
		if _, err := db.conn.Exec(fmt.Sprintf("ALTER TABLE menu_schedules ADD COLUMN %s %s", col, sqlType)); err != nil {
			return fmt.Errorf("repair menu_schedules.%s: %w", col, err)
		}
		slog.Info("repair: added column", "table", "menu_schedules", "column", col)
	}
	if _, err := db.conn.Exec(`CREATE INDEX IF NOT EXISTS idx_menu_schedules_priority ON menu_schedules(menu_id, day_of_week, priority)`); err != nil {
		return fmt.Errorf("repair idx_menu_schedules_priority: %w", err)
	}

	// 6. order_items.sku_variant_name — was migration 010 in the
	// pre-aa436c3 layout but the version slot got remapped to
	// `010_kitchen_ticket_counter.sql` during the migration cleanup.
	// Old DBs recorded v10 from the original idempotency_keys file +
	// v12 from idempotency_composite_pk → the rewritten 012 (which
	// adds sku_variant_name) silently skipped. Symptom: POST
	// /api/v1/pos/orders/{id}/items returned 500 with
	// "table order_items has no column named sku_variant_name".
	hasSkuVariant, err := db.columnExists("order_items", "sku_variant_name")
	if err != nil {
		return fmt.Errorf("introspect order_items.sku_variant_name: %w", err)
	}
	if !hasSkuVariant {
		if _, err := db.conn.Exec(`ALTER TABLE order_items ADD COLUMN sku_variant_name TEXT`); err != nil {
			return fmt.Errorf("repair order_items.sku_variant_name: %w", err)
		}
		slog.Info("repair: added order_items.sku_variant_name column")
	}

	// 7. kitchen_ticket_counters — same migration-cleanup victim.
	// Without it kitchen ticket printing throws "no such table" the
	// first time staff fires an order to the kitchen.
	if _, err := db.conn.Exec(`
		CREATE TABLE IF NOT EXISTS kitchen_ticket_counters (
		    date    TEXT PRIMARY KEY,
		    counter INTEGER NOT NULL DEFAULT 0
		)`); err != nil {
		return fmt.Errorf("repair kitchen_ticket_counters: %w", err)
	}

	// 8. order_item_toppings — required by AddItems for any item that
	// carries topping selections. Missing column / table → every
	// topping-laden cart insert 500s.
	if _, err := db.conn.Exec(`
		CREATE TABLE IF NOT EXISTS order_item_toppings (
		    id                    TEXT PRIMARY KEY,
		    order_item_id         TEXT NOT NULL REFERENCES order_items(id) ON DELETE CASCADE,
		    topping_group_item_id TEXT NOT NULL,
		    product_sku_id        TEXT NOT NULL,
		    name                  TEXT,
		    modifier_type         TEXT NOT NULL DEFAULT 'add',
		    topping_group_id      TEXT,
		    topping_group_name    TEXT,
		    quantity              INTEGER NOT NULL DEFAULT 1,
		    unit_price            INTEGER NOT NULL DEFAULT 0,
		    note                  TEXT,
		    created_at            TEXT NOT NULL DEFAULT (datetime('now'))
		)`); err != nil {
		return fmt.Errorf("repair order_item_toppings: %w", err)
	}
	if _, err := db.conn.Exec(`CREATE INDEX IF NOT EXISTS idx_order_item_toppings_item ON order_item_toppings(order_item_id)`); err != nil {
		return fmt.Errorf("repair idx_order_item_toppings_item: %w", err)
	}

	// 9. payments.* full-parity columns — migration 026 added them. For
	// DBs that pre-date 026, ALTER each missing column idempotently so
	// pos-web's PaymentDialog can round-trip every field (cash flow
	// needs tendered_amount + change_amount; auto-confirm needs
	// paid_at; plan-030 needs till_session_id). Without these the
	// INSERT in handleLocalPosCreatePayment 500s with "table payments
	// has no column named ...".
	for col, sqlType := range map[string]string{
		"payment_method_id": "TEXT",
		"tip_amount":        "INTEGER NOT NULL DEFAULT 0",
		"tendered_amount":   "INTEGER",
		"change_amount":     "INTEGER",
		"reference_no":      "TEXT",
		"note":              "TEXT",
		"paid_at":           "TEXT",
		"expires_at":        "TEXT",
		"till_session_id":   "TEXT",
		// sync_target (plan-818, migration 040_payments_sync_target). CRITICAL:
		// migration 040 is DUPLICATED — both 040_payments_sync_target.sql and
		// 040_plan045_refund_rounding.sql claim version 40, and the runner keys on
		// the integer version, so whichever sorts first records version 40 and the
		// OTHER is PERMANENTLY skipped. On any DB that recorded 40 via the plan-045
		// file, sync_target was never added and every LAN payment INSERT 500s with
		// "table payments has no column named sync_target". Healing it here
		// (idempotent, every Open) fixes all such DBs — the safe alternative to
		// renumbering, which would re-ALTER an already-present column on DBs that
		// recorded 40 via the sync_target file.
		"sync_target": "TEXT",
	} {
		exists, err := db.columnExists("payments", col)
		if err != nil {
			return fmt.Errorf("introspect payments.%s: %w", col, err)
		}
		if exists {
			continue
		}
		if _, err := db.conn.Exec(fmt.Sprintf("ALTER TABLE payments ADD COLUMN %s %s", col, sqlType)); err != nil {
			return fmt.Errorf("repair payments.%s: %w", col, err)
		}
		slog.Info("repair: added column", "table", "payments", "column", col)
	}
	if _, err := db.conn.Exec(`CREATE INDEX IF NOT EXISTS idx_payments_method ON payments(payment_method_id)`); err != nil {
		return fmt.Errorf("repair idx_payments_method: %w", err)
	}

	// 10. plan-043 (T3.1) — consumption-tax mirror + snapshot columns. Migration
	// 038 adds these on a fresh DB; heal DBs that recorded v038 from an older
	// layout, or that pre-date the migration, so the tax engine never hits a
	// "no such column: tax_rate" at pricing time. Every column carries a safe
	// DEFAULT (or is nullable) so old rows behave like unstamped/legacy lines.
	if _, err := db.conn.Exec(`
		CREATE TABLE IF NOT EXISTS tax_types (
		    id               TEXT PRIMARY KEY,
		    code             TEXT NOT NULL DEFAULT '',
		    name             TEXT NOT NULL DEFAULT '',
		    rate             REAL NOT NULL DEFAULT 0,
		    is_default       INTEGER NOT NULL DEFAULT 0,
		    is_active        INTEGER NOT NULL DEFAULT 1,
		    cloud_updated_at TEXT,
		    local_synced_at  TEXT NOT NULL DEFAULT (datetime('now'))
		)`); err != nil {
		return fmt.Errorf("repair tax_types: %w", err)
	}
	if _, err := db.conn.Exec(`CREATE INDEX IF NOT EXISTS idx_tax_types_default ON tax_types(is_default) WHERE is_default = 1`); err != nil {
		return fmt.Errorf("repair idx_tax_types_default: %w", err)
	}

	// Each (table, column, type) is introspected + ALTERed independently so a
	// partially-migrated DB still recovers cleanly (mirror of migration 038).
	for _, c := range []struct{ table, col, sqlType string }{
		{"order_items", "tax_type_id", "TEXT"},
		{"order_items", "tax_rate", "REAL"},
		{"order_items", "tax_amount", "INTEGER DEFAULT 0"},
		{"orders", "is_tax_included", "INTEGER DEFAULT 0"},
		{"menu_items", "tax_type_id", "TEXT"},
		// plan-043 (T4.4) — per-rate invoice mirror columns (migration 039).
		{"customer_invoices", "tax_breakdown", "TEXT NOT NULL DEFAULT '[]'"},
		{"customer_invoices", "seller_registration_number", "TEXT"},
		// plan-045 (T10) — rounding snapshot + refund columns (migration 064,
		// numbered 040 until #1196 renumbered it out of a version collision).
		// Still needed: a workstation upgraded BEFORE 064 existed got these
		// columns from here, and 064 now skips whichever it already finds.
		{"orders", "tax_rounding_mode", "TEXT DEFAULT 'round'"},
		{"orders", "tax_rounding_decimals", "INTEGER DEFAULT 0"},
		{"order_items", "refund_of_item_id", "TEXT"},
		{"order_items", "refunded_quantity", "INTEGER DEFAULT 0"},
		// #1282 (migration 066) — display-only mirror of Cloud's payment
		// summary, so a receipt for an online-paid order can still name the
		// method. Healed here too: the pull-DOWN upsert names the column
		// unconditionally, so a DB that missed 066 would fail EVERY order
		// pull with "no such column", not just the printing.
		{"orders", "cloud_payment_summary", "TEXT"},
		// #2934 (migration 095) — Cloud order attribution used only by
		// revenue reports. It stays separate from payments.till_session_id so
		// online Stripe / PayPay never changes the expected drawer balance.
		{"orders", "cloud_till_session_id", "TEXT"},
	} {
		exists, err := db.columnExists(c.table, c.col)
		if err != nil {
			return fmt.Errorf("introspect %s.%s: %w", c.table, c.col, err)
		}
		if exists {
			continue
		}
		if _, err := db.conn.Exec(fmt.Sprintf("ALTER TABLE %s ADD COLUMN %s %s", c.table, c.col, c.sqlType)); err != nil {
			return fmt.Errorf("repair %s.%s: %w", c.table, c.col, err)
		}
		slog.Info("repair: added column", "table", c.table, "column", c.col)
	}
	if _, err := db.conn.Exec(`CREATE INDEX IF NOT EXISTS idx_orders_cloud_till_session
		ON orders(cloud_till_session_id) WHERE cloud_till_session_id IS NOT NULL`); err != nil {
		return fmt.Errorf("repair idx_orders_cloud_till_session: %w", err)
	}

	// plan-045 (T10) — order_conditions ledger (migration 064; see #1196 for
	// why it spent months as a shadowed 040 that never ran). Heal DBs that
	// recorded v040 from an older layout, or that pre-date the migration.
	if _, err := db.conn.Exec(`
		CREATE TABLE IF NOT EXISTS order_conditions (
		    id                 TEXT PRIMARY KEY,
		    conditionable_type TEXT NOT NULL DEFAULT '',
		    conditionable_id   TEXT NOT NULL DEFAULT '',
		    type               TEXT NOT NULL DEFAULT '',
		    source             TEXT,
		    label              TEXT NOT NULL DEFAULT '',
		    rate               REAL,
		    amount             REAL NOT NULL DEFAULT 0,
		    taxable_base       REAL,
		    currency_code      TEXT NOT NULL DEFAULT 'VND',
		    meta               TEXT,
		    created_at         TEXT,
		    updated_at         TEXT
		)`); err != nil {
		return fmt.Errorf("repair order_conditions: %w", err)
	}
	// #2032 — `taxable_base` (migration 078) phải được VÁ riêng, không chỉ nằm
	// trong CREATE ở trên: nhánh repair tồn tại chính vì có DB đã ghi nhận
	// migration mà thiếu cột, nên `CREATE TABLE IF NOT EXISTS` là no-op ở đúng
	// những DB cần vá nhất. Thiếu cột thì `loadOrderConditions` lỗi query và
	// trả `conditions[]` RỖNG — POS/KDS nói "không thuế" mà không có lỗi nào.
	if exists, err := db.columnExists("order_conditions", "taxable_base"); err != nil {
		return fmt.Errorf("introspect order_conditions.taxable_base: %w", err)
	} else if !exists {
		if _, err := db.conn.Exec(`ALTER TABLE order_conditions ADD COLUMN taxable_base REAL`); err != nil {
			return fmt.Errorf("repair order_conditions.taxable_base: %w", err)
		}
	}
	if _, err := db.conn.Exec(`CREATE INDEX IF NOT EXISTS idx_order_conditions_conditionable ON order_conditions(conditionable_type, conditionable_id)`); err != nil {
		return fmt.Errorf("repair idx_order_conditions_conditionable: %w", err)
	}
	if _, err := db.conn.Exec(`CREATE INDEX IF NOT EXISTS idx_order_conditions_type ON order_conditions(type)`); err != nil {
		return fmt.Errorf("repair idx_order_conditions_type: %w", err)
	}

	// plan-046 (T1.4) — chain-of-shifts columns (migration 044). Heal DBs that
	// recorded v044 from an older layout, or that pre-date the migration, so the
	// handover/final settle never hits "no such column: settlement_kind". Every
	// column is nullable or carries a DEFAULT so legacy rows read as a completed
	// chain-of-one (chain_id NULL → R1 starts a fresh chain).
	for _, c := range []struct{ table, col, sqlType string }{
		{"till_sessions", "chain_id", "TEXT"},
		{"till_sessions", "chain_sequence", "INTEGER NOT NULL DEFAULT 1"},
		{"till_sessions", "settlement_kind", "TEXT"},
		{"till_sessions", "settlement_snapshot", "TEXT"},
	} {
		exists, err := db.columnExists(c.table, c.col)
		if err != nil {
			return fmt.Errorf("introspect %s.%s: %w", c.table, c.col, err)
		}
		if exists {
			continue
		}
		if _, err := db.conn.Exec(fmt.Sprintf("ALTER TABLE %s ADD COLUMN %s %s", c.table, c.col, c.sqlType)); err != nil {
			return fmt.Errorf("repair %s.%s: %w", c.table, c.col, err)
		}
		slog.Info("repair: added column", "table", c.table, "column", c.col)
	}
	if _, err := db.conn.Exec(`CREATE INDEX IF NOT EXISTS idx_till_sessions_chain ON till_sessions(chain_id, chain_sequence)`); err != nil {
		return fmt.Errorf("repair idx_till_sessions_chain: %w", err)
	}

	return nil
}

// columnExists returns true when `col` is present on `table`, using
// PRAGMA table_info. The table-info pragma also returns rows for views
// + virtual tables, so callers must know they're targeting a real
// table; this codebase only ever calls it on hand-written tables.
func (db *DB) columnExists(table, col string) (bool, error) {
	// PRAGMA does not accept parameter binding for the table name; the
	// inputs here are always hard-coded string literals from repair.go,
	// never user input, so the format-string assembly is safe.
	rows, err := db.conn.Query(fmt.Sprintf("PRAGMA table_info(%q)", table))
	if err != nil {
		return false, err
	}
	defer rows.Close()
	for rows.Next() {
		var cid int
		var name, ctype string
		var notnull, pk int
		var dfltValue any
		if err := rows.Scan(&cid, &name, &ctype, &notnull, &dfltValue, &pk); err != nil {
			return false, err
		}
		if name == col {
			return true, nil
		}
	}
	return false, rows.Err()
}
