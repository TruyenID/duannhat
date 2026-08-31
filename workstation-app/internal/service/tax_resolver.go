package service

import "database/sql"

// plan-043 §7 + Decision 9/10 (A1) — the LOCAL (offline) consumption-tax
// resolver. Mirrors backend/app/Services/Customer/TaxResolver.php so a line
// added on the workstation stamps the same tax_type_id / tax_rate /
// tax_alcohol_escalated Cloud would, to the yen.
//
// Resolution chain (highest priority first), all from synced SQLite mirrors:
//   1. menu_items.tax_type_id (resolved MenuProduct→Product override, synced)
//   2. branch default — shop_settings.default_tax_type_id
//   3. brand default — tax_types row with is_default = 1
//   → if NOTHING resolves (fresh org mid-rollout): legacy tax_rate fallback,
//      NO type to snapshot, NO escalation.
//
// Rate pick: spot|dine_in → rate_dine_in · takeaway → rate_takeaway.
//
// 酒類 escalation (A1): if the line's product OR any chosen component is alcohol
// and the resolved type is "reduced" (rate_takeaway < rate_dine_in), force
// rate_dine_in for ALL order types + flag escalated.

// taxResolution is the per-line outcome (mirror of PHP TaxResolution).
type taxResolution struct {
	TaxTypeID   string // "" when nothing resolved (legacy fallback)
	Rate        float64
	Escalated   bool
	HasSnapshot bool // false only in the legacy no-type fallback
}

// taxTypeRow is a synced tax_types row.
type taxTypeRow struct {
	id           string
	rateDineIn   float64
	rateTakeaway float64
}

// resolveLineTax resolves the tax snapshot for one order line.
//
//   - menuItemTaxTypeID: the menu_items.tax_type_id for this line ("" = inherit)
//   - productIsAlcohol:  menu_items.is_alcohol for this line
//   - hasAlcoholComponent: true if any selected topping/component is alcohol
//   - orderType: the order's type (spot|dine_in|takeaway)
func (e *OrderEngine) resolveLineTax(
	menuItemTaxTypeID string,
	productIsAlcohol bool,
	hasAlcoholComponent bool,
	orderType string,
) taxResolution {
	typeID := menuItemTaxTypeID
	if typeID == "" {
		typeID = e.branchDefaultTaxTypeID()
	}
	if typeID == "" {
		typeID = e.brandDefaultTaxTypeID()
	}

	// Tier-5 transition fallback: nothing configured yet. Behave like the
	// legacy single-rate engine — no type to snapshot, no escalation.
	if typeID == "" {
		return taxResolution{TaxTypeID: "", Rate: e.legacyTaxRate(), Escalated: false, HasSnapshot: false}
	}

	tt, ok := e.taxTypeByID(typeID)
	if !ok {
		// Referenced type not synced yet → legacy fallback (defensive; no panic).
		return taxResolution{TaxTypeID: "", Rate: e.legacyTaxRate(), Escalated: false, HasSnapshot: false}
	}

	isTakeaway := orderType == "takeaway"
	rate := tt.rateDineIn
	if isTakeaway {
		rate = tt.rateTakeaway
	}

	// 酒類 escalation (A1). "Reduced" = takeaway rate below dine-in rate.
	// Alcohol can never ride it → force dine-in rate for every order type.
	// Exempt (0/0) is not reduced → untouched.
	escalated := false
	if (productIsAlcohol || hasAlcoholComponent) && tt.rateTakeaway < tt.rateDineIn {
		rate = tt.rateDineIn
		escalated = true
	}

	return taxResolution{TaxTypeID: tt.id, Rate: rate, Escalated: escalated, HasSnapshot: true}
}

// ─── memoised lookups (per engine call; cheap SQLite reads) ──────────────────

func (e *OrderEngine) branchDefaultTaxTypeID() string {
	// shop_settings is a flat key-value mirror; default_tax_type_id lands there
	// via PullBranch's settings flattening.
	return e.shopSettingString("default_tax_type_id", "")
}

func (e *OrderEngine) brandDefaultTaxTypeID() string {
	var id string
	_ = e.db.QueryRow(`SELECT id FROM tax_types WHERE is_default = 1 AND is_active = 1 LIMIT 1`).Scan(&id)
	return id
}

func (e *OrderEngine) taxTypeByID(id string) (taxTypeRow, bool) {
	var r taxTypeRow
	err := e.db.QueryRow(
		`SELECT id, rate_dine_in, rate_takeaway FROM tax_types WHERE id = ?`, id,
	).Scan(&r.id, &r.rateDineIn, &r.rateTakeaway)
	if err != nil {
		return taxTypeRow{}, false
	}
	return r, true
}

// menuItemTaxFields reads the per-line resolution inputs for a menu_items row.
// lookupID is resolved the same way createItem resolves it (sku_id first, then
// id). Returns ("", false) when neither matches — the caller then treats the
// line as inheriting the branch/brand default (menuItemTaxTypeID = "").
func (e *OrderEngine) menuItemTaxFields(tx *sql.Tx, lookupID string) (taxTypeID string, isAlcohol bool) {
	var tt sql.NullString
	var alc sql.NullInt64
	err := tx.QueryRow(
		`SELECT tax_type_id, is_alcohol FROM menu_items WHERE sku_id = ? AND is_active = 1 LIMIT 1`, lookupID,
	).Scan(&tt, &alc)
	if err != nil {
		err = tx.QueryRow(
			`SELECT tax_type_id, is_alcohol FROM menu_items WHERE id = ? LIMIT 1`, lookupID,
		).Scan(&tt, &alc)
	}
	if err != nil {
		return "", false
	}
	return tt.String, alc.Valid && alc.Int64 == 1
}

// toppingsHaveAlcohol reports whether any selected topping references an
// alcohol component. Toppings carry a topping_group_item_id (→
// pos_topping_group_items.is_alcohol, the mirror of the component product's
// flag) and/or a product_sku_id (→ menu_items.is_alcohol). Either being flagged
// escalates the parent line (A1: alcohol topping drags the parent UP).
func (e *OrderEngine) toppingsHaveAlcohol(tx *sql.Tx, toppings []ToppingInput) bool {
	for _, t := range toppings {
		if t.ToppingGroupItemID != "" {
			var alc sql.NullInt64
			if err := tx.QueryRow(
				`SELECT is_alcohol FROM pos_topping_group_items WHERE id = ?`, t.ToppingGroupItemID,
			).Scan(&alc); err == nil && alc.Valid && alc.Int64 == 1 {
				return true
			}
		}
		if t.ProductSkuID != "" {
			if _, isAlc := e.menuItemTaxFields(tx, t.ProductSkuID); isAlc {
				return true
			}
		}
	}
	return false
}

// orderTypeOf reads an order's order_type (needed to re-pick the rate on
// component changes / order-type changes). Returns "spot" when absent.
func (e *OrderEngine) orderTypeOf(q rowQueryer, orderID string) string {
	var ot sql.NullString
	_ = q.QueryRow(`SELECT order_type FROM orders WHERE id = ?`, orderID).Scan(&ot)
	if ot.Valid && ot.String != "" {
		return ot.String
	}
	return "spot"
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

// escalatedInt maps the escalation flag to the SQLite integer column.
func (r taxResolution) escalatedInt() int {
	if r.Escalated {
		return 1
	}
	return 0
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
