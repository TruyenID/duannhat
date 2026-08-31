package service

import (
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"
)

// #2193 — tầng client của #2173: máy trạm phải TỰ chặn void dòng hoàn ở chỗ
// TẠO op, vì đường sync-UP không cứu được — nhánh 409-là-thành-công của
// sync_service đánh dấu synced một mutation Cloud đã bác (đo trong issue:
// row stamped synced_at), hai bên phân kỳ im lặng.
func TestVoidItem_RefusesRefundLine(t *testing.T) {
	e, db := newOrderEngineForTest(t)

	seedTaxType(t, e, "std", "標準", 10, true)
	mustExecTax(t, e, `INSERT INTO pos_products (id, name) VALUES ('p1','Item')`)
	mustExecTax(t, e, `INSERT INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('sku-1','p1','Reg','SKU-1',100,1)`)
	mustExecTax(t, e, `INSERT INTO menu_items (id, sku_id, name, price, is_active, tax_type_id)
		VALUES ('mi-1','sku-1','Item',100,1,'std')`)

	o, err := e.Create(CreateOrderInput{OrderType: "dine_in", Items: []CreateItemInput{
		{ProductSkuID: "sku-1", Quantity: 3},
	}}, nil)
	if err != nil {
		t.Fatalf("create: %v", err)
	}

	res, err := e.RefundItem(o.ID, o.Items[0].ID, 1, "test #2193")
	if err != nil {
		t.Fatalf("refund: %v", err)
	}
	totalBefore := res.Order.TotalAmount

	_, err = e.VoidItem(o.ID, res.RefundLineID, "thu ngân bấm nhầm")
	if !errors.Is(err, ErrCannotVoidRefundLine) {
		t.Fatalf("void dòng hoàn phải trả ErrCannotVoidRefundLine, got %v", err)
	}

	// Dòng hoàn còn nguyên, tổng không đổi — không có op sync nào được tạo từ
	// service (handler chỉ enqueue khi VoidItem trả nil).
	var status string
	var voidedAt *string
	if err := db.QueryRow(`SELECT status, voided_at FROM order_items WHERE id = ?`, res.RefundLineID).
		Scan(&status, &voidedAt); err != nil {
		t.Fatalf("read refund line: %v", err)
	}
	if status == "voided" || (voidedAt != nil && *voidedAt != "") {
		t.Errorf("dòng hoàn đã bị void: status=%q voided_at=%v", status, voidedAt)
	}
	after, _ := e.GetByID(o.ID)
	if after.TotalAmount != totalBefore {
		t.Errorf("tổng đổi sau void bị từ chối: %d → %d", totalBefore, after.TotalAmount)
	}
}

// #2193 — tầng hứng cho build cũ / op hỏng đã nằm sẵn trong hàng đợi: 409 mang
// mã họ "dấu vết khoản hoàn" (#2173/#2200) KHÔNG được rơi vào nhánh
// 409-là-idempotent-thành-công. Nó phải dead-letter + lộ ra, không phải stamped
// synced_at trong khi Cloud giữ nguyên dòng.
func TestOrderItemVoid_RefundTraceRejected409_DeadLettersNotSuccess(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusConflict)
		w.Write([]byte(`{"message":"A refund line cannot be voided.","code":"CANNOT_VOID_REFUND_LINE","item_id":"i-refund"}`))
	}))
	defer cloud.Close()

	e, db := newSyncTestEngine(t, cloud.URL)
	forceOnline(e)

	if _, err := db.Exec(`INSERT INTO orders (id, cloud_id, order_code, order_type, status, subtotal, discount_amount, total_amount, paid_amount, created_at, updated_at)
		VALUES ('ord-rt','cloud-ord-rt','C-1','spot','open',0,0,0,0,'2026-08-08T00:00:00Z','2026-08-08T00:00:00Z')`); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	if err := e.Enqueue("order", "ord-rt", "item_void", map[string]any{
		"bearer_token": "dev",
		"item_id":      "i-refund",
		"void_reason":  "op cũ trong hàng đợi",
	}, 1); err != nil {
		t.Fatalf("enqueue: %v", err)
	}
	e.processQueue()

	var syncedAt, deadReason *string
	if err := db.QueryRow(`SELECT synced_at, dead_letter_reason FROM sync_queue
		WHERE entity_type='order' AND operation='item_void' AND entity_id='ord-rt'`).
		Scan(&syncedAt, &deadReason); err != nil {
		t.Fatalf("read queue row: %v", err)
	}
	if syncedAt != nil && *syncedAt != "" {
		t.Error("409 CANNOT_VOID_REFUND_LINE bị đánh dấu synced — đúng lỗi phân-kỳ-im-lặng #2193 tồn tại để chặn")
	}
	if deadReason == nil || *deadReason != "refund_trace_rejected" {
		got := ""
		if deadReason != nil {
			got = *deadReason
		}
		t.Errorf("dead_letter_reason = %q, muốn refund_trace_rejected", got)
	}
}
