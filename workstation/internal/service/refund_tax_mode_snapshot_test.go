package service

import (
	"path/filepath"
	"testing"
	"time"

	storetest "github.com/dxs-platform/workstation-app/internal/store"
)

// #2108 — refund negates gross theo snapshot 税込/税抜 CỦA ĐƠN, không theo cờ
// chi nhánh đang sống trong shop_settings.
//
// Kịch bản thật: đơn mở lúc chi nhánh còn 総額表示 (is_tax_included=1, giá dòng
// đã GỒM thuế), admin đóng ca rồi flip cờ về 税抜, sau đó quán hoàn món của đơn
// cũ. Trước #2108 refund đọc e.pricesIncludeTax() (cờ SỐNG = 0) nên negate
// subtotal + tax = hoàn THỪA phần thuế đã nằm sẵn trong subtotal. Cloud
// (WritesCustomerOrders::refundItem) luôn đọc $order->is_tax_included — lệch
// hai repo cho cùng một cú hoàn.
func TestRefundGrossFollowsOrderTaxModeSnapshotNotLiveFlag(t *testing.T) {
	db, err := storetest.Open(filepath.Join(t.TempDir(), "refund_tax_mode.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	now := time.Now().UTC().Format(time.RFC3339)

	// Cờ chi nhánh SỐNG đã flip về 税抜 SAU khi đơn được tạo.
	if _, err := db.Exec(
		`INSERT INTO settings (key, value) VALUES ('prices_include_tax', '0')`,
	); err != nil {
		t.Fatalf("seed settings: %v", err)
	}

	// Đơn được tạo trong chế độ 総額表示: snapshot is_tax_included = 1,
	// dòng ¥1100 đã GỒM ¥100 thuế 10%.
	if _, err := db.Exec(`
		INSERT INTO orders (
			id, order_code, order_number, order_type, status,
			opened_at, guest_count,
			subtotal, discount_amount, service_charge, tax_amount,
			total_tip, total_amount, paid_amount, is_tax_included,
			organization_id, brand_id, branch_id,
			created_at, updated_at
		) VALUES ('order-incl', 'WS-000901', 901, 'spot', 'dining',
			?, 1, 1100, 0, 0, 100, 0, 1100, 0, 1,
			'org', 'brand', 'branch', ?, ?)
	`, now, now, now); err != nil {
		t.Fatalf("seed order: %v", err)
	}
	if _, err := db.Exec(`
		INSERT INTO order_items (
			id, customer_order_id, menu_item_name,
			quantity, unit_price, subtotal,
			tax_rate, tax_amount,
			printer_group, status, print_status,
			created_at, updated_at
		) VALUES ('item-incl', 'order-incl', 'Set A',
			1, 1100, 1100, 10, 100,
			'kitchen', 'served', 'printed', ?, ?)
	`, now, now); err != nil {
		t.Fatalf("seed item: %v", err)
	}

	engine := NewOrderEngine(db)
	if _, err := engine.RefundItem("order-incl", "item-incl", 1, "khach tra mon"); err != nil {
		t.Fatalf("RefundItem: %v", err)
	}

	var refundSubtotal, condAmount int
	if err := db.QueryRow(`
		SELECT oi.subtotal, CAST(oc.amount AS INTEGER)
		FROM order_items oi
		JOIN order_conditions oc
		  ON oc.conditionable_id = oi.id AND oc.type = 'refund'
		WHERE oi.refund_of_item_id = 'item-incl'`,
	).Scan(&refundSubtotal, &condAmount); err != nil {
		t.Fatalf("read refund line + condition: %v", err)
	}

	// 総額表示: subtotal đã là GROSS → condition negate đúng subtotal (-1100),
	// KHÔNG cộng thêm thuế lần nữa (-1200 là số của cờ sống 税抜).
	if refundSubtotal != -1100 {
		t.Fatalf("refund line subtotal = %d, muon -1100", refundSubtotal)
	}
	if condAmount != refundSubtotal {
		t.Errorf("refund condition amount = %d, muon %d (gross THEO SNAPSHOT cua don; %d+tax nghia la da doc co chi nhanh song — #2108)",
			condAmount, refundSubtotal, refundSubtotal)
	}
}
