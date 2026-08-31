package handler

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

func TestHandleLocalPosOrders_QueryBudgetIsConstantForHundredOrders(t *testing.T) {
	s, db := newServerWithAuth(t, "")
	s.orders = service.NewOrderEngine(db)
	if _, err := db.Exec(`INSERT INTO customers (id, first_name, full_name, phone)
		VALUES ('customer-shared', 'Lan', 'Lan Nguyen', '0900000000')`); err != nil {
		t.Fatalf("seed customer: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO payment_methods (id, code, name)
		VALUES ('pm-cash', 'cash', 'Cash synced once')`); err != nil {
		t.Fatalf("seed payment method: %v", err)
	}
	for i := 0; i < 100; i++ {
		if _, err := db.Exec(`INSERT INTO orders
			(id, order_code, order_type, status, opened_at, customer_id,
			 organization_id, brand_id, branch_id, created_at, updated_at)
			VALUES (?, ?, 'dine_in', 'open', ?, 'customer-shared', 'org', 'brand', 'branch-A', ?, ?)`,
			fmt.Sprintf("order-%03d", i), fmt.Sprintf("WS-%03d", i),
			fmt.Sprintf("2026-08-16T00:%02d:00Z", i%60),
			"2026-08-16T00:00:00Z", "2026-08-16T00:00:00Z"); err != nil {
			t.Fatalf("seed order %d: %v", i, err)
		}
		if _, err := db.Exec(`INSERT INTO payments
			(id, order_id, payment_method, payment_method_id, amount, status, created_at, updated_at)
			VALUES (?, ?, 'cash', 'pm-cash', 100, 'succeeded', '2026-08-16T00:00:00Z', '2026-08-16T00:00:00Z')`,
			fmt.Sprintf("payment-%03d", i), fmt.Sprintf("order-%03d", i)); err != nil {
			t.Fatalf("seed payment %d: %v", i, err)
		}
	}

	// Establish the small-page baseline first. A fixed budget at 100 rows is
	// useful, but comparing 1 vs 100 catches a subtler regression where one new
	// relation query is accidentally placed back inside the shaping loop.
	oneBefore := db.Diagnostics().QueryCount
	oneReq := httptest.NewRequest(http.MethodGet, "/api/v1/pos/orders?status=open&per_page=1", nil)
	oneW := httptest.NewRecorder()
	s.handleLocalPosOrders(oneW, oneReq)
	if oneW.Code != http.StatusOK {
		t.Fatalf("one-order baseline status=%d body=%s", oneW.Code, oneW.Body.String())
	}
	oneQueries := db.Diagnostics().QueryCount - oneBefore
	if oneQueries != 9 {
		t.Fatalf("1-order page used %d queries, want fixed budget 9", oneQueries)
	}

	before := db.Diagnostics().QueryCount
	req := httptest.NewRequest(http.MethodGet, "/api/v1/pos/orders?status=open&per_page=100", nil)
	w := httptest.NewRecorder()
	s.handleLocalPosOrders(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("status=%d body=%s", w.Code, w.Body.String())
	}
	if got := db.Diagnostics().QueryCount - before; got != oneQueries {
		t.Errorf("100-order page used %d queries, want same %d-query budget as 1 order", got, oneQueries)
	}

	var payload struct {
		Data []map[string]any `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &payload); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if len(payload.Data) != 100 {
		t.Fatalf("data length=%d, want 100", len(payload.Data))
	}
	for _, field := range []string{"tables", "conditions"} {
		value, ok := payload.Data[0][field].([]any)
		if !ok || len(value) != 0 {
			t.Errorf("response contract %s = %#v, want []", field, payload.Data[0][field])
		}
	}
	customer, ok := payload.Data[0]["customer"].(map[string]any)
	if !ok || customer["id"] != "customer-shared" {
		t.Errorf("shared customer relation changed: %#v", payload.Data[0]["customer"])
	}
	payments := payload.Data[0]["payments"].([]any)
	if len(payments) != 1 || payments[0].(map[string]any)["payment_method_name"] != "Cash synced once" {
		t.Errorf("payment relation changed: %#v", payments)
	}
}

func TestLoadOrderSkuStubs_QueryBudgetIsConstantForHundredItems(t *testing.T) {
	s, db := newServerWithAuth(t, "")
	order := &service.Order{Items: make([]service.Item, 100)}
	for i := range order.Items {
		order.Items[i] = service.Item{
			ProductSkuID: fmt.Sprintf("sku-item-%03d", i),
			Toppings: []service.ItemTopping{{
				ProductSkuID: fmt.Sprintf("sku-topping-%03d", i),
			}},
		}
	}

	before := db.Diagnostics().QueryCount
	stubs := s.loadOrderSkuStubs(order, "ja")
	if got := db.Diagnostics().QueryCount - before; got != 2 {
		t.Errorf("100 items plus toppings used %d catalog queries, want 2 bulk lookups", got)
	}
	if len(stubs) != 0 {
		t.Fatalf("unexpected SKU stubs for an empty catalog: %#v", stubs)
	}
}
