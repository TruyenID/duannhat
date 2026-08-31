package service

import (
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"
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

	// #1099 — an order_type flip does NOT touch tax anymore: a tax type is
	// ONE rate, context lives on the menu line the customer ordered from
	// (the old audit-fix 1.1 re-resolve is intentionally gone). The recalc
	// stays inside the same transaction purely so totals/service-charge
	// pick up any other meta field that feeds them.
	if input.OrderType != nil && *input.OrderType != order.OrderType {
		if err := e.db.Transaction(func(tx *sql.Tx) error {
			if _, err := tx.Exec(query, args...); err != nil {
				return fmt.Errorf("update order meta: %w", err)
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
				  AND o.status NOT IN `+SQLStatusTerminal+`
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
	ProductSkuID     *string         `json:"product_sku_id,omitempty"`
	MenuProductSkuID *string         `json:"menu_product_sku_id,omitempty"`
	Quantity         *int            `json:"quantity,omitempty"`
	Note             *string         `json:"note,omitempty"`
	Status           *string         `json:"status,omitempty"`
	Toppings         *[]ToppingInput `json:"toppings,omitempty"`
}

// UnmarshalJSON preserves an explicit `"note": null` as an empty-string
// update. A plain *string cannot distinguish JSON null from an omitted field,
// but POS edit mode needs null to clear a previously saved kitchen note.
func (p *ItemPatch) UnmarshalJSON(data []byte) error {
	type itemPatchAlias ItemPatch
	var decoded itemPatchAlias
	if err := json.Unmarshal(data, &decoded); err != nil {
		return err
	}
	*p = ItemPatch(decoded)

	var fields map[string]json.RawMessage
	if err := json.Unmarshal(data, &fields); err != nil {
		return err
	}
	if raw, ok := fields["note"]; ok && string(raw) == "null" {
		empty := ""
		p.Note = &empty
	}
	return nil
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

// ErrInvalidItemSKU indicates that the requested replacement variant is not
// present and active in the local POS catalog.
var ErrInvalidItemSKU = errors.New("invalid item product sku")

// ErrItemSKUProductMismatch prevents PATCH from turning a line into an
// unrelated product. Edit mode may replace variants/options only.
var ErrItemSKUProductMismatch = errors.New("replacement sku belongs to another product")

// ErrVoidReasonRequired — #1148: voiding a line the kitchen already owns
// (preparing/ready/served, allow_item_edit_any_status ON) demands a real
// operator-entered reason for the audit/stock trail.
var ErrVoidReasonRequired = errors.New("a reason is required when voiding an item that has already been prepared")

// ErrItemSKUImmutable — #1148 decision: a line's SKU cannot be edited in
// place; void the line (with reason) and add a new item. Keeps the kitchen
// trail + recipe/stock genealogy of the original SKU honest.
var ErrItemSKUImmutable = errors.New("item sku cannot be edited in place; void the line and add a new item")

// ErrItemEditRequiresPending mirrors BR-OI05 for the edit path:
// SKU / quantity / note / toppings changes are only allowed while the line
// is still `pending` (kitchen hasn't picked it up). Cloud's body is
// "Can only change quantity/note/toppings when item is pending."
// Handler maps to 409.
var ErrItemEditRequiresPending = errors.New("can only edit sku/quantity/note/toppings when item is pending")

// UpdateItem patches a line item on an order. Two distinct paths:
//
//   - Selection/header edits (SKU / toppings / quantity / note): require
//     order=open AND item=pending. SKU, price, promotion, tax, topping
//     snapshots and totals are replaced atomically.
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

	// #1148 — SKU is immutable on a line: reject before any other gate so
	// the caller gets the actionable "void + re-add" contract, mirroring
	// Cloud's 409 on the same keys.
	if patch.ProductSkuID != nil || patch.MenuProductSkuID != nil {
		return nil, ErrItemSKUImmutable
	}

	// BR-OI05 edit gate: topping/header edits require the order
	// still be open (or `confirmed` — a counter-pay takeaway staff adjusts
	// at the counter before payment, mirroring Cloud's updateItem gate)
	// AND the item still pending. Status-only patches bypass this gate
	// (KDS / waiter bumps an in-flight item).
	editingSelection := patch.Toppings != nil
	editingLine := editingSelection || patch.Quantity != nil || patch.Note != nil
	if editingLine {
		if order.Status != StatusOpen && order.Status != StatusConfirmed {
			return nil, ErrOrderNotOpen
		}
		// #1148 tightened — once the kitchen owns the line it cannot be
		// EDITED at all (the flag now governs VOIDING only): void with a
		// real reason + add a new item.
		if found.Status != "pending" {
			return nil, ErrItemEditRequiresPending
		}
	}

	now := time.Now().UTC().Format(time.RFC3339)
	productSkuID := found.ProductSkuID
	menuItemID := found.MenuItemID
	menuItemName := found.MenuItemName
	skuVariantName := found.SkuVariantName
	printerGroup := found.PrinterGroup
	unitPrice := found.UnitPrice
	toppingSubtotal := found.ToppingSubtotal
	originalUnitPrice := any(nil)
	promotionID := found.PromotionID
	promotionLabel := found.PromotionLabel

	// #1392 review round 2 — resolve the surface ONCE and let price, topping
	// tier and tax tier all read the SAME answer. While the resolver was
	// time-blind three separate calls could not disagree; now that it is
	// window-aware they can, and the line ends up priced by the menu but taxed
	// by a promotion that has already ended. createItem resolves once for the
	// whole line for exactly this reason.
	floatingFL := floatingLine{}
	floatingSt := floatingNoMembership

	if editingSelection {
		if patch.ProductSkuID != nil {
			productSkuID = *patch.ProductSkuID
		}
		if productSkuID == "" {
			return nil, ErrInvalidItemSKU
		}

		var (
			newProductID string
			productName  string
			variantName  sql.NullString
			sellingPrice int
			oldProductID string
		)
		if err := e.db.QueryRow(`
			SELECT ps.product_id, p.name, ps.name, ps.selling_price
			FROM pos_product_skus ps
			JOIN pos_products p ON p.id = ps.product_id
			WHERE ps.id = ? AND ps.is_active = 1 AND p.is_active = 1`,
			productSkuID,
		).Scan(&newProductID, &productName, &variantName, &sellingPrice); err != nil {
			return nil, ErrInvalidItemSKU
		}
		_ = e.db.QueryRow(
			`SELECT product_id FROM pos_product_skus WHERE id = ?`,
			found.ProductSkuID,
		).Scan(&oldProductID)
		if oldProductID != "" && oldProductID != newProductID {
			return nil, ErrItemSKUProductMismatch
		}

		menuItemID = productSkuID
		printerGroup = found.PrinterGroup
		_ = e.db.QueryRow(`
			SELECT id, printer_group
			FROM menu_items
			WHERE sku_id = ? AND is_active = 1
			ORDER BY id
			LIMIT 1`,
			productSkuID,
		).Scan(&menuItemID, &printerGroup)
		if printerGroup == "" {
			printerGroup = "kitchen"
		}

		skuVariantName = variantName.String
		menuItemName = productName
		if variantName.Valid && variantName.String != "" {
			menuItemName = fmt.Sprintf("%s · %s", productName, variantName.String)
		}

		unitPrice = sellingPrice
		// #1392 — a spotlight line stays a spotlight line across a variant
		// swap (the membership is per PRODUCT, and a cross-product swap is
		// already refused above), but the promo PRICE is per SKU: the new
		// variant may have no promo row, in which case the ordinary price is
		// the right one. Same LOWER-only precedence as createItem.
		floatingFL, floatingSt = e.resolveFloatingLineState(found.FloatingSectionProductID, productSkuID)
		if floatingSt == floatingApplies && floatingFL.PromoPrice.Valid {
			if promo := int(floatingFL.PromoPrice.Int64); promo < unitPrice {
				unitPrice = promo
			}
		}
		// The promotion applies to whatever the surface priced the line at —
		// menu price, or the spotlight's lower one — mirroring Cloud, which
		// runs MenuPromotionService on the already-min'ed raw unit price.
		originalPrice := unitPrice
		promotionID = ""
		promotionLabel = ""
		if e.promoEng != nil {
			final, match, promotionErr := e.promoEng.ApplyToItem(productSkuID, originalPrice, time.Now().UTC())
			if promotionErr == nil && match != nil && final != originalPrice {
				unitPrice = final
				promotionID = match.ID
				promotionLabel = match.Name
				originalUnitPrice = originalPrice
			}
		}
	}

	replaceToppings := patch.Toppings != nil || editingSelection
	toppings := []ToppingInput{}
	if patch.Toppings != nil {
		toppings = append(toppings, (*patch.Toppings)...)
	} else if editingSelection {
		// Other clients may replace only the SKU. Preserve the choices, but
		// intentionally drop their cached monetary/name snapshots so they are
		// resolved again for the fresh parent selection.
		for _, topping := range found.Toppings {
			toppings = append(toppings, ToppingInput{
				ToppingGroupItemID: topping.ToppingGroupItemID,
				ProductSkuID:       topping.ProductSkuID,
				Quantity:           topping.Quantity,
				Note:               topping.Note,
			})
		}
	}
	if replaceToppings {
		// #1392 — same either/or as createItem: the surface the line was sold
		// from owns its tier-1 topping overrides.
		owner := menuToppingOwner(e.menuProductIDForSku(productSkuID))
		if floatingSt == floatingApplies {
			owner = floatingToppingOwner(floatingFL.ID)
		}
		for i := range toppings {
			e.resolveToppingSnapshot(&toppings[i], productSkuID, owner)
		}
		groupPricing := e.loadToppingGroupPricing(toppings)
		priced := make([]pricedTopping, 0, len(toppings))
		for _, topping := range toppings {
			priced = append(priced, pricedTopping{
				ToppingGroupID: topping.ToppingGroupID,
				UnitPrice:      topping.UnitPrice,
				Quantity:       topping.Quantity,
			})
		}
		toppingSubtotal = priceLineAcrossGroups(priced, groupPricing)
		if toppingSubtotal < -unitPrice {
			toppingSubtotal = -unitPrice
		}
	}

	quantity := found.Quantity
	if patch.Quantity != nil {
		quantity = *patch.Quantity
		if quantity < 1 {
			return nil, fmt.Errorf("quantity must be >= 1")
		}
	}

	sets := []string{"updated_at = ?"}
	args := []any{now}
	recalc := patch.Quantity != nil || replaceToppings || editingSelection
	if editingSelection {
		sets = append(sets,
			"product_sku_id = ?",
			"menu_item_id = ?",
			"menu_item_name = ?",
			"sku_variant_name = ?",
			"unit_price = ?",
			"original_unit_price = ?",
			"promotion_id = ?",
			"promotion_label = ?",
		)
		args = append(args,
			productSkuID,
			nullableString(menuItemID),
			menuItemName,
			nullableString(skuVariantName),
			unitPrice,
			originalUnitPrice,
			nullableString(promotionID),
			nullableString(promotionLabel),
		)
	}
	if replaceToppings {
		sets = append(sets, "topping_subtotal = ?")
		args = append(args, toppingSubtotal)
	}
	if patch.Quantity != nil {
		sets = append(sets, "quantity = ?")
		args = append(args, quantity)
	}
	if recalc {
		sets = append(sets, "subtotal = ?")
		args = append(args, quantity*(unitPrice+toppingSubtotal))
	}
	if patch.Note != nil {
		sets = append(sets, "note = ?")
		args = append(args, nullableString(*patch.Note))
	}
	if editingSelection || patch.Toppings != nil || patch.Note != nil {
		// The kitchen-facing selection changed. Mark it unprinted so a saved
		// edit is not hidden behind the previous ticket snapshot.
		sets = append(sets, "print_status = ?", "printed_quantity = 0", "printed_at = NULL")
		args = append(args, string(PrintStatusPending))
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

	err = e.db.Transaction(func(tx *sql.Tx) error {
		txArgs := append(args, itemID)
		if _, execErr := tx.Exec(
			"UPDATE order_items SET "+joinComma(sets)+" WHERE id = ?", txArgs...,
		); execErr != nil {
			return fmt.Errorf("update item: %w", execErr)
		}

		if replaceToppings {
			if _, execErr := tx.Exec(
				`DELETE FROM order_item_toppings WHERE order_item_id = ?`,
				itemID,
			); execErr != nil {
				return fmt.Errorf("clear item toppings: %w", execErr)
			}
			for _, topping := range toppings {
				if topping.Quantity <= 0 {
					topping.Quantity = 1
				}
				modifierType := topping.ModifierType
				if modifierType == "" {
					modifierType = "add"
				}
				if _, execErr := tx.Exec(`
					INSERT INTO order_item_toppings (
						id, order_item_id, topping_group_item_id, product_sku_id,
						name, modifier_type, topping_group_id, topping_group_name,
						quantity, unit_price, note, created_at
					) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
					uuid.New().String(), itemID, topping.ToppingGroupItemID, topping.ProductSkuID,
					nullableString(topping.Name), modifierType,
					nullableString(topping.ToppingGroupID), nullableString(topping.ToppingGroupName),
					topping.Quantity, topping.UnitPrice, nullableString(topping.Note), now,
				); execErr != nil {
					return fmt.Errorf("replace item topping: %w", execErr)
				}
			}
		}

		if editingSelection {
			// #1239 — re-stamp from the MENU LINE this order line came from.
			// order_items.menu_item_id records it, and sku_id is not unique in
			// menu_items once more than one menu is active, so resolving by SKU
			// could re-stamp a takeaway line at the dine-in rate. Falls back to
			// the SKU when the line has no menu row (local-only, kiosk flat).
			// #1392 — a spotlight line re-stamps from its MEMBERSHIP, never
			// from menu_items: the surface that priced it is the surface that
			// rates it. A membership gone from the replica (retired spotlight)
			// keeps the line's existing type rather than dropping to the branch
			// default — the same B5 rule reResolveOrderLines applies when a
			// menu row disappears.
			var tier1 string
			if floatingSt == floatingWindowClosed {
				// #1392 review round 2 — the window shut between the sale and
				// this edit. The price and the topping tier above already fell
				// back to the menu (resolveFloatingLine is window-aware), so
				// taking the tax from the membership here would leave ONE line
				// carrying a menu price and a promo rate. createItem never
				// produces that shape — it drops the whole attribution when the
				// surface does not resolve — and the edit path must not either.
				//
				// Strip the column too, not just the rate: Cloud re-derives
				// from the menu (sync-UP does not carry the membership id), so
				// a line still flagged as a spotlight would be re-stamped at
				// the promo rate by the next reResolveOrderLines and disagree
				// with Cloud's own books.
				if _, execErr := tx.Exec(
					`UPDATE order_items SET floating_section_product_id = NULL WHERE id = ?`, itemID,
				); execErr != nil {
					return fmt.Errorf("clear closed spotlight attribution: %w", execErr)
				}
				taxLookupID := ""
				_ = tx.QueryRow(
					`SELECT COALESCE(menu_item_id, '') FROM order_items WHERE id = ?`, itemID,
				).Scan(&taxLookupID)
				if taxLookupID == "" {
					taxLookupID = productSkuID
				}
				tier1 = e.menuItemTaxTypeID(tx, taxLookupID)
			} else if fsID, ok := e.floatingTaxTypeIDTx(tx, found.FloatingSectionProductID); ok {
				tier1 = fsID
			} else if found.FloatingSectionProductID != "" {
				tier1 = found.TaxTypeID
			} else {
				taxLookupID := ""
				_ = tx.QueryRow(
					`SELECT COALESCE(menu_item_id, '') FROM order_items WHERE id = ?`, itemID,
				).Scan(&taxLookupID)
				if taxLookupID == "" {
					taxLookupID = productSkuID
				}
				tier1 = e.menuItemTaxTypeID(tx, taxLookupID)
			}
			resolution := e.resolveLineTax(tier1)
			if _, execErr := tx.Exec(
				`UPDATE order_items SET tax_type_id = ?, tax_rate = ? WHERE id = ?`,
				resolution.taxTypeIDNullable(), resolution.taxRateNullable(), itemID,
			); execErr != nil {
				return fmt.Errorf("update item tax snapshot: %w", execErr)
			}
		}
		if recalc {
			return e.recalcOrderTotalsTx(tx, orderID)
		}
		return nil
	})
	if err != nil {
		return nil, err
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
//
// plan-051 — the boolean gate became a per-status matrix; VoidItem now
// returns *ItemStatusNotVoidableError, whose Is() matches BOTH this legacy
// sentinel and ErrItemStatusNotVoidable so existing errors.Is callers keep
// working during the transition.
var ErrItemNotPending = errors.New("item not in pending status")

// ErrItemStatusNotVoidable — plan-051 (#1149): the item's status is not in
// the shop's resolved voidable-status matrix (item_voidable_statuses, with
// the legacy allow_item_edit_any_status flag as the old-Cloud fallback).
// Handlers map this to 409 with code ITEM_STATUS_NOT_VOIDABLE, mirroring
// Cloud's voidItem gate.
var ErrItemStatusNotVoidable = errors.New("item status is not voidable under the shop's void policy")

// ItemStatusNotVoidableError carries the offending status + the resolved
// matrix so the LAN handler can put the voidable list in the 409 payload
// (pos-web renders which statuses ARE voidable).
type ItemStatusNotVoidableError struct {
	Status           string
	VoidableStatuses []string
}

func (e *ItemStatusNotVoidableError) Error() string {
	return fmt.Sprintf("item status %q is not voidable (voidable: %s)",
		e.Status, strings.Join(e.VoidableStatuses, ", "))
}

// Is lets errors.Is match both the new sentinel and the legacy
// ErrItemNotPending sentinel (pre-plan-051 callers/tests).
func (e *ItemStatusNotVoidableError) Is(target error) bool {
	return target == ErrItemStatusNotVoidable || target == ErrItemNotPending
}

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
//
// plan-051 — the item-status gate is now the resolved per-shop matrix
// (see voidableItemStatuses); VoidItem keeps the 3-arg signature for
// existing callers and delegates to VoidItemWithReasonID with no picked
// reason.
func (e *OrderEngine) VoidItem(orderID, itemID string, reason string) (*Order, error) {
	return e.VoidItemWithReasonID(orderID, itemID, reason, "")
}

// VoidItemWithReasonID is VoidItem plus an optional picked VoidReason id
// (plan-051 #1149). The id is stored on the local row (void_reason_id) and
// rides the order.item_void sync op so Cloud can resolve stock compensation
// (stock_effect) from the master row. Gate semantics:
//
//   - item status must be in the resolved voidable matrix, else
//     *ItemStatusNotVoidableError (409 ITEM_STATUS_NOT_VOIDABLE).
//   - non-pending void still demands a REAL reason (#1148): either a
//     non-junk reason text, or a void_reason_id that resolves in the
//     mirrored VoidReason master (Cloud applies the same OR). An
//     unresolvable id never blocks — it degrades to the text requirement,
//     mirroring Cloud's converge-not-reject handling.
func (e *OrderEngine) VoidItemWithReasonID(orderID, itemID string, reason, voidReasonID string) (*Order, error) {
	order, err := e.GetByID(orderID)
	if err != nil {
		return nil, err
	}
	// `confirmed` mirrors Cloud's voidItem gate: a counter-pay takeaway is
	// trimmable at the counter before payment.
	if order.Status != StatusOpen && order.Status != StatusConfirmed {
		return nil, ErrOrderNotOpen
	}
	// Look up the item BEFORE the UPDATE so we can return distinct
	// errors for the three cases: not found / already past pending /
	// already voided (idempotent).
	var currentStatus string
	var alreadyVoided, refundOf sql.NullString
	if err := e.db.QueryRow(
		`SELECT status, voided_at, refund_of_item_id
		   FROM order_items
		   WHERE id = ? AND customer_order_id = ?`,
		itemID, orderID,
	).Scan(&currentStatus, &alreadyVoided, &refundOf); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, sql.ErrNoRows
		}
		return nil, fmt.Errorf("void item lookup: %w", err)
	}
	// #2193 — TRƯỚC mọi ma trận trạng thái, như Cloud (#2173): dòng hoàn không
	// bao giờ void được. Không có chốt này, máy trạm offline ghi nhận void
	// thành công rồi op sync-UP bị Cloud 409 — và thu ngân đã thấy "đã huỷ".
	if refundOf.Valid && refundOf.String != "" {
		return nil, ErrCannotVoidRefundLine
	}
	if alreadyVoided.Valid && alreadyVoided.String != "" {
		// Already voided — idempotent, just return the current order.
		return e.GetByID(orderID)
	}
	// Per-status matrix gate (plan-051, supersedes the BR-OI05 boolean):
	// the item's status must be in the shop's resolved voidable set.
	// Voiding past pending still demands a real operator-entered reason
	// (#1148): the dish physically exists, so the audit/stock trail must
	// say why it was written off. A picked VoidReason id that resolves in
	// the mirrored master counts as a real reason.
	if ItemStatus(currentStatus) != ItemStatusPending {
		voidable := e.voidableItemStatuses()
		if !slices.Contains(voidable, currentStatus) {
			return nil, &ItemStatusNotVoidableError{
				Status:           currentStatus,
				VoidableStatuses: voidable,
			}
		}
		trimmed := strings.TrimSpace(reason)
		junkText := trimmed == "" || trimmed == "voided_by_workstation" || trimmed == "Removed by staff"
		if junkText && !e.voidReasonIDResolves(voidReasonID) {
			return nil, ErrVoidReasonRequired
		}
	}

	now := time.Now().UTC().Format(time.RFC3339)
	if _, err := e.db.Exec(
		`UPDATE order_items
		   SET status = 'voided', voided_at = ?, void_reason = ?, void_reason_id = ?, updated_at = ?
		   WHERE id = ? AND customer_order_id = ?`,
		now, nullableString(reason), nullableString(voidReasonID), now, itemID, orderID,
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
	subtotal, _, tax, serviceCharge, total, pricing, err := e.computeOrderTotalsFromDB(tx, orderID)
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
	// #2083 — truyền `pricing.Discount` (ĐÃ KẸP về subtotal bởi `priceGroups`),
	// KHÔNG phải `orders.discount_amount` thô. Đơn ¥1.000 mang
	// `discount_amount` ¥5.000 thì sổ Cloud ghi −1.000 còn sổ ở đây từng ghi
	// −5.000, tức bất biến `total = subtotal + Σ(conditions)` VỠ bên máy trạm.
	// Cột giữ số YÊU CẦU, sổ giữ số THỰC TẾ — cùng luật với Cloud.
	//
	// #2032 — (tái) ghi sổ điều kiện từ chính kết quả vừa tính. Trước bài này
	// đơn máy trạm tự tạo có sổ TRỐNG cho tới khi sync UP và Cloud tính lại, nên
	// POS/KDS hiển thị "không thuế, không giảm giá" trong khi giấy nói ngược lại.
	if err := e.writeOrderConditionsTx(tx, orderID, pricing, pricing.Discount); err != nil {
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

	// #2232 (nửa Go của #2182) — khoản giảm ÁP DỤNG được kẹp theo giỏ SỐNG
	// (gộp − đã hoàn) trước khi phân bổ, mirror Cloud applyPricing's
	// `$appliedDiscount = min($discountAmount, $liveSubtotal)`. Phép kẹp
	// min(discount, subtotal) bên trong allocateLineTaxes chỉ thấy tổng GỘP các
	// dòng DƯƠNG — con số ấy không co lại khi hàng được trả (dòng hoàn là dòng
	// âm riêng), nên giỏ đã hoàn hết vẫn bị trừ nguyên khoản giảm và Σ thuế
	// từng dòng lệch khỏi tax_amount của đơn.
	live, err := e.liveGrossSubtotal(tx, orderID)
	if err != nil {
		return err
	}
	if discount > live {
		discount = live
	}

	allocated, err := e.allocateLineTaxes(tx, orderID, discount, includeTax)
	if err != nil {
		return err
	}
	for id, tax := range allocated {
		if _, err := tx.Exec(
			`UPDATE order_items SET tax_amount = ? WHERE id = ?`,
			int(math.Round(tax)), id,
		); err != nil {
			return err
		}
	}
	return nil
}

// allocateLineTaxes is the allocation behind stampLineTaxAmounts, split out —
// the port of Cloud's WritesCustomerOrders::allocateLineTaxes (#2182) — so it
// serves two roles WITHOUT writing anything: the stamp path above, and the
// refund path (#2232), which needs the original line's GROSS tax share (same
// allocator, discount = 0). Recomputing round(subtotal × rate) per line is a
// DIFFERENT operation: largest-remainder can leave a line carrying a tax that
// cannot be re-derived from itself (three ¥1,005 lines @10% ⇒ 101/101/100),
// and Σ of the per-line recomputes does not equal the group tax.
//
// plan-045 — the per-line allocation rounds the GROUP tax with the order's
// snapshot rule (never the live setting), then apportions by largest
// remainder. Refund lines are EXCLUDED (they carry a copied+negated tax
// snapshot that must NOT be re-derived through the ≥0 allocator).
// #2188 — unstamped (NULL tax_rate) lines are EXCLUDED too: they belong to
// no rate group (the engine dropped them, with a warning, in
// computeOrderTotalsFromDB), so their tax_amount is left untouched.
func (e *OrderEngine) allocateLineTaxes(q rowQueryer, orderID string, discount float64, includeTax bool) (map[string]float64, error) {
	taxMode, taxDecimals := e.orderRoundingSnapshot(q, orderID)
	tStep := taxStep(taxDecimals, e.currencyCode())

	rows, err := q.Query(
		`SELECT id, tax_rate AS rate,
		        (quantity * (unit_price + COALESCE(topping_subtotal, 0))) AS sub
		 FROM order_items
		 WHERE customer_order_id = ? AND tax_rate IS NOT NULL
		   AND (status IS NULL OR status != 'voided')
		   AND (refund_of_item_id IS NULL OR refund_of_item_id = '')`,
		orderID,
	)
	if err != nil {
		return nil, err
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
			return nil, err
		}
		lines = append(lines, l)
		subtotal += l.sub
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, err
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

	out := map[string]float64{}
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
			out[id] = alloc[i]
		}
	}
	return out, nil
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
