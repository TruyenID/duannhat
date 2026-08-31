package service

import (
	"database/sql"
	"log/slog"
)

// #1099 single-rate model — the LOCAL (offline) consumption-tax resolver.
// Mirrors backend/app/Services/Customer/TaxResolver.php so a line added on
// the workstation stamps the same tax_type_id / tax_rate Cloud would, to the
// yen.
//
// A tax type is ONE rate. Consumption context (店内 vs 持ち帰り) is a MENU
// concern — the takeaway menu carries reduced overrides on its items, so the
// resolver never looks at the order type and applies no special-casing
// beyond the tier walk.
//
// Resolution chain (highest priority first), all from synced SQLite mirrors:
//   1. menu_items.tax_type_id (resolved MenuProduct→Product override, synced)
//   2. branch default — shop_settings.default_tax_type_id
//   3. brand default — tax_types row with is_default = 1
//   → if NOTHING resolves: rate 0 with NO snapshot, exactly as Cloud does.
//
// Parity is pinned by testdata/tax_resolution_golden.json, the same file the
// Cloud test asserts against (backend/tests/Fixtures/). Three behaviours in
// this file were once ours alone and are now Cloud's, because a receipt printed
// offline is handed to a customer before Cloud ever sees the order — if the two
// walks disagree the shop collects one amount and books another:
//
//   - NOTHING RESOLVED is 0%, not the legacy shop_settings.tax_rate. Cloud
//     dropped that fallback (plan-043 T6.2); keeping it here meant printing 10%
//     on a sale Cloud then booked at 0%.
//   - AN UNRESOLVABLE ID AT ONE TIER CONTINUES DOWN THE CHAIN. Bailing out to
//     the legacy rate skipped the very tiers Cloud would have used — and the
//     only way to reach that state is a partial sync (shop_settings arrived
//     before its tax_types row), i.e. precisely when a register is offline.
//   - is_active IS NOT FILTERED anywhere in the walk. Deactivation blocks NEW
//     assignment; it must not silently re-rate lines already pointing at the
//     type, and Cloud applies no such filter at any tier.

// taxResolution is the per-line outcome (mirror of PHP TaxResolution).
type taxResolution struct {
	TaxTypeID   string // "" when nothing resolved (line stays unstamped)
	Rate        float64
	HasSnapshot bool // false only when nothing resolved (rate is 0, #2188)
}

// taxTypeRow is a synced tax_types row.
type taxTypeRow struct {
	id   string
	rate float64
}

// resolveLineTax resolves the tax snapshot for one order line.
//
//   - menuItemTaxTypeID: the menu_items.tax_type_id for this line ("" = inherit)
func (e *OrderEngine) resolveLineTax(menuItemTaxTypeID string) taxResolution {
	// Lazy candidates so a tier-1 hit costs no default lookups.
	candidates := []func() string{
		func() string { return menuItemTaxTypeID },
		e.branchDefaultTaxTypeID,
		e.brandDefaultTaxTypeID,
	}

	for _, candidate := range candidates {
		id := candidate()
		if id == "" {
			continue
		}
		// An id naming no synced row does not end the walk: it means this tier
		// has not arrived yet, and Cloud — where the FK cannot dangle — would
		// have carried on to the next one.
		if tt, ok := e.taxTypeByID(id); ok {
			return taxResolution{TaxTypeID: tt.id, Rate: tt.rate, HasSnapshot: true}
		}
	}

	// Nothing resolved. Reachable only for a brand whose tax types never synced.
	// 0% with no snapshot, matching Cloud — and logged, because it means this
	// register is under-collecting tax on every line until the pull succeeds.
	e.warnNoTaxTypeOnce()

	return taxResolution{TaxTypeID: "", Rate: 0, HasSnapshot: false}
}

// warnNoTaxTypeOnce logs the unresolved-tax condition once per engine, mirroring
// the Cloud resolver's warning. Once, because it fires per LINE otherwise and
// would bury itself.
func (e *OrderEngine) warnNoTaxTypeOnce() {
	e.noTaxTypeWarnOnce.Do(func() {
		slog.Warn("tax: no tax type resolved — lines are stamped 0% with no snapshot. The brand's tax_types have not synced to this workstation; check the menu pull.")
	})
}

// ─── memoised lookups (per engine call; cheap SQLite reads) ──────────────────

func (e *OrderEngine) branchDefaultTaxTypeID() string {
	// shop_settings is a flat key-value mirror; default_tax_type_id lands there
	// via PullBranch's settings flattening.
	return e.shopSettingString("default_tax_type_id", "")
}

// brandDefaultTaxTypeID is tier 3 locally (tier 4 on Cloud, which sees the
// product tier separately). No is_active filter: Cloud's brandDefault() has
// none, so adding one here would skip a deactivated brand default that Cloud
// still resolves.
func (e *OrderEngine) brandDefaultTaxTypeID() string {
	var id string
	_ = e.db.QueryRow(`SELECT id FROM tax_types WHERE is_default = 1 LIMIT 1`).Scan(&id)
	return id
}

func (e *OrderEngine) taxTypeByID(id string) (taxTypeRow, bool) {
	var r taxTypeRow
	err := e.db.QueryRow(
		`SELECT id, rate FROM tax_types WHERE id = ?`, id,
	).Scan(&r.id, &r.rate)
	if err != nil {
		return taxTypeRow{}, false
	}
	return r, true
}

// menuItemTaxTypeID reads the per-line resolution input for a menu_items row.
// lookupID is resolved the same way createItem resolves it (sku_id first, then
// id). Returns "" when neither matches — the caller then treats the line as
// inheriting the branch/brand default.
func (e *OrderEngine) menuItemTaxTypeID(tx *sql.Tx, lookupID string) string {
	// #1239 — primary key first. sku_id is not unique in menu_items (one row per
	// menu line, and every active menu is pulled), so a sku_id match picks an
	// arbitrary row. An id match is exact; when the caller passed a SKU this
	// lookup simply misses and falls through to the old one.
	var tt sql.NullString
	err := tx.QueryRow(
		`SELECT tax_type_id FROM menu_items WHERE id = ? AND is_active = 1 LIMIT 1`, lookupID,
	).Scan(&tt)
	if err != nil {
		err = tx.QueryRow(
			`SELECT tax_type_id FROM menu_items WHERE sku_id = ? AND is_active = 1 LIMIT 1`, lookupID,
		).Scan(&tt)
	}

	// plan-056 — second pass WITHOUT the is_active gate.
	//
	// A line can legitimately reference a menu_item that is currently inactive:
	// `createItem`'s third lookup has no is_active gate either, so a dish or a
	// variant the shop just turned off is still addable by hand — deliberately,
	// because taking something offline mid-service must not break the order a
	// colleague is holding. Before this pass, such a line fell through to the
	// BRANCH/BRAND default tax rate: the same dish, sold a minute apart, printed
	// at two different rates and was booked at two different rates. Nothing
	// errored; the receipt was simply wrong.
	//
	// Availability is not a tax fact. A turned-off item's tax type is still its
	// tax type.
	//
	// The gated lookups stay FIRST and keep winning. `menu_items` holds rows
	// from two feeds, both of which deactivate-then-reactivate on every pull, so
	// a stale row from a menu the shop has long dropped can sit here with an old
	// tax type. Dropping the gate outright would let that stale row answer;
	// asking for it only after the live rows have missed cannot.
	if err != nil {
		err = tx.QueryRow(
			`SELECT tax_type_id FROM menu_items WHERE id = ? LIMIT 1`, lookupID,
		).Scan(&tt)
	}
	if err != nil {
		err = tx.QueryRow(
			`SELECT tax_type_id FROM menu_items WHERE sku_id = ?
			 ORDER BY is_active DESC, local_updated_at DESC LIMIT 1`, lookupID,
		).Scan(&tt)
	}

	if err != nil {
		return ""
	}
	return tt.String
}

// taxRateNullable converts a resolution into a SQL-friendly nullable rate:
// NULL only when the resolution has no snapshot (legacy fallback), so an
// explicit 0% line is still distinguishable from an unstamped one.
func (r taxResolution) taxRateNullable() any {
	if !r.HasSnapshot {
		return nil
	}
	return r.Rate
}

func (r taxResolution) taxTypeIDNullable() any {
	if r.TaxTypeID == "" {
		return nil
	}
	return r.TaxTypeID
}

// boolToInt maps a Go bool to the 0/1 SQLite integer convention.
func boolToInt(b bool) int {
	if b {
		return 1
	}
	return 0
}

// floatPtrToNullable maps a *float64 to a SQL-friendly nullable REAL (NULL when
// nil) — used to persist an absent/legacy tax_rate as NULL.
func floatPtrToNullable(f *float64) any {
	if f == nil {
		return nil
	}
	return *f
}
