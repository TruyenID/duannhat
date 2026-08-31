package service

import (
	"database/sql"
	"errors"
	"fmt"
	"math"
	"time"
)

// OrderUpdateInput drives both PUT /pos/orders/{id} (full update) and PUT
// /pos/orders/{id}/init (initial bind of table + guests after a quick-create).
// All fields are pointers so callers can express "leave alone" by sending nil;
// JSON omitempty + nil pointer is the standard "patch field absent" sentinel.
type OrderUpdateInput struct {
	TableID    *string `json:"table_id,omitempty"`
	GuestCount *int    `json:"guest_count,omitempty"`
	Note       *string `json:"note,omitempty"`
	OrderType  *string `json:"order_type,omitempty"`
	CustomerID *string `json:"customer_id,omitempty"`
}

// UpdateMeta patches the mutable header fields on an open order. Returns the
// fully-loaded order (matches the handler contract that lets pos-web skip a
// separate GET after mutate).
func (e *OrderEngine) UpdateMeta(id string, input OrderUpdateInput) (*Order, error) {
	order, err := e.GetByID(id)
	if err != nil {
		return nil, err
	}
	// Mirrors CustomerOrderService::update — only `open` orders accept
	// header patches. Paying / closed / voided freeze. ErrOrderNotOpen
	// is the sentinel the handler maps to 409.
	if order.Status != StatusOpen {
		return nil, ErrOrderNotOpen
	}

	sets := []string{"updated_at = ?"}
	args := []any{time.Now().UTC().Format(time.RFC3339)}

	if input.TableID != nil {
		sets = append(sets, "table_id = ?")
		args = append(args, nullableString(*input.TableID))
	}
	if input.GuestCount != nil {
		gc := *input.GuestCount
		if gc < 1 {
			gc = 1
		}
		sets = append(sets, "guest_count = ?")
		args = append(args, gc)
	}
	if input.Note != nil {
		sets = append(sets, "note = ?")
		args = append(args, nullableString(*input.Note))
	}
	if input.OrderType != nil {
		sets = append(sets, "order_type = ?")
		args = append(args, *input.OrderType)
	}
	if input.CustomerID != nil {
		// orders.customer_id is a real column; Cloud's update lets
		// pos-web re-bind a customer mid-order (e.g. after a name lookup
		// resolves to a loyalty record). Match here so the LAN cart
		// header matches the sync UP payload it produces.
		sets = append(sets, "customer_id = ?")
		args = append(args, nullableString(*input.CustomerID))
	}
	if len(sets) == 1 {
		// Nothing to patch — return the order unchanged rather than running
		// a pointless UPDATE (idempotent semantics matter for sync UP).
		return order, nil
	}

	args = append(args, id)
	query := "UPDATE orders SET " + joinComma(sets) + " WHERE id = ?"

	// AUDIT FIX 1.1 (2026-07-14) — an order_type flip (takeaway↔dine_in) must
	// re-resolve EVERY line's tax snapshot (rate pick + 酒類 escalation are
	// order-type functions) and re-run the per-rate engine, exactly like
	// Cloud's update() → reResolveOrderLines() → recalculateTotals(). Before
	// this fix the LAN kept the old rates (bentō stayed 8% after switching to
	// dine-in), under-collecting on local receipts until the cloud recompute.
	//
	// Round-3 B9 + round-4 B-II.5: the META UPDATE, the re-resolve, and the
	// recalc all share ONE transaction — a crash at any point can no longer
	// leave the new order_type stored next to the old per-line rates (a state
	// the lazy re-stamp can't heal, since the lines aren't NULL).
	if input.OrderType != nil && *input.OrderType != order.OrderType {
		if err := e.db.Transaction(func(tx *sql.Tx) error {
			if _, err := tx.Exec(query, args...); err != nil {
				return fmt.Errorf("update order meta: %w", err)
			}
			if err := e.reResolveOrderLines(tx, id, *input.OrderType, false); err != nil {
				return err
			}
			return e.recalcOrderTotalsTx(tx, id)
		}); err != nil {
			return nil, fmt.Errorf("order_type change: %w", err)
		}

		return e.GetByID(id)
	}

	if _, err := e.db.Exec(query, args...); err != nil {
		return nil, fmt.Errorf("update order meta: %w", err)
	}

	return e.GetByID(id)
}

// InitOrderInput is what pos-web posts to PUT /pos/orders/{id}/init.
// Mirrors CustomerOrderService::initOrder — both `table_ids` and
// `guest_count` are "first write wins": if the order already has
// tables bound (any row in `order_tables`) the call leaves the
// existing bindings alone, and same for a non-null guest_count. This
// matches Cloud's idempotent init: the staff tablet may PUT init
// multiple times when navigation jitters, and we don't want a
// re-init to override the manager's earlier merge_table edits.
type InitOrderInput struct {
	TableIDs   []string
	GuestCount *int
}

// InitOrder applies first-write-wins binding of `table_ids` + guest
// count to an open order, populates `order_tables` for every passed
// table, and stamps `tables.status='occupied'`. Returns the reloaded
// order on success.
//
// Status gate: only `open` orders accept init — past checkout the
// table binding is frozen.
func (e *OrderEngine) InitOrder(id string, input InitOrderInput) (*Order, error) {
	order, err := e.GetByID(id)
	if err != nil {
		return nil, err
	}
	if order.Status != StatusOpen {
		return nil, ErrOrderNotOpen
	}

	// table_ids first-write-wins: if the order already has any binding
	// in the pivot, ignore the incoming list. Backend's check uses
	// `if ($order->tables->isEmpty())`.
	var existingBindingCount int
	_ = e.db.QueryRow(
		`SELECT COUNT(*) FROM order_tables WHERE order_id = ?`, id,
	).Scan(&existingBindingCount)

	now := time.Now().UTC().Format(time.RFC3339)

	if existingBindingCount == 0 && len(input.TableIDs) > 0 {
		// Occupancy check: every table must be available (not held by
		// another active order).
		for _, tID := range input.TableIDs {
			if tID == "" {
				continue
			}
			var conflict string
			_ = e.db.QueryRow(`
				SELECT ot.order_id FROM order_tables ot
				JOIN orders o ON o.id = ot.order_id
				WHERE ot.table_id = ?
				  AND ot.order_id != ?
				  AND o.status NOT IN ('voided','closed')
				LIMIT 1`,
				tID, id,
			).Scan(&conflict)
			if conflict != "" {
				return nil, ErrTableOccupied
			}
		}

		// Promote first table_id to primary; insert pivot rows for all
		// in submission order.
		if _, err := e.db.Exec(
			`UPDATE orders SET table_id = ?, updated_at = ? WHERE id = ?`,
			input.TableIDs[0], now, id,
		); err != nil {
			return nil, fmt.Errorf("init promote primary: %w", err)
		}
		for i, tID := range input.TableIDs {
			if tID == "" {
				continue
			}
			if _, err := e.db.Exec(`
				INSERT INTO order_tables (order_id, table_id, sort_order, bound_at)
				VALUES (?, ?, ?, ?)
				ON CONFLICT(order_id, table_id) DO NOTHING`,
				id, tID, i, now,
			); err != nil {
				return nil, fmt.Errorf("init bind table: %w", err)
			}
			_, _ = e.db.Exec(
				`UPDATE tables SET status='occupied' WHERE id = ?`, tID,
			)
		}
	}

	// guest_count: workstation's schema is NOT NULL DEFAULT 0 and
	// Create backfills <=0 to 1, so unlike Cloud (`is_null(guest_count)`
	// as the "untouched" sentinel) we cannot detect a true "first
	// init." Always apply on init when a count is supplied — the
	// caller is the staff tablet committing to a head count, and the
	// init endpoint is the canonical place to set it. If a manager
	// changes it later they should use PATCH /orders/{id} (UpdateMeta)
	// which is also `open`-gated.
	if input.GuestCount != nil {
		gc := *input.GuestCount
		if gc < 1 {
			gc = 1
		}
		if _, err := e.db.Exec(
			`UPDATE orders SET guest_count = ?, updated_at = ? WHERE id = ?`,
			gc, now, id,
		); err != nil {
			return nil, fmt.Errorf("init guest count: %w", err)
		}
	}

	return e.GetByID(id)
}

// SoftDelete marks an open order as cancelled. Refuses on terminal states.
// Workstation does NOT physically remove rows — sync UP needs the id stable
// for Cloud to recognize the same record on the next reconciliation.
func (e *OrderEngine) SoftDelete(id string) error {
	order, err := e.GetByID(id)
	if err != nil {
		return err
	}
	if order.Status == StatusVoided || order.Status == StatusClosed {
		return fmt.Errorf("cannot delete %s order", order.Status)
	}
	now := time.Now().UTC().Format(time.RFC3339)
	_, err = e.db.Exec(
		`UPDATE orders SET status = ?, voided_at = ?, void_reason = ?, updated_at = ? WHERE id = ?`,
		string(StatusVoided), now, "deleted_by_pos", now, id,
	)
	return err
}

// VoidOrder transitions a non-terminal order to voided with a reason. This is
// the explicit "void" workflow (different from delete: void preserves the
// reason for audit, delete is silent).
func (e *OrderEngine) VoidOrder(id string, reason string) (*Order, error) {
	order, err := e.GetByID(id)
	if err != nil {
		return nil, err
	}
	if order.Status == StatusVoided {
		return order, nil // idempotent
	}
	if order.Status == StatusClosed {
		return nil, fmt.Errorf("cannot void a closed order")
	}
	now := time.Now().UTC().Format(time.RFC3339)
	if _, err := e.db.Exec(
		`UPDATE orders SET status = ?, voided_at = ?, void_reason = ?, updated_at = ? WHERE id = ?`,
		string(StatusVoided), now, nullableString(reason), now, id,
	); err != nil {
		return nil, err
	}
	return e.GetByID(id)
}

// ItemPatch is the body shape for PATCH /orders/{id}/items/{item}. All fields
// optional; only the ones present are mutated.
type ItemPatch struct {
	Quantity *int    `json:"quantity,omitempty"`
	Note     *string `json:"note,omitempty"`
	Status   *string `json:"status,omitempty"`
}

// validItemStatusValues mirrors Cloud's request validation
// (CustomerOrderController::updateItem — `'in:pending,preparing,ready,served'`).
// `voided` is intentionally excluded; it has its own dedicated flow
// (VoidItem / BR-OI05) that captures void_reason for audit.
var validItemStatusValues = map[string]bool{
	"pending":   true,
	"preparing": true,
	"ready":     true,
	"served":    true,
}

// ErrInvalidItemStatus is returned when patch.Status is non-nil but
// outside the active enum. Handler maps to 422 (validation), matching
// Cloud's FormRequest behaviour.
var ErrInvalidItemStatus = errors.New("invalid item status")

// ErrItemEditRequiresPending mirrors BR-OI05 for the edit path:
// quantity / note / toppings changes are only allowed while the line
// is still `pending` (kitchen hasn't picked it up). Cloud's body is
// "Can only change quantity/note/toppings when item is pending."
// Handler maps to 409.
var ErrItemEditRequiresPending = errors.New("can only edit quantity/note when item is pending")

// UpdateItem patches a line item on an order. Two distinct paths:
//
//   - Header edits (quantity / note): require order=open AND item=pending.
//     Mirrors CustomerOrderService::updateItem's BR-OI05 gate.
//   - Status transitions (pending↔preparing↔ready↔served): allowed on
//     any non-voided item even when order has moved past `open`. Cloud
//     deliberately omits the order-status assertion for the status
//     branch so KDS can mark items served while cashier is at /checkout.
//
// On status == 'served', stamps `served_at` to match Cloud
// (CustomerOrderService.php:1175). Other lifecycle timestamps
// (started_preparing_at / ready_at) are owned by the dedicated KDS
// surface; we leave them alone here so the two surfaces don't race.
func (e *OrderEngine) UpdateItem(orderID, itemID string, patch ItemPatch) (*Order, error) {
	order, err := e.GetByID(orderID)
	if err != nil {
		return nil, err
	}

	var found *Item
	for i := range order.Items {
		if order.Items[i].ID == itemID {
			found = &order.Items[i]
			break
		}
	}
	if found == nil {
		return nil, sql.ErrNoRows
	}
	if found.VoidedAt != nil {
		return nil, fmt.Errorf("cannot edit a voided item")
	}

	// Validate status value up-front so a bad enum doesn't fall through
	// to the per-field branch — pos-web's dropdown can only emit one of
	// the four active enums, but a misbehaving client or sync race
	// shouldn't be able to write 'voided' through this endpoint.
	if patch.Status != nil && !validItemStatusValues[*patch.Status] {
		return nil, ErrInvalidItemStatus
	}

	// BR-OI05 edit gate: header edits (qty / note) require the order
	// still be open AND the item still pending. Status-only patches
	// bypass this gate (KDS / waiter bumps an in-flight item).
	editingHeader := patch.Quantity != nil || patch.Note != nil
	if editingHeader {
		if order.Status != StatusOpen {
			return nil, ErrOrderNotOpen
		}
		// Pending-only gate, unless the branch opted into any-status editing.
		if found.Status != "pending" && !e.allowItemEditAnyStatus() {
			return nil, ErrItemEditRequiresPending
		}
	}

	now := time.Now().UTC().Format(time.RFC3339)
	sets := []string{"updated_at = ?"}
	args := []any{now}

	recalc := false
	if patch.Quantity != nil {
		q := *patch.Quantity
		if q < 1 {
			return nil, fmt.Errorf("quantity must be >= 1")
		}
		sets = append(sets, "quantity = ?", "subtotal = ?")
		args = append(args, q, q*found.UnitPrice)
		recalc = true
	}
	if patch.Note != nil {
		sets = append(sets, "note = ?")
		args = append(args, nullableString(*patch.Note))
	}
	if patch.Status != nil {
		sets = append(sets, "status = ?")
		args = append(args, *patch.Status)
		// Stamp served_at when transitioning to served — Cloud parity
		// (CustomerOrderService::updateItem line 1174). Preparing and
		// Ready timestamps are written by the dedicated KDS endpoints
		// (kds/orders/{id}/items/{item}/status), not here, so we don't
		// race that pipeline.
		if *patch.Status == "served" && found.ServedAt == nil {
			sets = append(sets, "served_at = ?")
			args = append(args, now)
		}
	}
	if len(sets) == 1 {
		return order, nil
	}

	args = append(args, itemID)
	if _, err := e.db.Exec(
		"UPDATE order_items SET "+joinComma(sets)+" WHERE id = ?", args...,
	); err != nil {
		return nil, fmt.Errorf("update item: %w", err)
	}

	if recalc {
		if err := e.recalcOrderTotals(orderID); err != nil {
			return nil, err
		}
	}
	return e.GetByID(orderID)
}

// DeleteItem matches Cloud's `DELETE
// /api/v1/shops/{slug}/orders/{order}/items/{item}` semantics by
// delegating to VoidItem with a fixed `Removed by staff` reason —
// Cloud's `removeItem()` does the same (CustomerOrderService.php:1232).
// Pre-fix workstation HARD-DELETEd the row, which:
//
//  1. Lost the cart-line history (audit + refund + receipt all need
//     the voided line to remain visible).
//  2. Bypassed BR-OI05 — a kitchen-bumped item could be "removed"
//     locally even though Cloud would 409 the sync UP request.
//  3. Drifted the LAN cart total from Cloud after sync: Cloud sees a
//     voided line with subtotal still in the audit footprint;
//     workstation showed no line at all.
//
// Soft-void preserves the cart shape + audit trail + sync UP parity
// in one call.
func (e *OrderEngine) DeleteItem(orderID, itemID string) (*Order, error) {
	return e.VoidItem(orderID, itemID, "Removed by staff")
}

// ErrItemNotPending mirrors BR-OI05: an item that's already past
// pending (preparing / ready / served) can't be voided — the kitchen
// has either started or finished it, so refunds and inventory undo
// have to go through a different surface. Handler maps this to 409
// Conflict to match Cloud's `Only pending items can be voided` body.
var ErrItemNotPending = errors.New("item not in pending status")

// ErrOrderNotOpen surfaces when the parent order has moved out of the
// `open` lifecycle window — anything past checkout (paying / closed /
// voided / abandoned) freezes the line items + the table bindings +
// the header fields. Used by void/init/update/merge/unmerge — they
// all require Open. Cloud returns 409 in every one of these flows.
var ErrOrderNotOpen = errors.New("order not in open status")

// Backward-compat alias — earlier code used `ErrOrderNotVoidable` for
// the void-item flow specifically. Both names refer to the same
// "order must be open" gate; new code should prefer ErrOrderNotOpen.
var ErrOrderNotVoidable = ErrOrderNotOpen

// ErrTableOccupied surfaces when a merge-table target already carries
// a binding (to this order or another). Mirrors Cloud's 409
// "Table is already occupied by another order."
var ErrTableOccupied = errors.New("table is already occupied")

// ErrCannotUnmergeLastDineIn refuses to remove the last table binding
// from a dine_in order — that would orphan the cart at a phantom
// table. Mirrors Cloud's CustomerOrderService::unmergeTable check.
var ErrCannotUnmergeLastDineIn = errors.New("cannot unmerge the last table from a dine-in order")

// VoidItem soft-voids a single line item (preserves the row + recalculates
// totals using only non-voided items). Mirrors Cloud's POST
// /orders/{id}/items/{item}/void semantics including BR-OI05:
//
//   - order must be `open` (not pending / dining / checkout / paying / voided / closed).
//   - item must be `pending` (kitchen hasn't picked it up).
//
// Why the strict gates: once the kitchen sees the ticket, the
// foodstuff gets prepped. A "void after preparing" silently throws
// away both the cooked dish and its cost — staff have to use the
// refund flow instead, which DOES track inventory undo + manager
// approval. Loosening the rule on LAN breaks parity with Cloud and
// the sync UP eventually rejects with 409 anyway, so the local cart
// + cloud diverge until staff re-bumps.
func (e *OrderEngine) VoidItem(orderID, itemID string, reason string) (*Order, error) {
	order, err := e.GetByID(orderID)
	if err != nil {
		return nil, err
	}
	if order.Status != StatusOpen {
		return nil, ErrOrderNotOpen
	}
	// Look up the item BEFORE the UPDATE so we can return distinct
	// errors for the three cases: not found / already past pending /
	// already voided (idempotent).
	var currentStatus string
	var alreadyVoided sql.NullString
	if err := e.db.QueryRow(
		`SELECT status, voided_at
		   FROM order_items
		   WHERE id = ? AND customer_order_id = ?`,
		itemID, orderID,
	).Scan(&currentStatus, &alreadyVoided); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, sql.ErrNoRows
		}
		return nil, fmt.Errorf("void item lookup: %w", err)
	}
	if alreadyVoided.Valid && alreadyVoided.String != "" {
		// Already voided — idempotent, just return the current order.
		return e.GetByID(orderID)
	}
	// Pending-only gate (BR-OI05), unless the branch opted into any-status
	// editing — then a preparing/ready/served line can be voided too. The order
	// must still be open (checked above) and an already-voided line stayed
	// idempotent above.
	if ItemStatus(currentStatus) != ItemStatusPending && !e.allowItemEditAnyStatus() {
		return nil, ErrItemNotPending
	}

	now := time.Now().UTC().Format(time.RFC3339)
	if _, err := e.db.Exec(
		`UPDATE order_items
		   SET status = 'voided', voided_at = ?, void_reason = ?, updated_at = ?
		   WHERE id = ? AND customer_order_id = ?`,
		now, nullableString(reason), now, itemID, orderID,
	); err != nil {
		return nil, fmt.Errorf("void item: %w", err)
	}
	if err := e.recalcOrderTotals(orderID); err != nil {
		return nil, err
	}
	return e.GetByID(orderID)
}

// recalcOrderTotals re-groups the order's non-voided items by their per-line
// snapshot tax_rate and re-runs the plan-043 §8 engine into subtotal / tax /
// service_charge / total. Mirrors Cloud's CustomerOrderService::recalculateTotals
// so pos-web's cart breakdown lines up between LAN and Cloud mode. Reads the
// current discount_amount + is_tax_included so the order's applied coupon +
// tax mode survive a per-item edit. This is the per-mutation recompute hook
// (void / update / coupon apply+release all funnel through it).
func (e *OrderEngine) recalcOrderTotals(orderID string) error {
	return e.db.Transaction(func(tx *sql.Tx) error {
		return e.recalcOrderTotalsTx(tx, orderID)
	})
}

// recalcOrderTotalsTx is the transaction-scoped body of recalcOrderTotals so
// callers that must combine it with other writes (round-3 audit B9:
// UpdateMeta's order_type re-resolve) run everything atomically instead of
// two separate transactions with a crash window between them.
func (e *OrderEngine) recalcOrderTotalsTx(tx *sql.Tx, orderID string) error {
	// AUDIT FIX 2.6 (2026-07-14) — lazy re-stamp (Cloud BUG-8 mirror): a
	// line created offline before the first tax-type sync carries
	// tax_rate NULL (legacy fallback) and used to stay NULL forever. On
	// the next mutation, once tax types exist locally, re-resolve just
	// those unstamped lines so they stop pricing at the drifting legacy
	// rate. No-op (one cheap COUNT) when every line carries a snapshot.
	var unstamped int
	_ = tx.QueryRow(`
		SELECT COUNT(*) FROM order_items
		WHERE customer_order_id = ? AND tax_rate IS NULL
		  AND (status IS NULL OR status != 'voided')`, orderID).Scan(&unstamped)
	if unstamped > 0 && e.hasSyncedTaxTypesTx(tx) {
		if err := e.reResolveOrderLines(tx, orderID, e.orderTypeOf(tx, orderID), true); err != nil {
			return err
		}
	}

	subtotal, _, tax, serviceCharge, total, err := e.computeOrderTotalsFromDB(tx, orderID)
	if err != nil {
		return err
	}
	now := time.Now().UTC().Format(time.RFC3339)
	if _, err := tx.Exec(
		`UPDATE orders SET subtotal = ?, tax_amount = ?, service_charge = ?, total_amount = ?, updated_at = ?
		   WHERE id = ?`,
		subtotal, tax, serviceCharge, total, now, orderID,
	); err != nil {
		return err
	}
	// plan-043 — re-stamp per-line tax_amount from the same per-rate groups
	// so Σ line == the group tax the order total used (largest remainder).
	return e.stampLineTaxAmounts(tx, orderID)
}

// RecalcOrderTotals is the exported entry so the handler's legacy handy path can
// delegate to the per-rate engine (per-rate order totals + allocated per-line
// tax_amount) instead of its old single-rate recompute.
func (e *OrderEngine) RecalcOrderTotals(orderID string) error {
	return e.recalcOrderTotals(orderID)
}

// stampLineTaxAmounts re-stamps every non-voided line's tax_amount so that,
// within each rate group, Σ line == the once-per-group tax the order total uses
// (端数処理は税率ごとに1回, インボイス). Groups the lines by their snapshot rate,
// rebuilds the SAME net base priceGroups uses (subtotal − pro-rata discount),
// then allocates the group tax back to the lines by largest remainder. Mirrors
// CustomerOrderService::stampLineTaxAmounts on Cloud. Runs inside the caller's
// write tx; idempotent (re-running re-derives the same values).
func (e *OrderEngine) stampLineTaxAmounts(tx *sql.Tx, orderID string) error {
	var discount float64
	var includeTaxInt int
	if err := tx.QueryRow(
		`SELECT COALESCE(discount_amount, 0), COALESCE(is_tax_included, 0) FROM orders WHERE id = ?`,
		orderID,
	).Scan(&discount, &includeTaxInt); err != nil {
		return err
	}
	includeTax := includeTaxInt != 0
	legacy := e.legacyTaxRate()
	// plan-045 — the per-line allocation rounds the GROUP tax with the order's
	// snapshot rule (never the live setting), then apportions by largest
	// remainder. Refund lines are EXCLUDED (they carry a copied+negated tax
	// snapshot that must NOT be re-derived through the ≥0 allocator).
	taxMode, taxDecimals := e.orderRoundingSnapshot(tx, orderID)
	tStep := taxStep(taxDecimals, e.currencyCode())

	rows, err := tx.Query(
		`SELECT id, COALESCE(tax_rate, ?) AS rate,
		        (quantity * (unit_price + COALESCE(topping_subtotal, 0))) AS sub
		 FROM order_items
		 WHERE customer_order_id = ? AND (status IS NULL OR status != 'voided')
		   AND (refund_of_item_id IS NULL OR refund_of_item_id = '')`,
		legacy, orderID,
	)
	if err != nil {
		return err
	}
	type line struct {
		id   string
		rate float64
		sub  float64
	}
	var lines []line
	subtotal := 0.0
	for rows.Next() {
		var l line
		if err := rows.Scan(&l.id, &l.rate, &l.sub); err != nil {
			rows.Close()
			return err
		}
		lines = append(lines, l)
		subtotal += l.sub
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}

	if subtotal < 0 {
		subtotal = 0
	}
	if discount < 0 {
		discount = 0
	}
	if discount > subtotal {
		discount = subtotal
	}

	// Bucket lines by rate carrying each line's net base (subtotal − pro-rata
	// discount) — the exact input priceGroups groups on, so the group tax below
	// is byte-for-byte the order's.
	type grp struct {
		rate float64
		ids  []string
		nets []float64
	}
	groups := map[string]*grp{}
	var groupOrder []string
	for _, l := range lines {
		net := l.sub
		if subtotal > 0 {
			net = l.sub - discount*l.sub/subtotal
		}
		if net < 0 {
			net = 0
		}
		key := rateKey(l.rate)
		g := groups[key]
		if g == nil {
			g = &grp{rate: l.rate}
			groups[key] = g
			groupOrder = append(groupOrder, key)
		}
		g.ids = append(g.ids, l.id)
		g.nets = append(g.nets, net)
	}

	for _, key := range groupOrder {
		g := groups[key]
		netGroup := 0.0
		for _, n := range g.nets {
			netGroup += n
		}
		groupTax := GroupTaxFor(netGroup, g.rate, includeTax, tStep, taxMode)
		ideals := make([]float64, len(g.nets))
		for i, n := range g.nets {
			ideals[i] = LineTaxIdeal(n, g.rate, includeTax)
		}
		alloc := AllocateGroupTax(ideals, groupTax, tStep)
		for i, id := range g.ids {
			if _, err := tx.Exec(
				`UPDATE order_items SET tax_amount = ? WHERE id = ?`,
				int(math.Round(alloc[i])), id,
			); err != nil {
				return err
			}
		}
	}
	return nil
}

// refreshItemTaxAmounts re-reads the stored (re-stamped) per-line tax_amount for
// an order and patches the given in-memory items by id, so a create/add-item
// response returns the allocated figures rather than the createItem-time ones.
func (e *OrderEngine) refreshItemTaxAmounts(tx *sql.Tx, orderID string, items []Item) error {
	rows, err := tx.Query(`SELECT id, COALESCE(tax_amount, 0) FROM order_items WHERE customer_order_id = ?`, orderID)
	if err != nil {
		return err
	}
	defer rows.Close()
	byID := map[string]float64{}
	for rows.Next() {
		var id string
		var tax float64
		if err := rows.Scan(&id, &tax); err != nil {
			return err
		}
		byID[id] = tax
	}
	if err := rows.Err(); err != nil {
		return err
	}
	for i := range items {
		if t, ok := byID[items[i].ID]; ok {
			items[i].TaxAmount = t
		}
	}
	return nil
}

// joinComma is a tiny local helper that avoids a strings import in the
// SET-clause builders above — keeps this file self-contained.
func joinComma(parts []string) string {
	out := ""
	for i, p := range parts {
		if i > 0 {
			out += ", "
		}
		out += p
	}
	return out
}

// errOrderNotFound is exposed so handlers can distinguish missing-order from
// other errors when wrapping engine output. The engine itself still returns
// sql.ErrNoRows directly to avoid leaking abstraction at the storage layer.
var errOrderNotFound = errors.New("order not found")
