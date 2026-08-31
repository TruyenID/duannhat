package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// mockSSOCloud returns a fake Cloud that responds to GET /api/v1/me/context
// for SSO-format tokens (containing '|'). Used to drive POS endpoint tests.
func mockSSOCloud(t *testing.T, userID, userName, userEmail string) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/me/context" {
			t.Errorf("expected /api/v1/me/context, got %s", r.URL.Path)
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"user":{"id":"` + userID + `","name":"` + userName +
			`","email":"` + userEmail + `","locale":"vi","timezone":"Asia/Tokyo"},` +
			`"brand_count":1,"shop_count":1}`))
	}))
	t.Cleanup(srv.Close)
	return srv
}

func TestPosMeEndpointSSO(t *testing.T) {
	cloud := mockSSOCloud(t, "user-1", "Alice", "alice@x.com")
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("GET", "/api/v1/pos/me", nil)
	req.Header.Set("Authorization", "Bearer 5|sso-token-hash")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", w.Code, w.Body.String())
	}

	var resp struct {
		Data struct {
			IdentityType string `json:"identity_type"`
			BranchID     string `json:"branch_id"`
			User         struct {
				ID    string `json:"id"`
				Name  string `json:"name"`
				Email string `json:"email"`
			} `json:"user"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v body=%s", err, w.Body.String())
	}
	if resp.Data.IdentityType != "user" {
		t.Errorf("expected identity_type=user, got %s", resp.Data.IdentityType)
	}
	if resp.Data.User.ID != "user-1" || resp.Data.User.Name != "Alice" {
		t.Errorf("user info mismatch: %+v", resp.Data.User)
	}
}

func TestPosCorsPreflight(t *testing.T) {
	cloud := mockSSOCloud(t, "user-1", "Alice", "alice@x.com")
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("OPTIONS", "/api/v1/pos/orders", nil)
	req.Header.Set("Origin", "http://localhost:5440")
	req.Header.Set("Access-Control-Request-Method", "POST")
	req.Header.Set("Access-Control-Request-Headers", "Authorization, Content-Type")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusNoContent {
		t.Errorf("expected 204 on preflight, got %d", w.Code)
	}
	if w.Header().Get("Access-Control-Allow-Origin") != "http://localhost:5440" {
		t.Errorf("missing/wrong CORS origin header: %q", w.Header().Get("Access-Control-Allow-Origin"))
	}
	if !strings.Contains(w.Header().Get("Access-Control-Allow-Methods"), "POST") {
		t.Errorf("CORS Allow-Methods missing POST: %q", w.Header().Get("Access-Control-Allow-Methods"))
	}
	if !strings.Contains(w.Header().Get("Access-Control-Allow-Headers"), "Idempotency-Key") {
		t.Errorf("CORS Allow-Headers missing Idempotency-Key: %q", w.Header().Get("Access-Control-Allow-Headers"))
	}
}

func TestPosCorsRejectsUnknownOrigin(t *testing.T) {
	cloud := mockSSOCloud(t, "user-1", "Alice", "alice@x.com")
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("OPTIONS", "/api/v1/pos/orders", nil)
	req.Header.Set("Origin", "https://evil.com")
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	// Preflight still returns 204, but no Allow-Origin header → browser blocks.
	if got := w.Header().Get("Access-Control-Allow-Origin"); got != "" {
		t.Errorf("evil.com should NOT receive Allow-Origin, got %q", got)
	}
}

// TestPosMenusEmpty and TestPosCreatePaymentRequiresIdempotencyKey were
// removed when /pos/menus and /pos/orders/{id}/payments stopped being
// served from local SQLite and started proxying to Cloud (the local
// shape did not match Cloud's MenuResource / OrderPaymentResource which
// pos-web relies on for nested relations + decimal-string money fields).
// Behavior is now covered end-to-end by Cloud's Pest test suite.

func TestPosRequiresAuthToken(t *testing.T) {
	cloud := mockSSOCloud(t, "user-1", "Alice", "alice@x.com")
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus", nil)
	// No Authorization header
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusUnauthorized {
		t.Errorf("expected 401 without token, got %d", w.Code)
	}
}
