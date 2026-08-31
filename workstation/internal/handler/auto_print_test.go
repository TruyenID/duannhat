package handler

import (
	"testing"
	"time"

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

	// An order whose FIRST sighting is already closed (customer placed AND paid
	// online between two pulls) still fires. It used to return here "for the
	// paid hook to print" — but the paid hook prints the kitchen ticket only for
	// takeaway Mode A, so a dine-in order's cook was never told (#3123).
	closed := seedSpotOrder(t, s, []string{"kitchen"})
	s.handleOrderArrivedAutoPrint(closed.ID, "dine_in", "closed")
	if got := firedItemCount(t, s, closed.ID); got != 1 {
		t.Fatalf("closed-on-arrival dine-in: want 1 fired, got %d", got)
	}

	// …and the toggle still governs it: OFF means OFF for a closed arrival too.
	setWSSetting(t, s, "auto_print_kitchen", "false")
	closedOff := seedSpotOrder(t, s, []string{"kitchen"})
	s.handleOrderArrivedAutoPrint(closedOff.ID, "dine_in", "closed")
	if got := firedItemCount(t, s, closedOff.ID); got != 0 {
		t.Fatalf("closed-on-arrival with toggle off: want 0 fired, got %d", got)
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

// ─── Age gate: don't auto-print backfilled / re-seeded old orders ──────────

// A dine-in order that arrives via pull-down with a HISTORICAL opened_at is
// treated as backfill / re-seed data — NOT a live order — so it must NOT
// auto-fire its kitchen ticket. A fresh order (opened_at ~= now) still fires, so
// the live customer-web path is unaffected. This is the guard against the
// re-seed reprint storm.
func TestAutoPrint_SkipsOldSyncedOrder(t *testing.T) {
	s := newFireTestServer(t)
	s.idempotency = service.NewIdempotencyStore(s.db)
	setWSSetting(t, s, "auto_print_kitchen", "true")

	// Fresh order (opened_at = now, from Create) still fires.
	fresh := seedSpotOrder(t, s, []string{"kitchen"})
	s.handleOrderArrivedAutoPrint(fresh.ID, "dine_in", "open")
	if got := firedItemCount(t, s, fresh.ID); got != 1 {
		t.Fatalf("fresh order: want 1 fired, got %d", got)
	}

	// Backfilled order (opened 2h ago, beyond the 60m default) must NOT fire,
	// and must NOT be adopted for later auto-print merges.
	old := seedSpotOrder(t, s, []string{"kitchen"})
	setOrderOpenedAt(t, s, old.ID, time.Now().Add(-2*time.Hour))
	s.handleOrderArrivedAutoPrint(old.ID, "dine_in", "open")
	if got := firedItemCount(t, s, old.ID); got != 0 {
		t.Fatalf("old synced order: want 0 fired (backfill), got %d", got)
	}
	if s.isDineInAutoPrintEligible(old.ID) {
		t.Fatalf("old synced order must not be adopted for auto-print")
	}
}

// The PAID hook must NOT be age-gated: a Cloud-settled kiosk/customer order
// closes via pull-down and can be OPEN far longer than the age window before
// it's paid, so opened_at is not a "backfill" signal there. Regression for the
// kiosk auto-print that opened_at-gating the paid hook silently swallowed (#141).
func TestAutoPrint_PaidHookNotAgeGated(t *testing.T) {
	s := newFireTestServer(t)
	s.idempotency = service.NewIdempotencyStore(s.db)
	// prep_before_payment defaults to true (Mode A) → the paid hook fires the
	// kitchen ticket for a takeaway order.

	o := seedSpotOrder(t, s, []string{"kitchen"})
	if _, err := s.db.Exec(`UPDATE orders SET order_type='takeaway' WHERE id=?`, o.ID); err != nil {
		t.Fatalf("set takeaway: %v", err)
	}
	// Opened 2h ago — far past the 5m age window a live-arrival would use.
	setOrderOpenedAt(t, s, o.ID, time.Now().Add(-2*time.Hour))

	s.handleOrderPaidAutoPrint(o.ID, 1000)
	if got := firedItemCount(t, s, o.ID); got != 1 {
		t.Fatalf("paid hook must fire kitchen for an old-opened takeaway; got %d fired", got)
	}
}

// syncedOrderTooOldToAutoPrint keys off orders.opened_at vs the (configurable)
// max age, and fails OPEN (prints) on any unreadable / missing / unparseable
// timestamp so a live order is never starved over a data glitch.
func TestSyncedOrderTooOldToAutoPrint(t *testing.T) {
	s := newFireTestServer(t)

	fresh := seedSpotOrder(t, s, []string{"kitchen"})
	if s.syncedOrderTooOldToAutoPrint(fresh.ID) {
		t.Fatalf("fresh order must not be considered too old")
	}

	old := seedSpotOrder(t, s, []string{"kitchen"})
	setOrderOpenedAt(t, s, old.ID, time.Now().Add(-90*time.Minute))
	if !s.syncedOrderTooOldToAutoPrint(old.ID) {
		t.Fatalf("order opened 90m ago must exceed the 60m default")
	}

	// Custom threshold: 15m makes a 30m-old order too old, a 5m-old one fresh.
	setWSSetting(t, s, "auto_print_max_age_minutes", "15")
	if !s.syncedOrderTooOldToAutoPrint(old.ID) {
		t.Fatalf("30m/90m order must exceed a 15m threshold")
	}
	recent := seedSpotOrder(t, s, []string{"kitchen"})
	setOrderOpenedAt(t, s, recent.ID, time.Now().Add(-5*time.Minute))
	if s.syncedOrderTooOldToAutoPrint(recent.ID) {
		t.Fatalf("5m-old order must be under a 15m threshold")
	}

	// Fail-open: a row that doesn't exist locally prints (never blocks).
	if s.syncedOrderTooOldToAutoPrint("does-not-exist") {
		t.Fatalf("missing order must fail open (not too old)")
	}
}

// autoPrintMaxAge defaults to 5m and only accepts a positive integer override.
func TestAutoPrintMaxAge(t *testing.T) {
	s := newFireTestServer(t)

	if got := s.autoPrintMaxAge(); got != 5*time.Minute {
		t.Fatalf("unset: want 5m default, got %v", got)
	}
	for _, bad := range []string{"", "abc", "0", "-5"} {
		setWSSetting(t, s, "auto_print_max_age_minutes", bad)
		if got := s.autoPrintMaxAge(); got != 5*time.Minute {
			t.Fatalf("invalid %q: want 5m default, got %v", bad, got)
		}
	}
	setWSSetting(t, s, "auto_print_max_age_minutes", "20")
	if got := s.autoPrintMaxAge(); got != 20*time.Minute {
		t.Fatalf(`"20": want 20m, got %v`, got)
	}
}

// setOrderOpenedAt backdates an order's opened_at so a test can simulate a
// backfilled / re-seeded (old) order arriving via pull-down.
func setOrderOpenedAt(t *testing.T, s *Server, orderID string, at time.Time) {
	t.Helper()
	if _, err := s.db.Exec(
		`UPDATE orders SET opened_at = ? WHERE id = ?`,
		at.UTC().Format(time.RFC3339), orderID,
	); err != nil {
		t.Fatalf("set opened_at for %s: %v", orderID, err)
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
