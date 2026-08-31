package service

import (
	"reflect"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/google/uuid"
)

// #2170 — OrderTaxLines: các dòng `tax` của sổ đi tới tầng in NGUYÊN VĂN,
// làm tròn half-away-from-zero về đơn vị (đối xứng `(int) round` mà
// `ReceiptTaxSummary::fromBreakdown` phía Cloud làm), thứ tự rate tăng dần,
// và KHÔNG lẫn dòng của type khác / đơn khác / dòng món.

func seedTaxCondition(t *testing.T, db *store.DB, orderID, condType, ctype string, rate any, amount, taxableBase any) {
	t.Helper()
	if _, err := db.Exec(`
		INSERT INTO order_conditions (
			id, conditionable_type, conditionable_id, type, source, label,
			rate, amount, taxable_base, currency_code, created_at, updated_at
		) VALUES (?, ?, ?, ?, 'tax_type', 'x', ?, ?, ?, 'JPY',
			'2026-08-01T00:00:00Z', '2026-08-01T00:00:00Z')`,
		uuid.NewString(), ctype, orderID, condType, rate, amount, taxableBase); err != nil {
		t.Fatalf("seed condition: %v", err)
	}
}

func TestOrderTaxLines_ReadsLedgerRoundedAndOrdered(t *testing.T) {
	db := newPromoTestDB(t)
	engine := NewOrderEngine(db)
	orderID := mkOrder(t, db, 3300)

	// Cố tình chèn NGƯỢC thứ tự mức, với amount mang phần lẻ dưới đơn vị đúng
	// như dòng sổ 内税 thật (goldenOrder: 10% → 293.0909…, 8% → 21.9090…).
	seedTaxCondition(t, db, orderID, "tax", "order", 10.0, 293.09090909090907, 2936.0)
	seedTaxCondition(t, db, orderID, "tax", "order", 8.0, 21.909090909090907, 269.0)

	// Nhiễu phải bị loại: type khác trên cùng đơn, dòng `tax` của ĐƠN KHÁC, và
	// dòng gắn vào order_item (refund per-line ghi ở scope đó).
	seedTaxCondition(t, db, orderID, "discount", "order", 8.0, -9.0, nil)
	seedTaxCondition(t, db, orderID, "service_charge", "order", 10.0, 320.0, nil)
	otherID := mkOrder(t, db, 500)
	seedTaxCondition(t, db, otherID, "tax", "order", 10.0, 45.0, 455.0)
	seedTaxCondition(t, db, orderID, "tax", "order_item", 10.0, -26.0, -264.0)

	got := engine.OrderTaxLines(orderID)
	want := []OrderTaxLine{
		{Rate: 8, Taxable: 269, Tax: 22},
		{Rate: 10, Taxable: 2936, Tax: 293},
	}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("OrderTaxLines = %v, want %v", got, want)
	}
}

func TestOrderTaxLines_EmptyLedgerReturnsNil(t *testing.T) {
	// Sổ trống là một trạng thái CÓ NGHĨA (đơn cũ / offline chưa định giá):
	// loader trả nil để buildReceiptTaxSummary rơi về phép tính — không 500,
	// không bảng rỗng.
	db := newPromoTestDB(t)
	engine := NewOrderEngine(db)
	orderID := mkOrder(t, db, 1000)

	if got := engine.OrderTaxLines(orderID); got != nil {
		t.Errorf("sổ trống phải trả nil, got %v", got)
	}
}
