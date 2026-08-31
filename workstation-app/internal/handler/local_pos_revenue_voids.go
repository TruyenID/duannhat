package handler

import (
	"database/sql"
	"log/slog"
	"math"
	"net/http"
	"strings"
	"time"
)

// Local POS void report — cancellation analytics for the reports screen. Shares
// the /revenue/summary window contract (granularity + from/to). Two DISTINCT,
// non-overlapping lenses so nothing is double-counted:
//
//   - ORDER voids  : whole-order cancellations (orders.status='voided'). Their
//     total_amount is zeroed on void, so the "value" is the cancelled order's
//     item subtotals. Reasons from orders.void_reason.
//   - ITEM voids   : per-item removals on orders that were NOT wholly voided
//     (order_items.status='voided' AND the parent order status != 'voided').
//     value = order_items.subtotal. Reasons from order_items.void_reason.
//
// Timing uses COALESCE(voided_at, created_at) — voided_at is not always stamped
// on older rows, so this keeps every void inside its window.

type voidKPIs struct {
	OrderVoids       int64   `json:"order_voids"`
	OrderVoidValue   int64   `json:"order_void_value"`
	ItemVoids        int64   `json:"item_voids"`
	ItemVoidValue    int64   `json:"item_void_value"`
	OrderVoidRatePct float64 `json:"order_void_rate_pct"`
}

type voidSeriesPoint struct {
	Period     string `json:"period"`
	OrderVoids int64  `json:"order_voids"`
	ItemVoids  int64  `json:"item_voids"`
	VoidValue  int64  `json:"void_value"`
}

type voidReasonPoint struct {
	Reason string `json:"reason"`
	Count  int64  `json:"count"`
	Value  int64  `json:"value"`
}

type voidTopItem struct {
	Name    string `json:"name"`
	Variant string `json:"variant"`
	Count   int64  `json:"count"`
	Value   int64  `json:"value"`
}

type voidsSummary struct {
	Granularity  string            `json:"granularity"`
	From         string            `json:"from"`
	To           string            `json:"to"`
	KPIs         voidKPIs          `json:"kpis"`
	Series       []voidSeriesPoint `json:"series"`
	OrderReasons []voidReasonPoint `json:"order_reasons"`
	ItemReasons  []voidReasonPoint `json:"item_reasons"`
	TopItems     []voidTopItem     `json:"top_items"`
	GeneratedAt  string            `json:"generated_at"`
	Source       string            `json:"source"`
}

// GET /api/v1/pos/revenue/voids
func (s *Server) handleLocalPosRevenueVoids(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	granularity := strings.ToLower(strings.TrimSpace(q.Get("granularity")))
	switch granularity {
	case revenueGranularityYear, revenueGranularityMonth, revenueGranularityDay:
		// keep
	default:
		granularity = revenueGranularityDay
	}

	from, to, err := parseRevenueWindow(granularity, q.Get("from"), q.Get("to"))
	if err != nil {
		writeError(w, http.StatusUnprocessableEntity, err.Error())
		return
	}

	kpis, err := s.voidKPIs(from, to)
	if err != nil {
		slog.Error("pos void kpis failed", "err", err)
		writeError(w, http.StatusInternalServerError, "failed to load void kpis")
		return
	}
	series, err := s.voidSeries(granularity, from, to)
	if err != nil {
		slog.Error("pos void series failed", "err", err)
		writeError(w, http.StatusInternalServerError, "failed to load void series")
		return
	}
	orderReasons, err := s.voidReasons(from, to, false)
	if err != nil {
		slog.Error("pos void order reasons failed", "err", err)
		writeError(w, http.StatusInternalServerError, "failed to load void reasons")
		return
	}
	itemReasons, err := s.voidReasons(from, to, true)
	if err != nil {
		slog.Error("pos void item reasons failed", "err", err)
		writeError(w, http.StatusInternalServerError, "failed to load void reasons")
		return
	}
	topItems, err := s.voidTopItems(from, to, localeFromRequest(r))
	if err != nil {
		slog.Error("pos void top items failed", "err", err)
		writeError(w, http.StatusInternalServerError, "failed to load top voided items")
		return
	}

	resp := voidsSummary{
		Granularity:  granularity,
		From:         from.Format("2006-01-02"),
		To:           to.Format("2006-01-02"),
		KPIs:         kpis,
		Series:       series,
		OrderReasons: orderReasons,
		ItemReasons:  itemReasons,
		TopItems:     topItems,
		GeneratedAt:  time.Now().UTC().Format(time.RFC3339),
		Source:       "workstation",
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": resp})
}

// voidWindowArgs builds the [from,to] BETWEEN args + a branch filter (aliased
// `o`) when the workstation is paired — mirroring the revenue handlers' scoping.
func (s *Server) voidWindowArgs(from, to time.Time) (branchClause string, args []any) {
	args = []any{from.UTC().Format(time.RFC3339), to.UTC().Format(time.RFC3339)}
	if b := s.workstationBranchID(); b != "" {
		branchClause = " AND o.branch_id = ?"
		args = append(args, b)
	}
	return branchClause, args
}

func (s *Server) voidKPIs(from, to time.Time) (voidKPIs, error) {
	var k voidKPIs

	// Whole-order voids: count.
	bc, args := s.voidWindowArgs(from, to)
	if err := s.db.QueryRow(`
		SELECT COUNT(*) FROM orders o
		WHERE o.status = 'voided'
		  AND COALESCE(o.voided_at, o.created_at) BETWEEN ? AND ?`+bc, args...).Scan(&k.OrderVoids); err != nil {
		return voidKPIs{}, err
	}

	// Closed orders in the same window (created_at) → the rate denominator.
	bc2, args2 := s.voidWindowArgs(from, to)
	var closed int64
	if err := s.db.QueryRow(`
		SELECT COUNT(*) FROM orders o
		WHERE o.status = 'closed'
		  AND o.created_at BETWEEN ? AND ?`+bc2, args2...).Scan(&closed); err != nil {
		return voidKPIs{}, err
	}
	if k.OrderVoids+closed > 0 {
		k.OrderVoidRatePct = math.Round(float64(k.OrderVoids)/float64(k.OrderVoids+closed)*1000) / 10
	}

	// Whole-order void value = the cancelled orders' item subtotals.
	bc3, args3 := s.voidWindowArgs(from, to)
	if err := s.db.QueryRow(`
		SELECT COALESCE(SUM(oi.subtotal), 0)
		FROM orders o JOIN order_items oi ON oi.customer_order_id = o.id
		WHERE o.status = 'voided'
		  AND COALESCE(o.voided_at, o.created_at) BETWEEN ? AND ?`+bc3, args3...).Scan(&k.OrderVoidValue); err != nil {
		return voidKPIs{}, err
	}

	// Per-item voids (parent order NOT wholly voided): count + value.
	bc4, args4 := s.voidWindowArgs(from, to)
	if err := s.db.QueryRow(`
		SELECT COUNT(*), COALESCE(SUM(oi.subtotal), 0)
		FROM order_items oi JOIN orders o ON o.id = oi.customer_order_id
		WHERE oi.status = 'voided' AND o.status != 'voided'
		  AND COALESCE(oi.voided_at, oi.created_at) BETWEEN ? AND ?`+bc4, args4...).Scan(&k.ItemVoids, &k.ItemVoidValue); err != nil {
		return voidKPIs{}, err
	}
	return k, nil
}

func (s *Server) voidSeries(granularity string, from, to time.Time) ([]voidSeriesPoint, error) {
	periodFmt := func(col string) string {
		switch granularity {
		case revenueGranularityMonth:
			return "strftime('%Y-%m', " + col + ")"
		case revenueGranularityYear:
			return "strftime('%Y', " + col + ")"
		default:
			return "strftime('%Y-%m-%d', " + col + ")"
		}
	}

	bucket := map[string]*voidSeriesPoint{}
	get := func(p string) *voidSeriesPoint {
		if b, ok := bucket[p]; ok {
			return b
		}
		b := &voidSeriesPoint{Period: p}
		bucket[p] = b
		return b
	}

	// Order voids per period (bucketed by the order's void time) + their value.
	bc, args := s.voidWindowArgs(from, to)
	rows, err := s.db.Query(`
		SELECT `+periodFmt("COALESCE(o.voided_at, o.created_at)")+` AS period,
		       COUNT(DISTINCT o.id) AS c,
		       COALESCE(SUM(oi.subtotal), 0) AS v
		FROM orders o LEFT JOIN order_items oi ON oi.customer_order_id = o.id
		WHERE o.status = 'voided'
		  AND COALESCE(o.voided_at, o.created_at) BETWEEN ? AND ?`+bc+`
		GROUP BY period`, args...)
	if err != nil {
		return nil, err
	}
	for rows.Next() {
		var p string
		var c, v int64
		if err := rows.Scan(&p, &c, &v); err != nil {
			rows.Close()
			return nil, err
		}
		b := get(p)
		b.OrderVoids = c
		b.VoidValue += v
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, err
	}

	// Per-item voids per period (bucketed by the item's void time) + their value.
	bc2, args2 := s.voidWindowArgs(from, to)
	rows2, err := s.db.Query(`
		SELECT `+periodFmt("COALESCE(oi.voided_at, oi.created_at)")+` AS period,
		       COUNT(*) AS c,
		       COALESCE(SUM(oi.subtotal), 0) AS v
		FROM order_items oi JOIN orders o ON o.id = oi.customer_order_id
		WHERE oi.status = 'voided' AND o.status != 'voided'
		  AND COALESCE(oi.voided_at, oi.created_at) BETWEEN ? AND ?`+bc2+`
		GROUP BY period`, args2...)
	if err != nil {
		return nil, err
	}
	for rows2.Next() {
		var p string
		var c, v int64
		if err := rows2.Scan(&p, &c, &v); err != nil {
			rows2.Close()
			return nil, err
		}
		b := get(p)
		b.ItemVoids = c
		b.VoidValue += v
	}
	rows2.Close()
	if err := rows2.Err(); err != nil {
		return nil, err
	}

	// Backfill empty buckets so the chart spans the full window (same loop as
	// revenueSeries).
	out := []voidSeriesPoint{}
	appendPeriod := func(key string) {
		if b, ok := bucket[key]; ok {
			out = append(out, *b)
		} else {
			out = append(out, voidSeriesPoint{Period: key})
		}
	}
	switch granularity {
	case revenueGranularityYear:
		cur := time.Date(from.Year(), 1, 1, 0, 0, 0, 0, from.Location())
		end := time.Date(to.Year(), 1, 1, 0, 0, 0, 0, to.Location())
		for !cur.After(end) {
			appendPeriod(cur.Format("2006"))
			cur = cur.AddDate(1, 0, 0)
		}
	case revenueGranularityMonth:
		cur := time.Date(from.Year(), from.Month(), 1, 0, 0, 0, 0, from.Location())
		end := time.Date(to.Year(), to.Month(), 1, 0, 0, 0, 0, to.Location())
		for !cur.After(end) {
			appendPeriod(cur.Format("2006-01"))
			cur = cur.AddDate(0, 1, 0)
		}
	default:
		cur := time.Date(from.Year(), from.Month(), from.Day(), 0, 0, 0, 0, from.Location())
		end := time.Date(to.Year(), to.Month(), to.Day(), 0, 0, 0, 0, to.Location())
		for !cur.After(end) {
			appendPeriod(cur.Format("2006-01-02"))
			cur = cur.AddDate(0, 0, 1)
		}
	}
	return out, nil
}

// voidReasons groups voids by reason. item=false → whole-order reasons (value =
// the order's item subtotals); item=true → per-item void reasons.
func (s *Server) voidReasons(from, to time.Time, item bool) ([]voidReasonPoint, error) {
	bc, args := s.voidWindowArgs(from, to)
	var query string
	if item {
		query = `
			SELECT COALESCE(NULLIF(TRIM(oi.void_reason), ''), '') AS reason,
			       COUNT(*) AS c, COALESCE(SUM(oi.subtotal), 0) AS v
			FROM order_items oi JOIN orders o ON o.id = oi.customer_order_id
			WHERE oi.status = 'voided' AND o.status != 'voided'
			  AND COALESCE(oi.voided_at, oi.created_at) BETWEEN ? AND ?` + bc + `
			GROUP BY reason ORDER BY c DESC, v DESC`
	} else {
		query = `
			SELECT COALESCE(NULLIF(TRIM(o.void_reason), ''), '') AS reason,
			       COUNT(DISTINCT o.id) AS c, COALESCE(SUM(oi.subtotal), 0) AS v
			FROM orders o LEFT JOIN order_items oi ON oi.customer_order_id = o.id
			WHERE o.status = 'voided'
			  AND COALESCE(o.voided_at, o.created_at) BETWEEN ? AND ?` + bc + `
			GROUP BY reason ORDER BY c DESC, v DESC`
	}
	rows, err := s.db.Query(query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []voidReasonPoint{}
	for rows.Next() {
		var p voidReasonPoint
		if err := rows.Scan(&p.Reason, &p.Count, &p.Value); err != nil {
			return nil, err
		}
		out = append(out, p)
	}
	return out, rows.Err()
}

// voidTopItems ranks the most-voided menu items (per-item voids only), name
// resolved from the pos catalog with the item-name snapshot fallback — same
// resolution the revenue-by-product report uses.
func (s *Server) voidTopItems(from, to time.Time, locale string) ([]voidTopItem, error) {
	bc, args := s.voidWindowArgs(from, to)
	// Localized catalog name/variant FIRST (operator's pos-web language), then
	// the add-time snapshot when the SKU was removed from the catalog.
	nameExpr := localizedNameExpr("pp", "name", locale)
	variantExpr := localizedNameExpr("ps", "name", locale)
	rows, err := s.db.Query(`
		SELECT MAX(COALESCE(NULLIF(TRIM(`+nameExpr+`), ''), NULLIF(TRIM(oi.menu_item_name), ''), oi.product_sku_id)) AS name,
		       MAX(COALESCE(NULLIF(TRIM(`+variantExpr+`), ''), NULLIF(TRIM(oi.sku_variant_name), ''), '')) AS variant,
		       COUNT(*) AS c, COALESCE(SUM(oi.subtotal), 0) AS v
		FROM order_items oi JOIN orders o ON o.id = oi.customer_order_id
		LEFT JOIN pos_product_skus ps ON ps.id = oi.product_sku_id
		LEFT JOIN pos_products pp ON pp.id = ps.product_id
		WHERE oi.status = 'voided' AND o.status != 'voided'
		  AND COALESCE(oi.voided_at, oi.created_at) BETWEEN ? AND ?`+bc+`
		GROUP BY oi.product_sku_id
		ORDER BY c DESC, v DESC
		LIMIT 10`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []voidTopItem{}
	for rows.Next() {
		var it voidTopItem
		var name, variant sql.NullString
		if err := rows.Scan(&name, &variant, &it.Count, &it.Value); err != nil {
			return nil, err
		}
		it.Name = strings.TrimSpace(name.String)
		it.Variant = strings.TrimSpace(variant.String)
		out = append(out, it)
	}
	return out, rows.Err()
}

// ─── Void event log ──────────────────────────────────────────────────────────
// A flat, paginated list of individual cancellations (which order, when, why,
// how much) — the drill-down behind the aggregate void report. A UNION of the
// same two non-overlapping lenses: one row per wholly-voided order (value = its
// item subtotals, item_count = how many lines it held) and one row per per-item
// void on a NON-voided order. Newest first.

type voidEvent struct {
	Kind      string `json:"kind"` // "order" | "item"
	OrderID   string `json:"order_id"`
	OrderCode string `json:"order_code"`
	VoidedAt  string `json:"voided_at"`
	Reason    string `json:"reason"`
	ItemName  string `json:"item_name"` // "" for whole-order rows
	Variant   string `json:"variant"`   // "" for whole-order rows
	Quantity  int64  `json:"quantity"`  // item qty; 0 for whole-order rows
	ItemCount int64  `json:"item_count"`
	Value     int64  `json:"value"`
}

type voidEventsResult struct {
	From        string      `json:"from"`
	To          string      `json:"to"`
	Type        string      `json:"type"`
	Total       int64       `json:"total"`
	Page        int         `json:"page"`
	PerPage     int         `json:"per_page"`
	Rows        []voidEvent `json:"rows"`
	GeneratedAt string      `json:"generated_at"`
	Source      string      `json:"source"`
}

// GET /api/v1/pos/revenue/void-events
func (s *Server) handleLocalPosRevenueVoidEvents(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	granularity := strings.ToLower(strings.TrimSpace(q.Get("granularity")))
	switch granularity {
	case revenueGranularityYear, revenueGranularityMonth, revenueGranularityDay:
		// keep
	default:
		granularity = revenueGranularityDay
	}

	from, to, err := parseRevenueWindow(granularity, q.Get("from"), q.Get("to"))
	if err != nil {
		writeError(w, http.StatusUnprocessableEntity, err.Error())
		return
	}

	typ := strings.ToLower(strings.TrimSpace(q.Get("type")))
	switch typ {
	case "order", "item", "all":
		// keep
	default:
		typ = "all"
	}

	page := atoiDefault(q.Get("page"), 1)
	if page < 1 {
		page = 1
	}
	perPage := atoiDefault(q.Get("per_page"), 20)
	if perPage < 1 {
		perPage = 20
	}
	if perPage > 100 {
		perPage = 100
	}

	locale := localeFromRequest(r)
	total, err := s.voidEventsCount(from, to, typ, locale)
	if err != nil {
		slog.Error("pos void events count failed", "err", err)
		writeError(w, http.StatusInternalServerError, "failed to count void events")
		return
	}
	rows, err := s.voidEvents(from, to, typ, perPage, (page-1)*perPage, locale)
	if err != nil {
		slog.Error("pos void events failed", "err", err)
		writeError(w, http.StatusInternalServerError, "failed to load void events")
		return
	}

	resp := voidEventsResult{
		From:        from.Format("2006-01-02"),
		To:          to.Format("2006-01-02"),
		Type:        typ,
		Total:       total,
		Page:        page,
		PerPage:     perPage,
		Rows:        rows,
		GeneratedAt: time.Now().UTC().Format(time.RFC3339),
		Source:      "workstation",
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": resp})
}

// voidEventsInner builds the (un-paginated) UNION select for the given lens.
// Column order is identical in both halves so the compound select lines up.
func (s *Server) voidEventsInner(from, to time.Time, typ, locale string) (string, []any) {
	fromS := from.UTC().Format(time.RFC3339)
	toS := to.UTC().Format(time.RFC3339)
	branch := s.workstationBranchID()
	// Localized catalog name/variant first (operator language), snapshot next.
	nameExpr := localizedNameExpr("pp", "name", locale)
	variantExpr := localizedNameExpr("ps", "name", locale)

	orderArgs := []any{fromS, toS}
	itemArgs := []any{fromS, toS}
	orderBranch, itemBranch := "", ""
	if branch != "" {
		orderBranch = " AND o.branch_id = ?"
		itemBranch = " AND o.branch_id = ?"
		orderArgs = append(orderArgs, branch)
		itemArgs = append(itemArgs, branch)
	}

	orderSQL := `
		SELECT 'order' AS kind, o.id AS order_id, o.order_code AS order_code,
		       COALESCE(o.voided_at, o.created_at) AS voided_at,
		       COALESCE(NULLIF(TRIM(o.void_reason), ''), '') AS reason,
		       '' AS item_name, '' AS variant, 0 AS quantity,
		       COUNT(oi.id) AS item_count, COALESCE(SUM(oi.subtotal), 0) AS value
		FROM orders o LEFT JOIN order_items oi ON oi.customer_order_id = o.id
		WHERE o.status = 'voided'
		  AND COALESCE(o.voided_at, o.created_at) BETWEEN ? AND ?` + orderBranch + `
		GROUP BY o.id`

	itemSQL := `
		SELECT 'item' AS kind, o.id AS order_id, o.order_code AS order_code,
		       COALESCE(oi.voided_at, oi.created_at) AS voided_at,
		       COALESCE(NULLIF(TRIM(oi.void_reason), ''), '') AS reason,
		       COALESCE(NULLIF(TRIM(` + nameExpr + `), ''), NULLIF(TRIM(oi.menu_item_name), ''), oi.product_sku_id) AS item_name,
		       COALESCE(NULLIF(TRIM(` + variantExpr + `), ''), NULLIF(TRIM(oi.sku_variant_name), ''), '') AS variant,
		       oi.quantity AS quantity, 1 AS item_count, oi.subtotal AS value
		FROM order_items oi JOIN orders o ON o.id = oi.customer_order_id
		LEFT JOIN pos_product_skus ps ON ps.id = oi.product_sku_id
		LEFT JOIN pos_products pp ON pp.id = ps.product_id
		WHERE oi.status = 'voided' AND o.status != 'voided'
		  AND COALESCE(oi.voided_at, oi.created_at) BETWEEN ? AND ?` + itemBranch

	switch typ {
	case "order":
		return orderSQL, orderArgs
	case "item":
		return itemSQL, itemArgs
	default:
		return orderSQL + " UNION ALL " + itemSQL, append(orderArgs, itemArgs...)
	}
}

func (s *Server) voidEventsCount(from, to time.Time, typ, locale string) (int64, error) {
	inner, args := s.voidEventsInner(from, to, typ, locale)
	var n int64
	err := s.db.QueryRow(`SELECT COUNT(*) FROM (`+inner+`) v`, args...).Scan(&n)
	return n, err
}

func (s *Server) voidEvents(from, to time.Time, typ string, limit, offset int, locale string) ([]voidEvent, error) {
	inner, args := s.voidEventsInner(from, to, typ, locale)
	// Newest first; order_code as a stable tiebreak within the same instant.
	query := `SELECT kind, order_id, order_code, voided_at, reason, item_name, variant, quantity, item_count, value
		FROM (` + inner + `) v
		ORDER BY v.voided_at DESC, v.order_code DESC
		LIMIT ? OFFSET ?`
	args = append(args, limit, offset)

	rows, err := s.db.Query(query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	out := []voidEvent{}
	for rows.Next() {
		var e voidEvent
		var code, voidedAt, reason, name, variant sql.NullString
		if err := rows.Scan(&e.Kind, &e.OrderID, &code, &voidedAt, &reason, &name, &variant, &e.Quantity, &e.ItemCount, &e.Value); err != nil {
			return nil, err
		}
		e.OrderCode = strings.TrimSpace(code.String)
		e.VoidedAt = voidedAt.String
		e.Reason = reason.String
		e.ItemName = strings.TrimSpace(name.String)
		e.Variant = strings.TrimSpace(variant.String)
		out = append(out, e)
	}
	return out, rows.Err()
}
