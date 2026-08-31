package handler

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// The gate whose absence let #1311 live: does a device that has PAIRED end up
// able to sign?
//
// Everything around this was tested — the golden fixture matches Cloud byte for
// byte, the keystore has its own suite, Cloud's verifier has twenty reason
// codes — and all of it stayed green while no device ever held a key, because
// each test covered a part that WAS built. Nothing asked the end-to-end
// question.
//
// mockPairCloudWithSigningKey records what the workstation offered, so the test
// asserts the actual protocol (Cloud issues a key only when the request carries
// `public_key`) rather than trusting that the field was sent.
func mockPairCloudWithSigningKey(t *testing.T, offered *string, issueKey bool) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/devices/pair" {
			w.WriteHeader(http.StatusNotFound)
			return
		}

		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		if pk, ok := body["public_key"].(string); ok {
			*offered = pk
		}

		resp := map[string]any{
			"device_token": "ws-token",
			"device": map[string]any{
				"id":        "dev-x",
				"name":      "WS-1",
				"type":      "workstation",
				"status":    "active",
				"branch_id": "branch-A",
			},
		}
		// Cloud issues a key ONLY when one was offered — mirroring
		// Api/V1/Device/PairingController.
		if issueKey && *offered != "" {
			resp["signing_key"] = map[string]any{
				"key_id":     "key-1",
				"expires_at": time.Now().Add(180 * 24 * time.Hour).Format(time.RFC3339),
			}
		}

		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		_ = json.NewEncoder(w).Encode(resp)
	}))
	t.Cleanup(srv.Close)
	return srv
}

func TestPair_LeavesTheDeviceAbleToSignOfflineOrders(t *testing.T) {
	var offered string
	cloud := mockPairCloudWithSigningKey(t, &offered, true)
	srv, db := newServerWithAuth(t, cloud.URL)

	if w := doPair(t, srv, "ABC123"); w.Code != http.StatusOK {
		t.Fatalf("pair failed: %d body=%s", w.Code, w.Body.String())
	}

	// Half one: the device actually offered a public key. Without this Cloud
	// answers `signing_key: null` and everything below is unreachable — which
	// is exactly the state #1311 found.
	if offered == "" {
		t.Fatal("pair request carried no public_key, so Cloud can never issue a signing key")
	}

	// Half two: the returned key was adopted and is usable RIGHT NOW. Material()
	// is what the offline sale path consults; if it errors, every offline order
	// silently takes the unverified legacy path.
	material, err := service.NewOfflineKeyStore(db).Material()
	if err != nil {
		t.Fatalf("a paired device cannot sign: %v", err)
	}
	if material.KeyID != "key-1" {
		t.Fatalf("adopted key id = %q, want key-1", material.KeyID)
	}
	if len(material.PrivateKey) == 0 {
		t.Fatal("no private key stored")
	}
	if !material.ExpiresAt.After(time.Now()) {
		t.Fatalf("adopted key already expired at %v", material.ExpiresAt)
	}
}

// And the fail-open half: an older Cloud that issues no key must still pair.
// Pairing is how a shop starts trading; it cannot depend on a signature
// feature. The device simply keeps taking the legacy path, as it did before.
func TestPair_SucceedsWhenCloudIssuesNoSigningKey(t *testing.T) {
	var offered string
	cloud := mockPairCloudWithSigningKey(t, &offered, false)
	srv, db := newServerWithAuth(t, cloud.URL)

	if w := doPair(t, srv, "ABC123"); w.Code != http.StatusOK {
		t.Fatalf("pair must succeed without a signing key, got %d body=%s", w.Code, w.Body.String())
	}

	_, err := service.NewOfflineKeyStore(db).Material()
	if !errors.Is(err, service.ErrNoSigningKey) {
		t.Fatalf("want ErrNoSigningKey when Cloud issued none, got %v", err)
	}
}
