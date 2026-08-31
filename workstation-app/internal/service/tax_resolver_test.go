package service

import "testing"

// seedTaxType inserts a synced tax_types row.
func seedTaxType(t *testing.T, e *OrderEngine, id, code string, dineIn, takeaway float64, isDefault bool) {
	t.Helper()
	def := 0
	if isDefault {
		def = 1
	}
	if _, err := e.db.Exec(`
		INSERT INTO tax_types (id, code, name, rate_dine_in, rate_takeaway, is_default, is_active)
		VALUES (?, ?, ?, ?, ?, ?, 1)`,
		id, code, code, dineIn, takeaway, def); err != nil {
		t.Fatalf("seed tax_type: %v", err)
	}
}

// TestResolveLineTax_RatePickByOrderType — spot/dine_in → rate_dine_in,
// takeaway → rate_takeaway (locked decision #5).
func TestResolveLineTax_RatePickByOrderType(t *testing.T) {
	e, _ := newOrderEngineForTest(t)
	seedTaxType(t, e, "reduced", "軽減", 10, 8, false)

	if r := e.resolveLineTax("reduced", false, false, "takeaway"); r.Rate != 8 || r.Escalated {
		t.Errorf("takeaway reduced: rate=%g escalated=%v, want 8/false", r.Rate, r.Escalated)
	}
	if r := e.resolveLineTax("reduced", false, false, "dine_in"); r.Rate != 10 {
		t.Errorf("dine_in reduced: rate=%g, want 10", r.Rate)
	}
	if r := e.resolveLineTax("reduced", false, false, "spot"); r.Rate != 10 {
		t.Errorf("spot reduced (taxed as dine-in): rate=%g, want 10", r.Rate)
	}
}

// TestResolveLineTax_AlcoholEscalation (A1) — an alcohol line on a REDUCED type
// is pulled up to the dine-in rate for ALL order types + flagged.
func TestResolveLineTax_AlcoholEscalation(t *testing.T) {
	e, _ := newOrderEngineForTest(t)
	seedTaxType(t, e, "reduced", "軽減", 10, 8, false)

	// Alcohol PRODUCT on a reduced type, takeaway → escalated to 10.
	r := e.resolveLineTax("reduced", true, false, "takeaway")
	if r.Rate != 10 || !r.Escalated {
		t.Errorf("alcohol product takeaway: rate=%g escalated=%v, want 10/true", r.Rate, r.Escalated)
	}

	// Alcohol COMPONENT (topping) drags the parent up too.
	r = e.resolveLineTax("reduced", false, true, "takeaway")
	if r.Rate != 10 || !r.Escalated {
		t.Errorf("alcohol component takeaway: rate=%g escalated=%v, want 10/true", r.Rate, r.Escalated)
	}

	// Dine-in is already the standard rate → still 10, but NOT flagged as an
	// escalation source? Escalation still fires (reduced type + alcohol) — the
	// marker is stable across order types. Rate is 10 either way.
	r = e.resolveLineTax("reduced", true, false, "dine_in")
	if r.Rate != 10 || !r.Escalated {
		t.Errorf("alcohol product dine_in: rate=%g escalated=%v, want 10/true", r.Rate, r.Escalated)
	}
}

// TestResolveLineTax_ExemptNotEscalated — a 0/0 exempt type is NOT "reduced"
// (rate_takeaway is not below rate_dine_in), so alcohol does not escalate it.
func TestResolveLineTax_ExemptNotEscalated(t *testing.T) {
	e, _ := newOrderEngineForTest(t)
	seedTaxType(t, e, "exempt", "非課税", 0, 0, false)

	r := e.resolveLineTax("exempt", true, false, "takeaway")
	if r.Rate != 0 || r.Escalated {
		t.Errorf("alcohol on exempt: rate=%g escalated=%v, want 0/false", r.Rate, r.Escalated)
	}
}

// TestResolveLineTax_StandardTypeNoEscalation — a 10/10 standard type is not
// reduced → no escalation even for alcohol (rate already standard).
func TestResolveLineTax_StandardTypeNoEscalation(t *testing.T) {
	e, _ := newOrderEngineForTest(t)
	seedTaxType(t, e, "std", "標準", 10, 10, true)

	r := e.resolveLineTax("std", true, false, "takeaway")
	if r.Rate != 10 || r.Escalated {
		t.Errorf("alcohol on standard: rate=%g escalated=%v, want 10/false", r.Rate, r.Escalated)
	}
}

// TestResolveLineTax_ChainFallback — no per-line type → branch default →
// brand default → legacy fallback (no snapshot, no escalation).
func TestResolveLineTax_ChainFallback(t *testing.T) {
	e, _ := newOrderEngineForTest(t)

	// Nothing synced → legacy fallback (no snapshot).
	r := e.resolveLineTax("", false, false, "takeaway")
	if r.HasSnapshot || r.Escalated {
		t.Errorf("nothing synced: hasSnapshot=%v escalated=%v, want false/false", r.HasSnapshot, r.Escalated)
	}

	// Brand default (is_default) resolves when no per-line + no branch default.
	seedTaxType(t, e, "reduced", "軽減", 10, 8, true) // is_default = true
	r = e.resolveLineTax("", false, false, "takeaway")
	if !r.HasSnapshot || r.TaxTypeID != "reduced" || r.Rate != 8 {
		t.Errorf("brand default: id=%q rate=%g snapshot=%v, want reduced/8/true", r.TaxTypeID, r.Rate, r.HasSnapshot)
	}

	// Branch default overrides brand default.
	seedTaxType(t, e, "std", "標準", 10, 10, false)
	if _, err := e.db.Exec(`INSERT INTO shop_settings (key, value) VALUES ('default_tax_type_id', 'std')`); err != nil {
		t.Fatalf("seed branch default: %v", err)
	}
	r = e.resolveLineTax("", false, false, "takeaway")
	if r.TaxTypeID != "std" || r.Rate != 10 {
		t.Errorf("branch default: id=%q rate=%g, want std/10", r.TaxTypeID, r.Rate)
	}
}

// TestOrderThroughEngine_MixedRateTakeaway — the proof case end-to-end through
// the OrderEngine: a takeaway order with an 8% bentō + a 10% beer prices to
// tax 130 / total 1630 (parity with Cloud).
func TestOrderThroughEngine_MixedRateTakeaway(t *testing.T) {
	e, _ := newOrderEngineForTest(t)
	seedTaxType(t, e, "reduced", "軽減", 10, 8, false)
	seedTaxType(t, e, "std", "標準", 10, 10, true)

	// Two SKUs mirrored as menu_items with their tax type + alcohol flag.
	mustExecTax(t, e, `INSERT INTO pos_products (id, name) VALUES ('p-bento','Bento'),('p-beer','Beer')`)
	mustExecTax(t, e, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('sku-bento','p-bento','Reg','SKU-B',1000,1),('sku-beer','p-beer','Reg','SKU-BE',500,1)`)
	mustExecTax(t, e, `INSERT INTO menu_items (id, sku_id, name, price, is_active, tax_type_id, is_alcohol)
		VALUES ('mi-bento','sku-bento','Bento',1000,1,'reduced',0),
		       ('mi-beer','sku-beer','Beer',500,1,'std',1)`)

	o, err := e.Create(CreateOrderInput{OrderType: "takeaway", Items: []CreateItemInput{
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

	// The bentō line snapshots 8%, the beer line 10%.
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
}

// TestOrderThroughEngine_AlcoholEscalatesOnTakeaway — a bentō-typed (reduced)
// line that is flagged alcohol escalates to 10% on takeaway + marker.
func TestOrderThroughEngine_AlcoholEscalatesOnTakeaway(t *testing.T) {
	e, _ := newOrderEngineForTest(t)
	seedTaxType(t, e, "reduced", "軽減", 10, 8, true)

	mustExecTax(t, e, `INSERT INTO pos_products (id, name) VALUES ('p-sake','Sake')`)
	mustExecTax(t, e, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('sku-sake','p-sake','Reg','SKU-S',1000,1)`)
	// Alcohol product ERRONEOUSLY carrying a reduced type (inherit-chain gap) —
	// escalation is the defense-in-depth that catches it.
	mustExecTax(t, e, `INSERT INTO menu_items (id, sku_id, name, price, is_active, tax_type_id, is_alcohol)
		VALUES ('mi-sake','sku-sake','Sake',1000,1,'reduced',1)`)

	o, err := e.Create(CreateOrderInput{OrderType: "takeaway", Items: []CreateItemInput{
		{ProductSkuID: "sku-sake", Quantity: 1},
	}}, nil)
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	// 10% (escalated) not 8%.
	if o.TaxAmount != 100 {
		t.Errorf("tax_amount = %g, want 100 (escalated to 10%%)", o.TaxAmount)
	}
	items, _ := e.getItems(o.ID)
	if len(items) != 1 || !items[0].TaxAlcoholEscalated {
		t.Errorf("expected tax_alcohol_escalated=true on the sake line, got %+v", items)
	}
	if r := items[0].TaxRate; r == nil || *r != 10 {
		t.Errorf("sake tax_rate = %v, want 10 (escalated)", r)
	}
}

func mustExecTax(t *testing.T, e *OrderEngine, sql string) {
	t.Helper()
	if _, err := e.db.Exec(sql); err != nil {
		t.Fatalf("exec %q: %v", sql, err)
	}
}
