package service

import (
	"database/sql"
	"testing"
)

// User-reported regression: creating an empty order in pos-web LAN mode
// always landed with guest_count=1 even when the cashier never typed a
// value. Root cause was two layers stacked:
//
//   1. orders.guest_count column was NOT NULL DEFAULT 1 (migration 005).
//   2. order_service.Create forced `input.GuestCount = 1` whenever the
//      decoded value was <=0 (i.e. JSON `null` / missing field decoded
//      to Go's int zero).
//
// Migration 031 dropped the NOT NULL; the struct field flipped to
// *int; the auto-default branch was removed. These tests pin that an
// empty/null payload persists as NULL all the way through and that
// values >0 still round-trip.

func TestCreate_EmptyGuestCountPersistsAsNull(t *testing.T) {
	eng, db := newOrderEngineForTest(t)

	o, err := eng.Create(CreateOrderInput{
		OrderType: "spot",
		// GuestCount intentionally omitted — simulates pos-web's
		// JSON payload for an empty new order.
	}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if o.GuestCount != nil {
		t.Errorf("returned Order.GuestCount should be nil for empty input, got %v", *o.GuestCount)
	}

	// DB row must be SQL NULL, not 1.
	var dbVal sql.NullInt64
	if err := db.QueryRow(`SELECT guest_count FROM orders WHERE id = ?`, o.ID).Scan(&dbVal); err != nil {
		t.Fatalf("readback: %v", err)
	}
	if dbVal.Valid {
		t.Errorf("orders.guest_count should be NULL for empty input, got %d (the user-reported bug)", dbVal.Int64)
	}
}

func TestCreate_ZeroGuestCountCoercesToNull(t *testing.T) {
	// Defensive: a pos-web caller that explicitly sends 0 should be
	// treated like omission — cloud's spec is `minimum: 1`, so 0 is
	// not a valid value anyway. Persist NULL rather than store junk.
	eng, db := newOrderEngineForTest(t)
	zero := 0
	o, err := eng.Create(CreateOrderInput{
		OrderType:  "spot",
		GuestCount: &zero,
	}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if o.GuestCount != nil {
		t.Errorf("0 should coerce to nil, got %v", *o.GuestCount)
	}
	var dbVal sql.NullInt64
	_ = db.QueryRow(`SELECT guest_count FROM orders WHERE id = ?`, o.ID).Scan(&dbVal)
	if dbVal.Valid {
		t.Errorf("0 should persist as NULL, got %d", dbVal.Int64)
	}
}

func TestCreate_PositiveGuestCountRoundTrips(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	o, err := eng.Create(CreateOrderInput{
		OrderType:  "spot",
		GuestCount: intPtr(5),
	}, nil)
	if err != nil {
		t.Fatalf("Create: %v", err)
	}
	if o.GuestCount == nil || *o.GuestCount != 5 {
		t.Errorf("explicit 5 must round-trip: got %v", o.GuestCount)
	}
	var dbVal sql.NullInt64
	_ = db.QueryRow(`SELECT guest_count FROM orders WHERE id = ?`, o.ID).Scan(&dbVal)
	if !dbVal.Valid || dbVal.Int64 != 5 {
		t.Errorf("orders.guest_count want 5, got valid=%v val=%d", dbVal.Valid, dbVal.Int64)
	}

	// GetByID must hydrate it back.
	again, err := eng.GetByID(o.ID)
	if err != nil {
		t.Fatalf("GetByID: %v", err)
	}
	if again.GuestCount == nil || *again.GuestCount != 5 {
		t.Errorf("GetByID hydration: got %v", again.GuestCount)
	}
}

func TestCreate_GetByIDPreservesNullGuestCount(t *testing.T) {
	// Mirror of the round-trip test, but for the empty case: an order
	// stored with NULL must read back as nil — not get COALESCE'd to a
	// fake "1" on its way through GetByID.
	eng, _ := newOrderEngineForTest(t)
	o, err := eng.Create(CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	got, err := eng.GetByID(o.ID)
	if err != nil {
		t.Fatal(err)
	}
	if got.GuestCount != nil {
		t.Errorf("GetByID should preserve NULL guest_count, got %v", *got.GuestCount)
	}
}
