package service

import (
	"database/sql"
	"encoding/json"
	"fmt"
)

// ─── plan-043 audit fixes 1.1 + 2.6 (2026-07-14) ─────────────────────────────
//
// 1.1 — an order_type flip (takeaway↔dine_in) on the LAN kept every line's old
// tax snapshot: Cloud re-resolves ALL lines on the flip
// (CustomerOrderService::reResolveOrderLines), Go did not, so a bentō stamped
// 8% takeaway stayed 8% after switching to dine-in (must be 10%) — wrong local
// totals and receipts until the cloud sync corrected them. UpdateMeta now
// re-resolves + recalculates when the type changes.
//
// 2.6 — an order created OFFLINE before the first tax-type sync stamps its
// lines under the legacy fallback (tax_rate NULL). Once the types arrive those
// lines stayed NULL forever (Cloud has the BUG-8 lazy re-stamp; Go had no
// equivalent). recalcOrderTotals now re-resolves unstamped lines on the next
// mutation when tax types exist locally — the same trigger semantics as Cloud.

// lineToppingsHaveAlcohol is the stored-toppings variant of
// toppingsHaveAlcohol: it reads the line's persisted topping rows and reports
// whether any references an alcohol component (A1 escalation input).
func (e *OrderEngine) lineToppingsHaveAlcohol(tx *sql.Tx, itemID string) bool {
	rows, err := tx.Query(`
		SELECT COALESCE(topping_group_item_id, ''), COALESCE(product_sku_id, '')
		FROM order_item_toppings WHERE order_item_id = ?`, itemID)
	if err != nil {
		return false
	}
	defer rows.Close()

	var toppings []ToppingInput
	for rows.Next() {
		var tgi, sku string
		if err := rows.Scan(&tgi, &sku); err != nil {
			continue
		}
		toppings = append(toppings, ToppingInput{ToppingGroupItemID: tgi, ProductSkuID: sku})
	}
	return e.toppingsHaveAlcohol(tx, toppings)
}

// hasSyncedTaxTypesTx reports whether any active tax type is mirrored locally
// (the lazy re-stamp precondition — without types re-resolution would just
// reproduce the legacy fallback).
func (e *OrderEngine) hasSyncedTaxTypesTx(tx *sql.Tx) bool {
	var n int
	_ = tx.QueryRow(`SELECT COUNT(*) FROM tax_types WHERE is_active = 1`).Scan(&n)
	return n > 0
}

// reResolveOrderLines re-resolves the tax snapshot (tax_type_id / tax_rate /
// tax_alcohol_escalated) of the order's non-voided lines for the given order
// type — the Go mirror of Cloud's CustomerOrderService::reResolveOrderLines.
// With onlyUnstamped=true it touches just the legacy NULL-rate lines (lazy
// re-stamp); with false it re-stamps every line (order_type change).
// tax_amount is NOT written here — the caller's recalc allocates it per rate
// group right after (stampLineTaxAmounts).
//
// Round-3 audit B4/B5 (2026-07-14):
//   - B5: a line whose product dropped off the local menu mirror used to fall
//     through to the branch/brand DEFAULT — Cloud re-resolves from
//     product.taxType, which outlives the menu. When the menu row is gone the
//     line's EXISTING tax_type_id is used as the tier-1 input instead (with
//     the prior escalation flag standing in for the unknowable product
//     is_alcohol, so alcohol escalation stays sticky).
//   - B4: a stamped line is never UN-stamped — if resolution falls to the
//     legacy no-type fallback (e.g. tax_types vanished locally), the existing
//     snapshot is kept rather than overwritten with NULL.
func (e *OrderEngine) reResolveOrderLines(tx *sql.Tx, orderID, orderType string, onlyUnstamped bool) error {
	type line struct {
		id        string
		skuID     string
		menuID    string
		taxRate   sql.NullFloat64
		taxTypeID sql.NullString
		escalated int
	}

	rows, err := tx.Query(`
		SELECT id, COALESCE(product_sku_id, ''), COALESCE(menu_item_id, ''),
		       tax_rate, tax_type_id, COALESCE(tax_alcohol_escalated, 0)
		FROM order_items
		WHERE customer_order_id = ? AND (status IS NULL OR status != 'voided')`, orderID)
	if err != nil {
		return fmt.Errorf("re-resolve: list lines: %w", err)
	}
	var lines []line
	for rows.Next() {
		var l line
		if err := rows.Scan(&l.id, &l.skuID, &l.menuID, &l.taxRate, &l.taxTypeID, &l.escalated); err != nil {
			rows.Close()
			return err
		}
		lines = append(lines, l)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}

	for _, l := range lines {
		if onlyUnstamped && l.taxRate.Valid {
			continue
		}

		lookupID := l.skuID
		if lookupID == "" {
			lookupID = l.menuID
		}
		taxTypeID, isAlcohol, found := e.menuItemTaxFieldsAnyState(tx, lookupID)
		if !found && l.taxTypeID.Valid && l.taxTypeID.String != "" {
			// B5 — menu row gone: keep the line's own type as the override
			// input (Cloud parity: product.taxType persists off-menu). The
			// stored escalation flag proxies the product's is_alcohol so a
			// previously-escalated alcohol line can't silently de-escalate.
			taxTypeID = l.taxTypeID.String
			isAlcohol = l.escalated == 1
		}

		hasAlcoholComponent := e.lineToppingsHaveAlcohol(tx, l.id)
		res := e.resolveLineTax(taxTypeID, isAlcohol, hasAlcoholComponent, orderType)

		// B4 — never un-stamp: falling back to the legacy no-type resolution
		// must not erase an existing snapshot (it would re-price the line at
		// the drifting legacy rate and lose the type reference).
		if !res.HasSnapshot && l.taxRate.Valid {
			continue
		}

		if _, err := tx.Exec(`
			UPDATE order_items SET tax_type_id = ?, tax_rate = ?, tax_alcohol_escalated = ?
			WHERE id = ? AND (status IS NULL OR status != 'voided')`,
			res.taxTypeIDNullable(), res.taxRateNullable(), res.escalatedInt(), l.id,
		); err != nil {
			return fmt.Errorf("re-resolve: stamp line %s: %w", l.id, err)
		}
	}
	return nil
}

// jsonToBool decodes a JSON-decoded value into a bool, accepting the shapes a
// boolean can arrive in over the wire: true/false, 0/1 numbers, and
// json.Number. Round-3 audit B8 — the reconcile adoption of
// tax_alcohol_escalated used a bare bool type-assert, so a Cloud that ever
// serialized 0/1 would silently skip the field.
func jsonToBool(v any) (val, ok bool) {
	switch b := v.(type) {
	case bool:
		return b, true
	case float64:
		return b != 0, true
	case int:
		return b != 0, true
	case json.Number:
		f, err := b.Float64()
		if err != nil {
			return false, false
		}
		return f != 0, true
	}
	return false, false
}

// menuItemTaxFieldsAnyState reads the per-line resolution inputs for a
// menu_items row like menuItemTaxFields, but (a) reports whether a row was
// found at all and (b) accepts INACTIVE rows — an existing order line keeps
// its historical reference; deactivation only blocks NEW assignment
// (docs/guide/tax-types.md "deactivate over delete").
func (e *OrderEngine) menuItemTaxFieldsAnyState(tx *sql.Tx, lookupID string) (taxTypeID string, isAlcohol bool, found bool) {
	if lookupID == "" {
		return "", false, false
	}
	var tt sql.NullString
	var alc sql.NullInt64
	err := tx.QueryRow(
		`SELECT tax_type_id, is_alcohol FROM menu_items WHERE sku_id = ? LIMIT 1`, lookupID,
	).Scan(&tt, &alc)
	if err != nil {
		err = tx.QueryRow(
			`SELECT tax_type_id, is_alcohol FROM menu_items WHERE id = ? LIMIT 1`, lookupID,
		).Scan(&tt, &alc)
	}
	if err != nil {
		return "", false, false
	}
	return tt.String, alc.Valid && alc.Int64 == 1, true
}
