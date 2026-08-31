package service

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

// Production incident regression: shop manager adds a JPY denomination
// via Shop Settings, waits 5+ min, LAN-mode pos-web shows zero rows.
// Root cause was Laravel's `decimal:2` Eloquent cast emitting JSON
// values as quoted strings (`"1000.00"`), which the puller's
// `Value float64` field crash-decoded — silent error, no INSERT,
// table stayed empty.
//
// These tests mock Cloud with the EXACT shape Cloud emits in
// production (quoted decimal strings from `decimal:N` cast) and assert
// the pull actually lands rows. Any future field-type drift breaks
// these tests instead of vanishing into the void.

func TestPullDenominations_DecodesLaravelDecimalCast(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		// `value` is quoted — this is what Laravel's decimal:2 cast emits.
		w.Write([]byte(`{"data":[
			{"id":"d1","currency_code":"JPY","value":"1000.00","kind":"note","label":"1000円","sort_order":5,"is_active":true},
			{"id":"d2","currency_code":"JPY","value":"500.00","kind":"coin","label":"500円","sort_order":4,"is_active":true},
			{"id":"d3","currency_code":"USD","value":"0.25","kind":"coin","label":"quarter","sort_order":3,"is_active":true}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	if err := p.PullDenominations(context.Background()); err != nil {
		t.Fatalf("PullDenominations must succeed on production payload: %v", err)
	}

	var count int
	db.QueryRow("SELECT COUNT(*) FROM denominations").Scan(&count)
	if count != 3 {
		t.Errorf("expected 3 rows after pull, got %d (bug — production decimal cast silently dropped rows)", count)
	}

	var value float64
	db.QueryRow("SELECT value FROM denominations WHERE id = 'd1'").Scan(&value)
	if value != 1000 {
		t.Errorf("d1 value want 1000, got %v", value)
	}

	db.QueryRow("SELECT value FROM denominations WHERE id = 'd3'").Scan(&value)
	if value != 0.25 {
		t.Errorf("d3 value want 0.25, got %v", value)
	}

	// Filter-by-currency sanity check — the handler's `?currency=JPY`
	// query should pick up the two JPY rows.
	var jpyCount int
	db.QueryRow(`SELECT COUNT(*) FROM denominations WHERE currency_code = 'JPY' AND is_active = 1`).Scan(&jpyCount)
	if jpyCount != 2 {
		t.Errorf("expected 2 JPY rows, got %d", jpyCount)
	}
}

func TestPullDenominations_StillDecodesUnquotedNumber(t *testing.T) {
	// Backward-compat: if Cloud ever switches an endpoint OFF the decimal
	// cast (e.g. integer yen), we must keep working.
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":[
			{"id":"d1","currency_code":"JPY","value":1000,"kind":"note","label":"","sort_order":0,"is_active":true}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullDenominations(context.Background()); err != nil {
		t.Fatalf("unquoted number form must still decode: %v", err)
	}

	var n int
	db.QueryRow("SELECT COUNT(*) FROM denominations").Scan(&n)
	if n != 1 {
		t.Errorf("want 1 row, got %d", n)
	}
}

func TestPullTill_DecodesLaravelDecimalToleranceAmount(t *testing.T) {
	// Same regression class: `variance_tolerance_amount` is decimal:2
	// on the Till model. Production sends quoted string.
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":{
			"id":"till-1","branch_id":"b1","code":"MAIN",
			"default_currency_code":"JPY",
			"variance_tolerance_amount":"100.00",
			"current_session_id":null
		}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullTill(context.Background()); err != nil {
		t.Fatalf("PullTill must decode decimal string: %v", err)
	}

	var tolerance float64
	db.QueryRow("SELECT variance_tolerance_amount FROM tills WHERE id = 'till-1'").Scan(&tolerance)
	if tolerance != 100 {
		t.Errorf("variance_tolerance_amount want 100, got %v", tolerance)
	}
}

func TestPullLots_DecodesLaravelDecimalQuantity(t *testing.T) {
	// inventory_lots.quantity is decimal cast on Cloud.
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"lots":[
			{"id":"L1","material_id":"m1","material_name":"Flour","warehouse_id":"w1","warehouse_name":"Main","quantity":"25.500","unit":"kg","expires_at":"","status":"active","updated_at":"2026-01-01T00:00:00Z"}
		],"generated_at":"2026-01-01T00:00:00Z"}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullLots(context.Background()); err != nil {
		t.Fatalf("PullLots must decode decimal quantity: %v", err)
	}

	var qty float64
	db.QueryRow("SELECT quantity FROM inventory_lots WHERE id = 'L1'").Scan(&qty)
	if qty != 25.5 {
		t.Errorf("quantity want 25.5, got %v", qty)
	}
}
