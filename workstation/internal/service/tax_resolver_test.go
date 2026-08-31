package service

import "testing"

// seedTaxType inserts a synced tax_types row (#1099 single-rate).
func seedTaxType(t *testing.T, e *OrderEngine, id, code string, rate float64, isDefault bool) {
	t.Helper()
	def := 0
	if isDefault {
		def = 1
	}
	if _, err := e.db.Exec(`
		INSERT INTO tax_types (id, code, name, rate, is_default, is_active)
		VALUES (?, ?, ?, ?, ?, 1)`,
		id, code, code, rate, def); err != nil {
		t.Fatalf("seed tax_type: %v", err)
	}
}

// TestResolveLineTax_SingleRate — a tax type is ONE number (#1099). The
// resolver has no order-type input at all: REDUCED resolves to 8 no matter
// how the order is consumed; context lives on the menu line ordered from.
func TestResolveLineTax_SingleRate(t *testing.T) {
	e, _ := newOrderEngineForTest(t)
	seedTaxType(t, e, "reduced", "軽減", 8, false)
	seedTaxType(t, e, "standard", "標準", 10, false)

	if r := e.resolveLineTax("reduced"); r.Rate != 8 || !r.HasSnapshot {
		t.Errorf("reduced: rate=%g snapshot=%v, want 8/true", r.Rate, r.HasSnapshot)
	}
	if r := e.resolveLineTax("standard"); r.Rate != 10 || !r.HasSnapshot {
		t.Errorf("standard: rate=%g snapshot=%v, want 10/true", r.Rate, r.HasSnapshot)
	}
}

// TestResolveLineTax_ChainFallback — no per-line type → branch default →
// brand default → legacy fallback (no snapshot).
func TestResolveLineTax_ChainFallback(t *testing.T) {
	e, _ := newOrderEngineForTest(t)

	// Nothing synced → legacy fallback (no snapshot).
	r := e.resolveLineTax("")
	if r.HasSnapshot {
		t.Errorf("nothing synced: hasSnapshot=%v, want false", r.HasSnapshot)
	}
	if r.taxRateNullable() != nil {
		t.Errorf("fresh org: nullable rate = %v, want nil", r.taxRateNullable())
	}

	// Brand default (is_default) resolves when no per-line + no branch default.
	seedTaxType(t, e, "reduced", "軽減", 8, true) // is_default = true
	r = e.resolveLineTax("")
	if !r.HasSnapshot || r.TaxTypeID != "reduced" || r.Rate != 8 {
		t.Errorf("brand default: id=%q rate=%g snapshot=%v, want reduced/8/true", r.TaxTypeID, r.Rate, r.HasSnapshot)
	}

	// Branch default overrides brand default.
	seedTaxType(t, e, "std", "標準", 10, false)
	if _, err := e.db.Exec(`INSERT INTO shop_settings (key, value) VALUES ('default_tax_type_id', 'std')`); err != nil {
		t.Fatalf("seed branch default: %v", err)
	}
	r = e.resolveLineTax("")
	if r.TaxTypeID != "std" || r.Rate != 10 {
		t.Errorf("branch default: id=%q rate=%g, want std/10", r.TaxTypeID, r.Rate)
	}

	// A referenced-but-unsynced type CONTINUES down the chain rather than
	// bailing out: the state only happens mid-sync, and Cloud (whose FK cannot
	// dangle) would have used the branch default here. Bailing out used to stamp
	// the legacy shop rate, i.e. a rate Cloud never chose. Pinned by
	// testdata/tax_resolution_golden.json.
	r = e.resolveLineTax("ghost-type-id")
	if !r.HasSnapshot || r.TaxTypeID != "std" || r.Rate != 10 {
		t.Errorf("unsynced line type: id=%q rate=%g snapshot=%v, want std/10/true (fall through to the branch default)", r.TaxTypeID, r.Rate, r.HasSnapshot)
	}
}

// TestResolveLineTax_NoIsActiveFilter — a DEACTIVATED tax type still resolves.
//
// Cloud filters is_active at no tier: deactivation blocks NEW assignment
// (BR-TT02) but must not re-rate lines already pointing at the type. This test
// exists because the mirror used to add `AND is_active = 1` to the brand-default
// lookup, so deactivating a brand default made the workstation stamp 0% on every
// line while Cloud kept stamping the type's real rate.
func TestResolveLineTax_NoIsActiveFilter(t *testing.T) {
	e, _ := newOrderEngineForTest(t)
	seedTaxType(t, e, "retired", "旧税率", 5, true) // is_default = true
	mustExecTax(t, e, `UPDATE tax_types SET is_active = 0 WHERE id = 'retired'`)

	if r := e.resolveLineTax(""); !r.HasSnapshot || r.TaxTypeID != "retired" || r.Rate != 5 {
		t.Errorf("inactive brand default: id=%q rate=%g snapshot=%v, want retired/5/true", r.TaxTypeID, r.Rate, r.HasSnapshot)
	}
	if r := e.resolveLineTax("retired"); !r.HasSnapshot || r.Rate != 5 {
		t.Errorf("inactive line type: rate=%g snapshot=%v, want 5/true", r.Rate, r.HasSnapshot)
	}
}

// TestResolveLineTax_NothingResolvedIsZeroNotLegacy — the money-critical one.
//
// A brand whose tax_types never synced stamps 0% with no snapshot, NOT the
// legacy shop_settings.tax_rate. Cloud dropped that fallback (plan-043 T6.2), so
// keeping it here printed 10% on a receipt Cloud then booked at 0%.
func TestResolveLineTax_NothingResolvedIsZeroNotLegacy(t *testing.T) {
	e, _ := newOrderEngineForTest(t)
	mustExecTax(t, e, `INSERT INTO shop_settings (key, value) VALUES ('tax_rate', '10')`)

	r := e.resolveLineTax("")
	if r.HasSnapshot || r.Rate != 0 || r.TaxTypeID != "" {
		t.Errorf("nothing resolved: id=%q rate=%g snapshot=%v, want \"\"/0/false — the legacy 10%% must not leak in", r.TaxTypeID, r.Rate, r.HasSnapshot)
	}
	if r.taxRateNullable() != nil {
		t.Errorf("nothing resolved: nullable rate = %v, want nil", r.taxRateNullable())
	}
}

// TestResolveLineTax_ExplicitZeroIsAStamp — EXEMPT (0%) stamps rate 0, which
// must stay distinguishable from the unstamped legacy NULL.
func TestResolveLineTax_ExplicitZeroIsAStamp(t *testing.T) {
	e, _ := newOrderEngineForTest(t)
	seedTaxType(t, e, "exempt", "非課税", 0, false)

	r := e.resolveLineTax("exempt")
	if !r.HasSnapshot || r.Rate != 0 {
		t.Errorf("exempt: snapshot=%v rate=%g, want true/0", r.HasSnapshot, r.Rate)
	}
	if v := r.taxRateNullable(); v == nil {
		t.Error("exempt: nullable rate is nil — a stamped 0% line must not look unstamped")
	}
}

// TestOrderThroughEngine_MixedRate — the proof case end-to-end through the
// OrderEngine: an order mixing an 8%-menu-line bentō + a 10% beer prices to
// tax 130 / total 1630 (parity with Cloud) — and the ORDER TYPE plays no
// part: the same menu lines yield the same money for takeaway and dine-in.
func TestOrderThroughEngine_MixedRate(t *testing.T) {
	for _, orderType := range []string{"takeaway", "dine_in"} {
		t.Run(orderType, func(t *testing.T) {
			e, _ := newOrderEngineForTest(t)
			seedTaxType(t, e, "reduced", "軽減", 8, false)
			seedTaxType(t, e, "std", "標準", 10, true)

			mustExecTax(t, e, `INSERT INTO pos_products (id, name) VALUES ('p-bento','Bento'),('p-beer','Beer')`)
			mustExecTax(t, e, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
				VALUES ('sku-bento','p-bento','Reg','SKU-B',1000,1),('sku-beer','p-beer','Reg','SKU-BE',500,1)`)
			// The bentō rides a takeaway-menu line overriding to REDUCED; the
			// beer inherits the STANDARD default — context = menu line (#1099).
			mustExecTax(t, e, `INSERT INTO menu_items (id, sku_id, name, price, is_active, tax_type_id)
				VALUES ('mi-bento','sku-bento','Bento',1000,1,'reduced'),
				       ('mi-beer','sku-beer','Beer',500,1,'std')`)

			o, err := e.Create(CreateOrderInput{OrderType: orderType, Items: []CreateItemInput{
				{ProductSkuID: "sku-bento", Quantity: 1},
				{ProductSkuID: "sku-beer", Quantity: 1},
			}}, nil)
			if err != nil {
				t.Fatalf("create: %v", err)
			}

			if o.TaxAmount != 130 {
				t.Errorf("tax_amount = %g, want 130 (80@8%% + 50@10%%)", o.TaxAmount)
			}
			if o.TotalAmount != 1630 {
				t.Errorf("total_amount = %d, want 1630", o.TotalAmount)
			}

			items, _ := e.getItems(o.ID)
			byName := map[string]Item{}
			for _, it := range items {
				byName[it.ProductSkuID] = it
			}
			if r := byName["sku-bento"].TaxRate; r == nil || *r != 8 {
				t.Errorf("bento tax_rate = %v, want 8", r)
			}
			if r := byName["sku-beer"].TaxRate; r == nil || *r != 10 {
				t.Errorf("beer tax_rate = %v, want 10", r)
			}
		})
	}
}

func mustExecTax(t *testing.T, e *OrderEngine, sql string) {
	t.Helper()
	if _, err := e.db.Exec(sql); err != nil {
		t.Fatalf("exec %q: %v", sql, err)
	}
}
