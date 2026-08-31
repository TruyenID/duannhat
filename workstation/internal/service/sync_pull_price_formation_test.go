package service

import (
	"database/sql"
	"encoding/json"
	"testing"
)

// #2620 (tầng 4 của #2273) — máy trạm mirror dấu vết ĐỊNH HÌNH GIÁ mà Cloud
// đóng dấu ở tầng 2 (`price_source`) và tầng 3 (`waived_quantity`).
//
// Máy trạm KHÔNG tự tính hai giá trị này. Nó chỉ chép lại — nên bài test đo
// đúng một thứ: cái gì Cloud gửi thì cái đó phải nằm trong SQLite, và cái gì
// Cloud KHÔNG gửi thì không được bịa ra.

func mkPriceFormationOrder(item cloudOrderItemPayload) cloudOrderPayload {
	return cloudOrderPayload{
		ID: "ord-pf", OrderCode: "ORD-PF", OrderType: "dine_in", Status: "open",
		OpenedAt: "2026-08-13T10:00:00Z", UpdatedAt: "2026-08-13T10:00:00Z",
		BranchID: "br-1", BrandID: "bd-1", OrgID: "org-1",
		Items: []cloudOrderItemPayload{item},
	}
}

func mkPriceFormationItem(id, priceSource string, toppings *[]cloudOrderItemToppingPayload) cloudOrderItemPayload {
	return cloudOrderItemPayload{
		ID:           id,
		MenuItemName: "Tra sua",
		Quantity:     json.Number("1"),
		UnitPrice:    json.Number("1930"),
		Subtotal:     json.Number("1930"),
		Status:       "pending",
		UpdatedAt:    "2026-08-13T10:00:00Z",
		PriceSource:  priceSource,
		Toppings:     toppings,
	}
}

// Bốn nhánh precedence của #2618 phải đi qua nguyên vẹn — máy trạm không được
// gộp `floating` thành `menu` hay ngược lại.
func TestUpsertOrder_MirrorsPriceSource(t *testing.T) {
	for _, src := range []string{"sku_base", "menu", "floating", "menu_promotion"} {
		t.Run(src, func(t *testing.T) {
			db := newPullerTestDB(t)
			p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

			if err := p.upsertOrder(mkPriceFormationOrder(mkPriceFormationItem("it-pf", src, nil)), false); err != nil {
				t.Fatalf("upsertOrder: %v", err)
			}

			var got sql.NullString
			if err := db.QueryRow(`SELECT price_source FROM order_items WHERE id='it-pf'`).Scan(&got); err != nil {
				t.Fatalf("scan price_source: %v", err)
			}
			if !got.Valid || got.String != src {
				t.Fatalf("price_source = %v, muốn %q", got, src)
			}
		})
	}
}

// Cloud cũ KHÔNG mang field ⇒ NULL, và NULL phải giữ nguyên là NULL.
//
// Đây là chiều "phải IM" của rào: đọc NULL thành `sku_base` là bịa ra một dấu
// vết mà ruling #2132 §B đòi phải có THẬT — tệ hơn không có dấu vết, vì nó sẽ
// được tin.
func TestUpsertOrder_AbsentPriceSourceStaysNull(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

	if err := p.upsertOrder(mkPriceFormationOrder(mkPriceFormationItem("it-pf", "", nil)), false); err != nil {
		t.Fatalf("upsertOrder: %v", err)
	}

	var got sql.NullString
	if err := db.QueryRow(`SELECT price_source FROM order_items WHERE id='it-pf'`).Scan(&got); err != nil {
		t.Fatalf("scan: %v", err)
	}
	if got.Valid {
		t.Fatalf("price_source = %q, muốn NULL — máy trạm không được bịa nguồn giá", got.String)
	}
}

// Một lượt pull sau đó KHÔNG mang field thì không được xoá dấu vết đã có.
// (COALESCE + NULLIF trong DO UPDATE.)
func TestUpsertOrder_LaterPullWithoutPriceSourceKeepsIt(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

	if err := p.upsertOrder(mkPriceFormationOrder(mkPriceFormationItem("it-pf", "menu_promotion", nil)), false); err != nil {
		t.Fatalf("upsert 1: %v", err)
	}
	if err := p.upsertOrder(mkPriceFormationOrder(mkPriceFormationItem("it-pf", "", nil)), false); err != nil {
		t.Fatalf("upsert 2: %v", err)
	}

	var got sql.NullString
	if err := db.QueryRow(`SELECT price_source FROM order_items WHERE id='it-pf'`).Scan(&got); err != nil {
		t.Fatalf("scan: %v", err)
	}
	if !got.Valid || got.String != "menu_promotion" {
		t.Fatalf("price_source = %v, muốn giữ %q qua lượt pull thiếu field", got, "menu_promotion")
	}
}

// Chiều NGƯỢC lại, và là chiều mà hai test trên KHÔNG bắt được: dòng đã nằm sẵn
// trong SQLite (pull cũ, chưa có field) rồi Cloud gửi dấu vết ⇒ phải ADOPT.
//
// Đây là ca mà kiểm đột biến bắt được: gỡ hẳn `price_source` khỏi mệnh đề
// DO UPDATE thì `MirrorsPriceSource` (chỉ INSERT) và `LaterPullWithoutPriceSource`
// (chỉ đòi GIỮ) vẫn XANH — "giữ" thoả mãn một cách tầm thường khi không có UPDATE
// nào cả. Không có test này thì nửa UPDATE của bản vá không được đo.
func TestUpsertOrder_LaterPullAdoptsPriceSource(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

	if err := p.upsertOrder(mkPriceFormationOrder(mkPriceFormationItem("it-pf", "", nil)), false); err != nil {
		t.Fatalf("upsert 1: %v", err)
	}
	if err := p.upsertOrder(mkPriceFormationOrder(mkPriceFormationItem("it-pf", "floating", nil)), false); err != nil {
		t.Fatalf("upsert 2: %v", err)
	}

	var got sql.NullString
	if err := db.QueryRow(`SELECT price_source FROM order_items WHERE id='it-pf'`).Scan(&got); err != nil {
		t.Fatalf("scan: %v", err)
	}
	if !got.Valid || got.String != "floating" {
		t.Fatalf("price_source = %v, muốn ADOPT %q ở lượt pull sau", got, "floating")
	}
}

// #2619 — `waived_quantity` của topping đi qua nguyên vẹn, và bất biến tiền
// giữ được: Σ(unit_price × quantity) − topping_subtotal == Σ(waived × unit).
func TestUpsertOrder_MirrorsToppingWaivedQuantity(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

	// free_up_to_n miễn 2 trong 4 đơn vị, đơn giá 150.
	toppings := []cloudOrderItemToppingPayload{
		{ID: "t-w", Name: "Tran chau", ModifierType: "add",
			Quantity: json.Number("4"), UnitPrice: json.Number("150"),
			WaivedQuantity: json.Number("2")},
	}
	if err := p.upsertOrder(mkPriceFormationOrder(mkPriceFormationItem("it-pf", "menu", &toppings)), false); err != nil {
		t.Fatalf("upsertOrder: %v", err)
	}

	var qty, unit, waived int
	if err := db.QueryRow(`
		SELECT quantity, unit_price, waived_quantity
		FROM order_item_toppings WHERE id='t-w'
	`).Scan(&qty, &unit, &waived); err != nil {
		t.Fatalf("scan: %v", err)
	}
	if waived != 2 {
		t.Fatalf("waived_quantity = %d, muốn 2", waived)
	}
	if got, want := waived*unit, 300; got != want {
		t.Fatalf("phần được miễn = %d, muốn %d", got, want)
	}
}

// Cloud cũ bỏ field ⇒ "" ⇒ 0, đúng bằng mặc định cột. Đây là chiều "phải IM":
// mọi topping không thuộc nhóm free_up_to_n phải đọc ra 0, không phải NULL và
// không phải một con số bịa.
func TestUpsertOrder_AbsentWaivedQuantityIsZero(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://x", staticTokenFn("T"))

	toppings := []cloudOrderItemToppingPayload{
		{ID: "t-nw", Name: "Extra cheese", ModifierType: "add",
			Quantity: json.Number("1"), UnitPrice: json.Number("150")},
	}
	if err := p.upsertOrder(mkPriceFormationOrder(mkPriceFormationItem("it-pf", "menu", &toppings)), false); err != nil {
		t.Fatalf("upsertOrder: %v", err)
	}

	var waived int
	if err := db.QueryRow(`SELECT waived_quantity FROM order_item_toppings WHERE id='t-nw'`).Scan(&waived); err != nil {
		t.Fatalf("scan: %v", err)
	}
	if waived != 0 {
		t.Fatalf("waived_quantity = %d, muốn 0", waived)
	}
}
