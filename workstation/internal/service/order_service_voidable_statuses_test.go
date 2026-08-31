package service

// plan-051 T3.2 — per-status void matrix (item_voidable_statuses) +
// VoidReason master (void_reason_id) on the workstation order engine.
//
// The matrix mirror lands in shop_settings.item_voidable_statuses (JSON
// array flattened by PullBranch); when absent the resolver falls back to the
// legacy allow_item_edit_any_status flag exactly like Cloud's
// resolveVoidableStatuses. Fallback-path pins (flag true/false, list absent)
// live in order_service_allow_any_status_test.go.

import (
	"errors"
	"reflect"
	"testing"
)

// setShopSetting upserts one shop_settings row for the engine under test.
func setShopSetting(t *testing.T, eng *OrderEngine, key, value string) {
	t.Helper()
	if _, err := eng.db.Exec(
		`INSERT INTO shop_settings (key, value) VALUES (?, ?)
		 ON CONFLICT(key) DO UPDATE SET value = excluded.value`, key, value,
	); err != nil {
		t.Fatal(err)
	}
}

// ─── ResolveVoidableStatuses (pure resolver pins) ────────────────────────────

func TestResolveVoidableStatuses_Matrix(t *testing.T) {
	cases := []struct {
		name     string
		listJSON string
		flag     bool
		want     []string
	}{
		// list present → list wins, flag ignored
		{"list wins over flag", `["pending"]`, true, []string{"pending"}},
		{"pending+preparing", `["pending","preparing"]`, false, []string{"pending", "preparing"}},
		// pending is a hard floor even when the persisted list omits it
		{"pending union hard", `["preparing","served"]`, false, []string{"pending", "preparing", "served"}},
		{"empty list still pending", `[]`, false, []string{"pending"}},
		// canonical lifecycle order regardless of persisted order + dedupe
		{"order+dedupe", `["served","pending","preparing","served"]`, false, []string{"pending", "preparing", "served"}},
		// unknown entries dropped (voided can never be "voidable")
		{"garbage entries dropped", `["voided","banana","ready"]`, false, []string{"pending", "ready"}},
		// absent / null / unparsable → legacy flag fallback (Cloud resolver parity)
		{"absent flag false", ``, false, []string{"pending"}},
		{"absent flag true", ``, true, []string{"pending", "preparing", "ready", "served"}},
		{"null flag true", `null`, true, []string{"pending", "preparing", "ready", "served"}},
		{"unparsable flag false", `not-json`, false, []string{"pending"}},
		{"unparsable flag true", `{oops`, true, []string{"pending", "preparing", "ready", "served"}},
	}
	for _, tc := range cases {
		if got := ResolveVoidableStatuses(tc.listJSON, tc.flag); !reflect.DeepEqual(got, tc.want) {
			t.Errorf("%s: ResolveVoidableStatuses(%q, %v) = %v, want %v",
				tc.name, tc.listJSON, tc.flag, got, tc.want)
		}
	}
}

// ─── VoidItem matrix gate ────────────────────────────────────────────────────

// Matrix ["pending","preparing"] → preparing voids (with a real reason),
// ready is rejected with the new error carrying the resolved list.
func TestVoidItem_Matrix_PreparingAllowedReadyRejected(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	setShopSetting(t, eng, "item_voidable_statuses", `["pending","preparing"]`)

	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	if _, err := db.Exec(`UPDATE order_items SET status = 'preparing' WHERE id = ?`, itemID); err != nil {
		t.Fatal(err)
	}

	// ready → not in the matrix
	if _, err := db.Exec(`UPDATE order_items SET status = 'ready' WHERE id = ?`, itemID); err != nil {
		t.Fatal(err)
	}
	_, err := eng.VoidItem(o.ID, itemID, "khách đổi món")
	if !errors.Is(err, ErrItemStatusNotVoidable) {
		t.Fatalf("ready: want ErrItemStatusNotVoidable, got %v", err)
	}
	var nv *ItemStatusNotVoidableError
	if !errors.As(err, &nv) {
		t.Fatalf("want *ItemStatusNotVoidableError, got %T", err)
	}
	if nv.Status != "ready" {
		t.Errorf("error status want ready, got %q", nv.Status)
	}
	if want := []string{"pending", "preparing"}; !reflect.DeepEqual(nv.VoidableStatuses, want) {
		t.Errorf("error voidable list want %v, got %v", want, nv.VoidableStatuses)
	}
	// legacy sentinel compatibility — pre-plan-051 errors.Is callers keep working
	if !errors.Is(err, ErrItemNotPending) {
		t.Error("new error must still match the legacy ErrItemNotPending sentinel")
	}

	// preparing → in the matrix; real reason voids
	if _, err := db.Exec(`UPDATE order_items SET status = 'preparing' WHERE id = ?`, itemID); err != nil {
		t.Fatal(err)
	}
	if _, err := eng.VoidItem(o.ID, itemID, "khách đổi món"); err != nil {
		t.Fatalf("preparing must be voidable under the matrix: %v", err)
	}
	var status string
	db.QueryRow(`SELECT status FROM order_items WHERE id = ?`, itemID).Scan(&status)
	if status != "voided" {
		t.Errorf("status want voided, got %s", status)
	}
}

// Matrix present → the legacy flag is IGNORED (list wins even when the flag
// says any-status).
func TestVoidItem_Matrix_ListWinsOverLegacyFlag(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	setShopSetting(t, eng, "item_voidable_statuses", `["pending"]`)
	setShopSetting(t, eng, "allow_item_edit_any_status", "true")

	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	if _, err := db.Exec(`UPDATE order_items SET status = 'preparing' WHERE id = ?`, itemID); err != nil {
		t.Fatal(err)
	}

	if _, err := eng.VoidItem(o.ID, itemID, "real reason"); !errors.Is(err, ErrItemStatusNotVoidable) {
		t.Errorf("matrix [pending] must beat flag=true: want ErrItemStatusNotVoidable, got %v", err)
	}
}

// Pending stays voidable even when the persisted matrix is empty/corrupted —
// the union floor.
func TestVoidItem_Matrix_PendingAlwaysVoidable(t *testing.T) {
	for _, listJSON := range []string{`[]`, `["served"]`} {
		eng, _ := newOrderEngineForTest(t)
		setShopSetting(t, eng, "item_voidable_statuses", listJSON)

		o := seedSkuAndOrder(t, eng, "sku-A", 1000)
		if _, err := eng.VoidItem(o.ID, o.Items[0].ID, "any"); err != nil {
			t.Errorf("list %s: pending void must succeed, got %v", listJSON, err)
		}
	}
}

// #1148 junk-reason rejection is unchanged under the matrix: a non-pending
// void with a junk text and NO resolvable reason id is refused.
func TestVoidItem_Matrix_NonPendingStillRequiresRealReason(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	setShopSetting(t, eng, "item_voidable_statuses", `["pending","preparing"]`)

	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	if _, err := db.Exec(`UPDATE order_items SET status = 'preparing' WHERE id = ?`, itemID); err != nil {
		t.Fatal(err)
	}

	for _, junk := range []string{"", "   ", "voided_by_workstation", "Removed by staff"} {
		if _, err := eng.VoidItem(o.ID, itemID, junk); !errors.Is(err, ErrVoidReasonRequired) {
			t.Errorf("junk reason %q: want ErrVoidReasonRequired, got %v", junk, err)
		}
	}
}

// ─── void_reason_id (VoidReason master) ─────────────────────────────────────

const testVoidReasonsJSON = `[
	{"id":"vr-1","label":"Bấm nhầm","stock_effect":"restock","requires_note":false,"sort_order":0},
	{"id":"vr-2","label":"Nấu hỏng / đổ bỏ","stock_effect":"waste","requires_note":false,"sort_order":1}
]`

// A picked reason id is stored on the local row (both pending and
// non-pending paths) alongside the text snapshot.
func TestVoidItemWithReasonID_StoresIDOnRow(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	setShopSetting(t, eng, "void_reasons", testVoidReasonsJSON)

	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID

	if _, err := eng.VoidItemWithReasonID(o.ID, itemID, "Bấm nhầm", "vr-1"); err != nil {
		t.Fatalf("void with reason id: %v", err)
	}
	var reason, reasonID string
	db.QueryRow(`SELECT COALESCE(void_reason,''), COALESCE(void_reason_id,'') FROM order_items WHERE id = ?`, itemID).
		Scan(&reason, &reasonID)
	if reason != "Bấm nhầm" || reasonID != "vr-1" {
		t.Errorf("row want (Bấm nhầm, vr-1), got (%s, %s)", reason, reasonID)
	}

	// Engine read-back surfaces it on the Item struct.
	got, err := eng.GetByID(o.ID)
	if err != nil {
		t.Fatal(err)
	}
	if got.Items[0].VoidReasonID != "vr-1" {
		t.Errorf("Item.VoidReasonID want vr-1, got %q", got.Items[0].VoidReasonID)
	}
}

// A resolvable reason id satisfies the #1148 real-reason requirement on a
// non-pending void even when the text is a junk default (Cloud applies the
// same OR: valid void_reason_id OR real text).
func TestVoidItemWithReasonID_ResolvableIDSatisfiesRealReason(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	setShopSetting(t, eng, "item_voidable_statuses", `["pending","preparing"]`)
	setShopSetting(t, eng, "void_reasons", testVoidReasonsJSON)

	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	if _, err := db.Exec(`UPDATE order_items SET status = 'preparing' WHERE id = ?`, itemID); err != nil {
		t.Fatal(err)
	}

	if _, err := eng.VoidItemWithReasonID(o.ID, itemID, "Removed by staff", "vr-2"); err != nil {
		t.Fatalf("resolvable reason id must satisfy the real-reason rule: %v", err)
	}
	var reasonID string
	db.QueryRow(`SELECT COALESCE(void_reason_id,'') FROM order_items WHERE id = ?`, itemID).Scan(&reasonID)
	if reasonID != "vr-2" {
		t.Errorf("void_reason_id want vr-2, got %q", reasonID)
	}
}

// An UNRESOLVABLE id does not — the gate degrades to the text requirement
// (mirrors Cloud's converge-not-reject: the id alone proves nothing).
func TestVoidItemWithReasonID_UnknownIDStillNeedsRealText(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	setShopSetting(t, eng, "item_voidable_statuses", `["pending","preparing"]`)
	setShopSetting(t, eng, "void_reasons", testVoidReasonsJSON)

	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	itemID := o.Items[0].ID
	if _, err := db.Exec(`UPDATE order_items SET status = 'preparing' WHERE id = ?`, itemID); err != nil {
		t.Fatal(err)
	}

	if _, err := eng.VoidItemWithReasonID(o.ID, itemID, "Removed by staff", "no-such-id"); !errors.Is(err, ErrVoidReasonRequired) {
		t.Fatalf("unknown id + junk text: want ErrVoidReasonRequired, got %v", err)
	}
	// … but real text with the unknown id still voids (text carries it).
	if _, err := eng.VoidItemWithReasonID(o.ID, itemID, "khách trả lại", "no-such-id"); err != nil {
		t.Fatalf("unknown id + real text must void: %v", err)
	}
}

// Legacy 3-arg VoidItem leaves void_reason_id NULL.
func TestVoidItem_LegacyPathLeavesReasonIDNull(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-A", 1000)
	if _, err := eng.VoidItem(o.ID, o.Items[0].ID, "typed reason"); err != nil {
		t.Fatal(err)
	}
	var reasonID any
	db.QueryRow(`SELECT void_reason_id FROM order_items WHERE id = ?`, o.Items[0].ID).Scan(&reasonID)
	if reasonID != nil {
		t.Errorf("void_reason_id want NULL on the legacy path, got %v", reasonID)
	}
}

// ─── Settings parse round-trip ──────────────────────────────────────────────

func TestParseVoidReasons_RoundTrip(t *testing.T) {
	reasons := ParseVoidReasons(testVoidReasonsJSON)
	if len(reasons) != 2 {
		t.Fatalf("want 2 reasons, got %d", len(reasons))
	}
	want := VoidReason{ID: "vr-2", Label: "Nấu hỏng / đổ bỏ", StockEffect: "waste", RequiresNote: false, SortOrder: 1}
	if reasons[1] != want {
		t.Errorf("reason[1] = %+v, want %+v", reasons[1], want)
	}
	for _, empty := range []string{"", "  ", "null", "not-json"} {
		if got := ParseVoidReasons(empty); len(got) != 0 {
			t.Errorf("ParseVoidReasons(%q) want empty, got %v", empty, got)
		}
	}
}

// Engine accessors read the mirrored shop_settings rows.
func TestEngineVoidSettingsAccessors(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)

	// Nothing mirrored → pending-only + empty reason list.
	if got := eng.VoidableItemStatuses(); !reflect.DeepEqual(got, []string{"pending"}) {
		t.Errorf("default voidable want [pending], got %v", got)
	}
	if got := eng.VoidReasons(); len(got) != 0 {
		t.Errorf("default reasons want empty, got %v", got)
	}

	setShopSetting(t, eng, "item_voidable_statuses", `["pending","preparing","served"]`)
	setShopSetting(t, eng, "void_reasons", testVoidReasonsJSON)

	if got, want := eng.VoidableItemStatuses(), []string{"pending", "preparing", "served"}; !reflect.DeepEqual(got, want) {
		t.Errorf("voidable want %v, got %v", want, got)
	}
	if got := eng.VoidReasons(); len(got) != 2 || got[0].ID != "vr-1" {
		t.Errorf("reasons want the mirrored 2 rows, got %v", got)
	}
}
