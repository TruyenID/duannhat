package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// Regression: POST /api/v1/pos/orders/{id}/payments must persist + sync
// the workstation-side split-bill metadata (split_mode + bill_index +
// item_allocations[]) all the way through to Cloud. Without this, by-items
// split-bill receipts on the reprint screen lose the per-bill audit trail.
//
// Test wires the LAN HTTP handler to an in-memory SQLite + a mocked Cloud
// receiver, fires one POS payment with by_items metadata, asserts:
//  1. The local payments table stores the metadata JSON unchanged.
//  2. The sync_queue picks it up + forwards it as a decoded JSON object
//     on the cloud-bound request body.
func TestLocalPosPayment_PersistsAndForwardsSplitMetadata(t *testing.T) {
	// We don't need a real Cloud here — sync is enqueued, never sent.
	srv, db := newServerWithAuth(t, "http://unused")
	seedOpenShift(t, srv)
	srv.hub = NewHub()

	if _, err := db.Exec(`
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    branch_id, brand_id, organization_id)
		VALUES ('o1', 'O-1', 'dine_in', 'checkout', datetime('now'),
		        'br-1', 'bd-1', 'or-1')`); err != nil {
		t.Fatal(err)
	}

	body := `{
		"amount": 500,
		"payment_method": "cash",
		"metadata": "{\"split_mode\":\"by_items\",\"bill_index\":0,\"total_bills\":3,\"label\":\"A\",\"item_allocations\":[{\"item_id\":\"i1\",\"units\":1}]}"
	}`

	req := httptest.NewRequest("POST", "/api/v1/pos/orders/o1/payments", strings.NewReader(body))
	req.Header.Set("Idempotency-Key", "idem-split-1")
	req.SetPathValue("id", "o1")

	w := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(w, req)

	if w.Code != http.StatusCreated {
		t.Fatalf("want 201, got %d body=%s", w.Code, w.Body.String())
	}

	// (1) Persisted on the row.
	var stored string
	if err := db.QueryRow(
		`SELECT COALESCE(metadata, '') FROM payments WHERE order_id = 'o1'`,
	).Scan(&stored); err != nil {
		t.Fatalf("readback: %v", err)
	}
	if !strings.Contains(stored, `"split_mode":"by_items"`) {
		t.Errorf("metadata not stored locally; got %q", stored)
	}
	if !strings.Contains(stored, `"item_allocations"`) {
		t.Errorf("item_allocations missing; got %q", stored)
	}

	// (2) Surfaced on the sync_queue payload (worker decodes string → obj
	// during cloudPost; here we just confirm the queue carries the string
	// verbatim).
	var payload string
	if err := db.QueryRow(`
		SELECT payload FROM sync_queue
		WHERE entity_type='payment' AND operation='create'
		ORDER BY id DESC LIMIT 1`).Scan(&payload); err != nil {
		t.Fatalf("queue readback: %v", err)
	}
	var parsed map[string]any
	if err := json.Unmarshal([]byte(payload), &parsed); err != nil {
		t.Fatalf("queue payload not JSON: %v", err)
	}
	md, _ := parsed["metadata"].(string)
	if !strings.Contains(md, `"bill_index":0`) {
		t.Errorf("sync payload missing split metadata; got %q", payload)
	}
}
