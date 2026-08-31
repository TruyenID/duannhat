package handler

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// seedPrepayStatusOrder wires a server holding one order, with a Cloud stub that
// only has to answer device auth.
//
// The stub is not optional: device auth is verified against Cloud, and an
// unreachable host answers 503 "auth verification unavailable" before the
// handler runs — which a test asserting "the field is absent" would read as a
// pass, forever.
func seedPrepayStatusOrder(t *testing.T, orderType string, total, paid int) *Server {
	t.Helper()

	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprint(w, `{"data":{"id":"kiosk-1","type":"kiosk","branch_id":"branch-A","status":"active"}}`)
	}))
	t.Cleanup(cloud.Close)

	s, db := newServerWithAuth(t, cloud.URL)
	s.orders = service.NewOrderEngine(db)

	if _, err := db.Exec(`INSERT INTO orders (id, order_code, order_type, status, total_amount, paid_amount, branch_id, opened_at, updated_at)
		VALUES ('ord-p','O-P',?, 'open', ?, ?,'branch-A','2026-08-17T03:00:00Z','2026-08-17T03:00:00Z')`,
		orderType, total, paid); err != nil {
		t.Fatalf("seed order: %v", err)
	}

	return s
}

// awaitingPrepayment reads the flag the way the client must: present-and-true,
// or nothing. `ok` distinguishes "absent" from "false".
func awaitingPrepayment(t *testing.T, s *Server) (value, ok bool) {
	t.Helper()

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)
	req := httptest.NewRequest("GET", "/api/lan/print/status?order_id=ord-p", nil)
	req.Header.Set("Authorization", "Bearer kiosk-token")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("status probe: got %d body=%s", rec.Code, rec.Body.String())
	}

	var body struct {
		Order struct {
			AwaitingPrepayment *bool `json:"awaiting_prepayment"`
		} `json:"order"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("decode: %v — body=%s", err, rec.Body.String())
	}
	if body.Order.AwaitingPrepayment == nil {
		return false, false
	}

	return *body.Order.AwaitingPrepayment, true
}

// Mode A + an unsettled takeaway order is the one shape the client may act on.
func TestPrintStatus_AwaitingPrepaymentOnUnpaidTakeawayInModeA(t *testing.T) {
	s := seedPrepayStatusOrder(t, "takeaway", 2200, 0)
	setShopSetting(t, s, "prep_before_payment", "true")

	if v, ok := awaitingPrepayment(t, s); !ok || !v {
		t.Fatalf("phải báo đang chờ thu tiền; present=%v value=%v", ok, v)
	}
}

// Paid — the wait is over, and the flag must disappear rather than turn false,
// so the client's single `=== true` test keeps working.
func TestPrintStatus_NoFlagOncePaid(t *testing.T) {
	s := seedPrepayStatusOrder(t, "takeaway", 2200, 2200)
	setShopSetting(t, s, "prep_before_payment", "true")

	if _, ok := awaitingPrepayment(t, s); ok {
		t.Fatal("đơn đã trả đủ không được mang cờ chờ")
	}
}

// THE case that must never regress. `prep_before_payment` is a shop-wide row
// whose meaning is takeaway-only: a dine-in table pays after eating, so a flag
// that ignored the order type would grey out the fire button on every dine-in
// order in the shop.
func TestPrintStatus_NoFlagForDineIn(t *testing.T) {
	s := seedPrepayStatusOrder(t, "dine_in", 2200, 0)
	setShopSetting(t, s, "prep_before_payment", "true")

	if _, ok := awaitingPrepayment(t, s); ok {
		t.Fatal("dine-in KHÔNG được mang cờ chờ — bàn ăn tại chỗ trả tiền sau")
	}
}

// Mode B is "prepare immediately": the customer pays at handover, so an unpaid
// takeaway order firing to the kitchen is the whole point of the mode.
func TestPrintStatus_NoFlagInModeB(t *testing.T) {
	s := seedPrepayStatusOrder(t, "takeaway", 2200, 0)
	setShopSetting(t, s, "prep_before_payment", "false")

	if _, ok := awaitingPrepayment(t, s); ok {
		t.Fatal("Mode B không có gì để chờ")
	}
}
