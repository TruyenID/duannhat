package service

import (
	"testing"
	"time"

	"github.com/google/uuid"
)

// #501 — NormalizedTotals must return an already-costed order's stored,
// authoritative breakdown VERBATIM (Cloud / the local engine is the source of
// truth), not re-derive it from items + local rates. Re-deriving drifted on
// promo orders: the customer saw 2,200đ but the kiosk rang up 2,298đ.
func TestNormalizedTotals_TrustsStoredTotal(t *testing.T) {
	db := newPromoTestDB(t)
	orders := NewOrderEngine(db, 10)

	id := uuid.NewString()
	now := time.Now().UTC().Format(time.RFC3339)
	// Cloud-authoritative breakdown: total 2200 (e.g. after a promo discount).
	mustExecF(t, db, `
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    subtotal, discount_amount, tax_amount, service_charge, total_amount, paid_amount,
		    created_at, updated_at)
		VALUES (?, ?, 'takeaway', 'confirmed', ?, 2000, 300, 100, 100, 2200, 0, ?, ?)`,
		id, "ORD-501-A", now, now, now)
	// An item whose naive recompute (2300) deliberately != the stored total 2200.
	mustExecF(t, db, `
		INSERT INTO order_items (id, customer_order_id, quantity, unit_price, subtotal, status, created_at, updated_at)
		VALUES (?, ?, 1, 2300, 2300, 'pending', ?, ?)`,
		uuid.NewString(), id, now, now)

	o, err := orders.GetByID(id)
	if err != nil {
		t.Fatal(err)
	}
	sub, disc, tax, svc, total := orders.NormalizedTotals(o)
	if sub != 2000 || disc != 300 || tax != 100 || svc != 100 || total != 2200 {
		t.Fatalf("want stored 2000/300/100/100/2200, got %d/%d/%g/%d/%d", sub, disc, tax, svc, total)
	}
}

// Pre-#501 sync-down stored the authoritative total but service_charge = 0.
// The safety net must back the missing charge out of the total so the bill
// breakdown still sums (2350 − 0 + 235 + 118 = 2703).
func TestNormalizedTotals_BacksOutMissingServiceCharge(t *testing.T) {
	db := newPromoTestDB(t)
	orders := NewOrderEngine(db, 10)

	id := uuid.NewString()
	now := time.Now().UTC().Format(time.RFC3339)
	mustExecF(t, db, `
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    subtotal, discount_amount, tax_amount, service_charge, total_amount, paid_amount,
		    created_at, updated_at)
		VALUES (?, ?, 'takeaway', 'confirmed', ?, 2350, 0, 235, 0, 2703, 0, ?, ?)`,
		id, "ORD-501-C", now, now, now)

	o, err := orders.GetByID(id)
	if err != nil {
		t.Fatal(err)
	}
	sub, disc, tax, svc, total := orders.NormalizedTotals(o)
	if total != 2703 || svc != 118 || sub != 2350 || tax != 235 || disc != 0 {
		t.Fatalf("want 2350/0/235/118/2703 (service backed out), got %d/%d/%g/%d/%d", sub, disc, tax, svc, total)
	}
}

// Fallback: an uncosted order (total_amount 0 — legacy zeroed sync-down row)
// still derives a non-zero total from its items so the bill isn't blank.
func TestNormalizedTotals_FallsBackWhenUncosted(t *testing.T) {
	db := newPromoTestDB(t)
	orders := NewOrderEngine(db, 10)

	id := uuid.NewString()
	now := time.Now().UTC().Format(time.RFC3339)
	mustExecF(t, db, `
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    subtotal, discount_amount, total_amount, paid_amount, created_at, updated_at)
		VALUES (?, ?, 'takeaway', 'confirmed', ?, 0, 0, 0, 0, ?, ?)`,
		id, "ORD-501-B", now, now, now)
	mustExecF(t, db, `
		INSERT INTO order_items (id, customer_order_id, quantity, unit_price, subtotal, status, created_at, updated_at)
		VALUES (?, ?, 2, 1000, 2000, 'pending', ?, ?)`,
		uuid.NewString(), id, now, now)

	o, err := orders.GetByID(id)
	if err != nil {
		t.Fatal(err)
	}
	sub, _, _, _, total := orders.NormalizedTotals(o)
	if sub != 2000 || total < 2000 {
		t.Fatalf("want recomputed subtotal 2000 and total>=2000, got sub=%d total=%d", sub, total)
	}
}
