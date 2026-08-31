package handler

import (
	"database/sql"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// LAN-offline menu eager-load.
//
// Endpoints handled here (registered in routes.go BEFORE the catch-all
// cloud proxy):
//
//   GET /api/v1/pos/menus                       — list
//   GET /api/v1/pos/menus/{menu}                — detail with menu_products[]
//   GET /api/v1/pos/menus/by-day/{dayOfWeek}    — schedule-driven list
//
// Shape: matches ShopMenuResource (pos-web/src/app/pos/types.ts). Pagination
// is intentionally simple here — the menu list rarely exceeds 10 rows per
// shop and pos-web's MenuCatalog reads the bundled detail rather than the
// separate /products endpoint, which we deliberately leave proxied because
// it depends on richer eager-loads (gallery, toppings) we don't mirror.
//
// active_promotion overlay: computed inline from the menu_promotions /
// _products replicas at read time. Branch-local clock is the same source
// PromotionEngine consults, so what shows up here is exactly what the
// LAN-offline createItem will apply when staff taps "Add".

func (s *Server) handleLocalPosListMenus(w http.ResponseWriter, r *http.Request) {
	statusFilter := r.URL.Query().Get("status")
	search := strings.TrimSpace(r.URL.Query().Get("search"))

	q := `SELECT id, name, COALESCE(description, ''), status, sort_order
	      FROM pos_menus WHERE 1=1`
	args := []any{}
	if statusFilter != "" {
		// Cloud's MenuCatalogReplicaController emits status verbatim
		// from `menus.status`, which is one of {published, Published,
		// active, Active}. pos-web passes ?status=Active (the public
		// shop-side enum). Without normalization the equality check
		// silently misses every row whose stored status is the
		// lowercase spelling. Match the same broad set the cloud
		// catalog endpoint accepts so the LAN list matches what cloud
		// would return.
		if strings.EqualFold(statusFilter, "Active") {
			q += " AND status IN ('published','Published','active','Active')"
		} else {
			q += " AND status = ?"
			args = append(args, statusFilter)
		}
	}
	if search != "" {
		q += " AND name LIKE ?"
		args = append(args, "%"+search+"%")
	}
	// #481 — service-type gate. pos-web passes ?service_type=DineIn|Takeaway
	// derived from the order type; a menu shows when its (effective) type
	// matches, is 'Both', or is NULL (legacy row synced before the column
	// existed → treat as always-visible). Absent / other value → no gate.
	if st := r.URL.Query().Get("service_type"); st == "DineIn" || st == "Takeaway" {
		q += " AND (service_type = ? OR service_type = 'Both' OR service_type IS NULL)"
		args = append(args, st)
	}
	q += " ORDER BY sort_order, name"

	rows, err := s.db.Query(q, args...)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rows.Close()

	data := []map[string]any{}
	for rows.Next() {
		var id, name, desc, status string
		var sortOrder int
		if err := rows.Scan(&id, &name, &desc, &status, &sortOrder); err != nil {
			writeServerError(w, r, err)
			return
		}
		data = append(data, map[string]any{
			"id":          id,
			"name":        name,
			"description": nilIfEmpty(desc),
			"status":      status,
		})
	}

	// pos-web's PaginatedResponse shape — even when we serve everything
	// in a single page we keep the meta envelope so TanStack Query
	// pagination plumbing doesn't choke.
	writeJSON(w, http.StatusOK, map[string]any{
		"data": data,
		"meta": map[string]any{
			"current_page": 1,
			"last_page":    1,
			"per_page":     len(data),
			"total":        len(data),
		},
	})
}

func (s *Server) handleLocalPosMenuDetailLocal(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("menu")
	menu, err := s.loadMenuShape(id, localeFromRequest(r))
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	if menu == nil {
		writeError(w, http.StatusNotFound, "menu not found")
		return
	}
	// Swap any image URL that's already cached locally for the
	// /api/lan/images/{hash} form so pos-web renders even when Cloud
	// is unreachable. URLs not yet cached pass through untouched.
	s.rewriteResponseImages(r, menu)
	writeJSON(w, http.StatusOK, map[string]any{"data": menu})
}

// dispatchMenuTwoSeg routes the registered pattern
// `GET /api/v1/pos/menus/{seg1}/{seg2}` to either the by-day handler or
// the products handler depending on which static segment is present.
//
// Why: Go 1.22 ServeMux refuses to register `/menus/by-day/{dow}` and
// `/menus/{menu}/products` separately — both match `/menus/by-day/products`
// and neither is "more specific". One combined pattern + internal
// dispatch sidesteps the ambiguity check entirely; the dispatcher just
// inspects the resolved path values and routes to the right handler,
// preserving the URL contract pos-web (and Cloud) document.
//
// Order of checks favours the literal-segment cases (`by-day`, `products`).
// The catch-all 404 covers genuinely-invalid paths like
// `/menus/foo/bar` that don't match either real endpoint.
func (s *Server) dispatchMenuTwoSeg(w http.ResponseWriter, r *http.Request) {
	seg1 := r.PathValue("seg1")
	seg2 := r.PathValue("seg2")

	if seg1 == "by-day" {
		// Rewrite path value so the existing handler reads it as `dow`.
		r.SetPathValue("dow", seg2)
		s.handleLocalPosMenuByDay(w, r)
		return
	}
	if seg2 == "products" {
		r.SetPathValue("menu", seg1)
		s.handleLocalPosMenuProducts(w, r)
		return
	}
	writeError(w, http.StatusNotFound, "menu sub-resource not found")
}

func (s *Server) handleLocalPosMenuByDay(w http.ResponseWriter, r *http.Request) {
	dowStr := r.PathValue("dow")
	dow, err := strconv.Atoi(dowStr)
	if err != nil || dow < 0 || dow > 6 {
		writeError(w, http.StatusBadRequest, "day_of_week must be 0..6")
		return
	}

	// Pagination — mirror Cloud's ShopMenusByDay default 20 / max 100.
	perPage := atoiDefault(r.URL.Query().Get("per_page"), 20)
	if perPage > 100 {
		perPage = 100
	}
	if perPage < 1 {
		perPage = 20
	}
	page := atoiDefault(r.URL.Query().Get("page"), 1)
	search := strings.TrimSpace(r.URL.Query().Get("search"))

	// Resolve highest-priority `(menu_id, dow)` schedule row for each
	// menu — matches MenuService::listActiveBranchMenusForShopByDay's
	// correlated subquery, just done in app layer because SQLite
	// doesn't have window functions on the older modernc engine.
	scheduleRows, err := s.db.Query(`
		SELECT menu_id, COALESCE(start_time, ''), COALESCE(end_time, ''), COALESCE(priority, 0)
		FROM menu_schedules
		WHERE is_active = 1
		  AND (day_of_week IS NULL OR day_of_week = ?)
		ORDER BY menu_id, priority, id`, dow)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	type schedHit struct {
		startTime string
		endTime   string
		priority  int
	}
	hits := map[string]schedHit{}
	for scheduleRows.Next() {
		var menuID, st, et string
		var pr int
		if err := scheduleRows.Scan(&menuID, &st, &et, &pr); err != nil {
			scheduleRows.Close()
			writeServerError(w, r, err)
			return
		}
		// First row per menu wins (ORDER BY priority ASC).
		if _, ok := hits[menuID]; !ok {
			hits[menuID] = schedHit{startTime: st, endTime: et, priority: pr}
		}
	}
	scheduleRows.Close()

	// Source: either the matched menus OR a "schedule-empty" fallback
	// over every active menu (so a shop without per-day rows still
	// gets a usable picker — Cloud's strict-empty contract leaves the
	// LAN POS unusable in that case).
	type menuRow struct {
		id, name, desc, status string
		startTime, endTime     string
		productsCount          int
	}

	emitRows := []menuRow{}

	loadFromMenus := func(menuIDs []string) error {
		var query string
		var args []any
		hasFilter := len(menuIDs) > 0
		if hasFilter {
			placeholders := strings.Repeat("?,", len(menuIDs))
			placeholders = placeholders[:len(placeholders)-1]
			args = make([]any, 0, len(menuIDs)+1)
			for _, id := range menuIDs {
				args = append(args, id)
			}
			query = `
				SELECT id, name, COALESCE(description, ''), status,
				       (SELECT COUNT(*) FROM pos_menu_products mp WHERE mp.menu_id = m.id) AS menu_products_count
				FROM pos_menus m
				WHERE id IN (` + placeholders + `)
				  AND status IN ('published','Published','active','Active')`
		} else {
			query = `
				SELECT id, name, COALESCE(description, ''), status,
				       (SELECT COUNT(*) FROM pos_menu_products mp WHERE mp.menu_id = m.id) AS menu_products_count
				FROM pos_menus m
				WHERE status IN ('published','Published','active','Active')`
		}
		if search != "" {
			query += " AND name LIKE ?"
			args = append(args, "%"+search+"%")
		}
		// #481 — service-type gate (see handleLocalPosListMenus). Applies to
		// both the scheduled and schedule-empty-fallback menu sets.
		if st := r.URL.Query().Get("service_type"); st == "DineIn" || st == "Takeaway" {
			query += " AND (service_type = ? OR service_type = 'Both' OR service_type IS NULL)"
			args = append(args, st)
		}
		query += " ORDER BY sort_order, name"

		mrows, err := s.db.Query(query, args...)
		if err != nil {
			return err
		}
		defer mrows.Close()
		for mrows.Next() {
			var mr menuRow
			if err := mrows.Scan(&mr.id, &mr.name, &mr.desc, &mr.status, &mr.productsCount); err != nil {
				return err
			}
			if h, ok := hits[mr.id]; ok {
				mr.startTime = h.startTime
				mr.endTime = h.endTime
			}
			emitRows = append(emitRows, mr)
		}
		return mrows.Err()
	}

	if len(hits) > 0 {
		menuIDs := make([]string, 0, len(hits))
		for id := range hits {
			menuIDs = append(menuIDs, id)
		}
		if err := loadFromMenus(menuIDs); err != nil {
			writeServerError(w, r, err)
			return
		}
	} else {
		// Schedule-empty fallback (LAN-pragmatic divergence from Cloud
		// strict empty).
		if err := loadFromMenus(nil); err != nil {
			writeServerError(w, r, err)
			return
		}
	}

	total := len(emitRows)
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	start := (page - 1) * perPage
	end := start + perPage
	if start > total {
		start = total
	}
	if end > total {
		end = total
	}
	pageSlice := emitRows[start:end]

	data := make([]map[string]any, 0, len(pageSlice))
	for _, mr := range pageSlice {
		data = append(data, map[string]any{
			"id":                  mr.id,
			"name":                mr.name,
			"description":         nilIfEmpty(mr.desc),
			"status":              mr.status,
			"menu_products_count": mr.productsCount,
			// pickActiveMenu on pos-web tolerates empty strings — it
			// falls back to menus[0] when start/end can't parse.
			"start_time": mr.startTime,
			"end_time":   mr.endTime,
		})
	}
	// Match Laravel's paginated envelope so pos-web's
	// PaginatedResponse<ShopMenuByDayResource> consumer (TanStack
	// query keys) doesn't have to branch on shape. `from`/`to` are
	// null when the slice is empty (Laravel convention).
	var fromPtr, toPtr any
	if total > 0 {
		fromPtr = start + 1
		toPtr = start + len(pageSlice)
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"data": data,
		"links": map[string]any{
			"first": nil,
			"last":  nil,
			"prev":  nil,
			"next":  nil,
		},
		"meta": map[string]any{
			"current_page": page,
			"from":         fromPtr,
			"last_page":    lastPage,
			"per_page":     perPage,
			"to":           toPtr,
			"total":        total,
		},
	})
}

// loadMenuShape builds the ShopMenuResource shape: the menu container,
// menu_sections[], menu_products[] (each with product + skus[]), and the
// active_promotion overlay computed against the current branch-local
// time.
func (s *Server) loadMenuShape(id, locale string) (map[string]any, error) {
	var name, status, desc string
	if err := s.db.QueryRow(`
		SELECT name, COALESCE(description, ''), status
		FROM pos_menus WHERE id = ?`, id).Scan(&name, &desc, &status); err != nil {
		if err == sql.ErrNoRows {
			return nil, nil
		}
		return nil, err
	}

	sections, err := s.loadMenuSections(id)
	if err != nil {
		return nil, err
	}

	now := time.Now()
	products, err := s.loadMenuProducts(id, now, locale)
	if err != nil {
		return nil, err
	}

	return map[string]any{
		"id":                  id,
		"name":                name,
		"description":         nilIfEmpty(desc),
		"status":              status,
		"menu_sections":       sections,
		"menu_products":       products,
		"menu_products_count": len(products),
	}, nil
}

func (s *Server) loadMenuSections(menuID string) ([]map[string]any, error) {
	rows, err := s.db.Query(`
		SELECT id, name, sort_order, is_active
		FROM pos_menu_sections WHERE menu_id = ?
		ORDER BY sort_order, name`, menuID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var id, name string
		var sortOrder, active int
		if err := rows.Scan(&id, &name, &sortOrder, &active); err != nil {
			return nil, err
		}
		out = append(out, map[string]any{
			"id":         id,
			"menu_id":    menuID,
			"name":       name,
			"sort_order": sortOrder,
			"is_active":  active == 1,
		})
	}
	return out, nil
}

// productNameExpr is the SQL expression for a menu product's DISPLAY name in
// the operator's locale — the per-locale column (name_ja/name_en/name_vi synced
// from Cloud) with a fallback to the base pos_products.name when that locale has
// no translation. `locale` is a whitelisted value from localeFromRequest
// (ja/en/vi), so interpolating the fixed column name is injection-safe. The
// query must alias the parent products table `p`.
func productNameExpr(locale string) string {
	switch strings.ToLower(strings.TrimSpace(locale)) {
	case "en":
		return "COALESCE(NULLIF(p.name_en, ''), p.name)"
	case "vi":
		return "COALESCE(NULLIF(p.name_vi, ''), p.name)"
	case "ja":
		return "COALESCE(NULLIF(p.name_ja, ''), p.name)"
	default:
		return "p.name"
	}
}

// localizedNameExpr generalizes productNameExpr to any table alias + base
// column: it returns `COALESCE(NULLIF(<prefix><col>_<locale>, ”), <prefix><col>)`
// for a whitelisted locale (ja/en/vi), else just `<prefix><col>`. Used for SKU
// variant names, topping group names, option names, and option value labels
// (col="label"). `alias` may be "" for an unaliased single-table query. locale
// is whitelisted (injection-safe), col/alias are compile-time constants.
func localizedNameExpr(alias, col, locale string) string {
	prefix := ""
	if alias != "" {
		prefix = alias + "."
	}
	loc := strings.ToLower(strings.TrimSpace(locale))
	switch loc {
	case "en", "vi", "ja":
		return fmt.Sprintf("COALESCE(NULLIF(%s%s_%s, ''), %s%s)", prefix, col, loc, prefix, col)
	default:
		return prefix + col
	}
}

func (s *Server) loadMenuProducts(menuID string, now time.Time, locale string) ([]map[string]any, error) {
	// Same conn-pool deadlock rationale as loadProductToppingGroups:
	// each iteration of `rows.Next()` opens 4 more nested Rows
	// (loadProductSkus, loadProductGallery, loadProductToppingGroups,
	// activePromotionForProduct), and each of those opens further
	// nested Rows down the chain. Pre-fix a single GET
	// /pos/menus/{id}/products call could pin 4-5 connections at once;
	// 2-3 concurrent pos-web tabs filled the 8-conn pool and every
	// subsequent SQL (including workstationBranchID() on the LAN
	// health probe) blocked indefinitely on `(*DB).conn`. Pull the
	// row tuples into a slice first, close Rows, then run the nested
	// loaders against a free conn each.
	type mpRow struct {
		id, productID, sectionID, productName, productDesc string
		productImage, productTypeCode                      string
		sectionName                                        sql.NullString
		active, displayOrder                               int
	}
	rows, err := s.db.Query(fmt.Sprintf(`
		SELECT mp.id, mp.product_id, COALESCE(mp.menu_section_id, ''),
		       mp.is_active, mp.display_order,
		       %s AS product_name, COALESCE(p.description, ''),
		       COALESCE(p.image_url, ''),
		       COALESCE(p.product_type_code, ''),
		       s.name AS section_name
		FROM pos_menu_products mp
		JOIN pos_products p ON p.id = mp.product_id
		LEFT JOIN pos_menu_sections s
		       ON s.id = mp.menu_section_id AND s.menu_id = mp.menu_id
		WHERE mp.menu_id = ?
		ORDER BY mp.display_order, p.name`, productNameExpr(locale)), menuID)
	if err != nil {
		return nil, err
	}
	mps := []mpRow{}
	for rows.Next() {
		var m mpRow
		if err := rows.Scan(&m.id, &m.productID, &m.sectionID, &m.active, &m.displayOrder,
			&m.productName, &m.productDesc, &m.productImage, &m.productTypeCode, &m.sectionName); err != nil {
			rows.Close()
			return nil, err
		}
		mps = append(mps, m)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()

	out := []map[string]any{}
	for _, m := range mps {
		skus, err := s.loadProductSkus(m.productID, now, locale)
		if err != nil {
			return nil, err
		}
		gallery, err := s.loadProductGallery(m.productID)
		if err != nil {
			return nil, err
		}
		toppingGroups, err := s.loadProductToppingGroups(m.id, m.productID, locale)
		if err != nil {
			return nil, err
		}

		// active_promotion overlay: same heuristic as PromotionEngine —
		// match on any sku id; report the highest-priority match. pos-web
		// renders the badge + uses discounted_price as the row's apparent
		// price.
		activePromo := s.activePromotionForProduct(skus, now)

		// plan-043 — resolve the product's effective consumption-tax rates so
		// pos-web can render the 税込/税抜 menu price. Mirrors the resolver chain
		// (menu-item type → branch default → brand default) + 酒類 escalation.
		productPayload := map[string]any{
			"id":                m.productID,
			"name":              m.productName,
			"description":       nilIfEmpty(m.productDesc),
			"is_active":         true,
			"image_url":         nilIfEmpty(m.productImage),
			"product_type_code": nilIfEmpty(m.productTypeCode),
			"gallery":           gallery,
			"topping_groups":    toppingGroups,
		}
		dineIn, takeaway, isAlcohol, hasTax := s.resolveProductTaxRates(m.productID)
		productPayload["is_alcohol"] = isAlcohol
		if hasTax {
			productPayload["tax_rate_dine_in"] = dineIn
			productPayload["tax_rate_takeaway"] = takeaway
		} else {
			productPayload["tax_rate_dine_in"] = nil
			productPayload["tax_rate_takeaway"] = nil
		}

		mp := map[string]any{
			"id":               m.id,
			"menu_id":          menuID,
			"product_id":       m.productID,
			"menu_section_id":  nilIfEmpty(m.sectionID),
			"is_active":        m.active == 1,
			"display_order":    m.displayOrder,
			"skus":             skus,
			"product":          productPayload,
			"section":          nil,
			"active_promotion": activePromo,
		}
		if m.sectionID != "" && m.sectionName.Valid {
			mp["section"] = map[string]any{
				"id":   m.sectionID,
				"name": m.sectionName.String,
			}
		}
		out = append(out, mp)
	}
	return out, nil
}

// loadProductGallery returns every gallery row for one product, ordered
// the same way ProductResource.gallery does on Cloud (sort_order asc,
// NULL last). pos-web's MenuProductTile carousel renders this list
// directly; an empty slice means "no images" and the tile uses the
// placeholder.
func (s *Server) loadProductGallery(productID string) ([]map[string]any, error) {
	rows, err := s.db.Query(`
		SELECT id, url, COALESCE(original_name, ''), COALESCE(mime_type, ''),
		       sort_order
		FROM pos_product_galleries
		WHERE product_id = ?
		ORDER BY (sort_order IS NULL), sort_order, id`, productID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var id, url, origName, mime string
		var sortOrder sql.NullInt64
		if err := rows.Scan(&id, &url, &origName, &mime, &sortOrder); err != nil {
			return nil, err
		}
		row := map[string]any{
			"id":            id,
			"url":           url,
			"original_name": nilIfEmpty(origName),
			"mime_type":     nilIfEmpty(mime),
			"sort_order":    nil,
		}
		if sortOrder.Valid {
			row["sort_order"] = sortOrder.Int64
		}
		out = append(out, row)
	}
	return out, nil
}

// resolveProductTaxRates resolves a product's effective 店内/持ち帰り tax rates
// (+ alcohol flag) for the pos-web 税込/税抜 menu display, from the synced local
// mirror. Mirrors the plan-043 resolver chain WITHOUT a Cloud round-trip:
//
//	menu_items.tax_type_id (per-item override, usually null = inherit)
//	  → shop_settings.default_tax_type_id (branch default)
//	    → tax_types.is_default (brand default)
//	→ tax_types.rate_dine_in / rate_takeaway
//
// 酒類 escalation (A1): an alcohol product on a reduced type (takeaway < dine-in)
// is forced to the dine-in rate for both. `ok` is false when nothing resolves
// (fresh org) so the caller emits null rates and pos-web skips the reference.
func (s *Server) resolveProductTaxRates(productID string) (dineIn, takeaway float64, isAlcohol bool, ok bool) {
	var taxTypeID sql.NullString
	var alc sql.NullInt64
	// A product's SKUs share its tax type — read the first active menu_items row.
	_ = s.db.QueryRow(`
		SELECT mi.tax_type_id, COALESCE(mi.is_alcohol, 0)
		FROM menu_items mi
		JOIN pos_product_skus ps ON ps.id = mi.sku_id
		WHERE ps.product_id = ? AND mi.is_active = 1
		LIMIT 1`, productID).Scan(&taxTypeID, &alc)
	isAlcohol = alc.Int64 == 1

	effectiveID := ""
	if taxTypeID.Valid && taxTypeID.String != "" {
		effectiveID = taxTypeID.String
	} else {
		var def sql.NullString
		_ = s.db.QueryRow(`SELECT value FROM shop_settings WHERE key = 'default_tax_type_id'`).Scan(&def)
		if def.Valid && def.String != "" {
			effectiveID = def.String
		} else {
			_ = s.db.QueryRow(`SELECT id FROM tax_types WHERE is_default = 1 AND is_active = 1 LIMIT 1`).Scan(&effectiveID)
		}
	}
	if effectiveID == "" {
		return 0, 0, isAlcohol, false
	}
	if err := s.db.QueryRow(
		`SELECT rate_dine_in, rate_takeaway FROM tax_types WHERE id = ?`, effectiveID,
	).Scan(&dineIn, &takeaway); err != nil {
		return 0, 0, isAlcohol, false
	}
	if isAlcohol && takeaway < dineIn {
		takeaway = dineIn
	}
	return dineIn, takeaway, isAlcohol, true
}

func (s *Server) loadProductSkus(productID string, _ time.Time, locale string) ([]map[string]any, error) {
	// Drain Rows BEFORE calling resolveProductOptionValue — each
	// option_value resolution is its own QueryRow + nested
	// QueryRow against pos_product_options. Holding this Rows open
	// across 3 lookups per row was one of the layers contributing
	// to the conn-pool deadlock.
	type skuRow struct {
		id, name, sku, image, ov1, ov2, ov3 string
		price, active, overridden           int
		defaultPrice                        sql.NullInt64
	}
	rows, err := s.db.Query(fmt.Sprintf(`
		SELECT id, COALESCE(%s, ''), COALESCE(sku, ''), selling_price, is_active,
		       COALESCE(image_url, ''),
		       default_price, is_price_overridden,
		       COALESCE(option_value1_id, ''),
		       COALESCE(option_value2_id, ''),
		       COALESCE(option_value3_id, '')
		FROM pos_product_skus WHERE product_id = ? AND is_active = 1
		ORDER BY selling_price`, localizedNameExpr("", "name", locale)), productID)
	if err != nil {
		return nil, err
	}
	skus := []skuRow{}
	for rows.Next() {
		var sr skuRow
		if err := rows.Scan(&sr.id, &sr.name, &sr.sku, &sr.price, &sr.active, &sr.image,
			&sr.defaultPrice, &sr.overridden, &sr.ov1, &sr.ov2, &sr.ov3); err != nil {
			rows.Close()
			return nil, err
		}
		skus = append(skus, sr)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()

	out := []map[string]any{}
	for _, sr := range skus {
		row := map[string]any{
			"id": sr.id,
			// CRITICAL: also emit `product_sku_id` mirroring Cloud's
			// MenuProductSkuResource. pos-web's add-to-cart payload
			// reads `sku.product_sku_id` (NOT `sku.id`) and forwards
			// it as the canonical ProductSku UUID — workstation's
			// createItem then looks the row up by sku_id. Without
			// emitting this field pos-web sent product_sku_id=null
			// and every "Thêm vào đơn" returned 500 with
			// "menu item not found: sql: no rows in result set".
			//
			// In Cloud, MenuProductSkuResource.id is the pivot
			// row uuid while product_sku_id is the inner ProductSku
			// uuid. The LAN replica has no separate pivot table —
			// pos_product_skus.id IS the ProductSku uuid — so both
			// fields carry the same value here.
			"product_sku_id":      sr.id,
			"product_id":          productID,
			"name":                nilIfEmpty(sr.name),
			"sku":                 nilIfEmpty(sr.sku),
			"selling_price":       sr.price,
			"is_active":           sr.active == 1,
			"image_url":           nilIfEmpty(sr.image),
			"is_price_overridden": sr.overridden == 1,
		}
		// default_price emitted ONLY when an override is active —
		// matches MenuProductSkuResource.php:25-28 (conditional
		// `when($this->is_price_overridden)`). pos-web uses this for
		// the strike-through original price; nil otherwise.
		if sr.overridden == 1 && sr.defaultPrice.Valid {
			row["default_price"] = sr.defaultPrice.Int64
		} else {
			row["default_price"] = nil
		}
		// product_sku envelope mirrors ProductSkuResource —
		// optionValue1/2/3 each emit a {id, option:{...}, value,
		// label, position, is_active} object when loaded. We resolve
		// from local pos_product_option_values + pos_product_options
		// at read time.
		row["option_value1"] = s.resolveProductOptionValue(sr.ov1, locale)
		row["option_value2"] = s.resolveProductOptionValue(sr.ov2, locale)
		row["option_value3"] = s.resolveProductOptionValue(sr.ov3, locale)
		// `product_sku` is the eager-loaded reverse on MenuProductSkuResource
		// (line 30) — pos-web reads sku_variant_name + optionValue chain
		// through this nested object. We mirror just the slice MenuProductSku
		// actually consumes.
		row["product_sku"] = map[string]any{
			"id":            sr.id,
			"product_id":    productID,
			"name":          nilIfEmpty(sr.name),
			"sku":           nilIfEmpty(sr.sku),
			"selling_price": sr.price,
			"image_url":     nilIfEmpty(sr.image),
			"option_value1": row["option_value1"],
			"option_value2": row["option_value2"],
			"option_value3": row["option_value3"],
		}
		out = append(out, row)
	}
	return out, nil
}

// loadProductToppingGroups returns the MenuToppingGroupResource shape
// for one (menuProductID, productID) tuple. The 3-tier override
// resolution matches MenuToppingGroupItemResource.php exactly:
//
//	tier 1 (shop) — pos_menu_product_topping_overrides — keyed by
//	  (menu_product_id, topping_group_item_id, product_sku_id)
//	tier 2 (product) — pos_product_topping_item_overrides — keyed by
//	  (product_id, topping_group_item_id, product_sku_id)
//	tier 3 (base) — pos_topping_group_item_skus.extra_price
//
// `effective_min_select` / `effective_max_select` mirror the
// Resource's tier-1 COALESCE: pivot override → group default.
// Hidden items (any tier flagging is_hidden) drop out entirely
// because pos-web's MenuToppingGroup renderer ignores them.
//
// menuProductID may be empty (e.g. for the `loadMenuShape` legacy
// callers); when empty, only tier-2 + tier-3 apply.
func (s *Server) loadProductToppingGroups(menuProductID, productID, locale string) ([]map[string]any, error) {
	// CRITICAL: drain Rows BEFORE doing any nested query. Each open
	// `*sql.Rows` pins one connection from the pool for its lifetime,
	// and `loadToppingGroupItems` → `resolveToppingItemSkus` each open
	// further Rows. With 3 levels of nesting and SetMaxOpenConns(8),
	// 2-3 concurrent pos-web requests easily drain the pool — every
	// subsequent SQL call (including
	// workstationBranchID() on the LAN health probe path) blocks
	// indefinitely on `database/sql.(*DB).conn`. Production goroutine
	// dump on 2026-06-15 showed 59 goroutines waiting on
	// `(*DB).conn` while no goroutine was actually running SQL — the
	// textbook "Rows-iter + nested-query" deadlock. The fix is to
	// scan the row payload into a slice, close the Rows immediately,
	// then run the nested loaders.
	type groupRow struct {
		id, name, selType, modType, priceStrat        string
		freeQty, maxSel, minOv, maxOv                 sql.NullInt64
		minSelect, maxQtyPerItem, sortOrder, isActive int
	}
	groupRows, err := s.db.Query(fmt.Sprintf(`
		SELECT g.id, %s, g.selection_type, g.modifier_type, g.price_strategy,
		       g.free_quantity, g.min_select, g.max_select, g.max_qty_per_item,
		       pivot.sort_order, g.is_active,
		       pivot.min_select_override, pivot.max_select_override
		FROM pos_product_topping_groups pivot
		JOIN pos_topping_groups g ON g.id = pivot.topping_group_id
		WHERE pivot.product_id = ? AND g.is_active = 1
		ORDER BY pivot.sort_order, g.name`, localizedNameExpr("g", "name", locale)), productID)
	if err != nil {
		return nil, err
	}
	groups := []groupRow{}
	for groupRows.Next() {
		var g groupRow
		if err := groupRows.Scan(&g.id, &g.name, &g.selType, &g.modType, &g.priceStrat,
			&g.freeQty, &g.minSelect, &g.maxSel, &g.maxQtyPerItem, &g.sortOrder, &g.isActive,
			&g.minOv, &g.maxOv); err != nil {
			groupRows.Close()
			return nil, err
		}
		groups = append(groups, g)
	}
	if err := groupRows.Err(); err != nil {
		groupRows.Close()
		return nil, err
	}
	groupRows.Close()

	out := []map[string]any{}
	for _, g := range groups {
		items, err := s.loadToppingGroupItems(menuProductID, productID, g.id, locale)
		if err != nil {
			return nil, err
		}
		out = append(out, map[string]any{
			"id":                   g.id,
			"name":                 g.name,
			"selection_type":       g.selType,
			"modifier_type":        g.modType,
			"price_strategy":       g.priceStrat,
			"free_quantity":        nullableInt64(g.freeQty),
			"min_select":           g.minSelect,
			"max_select":           nullableInt64(g.maxSel),
			"max_qty_per_item":     g.maxQtyPerItem,
			"effective_min_select": int64IfValid(g.minOv, int64(g.minSelect)),
			"effective_max_select": int64IfValidPtr(g.maxOv, nullableInt64(g.maxSel)),
			"sort_order":           g.sortOrder,
			"is_active":            g.isActive == 1,
			"items":                items,
		})
	}
	return out, nil
}

// loadToppingGroupItems materializes the MenuToppingGroupItemResource
// list — joining items → item_skus → tier-1/2 overrides per the order
// in the Resource: shop override beats product override beats base.
// Items wholly hidden by any active tier are skipped (matches
// MenuToppingGroupItemResource line 23-55 where is_hidden=true short-
// circuits emission).
func (s *Server) loadToppingGroupItems(menuProductID, productID, groupID, locale string) ([]map[string]any, error) {
	// Drain rows BEFORE nested queries — see loadProductToppingGroups
	// for the deadlock rationale. resolveToppingItemSkus itself opens
	// more Rows + nested override lookups, so this is the second
	// layer of pinning if we don't close itemRows first.
	type itemRow struct {
		itemID, itemProductID string
		sortOrder, isDefault  int
	}
	itemRows, err := s.db.Query(`
		SELECT id, product_id, sort_order, is_default
		FROM pos_topping_group_items
		WHERE topping_group_id = ?
		ORDER BY sort_order, id`, groupID)
	if err != nil {
		return nil, err
	}
	items := []itemRow{}
	for itemRows.Next() {
		var ir itemRow
		if err := itemRows.Scan(&ir.itemID, &ir.itemProductID, &ir.sortOrder, &ir.isDefault); err != nil {
			itemRows.Close()
			return nil, err
		}
		items = append(items, ir)
	}
	if err := itemRows.Err(); err != nil {
		itemRows.Close()
		return nil, err
	}
	itemRows.Close()

	out := []map[string]any{}
	for _, ir := range items {
		// Resolve item name + image_url from the linked product so
		// the resource shape matches Cloud's `items[].name` /
		// `image_url`. The item references a product (the "topping
		// product") that already lives in pos_products with a
		// gallery_first style image_url column.
		var itemName, itemImage string
		_ = s.db.QueryRow(fmt.Sprintf(`SELECT COALESCE(%s, ''), COALESCE(image_url, '') FROM pos_products WHERE id = ?`,
			localizedNameExpr("", "name", locale)), ir.itemProductID).
			Scan(&itemName, &itemImage)

		skus, hidden, err := s.resolveToppingItemSkus(menuProductID, productID, ir.itemID, locale)
		if err != nil {
			return nil, err
		}
		if hidden {
			continue
		}

		out = append(out, map[string]any{
			"id":               ir.itemID,
			"topping_group_id": groupID,
			"product_id":       ir.itemProductID,
			"name":             itemName,
			"image_url":        nilIfEmpty(itemImage),
			"is_default":       ir.isDefault == 1,
			"is_hidden":        false,
			"sort_order":       ir.sortOrder,
			"skus":             skus,
		})
	}
	return out, nil
}

// resolveToppingItemSkus walks every base topping_group_item_skus row
// for one item, applying tier-1 (menu_product) → tier-2 (product) →
// tier-3 (base) precedence on both is_hidden and extra_price. Returns
// (skus, hiddenItem) — when the item is fully hidden across every
// tier-1/2 override the caller drops the row entirely.
func (s *Server) resolveToppingItemSkus(menuProductID, productID, itemID, locale string) ([]map[string]any, bool, error) {
	// Drain Rows BEFORE the tier-1/2 + sku-label lookups (each of
	// which opens its own Rows). The previous shape held this Rows
	// open across `loadMenuProductToppingOverrides`,
	// `loadProductToppingItemOverrides`, AND a per-iteration QueryRow
	// against pos_product_skus — 3 nested conn pins per outer row,
	// which under load + the 8-conn pool cap escalated into the
	// `(*DB).conn` wait deadlock that killed /api/lan/health.
	type skuRow struct {
		skuRowID string
		skuID    sql.NullString
		extra    int
	}
	rows, err := s.db.Query(`
		SELECT id, product_sku_id, extra_price
		FROM pos_topping_group_item_skus
		WHERE topping_group_item_id = ?`, itemID)
	if err != nil {
		return nil, false, err
	}
	baseRows := []skuRow{}
	for rows.Next() {
		var r skuRow
		if err := rows.Scan(&r.skuRowID, &r.skuID, &r.extra); err != nil {
			rows.Close()
			return nil, false, err
		}
		baseRows = append(baseRows, r)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, false, err
	}
	rows.Close()

	tier1 := s.loadMenuProductToppingOverrides(menuProductID, itemID)
	tier2 := s.loadProductToppingItemOverrides(productID, itemID)

	out := []map[string]any{}
	allHidden := true
	sawAny := len(baseRows) > 0
	for _, br := range baseRows {
		productSkuID := ""
		if br.skuID.Valid {
			productSkuID = br.skuID.String
		}

		// Tier-1 lookup keyed by product_sku_id (or "" for the
		// simple-topping fallback row that carries no sku binding).
		price := br.extra
		hidden := false
		if ov, ok := tier1[productSkuID]; ok {
			if ov.overridePrice != nil {
				price = *ov.overridePrice
			}
			if ov.isHidden {
				hidden = true
			}
		} else if ov, ok := tier2[productSkuID]; ok {
			if ov.overridePrice != nil {
				price = *ov.overridePrice
			}
			if ov.isHidden {
				hidden = true
			}
		}
		if hidden {
			continue
		}
		allHidden = false

		// Resolve sku_label / sku_code from pos_product_skus when
		// the row carries a real product_sku_id, matching
		// MenuToppingGroupItemSkuResource.
		var skuLabel, skuCode string
		if productSkuID != "" {
			_ = s.db.QueryRow(fmt.Sprintf(`SELECT COALESCE(%s, ''), COALESCE(sku, '')
				FROM pos_product_skus WHERE id = ?`, localizedNameExpr("", "name", locale)), productSkuID).Scan(&skuLabel, &skuCode)
		}

		row := map[string]any{
			"id":                    br.skuRowID,
			"topping_group_item_id": itemID,
			"product_sku_id":        nil,
			"extra_price":           fmt.Sprintf("%d", price),
			"sku_label":             nilIfEmpty(skuLabel),
			"sku_code":              nilIfEmpty(skuCode),
		}
		if productSkuID != "" {
			row["product_sku_id"] = productSkuID
		}
		out = append(out, row)
	}
	if !sawAny {
		// Empty base item — no skus at all, nothing to hide.
		return out, false, nil
	}
	return out, allHidden, nil
}

type toppingOverrideRow struct {
	isHidden      bool
	overridePrice *int
}

// loadMenuProductToppingOverrides returns the tier-1 (shop) overrides
// indexed by product_sku_id ("" for the simple-topping fallback).
// menuProductID may be empty when the call originates from a context
// without a specific menu_product binding (e.g. the bare menu detail
// load) — in that case we skip tier-1 entirely.
func (s *Server) loadMenuProductToppingOverrides(menuProductID, itemID string) map[string]toppingOverrideRow {
	out := map[string]toppingOverrideRow{}
	if menuProductID == "" || itemID == "" {
		return out
	}
	rows, err := s.db.Query(`
		SELECT COALESCE(product_sku_id, ''), is_hidden, override_price
		FROM pos_menu_product_topping_overrides
		WHERE menu_product_id = ? AND topping_group_item_id = ?`, menuProductID, itemID)
	if err != nil {
		return out
	}
	defer rows.Close()
	for rows.Next() {
		var key string
		var hidden int
		var op sql.NullInt64
		if err := rows.Scan(&key, &hidden, &op); err != nil {
			continue
		}
		row := toppingOverrideRow{isHidden: hidden == 1}
		if op.Valid {
			v := int(op.Int64)
			row.overridePrice = &v
		}
		out[key] = row
	}
	return out
}

func (s *Server) loadProductToppingItemOverrides(productID, itemID string) map[string]toppingOverrideRow {
	out := map[string]toppingOverrideRow{}
	if productID == "" || itemID == "" {
		return out
	}
	rows, err := s.db.Query(`
		SELECT COALESCE(product_sku_id, ''), is_hidden, override_price
		FROM pos_product_topping_item_overrides
		WHERE product_id = ? AND topping_group_item_id = ?`, productID, itemID)
	if err != nil {
		return out
	}
	defer rows.Close()
	for rows.Next() {
		var key string
		var hidden int
		var op sql.NullInt64
		if err := rows.Scan(&key, &hidden, &op); err != nil {
			continue
		}
		row := toppingOverrideRow{isHidden: hidden == 1}
		if op.Valid {
			v := int(op.Int64)
			row.overridePrice = &v
		}
		out[key] = row
	}
	return out
}

func nullableInt64(v sql.NullInt64) any {
	if v.Valid {
		return v.Int64
	}
	return nil
}

// int64IfValid returns override.Int64 when valid; otherwise falls back to base.
func int64IfValid(override sql.NullInt64, base int64) int64 {
	if override.Valid {
		return override.Int64
	}
	return base
}

// int64IfValidPtr is the pointer-fallback variant: when override is
// NULL we propagate whatever the base reference already resolves to
// (which itself may be `any nil` for an unbounded max_select).
func int64IfValidPtr(override sql.NullInt64, base any) any {
	if override.Valid {
		return override.Int64
	}
	return base
}

// resolveProductOptionValue mirrors ProductOptionValueResourceBase: id +
// option_id + value + label + position + is_active + parent option
// {id, product_id, key, name, position, is_active}. Empty input id →
// nil (matches `whenLoaded` returning omitted in Cloud).
func (s *Server) resolveProductOptionValue(id, locale string) any {
	if id == "" {
		return nil
	}
	var valueID, optionID, value, label string
	var position, active int
	err := s.db.QueryRow(fmt.Sprintf(`
		SELECT id, option_id, value, COALESCE(%s, ''), position, is_active
		FROM pos_product_option_values WHERE id = ?`, localizedNameExpr("", "label", locale)), id).Scan(
		&valueID, &optionID, &value, &label, &position, &active)
	if err != nil {
		return nil
	}
	opt := s.resolveProductOption(optionID, locale)
	return map[string]any{
		"id":        valueID,
		"option_id": optionID,
		"option":    opt,
		"value":     value,
		"label":     nilIfEmpty(label),
		"position":  position,
		"is_active": active == 1,
	}
}

func (s *Server) resolveProductOption(id, locale string) any {
	if id == "" {
		return nil
	}
	var optionID, productID, key, name string
	var position, active int
	err := s.db.QueryRow(fmt.Sprintf(`
		SELECT id, product_id, key, COALESCE(%s, ''), position, is_active
		FROM pos_product_options WHERE id = ?`, localizedNameExpr("", "name", locale)), id).Scan(
		&optionID, &productID, &key, &name, &position, &active)
	if err != nil {
		return nil
	}
	return map[string]any{
		"id":         optionID,
		"product_id": productID,
		"key":        key,
		"name":       name,
		"position":   position,
		"is_active":  active == 1,
	}
}

// activePromotionForProduct returns the matching ActivePromotionBlock if
// any SKU on this menu_product row falls inside an active promotion
// window. Uses the same SQL + day/time logic PromotionEngine consults on
// item-add — staff sees the price they'll get, no surprises at checkout.
func (s *Server) activePromotionForProduct(skus []map[string]any, now time.Time) any {
	if len(skus) == 0 {
		return nil
	}

	// Resolve the winning promotion through the SAME engine the order path
	// uses (PromotionEngine.ResolveForSku), so the badge equals what the cart
	// will actually charge — same active window + daily menu_promotion_schedules
	// filtering and the same highest-discount tie-break. This method previously
	// ran its own SQL that IGNORED the daily schedule and tie-broke on the
	// legacy `priority` column, so the tile advertised discounts the cart
	// wouldn't honor (a Happy Hour promo outside its scheduled hours, or a
	// lower-% higher-priority promo). All SKUs of a product share the same
	// product-level candidates, so resolving with any one SKU yields the winner
	// the whole product gets.
	var repSkuID string
	cheapest := 0
	for _, sk := range skus {
		if id, ok := sk["id"].(string); ok && repSkuID == "" {
			repSkuID = id
		}
		if p, ok := sk["selling_price"].(int); ok {
			if cheapest == 0 || p < cheapest {
				cheapest = p
			}
		}
	}
	if repSkuID == "" {
		return nil
	}

	winner, err := service.NewPromotionEngine(s.db).ResolveForSku(repSkuID, now)
	if err != nil || winner == nil {
		return nil
	}

	// discounted_price is informational (pos-web recomputes the shown range
	// from discount_percent); applyDiscountForBadge mirrors service.applyDiscount
	// so it equals the cart's final price for the cheapest SKU.
	discounted := applyDiscountForBadge(cheapest, winner.DiscountType, winner.DiscountValue)
	percent := 0
	if winner.DiscountType == "percent" {
		percent = winner.DiscountValue
	}
	stacking := "stackable_with_coupons"
	if winner.ExclusiveWithCoupons {
		stacking = "exclusive_with_coupons"
	}
	return map[string]any{
		"id":               winner.ID,
		"discount_percent": percent,
		"discounted_price": discounted,
		"ends_at":          winner.EndsAt,
		"stacking_mode":    stacking,
	}
}

// applyDiscountForBadge is a stripped-down mirror of
// service.applyDiscount used only for the LAN menu badge rendering.
// Importing internal/service from internal/handler would cycle, so we
// keep this small copy here.
func applyDiscountForBadge(original int, kind string, value int) int {
	switch kind {
	case "percent":
		if value <= 0 {
			return original
		}
		// discount_value is a plain percent (Cloud: 15% → 15), matching
		// service.applyDiscount + the coupon path. (Was / 10000 = basis points,
		// which applied a 15% promo as 0.15%.)
		if value >= 100 {
			return 0
		}
		return (original * (100 - value)) / 100
	case "amount":
		final := original - value
		if final < 0 {
			final = 0
		}
		return final
	case "fixed_unit_price":
		if value < 0 {
			value = 0
		}
		return value
	default:
		return original
	}
}
