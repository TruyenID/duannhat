package handler

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/google/uuid"
)

// Phase 1 handlers — extend the local POS surface to cover the 11 endpoints
// pos-web hits that already have local SQLite schema. Each handler mirrors
// the Cloud /api/v1/pos/* contract (envelope `{data: ...}`, same error shape)
// so pos-web cannot tell whether it is talking to a workstation or Cloud.
// Writes also enqueue a sync UP event so Cloud eventually reconciles.

// ─── Menu detail + products ───────────────────────────────────────────────────

// GET /api/v1/pos/menus/{menu}
//
// Local SQLite currently stores a flat menu_items table — there is no
// separate "menus" parent entity, so this handler returns a stub object that
// satisfies the shape pos-web expects (id + name) and uses the products list
// as the canonical content. When Phase 2 lands the dedicated menus table,
// swap the JSON for a real row.
func (s *Server) handleLocalPosMenuDetail(w http.ResponseWriter, r *http.Request) {
	menuID := r.PathValue("menu")
	if menuID == "" {
		writeError(w, http.StatusBadRequest, "menu id required")
		return
	}
	// No menus table yet — return a synthetic envelope so pos-web's
	// useShopMenu(menuId) hook resolves without round-tripping to Cloud.
	writeJSON(w, http.StatusOK, map[string]any{
		"data": map[string]any{
			"id":         menuID,
			"name":       "Local menu",
			"is_active":  true,
			"updated_at": time.Now().UTC().Format(time.RFC3339),
		},
	})
}

// handleLocalPosMenusTwoSeg dispatches the two ServeMux-conflicting routes
// /menus/{menu}/products and /menus/by-day/{dow} based on the first segment.
// Go 1.22 ServeMux refuses to register both as distinct patterns because
// neither strictly subsumes the other (a literal "by-day" can match a
// wildcard {menu}); a single registration with manual dispatch resolves it.
func (s *Server) handleLocalPosMenusTwoSeg(w http.ResponseWriter, r *http.Request) {
	seg1 := r.PathValue("seg1")
	seg2 := r.PathValue("seg2")
	if seg1 == "by-day" {
		// Re-bind so handleLocalPosMenusByDay reads the expected param.
		r.SetPathValue("dayOfWeek", seg2)
		s.handleLocalPosMenusByDay(w, r)
		return
	}
	if seg2 == "products" {
		r.SetPathValue("menu", seg1)
		s.handleLocalPosMenuProducts(w, r)
		return
	}
	writeError(w, http.StatusNotFound, "unknown menus sub-route")
}

// GET /api/v1/pos/menus/{menu}/products
//
// Returns the paginated product set for a menu. With the flat local schema
// every menu_item is treated as a product of every menu — pos-web filters
// further by category. Pagination params match Cloud's per_page/page contract.
// GET /api/v1/pos/menus/{menu}/products
//
// Paginated, searchable products list for one menu. Output matches
// Cloud's ShopMenuProductResource shape (id, menu_id, product_id,
// menu_section_id, skus[], product{}, active_promotion) so pos-web's
// MenuCatalog search input parses LAN responses identically to Cloud.
// Reads pos_menu_products + pos_products + pos_product_skus (migration
// 018). Promotion overlay re-computed inline via the same SQL the
// PromotionEngine consults — staff sees the at-cart price before tapping
// Add.
func (s *Server) handleLocalPosMenuProducts(w http.ResponseWriter, r *http.Request) {
	menuID := r.PathValue("menu")
	if menuID == "" {
		writeError(w, http.StatusBadRequest, "menu id required")
		return
	}

	page := atoiDefault(r.URL.Query().Get("page"), 1)
	perPage := atoiDefault(r.URL.Query().Get("per_page"), 50)
	if perPage > 200 {
		perPage = 200
	}
	search := strings.TrimSpace(r.URL.Query().Get("search"))

	args := []any{menuID}
	where := "WHERE mp.menu_id = ?"
	if search != "" {
		// Cloud's Shop\MenuController::listProducts (line 208) searches
		// product.name + ProductSku.name (variant label) + ProductSku.sku
		// (barcode/SKU code). LAN was name-only — a cashier typing the
		// barcode found nothing. Mirror the three-way match via an EXISTS
		// against pos_product_skus so a variant or SKU hit also surfaces
		// the parent menu_product row.
		where += ` AND (
			p.name LIKE ?
			OR EXISTS (
				SELECT 1 FROM pos_product_skus sk
				WHERE sk.product_id = mp.product_id
				  AND (sk.name LIKE ? OR sk.sku LIKE ?)
			)
		)`
		pat := "%" + search + "%"
		args = append(args, pat, pat, pat)
	}

	var total int
	if err := s.db.QueryRow(`
		SELECT COUNT(DISTINCT mp.id)
		FROM pos_menu_products mp
		JOIN pos_products p ON p.id = mp.product_id
		`+where, args...).Scan(&total); err != nil {
		writeServerError(w, r, err)
		return
	}

	offset := (page - 1) * perPage
	pageArgs := append(args, perPage, offset)
	// Drain Rows BEFORE running the per-product nested loaders. Each
	// inner loader (loadProductSkus, loadProductGallery,
	// loadProductToppingGroups) opens its own Rows + nested calls;
	// holding this outer Rows open across them pinned the conn-pool
	// connection for the full lifetime of the response, and under
	// concurrent /pos/menus/{id}/products + /api/lan/health load the
	// pool of 8 conns drained → /api/lan/health blocked on
	// workstationBranchID's SELECT, surfacing as pos-web's
	// "disconnected" banner after a few seconds of normal traffic.
	type mpRow struct {
		id, menuIDout, productID, sectionID                     string
		productName, productDesc, productImage, productTypeCode string
		sectionName                                             sql.NullString
		active, displayOrder                                    int
	}
	rows, err := s.db.Query(fmt.Sprintf(`
		SELECT mp.id, mp.menu_id, mp.product_id, COALESCE(mp.menu_section_id, ''),
		       mp.is_active, mp.display_order,
		       %s, COALESCE(p.description, ''),
		       COALESCE(p.image_url, ''),
		       COALESCE(p.product_type_code, ''),
		       sec.name AS section_name
		FROM pos_menu_products mp
		JOIN pos_products p ON p.id = mp.product_id
		LEFT JOIN pos_menu_sections sec
		       ON sec.id = mp.menu_section_id AND sec.menu_id = mp.menu_id
		`, productNameExpr(localeFromRequest(r)))+where+`
		ORDER BY mp.display_order, p.name
		LIMIT ? OFFSET ?`, pageArgs...)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	mps := []mpRow{}
	for rows.Next() {
		var m mpRow
		if err := rows.Scan(&m.id, &m.menuIDout, &m.productID, &m.sectionID,
			&m.active, &m.displayOrder, &m.productName, &m.productDesc,
			&m.productImage, &m.productTypeCode, &m.sectionName); err != nil {
			rows.Close()
			writeServerError(w, r, err)
			return
		}
		mps = append(mps, m)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		writeServerError(w, r, err)
		return
	}
	rows.Close()

	now := time.Now()
	locale := localeFromRequest(r)
	items := []map[string]any{}
	for _, m := range mps {
		skus, err := s.loadProductSkus(m.productID, now, locale)
		if err != nil {
			writeServerError(w, r, err)
			return
		}
		gallery, err := s.loadProductGallery(m.productID)
		if err != nil {
			writeServerError(w, r, err)
			return
		}
		toppingGroups, err := s.loadProductToppingGroups(m.id, m.productID, locale)
		if err != nil {
			writeServerError(w, r, err)
			return
		}
		activePromo := s.activePromotionForProduct(skus, now)

		// plan-043 — resolve the product's effective 店内/持ち帰り tax rates so
		// pos-web's menu grid can render the 税込/税抜 price. This is the paginated
		// endpoint pos-web actually calls (the /menus/{menu} detail path stamps
		// the same fields in loadMenuProducts).
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
			"menu_id":          m.menuIDout,
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
		items = append(items, mp)
	}

	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	// Swap cached image URLs to the /api/lan/images/{hash} form so
	// pos-web renders thumbnails / galleries off the workstation
	// even when Cloud is unreachable.
	for _, it := range items {
		s.rewriteResponseImages(r, it)
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"data": items,
		"meta": map[string]any{
			"current_page": page,
			"last_page":    lastPage,
			"per_page":     perPage,
			"total":        total,
		},
	})
}

// ─── Shop order settings ──────────────────────────────────────────────────────

// GET /api/v1/pos/settings/order
//
// Mirrors Cloud's ShopOrderSettingsController@show response shape EXACTLY so
// pos-web's currency dropdown, status help text, decimal formatting all line
// up regardless of which side served the request. The static enumerations
// (available_statuses, available_currencies) are duplicated here verbatim
// from the controller — when Cloud grows a new currency, mirror it here too
// or the LAN path will silently miss it.
func (s *Server) handleLocalPosOrderSettings(w http.ResponseWriter, r *http.Request) {
	// Best-effort branch-settings refresh so a toggle just flipped in admin-web
	// (Cloud) shows up the moment pos-web next reads settings — pos-web refetches
	// on window-focus + mount + a 60 s interval, so switching from the Settings
	// tab back to the POS picks up the change without waiting for the slow sync
	// tick. Short timeout + offline/429/error simply falls back to the local
	// shop_settings mirror (offline-first — never blocks the read for long).
	if s.puller != nil {
		ctx, cancel := context.WithTimeout(r.Context(), 1500*time.Millisecond)
		_ = s.puller.PullBranch(ctx)
		cancel()
	}

	readOptional := func(key string) *string {
		var v string
		err := s.db.QueryRow("SELECT value FROM shop_settings WHERE key = ?", key).Scan(&v)
		if err != nil {
			return nil
		}
		return &v
	}
	read := func(key, defaultVal string) string {
		if v := readOptional(key); v != nil && *v != "" {
			return *v
		}
		return defaultVal
	}
	enableQuick := read("enable_quick_order", "false") == "true"

	// default_order_item_status is genuinely nullable on Cloud — when the
	// shop has never configured it, the field returns null and pos-web
	// falls back to its system default. Don't fabricate a value here.
	var defaultStatus any
	if v := readOptional("default_order_item_status"); v != nil && *v != "" {
		defaultStatus = *v
	}

	// plan-043 — nullable branch default tax type (null = fall through to brand).
	var defaultTaxTypeID any
	if v := readOptional("default_tax_type_id"); v != nil && *v != "" {
		defaultTaxTypeID = *v
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"data": map[string]any{
			"default_order_item_status": defaultStatus,
			"enable_quick_order":        enableQuick,
			// plan-043 T3.3 — the settings-level `tax_rate` was DROPPED on Cloud
			// (per-line tax_types snapshots + default_tax_type_id replace it), so
			// this LAN mirror must not surface it either (pos-web's
			// ShopOrderSettings interface expects it gone). Surface the four
			// consumption-tax flags Cloud now returns instead, read from the
			// shop_settings rows PullBranch flattens from GET /workstation/branch.
			"service_charge_rate": read("service_charge_rate", "0.00"),
			// Decimal columns on Cloud render with scale=2 ("0.00"); keep the
			// same string format so any client doing strict string comparison
			// (e.g. dirty-form detection) sees identical values.
			"service_charge_tax_rate":    read("service_charge_tax_rate", "0.00"),
			"prices_include_tax":         read("prices_include_tax", "false") == "true",
			"default_tax_type_id":        defaultTaxTypeID,
			"close_report_tax_breakdown": read("close_report_tax_breakdown", "true") == "true",
			// Item-edit policy — pos-web relaxes its per-item edit/void gate on
			// this. Read from shop_settings (PullBranch flattens it from the Cloud
			// branch `settings`). Default false = pending-only.
			"allow_item_edit_any_status": read("allow_item_edit_any_status", "false") == "true",
			// Currency fallback chain: shop_settings.currency_code
			// (BR-SOS06, the per-shop display currency staff picks in
			// admin) → shop_settings.currency (legacy branch-level
			// `branches.currency`, written by PullBranch when Cloud
			// returns the column) → "VND" as the absolute last resort.
			// Pre-fix the handler short-circuited at currency_code and
			// fell back to a hard-coded "VND" even when Cloud had a
			// real branch currency synced — so a JPY shop without a
			// ShopOrderSetting row showed VND on pos-web until staff
			// opened the Settings page once and pressed save.
			"currency_code": read("currency_code",
				read("currency", "VND")),
			// Verbatim copy of ShopOrderSettingsController::availableStatuses
			// (backend/app/Http/Controllers/Api/V1/Shop/ShopOrderSettingsController.php).
			"available_statuses": []map[string]string{
				{"value": "pending", "label": "Pending", "description": "Full flow: pending → preparing → ready → served"},
				{"value": "preparing", "label": "Preparing", "description": "Skip pending — items go straight to kitchen"},
				{"value": "ready", "label": "Ready", "description": "Skip pending + preparing — counter service"},
				{"value": "served", "label": "Served", "description": "Skip all — self-service / grab and go"},
			},
			// Verbatim copy of ShopOrderSettingsController::availableCurrencies.
			"available_currencies": []map[string]string{
				{"code": "VND", "label": "Vietnamese Đồng (VND)", "symbol": "₫"},
				{"code": "JPY", "label": "Japanese Yen (JPY)", "symbol": "¥"},
				{"code": "USD", "label": "US Dollar (USD)", "symbol": "$"},
				{"code": "EUR", "label": "Euro (EUR)", "symbol": "€"},
				{"code": "KRW", "label": "Korean Won (KRW)", "symbol": "₩"},
				{"code": "CNY", "label": "Chinese Yuan (CNY)", "symbol": "¥"},
				{"code": "THB", "label": "Thai Baht (THB)", "symbol": "฿"},
				{"code": "IDR", "label": "Indonesian Rupiah (IDR)", "symbol": "Rp"},
			},
		},
	})
}

// ─── Order writes ─────────────────────────────────────────────────────────────

// PUT /api/v1/pos/orders/{id}/init
//
// Body: { table_ids?: [], guest_count? }
//
// pos-web calls this immediately after a quick-create to bind a table + guest
// count to an open order. We accept both `table_id` (singular, legacy) and
// `table_ids` (Cloud shape) — the singular form is what local SQLite currently
// stores; Phase 3's order_tables junction will normalize the plural shape.
func (s *Server) handleLocalPosInitOrder(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	var body struct {
		TableID    string   `json:"table_id"`
		TableIDs   []string `json:"table_ids"`
		GuestCount *int     `json:"guest_count"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}

	// Normalize: accept both legacy `table_id` and Cloud-shape
	// `table_ids` so existing pos-web callers keep working.
	tableIDs := body.TableIDs
	if len(tableIDs) == 0 && body.TableID != "" {
		tableIDs = []string{body.TableID}
	}

	o, err := s.orders.InitOrder(id, service.InitOrderInput{
		TableIDs:   tableIDs,
		GuestCount: body.GuestCount,
	})
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		if errors.Is(err, service.ErrOrderNotOpen) {
			writeError(w, http.StatusConflict, "order is not open")
			return
		}
		if errors.Is(err, service.ErrTableOccupied) {
			writeError(w, http.StatusConflict, "Table is already occupied by another order.")
			return
		}
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	s.hub.BroadcastEvent("order_updated", o)
	s.enqueueOrderSync("order.init", o.ID, map[string]any{
		"table_ids":   body.TableIDs,
		"table_id":    o.TableID,
		"guest_count": o.GuestCount,
	})
	s.auditLogPOS(r, "order.init", "order", o.ID, "")
	writeJSON(w, http.StatusOK, map[string]any{"data": s.shapeOrderForResponse(r, o)})
}

// PUT /api/v1/pos/orders/{id}
//
// Body: { guest_count?, note?, customer_id?, order_type? }
//
// Patches mutable header fields. customer_id is forwarded to the sync UP
// payload but not persisted locally until Phase 2 adds the customers table.
func (s *Server) handleLocalPosUpdateOrder(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	var body struct {
		GuestCount *int    `json:"guest_count"`
		Note       *string `json:"note"`
		CustomerID *string `json:"customer_id"`
		OrderType  *string `json:"order_type"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	patch := service.OrderUpdateInput{
		GuestCount: body.GuestCount,
		Note:       body.Note,
		OrderType:  body.OrderType,
		CustomerID: body.CustomerID,
	}
	o, err := s.orders.UpdateMeta(id, patch)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		if errors.Is(err, service.ErrOrderNotOpen) {
			writeError(w, http.StatusConflict, "order is not open")
			return
		}
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	payload := map[string]any{
		"guest_count": o.GuestCount,
		"note":        o.Note,
	}
	if body.CustomerID != nil {
		payload["customer_id"] = *body.CustomerID
	}
	s.hub.BroadcastEvent("order_updated", o)
	s.enqueueOrderSync("order.update", o.ID, payload)
	s.auditLogPOS(r, "order.update", "order", o.ID, "")
	writeJSON(w, http.StatusOK, map[string]any{"data": s.shapeOrderForResponse(r, o)})
}

// DELETE /api/v1/pos/orders/{id}
//
// Soft-deletes (transitions to voided with reason `deleted_by_pos`). Cloud
// observes the same row coming through the sync UP queue and applies its own
// delete policy. Returns 204 No Content to match Cloud's signature.
func (s *Server) handleLocalPosDeleteOrder(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	// Capture the bound tables BEFORE the delete removes/soft-deletes the order.
	tableIDs := s.orderTableIDs(id)
	if err := s.orders.SoftDelete(id); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	s.enqueueOrderSync("order.delete", id, nil)
	// Guarantee the table is released on Cloud even if order.delete is 409-drained.
	s.releaseTablesToFree(tableIDs, id)
	s.auditLogPOS(r, "order.delete", "order", id, "")
	w.WriteHeader(http.StatusNoContent)
}

// POST /api/v1/pos/orders/{id}/void
//
// Body: { void_reason }
func (s *Server) handleLocalPosVoidOrder(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	var body struct {
		VoidReason string `json:"void_reason"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	tableIDs := s.orderTableIDs(id)
	o, err := s.orders.VoidOrder(id, body.VoidReason)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	s.hub.BroadcastEvent("order_voided", o)
	s.enqueueOrderSync("order.void", o.ID, map[string]any{"void_reason": body.VoidReason})
	// A voided order served nothing → free its table(s). Guaranteed even if the
	// order.void sync is 409-drained on Cloud without releasing the table.
	s.releaseTablesToFree(tableIDs, o.ID)
	s.auditLogPOS(r, "order.void", "order", o.ID, fmt.Sprintf(`{"reason":%q}`, body.VoidReason))
	writeJSON(w, http.StatusOK, map[string]any{"data": s.shapeOrderForResponse(r, o)})
}

// ─── Item writes ──────────────────────────────────────────────────────────────

// PATCH /api/v1/pos/orders/{id}/items/{item}
//
// Body: { quantity?, note?, status? }
func (s *Server) handleLocalPosUpdateItem(w http.ResponseWriter, r *http.Request) {
	orderID := r.PathValue("id")
	itemID := r.PathValue("item")
	var body service.ItemPatch
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	o, err := s.orders.UpdateItem(orderID, itemID, body)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, http.StatusNotFound, "order or item not found")
			return
		}
		if errors.Is(err, service.ErrInvalidItemStatus) {
			// Pos-web's typings exclude `voided`, but be defensive
			// against a misbehaving client posting an unknown enum.
			writeError(w, http.StatusUnprocessableEntity,
				"status must be one of pending, preparing, ready, served")
			return
		}
		if errors.Is(err, service.ErrOrderNotOpen) {
			writeError(w, http.StatusConflict, "order is not open")
			return
		}
		if errors.Is(err, service.ErrItemEditRequiresPending) {
			writeError(w, http.StatusConflict,
				"Can only change quantity/note when item is pending.")
			return
		}
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	s.hub.BroadcastEvent("order_updated", o)
	// Distinct KDS event for the status branch so kitchen displays can
	// re-render without re-fetching the full order. Mirrors Cloud's
	// OrderItemStatusChanged broadcast.
	if body.Status != nil {
		s.hub.BroadcastEvent("order_item.status_changed", map[string]any{
			"order_id": orderID,
			"item_id":  itemID,
			"status":   *body.Status,
		})
	}
	s.enqueueOrderSync("order.item_update", orderID, map[string]any{
		"item_id": itemID,
		"patch":   body,
	})
	s.auditLogPOS(r, "order.item.update", "order_item", itemID, "")
	writeJSON(w, http.StatusOK, map[string]any{"data": s.shapeOrderForResponse(r, o)})
}

// DELETE /api/v1/pos/orders/{id}/items/{item}
//
// Cloud's removeItem (CustomerOrderService.php:1232) delegates to
// voidItem with reason "Removed by staff" — workstation now follows
// the same path through OrderEngine.DeleteItem so BR-OI05 applies
// uniformly. Status code mapping below mirrors `handleLocalPosVoidItem`.
func (s *Server) handleLocalPosDeleteItem(w http.ResponseWriter, r *http.Request) {
	orderID := r.PathValue("id")
	itemID := r.PathValue("item")
	o, err := s.orders.DeleteItem(orderID, itemID)
	if err != nil {
		switch {
		case errors.Is(err, sql.ErrNoRows):
			writeError(w, http.StatusNotFound, "order or item not found")
		case errors.Is(err, service.ErrOrderNotVoidable):
			writeError(w, http.StatusConflict, "order is not open")
		case errors.Is(err, service.ErrItemNotPending):
			writeError(w, http.StatusConflict,
				"Only pending items can be removed. Items that are preparing, ready, or served cannot be cancelled.")
		default:
			writeError(w, http.StatusBadRequest, err.Error())
		}
		return
	}
	s.hub.BroadcastEvent("order_updated", o)
	s.enqueueOrderSync("order.item_delete", orderID, map[string]any{"item_id": itemID})
	s.auditLogPOS(r, "order.item.delete", "order_item", itemID, "")
	writeJSON(w, http.StatusOK, map[string]any{"data": s.shapeOrderForResponse(r, o)})
}

// POST /api/v1/pos/orders/{id}/items/{item}/void
//
// Body: { void_reason }
func (s *Server) handleLocalPosVoidItem(w http.ResponseWriter, r *http.Request) {
	orderID := r.PathValue("id")
	itemID := r.PathValue("item")
	var body struct {
		VoidReason string `json:"void_reason"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	// Mirror Cloud's request validation
	// (CustomerOrderController::voidItem line 672): `void_reason` is
	// required + must be a string. Empty string fails the same way an
	// Eloquent validator would — 422 with a single field error so the
	// pos-web FE binds it to the "Lý do huỷ" input.
	reason := strings.TrimSpace(body.VoidReason)
	if reason == "" {
		writeError(w, http.StatusUnprocessableEntity, "void_reason is required")
		return
	}
	o, err := s.orders.VoidItem(orderID, itemID, reason)
	if err != nil {
		switch {
		case errors.Is(err, sql.ErrNoRows):
			writeError(w, http.StatusNotFound, "order or item not found")
		case errors.Is(err, service.ErrOrderNotVoidable):
			// BR-OI05: order must be `open`. 409 mirrors Cloud's body.
			writeError(w, http.StatusConflict, "order is not open")
		case errors.Is(err, service.ErrItemNotPending):
			// BR-OI05: item must be `pending`.
			writeError(w, http.StatusConflict,
				"Only pending items can be voided. Items that are preparing, ready, or served cannot be cancelled.")
		default:
			writeError(w, http.StatusBadRequest, err.Error())
		}
		return
	}
	s.hub.BroadcastEvent("order_updated", o)
	s.enqueueOrderSync("order.item_void", orderID, map[string]any{
		"item_id":     itemID,
		"void_reason": reason,
	})
	s.auditLogPOS(r, "order.item.void", "order_item", itemID, fmt.Sprintf(`{"reason":%q}`, reason))
	writeJSON(w, http.StatusOK, map[string]any{"data": s.shapeOrderForResponse(r, o)})
}

// POST /api/v1/pos/orders/{id}/items/{item}/refund
//
// Body: { quantity, reason? }
//
// plan-045 — appends a negative-qty refund line reversing N units of a line, its
// copied+negated tax snapshot, and an append-only refund order_conditions row.
// The order total drops by the reversed gross; the refund line syncs UP via the
// dedicated order.item_refund op (idempotent on the refund-line UUID).
func (s *Server) handleLocalPosRefundItem(w http.ResponseWriter, r *http.Request) {
	orderID := r.PathValue("id")
	itemID := r.PathValue("item")
	var body struct {
		Quantity int    `json:"quantity"`
		Reason   string `json:"reason"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	// Mirror Cloud's RefundOrderItemRequest: quantity gt:0.
	if body.Quantity <= 0 {
		writeError(w, http.StatusUnprocessableEntity, "quantity must be greater than zero")
		return
	}
	reason := strings.TrimSpace(body.Reason)

	res, err := s.orders.RefundItem(orderID, itemID, body.Quantity, reason)
	if err != nil {
		switch {
		case errors.Is(err, sql.ErrNoRows):
			writeError(w, http.StatusNotFound, "order or item not found")
		case errors.Is(err, service.ErrOrderNotOpen):
			// ORDER_NOT_REFUNDABLE (order voided / not in a refundable state).
			writeError(w, http.StatusConflict, "order is not refundable")
		case errors.Is(err, service.ErrCannotRefundRefundLine):
			writeError(w, http.StatusUnprocessableEntity, "cannot refund a refund line")
		case errors.Is(err, service.ErrRefundExceedsQuantity):
			writeError(w, http.StatusUnprocessableEntity, "refund exceeds refundable quantity")
		case errors.Is(err, service.ErrRefundQuantityInvalid):
			writeError(w, http.StatusUnprocessableEntity, "quantity must be greater than zero")
		default:
			writeError(w, http.StatusBadRequest, err.Error())
		}
		return
	}

	s.hub.BroadcastEvent("order_updated", res.Order)
	// Sync UP via the dedicated order.item_refund op. The refund-line UUID is the
	// idempotency anchor (client_order_item_id) so a re-drain never double-applies.
	s.enqueueOrderSync("order.item_refund", orderID, map[string]any{
		"item_id":        itemID,
		"refund_line_id": res.RefundLineID,
		"quantity":       body.Quantity,
		"reason":         reason,
	})
	s.auditLogPOS(r, "order.item.refund", "order_item", itemID,
		fmt.Sprintf(`{"quantity":%d,"reason":%q}`, body.Quantity, reason))
	writeJSON(w, http.StatusCreated, map[string]any{"data": s.shapeOrderForResponse(r, res.Order)})
}

// ─── Order payments list ──────────────────────────────────────────────────────

// GET /api/v1/pos/orders/{id}/payments
//
// Reads local payments table for the given order. pos-web uses this to render
// the receipt history dialog without needing Cloud.
func (s *Server) handleLocalPosListOrderPayments(w http.ResponseWriter, r *http.Request) {
	orderID := r.PathValue("id")
	rows, err := s.db.Query(`
		SELECT id, COALESCE(cloud_id, ''), order_id, payment_method,
		       amount, status, COALESCE(terminal_response, ''),
		       created_at, updated_at, COALESCE(synced_at, '')
		FROM payments
		WHERE order_id = ?
		ORDER BY created_at ASC`, orderID)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	defer rows.Close()

	type payment struct {
		ID               string `json:"id"`
		CloudID          string `json:"cloud_id,omitempty"`
		OrderID          string `json:"order_id"`
		PaymentMethod    string `json:"payment_method"`
		Amount           int    `json:"amount"`
		Status           string `json:"status"`
		TerminalResponse string `json:"terminal_response,omitempty"`
		CreatedAt        string `json:"created_at"`
		UpdatedAt        string `json:"updated_at"`
		SyncedAt         string `json:"synced_at,omitempty"`
	}
	out := []payment{}
	for rows.Next() {
		var p payment
		if err := rows.Scan(&p.ID, &p.CloudID, &p.OrderID, &p.PaymentMethod,
			&p.Amount, &p.Status, &p.TerminalResponse,
			&p.CreatedAt, &p.UpdatedAt, &p.SyncedAt); err != nil {
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}
		out = append(out, p)
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": out})
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

// enqueueOrderSync centralizes the sync UP enqueue for order-related events.
// All Phase 1 writes use the same payload envelope so the worker side can
// dispatch by action without per-handler branching.
//
// `action` is the full handler key (e.g. "order.init", "payment.refund",
// "customer.create"). The helper splits it into (entityType, operation)
// before calling Enqueue so the worker's lookup key `entityType.operation`
// matches the registered handler key exactly.
//
// Pre-fix bug: this used to pass `action` directly as the operation while
// hard-coding entityType="order", producing keys like
// "order.order.init" → no handler match → silent drop. Every Phase 1/3/4/5
// write that flowed through this helper (13 distinct operations) never
// reached Cloud. Splitting + dispatching by the real entity type closes
// that hole. See sync_service.go:pushToCloud for the lookup site.
func (s *Server) enqueueOrderSync(action, orderID string, extra map[string]any) {
	if s.sync == nil {
		return
	}
	entityType, operation, ok := strings.Cut(action, ".")
	if !ok || entityType == "" || operation == "" {
		// Defensive — every call site passes a "<entity>.<op>" string
		// today, but a malformed action used to be silently swallowed.
		// Surface it through the audit log so a future refactor can't
		// reintroduce the bug unnoticed.
		_ = json.NewEncoder(nullWriter{}).Encode(
			fmt.Errorf("enqueueOrderSync: malformed action %q (expected entity.operation)", action),
		)
		return
	}
	payload := map[string]any{
		"bearer_token":    s.GetDeviceToken(),
		"idempotency_key": uuid.NewString(),
		"order_id":        orderID,
	}
	for k, v := range extra {
		payload[k] = v
	}
	if err := s.sync.Enqueue(entityType, orderID, operation, payload, 1); err != nil {
		// Audit failure is non-fatal — sync_queue table has its own retry
		// machinery; we surface the error so it shows up in the audit log.
		_ = json.NewEncoder(nullWriter{}).Encode(err)
		return
	}
	// Drain immediately instead of waiting up to 5s for the next tick, so
	// every LAN-mode lifecycle write (add/void/update item, checkout, coupon…)
	// reaches Cloud right after the action. Non-blocking + coalescing; if the
	// workstation is offline the row stays queued for the next online tick.
	s.sync.Wake()
}

// orderTableIDs returns every table id bound to an order — the primary
// orders.table_id plus any merged tables from the order_tables pivot.
func (s *Server) orderTableIDs(orderID string) []string {
	rows, err := s.db.Query(`
		SELECT tid FROM (
			SELECT table_id AS tid FROM orders WHERE id = ?
			UNION
			SELECT table_id AS tid FROM order_tables WHERE order_id = ?
		) WHERE tid IS NOT NULL AND tid <> ''`, orderID, orderID)
	if err != nil {
		return nil
	}
	defer rows.Close()
	var ids []string
	for rows.Next() {
		var tid string
		if rows.Scan(&tid) == nil && tid != "" {
			ids = append(ids, tid)
		}
	}
	return ids
}

// releaseTablesToFree frees each given table (locally + on Cloud via a
// table.status sync op) UNLESS it is still held by another live order. Called
// after a terminal order transition (void / delete) so a table never stays
// stuck `occupied`: the order-lifecycle sync (order.void / order.delete) can be
// silently drained on a Cloud 409 (treated as success by cloudPost) without
// actually releasing the table there, but the table.status op is Cloud-
// authoritative and retries until applied, guaranteeing convergence.
func (s *Server) releaseTablesToFree(tableIDs []string, excludeOrderID string) {
	woke := false
	for _, tid := range tableIDs {
		var otherActive int
		_ = s.db.QueryRow(`
			SELECT COUNT(*) FROM orders o
			LEFT JOIN order_tables ot ON ot.order_id = o.id
			WHERE (ot.table_id = ? OR o.table_id = ?)
			  AND o.id <> ? AND o.status NOT IN ('voided', 'closed')`,
			tid, tid, excludeOrderID).Scan(&otherActive)
		if otherActive > 0 {
			continue // still serving another live order — leave it occupied
		}
		if _, err := s.db.Exec(
			`UPDATE tables SET status = 'free', local_synced_at = datetime('now') WHERE id = ?`, tid,
		); err != nil {
			slog.Warn("releaseTablesToFree: local update failed", "table", tid, "err", err)
			continue
		}
		if s.sync != nil {
			if err := s.sync.Enqueue("table", tid, "status", map[string]any{"status": "free"}, 2); err != nil {
				slog.Warn("releaseTablesToFree: enqueue failed", "table", tid, "err", err)
			} else {
				woke = true
			}
		}
		if s.hub != nil {
			s.hub.BroadcastEvent("table_status_changed", map[string]any{"id": tid, "status": "free"})
		}
	}
	if woke && s.sync != nil {
		s.sync.Wake()
	}
}

// reconcileStuckTables frees tables left stuck `occupied` by a PAST terminal
// order transition whose Cloud release never applied (e.g. an order.void /
// order.delete that Cloud 409'd, which cloudPost drains as success without
// releasing the table). Runs once at startup so pre-existing stuck rows heal.
//
// SAFE by construction — it only frees a table that BOTH has no active local
// order AND has at least one terminal (voided/closed) local order, i.e. one the
// workstation itself released. A table occupied by an order created on another
// device (no terminal local order) is never touched.
func (s *Server) reconcileStuckTables() {
	rows, err := s.db.Query(`
		SELECT t.id FROM tables t
		WHERE t.status = 'occupied'
		  AND NOT EXISTS (
		    SELECT 1 FROM orders o LEFT JOIN order_tables ot ON ot.order_id = o.id
		    WHERE (ot.table_id = t.id OR o.table_id = t.id)
		      AND o.status NOT IN ('voided', 'closed'))
		  AND EXISTS (
		    SELECT 1 FROM orders o LEFT JOIN order_tables ot ON ot.order_id = o.id
		    WHERE (ot.table_id = t.id OR o.table_id = t.id)
		      AND o.status IN ('voided', 'closed'))`)
	if err != nil {
		slog.Warn("reconcileStuckTables: query failed", "err", err)
		return
	}
	var ids []string
	for rows.Next() {
		var id string
		if rows.Scan(&id) == nil {
			ids = append(ids, id)
		}
	}
	rows.Close()
	if len(ids) == 0 {
		return
	}
	slog.Info("reconcileStuckTables: freeing tables stuck occupied with no active order", "count", len(ids))
	s.releaseTablesToFree(ids, "")
}

func atoiDefault(s string, def int) int {
	if s == "" {
		return def
	}
	var n int
	if _, err := fmt.Sscanf(s, "%d", &n); err != nil || n < 1 {
		return def
	}
	return n
}

// nullWriter discards writes. Tiny shim used so enqueueOrderSync's failure
// path can still surface through Go's encoding/json without forcing a new
// import path in callers.
type nullWriter struct{}

func (nullWriter) Write(p []byte) (int, error) { return len(p), nil }
