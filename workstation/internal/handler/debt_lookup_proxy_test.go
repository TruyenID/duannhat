package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// "Tra cứu nợ" over LAN.
//
// The workstation deliberately does NOT mirror debts. A debt is an on-account
// payment on ANY order of the shop, including orders this workstation never
// handled, so a local table could only ever answer for the subset it happens to
// know — an under-count presented as a total, which is the worst possible shape
// for a figure a cashier reads out before taking money. Both debt routes
// therefore fall through to the Cloud proxy, and go offline honestly rather
// than answering with a fraction.
//
// What must hold is that they REACH the proxy: `/pos/debts/{customer}` is a new
// path, and a route that silently fails to match would have the SPA catch-all
// answer instead — pos-web would parse HTML as JSON far from the cause (#1746).

func TestDebtRoutes_ReachTheCloudProxy(t *testing.T) {
	cloud := mockSSOCloudWithProxy(t)
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	for _, path := range []string{
		"/api/v1/pos/debts?limit=100",
		"/api/v1/pos/debts/019fd645-5581-7387-8c6c-32c348b0a633",
	} {
		t.Run(path, func(t *testing.T) {
			req := httptest.NewRequest("GET", path, nil)
			req.Header.Set("Authorization", "Bearer 5|sso-token-hash")
			req.Header.Set("X-Shop-Slug", "main-shop")
			w := httptest.NewRecorder()
			mux.ServeHTTP(w, req)

			if w.Code != http.StatusOK {
				t.Fatalf("want 200 from the proxy, got %d: %s", w.Code, w.Body.String())
			}

			// The echo server returns the path it was asked for. Anything else
			// means the request was answered locally or rewritten.
			var echoed struct {
				Path      string `json:"path"`
				XShopSlug string `json:"x_shop_slug"`
			}
			if err := json.Unmarshal(w.Body.Bytes(), &echoed); err != nil {
				t.Fatalf("proxy did not forward to Cloud — body was not the echo: %s", w.Body.String())
			}
			if echoed.Path == "" {
				t.Fatalf("empty forwarded path for %q", path)
			}
			// The shop must ride along: DebtController scopes every row to the
			// resolved shop's branch, and without the header Cloud cannot
			// resolve one.
			if echoed.XShopSlug != "main-shop" {
				t.Errorf("X-Shop-Slug not forwarded (got %q) — Cloud cannot scope the debts without it", echoed.XShopSlug)
			}
		})
	}
}
