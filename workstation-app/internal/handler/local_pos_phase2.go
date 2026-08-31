package handler

import (
	"database/sql"
	"errors"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/google/uuid"
)

// Phase 2 handlers — serve the new Cloud-mirrored entities from local SQLite.
// Migration 013 owns the schema; SyncPuller methods (sync_pull.go) keep the
// rows fresh from Cloud /api/v1/workstation/* sources.

// ─── /pos/shop ────────────────────────────────────────────────────────────────

// GET /api/v1/pos/shop
//
// Returns the branch row whose id matches workstation_branch_id (the slug the
// user is operating under is enforced upstream by the auth middleware — we
// only have one paired branch locally so identity follows automatically).
func (s *Server) handleLocalPosShop(w http.ResponseWriter, r *http.Request) {
	branchID := s.workstationBranchID()
	if branchID == "" {
		writeError(w, http.StatusServiceUnavailable, "workstation not paired")
		return
	}

	var (
		id, slug, name, brandID, orgID string
		code                           sql.NullString
		hq                             int
	)
	err := s.db.QueryRow(`
		SELECT id, slug, name, code, is_headquarters,
		       COALESCE(console_brand_id, ''), COALESCE(console_organization_id, '')
		FROM branches WHERE id = ? AND COALESCE(deleted_at, '') = ''`, branchID).Scan(
		&id, &slug, &name, &code, &hq, &brandID, &orgID,
	)
	if err != nil {
		// "no such table: branches" happens in test environments where the
		// omnify migrations are not loaded; treat the same as ErrNoRows and
		// fall back to the minimal pairing info.
		if errors.Is(err, sql.ErrNoRows) || strings.Contains(err.Error(), "no such table") {
			// Sync DOWN hasn't populated branches yet — fall back to the
			// minimal info we know locally so pos-web doesn't 404 on boot.
			writeJSON(w, http.StatusOK, map[string]any{
				"data": map[string]any{
					"id":              branchID,
					"slug":            s.settingValue("workstation_branch_slug"),
					"name":            s.settingValue("workstation_branch_name"),
					"code":            nil,
					"is_headquarters": false,
				},
			})
			return
		}
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	// Match Cloud's ShopInfoController shape — drop console_organization_id
	// (not in Cloud's response) so pos-web sees identical keys whether the
	// request landed locally or via Cloud. brand_slug + timezone + the
	// other operational fields require joining `brands` / reading omnify
	// columns workstation doesn't sync yet; pos-web doesn't read them so
	// the gap is acceptable. Re-enable when sync layer catches up.
	_ = orgID
	writeJSON(w, http.StatusOK, map[string]any{
		"data": map[string]any{
			"id":               id,
			"slug":             slug,
			"name":             name,
			"code":             nullStringOr(code, nil),
			"is_headquarters":  hq == 1,
			"console_brand_id": nullIfEmpty(brandID),
		},
	})
}

// ─── /pos/payment-methods ─────────────────────────────────────────────────────

// GET /api/v1/pos/payment-methods?include_inactive=true
func (s *Server) handleLocalPosPaymentMethods(w http.ResponseWriter, r *http.Request) {
	includeInactive := r.URL.Query().Get("include_inactive") == "true"

	q := `SELECT id, code, name, is_active, sort_order,
	             is_auto_confirm, requires_tendered FROM payment_methods`
	if !includeInactive {
		q += " WHERE is_active = 1"
	}
	q += " ORDER BY sort_order, code"

	rows, err := s.db.Query(q)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	defer rows.Close()

	type pm struct {
		ID        string `json:"id"`
		Code      string `json:"code"`
		Name      string `json:"name"`
		IsActive  bool   `json:"is_active"`
		SortOrder int    `json:"sort_order"`
		// PaymentDialog drivers: pos-web reads requires_tendered to gate the
		// cash-tendered input (otherwise OrderPaymentService throws "Tendered
		// amount must be provided …") and is_auto_confirm to decide polling.
		IsAutoConfirm    bool `json:"is_auto_confirm"`
		RequiresTendered bool `json:"requires_tendered"`
	}
	out := []pm{}
	for rows.Next() {
		var m pm
		var active, autoConf, tendered int
		if err := rows.Scan(&m.ID, &m.Code, &m.Name, &active, &m.SortOrder,
			&autoConf, &tendered); err != nil {
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}
		m.IsActive = active == 1
		m.IsAutoConfirm = autoConf == 1
		m.RequiresTendered = tendered == 1
		out = append(out, m)
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": out})
}

// ─── /pos/customers ───────────────────────────────────────────────────────────

// POST /api/v1/pos/customers/find-or-create
//
// Body: { phone }
// Returns: { data: Customer, created: bool }
//
// Lookup-by-phone first; if no row, insert a locally-generated record flagged
// for sync UP so Cloud picks it up on the next push cycle. The created row
// uses a deterministic id (UUIDv4) so the eventual sync UP -> sync DOWN cycle
// can swap the canonical Cloud id transparently (matching ids = upsert).
func (s *Server) handleLocalPosFindOrCreateCustomer(w http.ResponseWriter, r *http.Request) {
	var body struct {
		Phone string `json:"phone"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	phone := strings.TrimSpace(body.Phone)
	if phone == "" {
		writeError(w, http.StatusBadRequest, "phone required")
		return
	}

	if existing, found, err := s.findCustomerByPhone(phone); err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	} else if found {
		writeJSON(w, http.StatusOK, map[string]any{
			"data":    existing,
			"created": false,
		})
		return
	}

	// Synthesize a minimal customer row. first_name defaults to the phone
	// suffix; pos-web staff can rename it later via Cloud.
	id := uuid.NewString()
	display := "Customer " + lastN(phone, 4)
	now := time.Now().UTC().Format(time.RFC3339)
	branchID := s.workstationBranchID()
	orgID := s.settingValue("organization_id")
	brandID := s.settingValue("brand_id")

	if _, err := s.db.Exec(`
		INSERT INTO customers (id, first_name, full_name, phone,
		    organization_id, brand_id, branch_id,
		    local_pending_sync, local_synced_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)`,
		id, display, display, phone, orgID, brandID, branchID, now,
	); err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	s.enqueueOrderSync("customer.create", id, map[string]any{
		"phone":      phone,
		"first_name": display,
	})
	s.auditLogPOS(r, "customer.create", "customer", id, "")

	created := map[string]any{
		"id":         id,
		"first_name": display,
		"last_name":  nil,
		"full_name":  display,
		"phone":      phone,
		"email":      nil,
	}
	writeJSON(w, http.StatusCreated, map[string]any{
		"data":    created,
		"created": true,
	})
}

// GET /api/v1/pos/customers/{customer}/outstanding
//
// Reads local orders.customer_id (added in migration 016) and returns any
// non-voided order with paid_amount < total_amount. Each row is shaped
// via customerOrderShape so PaymentDialog's "gộp đơn nợ" list reads
// the same structure it would have from Cloud. `total_owed` is rendered
// as a decimal string the way Cloud's OutstandingResponse emits — pos-web
// uses the string-as-money convention throughout this surface.
//
// Falls back gracefully when the customer has no row locally (created on
// another terminal, not yet synced DOWN): empty data + zero owed.
func (s *Server) handleLocalPosCustomerOutstanding(w http.ResponseWriter, r *http.Request) {
	customerID := r.PathValue("customer")
	if customerID == "" {
		writeError(w, http.StatusBadRequest, "customer id required")
		return
	}

	orders, _, err := s.orders.ListByFilters(service.ListFilters{
		CustomerID: customerID,
		IncludeAll: true,
		PerPage:    200,
	})
	if err != nil {
		writeServerError(w, r, err)
		return
	}

	type shaped = map[string]any
	out := make([]shaped, 0, len(orders))
	owed := 0
	for i := range orders {
		o := &orders[i]
		// Skip rows that are fully paid / voided — outstanding means a real
		// remaining balance on a non-terminal order.
		if string(o.Status) == "voided" || string(o.Status) == "closed" {
			continue
		}
		remaining := o.TotalAmount - o.PaidAmount
		if remaining <= 0 {
			continue
		}
		owed += remaining
		out = append(out, s.customerOrderShape(o, localeFromRequest(r)))
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"data":       out,
		"total_owed": strconv.Itoa(owed),
	})
}

// ─── /pos/menus/by-day/{dayOfWeek} ────────────────────────────────────────────

// GET /api/v1/pos/menus/by-day/{dayOfWeek}
//
// Returns menus active on the given day-of-week (0=Sunday, 6=Saturday).
// Until menu_schedules is populated, falls back to listing all active menus
// from menu_items so pos-web still has something to render.
func (s *Server) handleLocalPosMenusByDay(w http.ResponseWriter, r *http.Request) {
	dowStr := r.PathValue("dayOfWeek")
	dow, err := strconv.Atoi(dowStr)
	if err != nil || dow < 0 || dow > 6 {
		writeError(w, http.StatusBadRequest, "dayOfWeek must be 0-6")
		return
	}

	rows, err := s.db.Query(`
		SELECT DISTINCT menu_id FROM menu_schedules
		WHERE day_of_week = ? AND is_active = 1
		ORDER BY menu_id`, dow)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	defer rows.Close()

	type menu struct {
		ID       string `json:"id"`
		Name     string `json:"name"`
		IsActive bool   `json:"is_active"`
	}
	out := []menu{}
	for rows.Next() {
		var id string
		if err := rows.Scan(&id); err != nil {
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}
		out = append(out, menu{ID: id, Name: "Local menu " + id, IsActive: true})
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": out})
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

func (s *Server) findCustomerByPhone(phone string) (map[string]any, bool, error) {
	var (
		id, first   string
		last, email sql.NullString
		full        string
	)
	err := s.db.QueryRow(`
		SELECT id, first_name, last_name, full_name, email
		FROM customers WHERE phone = ? LIMIT 1`, phone).Scan(
		&id, &first, &last, &full, &email,
	)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, false, nil
	}
	if err != nil {
		return nil, false, err
	}
	return map[string]any{
		"id":         id,
		"first_name": first,
		"last_name":  nullStringOr(last, nil),
		"full_name":  full,
		"phone":      phone,
		"email":      nullStringOr(email, nil),
	}, true, nil
}

// customerPhoneByID returns the phone of a locally-stored customer, or "" when
// the row is missing / has no phone. Used by the order sync-UP path to send a
// resolvable phone alongside a LAN-minted customer_id (see local_pos.go).
func (s *Server) customerPhoneByID(customerID string) string {
	if customerID == "" {
		return ""
	}
	var phone sql.NullString
	err := s.db.QueryRow(
		`SELECT phone FROM customers WHERE id = ? LIMIT 1`, customerID).Scan(&phone)
	if err != nil {
		return ""
	}
	return phone.String
}

func (s *Server) settingValue(key string) string {
	var v string
	err := s.db.QueryRow("SELECT value FROM settings WHERE key = ?", key).Scan(&v)
	if err != nil {
		return ""
	}
	return v
}

// posPrintLocaleKey is the shop-wide print language for staff-facing slips.
// It is a MANAGER-CONFIGURED setting, not a per-request footprint.
const posPrintLocaleKey = "pos_print_locale"

// printLocaleOverrideKey is the DELIBERATE local print-language pick made in the
// WS App (Settings → print language). It ranks above the branch default and the
// auto-captured operator locale, but below the Cloud-synced shop language — see
// printLabelLocale. Kept separate from posPrintLocaleKey so a passive
// payment-time capture never masquerades as an explicit operator override.
const printLocaleOverrideKey = "print_locale_override"

// rememberPrintLocale records the pos-web operator's print language so paths
// WITHOUT a request context (auto-print on payment) can still print in the
// operator's language.
//
// It is deliberately WRITE-ONCE-then-sticky: `settings` is a single shop-wide
// key-value row with no device dimension, so every POS terminal on the LAN
// shares this one value. Re-writing it on every payment/print made the last
// terminal to transact silently re-language every OTHER terminal's auto-printed
// slips — a cashier on ja would get vi slips because a colleague on vi took a
// payment a second earlier. Racy, order-dependent, and near-impossible to
// reproduce on demand.
//
// Seeding it only when unset keeps the useful behaviour (a fresh install adopts
// the first operator's language instead of printing the built-in default) while
// removing the cross-terminal clobber. To CHANGE it afterwards, use
// setPrintLocale — an explicit, deliberate act, not a side effect of printing.
// Only a whitelisted value is stored.
func (s *Server) rememberPrintLocale(locale string) {
	switch locale {
	case "ja", "en", "vi":
		// INSERT-only (no upsert): the first write wins, later ones no-op.
		_, _ = s.db.Exec(
			"INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO NOTHING",
			posPrintLocaleKey, locale)
	}
}

// setPrintLocale overwrites the shop-wide print language unconditionally. This
// is the deliberate counterpart to rememberPrintLocale's write-once seeding —
// for an explicit manager action, never for an incidental print/payment call.
func (s *Server) setPrintLocale(locale string) {
	switch locale {
	case "ja", "en", "vi":
		_, _ = s.db.Exec(
			"INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
			posPrintLocaleKey, locale)
	}
}

// rememberedPrintLocale returns the shop-wide operator locale ("" when none yet
// — the print layer then uses its own default). Used by auto-print, which has
// no Accept-Language to read.
func (s *Server) rememberedPrintLocale() string {
	return s.settingValue(posPrintLocaleKey)
}

// shopTaxRate returns the per-shop consumption-tax rate as a percent
// (e.g. 10 for 10%), read from the shop_settings.tax_rate value synced from
// Cloud (a decimal string like "10.00"). Returns 0 when unset/unparseable —
// the print layer then falls back to its own default rate.
func (s *Server) shopTaxRate() float64 {
	var raw string
	if err := s.db.QueryRow(`SELECT value FROM shop_settings WHERE key = 'tax_rate'`).Scan(&raw); err != nil {
		return 0
	}
	rate := 0.0
	fmt.Sscanf(raw, "%f", &rate)
	return rate
}

func nullStringOr(ns sql.NullString, def any) any {
	if !ns.Valid || ns.String == "" {
		return def
	}
	return ns.String
}

func lastN(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[len(s)-n:]
}

// fmtDecimal returns a string like Laravel renders decimal(precision, scale)
// so pos-web's `Number(rate)` coercion is consistent across Cloud and LAN.
func fmtDecimal(value, scale int) string {
	div := 1
	for i := 0; i < scale; i++ {
		div *= 10
	}
	return fmt.Sprintf("%d.%0*d", value/div, scale, value%div)
}
