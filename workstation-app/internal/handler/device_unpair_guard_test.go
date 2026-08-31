package handler

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

// unpairMockCloud returns a Cloud stub that 200s any request (covers the
// best-effort self-revoke goroutine so it completes fast and goleak stays
// happy). Callers pass its URL as the Server's cloud_api_url.
func unpairMockCloud(t *testing.T) *httptest.Server {
	t.Helper()
	s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		w.Write([]byte(`{"status":"ok"}`))
	}))
	t.Cleanup(s.Close)
	return s
}

func seedSetting(t *testing.T, srv *Server, key, val string) {
	t.Helper()
	mustExec(t, srv.db, `INSERT INTO settings (key, value) VALUES (?, ?)
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`, key, val)
}

// seedPaymentRow inserts a payment with explicit cloud_id / sync_target so the
// unpair guard + reconciler paths can be exercised. cloudID "" → NULL-equivalent
// (unsynced). expiresAt "" → NULL.
func seedPaymentRow(t *testing.T, srv *Server, id, orderID, status string, amount int, cloudID, syncTarget, expiresAt string) {
	t.Helper()
	var cid, exp, tgt any
	if cloudID != "" {
		cid = cloudID
	}
	if expiresAt != "" {
		exp = expiresAt
	}
	if syncTarget != "" {
		tgt = syncTarget
	}
	mustExec(t, srv.db, `
		INSERT INTO payments (id, order_id, payment_method, amount, status,
		    cloud_id, sync_target, expires_at, idempotency_key, created_at, updated_at)
		VALUES (?, ?, 'cash', ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`,
		id, orderID, amount, status, cid, tgt, exp, "idem-"+id)
}

func seedOrderRow(t *testing.T, srv *Server, id string, synced bool) {
	t.Helper()
	var syncedAt any
	if synced {
		syncedAt = time.Now().UTC().Format(time.RFC3339Nano)
	}
	mustExec(t, srv.db, `
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    total_amount, branch_id, brand_id, organization_id, cloud_id, synced_at)
		VALUES (?, ?, 'dine_in', 'open', datetime('now'), 1000, 'br-1', 'bd-1', 'or-1', ?, ?)`,
		id, "C-"+id, "cloud-"+id, syncedAt)
}

func tableCount(t *testing.T, srv *Server, table string) int {
	t.Helper()
	var n int
	if err := srv.db.QueryRow("SELECT COUNT(*) FROM " + table).Scan(&n); err != nil {
		t.Fatalf("count %s: %v", table, err)
	}
	return n
}

func settingVal(t *testing.T, srv *Server, key string) string {
	t.Helper()
	var v string
	srv.db.QueryRow("SELECT COALESCE(value,'') FROM settings WHERE key = ?", key).Scan(&v)
	return v
}

func doUnpair(t *testing.T, srv *Server, force bool) *httptest.ResponseRecorder {
	t.Helper()
	url := "/api/device/unpair"
	if force {
		url += "?force=true"
	}
	req := httptest.NewRequest("POST", url, nil)
	w := httptest.NewRecorder()
	srv.handleDeviceUnpair(w, req)
	return w
}

// plan-818 C1: money at risk is measured from the payments table (cloud_id
// empty), NOT the sync_queue. A committed-but-never-acknowledged payment must
// block an un-forced unpair with 409 and leave EVERYTHING intact.
func TestUnpair_BlockedWhenUnsyncedPayment(t *testing.T) {
	cloud := unpairMockCloud(t)
	srv, _ := newServerWithAuth(t, cloud.URL)
	seedSetting(t, srv, "device_token", "tok-1")
	seedSetting(t, srv, "device_id", "dev-1")

	seedOrderRow(t, srv, "o-1", true) // order already synced → payment is the sole trigger
	seedPaymentRow(t, srv, "p-1", "o-1", "confirmed", 820000, "", "workstation", "")

	w := doUnpair(t, srv, false)
	if w.Code != http.StatusConflict {
		t.Fatalf("want 409, got %d body=%s", w.Code, w.Body.String())
	}
	var body struct {
		Error          string `json:"error"`
		UnsyncedAmount int64  `json:"unsynced_amount"`
		HasUnsynced    bool   `json:"has_unsynced"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &body); err != nil {
		t.Fatal(err)
	}
	if body.Error != "unsynced_data_present" || body.UnsyncedAmount != 820000 || !body.HasUnsynced {
		t.Fatalf("bad 409 body: %+v", body)
	}
	// Nothing was touched.
	if got := settingVal(t, srv, "device_token"); got != "tok-1" {
		t.Errorf("token must survive a blocked unpair, got %q", got)
	}
	if n := tableCount(t, srv, "orders"); n != 1 {
		t.Errorf("orders must survive a blocked unpair, got %d", n)
	}
	if n := tableCount(t, srv, "payments"); n != 1 {
		t.Errorf("payments must survive, got %d", n)
	}
}

// A forced unpair with unsynced revenue KEEPS the transaction tables and only
// wipes the Cloud mirror; it clears the token and records prev_branch_id.
func TestUnpair_ForceKeepsTransactionData(t *testing.T) {
	cloud := unpairMockCloud(t)
	srv, _ := newServerWithAuth(t, cloud.URL) // seeds workstation_branch_id = branch-A
	seedSetting(t, srv, "device_token", "tok-1")
	seedSetting(t, srv, "device_id", "dev-1")

	seedOrderRow(t, srv, "o-1", false) // unsynced order
	seedPaymentRow(t, srv, "p-1", "o-1", "confirmed", 820000, "", "workstation", "")
	mustExec(t, srv.db, `INSERT INTO menu_items (id, name, price) VALUES ('m-1','X',100)`)

	w := doUnpair(t, srv, true)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	var body struct {
		DataKept bool `json:"data_kept"`
	}
	json.Unmarshal(w.Body.Bytes(), &body)
	if !body.DataKept {
		t.Errorf("data_kept should be true on forced unpair with unsynced data")
	}
	// Money/recovery tables kept.
	if n := tableCount(t, srv, "orders"); n != 1 {
		t.Errorf("orders must be KEPT on force, got %d", n)
	}
	if n := tableCount(t, srv, "payments"); n != 1 {
		t.Errorf("payments must be KEPT on force, got %d", n)
	}
	// Cloud mirror wiped.
	if n := tableCount(t, srv, "menu_items"); n != 0 {
		t.Errorf("menu_items must be wiped, got %d", n)
	}
	// Token cleared, gate recorded.
	if got := settingVal(t, srv, "device_token"); got != "" {
		t.Errorf("token must be cleared on force, got %q", got)
	}
	if got := settingVal(t, srv, "unpair.prev_branch_id"); got != "branch-A" {
		t.Errorf("prev_branch_id want branch-A, got %q", got)
	}
}

// When nothing is unsynced, unpair behaves as before: it wipes the transaction
// tables too (start fresh). No regression.
func TestUnpair_CleanWipeWhenAllSynced(t *testing.T) {
	cloud := unpairMockCloud(t)
	srv, _ := newServerWithAuth(t, cloud.URL)
	seedSetting(t, srv, "device_token", "tok-1")

	seedOrderRow(t, srv, "o-1", true)                                                       // synced
	seedPaymentRow(t, srv, "p-1", "o-1", "confirmed", 1000, "cloud-p-1", "workstation", "") // synced (cloud_id set)
	mustExec(t, srv.db, `INSERT INTO menu_items (id, name, price) VALUES ('m-1','X',100)`)

	w := doUnpair(t, srv, false)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	if n := tableCount(t, srv, "orders"); n != 0 {
		t.Errorf("clean unpair must wipe orders, got %d", n)
	}
	if n := tableCount(t, srv, "menu_items"); n != 0 {
		t.Errorf("clean unpair must wipe menu_items, got %d", n)
	}
}

// plan-818 F3: a phantom pending (cloud_id empty, status pending, expires_at in
// the past) is NOT real money — it must not block a clean unpair.
func TestUnpair_PhantomPendingDoesNotBlock(t *testing.T) {
	cloud := unpairMockCloud(t)
	srv, _ := newServerWithAuth(t, cloud.URL)
	seedSetting(t, srv, "device_token", "tok-1")

	seedOrderRow(t, srv, "o-1", true) // synced
	past := time.Now().UTC().Add(-time.Hour).Format(time.RFC3339Nano)
	seedPaymentRow(t, srv, "p-phantom", "o-1", "pending", 500, "", "workstation", past)

	w := doUnpair(t, srv, false)
	if w.Code != http.StatusOK {
		t.Fatalf("phantom pending must not block: want 200, got %d body=%s", w.Code, w.Body.String())
	}
}

// Control for F3: a LIVE pending (unexpired) IS real money and blocks.
func TestUnpair_LivePendingBlocks(t *testing.T) {
	cloud := unpairMockCloud(t)
	srv, _ := newServerWithAuth(t, cloud.URL)
	seedSetting(t, srv, "device_token", "tok-1")

	seedOrderRow(t, srv, "o-1", true)
	future := time.Now().UTC().Add(time.Hour).Format(time.RFC3339Nano)
	seedPaymentRow(t, srv, "p-live", "o-1", "pending", 500, "", "workstation", future)

	w := doUnpair(t, srv, false)
	if w.Code != http.StatusConflict {
		t.Fatalf("live pending must block: want 409, got %d body=%s", w.Code, w.Body.String())
	}
}
