package service

import (
	"database/sql"
	"errors"
	"fmt"
	"strconv"
)

// #1114 — build the OfflineSelection an order's evidence signs, from the
// final local rows. Runs at sync-drain time (dine-in orders grow items after
// create, so the selection is only final once the order is being pushed), but
// always claims the catalog gate STAMPED AT CREATE (orders.catalog_revision) —
// never the currently-pulled revision, which may already be newer than the
// prices the customer paid.
//
// ErrOrderNotSignable marks the deliberate refusals: such orders simply take
// the legacy sync path — no money is lost, it just is not signature-protected.
var ErrOrderNotSignable = errors.New("order not signable")

// catalogGate reads the pull-stamped catalog revision + toppings flag.
// (0, 0) until the first menu pull lands.
func (e *OrderEngine) catalogGate() (revision int, hasToppings int) {
	var rev, flag string
	_ = e.db.QueryRow(`SELECT value FROM settings WHERE key = ?`, catalogRevisionKey).Scan(&rev)
	_ = e.db.QueryRow(`SELECT value FROM settings WHERE key = ?`, catalogRevisionHasToppingsKey).Scan(&flag)
	revision, _ = strconv.Atoi(rev)
	if flag == "1" {
		hasToppings = 1
	}
	return revision, hasToppings
}

// BuildOfflineSelection assembles the signable selection for one order, plus
// the catalog gate it was created under. Refusals (ErrOrderNotSignable):
//   - no catalog revision stamped (order predates the first menu pull)
//   - a non-voided line without a menu_product_sku_id anchor (ghost/off-menu
//     lines have no historical price Cloud could verify)
//   - a coupon or scheduled pickup on the order (not representable yet)
//   - no non-voided lines at all (nothing to bill)
func BuildOfflineSelection(db interface {
	QueryRow(query string, args ...any) *sql.Row
	Query(query string, args ...any) (*sql.Rows, error)
}, orderID string) (sel OfflineSelection, catalogRevision int, hasToppings bool, err error) {
	var (
		orderType          string
		guestCount         sql.NullInt64
		note, pickupAt     sql.NullString
		catalogHasToppings int
	)

	err = db.QueryRow(`
		SELECT order_type, guest_count, COALESCE(note,''),
		       COALESCE(scheduled_pickup_time,''),
		       catalog_revision, catalog_has_toppings
		FROM orders WHERE id = ?`, orderID,
	).Scan(&orderType, &guestCount, &note, &pickupAt, &catalogRevision, &catalogHasToppings)
	if err != nil {
		return sel, 0, false, fmt.Errorf("load order: %w", err)
	}

	if catalogRevision <= 0 {
		return sel, 0, false, fmt.Errorf("%w: order %s carries no catalog revision", ErrOrderNotSignable, orderID)
	}
	if pickupAt.Valid && pickupAt.String != "" {
		return sel, 0, false, fmt.Errorf("%w: scheduled pickup is not representable in the signed selection yet", ErrOrderNotSignable)
	}

	// An ACTIVE coupon lives in the order_coupons pivot locally (released_at
	// NULL); the signed selection cannot represent its discount yet, so such
	// orders stay legacy. A released coupon changed no money — ignore it.
	var couponCount int
	_ = db.QueryRow(`SELECT COUNT(*) FROM order_coupons WHERE order_id = ? AND released_at IS NULL`, orderID).Scan(&couponCount)
	if couponCount > 0 {
		return sel, 0, false, fmt.Errorf("%w: coupons are not representable in the signed selection yet", ErrOrderNotSignable)
	}

	// Non-voided lines with their menu anchor, in stable creation order —
	// line order is part of the signed digest on both sides.
	rows, err := db.Query(`
		SELECT oi.id, COALESCE(mi.menu_product_sku_id,''), COALESCE(oi.product_sku_id,''),
		       oi.quantity, COALESCE(oi.note,'')
		FROM order_items oi
		LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
		WHERE oi.customer_order_id = ? AND oi.voided_at IS NULL
		ORDER BY oi.created_at, oi.id`, orderID)
	if err != nil {
		return sel, 0, false, fmt.Errorf("load items: %w", err)
	}
	defer rows.Close()

	type lineRow struct {
		id, anchor, skuID, note string
		qty                     int
	}
	var lineRows []lineRow
	for rows.Next() {
		var lr lineRow
		if err := rows.Scan(&lr.id, &lr.anchor, &lr.skuID, &lr.qty, &lr.note); err != nil {
			return sel, 0, false, err
		}
		lineRows = append(lineRows, lr)
	}
	if err := rows.Err(); err != nil {
		return sel, 0, false, err
	}
	if len(lineRows) == 0 {
		return sel, 0, false, fmt.Errorf("%w: order %s has no billable lines", ErrOrderNotSignable, orderID)
	}

	lines := make([]OfflineSelectionLine, 0, len(lineRows))
	for _, lr := range lineRows {
		if lr.anchor == "" {
			return sel, 0, false, fmt.Errorf("%w: line %s is not menu-anchored", ErrOrderNotSignable, lr.id)
		}
		line := OfflineSelectionLine{
			LineID:           lr.id,
			MenuProductSkuID: offlineStr(lr.anchor),
			Quantity:         lr.qty,
		}
		if lr.skuID != "" {
			line.ProductSkuID = offlineStr(lr.skuID)
		}
		if lr.note != "" {
			line.Note = offlineStr(lr.note)
		}

		toppings, terr := db.Query(`
			SELECT topping_group_item_id, product_sku_id, quantity, COALESCE(note,'')
			FROM order_item_toppings
			WHERE order_item_id = ?
			ORDER BY created_at, id`, lr.id)
		if terr != nil {
			return sel, 0, false, fmt.Errorf("load toppings: %w", terr)
		}
		for toppings.Next() {
			var t OfflineSelectionTopping
			var tnote string
			if err := toppings.Scan(&t.ToppingGroupItemID, &t.ProductSkuID, &t.Quantity, &tnote); err != nil {
				toppings.Close()
				return sel, 0, false, err
			}
			if tnote != "" {
				t.Note = offlineStr(tnote)
			}
			line.Toppings = append(line.Toppings, t)
		}
		if err := toppings.Err(); err != nil {
			toppings.Close()
			return sel, 0, false, err
		}
		toppings.Close()

		lines = append(lines, line)
	}

	// Table bindings: the pivot is authoritative (multi-table merges);
	// orders.table_id is only the legacy primary.
	tableIDs := []string{}
	trows, err := db.Query(`SELECT table_id FROM order_tables WHERE order_id = ? ORDER BY table_id`, orderID)
	if err == nil {
		for trows.Next() {
			var id string
			if trows.Scan(&id) == nil && id != "" {
				tableIDs = append(tableIDs, id)
			}
		}
		trows.Close()
	}

	if orderType == "" {
		orderType = "spot"
	}

	sel = OfflineSelection{
		Lines:      lines,
		OrderType:  orderType,
		PickupType: "immediate",
		TableIDs:   tableIDs,
		Locale:     "ja",
		Channel:    "workstation",
	}
	if guestCount.Valid {
		gc := int(guestCount.Int64)
		sel.GuestCount = &gc
	}
	if note.Valid && note.String != "" {
		sel.Note = offlineStr(note.String)
	}

	return sel, catalogRevision, catalogHasToppings == 1, nil
}

func offlineStr(s string) *string { return &s }
