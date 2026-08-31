package service

import (
	"database/sql"
	"errors"
	"fmt"
	"time"
)

// Phase 3 — multi-table support via order_tables junction. The legacy
// orders.table_id column remains the canonical "primary table"; this code
// keeps the junction in sync so pos-web's merge/unmerge UX works.

// MergeTable adds an additional table binding to an open order.
// Idempotent (re-binding the same table to the same order is a no-op).
// Mirrors CustomerOrderService::mergeTable (Cloud):
//
//   - order.status == open                            (409 ErrOrderNotOpen)
//   - target table not bound to a DIFFERENT order     (409 ErrTableOccupied)
//   - INSERT pivot row + stamp `tables.status='occupied'`
//   - set orders.table_id when null (first table becomes primary)
//
// Pre-fix the check was loose: only voided/closed orders were
// rejected, and a target table already occupied by another order was
// happily double-bound, producing two simultaneous orders pointing at
// the same physical table.
func (e *OrderEngine) MergeTable(orderID, tableID string) (*Order, error) {
	order, err := e.GetByID(orderID)
	if err != nil {
		return nil, err
	}
	if order.Status != StatusOpen {
		return nil, ErrOrderNotOpen
	}
	if tableID == "" {
		return nil, fmt.Errorf("table_id required")
	}

	// Occupancy check: any OTHER active order holding this table_id?
	// Voided / closed parents don't count — their pivot row may
	// linger until cleanup. Backend uses `Table::lockForUpdate` +
	// `current_order_id != null`; we approximate via the local pivot
	// which is the LAN authority for orders workstation owns.
	var conflictOrderID string
	_ = e.db.QueryRow(`
		SELECT ot.order_id FROM order_tables ot
		JOIN orders o ON o.id = ot.order_id
		WHERE ot.table_id = ?
		  AND ot.order_id != ?
		  AND o.status NOT IN ('voided','closed')
		LIMIT 1`,
		tableID, orderID,
	).Scan(&conflictOrderID)
	if conflictOrderID != "" {
		return nil, ErrTableOccupied
	}

	// Next sort_order keeps insertion order; primary (sort_order=0)
	// stays first when iterating.
	var maxSort int
	_ = e.db.QueryRow(
		"SELECT COALESCE(MAX(sort_order), -1) FROM order_tables WHERE order_id = ?",
		orderID,
	).Scan(&maxSort)
	newSort := maxSort + 1

	now := time.Now().UTC().Format(time.RFC3339)
	if _, err := e.db.Exec(`
		INSERT INTO order_tables (order_id, table_id, sort_order, bound_at)
		VALUES (?, ?, ?, ?)
		ON CONFLICT(order_id, table_id) DO NOTHING`,
		orderID, tableID, newSort, now,
	); err != nil {
		return nil, fmt.Errorf("merge table: %w", err)
	}

	// Promote to primary if order had no table_id yet — mirrors
	// backend's `if (! $order->table_id) $order->update(...)`. Without
	// this, /pos/tables' derived current_order_id query (uses
	// orders.table_id OR pivot) keeps the order off the primary
	// table's tile in TablesOverview.
	if order.TableID == "" {
		if _, err := e.db.Exec(
			`UPDATE orders SET table_id = ?, updated_at = ? WHERE id = ?`,
			tableID, now, orderID,
		); err != nil {
			return nil, fmt.Errorf("promote primary table: %w", err)
		}
	}

	// Stamp tables.status='occupied' for the local view. PullTables
	// will reset on next 5s tick to Cloud's view; inside the sync race
	// window the local stamp keeps the LAN UI honest.
	_, _ = e.db.Exec(`UPDATE tables SET status='occupied' WHERE id = ?`, tableID)

	return e.GetByID(orderID)
}

// UnmergeTable removes a table binding. Mirrors
// CustomerOrderService::unmergeTable (Cloud):
//
//   - order.status == open                                            (409 ErrOrderNotOpen)
//   - binding exists for (order, table)                               (404 sql.ErrNoRows)
//   - NOT the last binding on a dine_in order                         (409 ErrCannotUnmergeLastDineIn)
//   - DELETE pivot row + stamp `tables.status='free'`
//   - re-pick primary `orders.table_id` if we just removed it
//
// Pre-fix used a `sort_order == 0 → reject` rule that diverged from
// backend: it refused to unmerge the FIRST-bound table even when more
// tables remained, AND it allowed unmerging the last table on a
// dine-in (cart goes phantom). Replaced with backend's "last + dine_in"
// gate so the two implementations agree.
func (e *OrderEngine) UnmergeTable(orderID, tableID string) (*Order, error) {
	order, err := e.GetByID(orderID)
	if err != nil {
		return nil, err
	}
	if order.Status != StatusOpen {
		return nil, ErrOrderNotOpen
	}

	// Binding exists?
	var sortOrder int
	err = e.db.QueryRow(
		"SELECT sort_order FROM order_tables WHERE order_id = ? AND table_id = ?",
		orderID, tableID,
	).Scan(&sortOrder)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, sql.ErrNoRows
	}
	if err != nil {
		return nil, fmt.Errorf("lookup binding: %w", err)
	}

	// Last-on-dine-in gate.
	var tableCount int
	if err := e.db.QueryRow(
		`SELECT COUNT(*) FROM order_tables WHERE order_id = ?`, orderID,
	).Scan(&tableCount); err != nil {
		return nil, fmt.Errorf("count tables: %w", err)
	}
	if tableCount <= 1 && order.OrderType == "dine_in" {
		return nil, ErrCannotUnmergeLastDineIn
	}

	if _, err := e.db.Exec(
		"DELETE FROM order_tables WHERE order_id = ? AND table_id = ?",
		orderID, tableID,
	); err != nil {
		return nil, fmt.Errorf("delete binding: %w", err)
	}

	// Free the table locally (Cloud's sync DOWN will reconcile).
	_, _ = e.db.Exec(`UPDATE tables SET status='free' WHERE id = ?`, tableID)

	// Re-pick primary if we just dropped the one stamped on
	// orders.table_id. Pick the lowest-sort_order remaining row.
	if order.TableID == tableID {
		var newPrimary sql.NullString
		_ = e.db.QueryRow(`
			SELECT table_id FROM order_tables
			WHERE order_id = ?
			ORDER BY sort_order
			LIMIT 1`, orderID,
		).Scan(&newPrimary)
		nowStr := time.Now().UTC().Format(time.RFC3339)
		if newPrimary.Valid {
			_, _ = e.db.Exec(`UPDATE orders SET table_id = ?, updated_at = ? WHERE id = ?`,
				newPrimary.String, nowStr, orderID)
		} else {
			_, _ = e.db.Exec(`UPDATE orders SET table_id = NULL, updated_at = ? WHERE id = ?`,
				nowStr, orderID)
		}
	}

	return e.GetByID(orderID)
}

// SplitBillMode enumerates the three split modes pos-web's SplitBillDialog
// supports. Names match the Cloud OrderController@splitBill query/body
// vocabulary so a payload translates between the two unchanged.
type SplitBillMode string

const (
	SplitEqual    SplitBillMode = "equal"
	SplitByItems  SplitBillMode = "by_items"
	SplitByAmount SplitBillMode = "by_amount"
)

// SplitBillRequest is the input shape; not every field applies to every mode.
type SplitBillRequest struct {
	Mode         SplitBillMode `json:"mode"`
	SplitCount   int           `json:"split_count"`
	ItemBuckets  [][]string    `json:"item_buckets"`  // mode=by_items, len == split_count
	AmountSplits []int         `json:"amount_splits"` // mode=by_amount, units = same as total_amount
}

// SplitBillResult is the read-only output. Sums always equal the order total.
// `items_per_bill` is only populated for by_items mode; equal/by_amount fill
// it with empty slices so the JSON shape is consistent.
type SplitBillResult struct {
	OrderID      string                 `json:"order_id"`
	Mode         SplitBillMode          `json:"mode"`
	SplitCount   int                    `json:"split_count"`
	TotalAmount  int                    `json:"total_amount"`
	BillTotals   []int                  `json:"bill_totals"`
	ItemsPerBill [][]SplitItemBreakdown `json:"items_per_bill"`
}

// SplitItemBreakdown carries the per-item info pos-web renders in the
// SplitBillReceipt printout.
type SplitItemBreakdown struct {
	ItemID    string `json:"item_id"`
	Name      string `json:"name"`
	Quantity  int    `json:"quantity"`
	UnitPrice int    `json:"unit_price"`
	Subtotal  int    `json:"subtotal"`
}

// ComputeSplitBill builds a read-only preview of how the bill would be split.
// No mutations — pos-web's per-bill PaymentDialog flows still hit POST
// /orders/{id}/payments with the per-bill amount.
func (e *OrderEngine) ComputeSplitBill(orderID string, req SplitBillRequest) (*SplitBillResult, error) {
	order, err := e.GetByID(orderID)
	if err != nil {
		return nil, err
	}
	if req.SplitCount < 1 {
		return nil, fmt.Errorf("split_count must be >= 1")
	}

	result := &SplitBillResult{
		OrderID:      orderID,
		Mode:         req.Mode,
		SplitCount:   req.SplitCount,
		TotalAmount:  order.TotalAmount,
		BillTotals:   make([]int, req.SplitCount),
		ItemsPerBill: make([][]SplitItemBreakdown, req.SplitCount),
	}
	for i := range result.ItemsPerBill {
		result.ItemsPerBill[i] = []SplitItemBreakdown{}
	}

	switch req.Mode {
	case "", SplitEqual:
		// Equal split — distribute total amount and absorb rounding into the
		// last bill so the sum matches the source order exactly.
		each := order.TotalAmount / req.SplitCount
		remainder := order.TotalAmount - each*req.SplitCount
		for i := 0; i < req.SplitCount; i++ {
			result.BillTotals[i] = each
		}
		result.BillTotals[req.SplitCount-1] += remainder

	case SplitByItems:
		if len(req.ItemBuckets) != req.SplitCount {
			return nil, fmt.Errorf("item_buckets length must equal split_count")
		}
		itemByID := map[string]*Item{}
		for i := range order.Items {
			itemByID[order.Items[i].ID] = &order.Items[i]
		}
		seen := map[string]bool{}
		for bucketIdx, bucket := range req.ItemBuckets {
			total := 0
			for _, itemID := range bucket {
				it := itemByID[itemID]
				if it == nil {
					return nil, fmt.Errorf("unknown item id %q in bucket %d", itemID, bucketIdx)
				}
				if seen[itemID] {
					return nil, fmt.Errorf("item %q assigned to multiple buckets", itemID)
				}
				if it.VoidedAt != nil {
					return nil, fmt.Errorf("item %q is voided", itemID)
				}
				seen[itemID] = true
				total += it.Subtotal
				result.ItemsPerBill[bucketIdx] = append(result.ItemsPerBill[bucketIdx],
					SplitItemBreakdown{
						ItemID:    it.ID,
						Name:      it.MenuItemName,
						Quantity:  it.Quantity,
						UnitPrice: it.UnitPrice,
						Subtotal:  it.Subtotal,
					})
			}
			result.BillTotals[bucketIdx] = total
		}
		// Sanity: every non-voided item must appear in some bucket.
		for _, it := range order.Items {
			if it.VoidedAt == nil && !seen[it.ID] {
				return nil, fmt.Errorf("item %q not assigned to any bucket", it.ID)
			}
		}

	case SplitByAmount:
		if len(req.AmountSplits) != req.SplitCount {
			return nil, fmt.Errorf("amount_splits length must equal split_count")
		}
		sum := 0
		for _, a := range req.AmountSplits {
			if a < 0 {
				return nil, fmt.Errorf("amount split must be >= 0")
			}
			sum += a
		}
		if sum != order.TotalAmount {
			return nil, fmt.Errorf("amount_splits sum %d != order total %d", sum, order.TotalAmount)
		}
		for i, a := range req.AmountSplits {
			result.BillTotals[i] = a
		}

	default:
		return nil, fmt.Errorf("unknown split mode %q", req.Mode)
	}

	return result, nil
}
