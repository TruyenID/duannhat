package handler

import (
	"database/sql"
	"fmt"
	"net/http"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// #1180 / #1380 — serve the spotlight ("Khung giờ ưu đãi") over LAN.
//
//	GET /api/v1/pos/floating-sections
//
// #1319 landed the replica (five `pos_floating_*` tables + the window
// evaluator); nothing read it. This is the read side: pos-web browses menus
// through the LAN endpoints, so until a section comes out of here, a spotlight
// simply does not exist on the POS — and a product that lives ONLY in one
// cannot be sold at all.
//
// Two things this endpoint must get right, both money:
//
//  1. the price it returns is the PROMO price
//     (`pos_floating_section_product_skus.selling_price`), never
//     `pos_product_skus.selling_price`. The same SKU legitimately has two
//     prices — one from the ordinary menu, one from the spotlight — and the
//     caller must be told which one it is looking at.
//  2. `tax_type_id` is passed through EXACTLY as Cloud collapsed it
//     (`FloatingSectionProduct.tax_type_id ?? Product.tax_type_id`). No tier
//     walk happens here. `null` means inherit and stays null, so the device
//     resolver continues to branch → brand default the way Cloud does.
//
// Openness is decided HERE, on the shop clock, by service.FloatingSectionOpenAt
// — the feed ships schedules raw precisely so a workstation that has not pulled
// for hours still opens and closes its happy hour on time.
func (s *Server) handleLocalPosFloatingSections(w http.ResponseWriter, r *http.Request) {
	locale := localeFromRequest(r)
	now := time.Now()

	type sectionRow struct {
		id, name   string
		priority   int
		active     int
		start, end string
		windows    []service.FloatingWindow
	}

	// Same conn-pool discipline as loadMenuProducts: drain the parent rows
	// before opening any nested query, or a handful of concurrent tabs pin
	// the 8-connection pool and every later statement blocks.
	rows, err := s.db.Query(fmt.Sprintf(`
		SELECT id, %s AS display_name, priority, is_active,
		       COALESCE(start_date, ''), COALESCE(end_date, '')
		FROM pos_floating_sections
		ORDER BY priority, display_name`, localizedNameExpr("", "name", locale)))
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	sections := []sectionRow{}
	for rows.Next() {
		var sec sectionRow
		if err := rows.Scan(&sec.id, &sec.name, &sec.priority, &sec.active, &sec.start, &sec.end); err != nil {
			rows.Close()
			writeServerError(w, r, err)
			return
		}
		sections = append(sections, sec)
	}
	rows.Close()

	for i := range sections {
		wins, err := s.loadFloatingWindows(sections[i].id)
		if err != nil {
			writeServerError(w, r, err)
			return
		}
		sections[i].windows = wins
	}

	data := []map[string]any{}
	for _, sec := range sections {
		if !service.FloatingSectionOpenAt(now, sec.active == 1, sec.start, sec.end, sec.windows) {
			continue
		}
		products, err := s.loadFloatingProducts(sec.id, locale)
		if err != nil {
			writeServerError(w, r, err)
			return
		}
		// A section whose every product went inactive is not something to
		// show: the cashier would tap an empty promo panel mid-service.
		if len(products) == 0 {
			continue
		}
		data = append(data, map[string]any{
			"id":       sec.id,
			"name":     sec.name,
			"priority": sec.priority,
			"products": products,
		})
	}

	writeJSON(w, http.StatusOK, map[string]any{"data": data})
}

func (s *Server) loadFloatingWindows(sectionID string) ([]service.FloatingWindow, error) {
	rows, err := s.db.Query(`
		SELECT days_of_week, start_time, end_time,
		       COALESCE(start_date, ''), COALESCE(end_date, ''), is_active
		FROM pos_floating_section_schedules
		WHERE floating_section_id = ?`, sectionID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	out := []service.FloatingWindow{}
	for rows.Next() {
		var w service.FloatingWindow
		var active int
		if err := rows.Scan(&w.DaysOfWeek, &w.StartTime, &w.EndTime, &w.StartDate, &w.EndDate, &active); err != nil {
			return nil, err
		}
		w.IsActive = active == 1
		out = append(out, w)
	}
	return out, rows.Err()
}

// loadFloatingProducts returns the sellable members of one spotlight, each with
// its promo-priced SKUs. A member with no active promo SKU is dropped: it has no
// price of its own here, and falling back to the menu price would sell the
// ordinary price under a promo label.
func (s *Server) loadFloatingProducts(sectionID, locale string) ([]map[string]any, error) {
	type row struct {
		id, productID, name, image string
		taxTypeID                  sql.NullString
		displayOrder               int
	}

	rows, err := s.db.Query(fmt.Sprintf(`
		SELECT fsp.id, fsp.product_id, %s AS product_name,
		       COALESCE(p.image_url, ''), fsp.tax_type_id, fsp.display_order
		FROM pos_floating_section_products fsp
		JOIN pos_products p ON p.id = fsp.product_id
		WHERE fsp.floating_section_id = ? AND fsp.is_active = 1
		ORDER BY fsp.display_order, product_name`, productNameExpr(locale)), sectionID)
	if err != nil {
		return nil, err
	}
	members := []row{}
	for rows.Next() {
		var m row
		if err := rows.Scan(&m.id, &m.productID, &m.name, &m.image, &m.taxTypeID, &m.displayOrder); err != nil {
			rows.Close()
			return nil, err
		}
		members = append(members, m)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, err
	}

	out := []map[string]any{}
	for _, m := range members {
		skus, err := s.loadFloatingSkus(m.id, locale)
		if err != nil {
			return nil, err
		}
		if len(skus) == 0 {
			continue
		}
		// NULL *and* "" both mean inherit. The replica writes a real NULL, but
		// emitting "" here would have the caller look up a tax type whose id is
		// the empty string and find none — a silent 0% instead of the default.
		var taxType any
		if m.taxTypeID.Valid && m.taxTypeID.String != "" {
			taxType = m.taxTypeID.String
		}
		out = append(out, map[string]any{
			// The membership id, NOT the product id: it is what identifies
			// "this product, bought from this spotlight" — the key the order
			// path needs to price toppings and tax off the right line.
			"floating_section_product_id": m.id,
			"product_id":                  m.productID,
			"name":                        m.name,
			"image_url":                   nilIfEmpty(m.image),
			// Pre-collapsed by Cloud; null = inherit. Passed through untouched.
			"tax_type_id":   taxType,
			"display_order": m.displayOrder,
			"skus":          skus,
		})
	}
	return out, nil
}

func (s *Server) loadFloatingSkus(floatingProductID, locale string) ([]map[string]any, error) {
	rows, err := s.db.Query(fmt.Sprintf(`
		SELECT fss.product_sku_id, %s AS sku_name, COALESCE(sk.sku, ''),
		       fss.selling_price, COALESCE(sk.image_url, '')
		FROM pos_floating_section_product_skus fss
		JOIN pos_product_skus sk ON sk.id = fss.product_sku_id
		WHERE fss.floating_section_product_id = ? AND fss.is_active = 1
		ORDER BY sku_name`, localizedNameExpr("sk", "name", locale)), floatingProductID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	out := []map[string]any{}
	for rows.Next() {
		var id, name, code, image string
		var price int
		if err := rows.Scan(&id, &name, &code, &price, &image); err != nil {
			return nil, err
		}
		out = append(out, map[string]any{
			"id":   id,
			"name": name,
			"sku":  nilIfEmpty(code),
			// THE promo price. Deliberately named the same as the menu shape so
			// pos-web renders it without a branch — the caller is told which
			// price it is by WHERE it came from, not by a different field name.
			"selling_price": price,
			"image_url":     nilIfEmpty(image),
		})
	}
	return out, rows.Err()
}
