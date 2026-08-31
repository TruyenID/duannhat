package handler

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// Seed a minimal order so the list / detail / outstanding handlers have
// something to read. customerID may be empty when the test doesn't care.
// branch_id is 'branch-A' to match the workstation_branch_id newServerWithAuth
// seeds — handleLocalPosOrders now scopes the list to the paired branch, so a
// mismatched branch would make the order invisible to the list handler.
func seedTestOrder(t *testing.T, db execer, id, status string, customerID string, total, paid int) {
	t.Helper()
	now := time.Now().UTC().Format(time.RFC3339)
	mustExec(t, db, `
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    subtotal, total_amount, paid_amount, customer_id,
		    organization_id, brand_id, branch_id, created_at, updated_at)
		VALUES (?, ?, 'spot', ?, ?, ?, ?, ?, ?, 'org','brand','branch-A', ?, ?)`,
		id, "T-"+id, status, now, total, total, paid, nullIfEmpty(customerID), now, now)
}

func TestHandleLocalPosOrders_FilterByStatusCommaList(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	srv.orders = service.NewOrderEngine(db, 10)

	seedTestOrder(t, db, "o1", "open", "", 1000, 0)
	seedTestOrder(t, db, "o2", "dining", "", 2000, 0)
	seedTestOrder(t, db, "o3", "closed", "", 3000, 3000)

	req := httptest.NewRequest("GET", "/api/v1/pos/orders?status=open,dining", nil)
	w := httptest.NewRecorder()
	srv.handleLocalPosOrders(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	body := w.Body.String()
	for _, frag := range []string{`"id":"o1"`, `"id":"o2"`, `"current_page":1`, `"total":2`} {
		if !strings.Contains(body, frag) {
			t.Errorf("missing %q in %s", frag, body)
		}
	}
	if strings.Contains(body, `"id":"o3"`) {
		t.Errorf("closed order should be filtered out: %s", body)
	}
}

func TestHandleLocalPosOrders_DefaultExcludesTerminal(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	srv.orders = service.NewOrderEngine(db, 10)

	seedTestOrder(t, db, "alive", "open", "", 100, 0)
	seedTestOrder(t, db, "dead", "voided", "", 100, 0)

	req := httptest.NewRequest("GET", "/api/v1/pos/orders", nil)
	w := httptest.NewRecorder()
	srv.handleLocalPosOrders(w, req)

	body := w.Body.String()
	if !strings.Contains(body, `"id":"alive"`) {
		t.Errorf("default filter should include open: %s", body)
	}
	if strings.Contains(body, `"id":"dead"`) {
		t.Errorf("default filter should exclude voided: %s", body)
	}
}

func TestHandleLocalPosOrders_ShapeIsCloudCompatible(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	srv.orders = service.NewOrderEngine(db, 10)
	seedTestOrder(t, db, "s1", "open", "", 5000, 1000)

	req := httptest.NewRequest("GET", "/api/v1/pos/orders", nil)
	w := httptest.NewRecorder()
	srv.handleLocalPosOrders(w, req)
	body := w.Body.String()

	// Must carry Cloud-shape fields pos-web's CustomerOrder type asserts.
	for _, frag := range []string{
		`"remaining_amount":"4000"`, // stringified per Cloud convention
		`"customer":null`,
		`"items":[]`,
		`"tables":[]`,
		`"payments":[]`,
	} {
		if !strings.Contains(body, frag) {
			t.Errorf("shape missing %q\n%s", frag, body)
		}
	}
}

// The local orders DB can hold OTHER branches' rows (dev seed, or kept across a
// re-pair per plan-818). The list handler must scope to the paired branch so it
// never leaks another shop's orders into the overview / takeaway feed.
func TestHandleLocalPosOrders_ScopedToPairedBranch(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused") // paired to branch-A
	srv.hub = NewHub()
	srv.orders = service.NewOrderEngine(db, 10)

	now := time.Now().UTC().Format(time.RFC3339)
	// mine → branch-A (the paired branch); other → a different branch.
	seedTestOrder(t, db, "mine", "open", "", 1000, 0)
	mustExec(t, db, `
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    subtotal, total_amount, paid_amount,
		    organization_id, brand_id, branch_id, created_at, updated_at)
		VALUES ('other','T-other','takeaway','open',?, 2000,2000,0,
		    'org','brand','branch-OTHER', ?, ?)`, now, now, now)

	req := httptest.NewRequest("GET", "/api/v1/pos/orders?status=open&order_type=takeaway", nil)
	w := httptest.NewRecorder()
	srv.handleLocalPosOrders(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	body := w.Body.String()
	if strings.Contains(body, `"id":"other"`) {
		t.Errorf("cross-branch order must NOT leak into the list: %s", body)
	}
	// The count envelope must also be branch-scoped (0 takeaway on branch-A).
	if !strings.Contains(body, `"total":0`) {
		t.Errorf("total should be branch-scoped to 0, got: %s", body)
	}

	// A takeaway order ON the paired branch IS returned.
	mustExec(t, db, `
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    subtotal, total_amount, paid_amount,
		    organization_id, brand_id, branch_id, created_at, updated_at)
		VALUES ('mine-ta','T-mineta','takeaway','pending',?, 3000,3000,0,
		    'org','brand','branch-A', ?, ?)`, now, now, now)
	req2 := httptest.NewRequest("GET", "/api/v1/pos/orders?status=pending,open&order_type=takeaway", nil)
	w2 := httptest.NewRecorder()
	srv.handleLocalPosOrders(w2, req2)
	body2 := w2.Body.String()
	if !strings.Contains(body2, `"id":"mine-ta"`) {
		t.Errorf("paired-branch takeaway order should be returned: %s", body2)
	}
	if strings.Contains(body2, `"id":"other"`) {
		t.Errorf("cross-branch order still leaked: %s", body2)
	}
}

func TestHandleLocalPosCustomerOutstanding_FiltersByCustomerAndComputesOwed(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	srv.orders = service.NewOrderEngine(db, 10)

	seedTestOrder(t, db, "x1", "checkout", "cust-A", 1000, 200) // owed=800
	seedTestOrder(t, db, "x2", "checkout", "cust-A", 500, 0)    // owed=500
	seedTestOrder(t, db, "x3", "closed", "cust-A", 999, 999)    // skipped (terminal)
	seedTestOrder(t, db, "y1", "checkout", "cust-B", 999, 0)    // other customer

	req := httptest.NewRequest("GET", "/api/v1/pos/customers/cust-A/outstanding", nil)
	req.SetPathValue("customer", "cust-A")
	w := httptest.NewRecorder()
	srv.handleLocalPosCustomerOutstanding(w, req)

	body := w.Body.String()
	if !strings.Contains(body, `"id":"x1"`) || !strings.Contains(body, `"id":"x2"`) {
		t.Errorf("expected both x1 + x2 in body: %s", body)
	}
	if strings.Contains(body, `"id":"x3"`) {
		t.Errorf("closed order should not be in outstanding: %s", body)
	}
	if strings.Contains(body, `"id":"y1"`) {
		t.Errorf("other customer order leaked: %s", body)
	}
	if !strings.Contains(body, `"total_owed":"1300"`) {
		t.Errorf("total_owed should be 800+500=1300: %s", body)
	}
}

func TestHandleLocalPosCustomerOutstanding_EmptyWhenNoOrders(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	srv.orders = service.NewOrderEngine(db, 10)
	_ = db

	req := httptest.NewRequest("GET", "/api/v1/pos/customers/nobody/outstanding", nil)
	req.SetPathValue("customer", "nobody")
	w := httptest.NewRecorder()
	srv.handleLocalPosCustomerOutstanding(w, req)

	body := w.Body.String()
	if !strings.Contains(body, `"data":[]`) || !strings.Contains(body, `"total_owed":"0"`) {
		t.Errorf("empty result mismatch: %s", body)
	}
}

func TestHandleLocalPosFindOrCreateCustomer_HitsLookupOnExisting(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	srv.orders = service.NewOrderEngine(db, 10)

	mustExec(t, db, `INSERT INTO customers (id, first_name, full_name, phone) VALUES ('c1','Alice','Alice','090-1111')`)

	req := httptest.NewRequest("POST", "/api/v1/pos/customers/find-or-create",
		strings.NewReader(`{"phone":"090-1111"}`))
	w := httptest.NewRecorder()
	srv.handleLocalPosFindOrCreateCustomer(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("want 200 (existing), got %d body=%s", w.Code, w.Body.String())
	}
	body := w.Body.String()
	if !strings.Contains(body, `"created":false`) || !strings.Contains(body, `"id":"c1"`) {
		t.Errorf("expected existing-row response: %s", body)
	}
}

func TestHandleLocalPosFindOrCreateCustomer_CreatesWhenAbsent(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	srv.orders = service.NewOrderEngine(db, 10)
	_ = db

	req := httptest.NewRequest("POST", "/api/v1/pos/customers/find-or-create",
		strings.NewReader(`{"phone":"090-9999"}`))
	w := httptest.NewRecorder()
	srv.handleLocalPosFindOrCreateCustomer(w, req)

	if w.Code != http.StatusCreated {
		t.Errorf("want 201 (created), got %d body=%s", w.Code, w.Body.String())
	}
	if !strings.Contains(w.Body.String(), `"created":true`) {
		t.Errorf("response should flag created=true: %s", w.Body.String())
	}
}

func TestHandleLocalPosMenuProducts_PaginatedAndShaped(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	srv.orders = service.NewOrderEngine(db, 10)

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m1','Lunch','published',0)`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Pho')`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p2','Bun')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, selling_price) VALUES ('s1','p1','Bowl',1000)`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, selling_price) VALUES ('s2','p2','Bowl',2000)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mp1','m1','p1',1)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mp2','m1','p2',2)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1/products?page=1&per_page=10", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuProducts(w, req)

	body := w.Body.String()
	for _, frag := range []string{
		`"id":"mp1"`,
		`"id":"mp2"`,
		`"product"`,
		`"name":"Pho"`,
		`"skus"`,
		`"selling_price":1000`,
		`"total":2`,
	} {
		if !strings.Contains(body, frag) {
			t.Errorf("missing %q in %s", frag, body)
		}
	}
}

func TestHandleLocalPosMenuProducts_FilteredBySearch(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	srv.orders = service.NewOrderEngine(db, 10)

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1','Lunch','published')`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Pho Bo')`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p2','Bun Cha')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, selling_price) VALUES ('s1','p1','x',1)`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, selling_price) VALUES ('s2','p2','x',2)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mp1','m1','p1',1)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES ('mp2','m1','p2',2)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1/products?search=Pho", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuProducts(w, req)

	body := w.Body.String()
	if !strings.Contains(body, `"name":"Pho Bo"`) {
		t.Errorf("expected Pho in results: %s", body)
	}
	if strings.Contains(body, `"name":"Bun Cha"`) {
		t.Errorf("Bun should be filtered out: %s", body)
	}
	if !strings.Contains(body, `"total":1`) {
		t.Errorf("total should be 1: %s", body)
	}
}
