package handler

import (
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printjob"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// postLANPrint drives the endpoint through the real mux so the auth ring and
// routing are exercised, mirroring postReceipt in the sibling guard test.
func postLANPrint(t *testing.T, s *Server, path, body string) *httptest.ResponseRecorder {
	t.Helper()
	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)
	req := httptest.NewRequest("POST", path, strings.NewReader(body))
	req.Header.Set("Authorization", "Bearer kiosk-token")
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	return rec
}

// staleTakeawayCloud serves an order Cloud has already settled, so the local
// copy seeded by the tests below is provably behind.
func staleTakeawayCloud(t *testing.T) *httptest.Server {
	t.Helper()

	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if strings.Contains(r.URL.Path, "/workstation/orders") {
			fmt.Fprint(w, `{"data":[{"id":"ord-tw","order_code":"O-TW","order_type":"takeaway",
				"status":"closed","opened_at":"2026-08-17T03:00:00Z","updated_at":"2026-08-17T03:30:00Z",
				"branch_id":"branch-A","paid_amount":"2200","total_amount":"2200",
				"payment_summary":[{"id":"pay-tw","payment_method_code":"paypay","amount":"2200","status":"succeeded"}]}],
				"count":1,"generated_at":"2026-08-17T04:00:00Z"}`)
			return
		}
		fmt.Fprint(w, `{"data":{"id":"kiosk-1","type":"kiosk","branch_id":"branch-A","status":"active"}}`)
	}))
}

// seedStaleTakeaway wires a server whose local mirror still says the takeaway
// order is open and unpaid, while Cloud has taken the money.
func seedStaleTakeaway(t *testing.T, cloudURL string) (*Server, func()) {
	t.Helper()

	s, db := newServerWithAuth(t, cloudURL)
	s.orders = service.NewOrderEngine(db)
	s.printJournal = printjob.NewJournal(db)
	seedReceiptPrinter(t, s, db)
	s.puller = service.NewSyncPuller(db, cloudURL, s.GetDeviceToken)

	if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES ('device_token', 'WS-TOKEN')`); err != nil {
		t.Fatalf("seed token: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO orders (id, order_code, order_type, status, total_amount, paid_amount, branch_id, opened_at, updated_at)
		VALUES ('ord-tw','O-TW','takeaway','open',2200,0,'branch-A','2026-08-17T03:00:00Z','2026-08-17T03:00:00Z')`); err != nil {
		t.Fatalf("seed stale order: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO order_items (id, customer_order_id, menu_item_id, menu_item_name, quantity, unit_price, subtotal, status, printed_quantity)
		VALUES ('it-tw','ord-tw','mi-1','Bun bo',1,2200,2200,'pending',0)`); err != nil {
		t.Fatalf("seed item: %v", err)
	}

	assertStale := func() {
		t.Helper()
		var status string
		var paid int
		if err := db.QueryRow(`SELECT status, COALESCE(paid_amount,0) FROM orders WHERE id='ord-tw'`).Scan(&status, &paid); err != nil {
			t.Fatalf("read back: %v", err)
		}
		if status != "closed" || paid != 2200 {
			t.Fatalf("đơn phải được làm mới TRƯỚC khi in; còn status=%q paid=%d", status, paid)
		}
	}

	return s, assertStale
}

// The sheet that travels WITH THE FOOD now names payment, so printing it from a
// stale local order publishes a claim about money that is already false.
//
// The window is the ordinary one: the workstation has held the order since it
// was placed, the customer pays online, and the local mirror keeps saying
// `open` until the next pull. `o != nil` is true throughout, which is precisely
// why the pull-on-miss shape this handler used could not see it — the same half
// #3040 closed for the receipt and left open here.
func TestLANPrintKitchenTicket_RefreshesStaleLocalOrderPaidInCloud(t *testing.T) {
	cloud := staleTakeawayCloud(t)
	defer cloud.Close()

	s, assertRefreshed := seedStaleTakeaway(t, cloud.URL)

	rec := postLANPrint(t, s, "/api/lan/print/kitchen-ticket", `{"order_id":"ord-tw"}`)

	if rec.Code == http.StatusNotFound {
		t.Fatalf("đơn có mặt tại máy trạm, không được 404: %s", rec.Body.String())
	}
	assertRefreshed()
}

// Same for the hall/runner sheet — it carries the same payment word, and "in
// bếp với hall" is one operator action in the shop.
func TestLANPrintOrderBill_RefreshesStaleLocalOrderPaidInCloud(t *testing.T) {
	cloud := staleTakeawayCloud(t)
	defer cloud.Close()

	s, assertRefreshed := seedStaleTakeaway(t, cloud.URL)

	rec := postLANPrint(t, s, "/api/lan/print/order-bill", `{"order_id":"ord-tw"}`)

	if rec.Code == http.StatusNotFound {
		t.Fatalf("đơn có mặt tại máy trạm, không được 404: %s", rec.Body.String())
	}
	assertRefreshed()
}

// Cloud down must not stop the kitchen from getting its ticket. The refresh is
// best-effort by design (`ensureOrderLocal` fails OPEN when a usable local copy
// exists); a shop with no internet still has to be able to cook.
func TestLANPrintKitchenTicket_StaleRefreshFailsOpenWhenCloudDown(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.Contains(r.URL.Path, "/workstation/orders") {
			w.WriteHeader(http.StatusInternalServerError)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprint(w, `{"data":{"id":"kiosk-1","type":"kiosk","branch_id":"branch-A","status":"active"}}`)
	}))
	defer cloud.Close()

	s, _ := seedStaleTakeaway(t, cloud.URL)

	rec := postLANPrint(t, s, "/api/lan/print/kitchen-ticket", `{"order_id":"ord-tw"}`)

	if rec.Code == http.StatusServiceUnavailable || rec.Code == http.StatusGatewayTimeout {
		t.Fatalf("Cloud hỏng không được chặn phiếu bếp; got %d %s", rec.Code, rec.Body.String())
	}
}
