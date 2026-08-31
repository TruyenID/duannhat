package handler

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// Production scenario reproduction: shop manager adds JPY denomination
// via Shop Settings; LAN-mode pos-web cashier should see it within 5 s.
// This test wires the FULL chain in-process — mock Cloud → workstation
// puller → workstation handler — with the Laravel decimal:2 cast quoted
// string format Cloud actually emits in production.
//
// If THIS test fails, the bug is workstation Go code. If it passes but
// user's production is still broken, the bug is environmental
// (binary version, token cache, network, browser cache).
func TestDenominationE2E_ShopAddToLanHandlerInOneTick(t *testing.T) {
	// Mock Cloud server returning EXACTLY what Laravel emits in
	// production — value as quoted decimal string. Pre-flexFloat, this
	// payload silently failed decode and the puller never INSERTed.
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/till-denominations" {
			http.NotFound(w, r)
			return
		}
		if !strings.HasPrefix(r.Header.Get("Authorization"), "Bearer ") {
			http.Error(w, "no token", http.StatusUnauthorized)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		// Shop just added one JPY 1000 yen note.
		w.Write([]byte(`{"data":[
			{"id":"new-row-1","currency_code":"JPY","value":"1000.00","kind":"note","label":"1000円札","sort_order":5,"is_active":true}
		]}`))
	}))
	defer cloud.Close()

	srv, db := newServerWithAuth(t, cloud.URL)
	srv.hub = NewHub()
	srv.orders = service.NewOrderEngine(db)

	// Wire the puller against the mock Cloud.
	puller := service.NewSyncPuller(db, cloud.URL, srv.GetDeviceToken)

	// Seed a device token (simulates a paired workstation). Without
	// this, cloudGet silently skips and we'd never see the issue —
	// matches a real workstation that has been paired.
	if _, err := db.Exec(
		`INSERT OR REPLACE INTO settings (key, value) VALUES ('device_token', ?)`,
		"WS-TOKEN-1234",
	); err != nil {
		t.Fatalf("seed device_token: %v", err)
	}

	// Pre-state: denominations table empty.
	var pre int
	db.QueryRow("SELECT COUNT(*) FROM denominations").Scan(&pre)
	if pre != 0 {
		t.Fatalf("denominations should start empty, has %d rows", pre)
	}

	// One pull tick — same as the slow-loop firing.
	if err := puller.PullDenominations(t.Context()); err != nil {
		t.Fatalf("PullDenominations errored — production-shape payload MUST work: %v", err)
	}

	// State check: local table has the row from Cloud.
	var n int
	db.QueryRow("SELECT COUNT(*) FROM denominations").Scan(&n)
	if n != 1 {
		t.Fatalf("expected 1 row after pull, got %d (the production bug)", n)
	}

	// Final leg: pos-web calls the LAN handler with ?currency=JPY. The
	// row MUST surface — this is the byte-for-byte symptom user reports.
	req := httptest.NewRequest("GET", "/api/v1/pos/till/denominations?currency=JPY", nil)
	w := httptest.NewRecorder()
	srv.handleLocalPosTillDenominations(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("LAN handler want 200, got %d body=%s", w.Code, w.Body.String())
	}
	body := w.Body.String()
	if !strings.Contains(body, `"currency_code":"JPY"`) {
		t.Errorf("pos-web LAN response missing JPY row: %s", body)
	}
	if !strings.Contains(body, `"value":1000`) {
		t.Errorf("value lost in transit (should be 1000): %s", body)
	}
	if !strings.Contains(body, `"label":"1000円札"`) {
		t.Errorf("label not preserved: %s", body)
	}
}

// Regression: when Cloud returns empty (no denominations seeded yet),
// the empty guard in PullDenominations preserves whatever was already
// local. If local was also empty, that's the correct "nothing to
// show" answer. Verify the guard doesn't accidentally wipe a hot
// table either.
func TestDenominationE2E_EmptyCloudPreservesLocal(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":[]}`))
	}))
	defer cloud.Close()

	srv, db := newServerWithAuth(t, cloud.URL)
	srv.hub = NewHub()
	srv.orders = service.NewOrderEngine(db)

	// Pre-populate with a stale row that a transient Cloud empty
	// response should NOT wipe.
	if _, err := db.Exec(`
		INSERT INTO denominations (id, currency_code, value, kind, sort_order, is_active)
		VALUES ('stale-1','JPY',500,'coin',0,1)`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(
		`INSERT OR REPLACE INTO settings (key, value) VALUES ('device_token', ?)`,
		"WS-TOKEN-1234",
	); err != nil {
		t.Fatal(err)
	}

	puller := service.NewSyncPuller(db, cloud.URL, srv.GetDeviceToken)
	_ = puller.PullDenominations(t.Context())

	var n int
	db.QueryRow("SELECT COUNT(*) FROM denominations").Scan(&n)
	if n != 1 {
		t.Errorf("empty Cloud response should NOT wipe local (guard intent); got %d rows", n)
	}
}
