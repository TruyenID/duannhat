package handler

import (
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

func i45(v int) *int { return &v }

// [Go] the LAN order shape emits conditions[], tax_rounding_mode/decimals, and
// the negative refund item with refund_of_item_id + is_refund.
func TestCustomerOrderShape_EmitsConditionsAndRefundFields(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")

	// Seed the order + items so loadOrderConditions' item-level subquery resolves.
	mustExec(t, db, `
		INSERT INTO orders (id, order_code, order_number, order_type, status, opened_at, created_at, updated_at)
		VALUES ('o1', 'WS-1', 1, 'spot', 'open', datetime('now'), datetime('now'), datetime('now'))`)
	mustExec(t, db, `
		INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, created_at, updated_at)
		VALUES ('orig-1', 'o1', 'X', 3, 100, 300, datetime('now'), datetime('now'))`)
	mustExec(t, db, `
		INSERT INTO order_items (id, customer_order_id, menu_item_name, quantity, unit_price, subtotal, refund_of_item_id, created_at, updated_at)
		VALUES ('refund-1', 'o1', 'X', -1, 100, -100, 'orig-1', datetime('now'), datetime('now'))`)

	// Seed order_conditions: an order-level tax row + a refund row on the line.
	mustExec(t, db, `
		INSERT INTO order_conditions (id, conditionable_type, conditionable_id, type, source, label, rate, amount, currency_code, meta, created_at)
		VALUES ('c-tax', 'order', 'o1', 'tax', 'tax_type', '10%対象', 10, 100, 'JPY', NULL, datetime('now'))`)
	mustExec(t, db, `
		INSERT INTO order_conditions (id, conditionable_type, conditionable_id, type, source, label, rate, amount, currency_code, meta, created_at)
		VALUES ('c-refund', 'order_item', 'refund-1', 'refund', 'manual', 'Refund', 10, -110, 'JPY', '{"refund_of_item_id":"orig-1"}', datetime('now'))`)

	order := &service.Order{
		ID:                  "o1",
		Status:              "open",
		TaxRoundingMode:     "round_up",
		TaxRoundingDecimals: i45(0),
		Items: []service.Item{
			{ID: "orig-1", CustomerOrderID: "o1", Quantity: 3, UnitPrice: 100, Subtotal: 300, TaxAmount: 30, RefundedQuantity: 1, Status: "served"},
			{ID: "refund-1", CustomerOrderID: "o1", Quantity: -1, UnitPrice: 100, Subtotal: -100, TaxAmount: -10, RefundOfItemID: "orig-1", Status: "served"},
		},
	}

	shape := srv.customerOrderShape(order, "")

	// rev-B: a legacy round_up snapshot normalizes to ceil in the LAN shape.
	if shape["tax_rounding_mode"] != "ceil" {
		t.Errorf("legacy round_up should normalize to ceil, got %v", shape["tax_rounding_mode"])
	}
	if shape["tax_rounding_decimals"] != 0 {
		t.Errorf("tax_rounding_decimals want 0, got %v", shape["tax_rounding_decimals"])
	}

	conditions, ok := shape["conditions"].([]map[string]any)
	if !ok {
		t.Fatalf("conditions not a []map, got %T", shape["conditions"])
	}
	if len(conditions) != 2 {
		t.Fatalf("conditions len want 2, got %d: %+v", len(conditions), conditions)
	}
	// Find the refund condition and assert its meta round-tripped as an object.
	var refundCond map[string]any
	for _, c := range conditions {
		if c["type"] == "refund" {
			refundCond = c
		}
	}
	if refundCond == nil {
		t.Fatal("missing refund condition")
	}
	if refundCond["amount"].(float64) != -110 {
		t.Errorf("refund condition amount want -110, got %v", refundCond["amount"])
	}
	meta, ok := refundCond["meta"].(map[string]any)
	if !ok || meta["refund_of_item_id"] != "orig-1" {
		t.Errorf("refund condition meta want object with refund_of_item_id=orig-1, got %v", refundCond["meta"])
	}

	// The items must carry refund fields.
	items := shape["items"].([]map[string]any)
	var orig, refund map[string]any
	for _, it := range items {
		switch it["id"] {
		case "orig-1":
			orig = it
		case "refund-1":
			refund = it
		}
	}
	if orig["is_refund"] != false {
		t.Errorf("original is_refund want false, got %v", orig["is_refund"])
	}
	if orig["refunded_quantity"] != 1 {
		t.Errorf("original refunded_quantity want 1, got %v", orig["refunded_quantity"])
	}
	if orig["refund_of_item_id"] != nil {
		t.Errorf("original refund_of_item_id want nil, got %v", orig["refund_of_item_id"])
	}
	if refund["is_refund"] != true {
		t.Errorf("refund line is_refund want true, got %v", refund["is_refund"])
	}
	if refund["refund_of_item_id"] != "orig-1" {
		t.Errorf("refund line refund_of_item_id want orig-1, got %v", refund["refund_of_item_id"])
	}
	if refund["quantity"] != -1 {
		t.Errorf("refund line quantity want -1, got %v", refund["quantity"])
	}
}

// [Go] a blank rounding mode defaults to round in the shape.
func TestCustomerOrderShape_DefaultsRoundingMode(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	order := &service.Order{ID: "o2", Status: "open"}
	shape := srv.customerOrderShape(order, "")
	if shape["tax_rounding_mode"] != "round" {
		t.Errorf("blank mode should default to round, got %v", shape["tax_rounding_mode"])
	}
	if shape["tax_rounding_decimals"] != nil {
		t.Errorf("nil decimals should serialize null, got %v", shape["tax_rounding_decimals"])
	}
}
