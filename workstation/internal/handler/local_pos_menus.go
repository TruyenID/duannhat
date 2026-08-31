package handler

import (
	"database/sql"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/domain/generated/enums"
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

	// #1756 — service_type is emitted, not just filtered on. NULL (a row
	// synced before migration 037 added the column) collapses to 'Both'
	// here for the same reason the gate below lets it through: an
	// un-resynced mirror must degrade to "always visible", and 'Both' is
	// exactly that value spelled out. The stored value is already the
	// EFFECTIVE one — Cloud's MenuCatalogReplicaBuilder resolves
	// own ?? master ?? 'Both' before it ever reaches this table — so there
	// is no inheritance left to walk on the LAN side.
	q := `SELECT id, name, COALESCE(description, ''), status, sort_order,
	             COALESCE(service_type, 'Both')
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
		var id, name, desc, status, serviceType string
		var sortOrder int
		if err := rows.Scan(&id, &name, &desc, &status, &sortOrder, &serviceType); err != nil {
			writeServerError(w, r, err)
			return
		}
		data = append(data, map[string]any{
			"id":           id,
			"name":         name,
			"description":  nilIfEmpty(desc),
			"status":       status,
			"service_type": serviceType,
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
	// The calendar window is applied here too (#1970). Cloud's POS surface used
	// to ignore it while its guest surface did not (#1237); since that was
	// settled the other way, a LAN POS that skipped this check would be the
	// LOOSER of the two — an expired campaign menu still sellable precisely
	// when the shop is offline. `shopToday` is the SHOP's calendar date, which
	// is what a campaign window means; NULL or '' is unbounded, matching
	// Cloud's NULL semantics.
	today := s.shopToday()
	scheduleRows, err := s.db.Query(`
		SELECT menu_id, COALESCE(start_time, ''), COALESCE(end_time, ''), COALESCE(priority, 0),
		       COALESCE(start_date, ''), COALESCE(end_date, ''),
		       COALESCE(recurrence_kind, 'Weekly'), COALESCE(days_of_month, 0),
		       COALESCE(specific_dates, '')
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
	// Whether ANY schedule row covers this weekday, window ignored. It is what
	// separates "this shop keeps no per-day schedules" (fall back to every
	// menu, below) from "its windows have all closed" (emit nothing). Without
	// the distinction an expiring campaign would empty `hits` and trip the
	// fallback, putting the very menu #1970 just retired back on the till.
	scheduledForDow := false
	for scheduleRows.Next() {
		var menuID, st, et, sd, ed, kind, dates string
		var pr int
		var dom int64
		if err := scheduleRows.Scan(&menuID, &st, &et, &pr, &sd, &ed, &kind, &dom, &dates); err != nil {
			scheduleRows.Close()
			writeServerError(w, r, err)
			return
		}
		scheduledForDow = true
		// Calendar window (#1970). "" = unbounded on either side, matching
		// Cloud's NULL semantics. Lexical compare is ordering-correct for the
		// `YYYY-MM-DD` the feed sends.
		if (sd != "" && today < sd) || (ed != "" && today > ed) {
			continue
		}
		// Recurrence kind (#1979). A non-weekly row is fed as all seven
		// weekdays — the mirror's day_of_week column is NOT NULL with a 0–6
		// CHECK — so the weekday filter above always passes for those and the
		// real decision happens here.
		if !scheduleCoversDate(kind, dom, dates, today) {
			continue
		}
		// First SURVIVING row per menu wins (ORDER BY priority ASC) — an
		// out-of-window row must not claim the menu and report its hours.
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
		serviceType            string
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
				       COALESCE(service_type, 'Both'),
				       -- plan-056: dishes ON SALE, not rows in the table. The
				       -- feed now carries turned-off dishes, so a bare COUNT(*)
				       -- would inflate the badge on the menu picker and promise
				       -- the cashier food the shop does not have.
				       (SELECT COUNT(*) FROM pos_menu_products mp
				         LEFT JOIN pos_menu_availability_overrides ov
				                ON ov.entity_type = 'menu_product' AND ov.entity_id = mp.id
				        WHERE mp.menu_id = m.id
				          AND COALESCE(ov.is_active, mp.is_active) = 1) AS menu_products_count
				FROM pos_menus m
				WHERE id IN (` + placeholders + `)
				  AND status IN ('published','Published','active','Active')`
		} else {
			query = `
				SELECT id, name, COALESCE(description, ''), status,
				       COALESCE(service_type, 'Both'),
				       -- plan-056: dishes ON SALE, not rows in the table. The
				       -- feed now carries turned-off dishes, so a bare COUNT(*)
				       -- would inflate the badge on the menu picker and promise
				       -- the cashier food the shop does not have.
				       (SELECT COUNT(*) FROM pos_menu_products mp
				         LEFT JOIN pos_menu_availability_overrides ov
				                ON ov.entity_type = 'menu_product' AND ov.entity_id = mp.id
				        WHERE mp.menu_id = m.id
				          AND COALESCE(ov.is_active, mp.is_active) = 1) AS menu_products_count
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
			if err := mrows.Scan(&mr.id, &mr.name, &mr.desc, &mr.status, &mr.serviceType, &mr.productsCount); err != nil {
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
	} else if !scheduledForDow {
		// Schedule-empty fallback (LAN-pragmatic divergence from Cloud
		// strict empty). Only when the shop keeps NO schedule row for this
		// weekday — never when its rows exist and their calendar windows
		// have simply closed (#1970).
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
			"service_type":        mr.serviceType,
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
	// false — this is the ORDERING shape. Every caller of loadMenuShape feeds a
	// screen the cashier picks dishes from, so a turned-off dish must not reach
	// it. The management screen has its own handler and passes true there.
	products, err := s.loadMenuProducts(id, now, locale, false)
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

// loadMenuProducts returns one menu's dishes.
//
// `includeInactive` is false for every ORDERING caller (menu detail, product
// list) — those must keep answering exactly what they answered before
// plan-056 — and true only for the management screen, which cannot switch a
// dish back on if it cannot see it.
func (s *Server) loadMenuProducts(menuID string, now time.Time, locale string, includeInactive bool) ([]map[string]any, error) {
	// The outer page is drained first, then every relation is loaded in fixed
	// batches by hydrateMenuProductRows. Keeping both steps separate prevents
	// pool pinning and keeps large menus from multiplying SQL statements.
	// plan-056 — `COALESCE(ov.is_active, mp.is_active)`: a local toggle that has
	// not reached Cloud yet wins over the replica. Without it the shop turns a
	// dish off, an unrelated HQ edit triggers a pull, and the dish is quietly
	// back on the menu with staff none the wiser.
	availabilityGate := ""
	if !includeInactive {
		availabilityGate = " AND COALESCE(ov.is_active, mp.is_active) = 1"
	}

	rows, err := s.db.Query(fmt.Sprintf(`
		SELECT mp.id, mp.product_id, COALESCE(mp.menu_section_id, ''),
		       COALESCE(ov.is_active, mp.is_active), mp.display_order,
		       %s AS product_name, COALESCE(p.description, ''),
		       COALESCE(p.image_url, ''),
		       COALESCE(p.product_type_code, ''),
		       s.name AS section_name,
		       COALESCE(ov.reason, mp.disabled_reason),
		       mp.disabled_at,
		       COALESCE(ov.actor_name, mp.disabled_by_name)
		FROM pos_menu_products mp
		JOIN pos_products p ON p.id = mp.product_id
		LEFT JOIN pos_menu_sections s
		       ON s.id = mp.menu_section_id AND s.menu_id = mp.menu_id
		LEFT JOIN pos_menu_availability_overrides ov
		       ON ov.entity_type = 'menu_product' AND ov.entity_id = mp.id
		WHERE mp.menu_id = ?%s
		ORDER BY mp.display_order, p.name`, productNameExpr(locale), availabilityGate), menuID)
	if err != nil {
		return nil, err
	}
	mps := []menuProductRow{}
	for rows.Next() {
		var m menuProductRow
		m.menuID = menuID
		if err := rows.Scan(&m.id, &m.productID, &m.sectionID, &m.active, &m.displayOrder,
			&m.productName, &m.productDesc, &m.productImage, &m.productTypeCode, &m.sectionName,
			&m.disabledReason, &m.disabledAt, &m.disabledByName); err != nil {
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
	return s.hydrateMenuProductRows(mps, now, locale, includeInactive, true)
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

// resolveProductTaxRates resolves a product's effective tax rate for the
// pos-web 税込/税抜 menu display, from the synced local mirror. Mirrors the
// resolver chain WITHOUT a Cloud round-trip:
//
//	menu_items.tax_type_id (per-item override, usually null = inherit)
//	  → shop_settings.default_tax_type_id (branch default)
//	    → tax_types.is_default (brand default)
//	→ tax_types.rate
//
// #1099 single-rate: ONE number, no order-type branch, no escalation.
// `ok` is false when nothing resolves (fresh org) so the caller emits a
// null rate and pos-web skips the reference.
func (s *Server) resolveProductTaxRate(productID string) (rate float64, ok bool) {
	var taxTypeID sql.NullString
	// A product's SKUs share its tax type — read the first active menu_items row.
	_ = s.db.QueryRow(`
		SELECT mi.tax_type_id
		FROM menu_items mi
		JOIN pos_product_skus ps ON ps.id = mi.sku_id
		WHERE ps.product_id = ? AND mi.is_active = 1
		LIMIT 1`, productID).Scan(&taxTypeID)

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
		return 0, false
	}
	if err := s.db.QueryRow(
		`SELECT rate FROM tax_types WHERE id = ?`, effectiveID,
	).Scan(&rate); err != nil {
		return 0, false
	}
	return rate, true
}

// loadProductSkus returns the variants of one product AS SEEN FROM ONE
// menu_product.
//
// ## Why it takes menuProductID (plan-056)
//
// It used to key on product_id alone, which meant one product in two menus had
// exactly one variant state on the workstation. That was already wrong before
// this feature: a variant turned off in the lunch menu but on in the dinner one
// shipped as "on" and stayed orderable in BOTH, because the feed put it in
// scope via the live menu and the read had no way to tell the two apart. The
// pivot join fixes it — the same shape `loadProductToppingGroups` next door has
// used for its tier-1 overrides all along.
//
// ## The join is LEFT, and that is load-bearing
//
// A catalog SKU with NO `pos_menu_product_skus` row still has to appear. That
// happens whenever HQ adds a SKU to a product after the branch cloned the menu:
// there is no pivot row yet, and the ordering screen shows the variant today.
// An INNER JOIN would make it silently vanish from the cart picker — a
// regression nobody would trace back to an availability feature.
// `COALESCE(..., 1)` spells out the same rule: no pivot row means available.
//
// `includeInactive` is passed by exactly one caller (the management screen).
// The ordering paths pass false and get byte-identical results to before.
func (s *Server) loadProductSkus(menuProductID, productID string, _ time.Time, locale string, includeInactive bool) ([]map[string]any, error) {
	// Drain Rows BEFORE calling resolveProductOptionValue — each
	// option_value resolution is its own QueryRow + nested
	// QueryRow against pos_product_options. Holding this Rows open
	// across 3 lookups per row was one of the layers contributing
	// to the conn-pool deadlock.
	type skuRow struct {
		id, name, sku, image, ov1, ov2, ov3 string
		price, active, overridden           int
		defaultPrice                        sql.NullInt64
		menuProductSkuID                    sql.NullString
		menuActive                          int
		disabledReason                      sql.NullString
		disabledAt                          sql.NullString
		disabledByName                      sql.NullString
	}

	availabilityGate := ""
	if !includeInactive {
		availabilityGate = " AND COALESCE(ov.is_active, mps.is_active, 1) = 1"
	}

	rows, err := s.db.Query(fmt.Sprintf(`
		SELECT ps.id, COALESCE(%s, ''), COALESCE(ps.sku, ''), ps.selling_price, ps.is_active,
		       COALESCE(ps.image_url, ''),
		       ps.default_price, ps.is_price_overridden,
		       COALESCE(ps.option_value1_id, ''),
		       COALESCE(ps.option_value2_id, ''),
		       COALESCE(ps.option_value3_id, ''),
		       mps.id,
		       COALESCE(ov.is_active, mps.is_active, 1),
		       COALESCE(ov.reason, mps.disabled_reason),
		       mps.disabled_at,
		       COALESCE(ov.actor_name, mps.disabled_by_name)
		FROM pos_product_skus ps
		LEFT JOIN pos_menu_product_skus mps
		       ON mps.product_sku_id = ps.id AND mps.menu_product_id = ?
		LEFT JOIN pos_menu_availability_overrides ov
		       ON ov.entity_type = 'menu_product_sku' AND ov.entity_id = mps.id
		WHERE ps.product_id = ? AND ps.is_active = 1%s
		ORDER BY ps.selling_price`,
		localizedNameExpr("ps", "name", locale), availabilityGate), menuProductID, productID)
	if err != nil {
		return nil, err
	}
	skus := []skuRow{}
	for rows.Next() {
		var sr skuRow
		if err := rows.Scan(&sr.id, &sr.name, &sr.sku, &sr.price, &sr.active, &sr.image,
			&sr.defaultPrice, &sr.overridden, &sr.ov1, &sr.ov2, &sr.ov3,
			&sr.menuProductSkuID, &sr.menuActive,
			&sr.disabledReason, &sr.disabledAt, &sr.disabledByName); err != nil {
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
			"product_sku_id": sr.id,
			"product_id":     productID,
			"name":           nilIfEmpty(sr.name),
			"sku":            nilIfEmpty(sr.sku),
			"selling_price":  sr.price,
			// plan-056 — the SHOP's answer, from the pivot (or a not-yet-synced
			// local override), NOT the catalog flag. `ps.is_active` is HQ
			// saying the SKU exists at all; this is the shop saying it has any
			// left. Both were `sr.active == 1` before the pivot existed, which
			// is precisely why a variant turned off in one menu stayed
			// orderable in another.
			"is_active":           sr.menuActive == 1,
			"image_url":           nilIfEmpty(sr.image),
			"is_price_overridden": sr.overridden == 1,
			// The write address for a variant toggle. Null for a catalog SKU
			// that has no pivot row yet (HQ added it after the branch cloned
			// the menu) — such a row is sellable but not yet togglable, and the
			// management screen disables its switch rather than guessing an id.
			"menu_product_sku_id": nullableStringValue(sr.menuProductSkuID),
			"disabled_reason":     nullableStringValue(sr.disabledReason),
			"disabled_at":         nullableStringValue(sr.disabledAt),
			"disabled_by_name":    nullableStringValue(sr.disabledByName),
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
		// plan-056 — ONE field that answers "what tells this variant apart from
		// its siblings", resolved server-side so LAN and Cloud say the same
		// thing. Cloud's MenuAvailabilityController::variantLabel builds it the
		// same way.
		//
		// It has to be the OPTION VALUES ("Lớn · Cay"), not `name`: a shop's
		// simple products usually have one SKU with no name of its own, and a
		// multi-variant product is normally distinguished by its option axes
		// rather than per-SKU names. A client reading `name` alone renders a
		// column of identical placeholders — which is exactly what it did.
		row["variant_label"] = variantLabelFrom(
			row["option_value1"], row["option_value2"], row["option_value3"], sr.name,
		)
		// The option AXES this variant sits on, flattened. Cloud's
		// MenuAvailabilityController::optionShapes emits the identical five
		// keys, and the availability screen groups the dish's variants on
		// `value_id` to offer one switch per option value ("turn off Lớn").
		//
		// Flattened rather than left to the client to dig out of
		// `option_value1/2/3`: those three keys exist for the ORDERING contract
		// and a client walking them has to know the numbering is positional and
		// sparse. This says the same thing in the shape the grouping needs, and
		// keeps the two servers pinned to one payload.
		row["options"] = optionShapesFrom(row["option_value1"], row["option_value2"], row["option_value3"])
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

// optionShapesFrom flattens the three positional option-value objects into the
// list the availability screen groups on.
//
// Byte-for-byte the same five keys Cloud emits
// (`MenuAvailabilityController::optionShapes`): option_id · option_name ·
// value_id · value_label · position. Both ids travel because the grouping keys
// on `value_id` — two option groups can carry values spelled the same ("Không"
// under Cay and under Hành), and grouping on the label would merge them into
// one switch that turns off variants nobody selected.
//
// Skips a slot with no value: a product on one axis has option_value2/3 nil,
// and emitting a placeholder there would invent an axis the dish does not have.
func optionShapesFrom(ovs ...any) []map[string]any {
	out := []map[string]any{}
	for _, ov := range ovs {
		m, ok := ov.(map[string]any)
		if !ok {
			continue
		}
		valueID, _ := m["id"].(string)
		if valueID == "" {
			continue
		}

		var optionID any
		var optionName any
		var position any = 0
		if opt, ok := m["option"].(map[string]any); ok {
			optionID = opt["id"]
			// `name` first, `key` as the fallback — an option group created
			// through the API can carry only the key ("size"), and a switch
			// labelled with an empty string is a switch nobody presses.
			if n, ok := opt["name"].(string); ok && strings.TrimSpace(n) != "" {
				optionName = n
			} else {
				optionName = opt["key"]
			}
			if p, ok := opt["position"]; ok {
				position = p
			}
		}

		out = append(out, map[string]any{
			"option_id":   optionID,
			"option_name": optionName,
			"value_id":    valueID,
			"value_label": m["label"],
			"position":    position,
		})
	}

	return out
}

// variantLabelFrom joins the option values that identify one variant, e.g.
// "Lớn · Cay", falling back to the SKU's own name.
//
// Returns nil when there is nothing to say — which is the NORMAL case for a
// simple product carrying a single unnamed SKU. Callers must treat nil as "this
// dish has no meaningful variant axis" and render accordingly, NOT substitute a
// placeholder: a column of "(variant)" rows is noise that hides the two or
// three products where the axis actually matters.
func variantLabelFrom(ov1, ov2, ov3 any, skuName string) any {
	parts := []string{}
	for _, ov := range []any{ov1, ov2, ov3} {
		m, ok := ov.(map[string]any)
		if !ok {
			continue
		}
		label, _ := m["label"].(string)
		if strings.TrimSpace(label) != "" {
			parts = append(parts, label)
		}
	}
	if len(parts) > 0 {
		return strings.Join(parts, " · ")
	}

	return nilIfEmpty(strings.TrimSpace(skuName))
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
func (s *Server) loadProductToppingGroups(menuProductID, productID, locale string, includeHidden bool) ([]map[string]any, error) {
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
		items, err := s.loadToppingGroupItems(menuProductID, productID, g.id, locale, includeHidden)
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
func (s *Server) loadToppingGroupItems(menuProductID, productID, groupID, locale string, includeHidden bool) ([]map[string]any, error) {
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

		skus, hidden, err := s.resolveToppingItemSkus(menuProductID, productID, ir.itemID, locale, includeHidden)
		if err != nil {
			return nil, err
		}
		if hidden && !includeHidden {
			continue
		}

		out = append(out, map[string]any{
			"id":               ir.itemID,
			"topping_group_id": groupID,
			"product_id":       ir.itemProductID,
			"name":             itemName,
			"image_url":        nilIfEmpty(itemImage),
			"is_default":       ir.isDefault == 1,
			// The REAL flag, not a hard-coded false. The ordering read never
			// emits a hidden row at all, so this only ever differs on the
			// management read — which is exactly the screen that has to render
			// the off state.
			"is_hidden": hidden,
			// …and the SAME fact spelled the way every other level of the
			// availability screen spells it.
			//
			// This is not decoration. Cloud's management endpoint emits
			// `is_active` for topping items; this one emitted only `is_hidden`,
			// so a pos-web reading `item.is_active` got `undefined` from the
			// LAN — falsy — and rendered EVERY topping as switched off, on the
			// one transport most shops actually run. Neither side's tests
			// caught it: the Go test asserted the Go shape, the vitest fixture
			// was written from the Cloud shape, and the route-parity test only
			// compares URLs. `TestTopping_ManagementReadSpeaksTheClientVocabulary`
			// is the guard.
			//
			// ADDITIVE — `is_hidden` stays, because the ORDERING payload is a
			// contract with the sales screen and dropping a field there would
			// be a second bug fixing the first.
			"is_active":  !hidden,
			"sort_order": ir.sortOrder,
			"skus":       skus,
		})
	}
	return out, nil
}

// resolveToppingItemSkus walks every base topping_group_item_skus row
// for one item, applying tier-1 (menu_product) → tier-2 (product) →
// tier-3 (base) precedence on both is_hidden and extra_price. Returns
// (skus, hiddenItem) — when the item is fully hidden across every
// tier-1/2 override the caller drops the row entirely.
func (s *Server) resolveToppingItemSkus(menuProductID, productID, itemID, locale string, includeHidden bool) ([]map[string]any, bool, error) {
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

	// plan-056 — the ITEM-level hide, decided before the per-SKU walk.
	//
	// The loop below keys tier-1 strictly by `product_sku_id`, so a WILDCARD
	// row (product_sku_id NULL → key "") only ever matched the one base row
	// that also carries no SKU binding. On any topping with SKU-bound rows the
	// wildcard was silently ignored — while Cloud, which reads the override at
	// the item level, hid it. A shop hiding a topping in admin-web therefore
	// saw it vanish online and stay on the LAN, on the same data.
	//
	// The rule is now the same on both sides and does not depend on row order:
	// ANY shop override row that says hidden hides the topping. It resolves
	// toward HIDING because serving something the shop marked gone is the worse
	// mistake.
	//
	// PRICE resolution is deliberately untouched — it stays keyed per SKU. This
	// is a visibility fix, not a re-pricing.
	itemHidden := false
	if local, ok := s.localToppingHidden(menuProductID, itemID); ok {
		// A LAN toggle that has not reached Cloud yet outranks the replica, the
		// same way it does for dishes and variants. Without this the shop hides
		// a topping while offline and an unrelated catalog pull puts it back.
		itemHidden = local
	} else {
		for _, ov := range tier1 {
			if ov.isHidden {
				itemHidden = true

				break
			}
		}
	}
	// The ORDERING read stops here — a hidden topping is not on offer, so there
	// is nothing to price. The MANAGEMENT read carries on and returns the rows,
	// because a topping the shop cannot see is one it can never switch back on.
	if itemHidden && !includeHidden {
		return []map[string]any{}, true, nil
	}

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
		// #1203 — a tier-1 row only outranks tier-2 when it actually SAYS
		// something: a price, or a hide. A row that carries neither used to
		// suppress tier-2 purely by existing, which is the opposite of what
		// Cloud does (its tier-1 query filters on override_price NOT NULL, so
		// an empty row falls through). Same basket, two prices depending on
		// whether the order went through Cloud or the LAN — and an offline
		// order priced here and re-priced there is rejected as tampered.
		// The API now refuses to store an empty row at all; this keeps the
		// reading side correct for any that predate the guard.
		price := br.extra
		hidden := false
		ov, ok := tier1[productSkuID]
		if !ok || (ov.overridePrice == nil && !ov.isHidden) {
			ov, ok = tier2[productSkuID]
		}
		if ok {
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
		// Empty base item — no skus at all, nothing to hide, UNLESS the shop
		// hid it outright.
		return out, itemHidden, nil
	}

	// `itemHidden` first: on the MANAGEMENT read the walk above did not stop, so
	// `allHidden` reflects only per-SKU hides and would report a
	// wildcard-hidden topping as visible — which is precisely the state the
	// screen has to render as "off" for the shop to switch it back on.
	return out, itemHidden || allHidden, nil
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
// toppingOverrideKey addresses one (dish, topping) pair in the LOCAL override
// table.
//
// A composite string rather than a row id because the Cloud row may not exist
// yet: hiding a topping for the first time CREATES the override, so there is
// nothing to key on until the write reaches Cloud. Both halves are UUIDs, so
// the colon can never be ambiguous.
func toppingOverrideKey(menuProductID, itemID string) string {
	return menuProductID + ":" + itemID
}

// localToppingHidden reports a not-yet-synced LAN decision for one topping.
//
// `ok == false` means the shop has made no local decision and the replica is
// the answer.
func (s *Server) localToppingHidden(menuProductID, itemID string) (bool, bool) {
	if menuProductID == "" || itemID == "" {
		return false, false
	}
	var isActive int
	err := s.db.QueryRow(`
		SELECT is_active FROM pos_menu_availability_overrides
		WHERE entity_type = 'topping_item' AND entity_id = ?`,
		toppingOverrideKey(menuProductID, itemID)).Scan(&isActive)
	if err != nil {
		return false, false
	}

	// The override table stores AVAILABILITY (is_active) for all three entity
	// kinds so one table, one sync path and one reconciler cover them. Topping
	// rows carry the INVERSE of `is_hidden` — converted here, at the single
	// point where the two vocabularies meet, rather than leaving both spellings
	// loose in the codebase.
	return isActive == 0, true
}

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

// scheduleCoversDate answers whether a schedule row is on, on `today`
// (`YYYY-MM-DD` in the SHOP's calendar), for the recurrence kinds added by
// #1979. It mirrors Cloud's `MenuScheduleDateRule`; if the two ever disagree the
// LAN till and the online till sell different menus, which is the whole class of
// bug #1970 was opened for.
//
// An unknown or empty kind is treated as Weekly: that is what every row meant
// before the column existed, and a mirror that has not re-synced yet must keep
// behaving, not go blank.
func scheduleCoversDate(kind string, daysOfMonth int64, specificDates string, today string) bool {
	switch enums.MenuScheduleRecurrence(kind) {
	case enums.MenuScheduleRecurrenceMonthly:
		// today is YYYY-MM-DD; the day is the last two characters.
		if len(today) != 10 {
			return false
		}
		day, err := strconv.Atoi(today[8:])
		if err != nil || day < 1 || day > 31 {
			return false
		}
		// bit0 = the 1st. The 31st simply never matches in a 30-day month —
		// no silent slide onto the last day, same ruling as Cloud.
		return daysOfMonth&(1<<uint(day-1)) != 0
	case enums.MenuScheduleRecurrenceSpecificdates:
		for _, d := range strings.Split(specificDates, ",") {
			if strings.TrimSpace(d) == today {
				return true
			}
		}
		return false
	default:
		// Weekly — the day_of_week filter in the SQL already decided.
		return true
	}
}
