package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// #544 — the workstation is a single-shop device, but pos-web addresses it at
// /shop/<slug> and sends that slug in X-Shop-Slug. A slug that isn't this
// workstation's paired branch (a typo, a stale bookmark, a genuinely
// non-existent shop, or a real-but-different branch) must 404 "Shop not found."
// — the same shape Cloud's ResolvePosShop returns — instead of being silently
// served / rewritten to the paired branch. Otherwise pos-web's ShiftGate reads
// the 200 as "no open session" and drops the operator into the open-shift flow
// for a shop that isn't there.
//
// The handler test DB has no omnify-managed `branches` table, so seed a minimal
// one here; workstationBranchSlug() only reads (id, slug).
func seedPairedBranch(t *testing.T, db *store.DB, slug string) {
	t.Helper()
	if _, err := db.Exec(`CREATE TABLE IF NOT EXISTS branches (id TEXT PRIMARY KEY, slug TEXT NOT NULL)`); err != nil {
		t.Fatalf("create branches: %v", err)
	}
	// newServerWithAuth seeds workstation_branch_id = 'branch-A'.
	if _, err := db.Exec(`INSERT OR REPLACE INTO branches (id, slug) VALUES ('branch-A', ?)`, slug); err != nil {
		t.Fatalf("seed branch: %v", err)
	}
}

func TestPosShopGuard_RejectsMismatchedSlug_Proxied(t *testing.T) {
	cloud := mockSSOCloudWithProxy(t)
	s, db := newServerWithAuth(t, cloud.URL)
	seedPairedBranch(t, db, "hanoi")

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	// Debt detail is explicitly Cloud-owned. A ghost slug must be
	// rejected up front by the guard, never reaching the proxy/Cloud.
	req := httptest.NewRequest("GET", "/api/v1/pos/debts/customer-1", nil)
	req.Header.Set("Authorization", "Bearer 5|sso-token-hash")
	req.Header.Set("X-Shop-Slug", "khong-ton-tai-123")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusNotFound {
		t.Fatalf("ghost slug: want 404, got %d body=%s", w.Code, w.Body.String())
	}
	var resp map[string]string
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v body=%s", err, w.Body.String())
	}
	if resp["message"] != "Shop not found." {
		t.Errorf("want Cloud-shaped message, got %q", resp["message"])
	}
}

func TestPosShopGuard_RejectsMismatchedSlug_LocalHandler(t *testing.T) {
	cloud := mockSSOCloudWithProxy(t)
	s, db := newServerWithAuth(t, cloud.URL)
	seedPairedBranch(t, db, "hanoi")

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	// GET /pos/me is a LOCAL handler (posAuth wrap) — guard must apply there too.
	req := httptest.NewRequest("GET", "/api/v1/pos/me", nil)
	req.Header.Set("Authorization", "Bearer 5|sso-token-hash")
	req.Header.Set("X-Shop-Slug", "tokyo") // a real-but-different branch
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusNotFound {
		t.Fatalf("cross-branch slug on local handler: want 404, got %d body=%s", w.Code, w.Body.String())
	}
}

func TestPosShopGuard_AllowsMatchingSlug(t *testing.T) {
	cloud := mockSSOCloudWithProxy(t)
	s, db := newServerWithAuth(t, cloud.URL)
	seedPairedBranch(t, db, "hanoi")

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("GET", "/api/v1/pos/debts/customer-1", nil)
	req.Header.Set("Authorization", "Bearer 5|sso-token-hash")
	req.Header.Set("X-Shop-Slug", "hanoi") // the paired branch
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("matching slug: want 200 (proxied), got %d body=%s", w.Code, w.Body.String())
	}
	var resp map[string]string
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v body=%s", err, w.Body.String())
	}
	if resp["x_shop_slug"] != "hanoi" {
		t.Errorf("slug not forwarded to Cloud: %q", resp["x_shop_slug"])
	}
}

func TestPosShopGuard_FailsOpenWhenUnresolved(t *testing.T) {
	// No branches row seeded → workstationBranchSlug() is empty → guard must
	// fall open (preserve prior behavior; never hard-block an unpaired/booting
	// workstation). Any non-empty slug is allowed through.
	cloud := mockSSOCloudWithProxy(t)
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("GET", "/api/v1/pos/debts/customer-1", nil)
	req.Header.Set("Authorization", "Bearer 5|sso-token-hash")
	req.Header.Set("X-Shop-Slug", "anything")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code == http.StatusNotFound {
		t.Fatalf("unresolved paired slug must fail open, got 404 body=%s", w.Body.String())
	}
}
