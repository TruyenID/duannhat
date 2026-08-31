package handler

import (
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// prepBeforePayment defaults to true (Mode A) when the setting hasn't synced,
// mirrors an explicit "true", and flips to false (Mode B) only on "false".
func TestPrepBeforePaymentSetting(t *testing.T) {
	s := newFireTestServer(t)

	// Unset → default true (matches backend brand-policy default).
	if !s.prepBeforePayment() {
		t.Fatalf("unset prep_before_payment should default to true (Mode A)")
	}

	setShopSetting(t, s, "prep_before_payment", "false")
	if s.prepBeforePayment() {
		t.Fatalf(`prep_before_payment="false" should be Mode B (false)`)
	}

	setShopSetting(t, s, "prep_before_payment", "true")
	if !s.prepBeforePayment() {
		t.Fatalf(`prep_before_payment="true" should be Mode A (true)`)
	}

	// Empty string is treated as unset → default true.
	setShopSetting(t, s, "prep_before_payment", "")
	if !s.prepBeforePayment() {
		t.Fatalf("empty prep_before_payment should default to true")
	}
}

// claimAutoPrint is a one-shot latch per (kind, orderID): the first call wins,
// later calls are blocked — so a re-sync / retry can't double-print a receipt.
// Different kinds and different orders are independent.
func TestClaimAutoPrintDedup(t *testing.T) {
	s := newFireTestServer(t)
	s.idempotency = service.NewIdempotencyStore(s.db)

	if !s.claimAutoPrint("receipt", "order-1") {
		t.Fatalf("first claim should succeed")
	}
	if s.claimAutoPrint("receipt", "order-1") {
		t.Fatalf("second claim for same (kind, order) must be blocked")
	}
	// Different kind, same order → independent latch.
	if !s.claimAutoPrint("kitchen", "order-1") {
		t.Fatalf("different kind should have its own latch")
	}
	// Different order → independent latch.
	if !s.claimAutoPrint("receipt", "order-2") {
		t.Fatalf("different order should have its own latch")
	}
}

// claimAutoPrint degrades safely (allows the print) when no idempotency store
// is wired, so the auto-print never silently no-ops on a misconfigured server.
func TestClaimAutoPrintNilStore(t *testing.T) {
	s := newFireTestServer(t)
	s.idempotency = nil
	if !s.claimAutoPrint("receipt", "order-1") {
		t.Fatalf("nil idempotency store should allow the print")
	}
}

// ─── Dine-in kitchen auto-print (customer-web QR-table orders) ──────────

// A dine-in / spot order that first arrives from customer-web auto-fires its
// kitchen + hold slip only when the auto_print_kitchen toggle is on. OFF (the
// default) → nothing fires and the order isn't adopted, so staff keep manual
// control and POS flows are unchanged.
func TestDineInAutoPrint_ArrivedGatedBySetting(t *testing.T) {
	s := newFireTestServer(t)
	s.idempotency = service.NewIdempotencyStore(s.db)

	// Toggle OFF (unset default): arrival must not fire, order not adopted.
	off := seedSpotOrder(t, s, []string{"kitchen"})
	s.handleOrderArrivedAutoPrint(off.ID, "dine_in", "open")
	if got := firedItemCount(t, s, off.ID); got != 0 {
		t.Fatalf("auto_print_kitchen off: want 0 fired, got %d", got)
	}
	if s.isDineInAutoPrintEligible(off.ID) {
		t.Fatalf("order must not be marked eligible while the toggle is off")
	}

	// Toggle ON: arrival fires the kitchen slip AND marks the order eligible so
	// later append rounds fire on merge.
	setWSSetting(t, s, "auto_print_kitchen", "true")
	on := seedSpotOrder(t, s, []string{"kitchen"})
	s.handleOrderArrivedAutoPrint(on.ID, "dine_in", "open")
	if got := firedItemCount(t, s, on.ID); got != 1 {
		t.Fatalf("auto_print_kitchen on: want 1 fired, got %d", got)
	}
	if !s.isDineInAutoPrintEligible(on.ID) {
		t.Fatalf("arrived dine-in order should be marked auto-print-eligible")
	}

	// An already-closed order defers to the paid hook — never fires here.
	closed := seedSpotOrder(t, s, []string{"kitchen"})
	s.handleOrderArrivedAutoPrint(closed.ID, "dine_in", "closed")
	if got := firedItemCount(t, s, closed.ID); got != 0 {
		t.Fatalf("closed order on arrival must not fire, got %d", got)
	}
}

// On a later "add more" round (merge), only customer-web orders (marked eligible
// at arrival) auto-fire their appended items. A POS-created order is never
// marked, so its merge is a no-op and manual fire is preserved.
func TestDineInAutoPrint_MergeRequiresEligibility(t *testing.T) {
	s := newFireTestServer(t)
	s.idempotency = service.NewIdempotencyStore(s.db)
	setWSSetting(t, s, "auto_print_kitchen", "true")

	// POS-style order: never adopted → merge must not fire.
	pos := seedSpotOrder(t, s, []string{"kitchen"})
	s.handleOrderMergedAutoPrint(pos.ID, "dine_in", "open")
	if got := firedItemCount(t, s, pos.ID); got != 0 {
		t.Fatalf("non-eligible (POS) order: want 0 fired on merge, got %d", got)
	}

	// Customer-web order adopted at arrival → merge fires the appended delta.
	web := seedSpotOrder(t, s, []string{"kitchen"})
	s.markDineInAutoPrint(web.ID)
	s.handleOrderMergedAutoPrint(web.ID, "dine_in", "open")
	if got := firedItemCount(t, s, web.ID); got != 1 {
		t.Fatalf("eligible order: want 1 fired on merge, got %d", got)
	}
}

// The merge auto-fire ignores takeaway (handled by its own hooks), closed/voided
// orders (nothing new to send), and is silenced when the toggle is off.
func TestDineInAutoPrint_MergeSkips(t *testing.T) {
	s := newFireTestServer(t)
	s.idempotency = service.NewIdempotencyStore(s.db)
	setWSSetting(t, s, "auto_print_kitchen", "true")

	takeaway := seedSpotOrder(t, s, []string{"kitchen"})
	s.markDineInAutoPrint(takeaway.ID)
	s.handleOrderMergedAutoPrint(takeaway.ID, "takeaway", "open")
	if got := firedItemCount(t, s, takeaway.ID); got != 0 {
		t.Fatalf("takeaway merge must not fire, got %d", got)
	}

	closed := seedSpotOrder(t, s, []string{"kitchen"})
	s.markDineInAutoPrint(closed.ID)
	s.handleOrderMergedAutoPrint(closed.ID, "dine_in", "closed")
	if got := firedItemCount(t, s, closed.ID); got != 0 {
		t.Fatalf("closed merge must not fire, got %d", got)
	}

	setWSSetting(t, s, "auto_print_kitchen", "false")
	silenced := seedSpotOrder(t, s, []string{"kitchen"})
	s.markDineInAutoPrint(silenced.ID)
	s.handleOrderMergedAutoPrint(silenced.ID, "dine_in", "open")
	if got := firedItemCount(t, s, silenced.ID); got != 0 {
		t.Fatalf("toggle off: merge must not fire, got %d", got)
	}
}

// setWSSetting writes a workstation-local setting (the `settings` table read by
// settingValue), as opposed to setShopSetting which writes the Cloud-synced
// shop_settings.
func setWSSetting(t *testing.T, s *Server, key, value string) {
	t.Helper()
	if _, err := s.db.Exec(
		`INSERT INTO settings (key, value) VALUES (?, ?)
		 ON CONFLICT(key) DO UPDATE SET value = excluded.value`,
		key, value,
	); err != nil {
		t.Fatalf("set ws setting %s: %v", key, err)
	}
}

// firedItemCount reports how many of an order's lines have been fully sent to the
// kitchen (printed_quantity >= quantity). With no physical printer configured,
// fireKitchenForOrder still marks items sent (the KDS is the authoritative
// ticket), so this is the observable proof that an auto-fire ran.
func firedItemCount(t *testing.T, s *Server, orderID string) int {
	t.Helper()
	o, err := s.orders.GetByID(orderID)
	if err != nil || o == nil {
		t.Fatalf("reload order %s: %v", orderID, err)
	}
	n := 0
	for _, it := range o.Items {
		if it.Quantity > 0 && it.PrintedQuantity >= it.Quantity {
			n++
		}
	}
	return n
}

func setShopSetting(t *testing.T, s *Server, key, value string) {
	t.Helper()
	if _, err := s.db.Exec(
		`INSERT INTO shop_settings (key, value) VALUES (?, ?)
		 ON CONFLICT(key) DO UPDATE SET value = excluded.value`,
		key, value,
	); err != nil {
		t.Fatalf("set shop setting %s: %v", key, err)
	}
}
