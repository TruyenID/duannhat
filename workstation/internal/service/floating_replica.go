package service

import (
	"database/sql"
	"strings"
	"time"
)

// #1180 / #1319 — the spotlight ("Khung giờ ưu đãi") replica.
//
// Cloud has been emitting five floating-section arrays on
// `GET /workstation/menu-catalog` since ca373cdec; nothing on this side stored
// them, so an offline shop could not show a spotlight at all and a product that
// exists ONLY in one was invisible on the POS.
//
// Three invariants this file exists to protect, each a money bug if broken:
//
//  1. tax tiers are NOT re-walked here. `floating_section_products.tax_type_id`
//     arrives already collapsed by Cloud
//     (`FloatingSectionProduct.tax_type_id ?? Product.tax_type_id`). A second
//     implementation of that walk is a second thing that can drift, and drift
//     here prints one rate and books another. NULL means inherit — the device
//     resolver carries on to branch then brand default, exactly as Cloud does.
//
//  2. two prices stay two prices. The promo price is
//     `floating_section_product_skus.selling_price`; `skus.selling_price` remains
//     what that SKU costs from a normal menu.
//
//  3. the window is evaluated HERE, against the shop clock, not by Cloud. The
//     feed ships schedules raw because a workstation runs for hours between
//     pulls — a pre-filtered "open now" would be stale minutes later.

// floatingSectionRow mirrors one `floating_sections` entry. Per-locale names are
// flat on the row: Cloud pre-joins floating_section_translations, and POS runs
// three languages, so dropping them would show the base name in two of them.
type floatingSectionRow struct {
	ID     string  `json:"id"`
	Name   string  `json:"name"`
	NameJa *string `json:"name_ja"`
	NameEn *string `json:"name_en"`
	NameVi *string `json:"name_vi"`
	// Lower = shown first, same convention as menus.sort_order.
	Priority  int     `json:"priority"`
	IsActive  bool    `json:"is_active"`
	StartDate *string `json:"start_date"`
	EndDate   *string `json:"end_date"`
}

type floatingSectionScheduleRow struct {
	ID                string `json:"id"`
	FloatingSectionID string `json:"floating_section_id"`
	// Bitmask 1 << dayOfWeek with 0 = Sunday — the encoding Cloud's
	// FloatingSectionPriceResolver matches against. NOT a list of day names.
	DaysOfWeek int     `json:"days_of_week"`
	StartTime  string  `json:"start_time"`
	EndTime    string  `json:"end_time"`
	StartDate  *string `json:"start_date"`
	EndDate    *string `json:"end_date"`
	IsActive   bool    `json:"is_active"`
	Priority   int     `json:"priority"`
}

type floatingSectionProductRow struct {
	ID                string `json:"id"`
	FloatingSectionID string `json:"floating_section_id"`
	ProductID         string `json:"product_id"`
	// Pre-collapsed by Cloud — see invariant 1 above. Do not re-derive.
	TaxTypeID    *string `json:"tax_type_id"`
	IsActive     bool    `json:"is_active"`
	DisplayOrder int     `json:"display_order"`
}

type floatingSectionSkuRow struct {
	ID                       string `json:"id"`
	FloatingSectionProductID string `json:"floating_section_product_id"`
	ProductSkuID             string `json:"product_sku_id"`
	// The promo price, and the only place it lives — see invariant 2 above.
	SellingPrice      int  `json:"selling_price"`
	IsActive          bool `json:"is_active"`
	IsPriceOverridden bool `json:"is_price_overridden"`
}

// floatingToppingOverrideRow is a tier-1 topping override belonging to the
// SPOTLIGHT line. Keyed by floating_section_product_id on purpose: the same
// product bought from a menu resolves through pos_menu_product_topping_overrides
// instead, and mixing the two would price a topping off the wrong line.
type floatingToppingOverrideRow struct {
	ID                       string  `json:"id"`
	FloatingSectionProductID string  `json:"floating_section_product_id"`
	ToppingGroupID           string  `json:"topping_group_id"`
	ToppingGroupItemID       string  `json:"topping_group_item_id"`
	ProductSkuID             *string `json:"product_sku_id"`
	IsHidden                 bool    `json:"is_hidden"`
	OverridePrice            *int    `json:"override_price"`
}

// insertFloatingReplica writes all five spotlight tables. Caller runs it inside
// the catalog transaction, AFTER the DELETE wave and BEFORE the `no menus`
// bail-out — a floating section hangs off no menu, so a shop promoting without
// a published menu still has one.
func insertFloatingReplica(
	tx *sql.Tx,
	sections []floatingSectionRow,
	schedules []floatingSectionScheduleRow,
	products []floatingSectionProductRow,
	skus []floatingSectionSkuRow,
	overrides []floatingToppingOverrideRow,
) error {
	secStmt, err := tx.Prepare(`
		INSERT INTO pos_floating_sections (id, name, name_ja, name_en, name_vi,
		    priority, is_active, start_date, end_date, cloud_updated_at, local_synced_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
	if err != nil {
		return err
	}
	defer secStmt.Close()
	for _, s := range sections {
		if _, err := secStmt.Exec(s.ID, s.Name,
			nullableString(deref(s.NameJa)), nullableString(deref(s.NameEn)),
			nullableString(deref(s.NameVi)), s.Priority, boolToInt(s.IsActive),
			nullableString(deref(s.StartDate)), nullableString(deref(s.EndDate)),
		); err != nil {
			return err
		}
	}

	schedStmt, err := tx.Prepare(`
		INSERT INTO pos_floating_section_schedules (id, floating_section_id, days_of_week,
		    start_time, end_time, start_date, end_date, is_active, priority,
		    cloud_updated_at, local_synced_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
	if err != nil {
		return err
	}
	defer schedStmt.Close()
	for _, s := range schedules {
		if _, err := schedStmt.Exec(s.ID, s.FloatingSectionID, s.DaysOfWeek,
			s.StartTime, s.EndTime,
			nullableString(deref(s.StartDate)), nullableString(deref(s.EndDate)),
			boolToInt(s.IsActive), s.Priority,
		); err != nil {
			return err
		}
	}

	prodStmt, err := tx.Prepare(`
		INSERT INTO pos_floating_section_products (id, floating_section_id, product_id,
		    tax_type_id, is_active, display_order, cloud_updated_at, local_synced_at)
		VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
	if err != nil {
		return err
	}
	defer prodStmt.Close()
	for _, p := range products {
		// nullableString keeps a NULL tax_type_id NULL: that is "inherit", not
		// "no tax". Writing "" here would make the resolver look up a tax type
		// whose id is the empty string and find nothing.
		if _, err := prodStmt.Exec(p.ID, p.FloatingSectionID, p.ProductID,
			nullableString(deref(p.TaxTypeID)), boolToInt(p.IsActive), p.DisplayOrder,
		); err != nil {
			return err
		}
	}

	skuStmt, err := tx.Prepare(`
		INSERT INTO pos_floating_section_product_skus (id, floating_section_product_id,
		    product_sku_id, selling_price, is_active, is_price_overridden,
		    cloud_updated_at, local_synced_at)
		VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
	if err != nil {
		return err
	}
	defer skuStmt.Close()
	for _, s := range skus {
		if _, err := skuStmt.Exec(s.ID, s.FloatingSectionProductID, s.ProductSkuID,
			s.SellingPrice, boolToInt(s.IsActive), boolToInt(s.IsPriceOverridden),
		); err != nil {
			return err
		}
	}

	ovStmt, err := tx.Prepare(`
		INSERT INTO pos_floating_section_topping_overrides (id, floating_section_product_id,
		    topping_group_id, topping_group_item_id, product_sku_id, is_hidden,
		    override_price, cloud_updated_at, local_synced_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
	if err != nil {
		return err
	}
	defer ovStmt.Close()
	for _, o := range overrides {
		var price any
		if o.OverridePrice != nil {
			price = *o.OverridePrice
		}
		if _, err := ovStmt.Exec(o.ID, o.FloatingSectionProductID, o.ToppingGroupID,
			o.ToppingGroupItemID, nullableString(deref(o.ProductSkuID)),
			boolToInt(o.IsHidden), price,
		); err != nil {
			return err
		}
	}

	return nil
}

// FloatingWindow is one section's schedule as the device stores it. Times are
// shop-local wall clock, which is the right clock here: this process runs on the
// shop PC, so `time.Now()` in the shop's location IS the business clock for
// "is the happy hour open".
type FloatingWindow struct {
	DaysOfWeek int
	StartTime  string
	EndTime    string
	StartDate  string // "" = unbounded
	EndDate    string
	IsActive   bool
}

// FloatingSectionOpenAt answers whether a section is sellable at `now`, given
// the section's own date bounds and its schedule rows.
//
// Rules, each one a case Cloud's FloatingSectionPriceResolver also honours:
//
//   - an inactive section is closed, whatever its schedules say;
//   - date bounds are INCLUSIVE calendar dates in shop-local time, compared as
//     Y-m-d strings — never as timestamps. A stored "2026-07-31" is a date, and
//     converting it to a UTC instant is how a Tokyo evening becomes tomorrow;
//   - NO schedule rows means open for the whole date range. Cloud allows a
//     section with only date bounds, so reading "no rows" as "never" would
//     silently hide a running promotion;
//   - a schedule whose end_time is <= start_time WRAPS past midnight (22:00→02:00
//     is a late-night promo, not an empty window). The day bit is matched against
//     the day the window STARTED, so 01:00 Sunday belongs to Saturday's bit;
//   - times compare as HH:MM strings after normalisation, so "9:00", "09:00" and
//     "09:00:00" all mean the same instant.
func FloatingSectionOpenAt(now time.Time, sectionActive bool, sectionStart, sectionEnd string, windows []FloatingWindow) bool {
	if !sectionActive {
		return false
	}
	today := now.Format("2006-01-02")
	if !withinDateBounds(today, sectionStart, sectionEnd) {
		return false
	}

	active := make([]FloatingWindow, 0, len(windows))
	for _, w := range windows {
		if w.IsActive {
			active = append(active, w)
		}
	}
	// Date-bounded but unscheduled = open all day, every day in range.
	if len(active) == 0 {
		return true
	}

	nowHM := normalizeHM(now.Format("15:04"))
	dowBit := 1 << uint(now.Weekday()) // time.Sunday == 0, same as Cloud
	yesterday := now.AddDate(0, 0, -1)
	prevBit := 1 << uint(yesterday.Weekday())
	prevDay := yesterday.Format("2006-01-02")

	for _, w := range active {
		start, end := normalizeHM(w.StartTime), normalizeHM(w.EndTime)
		if start == "" || end == "" {
			continue
		}
		if end > start {
			// Same-day window: [start, end).
			if w.DaysOfWeek&dowBit != 0 &&
				withinDateBounds(today, w.StartDate, w.EndDate) &&
				nowHM >= start && nowHM < end {
				return true
			}
			continue
		}
		// Wrapping window (22:00 → 02:00, or a full 24h when start == end).
		// Either we are in the tail of a window that began YESTERDAY, or in the
		// head of one that began today.
		if w.DaysOfWeek&prevBit != 0 &&
			withinDateBounds(prevDay, w.StartDate, w.EndDate) &&
			nowHM < end {
			return true
		}
		if w.DaysOfWeek&dowBit != 0 &&
			withinDateBounds(today, w.StartDate, w.EndDate) &&
			nowHM >= start {
			return true
		}
	}
	return false
}

// withinDateBounds compares Y-m-d strings. Lexicographic order IS chronological
// order for that format, which is why the bounds never become timestamps.
func withinDateBounds(day, start, end string) bool {
	if start != "" && day < start {
		return false
	}
	if end != "" && day > end {
		return false
	}
	return true
}

// normalizeHM trims a stored time to "HH:MM" and zero-pads a single-digit hour,
// so "9:00", "09:00" and "09:00:00" all compare equal.
func normalizeHM(v string) string {
	v = strings.TrimSpace(v)
	if v == "" {
		return ""
	}
	parts := strings.Split(v, ":")
	if len(parts) < 2 {
		return ""
	}
	h, m := parts[0], parts[1]
	if len(h) == 1 {
		h = "0" + h
	}
	if len(h) != 2 || len(m) != 2 {
		return ""
	}
	return h + ":" + m
}
