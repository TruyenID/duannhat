package service

import (
	"database/sql"
	"time"
)

// #1392 (phần còn lại của #1380) — the ORDER-path half of the spotlight.
//
// #1319 replicated the floating-section ("Khung giờ ưu đãi") tables and #1380
// served them over LAN; both are read-only surfaces. This file is what happens
// when the cashier actually taps one of those tiles: the line must be priced,
// taxed and topping-priced off the SPOTLIGHT row, not off the menu row for the
// same SKU.
//
// It matters because the two really do disagree. A product can sit on a menu
// AND in a spotlight with a different tax type and a different topping override
// (067_pos_floating_sections.sql spells out why each is a separate column), and
// `menu_items` already holds one row per menu line for one sku_id. Resolving by
// SKU with LIMIT 1 picks whichever row SQLite returns first — the same class of
// bug #1239 fixed for two menus, now between a menu and a spotlight. Picking
// wrong prints one rate on the customer's receipt and books another.
//
// The client (pos-web, #1320) names the surface by sending the membership id
// the mảnh-1 endpoint handed it (`floating_section_product_id`). That id is
// UNTRUSTED input: it is re-read here against the local replica and dropped
// unless it names an active membership whose product actually owns the SKU
// being sold. A dropped attribution degrades to exactly the pre-#1392
// behaviour rather than to a wrong price.

// floatingLine is a validated spotlight attribution for one order line.
type floatingLine struct {
	// ID is pos_floating_section_products.id — the membership row, i.e.
	// "this product, bought from this spotlight". Topping tier-1 is keyed by it.
	ID string
	// TaxTypeID is the tier ALREADY COLLAPSED by Cloud
	// (FloatingSectionProduct.tax_type_id ?? Product.tax_type_id) and mirrored
	// verbatim. "" means inherit — the resolver walks on to branch then brand,
	// exactly as it does for a menu line with no override. Nothing here
	// re-walks the tiers; a second implementation of that walk is a second
	// thing that can drift.
	TaxTypeID string
	// PromoPrice is pos_floating_section_product_skus.selling_price for the SKU
	// being sold. Valid=false when the membership carries no active price row
	// for this SKU: the spotlight then contributes its tax + topping tiers but
	// no price, and the ordinary catalogue price stands.
	PromoPrice sql.NullInt64
}

// resolveFloatingLine validates a client-supplied floating_section_product_id
// against the local replica and returns the attribution for this SKU.
//
// ok=false — and NOTHING is applied — when the id is empty, names no active
// membership, names one whose product is not the product this SKU belongs to,
// or names a spotlight whose window is NOT OPEN right now. The ownership check
// matters because without it a stale or mistyped id would apply one product's
// promo tax type to a different product's sale, and the line would look
// perfectly ordinary afterwards. The window check matters because the window
// closes on schedule every single day — see below.
func (e *OrderEngine) resolveFloatingLine(floatingSectionProductID, productSkuID string) (floatingLine, bool) {
	fl, st := e.resolveFloatingLineState(floatingSectionProductID, productSkuID)
	return fl, st == floatingApplies
}

// floatingState says WHY an attribution did or did not apply.
//
// resolveFloatingLine collapses every failure to one `false`, which is right
// for the sell path — nothing applies either way. The EDIT path needs the two
// apart, because they carry opposite instructions about the line's stamped tax
// type (#1392 review round 2):
//
//   - window shut → the sale is no longer a spotlight sale, so the whole
//     attribution goes, tax tier included;
//   - membership gone from the replica (a retired spotlight is wiped by the
//     next catalog pull) → audit-fix B5 says KEEP the line's stamped type. A
//     snapshot outlives the surface it came from; retiring a spotlight must
//     not silently re-rate lines already sold under it.
//
// Collapsing those two would either re-rate history or leave a line priced by
// the menu while taxed by a promotion that has ended.
type floatingState int

const (
	floatingApplies floatingState = iota
	floatingWindowClosed
	floatingNoMembership
)

func (e *OrderEngine) resolveFloatingLineState(floatingSectionProductID, productSkuID string) (floatingLine, floatingState) {
	if floatingSectionProductID == "" || productSkuID == "" {
		return floatingLine{}, floatingNoMembership
	}

	var (
		id        string
		taxTypeID sql.NullString
		sectionID string
	)
	// The join is the ownership check: the membership's product must be the
	// SKU's product. is_active on the membership matches the surface that
	// displayed it (loadFloatingProducts filters the same way), so a spotlight
	// switched off between the tap and the tap's arrival prices as an ordinary
	// line instead of at a promo price the shop no longer offers.
	err := e.db.QueryRow(`
		SELECT fsp.id, fsp.tax_type_id, fsp.floating_section_id
		FROM pos_floating_section_products fsp
		JOIN pos_product_skus ps ON ps.product_id = fsp.product_id
		WHERE fsp.id = ? AND ps.id = ? AND fsp.is_active = 1`,
		floatingSectionProductID, productSkuID,
	).Scan(&id, &taxTypeID, &sectionID)
	if err != nil {
		return floatingLine{}, floatingNoMembership
	}

	// The WINDOW, evaluated on the shop clock (review round 1 of #1392).
	//
	// The membership flag above only catches an operator switching a spotlight
	// off by hand — rare. The routine case is the window closing on schedule,
	// which happens every day: a POS panel loaded at 18:55 still shows the
	// 17:00-19:00 tile at 19:05 (the catalog is cached client-side), and
	// without this the tap would still be priced at the promo price.
	//
	// It would also put the device at odds with Cloud, which is the half that
	// actually books the money: FloatingSectionPriceResolver filters on
	// start_date/end_date, the day bitmask and the time window before it hands
	// out a promo price. Since sync-UP does NOT carry
	// floating_section_product_id and Cloud re-derives, a line sold here
	// outside the window would be re-priced there — two books, one sale.
	//
	// Closed → drop the WHOLE attribution, not just the price: a sale outside
	// the promo is not a spotlight sale, so its tax tier and topping tier are
	// the menu's too. That is the same "degrade to pre-#1392 behaviour" this
	// function already applies to an id it cannot vouch for.
	//
	// The evaluator is reused, never re-implemented — FloatingSectionOpenAt is
	// the same function the LAN endpoint uses to decide whether the tile is
	// shown at all, so display and sale cannot drift apart.
	if !e.floatingSectionOpenNow(sectionID) {
		return floatingLine{}, floatingWindowClosed
	}

	f := floatingLine{ID: id, TaxTypeID: taxTypeID.String}
	// The promo price lives in pos_floating_section_product_skus and ONLY
	// there; pos_product_skus.selling_price stays the menu price for the same
	// SKU (067 point 2). A membership with no active SKU row is a shape the
	// replica allows, so a missed lookup is not an error.
	_ = e.db.QueryRow(`
		SELECT selling_price
		FROM pos_floating_section_product_skus
		WHERE floating_section_product_id = ? AND product_sku_id = ? AND is_active = 1
		LIMIT 1`,
		id, productSkuID,
	).Scan(&f.PromoPrice)

	return f, floatingApplies
}

// floatingSectionOpenNow answers whether a spotlight is sellable at this
// instant, on the shop's own clock — the process runs on the shop PC, so
// time.Now() IS the business clock for "is the happy hour open".
//
// A section that has vanished from the replica reads as CLOSED: the catalog
// pull wipes and rewrites these tables, so a missing row means the spotlight is
// retired, and the safe reading of "I cannot tell" is "do not discount".
func (e *OrderEngine) floatingSectionOpenNow(sectionID string) bool {
	if sectionID == "" {
		return false
	}

	var (
		active             int
		startDate, endDate sql.NullString
	)
	if err := e.db.QueryRow(`
		SELECT is_active, COALESCE(start_date, ''), COALESCE(end_date, '')
		FROM pos_floating_sections WHERE id = ?`, sectionID,
	).Scan(&active, &startDate, &endDate); err != nil {
		return false
	}

	rows, err := e.db.Query(`
		SELECT days_of_week, start_time, end_time,
		       COALESCE(start_date, ''), COALESCE(end_date, ''), is_active
		FROM pos_floating_section_schedules
		WHERE floating_section_id = ?`, sectionID)
	if err != nil {
		return false
	}
	defer rows.Close()

	windows := []FloatingWindow{}
	for rows.Next() {
		var (
			w           FloatingWindow
			schedActive int
		)
		if err := rows.Scan(&w.DaysOfWeek, &w.StartTime, &w.EndTime,
			&w.StartDate, &w.EndDate, &schedActive); err != nil {
			return false
		}
		w.IsActive = schedActive == 1
		windows = append(windows, w)
	}
	if err := rows.Err(); err != nil {
		return false
	}

	return FloatingSectionOpenAt(time.Now(), active == 1, startDate.String, endDate.String, windows)
}

// floatingTaxTypeIDTx reads the collapsed tax tier of a membership inside a
// transaction — the re-resolution counterpart of resolveFloatingLine, used
// where the line ALREADY carries a validated attribution and only the tax input
// is wanted.
//
// found=false when the membership has gone from the replica (a retired
// spotlight is wiped by the next catalog pull). The caller then keeps the
// line's own stamped type rather than falling to the branch default, mirroring
// audit-fix B5: a snapshot outlives the surface it came from.
//
// No is_active filter, deliberately, and for the same reason the tax walk has
// none: deactivation blocks NEW assignment; it must not silently re-rate lines
// already pointing at the row.
func (e *OrderEngine) floatingTaxTypeIDTx(tx *sql.Tx, floatingSectionProductID string) (taxTypeID string, found bool) {
	if floatingSectionProductID == "" {
		return "", false
	}
	var tt sql.NullString
	if err := tx.QueryRow(
		`SELECT tax_type_id FROM pos_floating_section_products WHERE id = ? LIMIT 1`,
		floatingSectionProductID,
	).Scan(&tt); err != nil {
		return "", false
	}

	return tt.String, true
}

// toppingOwner names the surface whose tier-1 topping overrides apply to a
// line. Exactly one side is set: a spotlight line uses the spotlight's
// overrides INSTEAD of the menu's, never both.
//
// Cloud draws the same either/or — CustomerOrderPricingResolution hands the
// topping pricer the floating membership when the line named no menu line, and
// the menu_product_id when it did — so the guest is charged what the surface
// they were looking at displayed.
type toppingOwner struct {
	MenuProductID            string
	FloatingSectionProductID string
}

// menuToppingOwner is the ordinary (menu tile) owner.
func menuToppingOwner(menuProductID string) toppingOwner {
	return toppingOwner{MenuProductID: menuProductID}
}

// floatingToppingOwner is the spotlight owner. The menu tier is dropped, not
// merged: the spotlight is a complete tier-1 of its own.
func floatingToppingOwner(floatingSectionProductID string) toppingOwner {
	return toppingOwner{FloatingSectionProductID: floatingSectionProductID}
}

// tier1Override reads the tier-1 topping override row for this owner, keyed by
// (owner, topping_group_item_id, product_sku_id).
//
// Both tables carry the identical (override_price, is_hidden) shape and are
// read with identical semantics, including #1203: the CALLER decides whether a
// row "speaks" (a price, or a hide) — an empty row must not suppress tier-2
// merely by existing. Returning the raw nullables rather than a resolved price
// keeps that single decision in one place.
func (e *OrderEngine) tier1Override(owner toppingOwner, toppingGroupItemID, toppingSkuID string) (found bool, price, hidden sql.NullInt64) {
	if toppingGroupItemID == "" {
		return false, price, hidden
	}

	var (
		query string
		key   string
	)
	switch {
	case owner.FloatingSectionProductID != "":
		key = owner.FloatingSectionProductID
		query = `
			SELECT override_price, is_hidden
			FROM pos_floating_section_topping_overrides
			WHERE floating_section_product_id = ?
			  AND topping_group_item_id = ?
			  AND COALESCE(product_sku_id, '') = ?
			LIMIT 1`
	case owner.MenuProductID != "":
		key = owner.MenuProductID
		query = `
			SELECT override_price, is_hidden
			FROM pos_menu_product_topping_overrides
			WHERE menu_product_id = ?
			  AND topping_group_item_id = ?
			  AND COALESCE(product_sku_id, '') = ?
			LIMIT 1`
	default:
		return false, price, hidden
	}

	err := e.db.QueryRow(query, key, toppingGroupItemID, toppingSkuID).Scan(&price, &hidden)

	return err == nil, price, hidden
}
