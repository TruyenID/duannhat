package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// Same assertion as local_pos_payment_locale_test.go, but driven through the
// REAL route table and its middleware ring (cors → auth → type gate → shop
// guard) instead of calling the handler function directly.
//
// The direct-call tests cannot see a whole class of failure: any middleware
// that rebuilt the request instead of wrapping it would drop Accept-Language,
// and the handler would silently fall back to its "ja" default — which looks
// exactly like "the language switch does nothing".
func TestRoute_TenderTypesLocaleSurvivesMiddleware(t *testing.T) {
	cloud := mockSSOCloudWithProxy(t)
	s, db := newServerWithAuth(t, cloud.URL)

	mustExec(t, db, `
		INSERT INTO till_tender_types
			(id, tender_key, name, name_ja, name_en, name_vi, category, currency_code, sort_order)
		VALUES ('tt-1','credit','Credit','クレジット','Credit','Tín dụng','card','JPY',0)`)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	for _, c := range []struct{ lang, want string }{
		{"ja", "クレジット"},
		{"en", "Credit"},
		{"vi", "Tín dụng"},
	} {
		t.Run(c.lang, func(t *testing.T) {
			req := httptest.NewRequest("GET", "/api/v1/pos/till/tender-types", nil)
			req.Header.Set("Authorization", "Bearer 5|sso-token-hash")
			req.Header.Set("X-Shop-Slug", "main-shop")
			req.Header.Set("Accept-Language", c.lang)
			w := httptest.NewRecorder()
			mux.ServeHTTP(w, req)

			if w.Code != http.StatusOK {
				t.Fatalf("[%s] want 200, got %d: %s", c.lang, w.Code, w.Body.String())
			}
			var resp struct {
				Data []map[string]any `json:"data"`
			}
			if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
				t.Fatalf("decode: %v", err)
			}
			if got := nameOfTender(resp.Data, "credit"); got != c.want {
				t.Errorf("[%s] want %q, got %q", c.lang, c.want, got)
			}
		})
	}
}
