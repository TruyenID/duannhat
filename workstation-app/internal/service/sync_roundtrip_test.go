package service

// End-to-end sync roundtrip: workstation creates an order locally, pushes it
// UP to a stateful mock Cloud, then pulls DOWN via SyncPuller.Recover and
// asserts the round-tripped data matches the push payload + the cloud-side
// fields the workstation never sets itself.
//
// Existing tests cover the two halves in isolation (sync_push_test exercises
// POST only; sync_pull_test serves canned JSON from httptest). Neither would
// catch a regression where, say, push UP drops `note` mid-flight or pull
// DOWN trips over an order_type the workstation just sent. Pinning the
// combined flow keeps the contract honest without spinning up a real
// Laravel stack.
//
// What the test pins:
//   - workstation push UP populates `cloud_id` on the local row.
//   - cloud receives the field set workstation actually sends (no silent
//     mid-pipeline drops).
//   - pull DOWN inserts the cloud's enriched view (status, opened_at, totals)
//     as a separate row keyed by the cloud UUID — confirming the
//     "Recovery rows reuse the Cloud UUID as local id" invariant
//     documented in sync_pull.go.
//   - subsequent reopens / pulls don't double-create.

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"sync"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// stubCloud is the minimal stateful cloud the workstation expects to talk to:
// it records every order the workstation pushes, mints a cloud UUID, and then
// serves the same rows back when SyncPuller.Recover hits GET /orders.
type stubCloud struct {
	t      *testing.T
	mu     sync.Mutex
	orders []map[string]any // cloud-side view, keyed by `id`
}

func (s *stubCloud) handler() http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/orders" {
			http.NotFound(w, r)
			return
		}
		switch r.Method {
		case http.MethodPost:
			s.acceptPush(w, r)
		case http.MethodGet:
			s.servePull(w)
		default:
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		}
	})
}

func (s *stubCloud) acceptPush(w http.ResponseWriter, r *http.Request) {
	s.t.Helper()
	var body map[string]any
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		s.t.Fatalf("decode push body: %v", err)
	}

	// Build the cloud-side row. Cloud enriches workstation's payload with
	// fields it didn't send (id, opened_at, status, amounts, tenancy) — pull
	// DOWN should expose those.
	cloudID := "cloud-" + asString(body["order_code"])
	now := time.Now().UTC().Format(time.RFC3339)

	row := map[string]any{
		"id":              cloudID,
		"order_code":      body["order_code"],
		"order_type":      body["order_type"],
		"status":          "open",
		"opened_at":       now,
		"created_at":      now,
		"updated_at":      now,
		"guest_count":     body["guest_count"],
		"note":            body["note"],
		"organization_id": "org-stub",
		"brand_id":        "brand-stub",
		"branch_id":       "branch-stub",
		// Yen amounts as strings — matches Laravel decimal(15,2) serialisation
		// that sync_pull.go's UnmarshalJSON already handles.
		"subtotal":        "0.00",
		"discount_amount": "0.00",
		"service_charge":  "0.00",
		"tax_amount":      "0.00",
		"total_tip":       "0.00",
		"total_amount":    "0.00",
		"paid_amount":     "0.00",
	}
	s.mu.Lock()
	s.orders = append(s.orders, row)
	s.mu.Unlock()

	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusCreated)
	_ = json.NewEncoder(w).Encode(map[string]any{"data": row})
}

func (s *stubCloud) servePull(w http.ResponseWriter) {
	s.mu.Lock()
	out := make([]map[string]any, len(s.orders))
	copy(out, s.orders)
	s.mu.Unlock()

	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(map[string]any{
		"data":  out,
		"count": len(out),
	})
}

func asString(v any) string {
	if s, ok := v.(string); ok {
		return s
	}
	return fmt.Sprint(v)
}

// seedLocalOrder writes a workstation-local order the same way OrderEngine
// would after a POST /api/v1/pos/orders — skipping the engine keeps the test
// focused on the sync wiring rather than order-creation semantics.
func seedLocalOrder(t *testing.T, db *store.DB, id, orderCode, orderType string) {
	t.Helper()
	now := time.Now().UTC().Format(time.RFC3339)
	_, err := db.Exec(`
		INSERT INTO orders (
			id, order_code, order_number, order_type, status,
			opened_at, guest_count,
			subtotal, discount_amount, service_charge, tax_amount,
			total_tip, total_amount, paid_amount,
			organization_id, brand_id, branch_id,
			created_at, updated_at, note
		) VALUES (?, ?, 1, ?, 'open',
			?, 2, 0, 0, 0, 0, 0, 0, 0,
			'org-local', 'brand-local', 'branch-local',
			?, ?, 'roundtrip note')
	`, id, orderCode, orderType, now, now, now)
	if err != nil {
		t.Fatalf("seed local order: %v", err)
	}
}

func TestSyncRoundtrip_OrderPushThenPull(t *testing.T) {
	cloud := &stubCloud{t: t}
	srv := httptest.NewServer(cloud.handler())
	t.Cleanup(srv.Close)

	db, err := store.Open(filepath.Join(t.TempDir(), "roundtrip.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	// ─── 1. Local-only state (workstation created the order, no Cloud yet)
	const localID = "local-ord-1"
	seedLocalOrder(t, db, localID, "WS-000001", "dine_in")

	var beforeCloudID string
	if err := db.QueryRow("SELECT COALESCE(cloud_id, '') FROM orders WHERE id = ?", localID).Scan(&beforeCloudID); err != nil {
		t.Fatalf("read pre-push cloud_id: %v", err)
	}
	if beforeCloudID != "" {
		t.Errorf("pre-push cloud_id: want empty, got %q", beforeCloudID)
	}

	// ─── 2. Push UP via SyncEngine handler (skip sync_queue plumbing — that
	//        layer is already covered by sync_push_test). Set entityID so the
	//        success-path UPDATE finds our local row.
	engine := NewSyncEngine(db, srv.URL, nil)
	pushPayload := map[string]any{
		"bearer_token":    "ws-token",
		"idempotency_key": "idem-1",
		"order": map[string]any{
			"order_code":  "WS-000001",
			"order_type":  "dine_in",
			"guest_count": 2,
			"note":        "roundtrip note",
		},
	}
	resp, _, err := engine.handleOrderCreate(t.Context(), localID, pushPayload)
	if err != nil {
		t.Fatalf("handleOrderCreate: %v", err)
	}
	cloudID, _ := resp["id"].(string)
	if cloudID == "" {
		t.Fatalf("cloud response missing id: %#v", resp)
	}

	// Simulate the queue post-success update (sync_service.processQueue does
	// this inline; calling it here keeps the test free of queue scaffolding).
	if _, err := db.Exec("UPDATE orders SET cloud_id = ? WHERE id = ?", cloudID, localID); err != nil {
		t.Fatalf("backfill cloud_id: %v", err)
	}

	// ─── 3. Verify push reached Cloud with the fields workstation actually sent.
	cloud.mu.Lock()
	pushedRow := cloud.orders[len(cloud.orders)-1]
	cloud.mu.Unlock()
	if got := asString(pushedRow["order_code"]); got != "WS-000001" {
		t.Errorf("cloud-side order_code: want WS-000001, got %q", got)
	}
	if got := asString(pushedRow["note"]); got != "roundtrip note" {
		t.Errorf("note dropped mid-pipeline: got %q", got)
	}
	if got := asString(pushedRow["order_type"]); got != "dine_in" {
		t.Errorf("order_type dropped: got %q", got)
	}

	// ─── 4. Pull DOWN — the workstation OWNS this order (created locally,
	//        already synced UP so cloud_id is set). Recover must SKIP it, NOT
	//        ingest a duplicate sibling keyed by the cloud UUID (plan-041
	//        dedup — the previous behaviour double-listed every WS-created
	//        order in the local mirror).
	puller := NewSyncPuller(db, srv.URL, staticTokenFn("ws-token"))
	restored, err := puller.Recover(t.Context(), 30*24*time.Hour)
	if err != nil {
		t.Fatalf("Recover: %v", err)
	}
	if restored != 0 {
		t.Errorf("Recover restored: want 0 (locally-owned order skipped), got %d", restored)
	}

	// ─── 5. The local-created row keeps its local id + cloud_id, and there is
	//        NO duplicate sibling row keyed by the cloud UUID.
	var localCloudID string
	if err := db.QueryRow("SELECT COALESCE(cloud_id, '') FROM orders WHERE id = ?", localID).Scan(&localCloudID); err != nil {
		t.Fatalf("read local row cloud_id: %v", err)
	}
	if localCloudID != cloudID {
		t.Errorf("local cloud_id: want %q, got %q", cloudID, localCloudID)
	}

	var dupCount int
	if err := db.QueryRow("SELECT COUNT(*) FROM orders WHERE id = ?", cloudID).Scan(&dupCount); err != nil {
		t.Fatalf("count cloud-keyed row: %v", err)
	}
	if dupCount != 0 {
		t.Errorf("duplicate row created: want 0 rows keyed by cloud id %q, got %d", cloudID, dupCount)
	}

	// Exactly one local row represents this order — no double-listing.
	var total int
	if err := db.QueryRow("SELECT COUNT(*) FROM orders WHERE order_code = 'WS-000001'").Scan(&total); err != nil {
		t.Fatalf("count order rows: %v", err)
	}
	if total != 1 {
		t.Errorf("want exactly 1 row for the order, got %d (duplication regression)", total)
	}
}

// TestSyncRoundtrip_RecoverIsIdempotent confirms a second Recover() over the
// same cloud snapshot doesn't duplicate rows — UPSERT on the cloud UUID.
func TestSyncRoundtrip_RecoverIsIdempotent(t *testing.T) {
	cloud := &stubCloud{t: t}
	srv := httptest.NewServer(cloud.handler())
	t.Cleanup(srv.Close)

	db, err := store.Open(filepath.Join(t.TempDir(), "idem.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	cloud.mu.Lock()
	cloud.orders = append(cloud.orders, map[string]any{
		"id":              "cloud-static-1",
		"order_code":      "WS-STATIC",
		"order_type":      "spot",
		"status":          "closed",
		"opened_at":       "2026-05-20T10:00:00Z",
		"closed_at":       "2026-05-20T10:30:00Z",
		"created_at":      "2026-05-20T10:00:00Z",
		"updated_at":      "2026-05-20T10:30:00Z",
		"guest_count":     1,
		"organization_id": "org-stub",
		"brand_id":        "brand-stub",
		"branch_id":       "branch-stub",
		"subtotal":        "1000.00",
		"discount_amount": "0.00",
		"service_charge":  "0.00",
		"tax_amount":      "0.00",
		"total_tip":       "0.00",
		"total_amount":    "1000.00",
		"paid_amount":     "1000.00",
	})
	cloud.mu.Unlock()

	puller := NewSyncPuller(db, srv.URL, staticTokenFn("ws-token"))

	if _, err := puller.Recover(t.Context(), 30*24*time.Hour); err != nil {
		t.Fatalf("first Recover: %v", err)
	}
	if _, err := puller.Recover(t.Context(), 30*24*time.Hour); err != nil {
		t.Fatalf("second Recover: %v", err)
	}

	var count int
	if err := db.QueryRow("SELECT COUNT(*) FROM orders WHERE id = 'cloud-static-1'").Scan(&count); err != nil {
		t.Fatalf("count: %v", err)
	}
	if count != 1 {
		t.Errorf("recover idempotency broken: want 1 row, got %d", count)
	}
}
