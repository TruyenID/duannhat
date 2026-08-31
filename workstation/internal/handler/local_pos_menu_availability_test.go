package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// plan-056 — the LAN "Tồn món" screen.
//
// The tests that matter most here are NOT the ones proving the new screen
// works. They are the ones proving the ORDERING screen did not change: the
// feed now carries turned-off dishes, and every read path had to grow a gate
// to keep them away from the cart picker. A gate that is missing produces no
// error — it just offers a customer food the shop does not have.

// seedAvailabilityFixture builds one menu, one section, two dishes, and two
// variants on the first dish. Everything starts ON.
func seedAvailabilityFixture(t *testing.T, srv *Server) {
	t.Helper()
	db := srv.db

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m1','Lunch','published',0)`)
	mustExec(t, db, `INSERT INTO pos_menu_sections (id, menu_id, name, sort_order) VALUES ('sec1','m1','Mains',0)`)

	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p1','Pho')`)
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('p2','Com ga')`)

	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sk1','p1','Small','PHO-S',1000)`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sk2','p1','Large','PHO-L',1400)`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sk3','p2','Regular','COM-1',1200)`)

	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, menu_section_id, is_active, display_order) VALUES ('mp1','m1','p1','sec1',1,1)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, menu_section_id, is_active, display_order) VALUES ('mp2','m1','p2','sec1',1,2)`)

	// One option AXIS with two values, so the fixture exercises the option-value
	// switch strip. Without it `options` comes back empty on every SKU and the
	// contract test below has nothing to assert against — a fixture that is
	// silently too simple reads as coverage it does not have.
	mustExec(t, db, `INSERT INTO pos_product_options (id, product_id, key, name, position, is_active) VALUES ('opt-size','p1','size','Size',1,1)`)
	mustExec(t, db, `INSERT INTO pos_product_option_values (id, option_id, value, label, position, is_active) VALUES ('ov-s','opt-size','small','Small',0,1)`)
	mustExec(t, db, `INSERT INTO pos_product_option_values (id, option_id, value, label, position, is_active) VALUES ('ov-l','opt-size','large','Large',1,1)`)
	mustExec(t, db, `UPDATE pos_product_skus SET option_value1_id = 'ov-s' WHERE id = 'sk1'`)
	mustExec(t, db, `UPDATE pos_product_skus SET option_value1_id = 'ov-l' WHERE id = 'sk2'`)

	mustExec(t, db, `INSERT INTO pos_menu_product_skus (id, menu_product_id, product_sku_id, is_active, selling_price) VALUES ('mps1','mp1','sk1',1,1000)`)
	mustExec(t, db, `INSERT INTO pos_menu_product_skus (id, menu_product_id, product_sku_id, is_active, selling_price) VALUES ('mps2','mp1','sk2',1,1400)`)
	mustExec(t, db, `INSERT INTO pos_menu_product_skus (id, menu_product_id, product_sku_id, is_active, selling_price) VALUES ('mps3','mp2','sk3',1,1200)`)
}

func availabilityRequest(t *testing.T, srv *Server, method, path string, pathValues map[string]string, body string) *httptest.ResponseRecorder {
	t.Helper()
	var reader *strings.Reader
	if body == "" {
		reader = strings.NewReader("")
	} else {
		reader = strings.NewReader(body)
	}
	req := httptest.NewRequest(method, path, reader)
	for k, v := range pathValues {
		req.SetPathValue(k, v)
	}
	w := httptest.NewRecorder()

	switch {
	case method == http.MethodGet && strings.HasSuffix(path, "/menus"):
		srv.handleLocalPosAvailabilityMenus(w, req)
	case method == http.MethodGet:
		srv.handleLocalPosAvailabilityMenuDetail(w, req)
	// BEFORE the generic /bulk arm: `strings.Contains(path, "/bulk")` matches
	// the sku-bulk path too, and the section handler would answer it.
	case strings.HasSuffix(path, "/skus/bulk"):
		srv.handleLocalPosBulkSkuAvailability(w, req)
	case strings.Contains(path, "/bulk"):
		srv.handleLocalPosBulkSectionAvailability(w, req)
	case strings.Contains(path, "/toppings/"):
		srv.handleLocalPosSetToppingAvailability(w, req)
	case strings.Contains(path, "/skus/"):
		srv.handleLocalPosSetSkuAvailability(w, req)
	default:
		srv.handleLocalPosSetProductAvailability(w, req)
	}

	return w
}

func decodeProducts(t *testing.T, w *httptest.ResponseRecorder) []map[string]any {
	t.Helper()
	var wrap struct {
		Data struct {
			Products []map[string]any `json:"products"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &wrap); err != nil {
		t.Fatalf("decode: %v — body=%s", err, w.Body.String())
	}

	return wrap.Data.Products
}

// =========================================================================
//  The management screen sees what the ordering screen hides
// =========================================================================

func TestAvailabilityDetail_ListsTurnedOffDishWithReason(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	w := availabilityRequest(t, srv, http.MethodPut, "/api/v1/pos/menu-availability/products/mp2",
		map[string]string{"menuProduct": "mp2"},
		`{"is_active":false,"reason":"Hết hàng","actor_name":"Ann"}`)
	if w.Code != http.StatusOK {
		t.Fatalf("turn off: want 200, got %d body=%s", w.Code, w.Body.String())
	}

	got := decodeProducts(t, availabilityRequest(t, srv, http.MethodGet,
		"/api/v1/pos/menu-availability/menus/m1", map[string]string{"menu": "m1"}, ""))
	if len(got) != 2 {
		t.Fatalf("management view must still list the turned-off dish, got %d", len(got))
	}

	var off map[string]any
	for _, p := range got {
		if p["id"] == "mp2" {
			off = p
		}
	}
	if off == nil {
		t.Fatal("mp2 missing from the management view")
	}
	if off["is_active"] != false {
		t.Errorf("is_active = %v, want false", off["is_active"])
	}
	if off["disabled_reason"] != "Hết hàng" {
		t.Errorf("disabled_reason = %v, want Hết hàng", off["disabled_reason"])
	}
	if off["disabled_by_name"] != "Ann" {
		t.Errorf("disabled_by_name = %v, want Ann", off["disabled_by_name"])
	}
}

func TestOrderingScreen_HidesWhatManagementShows(t *testing.T) {
	// The contract of the whole feature in one test.
	srv, _ := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	availabilityRequest(t, srv, http.MethodPut, "/api/v1/pos/menu-availability/products/mp2",
		map[string]string{"menuProduct": "mp2"}, `{"is_active":false,"reason":"Hết"}`)

	// Management: 2 dishes.
	if got := decodeProducts(t, availabilityRequest(t, srv, http.MethodGet,
		"/api/v1/pos/menu-availability/menus/m1", map[string]string{"menu": "m1"}, "")); len(got) != 2 {
		t.Fatalf("management: want 2 dishes, got %d", len(got))
	}

	// Ordering (menu detail): 1 dish.
	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuDetailLocal(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("ordering detail: want 200, got %d body=%s", w.Code, w.Body.String())
	}
	var wrap struct {
		Data struct {
			MenuProducts []map[string]any `json:"menu_products"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &wrap); err != nil {
		t.Fatalf("decode: %v", err)
	}
	for _, p := range wrap.Data.MenuProducts {
		if p["id"] == "mp2" {
			t.Fatal("a turned-off dish reached the ORDERING screen — a customer would be offered food the shop does not have")
		}
	}
	if len(wrap.Data.MenuProducts) != 1 {
		t.Fatalf("ordering: want 1 dish, got %d", len(wrap.Data.MenuProducts))
	}
}

func TestOrderingProductList_ExcludesTurnedOffDish_AndCountsMatch(t *testing.T) {
	// COUNT and SELECT must carry the same gate, or a page claims N results and
	// renders fewer — pagination that lies is worse than pagination that is slow.
	srv, _ := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	availabilityRequest(t, srv, http.MethodPut, "/api/v1/pos/menu-availability/products/mp2",
		map[string]string{"menuProduct": "mp2"}, `{"is_active":false}`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1/products", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuProducts(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}

	var wrap struct {
		Data []map[string]any `json:"data"`
		Meta struct {
			Total int `json:"total"`
		} `json:"meta"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &wrap); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if len(wrap.Data) != 1 {
		t.Fatalf("want 1 dish on the ordering list, got %d", len(wrap.Data))
	}
	if wrap.Meta.Total != 1 {
		t.Errorf("meta.total = %d, want 1 — COUNT and SELECT disagree", wrap.Meta.Total)
	}
}

// =========================================================================
//  Variants
// =========================================================================

func TestVariantToggle_HidesOnlyThatVariantFromOrdering(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	w := availabilityRequest(t, srv, http.MethodPut, "/api/v1/pos/menu-availability/skus/mps2",
		map[string]string{"menuProductSku": "mps2"}, `{"is_active":false,"reason":"Hết size L"}`)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1", nil)
	req.SetPathValue("menu", "m1")
	rec := httptest.NewRecorder()
	srv.handleLocalPosMenuDetailLocal(rec, req)

	var wrap struct {
		Data struct {
			MenuProducts []struct {
				ID   string           `json:"id"`
				Skus []map[string]any `json:"skus"`
			} `json:"menu_products"`
		} `json:"data"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &wrap); err != nil {
		t.Fatalf("decode: %v", err)
	}

	for _, mp := range wrap.Data.MenuProducts {
		if mp.ID != "mp1" {
			continue
		}
		if len(mp.Skus) != 1 {
			t.Fatalf("ordering: want 1 sellable variant on mp1, got %d", len(mp.Skus))
		}
		if mp.Skus[0]["product_sku_id"] != "sk1" {
			t.Errorf("wrong variant survived: %v", mp.Skus[0]["product_sku_id"])
		}
	}

	// …and the dish itself is untouched.
	if len(wrap.Data.MenuProducts) != 2 {
		t.Errorf("turning a variant off must not remove the dish, got %d dishes", len(wrap.Data.MenuProducts))
	}
}

func TestVariantWithoutPivotRow_StaysVisibleToOrdering(t *testing.T) {
	// REGRESSION GUARD for the LEFT JOIN.
	//
	// HQ adds a SKU to a product AFTER the branch cloned the menu: the catalog
	// row exists, the pivot row does not. That variant is on sale today. An
	// INNER JOIN on the pivot would make it silently vanish from the cart
	// picker — a regression nobody would trace back to an availability feature.
	srv, db := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price) VALUES ('sk9','p1','XL','PHO-XL',1800)`)
	// Deliberately NO pos_menu_product_skus row for sk9.

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuDetailLocal(w, req)

	var wrap struct {
		Data struct {
			MenuProducts []struct {
				ID   string           `json:"id"`
				Skus []map[string]any `json:"skus"`
			} `json:"menu_products"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &wrap); err != nil {
		t.Fatalf("decode: %v", err)
	}

	found := false
	for _, mp := range wrap.Data.MenuProducts {
		if mp.ID != "mp1" {
			continue
		}
		for _, s := range mp.Skus {
			if s["product_sku_id"] == "sk9" {
				found = true
				if s["menu_product_sku_id"] != nil {
					t.Errorf("a pivot-less variant must report a null write address, got %v", s["menu_product_sku_id"])
				}
			}
		}
	}
	if !found {
		t.Fatal("a catalog SKU with no pivot row disappeared from the ordering screen (INNER JOIN regression)")
	}
}

func TestSearch_DoesNotSurfaceParentViaTurnedOffVariantCode(t *testing.T) {
	// Typing the barcode of a size the shop has run out of must not put the
	// parent dish back in front of the cashier. The EXISTS clause gates on the
	// variant's menu-level availability for exactly this.
	srv, _ := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	availabilityRequest(t, srv, http.MethodPut, "/api/v1/pos/menu-availability/skus/mps2",
		map[string]string{"menuProductSku": "mps2"}, `{"is_active":false}`)

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1/products?search=PHO-L", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuProducts(w, req)

	var wrap struct {
		Data []map[string]any `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &wrap); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if len(wrap.Data) != 0 {
		t.Fatalf("searching a sold-out variant's code surfaced %d dishes on the SELLING screen", len(wrap.Data))
	}

	// The still-available size must remain findable — the gate is per variant,
	// not per product.
	req2 := httptest.NewRequest("GET", "/api/v1/pos/menus/m1/products?search=PHO-S", nil)
	req2.SetPathValue("menu", "m1")
	w2 := httptest.NewRecorder()
	srv.handleLocalPosMenuProducts(w2, req2)
	var wrap2 struct {
		Data []map[string]any `json:"data"`
	}
	json.Unmarshal(w2.Body.Bytes(), &wrap2)
	if len(wrap2.Data) != 1 {
		t.Fatalf("the available size stopped being findable: got %d", len(wrap2.Data))
	}
}

// =========================================================================
//  Writes
// =========================================================================

func TestSetAvailability_IsIdempotentAndQueuesOneOpPerCall(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	for range 3 {
		w := availabilityRequest(t, srv, http.MethodPut, "/api/v1/pos/menu-availability/products/mp1",
			map[string]string{"menuProduct": "mp1"}, `{"is_active":false,"reason":"Hết"}`)
		if w.Code != http.StatusOK {
			t.Fatalf("want 200, got %d", w.Code)
		}
	}

	var active, pending int
	if err := db.QueryRow(`
		SELECT is_active, pending_sync FROM pos_menu_availability_overrides
		WHERE entity_type='menu_product' AND entity_id='mp1'`).Scan(&active, &pending); err != nil {
		t.Fatalf("override row missing: %v", err)
	}
	if active != 0 {
		t.Errorf("is_active = %d after three identical writes, want 0 — a toggle would have flipped it back", active)
	}
	if pending != 1 {
		t.Errorf("pending_sync = %d, want 1", pending)
	}

	// One override row, not three.
	var rows int
	db.QueryRow(`SELECT COUNT(*) FROM pos_menu_availability_overrides`).Scan(&rows)
	if rows != 1 {
		t.Errorf("override rows = %d, want 1", rows)
	}
}

func TestSetAvailability_QueuesSyncOpWithOperatorTimestamp(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	availabilityRequest(t, srv, http.MethodPut, "/api/v1/pos/menu-availability/products/mp1",
		map[string]string{"menuProduct": "mp1"}, `{"is_active":false,"reason":"Hết hàng","actor_name":"Ann","actor_user_id":"u-1"}`)

	var entityType, entityID, operation, payload string
	err := db.QueryRow(`
		SELECT entity_type, entity_id, operation, payload
		FROM sync_queue ORDER BY id DESC LIMIT 1`).Scan(&entityType, &entityID, &operation, &payload)
	if err != nil {
		t.Fatalf("no sync op queued: %v", err)
	}
	// The dispatch key is entity_type + "." + operation. A mismatch here is the
	// #534 failure: pushToCloud finds no handler and drains the row as a
	// silent success, so the shop's "we are out of this" never reaches Cloud.
	if entityType != "menu_product" || operation != "availability" {
		t.Fatalf("dispatch key = %q.%q, want menu_product.availability", entityType, operation)
	}
	if entityID != "mp1" {
		t.Errorf("entity_id = %q, want mp1", entityID)
	}
	for _, want := range []string{`"is_active":false`, `"reason":"Hết hàng"`, `"actor_name":"Ann"`, `"occurred_at"`} {
		if !strings.Contains(payload, want) {
			t.Errorf("payload missing %s: %s", want, payload)
		}
	}
}

func TestSetAvailability_TurningBackOnClearsTheReason(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	availabilityRequest(t, srv, http.MethodPut, "/api/v1/pos/menu-availability/products/mp1",
		map[string]string{"menuProduct": "mp1"}, `{"is_active":false,"reason":"Hết hàng","actor_name":"Ann"}`)
	availabilityRequest(t, srv, http.MethodPut, "/api/v1/pos/menu-availability/products/mp1",
		map[string]string{"menuProduct": "mp1"}, `{"is_active":true}`)

	var reason, actor any
	var active int
	if err := db.QueryRow(`
		SELECT is_active, reason, actor_name FROM pos_menu_availability_overrides
		WHERE entity_type='menu_product' AND entity_id='mp1'`).Scan(&active, &reason, &actor); err != nil {
		t.Fatalf("scan: %v", err)
	}
	if active != 1 {
		t.Errorf("is_active = %d, want 1", active)
	}
	// A leftover "hết hàng" on a dish that IS on sale reads as a defect in the
	// shop's stock, not in us.
	if reason != nil {
		t.Errorf("reason survived re-enable: %v", reason)
	}
	if actor != nil {
		t.Errorf("actor survived re-enable: %v", actor)
	}
}

func TestSetAvailability_RejectsMissingIsActive(t *testing.T) {
	// A pointer, not a bool: an ABSENT flag decoding to false would take a dish
	// off the menu because a client forgot a field.
	srv, _ := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	w := availabilityRequest(t, srv, http.MethodPut, "/api/v1/pos/menu-availability/products/mp1",
		map[string]string{"menuProduct": "mp1"}, `{"reason":"oops"}`)
	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("want 422, got %d body=%s", w.Code, w.Body.String())
	}
}

func TestSetAvailability_AcceptsOneCharacterReasonAndTruncatesLongOnes(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	if w := availabilityRequest(t, srv, http.MethodPut, "/api/v1/pos/menu-availability/products/mp1",
		map[string]string{"menuProduct": "mp1"}, `{"is_active":false,"reason":"X"}`); w.Code != http.StatusOK {
		t.Fatalf("a one-character reason was rejected: %d %s", w.Code, w.Body.String())
	}

	// Over-long text is TRUNCATED, never rejected: the toggle is the point, the
	// words are metadata, and 255 matches the Cloud column so the sync op
	// cannot 422 later.
	long := strings.Repeat("ế", 400)
	if w := availabilityRequest(t, srv, http.MethodPut, "/api/v1/pos/menu-availability/products/mp2",
		map[string]string{"menuProduct": "mp2"}, `{"is_active":false,"reason":"`+long+`"}`); w.Code != http.StatusOK {
		t.Fatalf("a long reason was rejected: %d", w.Code)
	}
	var stored string
	db.QueryRow(`SELECT reason FROM pos_menu_availability_overrides WHERE entity_id='mp2'`).Scan(&stored)
	if n := len([]rune(stored)); n != 255 {
		t.Errorf("stored reason = %d runes, want 255 (truncated by RUNE, not byte)", n)
	}
}

func TestSetAvailability_404sUnknownIDs(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	if w := availabilityRequest(t, srv, http.MethodPut, "/api/v1/pos/menu-availability/products/nope",
		map[string]string{"menuProduct": "nope"}, `{"is_active":false}`); w.Code != http.StatusNotFound {
		t.Errorf("dish: want 404, got %d", w.Code)
	}
	if w := availabilityRequest(t, srv, http.MethodPut, "/api/v1/pos/menu-availability/skus/nope",
		map[string]string{"menuProductSku": "nope"}, `{"is_active":false}`); w.Code != http.StatusNotFound {
		t.Errorf("variant: want 404, got %d", w.Code)
	}
}

// =========================================================================
//  Bulk
// =========================================================================

func TestBulkSection_ReportsRealChangesAndQueuesExplicitIDs(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	// mp2 already off — the toast must say "1", not "2". A number staff learn
	// to distrust is worse than no number.
	availabilityRequest(t, srv, http.MethodPut, "/api/v1/pos/menu-availability/products/mp2",
		map[string]string{"menuProduct": "mp2"}, `{"is_active":false}`)

	w := availabilityRequest(t, srv, http.MethodPost,
		"/api/v1/pos/menu-availability/menus/m1/sections/sec1/bulk",
		map[string]string{"menu": "m1", "menuSection": "sec1"},
		`{"is_active":false,"reason":"Hết nguyên liệu"}`)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}

	var out struct {
		Updated int `json:"updated"`
	}
	json.Unmarshal(w.Body.Bytes(), &out)
	if out.Updated != 1 {
		t.Errorf("updated = %d, want 1 (only mp1 actually moved)", out.Updated)
	}

	var entityType, operation, payload string
	if err := db.QueryRow(`
		SELECT entity_type, operation, payload FROM sync_queue
		WHERE operation = 'bulk' ORDER BY id DESC LIMIT 1`).Scan(&entityType, &operation, &payload); err != nil {
		t.Fatalf("no bulk op queued: %v", err)
	}
	if entityType != "menu_availability" {
		t.Errorf("dispatch key = %q.%q, want menu_availability.bulk", entityType, operation)
	}
	// EXPLICIT ids, never the section name: a replay hours later must not reach
	// dishes HQ moved into the section in the meantime.
	for _, want := range []string{`"mp1"`, `"mp2"`, `"menu_product_ids"`} {
		if !strings.Contains(payload, want) {
			t.Errorf("payload missing %s: %s", want, payload)
		}
	}
}

func TestBulkSection_AppliesAtomicallyToEveryRow(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	availabilityRequest(t, srv, http.MethodPost,
		"/api/v1/pos/menu-availability/menus/m1/sections/sec1/bulk",
		map[string]string{"menu": "m1", "menuSection": "sec1"},
		`{"is_active":false,"reason":"Đóng bếp"}`)

	var n int
	db.QueryRow(`SELECT COUNT(*) FROM pos_menu_availability_overrides WHERE is_active = 0`).Scan(&n)
	if n != 2 {
		t.Fatalf("overrides written = %d, want 2 — a half-applied section is a screen nobody can reason about", n)
	}

	// And the ordering screen is now empty for that menu.
	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1", nil)
	req.SetPathValue("menu", "m1")
	rec := httptest.NewRecorder()
	srv.handleLocalPosMenuDetailLocal(rec, req)
	var wrap struct {
		Data struct {
			MenuProducts []map[string]any `json:"menu_products"`
		} `json:"data"`
	}
	json.Unmarshal(rec.Body.Bytes(), &wrap)
	if len(wrap.Data.MenuProducts) != 0 {
		t.Errorf("ordering screen still offers %d dishes after the section was turned off", len(wrap.Data.MenuProducts))
	}
}

func TestBulkSection_404sEmptySection(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	w := availabilityRequest(t, srv, http.MethodPost,
		"/api/v1/pos/menu-availability/menus/m1/sections/ghost/bulk",
		map[string]string{"menu": "m1", "menuSection": "ghost"},
		`{"is_active":false}`)
	if w.Code != http.StatusNotFound {
		t.Errorf("want 404, got %d", w.Code)
	}
}

// =========================================================================
//  Menu list
// =========================================================================

func TestAvailabilityMenus_ListsEveryStatus(t *testing.T) {
	// A shop turns dishes off in tomorrow's menu as readily as today's.
	srv, db := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)
	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m2','Dinner','draft',1)`)

	w := availabilityRequest(t, srv, http.MethodGet, "/api/v1/pos/menu-availability/menus", nil, "")
	var wrap struct {
		Data []map[string]any `json:"data"`
	}
	json.Unmarshal(w.Body.Bytes(), &wrap)
	if len(wrap.Data) != 2 {
		t.Fatalf("want 2 menus regardless of status, got %d", len(wrap.Data))
	}
}

// =========================================================================
//  Variant labelling
// =========================================================================

func TestVariantLabel_PrefersOptionValuesThenNameThenNull(t *testing.T) {
	// A shop's simple products carry ONE SKU with no name and no option axis.
	// A client reading `name` alone rendered a column of identical "(variant)"
	// placeholders — noise that buried the two or three dishes where size
	// actually matters. `variant_label` is resolved here so the LAN and Cloud
	// answer the same way and the client never has to invent a fallback.
	srv, db := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	// sk1 keeps its own name ("Small"); sk2 gets an option axis; sk3 gets
	// neither — the simple-product shape.
	mustExec(t, db, `INSERT INTO pos_product_options (id, product_id, key, name, position, is_active) VALUES ('opt1','p1','size','Size',1,1)`)
	mustExec(t, db, `INSERT INTO pos_product_option_values (id, option_id, value, label, position, is_active) VALUES ('ov1','opt1','L','Lớn',1,1)`)
	mustExec(t, db, `UPDATE pos_product_skus SET option_value1_id = 'ov1' WHERE id = 'sk2'`)
	mustExec(t, db, `UPDATE pos_product_skus SET name = '' WHERE id = 'sk3'`)

	got := decodeProducts(t, availabilityRequest(t, srv, http.MethodGet,
		"/api/v1/pos/menu-availability/menus/m1", map[string]string{"menu": "m1"}, ""))

	labels := map[string]any{}
	for _, p := range got {
		skus, _ := p["skus"].([]any)
		for _, raw := range skus {
			s, _ := raw.(map[string]any)
			labels[s["product_sku_id"].(string)] = s["variant_label"]
		}
	}

	if labels["sk2"] != "Lớn" {
		t.Errorf("option axis must win: sk2 label = %v, want Lớn", labels["sk2"])
	}
	if labels["sk1"] != "Small" {
		t.Errorf("SKU name is the fallback: sk1 label = %v, want Small", labels["sk1"])
	}
	// NULL, not a placeholder. The client renders the SKU code instead, which
	// is something a cashier can match against a package.
	if labels["sk3"] != nil {
		t.Errorf("nothing to say must be null, got %v", labels["sk3"])
	}
}

// =========================================================================
//  Toppings
// =========================================================================

func seedToppingFixture(t *testing.T, srv *Server) {
	t.Helper()
	db := srv.db
	mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES ('tp1','Trung chan')`)
	mustExec(t, db, `INSERT INTO pos_topping_groups (id, name, selection_type, modifier_type, price_strategy, min_select, max_qty_per_item, sort_order, is_active) VALUES ('tg1','Topping pho','multiple','add','flat',0,1,1,1)`)
	mustExec(t, db, `INSERT INTO pos_product_topping_groups (product_id, topping_group_id, sort_order) VALUES ('p1','tg1',1)`)
	mustExec(t, db, `INSERT INTO pos_topping_group_items (id, topping_group_id, product_id, sort_order, is_default) VALUES ('ti1','tg1','tp1',1,0)`)
	// Two base rows: one bound to a real SKU, one the no-SKU fallback. The
	// SKU-bound row is what made a wildcard hide silently ineffective.
	mustExec(t, db, `INSERT INTO pos_topping_group_item_skus (id, topping_group_item_id, product_sku_id, extra_price) VALUES ('tis1','ti1','sk1',120)`)
}

func TestTopping_WildcardHideFromCloudHidesTheWholeItem(t *testing.T) {
	// REGRESSION — this was broken before plan-056 and nobody had noticed.
	//
	// Cloud reads the shop override at the ITEM level, so a wildcard row
	// (product_sku_id NULL) hid the topping there. The LAN keyed tier-1
	// strictly by product_sku_id, so on any topping with SKU-bound rows the
	// wildcard matched nothing and the topping stayed on offer. Same data, two
	// answers: hidden online, still sellable on the shop's own tablets.
	srv, db := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)
	seedToppingFixture(t, srv)

	mustExec(t, db, `
		INSERT INTO pos_menu_product_topping_overrides
			(id, menu_product_id, topping_group_id, topping_group_item_id, product_sku_id, is_hidden, override_price)
		VALUES ('ov1','mp1','tg1','ti1',NULL,1,NULL)`)

	groups, err := srv.loadProductToppingGroups("mp1", "p1", "vi", false)
	if err != nil {
		t.Fatalf("load: %v", err)
	}
	for _, g := range groups {
		items, _ := g["items"].([]map[string]any)
		if len(items) != 0 {
			t.Fatalf("a wildcard-hidden topping is still on offer on the LAN: %v", items)
		}
	}
}

func TestTopping_ManagementReadKeepsHiddenItemsVisible(t *testing.T) {
	// The management screen must SEE what the ordering screen hides, or the
	// shop can never switch the topping back on.
	srv, db := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)
	seedToppingFixture(t, srv)
	mustExec(t, db, `
		INSERT INTO pos_menu_product_topping_overrides
			(id, menu_product_id, topping_group_id, topping_group_item_id, product_sku_id, is_hidden, override_price)
		VALUES ('ov1','mp1','tg1','ti1',NULL,1,NULL)`)

	groups, err := srv.loadProductToppingGroups("mp1", "p1", "vi", true)
	if err != nil {
		t.Fatalf("load: %v", err)
	}

	found := false
	for _, g := range groups {
		items, _ := g["items"].([]map[string]any)
		for _, it := range items {
			if it["id"] == "ti1" {
				found = true
				if it["is_hidden"] != true {
					t.Errorf("is_hidden = %v, want true", it["is_hidden"])
				}
			}
		}
	}
	if !found {
		t.Fatal("the hidden topping is invisible to the management read — unfixable from the POS")
	}
}

func TestTopping_LocalToggleTakesEffectBeforeItReachesCloud(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)
	seedToppingFixture(t, srv)

	w := availabilityRequest(t, srv, http.MethodPut,
		"/api/v1/pos/menu-availability/products/mp1/toppings/ti1",
		map[string]string{"menuProduct": "mp1", "toppingItem": "ti1"},
		`{"is_active":false,"reason":"Hết trứng"}`)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}

	// Gone from the ordering read immediately — no Cloud round trip.
	groups, err := srv.loadProductToppingGroups("mp1", "p1", "vi", false)
	if err != nil {
		t.Fatalf("load: %v", err)
	}
	for _, g := range groups {
		if items, _ := g["items"].([]map[string]any); len(items) != 0 {
			t.Fatal("the topping is still on offer right after being turned off")
		}
	}

	// …and the op is queued under a dispatch key a handler is registered for.
	var entityType, entityID, operation, payload string
	if err := db.QueryRow(`
		SELECT entity_type, entity_id, operation, payload FROM sync_queue
		ORDER BY id DESC LIMIT 1`).Scan(&entityType, &entityID, &operation, &payload); err != nil {
		t.Fatalf("no op queued: %v", err)
	}
	if entityType != "topping_item" || operation != "availability" {
		t.Fatalf("dispatch key = %q.%q, want topping_item.availability", entityType, operation)
	}
	if entityID != "mp1:ti1" {
		t.Errorf("entity_id = %q, want the mp:item composite", entityID)
	}
	for _, want := range []string{`"menu_product_id":"mp1"`, `"topping_group_item_id":"ti1"`, `"is_active":false`} {
		if !strings.Contains(payload, want) {
			t.Errorf("payload missing %s: %s", want, payload)
		}
	}
}

func TestTopping_404sUnknownIDs(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)
	seedToppingFixture(t, srv)

	if w := availabilityRequest(t, srv, http.MethodPut,
		"/api/v1/pos/menu-availability/products/mp1/toppings/nope",
		map[string]string{"menuProduct": "mp1", "toppingItem": "nope"},
		`{"is_active":false}`); w.Code != http.StatusNotFound {
		t.Errorf("unknown topping: want 404, got %d", w.Code)
	}
}

// TestTopping_ManagementReadSpeaksTheClientVocabulary is a CONTRACT test, not a
// behaviour test.
//
// It exists because a field-name divergence between the two servers shipped
// undetected: Cloud's management endpoint emits `is_active` for a topping item,
// this one emitted only `is_hidden`, and pos-web reads `is_active`. On the LAN —
// the transport most shops actually run — every topping therefore rendered as
// switched OFF.
//
// Nothing caught it. The Go test asserted the Go shape. The vitest fixture was
// written from the Cloud shape. The route-parity test compares URLs, not
// payloads. So the guard has to be exactly this: the field names the TypeScript
// client destructures, asserted against the real response.
func TestTopping_ManagementReadSpeaksTheClientVocabulary(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)
	seedToppingFixture(t, srv)

	got := decodeProducts(t, availabilityRequest(t, srv, http.MethodGet,
		"/api/v1/pos/menu-availability/menus/m1", map[string]string{"menu": "m1"}, ""))

	var checked, checkedSkus int
	for _, p := range got {
		groups, _ := p["topping_groups"].([]any)
		for _, rawGroup := range groups {
			g, _ := rawGroup.(map[string]any)
			// AvailabilityToppingGroup — id, name, items.
			for _, field := range []string{"id", "name", "items"} {
				if _, ok := g[field]; !ok {
					t.Errorf("topping group is missing %q — the client cannot render it", field)
				}
			}

			items, _ := g["items"].([]any)
			for _, rawItem := range items {
				it, _ := rawItem.(map[string]any)
				// AvailabilityToppingItem — id, name, is_active.
				for _, field := range []string{"id", "name", "is_active"} {
					if _, ok := it[field]; !ok {
						t.Errorf("topping item is missing %q — this is the exact field whose absence rendered every topping as OFF", field)
					}
				}
				// And the two spellings must never disagree.
				if hidden, ok := it["is_hidden"].(bool); ok {
					if active, ok := it["is_active"].(bool); ok && active == hidden {
						t.Errorf("is_active (%v) and is_hidden (%v) contradict each other", active, hidden)
					}
				}

				// AvailabilityToppingSku — the expandable add-on price table.
				// Same class of divergence one level deeper: a missing field
				// here renders an empty cell rather than a wrong switch, but
				// it is invisible in exactly the same way, and Cloud emits
				// these five names.
				skus, ok := it["skus"].([]any)
				if !ok {
					t.Errorf("topping item %v is missing %q", it["id"], "skus")
				}
				for _, rawSku := range skus {
					sku, _ := rawSku.(map[string]any)
					for _, field := range []string{
						"id", "product_sku_id", "sku_label", "sku_code", "extra_price",
					} {
						if _, ok := sku[field]; !ok {
							t.Errorf("topping sku is missing %q", field)
						}
					}
					// A STRING on both transports. A number here would make
					// the client's Number() coercion a no-op on one side and
					// the two would drift the day a price gains a decimal.
					if _, ok := sku["extra_price"].(string); !ok {
						t.Errorf("extra_price is %T, want string — Cloud sends a string", sku["extra_price"])
					}
					checkedSkus++
				}
				checked++
			}
		}
	}

	if checked == 0 {
		t.Fatal("no topping items reached the assertion — the fixture stopped exercising this path")
	}
	if checkedSkus == 0 {
		t.Fatal("no topping SKU rows reached the assertion — the add-on price table is unguarded")
	}
}

// TestAvailabilityDetail_SkuShapeMatchesTheClient is the same guard one level
// up: the fields the variant table destructures, asserted against the real
// response rather than against a fixture somebody wrote by hand.
func TestAvailabilityDetail_SkuShapeMatchesTheClient(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	got := decodeProducts(t, availabilityRequest(t, srv, http.MethodGet,
		"/api/v1/pos/menu-availability/menus/m1", map[string]string{"menu": "m1"}, ""))

	var checked int
	for _, p := range got {
		for _, field := range []string{
			"id", "menu_id", "product_id", "menu_section_id", "display_order",
			"is_active", "disabled_reason", "disabled_at", "disabled_by_name",
			"skus", "topping_groups", "product",
		} {
			if _, ok := p[field]; !ok {
				t.Errorf("product is missing %q", field)
			}
		}

		skus, _ := p["skus"].([]any)
		for _, raw := range skus {
			sku, _ := raw.(map[string]any)
			for _, field := range []string{
				"menu_product_sku_id", "product_sku_id", "variant_label", "name",
				"sku", "selling_price", "is_active",
			} {
				if _, ok := sku[field]; !ok {
					t.Errorf("sku is missing %q", field)
				}
			}
			checked++
		}
	}

	if checked == 0 {
		t.Fatal("no sku rows reached the assertion — the fixture stopped exercising this path")
	}
}

// =========================================================================
//  Option values — "hết cỡ Lớn"
// =========================================================================

// TestAvailability_OptionShapeMatchesTheClient is a CONTRACT test.
//
// The screen groups a dish's variants on `value_id` to build one switch per
// option value. A missing key here renders no strip at all on the LAN — the
// transport most shops actually run — and nothing else would notice, which is
// exactly how the topping `is_active` divergence shipped.
func TestAvailability_OptionShapeMatchesTheClient(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	got := decodeProducts(t, availabilityRequest(t, srv, http.MethodGet,
		"/api/v1/pos/menu-availability/menus/m1", map[string]string{"menu": "m1"}, ""))

	checked := 0
	for _, p := range got {
		skus, _ := p["skus"].([]any)
		for _, raw := range skus {
			sku, _ := raw.(map[string]any)
			opts, ok := sku["options"].([]any)
			if !ok {
				t.Fatalf("sku is missing %q — the option strip cannot be built", "options")
			}
			for _, rawOpt := range opts {
				o, _ := rawOpt.(map[string]any)
				// The same five keys Cloud's optionShapes() emits.
				for _, field := range []string{
					"option_id", "option_name", "value_id", "value_label", "position",
				} {
					if _, ok := o[field]; !ok {
						t.Errorf("option is missing %q", field)
					}
				}
				if o["option_name"] != "Size" {
					t.Errorf("option_name = %v, want the option's NAME (falls back to key)", o["option_name"])
				}
				checked++
			}
		}
	}
	if checked == 0 {
		t.Fatal("no option rows reached the assertion — the fixture stopped exercising this path")
	}
}

func TestBulkSkuAvailability_MovesOnlyTheVariantsSent(t *testing.T) {
	// The whole risk this endpoint carries: a bulk write that reaches rows the
	// operator did not select.
	srv, _ := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	w := availabilityRequest(t, srv, http.MethodPost,
		"/api/v1/pos/menu-availability/menus/m1/skus/bulk",
		map[string]string{"menu": "m1"},
		`{"is_active":false,"reason":"Hết tô lớn","menu_product_sku_ids":["mps2"]}`)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	if !strings.Contains(w.Body.String(), `"updated":1`) {
		t.Errorf("want updated=1, got %s", w.Body.String())
	}

	got := decodeProducts(t, availabilityRequest(t, srv, http.MethodGet,
		"/api/v1/pos/menu-availability/menus/m1", map[string]string{"menu": "m1"}, ""))
	for _, p := range got {
		skus, _ := p["skus"].([]any)
		for _, raw := range skus {
			sku, _ := raw.(map[string]any)
			switch sku["menu_product_sku_id"] {
			case "mps2":
				if sku["is_active"] != false {
					t.Errorf("the targeted variant is still on")
				}
			case "mps1", "mps3":
				if sku["is_active"] != true {
					t.Errorf("variant %v moved but was never sent", sku["menu_product_sku_id"])
				}
			}
		}
	}
}

func TestBulkSkuAvailability_DropsIdsFromAnotherMenu(t *testing.T) {
	// A queued op can sit for hours offline. It must never reach rows outside
	// the menu it was recorded against — and one stale id must not fail the
	// whole batch, or the other variants stay stuck behind it.
	srv, db := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)
	mustExec(t, db, `INSERT INTO pos_menus (id, name, status, sort_order) VALUES ('m2','Dinner','published',1)`)
	mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, is_active, display_order) VALUES ('mpX','m2','p2',1,1)`)
	mustExec(t, db, `INSERT INTO pos_menu_product_skus (id, menu_product_id, product_sku_id, is_active, selling_price) VALUES ('mpsX','mpX','sk3',1,1200)`)

	w := availabilityRequest(t, srv, http.MethodPost,
		"/api/v1/pos/menu-availability/menus/m1/skus/bulk",
		map[string]string{"menu": "m1"},
		`{"is_active":false,"menu_product_sku_ids":["mps2","mpsX"]}`)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}

	var foreign int
	if err := db.QueryRow(`SELECT COUNT(*) FROM pos_menu_availability_overrides
		WHERE entity_type='menu_product_sku' AND entity_id='mpsX'`).Scan(&foreign); err != nil {
		t.Fatalf("query: %v", err)
	}
	if foreign != 0 {
		t.Errorf("a variant from another menu was written")
	}
}

func TestBulkSkuAvailability_RefusesAnEmptyList(t *testing.T) {
	// "Turn off nothing" is never what a cashier meant, and a 200 there would
	// leave the switch showing a state no write produced.
	srv, _ := newServerWithAuth(t, "http://unused")
	seedAvailabilityFixture(t, srv)

	w := availabilityRequest(t, srv, http.MethodPost,
		"/api/v1/pos/menu-availability/menus/m1/skus/bulk",
		map[string]string{"menu": "m1"}, `{"is_active":false,"menu_product_sku_ids":[]}`)
	if w.Code != http.StatusBadRequest {
		t.Errorf("want 400, got %d body=%s", w.Code, w.Body.String())
	}
}
