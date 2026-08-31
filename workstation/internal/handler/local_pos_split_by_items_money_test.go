package handler

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strconv"
	"strings"
	"testing"
)

// #1312 — the money math of split-by-items, pinned on the LIVE path.
//
// There are two implementations of "split the bill by item" in this repo:
//
//	service.OrderEngine.ComputeSplitBill      — no production caller
//	handler.handleLocalPosSplitByItemsPreview — what pos-web actually calls
//
// The first one owns three careful money tests (order_split_bill_test.go:
// proportional tax, rounding-stays-exact, no-adjustment). The second one had
// only SHAPE tests (claims listed, bills present, 4096-byte guard) plus one
// zero-tax case — so every arithmetic branch a cashier can reach was unpinned:
// proportional discount, proportional tax, proportional service charge, and the
// reconciliation that forces Σ bills == order total.
//
// These tests deliberately reuse the FIXTURES AND EXPECTED NUMBERS of the
// service-side tests (4000+400 → 1100/3300; 3000+100 → three buckets summing to
// 3100). Same inputs, same expected output, two engines: if the live path ever
// drifts from the tested one, one of the two suites goes red instead of both
// staying green while the shop is charged something else.
//
// Which of the two implementations should survive is #1312's open question and
// needs a human ruling — these tests do not answer it, and stay correct either
// way (they assert behaviour through the HTTP handler, not through whichever
// function it delegates to).

// seedSplitByItemsOrder builds an order whose money columns are set directly,
// mirroring seedSplitOrder in internal/service/order_split_bill_test.go.
func seedSplitByItemsOrder(
	t *testing.T,
	subtotal, taxAmount, serviceCharge, discountAmount, totalAmount int,
	items [][2]int, // {quantity, unitPrice}
) (*Server, string, []string) {
	t.Helper()

	srv, _, orderID := setupOrderForSplit(t, totalAmount)
	if _, err := srv.db.Exec(`
		UPDATE orders
		   SET subtotal = ?, tax_amount = ?, service_charge = ?, discount_amount = ?, total_amount = ?
		 WHERE id = ?`,
		subtotal, taxAmount, serviceCharge, discountAmount, totalAmount, orderID,
	); err != nil {
		t.Fatal(err)
	}

	ids := make([]string, 0, len(items))
	for i, it := range items {
		id := fmt.Sprintf("it-%d", i)
		qty, unit := it[0], it[1]
		if _, err := srv.db.Exec(`
			INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity,
			    unit_price, subtotal, status, print_status, printer_group, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, 'pending', 'pending', 'kitchen', datetime('now'), datetime('now'))`,
			id, orderID, "item-"+id, qty, unit, unit*qty,
		); err != nil {
			t.Fatal(err)
		}
		ids = append(ids, id)
	}

	return srv, orderID, ids
}

// previewBills drives the real HTTP handler and returns its preview_bills.
func previewBills(t *testing.T, srv *Server, orderID string, allocations string) []map[string]any {
	t.Helper()

	req := httptest.NewRequest("GET",
		"/api/v1/pos/orders/"+orderID+"/split-by-items/preview?allocations="+url.QueryEscape(allocations), nil)
	req.SetPathValue("id", orderID)
	rec := httptest.NewRecorder()
	srv.handleLocalPosSplitByItemsPreview(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("status %d body=%s", rec.Code, rec.Body.String())
	}

	var env map[string]any
	if err := json.NewDecoder(rec.Body).Decode(&env); err != nil {
		t.Fatalf("decode: %v", err)
	}
	data, ok := env["data"].(map[string]any)
	if !ok {
		t.Fatalf("no data envelope: %s", rec.Body.String())
	}
	raw, ok := data["preview_bills"].([]any)
	if !ok {
		t.Fatalf("preview_bills missing: %v", data)
	}

	bills := make([]map[string]any, 0, len(raw))
	for _, b := range raw {
		bills = append(bills, b.(map[string]any))
	}

	return bills
}

// yen parses the "1100.00" wire format back to an integer amount.
func yen(t *testing.T, bill map[string]any, field string) int {
	t.Helper()

	s, ok := bill[field].(string)
	if !ok {
		t.Fatalf("%s is not a string: %v", field, bill[field])
	}
	n, err := strconv.Atoi(strings.TrimSuffix(s, ".00"))
	if err != nil {
		t.Fatalf("%s = %q, not a yen amount: %v", field, s, err)
	}

	return n
}

func billTotals(t *testing.T, bills []map[string]any) []int {
	t.Helper()

	out := make([]int, 0, len(bills))
	for _, b := range bills {
		out = append(out, yen(t, b, "total"))
	}

	return out
}

// Two items 1000 + 3000, 10% tax → total 4400. The 400 of tax must ride along
// with each guest's share (100 / 300), not sit on one bill or vanish.
// Same fixture and same expectation as
// TestComputeSplitBill_ByItemsAllocatesTaxProportionally.
func TestSplitByItemsPreview_AllocatesTaxProportionally(t *testing.T) {
	srv, orderID, ids := seedSplitByItemsOrder(t, 4000, 400, 0, 0, 4400,
		[][2]int{{1, 1000}, {1, 3000}})

	bills := previewBills(t, srv, orderID, fmt.Sprintf(
		`[{"item_id":%q,"units":1,"bill_index":0},{"item_id":%q,"units":1,"bill_index":1}]`,
		ids[0], ids[1]))

	totals := billTotals(t, bills)
	if len(totals) != 2 {
		t.Fatalf("want 2 bills, got %d (%v)", len(totals), totals)
	}
	if sum := totals[0] + totals[1]; sum != 4400 {
		t.Fatalf("Σ bills must equal total_amount: want 4400, got %d (%v)", sum, totals)
	}
	if totals[0] != 1100 || totals[1] != 3300 {
		t.Errorf("proportional allocation: want [1100 3300], got %v", totals)
	}
	if tax := yen(t, bills[0], "tax"); tax != 100 {
		t.Errorf("bill 0 tax: want 100, got %d", tax)
	}
}

// Indivisible remainder: three equal items and 100 of tax that does not divide
// by three. Every bucket must stay within one unit of its share AND the sum
// must be exact — a split that loses a yen is a till that will not reconcile.
// Same fixture as TestComputeSplitBill_ByItemsRoundingStaysExact.
func TestSplitByItemsPreview_RoundingStaysExact(t *testing.T) {
	srv, orderID, ids := seedSplitByItemsOrder(t, 3000, 100, 0, 0, 3100,
		[][2]int{{1, 1000}, {1, 1000}, {1, 1000}})

	bills := previewBills(t, srv, orderID, fmt.Sprintf(
		`[{"item_id":%q,"units":1,"bill_index":0},`+
			`{"item_id":%q,"units":1,"bill_index":1},`+
			`{"item_id":%q,"units":1,"bill_index":2}]`,
		ids[0], ids[1], ids[2]))

	totals := billTotals(t, bills)
	if len(totals) != 3 {
		t.Fatalf("want 3 bills, got %d (%v)", len(totals), totals)
	}

	sum := 0
	for i, bt := range totals {
		sum += bt
		if bt < 1033 || bt > 1034 {
			t.Errorf("bucket %d drifted beyond one unit: %d (%v)", i, bt, totals)
		}
	}
	if sum != 3100 {
		t.Errorf("Σ bills: want 3100, got %d (%v)", sum, totals)
	}
}

// A discount on the order must reach every guest in proportion, not just the
// one whose bill happens to be reconciled last. 20%% off 4000, then 10%% tax on
// what is left: bill 0 = 1000 − 200 + 80 = 880, bill 1 = 3000 − 600 + 240 = 2640.
// This branch of the live handler had no test at all.
func TestSplitByItemsPreview_SpreadsDiscountProportionally(t *testing.T) {
	srv, orderID, ids := seedSplitByItemsOrder(t, 4000, 320, 0, 800, 3520,
		[][2]int{{1, 1000}, {1, 3000}})

	bills := previewBills(t, srv, orderID, fmt.Sprintf(
		`[{"item_id":%q,"units":1,"bill_index":0},{"item_id":%q,"units":1,"bill_index":1}]`,
		ids[0], ids[1]))

	if got := yen(t, bills[0], "discount"); got != 200 {
		t.Errorf("bill 0 discount: want 200 (a fifth of 1000), got %d", got)
	}
	if got := yen(t, bills[1], "discount"); got != 600 {
		t.Errorf("bill 1 discount: want 600 (a fifth of 3000), got %d", got)
	}

	totals := billTotals(t, bills)
	if sum := totals[0] + totals[1]; sum != 3520 {
		t.Fatalf("Σ bills must equal total_amount: want 3520, got %d (%v)", sum, totals)
	}
	if totals[0] != 880 || totals[1] != 2640 {
		t.Errorf("discounted allocation: want [880 2640], got %v", totals)
	}
}

// A service charge is money the guest owes too, so it must spread like tax
// rather than being dropped. 4000 subtotal, 400 tax + 400 service → 4800.
func TestSplitByItemsPreview_SpreadsServiceChargeProportionally(t *testing.T) {
	srv, orderID, ids := seedSplitByItemsOrder(t, 4000, 400, 400, 0, 4800,
		[][2]int{{1, 1000}, {1, 3000}})

	bills := previewBills(t, srv, orderID, fmt.Sprintf(
		`[{"item_id":%q,"units":1,"bill_index":0},{"item_id":%q,"units":1,"bill_index":1}]`,
		ids[0], ids[1]))

	if got := yen(t, bills[0], "service"); got != 100 {
		t.Errorf("bill 0 service: want 100, got %d", got)
	}
	if got := yen(t, bills[1], "service"); got != 300 {
		t.Errorf("bill 1 service: want 300, got %d", got)
	}

	totals := billTotals(t, bills)
	if sum := totals[0] + totals[1]; sum != 4800 {
		t.Errorf("Σ bills must equal total_amount: want 4800, got %d (%v)", sum, totals)
	}
}

// A seat in the middle that claims nothing (allocations skip bill_index 1)
// must stay at zero, and the rounding remainder must land on the last
// NON-EMPTY bill — charging an empty seat is how a guest who ordered nothing
// ends up with a bill.
//
// 1000 to seat 0 and 2000 to seat 2, over 3000 + 100 tax: shares are 33 and 66,
// leaving one yen that reconciliation must give to seat 2.
func TestSplitByItemsPreview_EmptyBillStaysZeroAndRemainderGoesToLastClaimed(t *testing.T) {
	srv, orderID, ids := seedSplitByItemsOrder(t, 3000, 100, 0, 0, 3100,
		[][2]int{{1, 1000}, {1, 1000}, {1, 1000}})

	bills := previewBills(t, srv, orderID, fmt.Sprintf(
		`[{"item_id":%q,"units":1,"bill_index":0},`+
			`{"item_id":%q,"units":1,"bill_index":2},`+
			`{"item_id":%q,"units":1,"bill_index":2}]`,
		ids[0], ids[1], ids[2]))

	if len(bills) != 3 {
		t.Fatalf("want 3 bills (seat 1 empty in the middle), got %d", len(bills))
	}
	if empty, _ := bills[1]["is_empty"].(bool); !empty {
		t.Errorf("seat 1 claimed nothing but is_empty=%v", bills[1]["is_empty"])
	}
	if got := yen(t, bills[1], "total"); got != 0 {
		t.Errorf("empty seat total: want 0, got %d", got)
	}

	totals := billTotals(t, bills)
	if sum := totals[0] + totals[1] + totals[2]; sum != 3100 {
		t.Fatalf("Σ bills must equal total_amount: want 3100, got %d (%v)", sum, totals)
	}
	if totals[0] != 1033 || totals[2] != 2067 {
		t.Errorf("remainder must land on the last CLAIMED seat: want [1033 0 2067], got %v", totals)
	}
}
