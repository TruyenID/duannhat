package service

// #149 — the cancelled-orders board, and the regression that made it necessary.
//
// `expired` is Cloud's auto-cancellation of a takeaway counter-pay order whose
// payment window elapsed. It reaches the workstation verbatim through
// pull-DOWN, but every local "still on the floor" filter was written as
// `NOT IN ('closed','voided')` — so an order Cloud had already cancelled stayed
// on the active board forever, with no view anywhere that could show it as
// cancelled.

import (
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/google/uuid"
)

// seedOrderWithStatus inserts one order in the given status, stamping
// updated_at explicitly so the recency ordering under test is deterministic.
func seedOrderWithStatus(t *testing.T, db *store.DB, status string, updatedAt time.Time) string {
	t.Helper()
	id := uuid.NewString()
	ts := updatedAt.UTC().Format(time.RFC3339)
	if _, err := db.Exec(`
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    subtotal, discount_amount, total_amount, paid_amount,
		    created_at, updated_at)
		VALUES (?, ?, 'takeaway', ?, ?, 1000, 0, 1000, 0, ?, ?)`,
		id, "T-"+id[:8], status, ts, ts, ts); err != nil {
		t.Fatalf("seed %s order: %v", status, err)
	}
	return id
}

func statusesOf(orders []Order) []string {
	out := make([]string, 0, len(orders))
	for _, o := range orders {
		out = append(out, string(o.Status))
	}
	return out
}

func TestListActive_ExcludesCloudExpiredOrders(t *testing.T) {
	db := newPullerTestDB(t)
	eng := NewOrderEngine(db)
	now := time.Now().UTC()

	openID := seedOrderWithStatus(t, db, "open", now)
	seedOrderWithStatus(t, db, "closed", now)
	seedOrderWithStatus(t, db, "voided", now)
	expiredID := seedOrderWithStatus(t, db, "expired", now)

	active, err := eng.ListActive()
	if err != nil {
		t.Fatalf("ListActive: %v", err)
	}
	if len(active) != 1 || active[0].ID != openID {
		t.Fatalf("active board must hold only the open order, got %v (%v)",
			statusesOf(active), len(active))
	}
	for _, o := range active {
		if o.ID == expiredID {
			t.Fatal("a Cloud-expired takeaway is cancelled — it must leave the active board")
		}
	}
}

func TestListRecentCancelled_ReturnsVoidedAndExpiredNewestFirst(t *testing.T) {
	db := newPullerTestDB(t)
	eng := NewOrderEngine(db)
	now := time.Now().UTC()

	seedOrderWithStatus(t, db, "open", now)
	seedOrderWithStatus(t, db, "closed", now)
	voidedID := seedOrderWithStatus(t, db, "voided", now.Add(-2*time.Hour))
	expiredID := seedOrderWithStatus(t, db, "expired", now.Add(-1*time.Hour))

	cancelled, err := eng.ListRecentCancelled(100)
	if err != nil {
		t.Fatalf("ListRecentCancelled: %v", err)
	}
	if len(cancelled) != 2 {
		t.Fatalf("want 2 cancelled orders, got %d (%v)", len(cancelled), statusesOf(cancelled))
	}
	// updated_at DESC — the expiry happened an hour after the void.
	if cancelled[0].ID != expiredID || cancelled[1].ID != voidedID {
		t.Fatalf("want newest-first [expired, voided], got %v", statusesOf(cancelled))
	}
}

// The limit is clamped the same way ListRecentClosed clamps it — a caller
// passing 0 (or garbage) gets the 100-row default, never an unbounded scan.
func TestListRecentCancelled_ClampsLimit(t *testing.T) {
	db := newPullerTestDB(t)
	eng := NewOrderEngine(db)
	now := time.Now().UTC()

	for i := 0; i < 3; i++ {
		seedOrderWithStatus(t, db, "voided", now.Add(-time.Duration(i)*time.Minute))
	}

	for _, limit := range []int{0, -5, 9999} {
		got, err := eng.ListRecentCancelled(limit)
		if err != nil {
			t.Fatalf("ListRecentCancelled(%d): %v", limit, err)
		}
		if len(got) != 3 {
			t.Fatalf("ListRecentCancelled(%d) = %d rows, want 3", limit, len(got))
		}
	}

	if got, err := eng.ListRecentCancelled(1); err != nil || len(got) != 1 {
		t.Fatalf("ListRecentCancelled(1) = %d rows (err %v), want 1", len(got), err)
	}
}

// ListByFilters backs pos-web's order list; its default (no explicit statuses,
// IncludeAll false) must match ListActive exactly, expired included.
func TestListByFilters_DefaultExcludesExpired(t *testing.T) {
	db := newPullerTestDB(t)
	eng := NewOrderEngine(db)
	now := time.Now().UTC()

	seedOrderWithStatus(t, db, "open", now)
	seedOrderWithStatus(t, db, "expired", now)

	rows, total, err := eng.ListByFilters(ListFilters{})
	if err != nil {
		t.Fatalf("ListByFilters: %v", err)
	}
	if total != 1 || len(rows) != 1 || rows[0].Status != StatusOpen {
		t.Fatalf("default filter must hide expired orders, got %v (total %d)",
			statusesOf(rows), total)
	}

	// …but an explicit status filter still reaches them, which is what the
	// cancelled tab and any future audit view rely on.
	rows, _, err = eng.ListByFilters(ListFilters{Statuses: []string{"expired"}})
	if err != nil {
		t.Fatalf("ListByFilters(expired): %v", err)
	}
	if len(rows) != 1 || rows[0].Status != StatusExpired {
		t.Fatalf("explicit expired filter must return the expired order, got %v", statusesOf(rows))
	}
}

func TestIsCancelledStatus(t *testing.T) {
	for _, s := range []string{"voided", "expired"} {
		if !IsCancelledStatus(s) {
			t.Errorf("IsCancelledStatus(%q) = false, want true", s)
		}
	}
	for _, s := range []string{"open", "closed", "pending", "confirmed", "paying", ""} {
		if IsCancelledStatus(s) {
			t.Errorf("IsCancelledStatus(%q) = true, want false", s)
		}
	}
}
