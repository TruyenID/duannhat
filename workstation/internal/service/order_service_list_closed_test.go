package service

import "testing"

// The workstation's "paid bills" view lists closed orders — kiosk/customer
// orders confirmed in Cloud arrive already closed via pull-down and are absent
// from the active board. ListRecentClosed must return only closed orders, and
// ListActive must never leak them.
func TestListRecentClosed_ReturnsOnlyClosed(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	insertOrderWithNullableNULL(t, db, "active-1", "open")
	insertOrderWithNullableNULL(t, db, "paid-1", "closed")
	insertOrderWithNullableNULL(t, db, "paid-2", "closed")

	closed, err := eng.ListRecentClosed(100)
	if err != nil {
		t.Fatalf("ListRecentClosed: %v", err)
	}
	if len(closed) != 2 {
		t.Fatalf("expected 2 closed orders, got %d", len(closed))
	}
	for _, o := range closed {
		if o.Status != "closed" {
			t.Errorf("ListRecentClosed returned non-closed order %s (%s)", o.ID, o.Status)
		}
	}

	active, err := eng.ListActive()
	if err != nil {
		t.Fatalf("ListActive: %v", err)
	}
	if len(active) != 1 {
		t.Fatalf("expected 1 active order, got %d", len(active))
	}
	for _, o := range active {
		if o.Status == "closed" {
			t.Errorf("ListActive leaked closed order %s", o.ID)
		}
	}
}

// The limit is clamped to a sane default when callers pass 0 or something huge.
func TestListRecentClosed_ClampsLimit(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	insertOrderWithNullableNULL(t, db, "paid-1", "closed")

	for _, lim := range []int{0, -5, 10000} {
		got, err := eng.ListRecentClosed(lim)
		if err != nil {
			t.Fatalf("ListRecentClosed(%d): %v", lim, err)
		}
		if len(got) != 1 {
			t.Fatalf("ListRecentClosed(%d): expected 1, got %d", lim, len(got))
		}
	}
}
