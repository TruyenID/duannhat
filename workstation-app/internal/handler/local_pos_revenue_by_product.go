package handler

import (
	"database/sql"
	"fmt"
	"log/slog"
	"net/http"
	"strconv"
	"strings"
	"time"
)

// /api/v1/pos/revenue/by-product
//
// LAN-served product/SKU revenue ranking, response shape mirrors Cloud's
// PosRevenueController::byProduct so pos-web treats workstation +
// cloud interchangeably.
//
// What was rewritten (plan: revenue reports parity):
//   - Pagination via `page` + `per_page` (1..100, default 25) — pos-web
//     uses it for the products table; pre-rewrite the handler only
//     supported `limit` so the page count + "Trang sau" button on the
//     LAN client were broken.
//   - Category resolution joins through `pos_menu_sections` (Phase B
//     mirror of Cloud's menu_sections) rather than the legacy flat
//     `menu_items.category` string. Returns the section id as
//     `category_id` so the dropdown filter round-trips with Cloud.
//   - Product-id grouping reads through `pos_products` /
//     `pos_product_skus` (Phase B). Falls back to the legacy
//     `menu_items` cache when an order item's product_sku_id has no
//     parent in pos_product_skus (orders that predate Phase B sync).
//   - `total_revenue` / `total_quantity` computed across the FULL
//     filtered window (not just the current page) so the share % math
//     stays stable as the user pages.

type byProductRow struct {
	ID           string  `json:"id"`
	Name         string  `json:"name"`
	SKU          *string `json:"sku"`
	CategoryID   *string `json:"category_id"`
	CategoryName *string `json:"category_name"`
	Quantity     int64   `json:"quantity"`
	Revenue      int64   `json:"revenue"`
	SharePct     float64 `json:"share_pct"`
}

type categoryOption struct {
	ID   string `json:"id"`
	Name string `json:"name"`
}

type paginationMeta struct {
	CurrentPage int `json:"current_page"`
	PerPage     int `json:"per_page"`
	Total       int `json:"total"`
}

type byProductResponse struct {
	From                string           `json:"from"`
	To                  string           `json:"to"`
	Level               string           `json:"level"`
	Sort                string           `json:"sort"`
	CategoryID          *string          `json:"category_id"`
	TotalRevenue        int64            `json:"total_revenue"`
	TotalQuantity       int64            `json:"total_quantity"`
	Rows                []byProductRow   `json:"rows"`
	Meta                paginationMeta   `json:"meta"`
	AvailableCategories []categoryOption `json:"available_categories"`
	GeneratedAt         string           `json:"generated_at"`
	Source              string           `json:"source"`
}

func (s *Server) handleLocalPosRevenueByProduct(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()

	level := strings.ToLower(strings.TrimSpace(q.Get("level")))
	if level != "sku" {
		level = "product"
	}

	sort := strings.ToLower(strings.TrimSpace(q.Get("sort")))
	if sort != "quantity" && sort != "share" {
		sort = "revenue"
	}

	from, to, err := parseRevenueWindow(revenueGranularityDay, q.Get("from"), q.Get("to"))
	if err != nil {
		writeError(w, http.StatusUnprocessableEntity, err.Error())
		return
	}

	// Pagination — pos-web defaults to page=1, per_page=25.
	page := parseIntDefault(q.Get("page"), 1, 1, 10000)
	perPage := parseIntDefault(q.Get("per_page"), 25, 1, 100)

	var categoryID *string
	if cid := strings.TrimSpace(q.Get("category_id")); cid != "" {
		categoryID = &cid
	}

	allRows, total, totalQty, err := s.byProductAggregate(level, from, to, categoryID, sort, localeFromRequest(r))
	if err != nil {
		slog.Error("by-product aggregation failed", "err", err)
		writeError(w, http.StatusInternalServerError, "failed to aggregate by-product revenue")
		return
	}

	// Share % computed against the full filtered window's total so the
	// numbers don't shift as the user pages.
	for i := range allRows {
		if total > 0 {
			allRows[i].SharePct = round1(float64(allRows[i].Revenue) * 100.0 / float64(total))
		}
	}

	// share-sort piggybacks on revenue ordering (share is monotonic
	// in revenue when the denominator is fixed).
	pageRows := pageSlice(allRows, page, perPage)

	cats, err := s.byProductCategories()
	if err != nil {
		slog.Error("by-product categories failed", "err", err)
		cats = []categoryOption{}
	}

	resp := byProductResponse{
		From:                from.Format("2006-01-02"),
		To:                  to.Format("2006-01-02"),
		Level:               level,
		Sort:                sort,
		CategoryID:          categoryID,
		TotalRevenue:        total,
		TotalQuantity:       totalQty,
		Rows:                pageRows,
		Meta:                paginationMeta{CurrentPage: page, PerPage: perPage, Total: len(allRows)},
		AvailableCategories: cats,
		GeneratedAt:         time.Now().UTC().Format(time.RFC3339),
		Source:              "workstation",
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": resp})
}

// byProductAggregate runs the full grouped query — pagination happens in
// Go after share % is computed against the full-window totals so the
// numbers don't drift between pages.
//
// Group key: pos_product_skus.product_id (level=product) or
// order_items.product_sku_id (level=sku), falling back to
// order_items.menu_item_id then menu_item_name when no Phase B catalog
// row matches. Category resolution prefers pos_menu_products →
// pos_menu_sections (section id is what Cloud emits) over the legacy
// menu_items.category string.
func (s *Server) byProductAggregate(
	level string,
	from, to time.Time,
	categoryID *string,
	sort string,
	locale string,
) ([]byProductRow, int64, int64, error) {
	branchID := s.workstationBranchID()

	// Follow the operator's selected pos-web language (Accept-Language) for the
	// product + SKU variant names, exactly like the menu/cart do — falling back
	// to the add-time snapshot (menu_item_name) when the catalog row is gone.
	// pos_menu_sections has no localized columns, so the category stays base.
	nameExpr := localizedNameExpr("pp", "name", locale)
	skuNameExpr := localizedNameExpr("ps", "name", locale)

	var groupKey string
	if level == "sku" {
		groupKey = "COALESCE(oi.product_sku_id, oi.menu_item_id, oi.menu_item_name)"
	} else {
		// Resolve sku → product via pos_product_skus when available;
		// fall back to menu_item_id for legacy orders.
		groupKey = "COALESCE(ps.product_id, oi.menu_item_id, oi.menu_item_name)"
	}

	orderColumn := "revenue"
	if sort == "quantity" {
		orderColumn = "quantity"
	}

	args := []any{from.UTC().Format(time.RFC3339), to.UTC().Format(time.RFC3339)}
	whereBranch := ""
	if branchID != "" {
		whereBranch = " AND o.branch_id = ?"
		args = append(args, branchID)
	}

	whereCategory := ""
	if categoryID != nil {
		// Category filter accepts EITHER the new pos_menu_sections.id
		// (the canonical Cloud-emitted value) OR the legacy
		// menu_items.category string — keeps existing client URLs and
		// any bookmarked category UUIDs working.
		whereCategory = " AND (pmp.menu_section_id = ? OR mi.category = ?)"
		args = append(args, *categoryID, *categoryID)
	}

	sqlQuery := `
		SELECT
		    ` + groupKey + ` AS id,
		    MAX(COALESCE(` + nameExpr + `, oi.menu_item_name)) AS name,
		    MAX(COALESCE(` + skuNameExpr + `, oi.product_sku_id)) AS sku,
		    MAX(COALESCE(pmp.menu_section_id, mi.category)) AS category_id,
		    MAX(COALESCE(pms.name, mi.category)) AS category_name,
		    COALESCE(SUM(oi.quantity), 0) AS quantity,
		    COALESCE(SUM(oi.subtotal), 0) AS revenue
		FROM order_items oi
		INNER JOIN orders o ON o.id = oi.customer_order_id
		LEFT JOIN pos_product_skus ps ON ps.id = oi.product_sku_id
		LEFT JOIN pos_products pp ON pp.id = ps.product_id
		LEFT JOIN pos_menu_products pmp ON pmp.product_id = ps.product_id
		LEFT JOIN pos_menu_sections pms ON pms.id = pmp.menu_section_id
		LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
		WHERE o.status = 'closed'
		  AND oi.voided_at IS NULL
		  AND o.created_at BETWEEN ? AND ?` + whereBranch + whereCategory + `
		GROUP BY ` + groupKey + `
		ORDER BY ` + orderColumn + ` DESC`

	rows, err := s.db.Query(sqlQuery, args...)
	if err != nil {
		return nil, 0, 0, err
	}
	defer rows.Close()

	out := []byProductRow{}
	var totalRevenue, totalQty int64
	for rows.Next() {
		var row byProductRow
		var sku, catID, catName sql.NullString
		var name sql.NullString
		if err := rows.Scan(&row.ID, &name, &sku, &catID, &catName, &row.Quantity, &row.Revenue); err != nil {
			return nil, 0, 0, err
		}
		if name.Valid {
			row.Name = name.String
		}
		if sku.Valid {
			v := sku.String
			row.SKU = &v
		}
		if catID.Valid {
			v := catID.String
			row.CategoryID = &v
		}
		if catName.Valid {
			v := catName.String
			row.CategoryName = &v
		}
		out = append(out, row)
		totalRevenue += row.Revenue
		totalQty += row.Quantity
	}
	if err := rows.Err(); err != nil {
		return nil, 0, 0, err
	}
	return out, totalRevenue, totalQty, nil
}

// byProductCategories returns the section dropdown options. Prefers
// pos_menu_sections (Phase B mirror of Cloud's menu_sections); falls
// back to the legacy menu_items.category strings when no Phase B
// catalog has been synced yet so the dropdown never renders empty.
func (s *Server) byProductCategories() ([]categoryOption, error) {
	out := []categoryOption{}

	rows, err := s.db.Query(`
		SELECT id, name FROM pos_menu_sections
		WHERE is_active = 1
		ORDER BY sort_order, name`)
	if err == nil {
		defer rows.Close()
		seen := map[string]bool{}
		for rows.Next() {
			var id, name string
			if err := rows.Scan(&id, &name); err == nil && !seen[id] {
				seen[id] = true
				out = append(out, categoryOption{ID: id, Name: name})
			}
		}
		if len(out) > 0 {
			return out, nil
		}
	}

	// Fallback to legacy menu_items.category labels.
	legacy, lErr := s.db.Query(`
		SELECT DISTINCT category FROM menu_items
		WHERE category IS NOT NULL AND category <> ''
		ORDER BY category`)
	if lErr != nil {
		return nil, lErr
	}
	defer legacy.Close()
	for legacy.Next() {
		var c sql.NullString
		if err := legacy.Scan(&c); err != nil {
			return nil, err
		}
		if c.Valid && c.String != "" {
			out = append(out, categoryOption{ID: c.String, Name: c.String})
		}
	}
	return out, legacy.Err()
}

// parseIntDefault clamps an int query param to [min, max], falling back
// to defaultVal when the param is missing or malformed.
func parseIntDefault(raw string, defaultVal, minVal, maxVal int) int {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return defaultVal
	}
	n, err := strconv.Atoi(raw)
	if err != nil {
		return defaultVal
	}
	if n < minVal {
		return minVal
	}
	if n > maxVal {
		return maxVal
	}
	return n
}

// pageSlice returns the 1-indexed page of size perPage from rows. Out-of-
// range pages return an empty slice (matches Laravel paginator behavior).
func pageSlice(rows []byProductRow, page, perPage int) []byProductRow {
	start := (page - 1) * perPage
	if start >= len(rows) {
		return []byProductRow{}
	}
	end := start + perPage
	if end > len(rows) {
		end = len(rows)
	}
	return rows[start:end]
}

// keep fmt import used by future error wrapping (defensive — avoids
// "imported and not used" if all wrapping sites get refactored later).
var _ = fmt.Sprintf
