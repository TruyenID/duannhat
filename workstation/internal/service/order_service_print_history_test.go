package service

import (
	"encoding/json"
	"errors"
	"path/filepath"
	"sync"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

func newPrintHistoryEngine(t *testing.T) (*OrderEngine, *store.DB) {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), "print_history.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return NewOrderEngine(db), db
}

// seedPayment creates a minimal payments row (just id + metadata + status)
// — bypasses OrderEngine because the existing public Create flow has many
// dependencies we don't need to exercise for the JSON-append test.
func seedPayment(t *testing.T, db *store.DB, paymentID, metadata string) {
	t.Helper()
	_, err := db.Exec(
		`INSERT INTO payments (id, order_id, payment_method, amount, status, metadata, created_at, updated_at)
		 VALUES (?, 'ord-1', 'cash', '0', 'succeeded', ?, '2026-06-20T00:00:00Z', '2026-06-20T00:00:00Z')`,
		paymentID, metadata,
	)
	if err != nil {
		t.Fatalf("seed payment: %v", err)
	}
}

func TestAppendPrintHistory_HappyPath(t *testing.T) {
	eng, db := newPrintHistoryEngine(t)
	seedPayment(t, db, "p1", `{"split_mode":"by_amount","bill_index":1}`)

	got1, err := eng.AppendPrintHistory("p1", "auto")
	if err != nil {
		t.Fatalf("first append: %v", err)
	}
	if got1.ReprintNo != 1 {
		t.Errorf("first reprint_no: want 1, got %d", got1.ReprintNo)
	}
	if got1.Reason != "auto" || got1.At == "" {
		t.Errorf("first entry shape: %+v", got1)
	}

	got2, err := eng.AppendPrintHistory("p1", "manual reprint")
	if err != nil {
		t.Fatalf("second append: %v", err)
	}
	if got2.ReprintNo != 2 {
		t.Errorf("second reprint_no: want 2, got %d", got2.ReprintNo)
	}

	// Round-trip the metadata: other fields preserved, history has 2 entries.
	var stored string
	if err := db.QueryRow(`SELECT metadata FROM payments WHERE id='p1'`).Scan(&stored); err != nil {
		t.Fatal(err)
	}
	var parsed map[string]any
	if err := json.Unmarshal([]byte(stored), &parsed); err != nil {
		t.Fatalf("metadata not valid JSON: %v", err)
	}
	if parsed["split_mode"] != "by_amount" {
		t.Errorf("split_mode lost: %v", parsed["split_mode"])
	}
	if n, _ := parsed["bill_index"].(float64); n != 1 {
		t.Errorf("bill_index lost: %v", parsed["bill_index"])
	}
	hist, _ := parsed["print_history"].([]any)
	if len(hist) != 2 {
		t.Fatalf("history len: want 2, got %d", len(hist))
	}
}

func TestAppendPrintHistory_MalformedMetadataReinits(t *testing.T) {
	eng, db := newPrintHistoryEngine(t)
	seedPayment(t, db, "p2", `not valid json at all {{`)

	got, err := eng.AppendPrintHistory("p2", "auto")
	if err != nil {
		t.Fatalf("append: %v", err)
	}
	if got.ReprintNo != 1 {
		t.Errorf("malformed → fresh start: want reprint_no=1, got %d", got.ReprintNo)
	}

	var stored string
	_ = db.QueryRow(`SELECT metadata FROM payments WHERE id='p2'`).Scan(&stored)
	var parsed map[string]any
	if err := json.Unmarshal([]byte(stored), &parsed); err != nil {
		t.Errorf("post-fix metadata not valid JSON: %v (raw=%s)", err, stored)
	}
	if _, ok := parsed["print_history"].([]any); !ok {
		t.Errorf("print_history not an array after recovery: %v", parsed["print_history"])
	}
}

func TestAppendPrintHistory_PaymentNotFound(t *testing.T) {
	eng, _ := newPrintHistoryEngine(t)
	_, err := eng.AppendPrintHistory("nope", "auto")
	if !errors.Is(err, ErrPaymentNotFound) {
		t.Errorf("want ErrPaymentNotFound, got %v", err)
	}
}

func TestAppendPrintHistory_EmptyPaymentIDRejected(t *testing.T) {
	eng, _ := newPrintHistoryEngine(t)
	if _, err := eng.AppendPrintHistory("", "auto"); err == nil {
		t.Error("want error for empty payment_id, got nil")
	}
}

func TestAppendPrintHistory_EmptyMetadataInitialises(t *testing.T) {
	eng, db := newPrintHistoryEngine(t)
	seedPayment(t, db, "p3", "")

	got, err := eng.AppendPrintHistory("p3", "auto")
	if err != nil {
		t.Fatalf("append: %v", err)
	}
	if got.ReprintNo != 1 {
		t.Errorf("empty metadata → reprint_no=1, got %d", got.ReprintNo)
	}
}

// Concurrent appends produce ordered, monotonic reprint_no.
// SQLite serialises writes inside transactions, so we expect no duplicates.
func TestAppendPrintHistory_ConcurrentOrdered(t *testing.T) {
	eng, db := newPrintHistoryEngine(t)
	seedPayment(t, db, "p4", `{"split_mode":"even"}`)

	const N = 8
	var wg sync.WaitGroup
	results := make([]int, N)
	wg.Add(N)
	for i := 0; i < N; i++ {
		go func(i int) {
			defer wg.Done()
			entry, err := eng.AppendPrintHistory("p4", "manual reprint")
			if err != nil {
				t.Errorf("worker %d: %v", i, err)
				return
			}
			results[i] = entry.ReprintNo
		}(i)
	}
	wg.Wait()

	// All N reprint_no values 1..N must appear exactly once.
	seen := make(map[int]bool)
	for _, n := range results {
		if n < 1 || n > N {
			t.Errorf("reprint_no out of range: %d", n)
			continue
		}
		if seen[n] {
			t.Errorf("duplicate reprint_no: %d", n)
		}
		seen[n] = true
	}
	if len(seen) != N {
		t.Errorf("want %d distinct reprint_no values, got %d", N, len(seen))
	}

	// Stored history has exactly N entries.
	var stored string
	_ = db.QueryRow(`SELECT metadata FROM payments WHERE id='p4'`).Scan(&stored)
	var parsed map[string]any
	_ = json.Unmarshal([]byte(stored), &parsed)
	hist, _ := parsed["print_history"].([]any)
	if len(hist) != N {
		t.Errorf("stored history len: want %d, got %d", N, len(hist))
	}
	// Original metadata preserved.
	if parsed["split_mode"] != "even" {
		t.Errorf("split_mode lost under concurrency: %v", parsed["split_mode"])
	}
}
