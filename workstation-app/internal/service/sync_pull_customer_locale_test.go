package service

import (
	"context"
	"database/sql"
	"net/http"
	"net/http/httptest"
	"testing"
)

// The pull mirrors Cloud's customer_locale (the language the guest ordered in
// on customer-web) so the workstation's auto-print can render the dine-in
// kitchen + hold slips in that language. A later pull from an older Cloud —
// which omits the field — must NOT wipe it.
func TestPullOrder_MirrorsCustomerLocale(t *testing.T) {
	locale := `"customer_locale":"ja",`
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"data":[
			{"id":"ord-loc","order_code":"O-LOC","order_type":"dine_in","status":"open",
			 "opened_at":"2026-06-19T10:00:00Z","updated_at":"2026-06-19T10:00:00Z",
			 ` + locale + `
			 "branch_id":"br-1","brand_id":"bd-1","organization_id":"org-1","items":[]}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	if err := p.PullOrderNow(context.Background(), "ord-loc"); err != nil {
		t.Fatalf("PullOrderNow: %v", err)
	}

	read := func() sql.NullString {
		t.Helper()
		var got sql.NullString
		if err := db.QueryRow("SELECT customer_locale FROM orders WHERE id = 'ord-loc'").Scan(&got); err != nil {
			t.Fatalf("read customer_locale: %v", err)
		}
		return got
	}
	if got := read(); got.String != "ja" {
		t.Fatalf("expected customer_locale=ja, got %q", got.String)
	}

	// Re-pull with the field absent (old Cloud) — the stored locale survives.
	locale = ""
	if err := p.PullOrderNow(context.Background(), "ord-loc"); err != nil {
		t.Fatalf("PullOrderNow (re-pull): %v", err)
	}
	if got := read(); got.String != "ja" {
		t.Fatalf("re-pull without the field wiped the locale: %q", got.String)
	}
}
