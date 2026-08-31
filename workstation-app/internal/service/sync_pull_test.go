package service

import (
	"context"
	"database/sql"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strconv"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// ─── Test helpers ─────────────────────────────────────────────────────────

func newPullerTestDB(t *testing.T) *store.DB {
	t.Helper()
	db, err := store.Open(filepath.Join(t.TempDir(), "pull.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return db
}

// staticTokenFn returns a closure satisfying SyncPuller's tokenFn parameter.
func staticTokenFn(t string) func() string { return func() string { return t } }

// ─── Zones ────────────────────────────────────────────────────────────────

func TestPullZonesUpsertsFromCloud(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/tms/zones" {
			t.Errorf("unexpected path %s", r.URL.Path)
		}
		if r.Header.Get("Authorization") != "Bearer WS-TOKEN" {
			t.Errorf("wrong auth header: %q", r.Header.Get("Authorization"))
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":[
			{"id":"z1","name":"Khu A","branch_id":"br-1"},
			{"id":"z2","name":"Khu B","branch_id":"br-1"}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	if err := p.PullZones(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var count int
	db.QueryRow("SELECT COUNT(*) FROM zones").Scan(&count)
	if count != 2 {
		t.Errorf("expected 2 zones, got %d", count)
	}

	var name string
	db.QueryRow("SELECT name FROM zones WHERE id = 'z1'").Scan(&name)
	if name != "Khu A" {
		t.Errorf("wrong name for z1: %q", name)
	}
}

func TestPullZonesReplaceAllAtomic(t *testing.T) {
	// Cloud now has only z2 — z1 should be removed locally.
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":[{"id":"z2","name":"Khu B"}]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	db.Exec(`INSERT INTO zones (id, name) VALUES ('z1', 'Stale')`)
	db.Exec(`INSERT INTO zones (id, name) VALUES ('z2', 'Old')`)

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullZones(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var count int
	db.QueryRow("SELECT COUNT(*) FROM zones").Scan(&count)
	if count != 1 {
		t.Errorf("expected 1 zone after replace, got %d", count)
	}

	var name string
	db.QueryRow("SELECT name FROM zones WHERE id = 'z2'").Scan(&name)
	if name != "Khu B" {
		t.Errorf("z2 should be updated to 'Khu B', got %q", name)
	}
}

func TestPullZonesEmptyResponseDoesNotWipe(t *testing.T) {
	// Defensive: empty Cloud response (maybe transient bug) — keep local.
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":[]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	db.Exec(`INSERT INTO zones (id, name) VALUES ('z1', 'Khu A')`)

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	_ = p.PullZones(context.Background())

	var count int
	db.QueryRow("SELECT COUNT(*) FROM zones").Scan(&count)
	if count != 1 {
		t.Errorf("expected local row preserved when Cloud returns empty, got count=%d", count)
	}
}

// ─── Tables ───────────────────────────────────────────────────────────────

func TestPullTablesMapsQrTokenAndCapacity(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/tms/tables" {
			t.Errorf("unexpected path %s", r.URL.Path)
		}
		w.Write([]byte(`{"data":[
			{"id":"t1","code":"A1","name":"Bàn A1","seat_count":4,"status":"free","zone_id":"z1","qr_token":"qr-a1"},
			{"id":"t2","code":"A2","name":"Bàn A2","seat_count":2,"status":"occupied","zone_id":"z1","qr_token":"qr-a2"}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	if err := p.PullTables(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var qr string
	var capacity int
	db.QueryRow("SELECT qr_token, capacity FROM tables WHERE id = 't1'").Scan(&qr, &capacity)
	if qr != "qr-a1" || capacity != 4 {
		t.Errorf("t1 mapping wrong: qr=%q capacity=%d (expected qr-a1, 4)", qr, capacity)
	}
}

// ─── Branch + shop_settings flatten ───────────────────────────────────────

func TestPullBranchUpsertsBranchAndFlattensSettings(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/branch" {
			t.Errorf("unexpected path %s", r.URL.Path)
		}
		w.Write([]byte(`{"data":{
			"id":"br-1","slug":"qpb","name":"Quán Phở Bò",
			"currency":"JPY","timezone":"Asia/Tokyo","locale":"ja",
			"cart_timeout_minutes":5,
			"settings":{
				"tax_rate":"10.00","service_charge_rate":"5.00","currency_code":"JPY","enable_quick_order":true
			}
		}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	// `branches` table lives under migrations/omnify/, which is only wired by
	// cmd/workstation/main.go (production). Tests run with hand-written
	// migrations only — recreate the minimum schema we need here.
	if _, err := db.Exec(`
		CREATE TABLE IF NOT EXISTS branches (
			id TEXT PRIMARY KEY,
			console_branch_id TEXT NOT NULL UNIQUE,
			console_organization_id TEXT NOT NULL,
			slug TEXT NOT NULL,
			name TEXT NOT NULL,
			is_active INTEGER NOT NULL DEFAULT 1,
			timezone TEXT, currency TEXT, locale TEXT,
			updated_at TEXT NOT NULL DEFAULT (datetime('now'))
		)
	`); err != nil {
		t.Fatalf("create branches: %v", err)
	}
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	if err := p.PullBranch(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var name, currency string
	db.QueryRow("SELECT name, currency FROM branches WHERE id = 'br-1'").Scan(&name, &currency)
	if name != "Quán Phở Bò" || currency != "JPY" {
		t.Errorf("branch upsert wrong: name=%q currency=%q", name, currency)
	}

	// BLOCKER-WS-TAX — the branch-settings flat `tax_rate` is the authoritative
	// per-branch consumption rate and MUST flatten into shop_settings.tax_rate;
	// the local order engine reads it for every line (no per-line tax_type
	// snapshot exists yet). Skipping it (the reverted plan-043 T6.2/T6.3 change)
	// zeroed all LAN/kiosk/workstation order tax.
	var taxRate sql.NullString
	db.QueryRow("SELECT value FROM shop_settings WHERE key = 'tax_rate'").Scan(&taxRate)
	if !taxRate.Valid || taxRate.String != "10.00" {
		t.Errorf("tax_rate must flatten to 10.00, got valid=%v %q", taxRate.Valid, taxRate.String)
	}

	// Positive control: a KEPT settings key still flattens.
	var serviceCharge string
	db.QueryRow("SELECT value FROM shop_settings WHERE key = 'service_charge_rate'").Scan(&serviceCharge)
	if serviceCharge != "5.00" {
		t.Errorf("service_charge_rate flatten wrong: got %q", serviceCharge)
	}

	var cartTimeout string
	db.QueryRow("SELECT value FROM shop_settings WHERE key = 'cart_timeout_minutes'").Scan(&cartTimeout)
	if cartTimeout != "5" {
		t.Errorf("cart_timeout_minutes flatten wrong: got %q", cartTimeout)
	}
}

// ─── Menu (A1 flatten, preserve printer_group) ────────────────────────────

func TestPullMenuFlattensAndPreservesPrinterGroup(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/menu" {
			t.Errorf("unexpected path %s", r.URL.Path)
		}
		w.Write([]byte(`{"data":{
			"menu_id":"m1","menu_name":"Menu chính",
			"cart_timeout_minutes":20,
			"cart_deadline_iso":"2026-06-03T23:59:59+09:00",
			"categories":[
				{"id":"sec1","name":"Đồ uống","items":[
					{"id":"mp1","sku_id":"sku-1","name":"Trà sữa","description":"Trà sữa thơm ngon","price":35000,"image":"https://cdn/x.jpg","status":"available","active_promotion":{"id":"promo-1","discount_percent":10,"discounted_price":31500}},
					{"id":"mp2","sku_id":"sku-2","name":"Cà phê","description":null,"price":30000,"image":null,"status":"unavailable","active_promotion":null}
				]}
			]
		}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	// Existing row with custom printer_group set by workstation admin
	db.Exec(`INSERT INTO menu_items (id, cloud_id, name, price, printer_group, is_active)
		VALUES ('mp1', 'mp1', 'OLD NAME', 1, 'bar', 0)`)

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullMenu(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	// mp1: name/price/is_active/sku_id/description/discount updated from Cloud; printer_group preserved
	var name, printerGroup, skuID string
	var price, isActive int
	var discountPrice *int
	db.QueryRow("SELECT name, price, is_active, printer_group, COALESCE(sku_id,''), discount_price FROM menu_items WHERE id = 'mp1'").
		Scan(&name, &price, &isActive, &printerGroup, &skuID, &discountPrice)
	if name != "Trà sữa" || price != 35000 || isActive != 1 {
		t.Errorf("mp1 update wrong: name=%q price=%d active=%d", name, price, isActive)
	}
	if printerGroup != "bar" {
		t.Errorf("printer_group MUST be preserved as 'bar', got %q (workstation-local field, never overwritten by sync DOWN)", printerGroup)
	}
	if skuID != "sku-1" {
		t.Errorf("mp1 sku_id wrong: %q", skuID)
	}
	if discountPrice == nil || *discountPrice != 31500 {
		t.Errorf("mp1 discount_price wrong: %v", discountPrice)
	}

	// mp2: new row, default printer_group, no promotion
	var cat string
	db.QueryRow("SELECT category, is_active FROM menu_items WHERE id = 'mp2'").Scan(&cat, &isActive)
	if cat != "Đồ uống" {
		t.Errorf("mp2 category wrong: %q", cat)
	}
	if isActive != 0 {
		t.Errorf("mp2 should be inactive (status=unavailable from Cloud), is_active=%d", isActive)
	}

	// menu_meta must be populated with cart timeout and menu identity
	var menuID, menuName, cartDeadline string
	var cartTimeout int
	db.QueryRow("SELECT cloud_menu_id, cloud_menu_name, cart_timeout_minutes, COALESCE(cart_deadline_iso,'') FROM menu_meta WHERE id = 'current'").
		Scan(&menuID, &menuName, &cartTimeout, &cartDeadline)
	if menuID != "m1" || menuName != "Menu chính" {
		t.Errorf("menu_meta identity wrong: id=%q name=%q", menuID, menuName)
	}
	if cartTimeout != 20 {
		t.Errorf("menu_meta cart_timeout_minutes wrong: %d", cartTimeout)
	}
	if cartDeadline != "2026-06-03T23:59:59+09:00" {
		t.Errorf("menu_meta cart_deadline_iso wrong: %q", cartDeadline)
	}
}

func TestPullMenuLeavesLocalOnlyItemsAlone(t *testing.T) {
	// Local-only items (cloud_id IS NULL) added by admin via Wails UI
	// must not be touched by sync DOWN.
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":{"menu_id":"m1","menu_name":"M","cart_timeout_minutes":30,"categories":[]}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	db.Exec(`INSERT INTO menu_items (id, cloud_id, name, price, printer_group, is_active)
		VALUES ('local-1', NULL, 'Admin món bí mật', 10000, 'kitchen', 1)`)

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	_ = p.PullMenu(context.Background())

	var isActive int
	var name string
	db.QueryRow("SELECT is_active, name FROM menu_items WHERE id = 'local-1'").Scan(&isActive, &name)
	if isActive != 1 || name != "Admin món bí mật" {
		t.Errorf("local-only item should be untouched, got active=%d name=%q", isActive, name)
	}
}

// ─── Cloud URL resolver ───────────────────────────────────────────────────

func TestPullerHonorsCloudURLResolver(t *testing.T) {
	dynamicCloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":[{"id":"z-dynamic","name":"Dynamic"}]}`))
	}))
	defer dynamicCloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://stale-static-url", staticTokenFn("WS-TOKEN"))
	p.SetCloudURLResolver(func() string { return dynamicCloud.URL })

	if err := p.PullZones(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var name string
	db.QueryRow("SELECT name FROM zones WHERE id = 'z-dynamic'").Scan(&name)
	if name != "Dynamic" {
		t.Errorf("resolver should override static URL — got name=%q", name)
	}
}

func TestPullerSkipsWhenNoToken(t *testing.T) {
	// No device_token → cannot pull. Should not panic, return empty/no-op.
	calls := 0
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		w.Write([]byte(`{"data":[]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn(""))
	_ = p.PullZones(context.Background())

	if calls != 0 {
		t.Errorf("expected 0 cloud calls when token empty, got %d", calls)
	}
}

// ─── Lots ─────────────────────────────────────────────────────────────────

func TestPullLotsUpsertsRows(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/lots" {
			t.Errorf("unexpected path: %s", r.URL.Path)
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"lots":[
			{"id":"lot1","material_id":"m1","material_name":"Beef","warehouse_id":"w1","warehouse_name":"Main","quantity":50,"unit":"kg","status":"active","updated_at":"2026-05-21T10:00:00Z"},
			{"id":"lot2","material_id":"m2","material_name":"Rice","warehouse_id":"w1","warehouse_name":"Main","quantity":100,"unit":"kg","status":"active","updated_at":"2026-05-21T10:00:00Z"}
		],"generated_at":"2026-05-21T10:00:00Z"}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	if err := p.PullLots(context.Background()); err != nil {
		t.Fatalf("PullLots: %v", err)
	}

	var count int
	if err := db.QueryRow(`SELECT COUNT(*) FROM inventory_lots`).Scan(&count); err != nil {
		t.Fatalf("count: %v", err)
	}
	if count != 2 {
		t.Fatalf("expected 2 lots, got %d", count)
	}

	var qty float64
	_ = db.QueryRow(`SELECT quantity FROM inventory_lots WHERE id='lot1'`).Scan(&qty)
	if qty != 50 {
		t.Fatalf("expected lot1.quantity=50, got %v", qty)
	}
}

func TestPullLotsUpdatesQuantityOnRePull(t *testing.T) {
	var quantity float64 = 50
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"lots":[
			{"id":"lot1","material_id":"m1","material_name":"Beef","quantity":` + strconvFmt(quantity) + `,"unit":"kg","status":"active"}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))

	// First pull: quantity = 50
	_ = p.PullLots(context.Background())
	var got float64
	_ = db.QueryRow(`SELECT quantity FROM inventory_lots WHERE id='lot1'`).Scan(&got)
	if got != 50 {
		t.Fatalf("first pull: expected 50, got %v", got)
	}

	// Second pull simulating Cloud update: quantity drops to 30
	quantity = 30
	_ = p.PullLots(context.Background())
	_ = db.QueryRow(`SELECT quantity FROM inventory_lots WHERE id='lot1'`).Scan(&got)
	if got != 30 {
		t.Fatalf("second pull: expected 30 (UPSERT), got %v", got)
	}

	var rowCount int
	_ = db.QueryRow(`SELECT COUNT(*) FROM inventory_lots`).Scan(&rowCount)
	if rowCount != 1 {
		t.Fatalf("expected 1 row total after UPSERT, got %d", rowCount)
	}
}

// TestPullSlowCallsPaymentMethods locks in that `pullSlow` actually invokes
// pullSlowPos → PullPaymentMethods. Regression for the bug where the slow
// loop was only calling PullLots, so payment_methods stayed empty/stale and
// pos-web sent payment_method IDs that Cloud's `exists:payment_methods,id`
// validator rejected with "The selected payment_method_id is invalid".
func TestPullSlowCallsPaymentMethods(t *testing.T) {
	var hitPaymentMethods, hitCustomers, hitMenuSchedules, hitPeripheralDevices, hitLots bool
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/v1/workstation/payment-methods":
			hitPaymentMethods = true
			w.Write([]byte(`{"data":[{"id":"pm-1","code":"cash","name":"Tiền mặt","is_active":true,"sort_order":0,"is_auto_confirm":true,"requires_tendered":true}]}`))
		case "/api/v1/workstation/customers":
			hitCustomers = true
			w.Write([]byte(`{"data":[]}`))
		case "/api/v1/workstation/menu-schedules":
			hitMenuSchedules = true
			w.Write([]byte(`{"data":[]}`))
		case "/api/v1/workstation/peripheral-devices":
			hitPeripheralDevices = true
			w.Write([]byte(`{"data":[]}`))
		case "/api/v1/workstation/lots":
			hitLots = true
			w.Write([]byte(`{"lots":[],"generated_at":"2026-05-21T10:00:00Z"}`))
		default:
			w.Write([]byte(`{"data":[]}`))
		}
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	p.pullSlow()

	if !hitLots {
		t.Errorf("pullSlow did not call /lots")
	}
	if !hitPaymentMethods {
		t.Errorf("pullSlow did not call /payment-methods — pos-web will use stale IDs")
	}
	if !hitCustomers {
		t.Errorf("pullSlow did not call /customers")
	}
	if !hitMenuSchedules {
		t.Errorf("pullSlow did not call /menu-schedules")
	}
	if !hitPeripheralDevices {
		t.Errorf("pullSlow did not call /peripheral-devices")
	}

	var count int
	db.QueryRow("SELECT COUNT(*) FROM payment_methods WHERE id = 'pm-1'").Scan(&count)
	if count != 1 {
		t.Errorf("expected payment_methods row written, got count=%d", count)
	}
}

func TestPullPeripheralDevicesStoresRows(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/peripheral-devices" {
			t.Errorf("unexpected path %s", r.URL.Path)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":[
			{
				"id":"pd-1",
				"name":"POS-01",
				"type":"pos",
				"is_active":true,
				"metadata":{"brand":"pos","location":"counter"},
				"registered_by_device_id":null,
				"organization_id":"org-1",
				"branch_id":"br-1",
				"created_at":"2026-07-01T10:00:00Z",
				"updated_at":"2026-07-01T10:00:00Z"
			},
			{
				"id":"pd-2",
				"name":"Cash Drawer",
				"type":"receipt_printer",
				"is_active":false,
				"metadata":{},
				"registered_by_device_id":"dev-1",
				"organization_id":"org-1",
				"branch_id":"br-1",
				"created_at":"2026-07-01T10:00:00Z",
				"updated_at":"2026-07-01T10:01:00Z"
			}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	if err := p.PullPeripheralDevices(context.Background()); err != nil {
		t.Fatalf("pull peripheral devices: %v", err)
	}

	var count int
	var metadata string
	db.QueryRow("SELECT COUNT(*) FROM peripheral_devices").Scan(&count)
	if count != 2 {
		t.Fatalf("expected 2 rows, got %d", count)
	}
	db.QueryRow("SELECT metadata FROM peripheral_devices WHERE id='pd-1'").Scan(&metadata)
	if metadata == "" {
		t.Fatalf("expected metadata to be stored for pd-1")
	}
}

func TestPullLotsEmptyDoesNotError(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"lots":[],"generated_at":"2026-05-21T10:00:00Z"}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	if err := p.PullLots(context.Background()); err != nil {
		t.Fatalf("PullLots empty: %v", err)
	}
}

// strconvFmt formats a float as JSON-safe text — tests use integer-valued
// floats only, so FormatFloat(_, 'f', -1, 64) renders cleanly.
func strconvFmt(f float64) string {
	return strconv.FormatFloat(f, 'f', -1, 64)
}

// ─── Recover ──────────────────────────────────────────────────────────────

func TestRecoverUpsertsOrders(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/orders" {
			t.Errorf("unexpected path: %s", r.URL.Path)
		}
		if r.URL.Query().Get("limit") != "500" {
			t.Errorf("expected limit=500, got %q", r.URL.Query().Get("limit"))
		}
		w.Header().Set("Content-Type", "application/json")
		// Note: total_amount/subtotal/tax_amount come as quoted strings — matches
		// Laravel's decimal(15,2) JSON serialisation. Status enum is now
		// cloud-aligned: "closed" (not "paid").
		w.Write([]byte(`{"data":[
			{"id":"order-uuid-1","order_code":"O-001","status":"closed","order_type":"dine_in",
			 "total_amount":"50000.00","subtotal":"45454.00","tax_amount":"4546.00",
			 "discount_amount":"0.00","service_charge":"0.00","total_tip":"0.00","paid_amount":"50000.00",
			 "note":"VIP",
			 "opened_at":"2026-05-20T10:00:00Z","created_at":"2026-05-20T10:00:00Z",
			 "updated_at":"2026-05-20T10:30:00Z","closed_at":"2026-05-20T10:30:00Z",
			 "organization_id":"org-1","brand_id":"brand-1","branch_id":"branch-1"},
			{"id":"order-uuid-2","order_code":"O-002","status":"open","order_type":"spot",
			 "total_amount":"30000.00","subtotal":"27272.00","tax_amount":"2728.00",
			 "discount_amount":"0.00","service_charge":"0.00","total_tip":"0.00","paid_amount":"0.00",
			 "opened_at":"2026-05-21T08:00:00Z","created_at":"2026-05-21T08:00:00Z",
			 "updated_at":"2026-05-21T08:00:00Z",
			 "organization_id":"org-1","brand_id":"brand-1","branch_id":"branch-1"}
		],"count":2,"since":"2026-04-21T00:00:00Z","generated_at":"2026-05-21T10:00:00Z"}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	n, err := p.Recover(context.Background(), 30*24*60*60*1_000_000_000) // 30 days in ns
	if err != nil {
		t.Fatalf("Recover: %v", err)
	}
	if n != 2 {
		t.Fatalf("expected 2 restored, got %d", n)
	}

	var status, closedAt string
	var totalAmount int
	_ = db.QueryRow(`SELECT status, IFNULL(closed_at,''), total_amount FROM orders WHERE id='order-uuid-1'`).Scan(&status, &closedAt, &totalAmount)
	if status != "closed" {
		t.Fatalf("expected status=closed, got %q", status)
	}
	if closedAt == "" {
		t.Fatalf("expected closed_at populated for closed order")
	}
	if totalAmount != 50000 {
		t.Fatalf("expected total_amount=50000 (parsed from decimal string), got %d", totalAmount)
	}

	// cloud_id should equal id (so subsequent sync_queue pushes match)
	var cloudID string
	_ = db.QueryRow(`SELECT cloud_id FROM orders WHERE id='order-uuid-1'`).Scan(&cloudID)
	if cloudID != "order-uuid-1" {
		t.Fatalf("expected cloud_id=order-uuid-1, got %q", cloudID)
	}
}

func TestRecoverIdempotentOnRerun(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":[
			{"id":"o1","status":"closed","order_type":"spot",
			 "total_amount":"50000.00","subtotal":"45454.00","tax_amount":"4546.00",
			 "discount_amount":"0.00","service_charge":"0.00","total_tip":"0.00","paid_amount":"50000.00",
			 "opened_at":"2026-05-20T10:00:00Z","created_at":"2026-05-20T10:00:00Z","updated_at":"2026-05-20T10:00:00Z",
			 "organization_id":"","brand_id":"","branch_id":""}
		],"count":1}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))

	_, _ = p.Recover(context.Background(), 30*24*60*60*1_000_000_000)
	_, _ = p.Recover(context.Background(), 30*24*60*60*1_000_000_000)

	var count int
	_ = db.QueryRow(`SELECT COUNT(*) FROM orders`).Scan(&count)
	if count != 1 {
		t.Fatalf("expected 1 row after double-recover (UPSERT), got %d", count)
	}
}

func TestRecoverEmptyDoesNotError(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":[],"count":0}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	n, err := p.Recover(context.Background(), 30*24*60*60*1_000_000_000)
	if err != nil {
		t.Fatalf("Recover empty: %v", err)
	}
	if n != 0 {
		t.Fatalf("expected 0 restored, got %d", n)
	}
}

// ─── Customer orders pull-DOWN ────────────────────────────────────────────

// stubHub satisfies BroadcastHub for tests; records broadcast calls.
type stubHub struct {
	events []map[string]any
}

func (h *stubHub) BroadcastEventScoped(eventType string, payload any, branchID string) {
	m, ok := payload.(map[string]any)
	if !ok {
		return
	}
	rec := make(map[string]any, len(m)+2)
	for k, v := range m {
		rec[k] = v
	}
	rec["_event_type"] = eventType
	rec["_branch_id"] = branchID
	h.events = append(h.events, rec)
}

// twoOrdersJSON is a helper that returns a JSON response with 2 orders and
// items. updated_at strings are RFC3339-sortable so cursor tests are stable.
func twoOrdersJSON() string {
	return `{"data":[
		{
			"id":"order-cl-1","order_code":"QR-001","order_type":"dine_in",
			"status":"open","opened_at":"2026-05-26T10:00:00Z",
			"updated_at":"2026-05-26T10:05:00Z",
			"branch_id":"br-1","brand_id":"brand-1","organization_id":"org-1",
			"items":[
				{"id":"item-cl-1","menu_item_id":"mi-1","menu_item_name":"Phở bò",
				 "quantity":2,"unit_price":50000,"subtotal":100000,
				 "status":"pending","updated_at":"2026-05-26T10:05:00Z"}
			]
		},
		{
			"id":"order-cl-2","order_code":"QR-002","order_type":"dine_in",
			"status":"open","opened_at":"2026-05-26T10:10:00Z",
			"updated_at":"2026-05-26T10:15:00Z",
			"branch_id":"br-1","brand_id":"brand-1","organization_id":"org-1",
			"items":[
				{"id":"item-cl-2","menu_item_id":"mi-2","menu_item_name":"Bún bò",
				 "quantity":1,"unit_price":60000,"subtotal":60000,
				 "status":"pending","updated_at":"2026-05-26T10:15:00Z"}
			]
		}
	]}`
}

func TestSyncPull_CustomerOrders_AdvancesCursor(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/orders" {
			t.Errorf("unexpected path: %s", r.URL.Path)
		}
		if r.Header.Get("Authorization") != "Bearer WS-TOKEN" {
			t.Errorf("wrong auth header: %q", r.Header.Get("Authorization"))
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(twoOrdersJSON()))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pullCustomerOrders: %v", err)
	}

	// Cursor should advance to max(updated_at) across both orders.
	cursor := p.getCursor(settingsCursorKey)
	if cursor != "2026-05-26T10:15:00Z" {
		t.Errorf("cursor not advanced: got %q, want 2026-05-26T10:15:00Z", cursor)
	}

	// Both orders must be in the DB.
	var orderCount int
	db.QueryRow("SELECT COUNT(*) FROM orders WHERE id IN ('order-cl-1','order-cl-2')").Scan(&orderCount)
	if orderCount != 2 {
		t.Errorf("expected 2 orders, got %d", orderCount)
	}

	// Both items must be in order_items.
	var itemCount int
	db.QueryRow("SELECT COUNT(*) FROM order_items WHERE id IN ('item-cl-1','item-cl-2')").Scan(&itemCount)
	if itemCount != 2 {
		t.Errorf("expected 2 order_items, got %d", itemCount)
	}
}

func TestSyncPull_CustomerOrders_EmitsWSEventOnStatusChange(t *testing.T) {
	// Pre-populate workstation with order + item at status='pending'.
	db := newPullerTestDB(t)
	db.Exec(`INSERT INTO orders (id, cloud_id, order_code, order_type, status, opened_at, branch_id, brand_id, organization_id, created_at, updated_at)
		VALUES ('order-cl-3','order-cl-3','QR-003','dine_in','open','2026-05-26T10:00:00Z','br-1','brand-1','org-1','2026-05-26T10:00:00Z','2026-05-26T10:00:00Z')`)
	db.Exec(`INSERT INTO order_items (id, customer_order_id, menu_item_id, menu_item_name, quantity, unit_price, subtotal, status, printer_group, created_at, updated_at)
		VALUES ('item-cl-3','order-cl-3','mi-3','Cơm chiên',1,40000,40000,'pending','kitchen','2026-05-26T10:00:00Z','2026-05-26T10:00:00Z')`)

	// Cloud returns same order+item but status bumped to 'preparing'.
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":[{
			"id":"order-cl-3","order_code":"QR-003","order_type":"dine_in",
			"status":"open","opened_at":"2026-05-26T10:00:00Z",
			"updated_at":"2026-05-26T10:20:00Z",
			"branch_id":"br-1","brand_id":"brand-1","organization_id":"org-1",
			"items":[{
				"id":"item-cl-3","menu_item_id":"mi-3","menu_item_name":"Cơm chiên",
				"quantity":1,"unit_price":40000,"subtotal":40000,
				"status":"preparing","updated_at":"2026-05-26T10:20:00Z"
			}]
		}]}`))
	}))
	defer cloud.Close()

	hub := &stubHub{}
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	p.SetHub(hub)

	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pullCustomerOrders: %v", err)
	}

	if len(hub.events) != 1 {
		t.Fatalf("expected 1 WS event, got %d", len(hub.events))
	}
	ev := hub.events[0]
	if ev["_event_type"] != "order_item.status_changed" {
		t.Errorf("wrong event type: %v", ev["_event_type"])
	}
	if ev["previous_status"] != "pending" {
		t.Errorf("expected previous_status=pending, got %v", ev["previous_status"])
	}
	if ev["status"] != "preparing" {
		t.Errorf("expected status=preparing, got %v", ev["status"])
	}
	if ev["source"] != "pull_down" {
		t.Errorf("expected source=pull_down, got %v", ev["source"])
	}
	if ev["_branch_id"] != "br-1" {
		t.Errorf("expected branch_id=br-1, got %v", ev["_branch_id"])
	}
}

func TestSyncPull_CustomerOrders_NoEventOnNoChange(t *testing.T) {
	db := newPullerTestDB(t)
	db.Exec(`INSERT INTO orders (id, cloud_id, order_code, order_type, status, opened_at, branch_id, brand_id, organization_id, created_at, updated_at)
		VALUES ('order-cl-4','order-cl-4','QR-004','dine_in','open','2026-05-26T10:00:00Z','br-1','brand-1','org-1','2026-05-26T10:00:00Z','2026-05-26T10:00:00Z')`)
	db.Exec(`INSERT INTO order_items (id, customer_order_id, menu_item_id, menu_item_name, quantity, unit_price, subtotal, status, printer_group, created_at, updated_at)
		VALUES ('item-cl-4','order-cl-4','mi-4','Chả giò',2,30000,60000,'ready','kitchen','2026-05-26T10:00:00Z','2026-05-26T10:00:00Z')`)

	// Cloud returns same item, same status='ready'.
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":[{
			"id":"order-cl-4","order_code":"QR-004","order_type":"dine_in",
			"status":"open","opened_at":"2026-05-26T10:00:00Z",
			"updated_at":"2026-05-26T10:25:00Z",
			"branch_id":"br-1","brand_id":"brand-1","organization_id":"org-1",
			"items":[{
				"id":"item-cl-4","menu_item_id":"mi-4","menu_item_name":"Chả giò",
				"quantity":2,"unit_price":30000,"subtotal":60000,
				"status":"ready","updated_at":"2026-05-26T10:25:00Z"
			}]
		}]}`))
	}))
	defer cloud.Close()

	hub := &stubHub{}
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	p.SetHub(hub)

	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pullCustomerOrders: %v", err)
	}

	if len(hub.events) != 0 {
		t.Errorf("expected 0 WS events (idempotent, status unchanged), got %d", len(hub.events))
	}
}

func TestSyncPull_CustomerOrders_HandlesEmptyResponse(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":[]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	// Seed a cursor so we can verify it is NOT advanced.
	db.Exec(`INSERT INTO settings (key, value) VALUES ('sync.customer_orders.last_pulled', '2026-05-26T09:00:00Z')`)

	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pullCustomerOrders: %v", err)
	}

	cursor := p.getCursor(settingsCursorKey)
	if cursor != "2026-05-26T09:00:00Z" {
		t.Errorf("cursor must not change on empty response, got %q", cursor)
	}

	var orderCount int
	db.QueryRow("SELECT COUNT(*) FROM orders").Scan(&orderCount)
	if orderCount != 0 {
		t.Errorf("expected 0 orders after empty response, got %d", orderCount)
	}
}

func TestPullEffectivePaymentOptionsUpsertsSnapshot(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/effective-payment-options" {
			t.Errorf("unexpected path %s", r.URL.Path)
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":{
			"revision": 3,
			"snapshot_hash": "abc123",
			"ownership_revision": "own-rev-1",
			"published_at": "2026-07-23T00:00:00Z",
			"options": [{
				"id": "opt-cash",
				"display_name": "Cash",
				"provider": "internal",
				"rail": "cash",
				"effective": true,
				"source": "effective",
				"reason": "allowed",
				"connection_id": "conn-1",
				"connection_option_id": "conn-opt-1",
				"shop_preference": "inherit",
				"device_preference": "inherit",
				"trace": [{"layer":"shop","decision":"allowed"}]
			}]
		}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullEffectivePaymentOptions(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var revision int
	var hash string
	db.QueryRow(`SELECT revision, snapshot_hash FROM payment_policy_snapshot WHERE id = 1`).Scan(&revision, &hash)
	if revision != 3 || hash != "abc123" {
		t.Errorf("snapshot wrong: rev=%d hash=%q", revision, hash)
	}

	var count int
	db.QueryRow(`SELECT COUNT(*) FROM effective_payment_options`).Scan(&count)
	if count != 1 {
		t.Fatalf("expected 1 option, got %d", count)
	}

	var effective int
	db.QueryRow(`SELECT effective FROM effective_payment_options WHERE id = 'opt-cash'`).Scan(&effective)
	if effective != 1 {
		t.Errorf("expected effective=1, got %d", effective)
	}

	if p.getCursor(paymentPolicyRevisionKey) != "3" {
		t.Errorf("revision cursor not set")
	}
	if p.getCursor(paymentPolicySnapshotHashKey) != "abc123" {
		t.Errorf("hash cursor not set")
	}
}

func TestPullEffectivePaymentOptionsSkipsWhenUnchanged(t *testing.T) {
	calls := 0
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		w.Write([]byte(`{"data":{"revision":2,"snapshot_hash":"same","ownership_revision":"o","options":[{"id":"opt-1","display_name":"Cash","provider":"internal","rail":"cash","effective":true,"source":"effective","reason":"allowed","shop_preference":"inherit","device_preference":"inherit","trace":[]}]}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("tok"))
	_ = p.setCursor(paymentPolicyRevisionKey, "2")
	_ = p.setCursor(paymentPolicySnapshotHashKey, "same")
	db.Exec(`INSERT INTO effective_payment_options (id, display_name, provider, rail, effective) VALUES ('stale', 'Old', 'internal', 'cash', 1)`)

	if err := p.PullEffectivePaymentOptions(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}
	if calls != 1 {
		t.Fatalf("expected 1 cloud call, got %d", calls)
	}

	var name string
	db.QueryRow(`SELECT display_name FROM effective_payment_options WHERE id = 'stale'`).Scan(&name)
	if name != "Old" {
		t.Errorf("local replica should be unchanged when revision/hash match, got name=%q", name)
	}
}

func TestPullEffectivePaymentOptionsReplaceOnRevisionChange(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":{"revision":5,"snapshot_hash":"new-hash","ownership_revision":"o","options":[{"id":"opt-new","display_name":"Card","provider":"stripe","rail":"card","effective":true,"source":"effective","reason":"allowed","shop_preference":"inherit","device_preference":"inherit","trace":[]}]}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("tok"))
	_ = p.setCursor(paymentPolicyRevisionKey, "4")
	_ = p.setCursor(paymentPolicySnapshotHashKey, "old-hash")
	db.Exec(`INSERT INTO effective_payment_options (id, display_name, provider, rail, effective) VALUES ('opt-old', 'Old', 'internal', 'cash', 1)`)

	if err := p.PullEffectivePaymentOptions(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	var count int
	db.QueryRow(`SELECT COUNT(*) FROM effective_payment_options`).Scan(&count)
	if count != 1 {
		t.Fatalf("expected replace-all to leave 1 row, got %d", count)
	}
	var name string
	db.QueryRow(`SELECT display_name FROM effective_payment_options WHERE id = 'opt-new'`).Scan(&name)
	if name != "Card" {
		t.Errorf("expected new option row, got %q", name)
	}
}

func TestPullPrintersStoresCloudRowsAndKeepsLocal(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/workstation/printers" {
			t.Errorf("unexpected path %s", r.URL.Path)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":[
			{
				"id":"cloud-1",
				"name":"Kitchen (cloud)",
				"roles":["kitchen_printer","hold_printer"],
				"connection_type":"network",
				"address":"192.168.1.50:9100",
				"paper_width":80,
				"cut_type":"full",
				"encoding":"shift_jis",
				"is_active":true,
				"created_at":"2026-07-01T10:00:00Z",
				"updated_at":"2026-07-01T10:00:00Z"
			}
		]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	// A local printer added by hand in the WS App — must survive the cloud pull.
	if _, err := db.Exec(`INSERT INTO printers (id, type, name, connection_type, address, roles, is_active, origin)
		VALUES ('local-1','kitchen_printer','Local Kitchen','network','192.168.1.99:9100','["kitchen_printer"]',1,'local')`); err != nil {
		t.Fatalf("seed local printer: %v", err)
	}

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	reloaded := false
	p.SetOnPrintersSynced(func() { reloaded = true })

	if err := p.PullPrinters(context.Background()); err != nil {
		t.Fatalf("pull printers: %v", err)
	}
	if !reloaded {
		t.Fatalf("expected onPrintersSynced to fire")
	}

	var cloudCount, localCount int
	db.QueryRow("SELECT COUNT(*) FROM printers WHERE origin='cloud'").Scan(&cloudCount)
	db.QueryRow("SELECT COUNT(*) FROM printers WHERE origin='local'").Scan(&localCount)
	if cloudCount != 1 {
		t.Fatalf("expected 1 cloud printer, got %d", cloudCount)
	}
	if localCount != 1 {
		t.Fatalf("local printer must survive the pull, got %d", localCount)
	}

	// Verify config JSON + address + roles landed in the shape LoadFromDB reads.
	var addr, cfg, roles, typ string
	db.QueryRow("SELECT address, config, roles, type FROM printers WHERE id='cloud-1'").Scan(&addr, &cfg, &roles, &typ)
	if addr != "192.168.1.50:9100" {
		t.Fatalf("unexpected address %q", addr)
	}
	if typ != "kitchen_printer" {
		t.Fatalf("type should mirror primary role, got %q", typ)
	}
	if !strings.Contains(cfg, `"paper_width":80`) || !strings.Contains(cfg, `"cut_type":"full"`) {
		t.Fatalf("config JSON missing paper/cut: %q", cfg)
	}
	if !strings.Contains(roles, "hold_printer") {
		t.Fatalf("roles JSON missing hold_printer: %q", roles)
	}
}

func TestPullPrintersReplacesCloudButNotLocalOnEmptyFeed(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":[]}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	// One stale cloud row (should be deleted) + one local row (should survive).
	db.Exec(`INSERT INTO printers (id, type, name, connection_type, address, roles, is_active, origin)
		VALUES ('cloud-old','kitchen_printer','Old Cloud','network','1.2.3.4:9100','["kitchen_printer"]',1,'cloud')`)
	db.Exec(`INSERT INTO printers (id, type, name, connection_type, address, roles, is_active, origin)
		VALUES ('local-1','kitchen_printer','Local','network','192.168.1.99:9100','["kitchen_printer"]',1,'local')`)

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullPrinters(context.Background()); err != nil {
		t.Fatalf("pull printers: %v", err)
	}

	var cloudCount, localCount int
	db.QueryRow("SELECT COUNT(*) FROM printers WHERE origin='cloud'").Scan(&cloudCount)
	db.QueryRow("SELECT COUNT(*) FROM printers WHERE origin='local'").Scan(&localCount)
	if cloudCount != 0 {
		t.Fatalf("empty feed must clear cloud printers, got %d", cloudCount)
	}
	if localCount != 1 {
		t.Fatalf("local printer must survive empty feed, got %d", localCount)
	}
}

func TestPullPrintersCloudErrorKeepsLocalRows(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	db.Exec(`INSERT INTO printers (id, type, name, connection_type, address, roles, is_active, origin)
		VALUES ('cloud-1','kitchen_printer','Cloud','network','1.2.3.4:9100','["kitchen_printer"]',1,'cloud')`)
	db.Exec(`INSERT INTO printers (id, type, name, connection_type, address, roles, is_active, origin)
		VALUES ('local-1','kitchen_printer','Local','network','192.168.1.99:9100','["kitchen_printer"]',1,'local')`)

	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	fired := false
	p.SetOnPrintersSynced(func() { fired = true })

	if err := p.PullPrinters(context.Background()); err == nil {
		t.Fatalf("expected error when cloud is down")
	}
	if fired {
		t.Fatalf("onPrintersSynced must not fire on cloud error")
	}

	// Nothing mutated: both rows remain (offline fallback intact).
	var total int
	db.QueryRow("SELECT COUNT(*) FROM printers").Scan(&total)
	if total != 2 {
		t.Fatalf("cloud error must not mutate printers, got %d rows", total)
	}
}
