package service

import (
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

// TestSyncEngine_WakeIsNonBlocking asserts Wake() never blocks the caller
// (the HTTP request thread) even under a burst, and coalesces to a single
// pending signal — the buffered-size-1 + non-blocking-send contract.
func TestSyncEngine_WakeIsNonBlocking(t *testing.T) {
	e, _ := newSyncTestEngine(t, "")

	done := make(chan struct{})
	go func() {
		for range 1000 {
			e.Wake() // nothing drains wakeCh — must still never block
		}
		close(done)
	}()

	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("Wake() blocked — buffered/non-blocking contract broken")
	}

	// Exactly one signal buffered (the burst coalesced).
	select {
	case <-e.wakeCh:
	default:
		t.Fatal("expected exactly one buffered wake signal after the burst")
	}
	select {
	case <-e.wakeCh:
		t.Fatal("expected the burst to coalesce to a single signal, found a second")
	default:
	}
}

// TestSyncEngine_WakeTriggersImmediateDrain asserts that Wake() makes the
// worker push a freshly-enqueued order to Cloud right away — well under the
// 5s tick — which is the whole point of immediate LAN-mode sync-up.
func TestSyncEngine_WakeTriggersImmediateDrain(t *testing.T) {
	got := make(chan struct{}, 1)
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"data":{"id":"cloud-1","order_code":"ORD-1","status":"open"}}`))
		select {
		case got <- struct{}{}:
		default:
		}
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)

	if _, err := db.Exec(`INSERT INTO orders (
		id, order_code, order_number, order_type, status,
		opened_at, guest_count,
		subtotal, discount_amount, service_charge, tax_amount, total_tip, total_amount, paid_amount,
		organization_id, brand_id, branch_id,
		created_at, updated_at
	) VALUES ('ord-1', 'WS-0001', 1, 'spot', 'open',
		datetime('now'), 1,
		0, 0, 0, 0, 0, 0, 0, '', '', '',
		datetime('now'), datetime('now'))`); err != nil {
		t.Fatalf("seed order: %v", err)
	}

	// Force online so the worker actually drains (skip the live monitor probe).
	e.monitor.mu.Lock()
	e.monitor.status = ConnOnline
	e.monitor.mu.Unlock()

	go e.processLoop()
	defer close(e.stopCh) // unblock + return the worker goroutine

	payload := map[string]any{
		"bearer_token":    "ws-token",
		"idempotency_key": "idem-1",
		"order":           map[string]any{"order_type": "spot"},
	}
	if err := e.Enqueue("order", "ord-1", "create", payload, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}
	e.Wake()

	select {
	case <-got:
		// Pushed via the wake, comfortably before the 5s tick would fire.
	case <-time.After(2 * time.Second):
		t.Fatal("Wake() did not trigger an immediate Cloud push within 2s")
	}
}
