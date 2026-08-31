package service

import (
	"testing"
)

// Pos-web Settings → Shop Order Settings page lets staff change
// default_order_item_status. PullBranch flattens it into shop_settings.
// AddItems (and Create) must stamp new lines with that synced value
// instead of always defaulting to 'pending'. A "counter service" shop
// (default_order_item_status='ready') means a new add-to-cart row
// jumps straight past pending → preparing → ready so the kitchen
// queue stays clean.
func TestAddItems_StampsSyncedDefaultStatusOnNewLines(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	if _, err := db.Exec(
		`INSERT INTO shop_settings (key, value) VALUES ('default_order_item_status', 'ready')
		 ON CONFLICT(key) DO UPDATE SET value = excluded.value`,
	); err != nil {
		t.Fatal(err)
	}

	o := seedSkuAndOrder(t, eng, "sku-D", 1000)
	if got := o.Items[0].Status; string(got) != "ready" {
		t.Errorf("new item status want ready (from shop_settings), got %q", got)
	}
}

func TestAddItems_FallsBackToPendingWhenSettingAbsent(t *testing.T) {
	eng, _ := newOrderEngineForTest(t)
	o := seedSkuAndOrder(t, eng, "sku-E", 1000)
	if got := o.Items[0].Status; string(got) != "pending" {
		t.Errorf("no shop_setting → want pending fallback, got %q", got)
	}
}

func TestAddItems_AcceptsPreparingDefault(t *testing.T) {
	eng, db := newOrderEngineForTest(t)
	if _, err := db.Exec(
		`INSERT INTO shop_settings (key, value) VALUES ('default_order_item_status', 'preparing')
		 ON CONFLICT(key) DO UPDATE SET value = excluded.value`,
	); err != nil {
		t.Fatal(err)
	}
	o := seedSkuAndOrder(t, eng, "sku-F", 1000)
	if got := o.Items[0].Status; string(got) != "preparing" {
		t.Errorf("preparing default: got %q", got)
	}
}
