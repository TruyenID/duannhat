package service

// #1114 wiring 3/3 — order.create tries the evidence-verified replay first,
// falls back to the legacy POST when the order is not final / not signable /
// the evidence is rejected.

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

type replayCloud struct {
	replayHits []map[string]any
	legacyHits int
	rejectWith string // non-empty → respond 422 with this error_code on replay
}

func (c *replayCloud) server(t *testing.T) *httptest.Server {
	t.Helper()
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/orders/replay-offline"):
			var body map[string]any
			_ = json.NewDecoder(r.Body).Decode(&body)
			c.replayHits = append(c.replayHits, body)
			w.Header().Set("Content-Type", "application/json")
			if c.rejectWith != "" {
				w.WriteHeader(http.StatusUnprocessableEntity)
				_, _ = w.Write([]byte(`{"message":"nope","error_code":"` + c.rejectWith + `","reason_code":"signature_invalid"}`))
				return
			}
			w.WriteHeader(http.StatusCreated)
			_, _ = w.Write([]byte(`{"data":{"id":"ord-1","order_id":"ord-1","order_code":"ORD-0042"}}`))
		case strings.HasSuffix(r.URL.Path, "/workstation/orders"):
			c.legacyHits++
			w.Header().Set("Content-Type", "application/json")
			_, _ = w.Write([]byte(`{"data":{"id":"ord-1","order_code":"ORD-0042"}}`))
		default:
			t.Errorf("unexpected cloud path %s", r.URL.Path)
			w.WriteHeader(http.StatusNotFound)
		}
	}))
}

func seedReplayableOrder(t *testing.T, db *store.DB, status string) {
	t.Helper()
	seedSignableOrder(t, db, "ord-1", 7)
	mustExecT(t, db, `UPDATE orders SET status = ? WHERE id = 'ord-1'`, status)
	mustExecT(t, db, `INSERT INTO settings (key, value) VALUES ('device_id', 'dev-1') ON CONFLICT(key) DO UPDATE SET value = excluded.value`)

	ks := NewOfflineKeyStore(db)
	pub, err := ks.EnsureKeypair()
	if err != nil {
		t.Fatalf("ensure keypair: %v", err)
	}
	if err := ks.AdoptRegisteredKey("key-1", time.Now().UTC().Add(180*24*time.Hour).Format(time.RFC3339), pub, ""); err != nil {
		t.Fatalf("adopt key: %v", err)
	}
}

func TestHandleOrderCreate_SignedReplayHappyPath(t *testing.T) {
	cloud := &replayCloud{}
	srv := cloud.server(t)
	t.Cleanup(srv.Close)

	db := newBuilderDB(t)
	seedReplayableOrder(t, db, "closed")

	engine := NewSyncEngine(db, srv.URL, nil)
	resp, retry, err := engine.handleOrderCreate(t.Context(), "ord-1", map[string]any{
		"bearer_token": "ws-token", "idempotency_key": "ik-1",
		"order": map[string]any{"id": "ord-1"},
	})
	if err != nil {
		t.Fatalf("handleOrderCreate: err=%v retry=%v", err, retry)
	}
	if len(cloud.replayHits) != 1 || cloud.legacyHits != 0 {
		t.Fatalf("want 1 replay hit and 0 legacy, got %d/%d", len(cloud.replayHits), cloud.legacyHits)
	}
	if resp["order_code"] != "ORD-0042" {
		t.Errorf("resp must carry the minted code for write-back: %v", resp)
	}

	// The signature Cloud received must verify against the device's public
	// key over the digest of the selection AS SENT — full wire round-trip.
	hit := cloud.replayHits[0]
	selJSON, _ := json.Marshal(hit["selection"])
	var sel OfflineSelection
	if err := json.Unmarshal(selJSON, &sel); err != nil {
		t.Fatalf("selection wire did not round-trip: %v", err)
	}
	evidence := hit["evidence"].(map[string]any)
	env := OfflineEvidenceEnvelope{
		DeviceID:        evidence["device_id"].(string),
		IssuerID:        evidence["issuer_id"].(string),
		CatalogRevision: int(evidence["catalog_revision"].(float64)),
		IssuedAt:        evidence["issued_at"].(string),
		ExpiresAt:       evidence["expires_at"].(string),
		KeyID:           evidence["key_id"].(string),
	}
	pub := NewOfflineKeyStore(db).setting(settingOfflinePublicKey)
	if !VerifyOfflineSignature(pub, evidence["signature"].(string), OfflineSigningMessage(env, OfflineSelectionDigest(sel))) {
		t.Error("the signature sent to Cloud does not verify over the wire selection")
	}
	if env.CatalogRevision != 7 {
		t.Errorf("must claim the CREATE-time revision, got %d", env.CatalogRevision)
	}
}

func TestHandleOrderCreate_EvidenceRejectionFallsBackToLegacy(t *testing.T) {
	cloud := &replayCloud{rejectWith: "OFFLINE_EVIDENCE_REJECTED"}
	srv := cloud.server(t)
	t.Cleanup(srv.Close)

	db := newBuilderDB(t)
	seedReplayableOrder(t, db, "closed")

	engine := NewSyncEngine(db, srv.URL, nil)
	resp, _, err := engine.handleOrderCreate(t.Context(), "ord-1", map[string]any{
		"bearer_token": "ws-token", "order": map[string]any{"id": "ord-1"},
	})
	if err != nil {
		t.Fatalf("must fail open to legacy: %v", err)
	}
	if len(cloud.replayHits) != 1 || cloud.legacyHits != 1 {
		t.Fatalf("want replay attempt THEN legacy fallback, got %d/%d", len(cloud.replayHits), cloud.legacyHits)
	}
	if resp["id"] != "ord-1" {
		t.Errorf("legacy response must flow through: %v", resp)
	}
}

func TestHandleOrderCreate_OpenOrderSkipsReplay(t *testing.T) {
	cloud := &replayCloud{}
	srv := cloud.server(t)
	t.Cleanup(srv.Close)

	db := newBuilderDB(t)
	seedReplayableOrder(t, db, "open")

	engine := NewSyncEngine(db, srv.URL, nil)
	if _, _, err := engine.handleOrderCreate(t.Context(), "ord-1", map[string]any{
		"order": map[string]any{"id": "ord-1"},
	}); err != nil {
		t.Fatalf("legacy path: %v", err)
	}
	if len(cloud.replayHits) != 0 || cloud.legacyHits != 1 {
		t.Errorf("an open order must sync legacy only, got %d/%d", len(cloud.replayHits), cloud.legacyHits)
	}
}

func TestHandleOrderCreate_NoSigningKeySkipsReplay(t *testing.T) {
	cloud := &replayCloud{}
	srv := cloud.server(t)
	t.Cleanup(srv.Close)

	db := newBuilderDB(t)
	seedSignableOrder(t, db, "ord-1", 7)
	mustExecT(t, db, `UPDATE orders SET status = 'closed' WHERE id = 'ord-1'`)
	mustExecT(t, db, `INSERT INTO settings (key, value) VALUES ('device_id', 'dev-1') ON CONFLICT(key) DO UPDATE SET value = excluded.value`)
	// No keypair registered.

	engine := NewSyncEngine(db, srv.URL, nil)
	if _, _, err := engine.handleOrderCreate(t.Context(), "ord-1", map[string]any{
		"order": map[string]any{"id": "ord-1"},
	}); err != nil {
		t.Fatalf("legacy path: %v", err)
	}
	if len(cloud.replayHits) != 0 || cloud.legacyHits != 1 {
		t.Errorf("keyless device must sync legacy only, got %d/%d", len(cloud.replayHits), cloud.legacyHits)
	}
}
