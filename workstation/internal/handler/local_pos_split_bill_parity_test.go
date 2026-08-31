package handler

import (
	"database/sql"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"net/url"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// "Chia đều" (split equally) regression — the user-reported "nút chia
// đều đang bị lỗi" bug. Pre-fix the LAN handler returned a
// `{bill_totals: []int, items_per_bill: ...}` envelope. pos-web's
// useSplitBill hook destructures `per_person_amounts: string[]`, sees
// `undefined`, and crashes the SplitBillEqualTab render.
//
// Post-fix the LAN response shape is byte-compatible with Cloud's
// CustomerOrderService::splitBill: per_person_amounts is a string[]
// with exact-decimal yen values, total/remaining as strings,
// rounding_note populated when the first payer absorbs the remainder.

func setupOrderForSplit(t *testing.T, totalAmount int) (*Server, *sql.DB, string) {
	t.Helper()
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.orders = service.NewOrderEngine(srv.db)
	srv.hub = NewHub()

	o, err := srv.orders.Create(service.CreateOrderInput{OrderType: "spot"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := srv.db.Exec(
		`UPDATE orders SET total_amount = ?, paid_amount = 0, status = 'checkout' WHERE id = ?`,
		totalAmount, o.ID,
	); err != nil {
		t.Fatal(err)
	}
	return srv, srv.db.Conn(), o.ID
}

func getSplitBill(t *testing.T, srv *Server, orderID string, splitCount int) map[string]any {
	t.Helper()
	q := ""
	if splitCount > 0 {
		q = "?split_count=" + url.QueryEscape(
			// itoa
			func() string {
				b := []byte{}
				n := splitCount
				if n == 0 {
					return "0"
				}
				for n > 0 {
					b = append([]byte{byte('0' + n%10)}, b...)
					n /= 10
				}
				return string(b)
			}(),
		)
	}
	req := httptest.NewRequest("GET",
		"/api/v1/pos/orders/"+orderID+"/split-bill"+q, nil)
	req.SetPathValue("id", orderID)
	rec := httptest.NewRecorder()
	srv.handleLocalPosSplitBill(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("status %d body=%s", rec.Code, rec.Body.String())
	}
	// Backend's CustomerOrderController@splitBill returns a FLAT object
	// — no `{data: ...}` envelope. Workstation mirrors that exactly so
	// pos-web's apiFetch<SplitBillResponse> reads the same shape on
	// LAN + Cloud. Decoding straight into a map[string]any captures
	// the top-level fields.
	var data map[string]any
	if err := json.NewDecoder(rec.Body).Decode(&data); err != nil {
		t.Fatalf("decode: %v", err)
	}
	return data
}

func TestSplitBill_EqualMode_CloudShapeParity(t *testing.T) {
	srv, _, orderID := setupOrderForSplit(t, 1000)
	data := getSplitBill(t, srv, orderID, 4)

	// Cloud emits decimal strings; pos-web reads as `string`.
	if v, _ := data["total_amount"].(string); v != "1000.00" {
		t.Errorf("total_amount: want '1000.00', got %v", data["total_amount"])
	}
	if v, _ := data["remaining_amount"].(string); v != "1000.00" {
		t.Errorf("remaining_amount: want '1000.00', got %v", data["remaining_amount"])
	}
	if v, _ := data["split_count"].(float64); int(v) != 4 {
		t.Errorf("split_count: want 4, got %v", data["split_count"])
	}
	if v, _ := data["per_person_amount"].(string); v != "250.00" {
		t.Errorf("per_person_amount: want '250.00', got %v", data["per_person_amount"])
	}
	amounts, ok := data["per_person_amounts"].([]any)
	if !ok {
		t.Fatalf("per_person_amounts must be a string array, got %T %v",
			data["per_person_amounts"], data["per_person_amounts"])
	}
	if len(amounts) != 4 {
		t.Errorf("per_person_amounts len: want 4, got %d", len(amounts))
	}
	for i, a := range amounts {
		if a != "250.00" {
			t.Errorf("per_person_amounts[%d]: want '250.00', got %v", i, a)
		}
	}
	if data["rounding_note"] != nil {
		t.Errorf("rounding_note: want null for clean split, got %v", data["rounding_note"])
	}
}

// Rounding rule: floor division, FIRST payer absorbs the remainder
// (mirrors backend CustomerOrderService::splitBill).
func TestSplitBill_EqualMode_FirstPayerAbsorbsRemainder(t *testing.T) {
	srv, _, orderID := setupOrderForSplit(t, 1003)
	data := getSplitBill(t, srv, orderID, 3)

	amounts := data["per_person_amounts"].([]any)
	// 1003 / 3 = 334 floor, remainder 1 → [335, 334, 334]
	if amounts[0] != "335.00" {
		t.Errorf("first payer absorbs: want '335.00', got %v", amounts[0])
	}
	if amounts[1] != "334.00" || amounts[2] != "334.00" {
		t.Errorf("trailing payers: want '334.00', got %v / %v", amounts[1], amounts[2])
	}
	note, ok := data["rounding_note"].(string)
	if !ok || note == "" {
		t.Errorf("rounding_note must populate for uneven splits: got %v", data["rounding_note"])
	}
}

func TestSplitBill_EqualMode_DefaultsToGuestCount(t *testing.T) {
	srv, _, orderID := setupOrderForSplit(t, 500)
	// guest_count nullable post-031 — set explicitly via SQL.
	if _, err := srv.db.Exec(
		`UPDATE orders SET guest_count = 5 WHERE id = ?`, orderID,
	); err != nil {
		t.Fatal(err)
	}

	// No split_count query → handler must fall back to order.guest_count = 5.
	data := getSplitBill(t, srv, orderID, 0)
	if int(data["split_count"].(float64)) != 5 {
		t.Errorf("split_count default: want 5 (guest_count), got %v", data["split_count"])
	}
	if len(data["per_person_amounts"].([]any)) != 5 {
		t.Errorf("per_person_amounts len: want 5, got %d",
			len(data["per_person_amounts"].([]any)))
	}
}

func TestSplitBill_EqualMode_RejectsSplitCountBelowTwo(t *testing.T) {
	srv, _, orderID := setupOrderForSplit(t, 500)
	req := httptest.NewRequest("GET",
		"/api/v1/pos/orders/"+orderID+"/split-bill?split_count=1", nil)
	req.SetPathValue("id", orderID)
	rec := httptest.NewRecorder()
	srv.handleLocalPosSplitBill(rec, req)
	if rec.Code != http.StatusUnprocessableEntity {
		t.Errorf("split_count=1 must 422, got %d", rec.Code)
	}
}

func TestSplitBill_EqualMode_FullyPaidReturnsZeros(t *testing.T) {
	srv, _, orderID := setupOrderForSplit(t, 1000)
	if _, err := srv.db.Exec(
		`UPDATE orders SET paid_amount = 1000 WHERE id = ?`, orderID,
	); err != nil {
		t.Fatal(err)
	}
	data := getSplitBill(t, srv, orderID, 4)
	if data["remaining_amount"] != "0.00" {
		t.Errorf("remaining_amount: want '0.00', got %v", data["remaining_amount"])
	}
	for i, a := range data["per_person_amounts"].([]any) {
		if a != "0.00" {
			t.Errorf("per_person_amounts[%d]: want '0.00', got %v", i, a)
		}
	}
}

// ─── Split by items preview ───────────────────────────────────────────────

func TestSplitByItemsPreview_NoAllocations_ReturnsClaimsOnly(t *testing.T) {
	srv, _, orderID := setupOrderForSplit(t, 500)
	// Seed 1 order item so the items[] array isn't empty.
	if _, err := srv.db.Exec(`
		INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity,
		    unit_price, subtotal, status, print_status, printer_group, created_at, updated_at)
		VALUES ('it-1', ?, 'Phở', 1, 500, 500, 'pending', 'pending', 'kitchen', datetime('now'), datetime('now'))`,
		orderID); err != nil {
		t.Fatal(err)
	}

	req := httptest.NewRequest("GET",
		"/api/v1/pos/orders/"+orderID+"/split-by-items/preview", nil)
	req.SetPathValue("id", orderID)
	rec := httptest.NewRecorder()
	srv.handleLocalPosSplitByItemsPreview(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("status %d body=%s", rec.Code, rec.Body.String())
	}

	var env map[string]any
	_ = json.NewDecoder(rec.Body).Decode(&env)
	data := env["data"].(map[string]any)

	if data["currency_code"] != "JPY" {
		t.Errorf("currency_code default: want JPY, got %v", data["currency_code"])
	}
	items := data["items"].([]any)
	if len(items) != 1 {
		t.Fatalf("items len: want 1, got %d", len(items))
	}
	item := items[0].(map[string]any)
	if item["item_id"] != "it-1" {
		t.Errorf("item_id: %v", item["item_id"])
	}
	if int(item["units_claimed"].(float64)) != 0 {
		t.Errorf("units_claimed default: want 0, got %v", item["units_claimed"])
	}
	if int(item["units_remaining"].(float64)) != 1 {
		t.Errorf("units_remaining: want 1, got %v", item["units_remaining"])
	}
	// preview_bills omitted in no-allocations mode.
	if _, present := data["preview_bills"]; present {
		t.Error("preview_bills must be omitted when allocations is empty")
	}
}

func TestSplitByItemsPreview_WithAllocations_ReturnsBills(t *testing.T) {
	srv, _, orderID := setupOrderForSplit(t, 1500)
	// 2 items: A(500) + B(1000). Subtotal=1500, total=1500 (no tax in this fixture).
	for _, row := range []struct {
		id   string
		name string
		qty  int
		unit int
	}{
		{"it-A", "A", 1, 500},
		{"it-B", "B", 1, 1000},
	} {
		_, err := srv.db.Exec(`
			INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity,
			    unit_price, subtotal, status, print_status, printer_group, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, 'pending', 'pending', 'kitchen', datetime('now'), datetime('now'))`,
			row.id, orderID, row.name, row.qty, row.unit, row.unit*row.qty)
		if err != nil {
			t.Fatal(err)
		}
	}
	if _, err := srv.db.Exec(`UPDATE orders SET subtotal = 1500 WHERE id = ?`, orderID); err != nil {
		t.Fatal(err)
	}

	// Bill 0 gets A, bill 1 gets B.
	alloc := url.QueryEscape(`[{"item_id":"it-A","units":1,"bill_index":0},{"item_id":"it-B","units":1,"bill_index":1}]`)
	req := httptest.NewRequest("GET",
		"/api/v1/pos/orders/"+orderID+"/split-by-items/preview?allocations="+alloc, nil)
	req.SetPathValue("id", orderID)
	rec := httptest.NewRecorder()
	srv.handleLocalPosSplitByItemsPreview(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("status %d body=%s", rec.Code, rec.Body.String())
	}

	var env map[string]any
	_ = json.NewDecoder(rec.Body).Decode(&env)
	data := env["data"].(map[string]any)

	bills, ok := data["preview_bills"].([]any)
	if !ok {
		t.Fatal("preview_bills missing")
	}
	if len(bills) != 2 {
		t.Fatalf("preview_bills len: want 2, got %d", len(bills))
	}
	b0 := bills[0].(map[string]any)
	b1 := bills[1].(map[string]any)
	if b0["subtotal"] != "500.00" || b0["total"] != "500.00" {
		t.Errorf("bill 0: subtotal=%v total=%v", b0["subtotal"], b0["total"])
	}
	if b1["subtotal"] != "1000.00" || b1["total"] != "1000.00" {
		t.Errorf("bill 1: subtotal=%v total=%v", b1["subtotal"], b1["total"])
	}
}

func TestSplitByItemsPreview_RejectsTooLargeAllocations(t *testing.T) {
	srv, _, orderID := setupOrderForSplit(t, 500)
	big := make([]byte, 5000)
	for i := range big {
		big[i] = 'a'
	}
	req := httptest.NewRequest("GET",
		"/api/v1/pos/orders/"+orderID+"/split-by-items/preview?allocations="+string(big), nil)
	req.SetPathValue("id", orderID)
	rec := httptest.NewRecorder()
	srv.handleLocalPosSplitByItemsPreview(rec, req)
	if rec.Code != http.StatusUnprocessableEntity {
		t.Errorf("5KB allocations must 422, got %d", rec.Code)
	}
}
