package handler

import (
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"strconv"
	"strings"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/google/uuid"
)

// Handy (staff handheld) endpoints — device-token auth, native app so no CORS.
// Flow: handy → workstation LAN → SQLite → sync UP to Cloud.
//
// Route namespace /api/v1/handy/* mirrors Cloud's handy.php routes so the app
// can target either endpoint without URL changes (only base URL differs).

// GET /api/v1/handy/me
func (s *Server) handleLocalHandyMe(w http.ResponseWriter, r *http.Request) {
	d, ok := DeviceFromContext(r.Context())
	if !ok {
		writeError(w, http.StatusUnauthorized, "not authenticated")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"data": map[string]any{
			"id":        d.ID,
			"type":      d.Type,
			"status":    "active",
			"branch_id": d.BranchID,
		},
	})
}

// GET /api/v1/handy/tables
func (s *Server) handleLocalHandyTables(w http.ResponseWriter, r *http.Request) {
	// Left-join active orders so current_order_id can be returned — handy uses
	// it to navigate directly into an existing order from the table grid.
	rows, err := s.db.Query(`
		SELECT t.id, t.name, COALESCE(t.zone_id, ''),
		       COALESCE(t.status, 'free'), COALESCE(t.capacity, 0),
		       COALESCE(t.qr_token, ''), COALESCE(z.name, ''), COALESCE(z.sort_order, 0),
		       COALESCE(
		           (SELECT id FROM orders
		            WHERE table_id = t.id AND status NOT IN ` + service.SQLStatusTerminal + `
		            ORDER BY created_at DESC LIMIT 1),
		           '')
		FROM tables t
		LEFT JOIN zones z ON z.id = t.zone_id
		ORDER BY COALESCE(z.sort_order, 999), t.name`)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rows.Close()

	type zone struct {
		ID        string `json:"id,omitempty"`
		Name      string `json:"name"`
		SortOrder int    `json:"sort_order"`
	}
	type table struct {
		ID             string  `json:"id"`
		Code           string  `json:"code"`
		Name           string  `json:"name"`
		ZoneID         string  `json:"zone_id,omitempty"`
		Status         string  `json:"status"`
		Capacity       int     `json:"seat_count"`
		QrToken        string  `json:"qr_token,omitempty"`
		IsActive       bool    `json:"is_active"`
		CurrentOrderID *string `json:"current_order_id"`
		Zone           *zone   `json:"zone,omitempty"`
	}

	out := []table{}
	for rows.Next() {
		var t table
		var zoneName, currentOrderID string
		var zoneSortOrder int
		if err := rows.Scan(&t.ID, &t.Name, &t.ZoneID, &t.Status, &t.Capacity,
			&t.QrToken, &zoneName, &zoneSortOrder, &currentOrderID); err != nil {
			writeServerError(w, r, err)
			return
		}
		t.Code = t.Name // local schema stores table code in `name` column
		t.IsActive = true
		if currentOrderID != "" {
			t.CurrentOrderID = &currentOrderID
		}
		if t.ZoneID != "" {
			t.Zone = &zone{ID: t.ZoneID, Name: zoneName, SortOrder: zoneSortOrder}
		}
		out = append(out, t)
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": out})
}

// GET /api/v1/handy/menus/by-day/{dayOfWeek}
//
// Serves from handy_menu_cache — a daily snapshot of ShopMenuByDayResource[]
// pulled from Cloud. Falls back to the stale cache when today's pull hasn't
// happened yet (boot race) rather than returning an empty list.
func (s *Server) handleLocalHandyMenusByDay(w http.ResponseWriter, r *http.Request) {
	if d := r.PathValue("dayOfWeek"); d != "" {
		if n, err := strconv.Atoi(d); err != nil || n < 0 || n > 6 {
			writeError(w, http.StatusBadRequest, "dayOfWeek must be 0-6")
			return
		}
	}

	payload, err := s.handyMenuCachePayload()
	if err != nil {
		writeServerError(w, r, err)
		return
	}

	// payload is already a JSON array — write it directly as {"data": <array>}
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	fmt.Fprintf(w, `{"data":%s}`, payload)
}

// GET /api/v1/handy/menus/{menuId}/products
//
// Extracts the matching menu from the daily cache and returns its
// menu_products array so handy's useMenuProducts hook gets the full
// ShopMenuProduct[] (multi-SKU, toppings, promotions, sections).
func (s *Server) handleLocalHandyMenuProducts(w http.ResponseWriter, r *http.Request) {
	menuID := r.PathValue("menu")

	payload, err := s.handyMenuCachePayload()
	if err != nil {
		writeServerError(w, r, err)
		return
	}

	// Parse the cached array to find the requested menu.
	var menus []json.RawMessage
	if err := json.Unmarshal([]byte(payload), &menus); err != nil {
		writeServerError(w, r, fmt.Errorf("handy cache parse: %w", err))
		return
	}

	for _, raw := range menus {
		var header struct {
			ID           string            `json:"id"`
			MenuProducts []json.RawMessage `json:"menu_products"`
		}
		if err := json.Unmarshal(raw, &header); err != nil {
			continue
		}
		if header.ID != menuID {
			continue
		}
		productsJSON, _ := json.Marshal(header.MenuProducts)
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		fmt.Fprintf(w, `{"data":%s,"meta":{"current_page":1,"last_page":1,"per_page":500,"total":%d}}`, productsJSON, len(header.MenuProducts))
		return
	}

	writeError(w, http.StatusNotFound, "menu not found in local cache")
}

// handyMenuCachePayload returns the cached ShopMenuByDayResource[] JSON string.
// Falls back to a stale day's cache when today's pull hasn't completed yet.
func (s *Server) handyMenuCachePayload() (string, error) {
	var payload string
	err := s.db.QueryRow(`SELECT payload FROM handy_menu_cache WHERE id = 'current'`).Scan(&payload)
	if err != nil {
		return "[]", nil // no cache yet — return empty array (handy shows "no menu" state)
	}
	return payload, nil
}

// GET /api/v1/handy/settings/order
func (s *Server) handleLocalHandyOrderSettings(w http.ResponseWriter, r *http.Request) {
	get := func(key, def string) string {
		var v string
		if err := s.db.QueryRow(`SELECT value FROM shop_settings WHERE key = ?`, key).Scan(&v); err != nil {
			return def
		}
		return v
	}

	enableQuickOrder := false
	if get("enable_quick_order", "false") == "true" {
		enableQuickOrder = true
	}

	var defaultStatus any
	if v := get("default_order_item_status", ""); v != "" {
		defaultStatus = v
	}

	// #876 — mirrored toggle; the app shows/hides the pay action on this.
	handyDirectPayment := false
	if v := get("handy_allow_direct_payment", "false"); v == "true" || v == "1" {
		handyDirectPayment = true
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"data": map[string]any{
			"currency_code":              get("currency_code", "JPY"),
			"tax_rate":                   get("tax_rate", "0.00"),
			"service_charge_rate":        get("service_charge_rate", "0.00"),
			"enable_quick_order":         enableQuickOrder,
			"default_order_item_status":  defaultStatus,
			"handy_allow_direct_payment": handyDirectPayment,
		},
	})
}

// GET /api/v1/handy/orders
func (s *Server) handleLocalHandyOrders(w http.ResponseWriter, r *http.Request) {
	statusParam := r.URL.Query().Get("status")

	var (
		rows *sql.Rows
		err  error
	)
	if statusParam != "" {
		// Support comma-separated values e.g. "open,dining" → IN ('open','dining')
		parts := strings.Split(statusParam, ",")
		placeholders := make([]string, len(parts))
		args := make([]any, len(parts))
		for i, p := range parts {
			placeholders[i] = "?"
			args[i] = strings.TrimSpace(p)
		}
		query := fmt.Sprintf(`
			SELECT o.id, COALESCE(o.cloud_id,''), o.order_number,
			       COALESCE(t.name, COALESCE(o.table_number,'')),
			       o.status, COALESCE(o.guest_count,1), COALESCE(o.note,''),
			       o.subtotal, o.tax_amount, o.total_amount, COALESCE(o.payment_method,''),
			       COALESCE(o.closed_at,''), o.created_at, o.updated_at,
			       COALESCE(o.order_code,''), COALESCE(o.branch_id,''), COALESCE(o.brand_id,''),
			       COALESCE(o.opened_at, o.created_at), COALESCE(o.voided_at,''), COALESCE(o.void_reason,'')
			FROM orders o
			LEFT JOIN tables t ON t.id = o.table_id
			WHERE o.status IN (%s)
			ORDER BY o.created_at DESC LIMIT 200`, strings.Join(placeholders, ","))
		rows, err = s.db.Query(query, args...)
	} else {
		rows, err = s.db.Query(`
			SELECT o.id, COALESCE(o.cloud_id,''), o.order_number,
			       COALESCE(t.name, COALESCE(o.table_number,'')),
			       o.status, COALESCE(o.guest_count,1), COALESCE(o.note,''),
			       o.subtotal, o.tax_amount, o.total_amount, COALESCE(o.payment_method,''),
			       COALESCE(o.closed_at,''), o.created_at, o.updated_at,
			       COALESCE(o.order_code,''), COALESCE(o.branch_id,''), COALESCE(o.brand_id,''),
			       COALESCE(o.opened_at, o.created_at), COALESCE(o.voided_at,''), COALESCE(o.void_reason,'')
			FROM orders o
			LEFT JOIN tables t ON t.id = o.table_id
			WHERE o.status NOT IN ` + service.SQLStatusTerminal + `
			ORDER BY o.created_at DESC LIMIT 200`)
	}
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rows.Close()

	out, err := s.scanHandyOrders(rows)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	for i, o := range out {
		items, err := s.queryHandyOrderItems(o["id"].(string))
		if err != nil {
			writeServerError(w, r, err)
			return
		}
		out[i]["items"] = items
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"data": out,
		"meta": map[string]any{"total": len(out)},
	})
}

// POST /api/v1/handy/orders
//
// Body: { order_type?, table_ids?, guest_count?, note? }
func (s *Server) handleLocalHandyCreateOrder(w http.ResponseWriter, r *http.Request) {
	var body struct {
		OrderType string   `json:"order_type"`
		TableIDs  []string `json:"table_ids"`
		// nullable to match the engine's *int field — keeps the
		// "khách trống = NULL" semantic intact for handy too.
		GuestCount *int   `json:"guest_count"`
		Note       string `json:"note"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}

	tableID := ""
	if len(body.TableIDs) > 0 {
		tableID = body.TableIDs[0]
	}
	orderType := body.OrderType
	if orderType == "" {
		orderType = "dine_in"
	}

	input := service.CreateOrderInput{
		TableID:    tableID,
		OrderType:  orderType,
		GuestCount: body.GuestCount, // nil → DB NULL (no auto-default)
		Note:       body.Note,
		Items:      []service.CreateItemInput{},
	}
	o, err := s.orders.Create(input, s.codeGen)
	if err != nil {
		writeServerError(w, r, err)
		return
	}

	s.hub.BroadcastEvent("order_created", o)
	s.auditLogPOS(r, "order.create", "order", o.ID, fmt.Sprintf(`{"source":"handy","total":%d}`, o.TotalAmount))

	if s.sync != nil {
		// plan-041 parity with pos — Cloud is the single authority for the
		// gapless ORD-#### code; it is minted at insert, NOT sent from here.
		// client_order_id (this order's local UUID) is the durable idempotency
		// key so a re-sync (restart re-enqueue) maps to the same Cloud order
		// without minting a second number. o.OrderCode stays as the provisional
		// WS-#### value until the sync response is reconciled back in.
		orderShape := map[string]any{
			"client_order_id": o.ID,
			"order_type":      o.OrderType,
			"guest_count":     o.GuestCount,
			"note":            o.Note,
		}
		// Resolve the full table set from the pivot — orders.table_id only
		// holds the primary. Sync UP needs every binding so Cloud's
		// table_ids[] write hits the same physical rows handy picked.
		tableIDs, err := s.loadOrderTableIDs(o.ID)
		if err != nil {
			s.auditLogPOS(r, "order.table_pivot_load_failed", "order", o.ID,
				fmt.Sprintf(`{"err":%q}`, err.Error()))
		}
		if len(tableIDs) == 0 && o.TableID != "" {
			tableIDs = []string{o.TableID}
		}
		if len(tableIDs) > 0 {
			orderShape["table_ids"] = tableIDs
			orderShape["table_id"] = tableIDs[0] // legacy single-table contract
		}

		syncPayload := map[string]any{
			"bearer_token":    s.GetDeviceToken(),
			"idempotency_key": uuid.NewString(),
			"order":           orderShape,
		}
		if err := s.sync.Enqueue("order", o.ID, "create", syncPayload, 1); err != nil {
			s.auditLogPOS(r, "order.sync_enqueue_failed", "order", o.ID,
				fmt.Sprintf(`{"err":%q}`, err.Error()))
		} else {
			// LAN-mode UX: push the new order to Cloud immediately instead of
			// waiting up to 5s for the next sync tick. Non-blocking — offline
			// rows stay queued for the next online tick.
			s.sync.Wake()
		}
	}

	full, err := s.toHandyOrder(o.ID)
	if err != nil {
		writeJSON(w, http.StatusCreated, map[string]any{"data": o})
		return
	}
	writeJSON(w, http.StatusCreated, map[string]any{"data": full})
}

// GET /api/v1/handy/orders/{order}
func (s *Server) handleLocalHandyGetOrder(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("order")
	o, err := s.toHandyOrder(id)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		writeServerError(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": o})
}

// PUT /api/v1/handy/orders/{order}/init
//
// Body: { table_ids?, guest_count? }
// Sets table and guest count on a freshly created order before items are added.
func (s *Server) handleLocalHandyInitOrder(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("order")
	var body struct {
		TableIDs   []string `json:"table_ids"`
		GuestCount *int     `json:"guest_count"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}

	tableID := ""
	if len(body.TableIDs) > 0 {
		tableID = body.TableIDs[0]
	}

	if tableID != "" {
		if _, err := s.db.Exec(`UPDATE orders SET table_id = ?, updated_at = datetime('now') WHERE id = ?`,
			tableID, id); err != nil {
			writeServerError(w, r, err)
			return
		}
	}
	if body.GuestCount != nil {
		if _, err := s.db.Exec(`UPDATE orders SET guest_count = ?, updated_at = datetime('now') WHERE id = ?`,
			*body.GuestCount, id); err != nil {
			writeServerError(w, r, err)
			return
		}
	}

	o, err := s.toHandyOrder(id)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		writeServerError(w, r, err)
		return
	}
	s.hub.BroadcastEvent("order_updated", o)
	// #1266 — same as the POS surface: without it Cloud never learns which
	// tables the order was seated at, or the guest count, when the waiter set
	// them from the handheld.
	s.enqueueOrderSync("order.init", id, map[string]any{
		"table_ids":   body.TableIDs,
		"table_id":    tableID,
		"guest_count": body.GuestCount,
	})
	writeJSON(w, http.StatusOK, map[string]any{"data": o})
}

// POST /api/v1/handy/orders/{order}/items
//
// Body: { items: [{product_sku_id, quantity, note?}] }
func (s *Server) handleLocalHandyAddItems(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("order")
	var body struct {
		Items []service.CreateItemInput `json:"items"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	if len(body.Items) == 0 {
		writeError(w, http.StatusBadRequest, "items array is empty")
		return
	}
	created, err := s.orders.AddItems(id, body.Items)
	if err != nil {
		if errors.Is(err, service.ErrOrderNotOpen) {
			writeError(w, http.StatusConflict, err.Error())
			return
		}
		writeServerError(w, r, err)
		return
	}
	o, err := s.toHandyOrder(id)
	if err != nil {
		writeError(w, http.StatusNotFound, "order not found")
		return
	}
	s.hub.BroadcastEvent("order_updated", o)
	// #1266 — the same enqueue the POS surface does. Without it a waiter's
	// items stay on this machine: order.create carries only order-level fields,
	// order.checkout carries no lines, and order.item_add is the ONLY path that
	// puts a line on Cloud. Cloud was receiving an empty order and, later, the
	// payment for it.
	//
	// Dedup mirrors POS for the same reason: a resend or double-tap must not
	// stack a second item_add row that then fails in lockstep with the first.
	itemIDs := make([]string, 0, len(created))
	for _, it := range created {
		itemIDs = append(itemIDs, it.ID)
	}
	if s.sync != nil {
		itemIDs = s.sync.FilterNewItemAddIDs(id, itemIDs)
	}
	if len(itemIDs) > 0 {
		s.enqueueOrderSync("order.item_add", id, map[string]any{"item_ids": itemIDs})
	}
	s.auditLogPOS(r, "order.add_items", "order", id, fmt.Sprintf(`{"source":"handy","count":%d}`, len(body.Items)))
	writeJSON(w, http.StatusOK, map[string]any{"data": o})
}

// PATCH /api/v1/handy/orders/{order}/items/{item}
//
// Body: { quantity?, note?, status? }
func (s *Server) handleLocalHandyUpdateItem(w http.ResponseWriter, r *http.Request) {
	orderID := r.PathValue("order")
	itemID := r.PathValue("item")

	if err := s.requireHandyItemsMutable(orderID); err != nil {
		if errors.Is(err, service.ErrOrderNotOpen) {
			writeError(w, http.StatusConflict, err.Error())
			return
		}
		writeServerError(w, r, err)
		return
	}

	var body struct {
		Quantity *int    `json:"quantity"`
		Note     *string `json:"note"`
		Status   string  `json:"status"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}

	if body.Quantity != nil {
		if _, err := s.db.Exec(
			`UPDATE order_items SET quantity = ?, updated_at = datetime('now') WHERE id = ? AND customer_order_id = ?`,
			*body.Quantity, itemID, orderID); err != nil {
			writeServerError(w, r, err)
			return
		}
	}
	if body.Note != nil {
		if _, err := s.db.Exec(
			`UPDATE order_items SET note = ?, updated_at = datetime('now') WHERE id = ? AND customer_order_id = ?`,
			*body.Note, itemID, orderID); err != nil {
			writeServerError(w, r, err)
			return
		}
	}
	if body.Status != "" {
		if _, err := s.db.Exec(
			`UPDATE order_items SET status = ?, updated_at = datetime('now') WHERE id = ? AND customer_order_id = ?`,
			body.Status, itemID, orderID); err != nil {
			writeServerError(w, r, err)
			return
		}
	}

	// Recalculate order totals after item change.
	if err := s.recalcOrderTotals(orderID); err != nil {
		writeServerError(w, r, err)
		return
	}

	// #1266 — mirrors the POS surface, including its condition: order.item_update
	// forwards only qty/note/toppings, so a status-only patch would POST an
	// empty body to Cloud. Status changes travel on their own path.
	if body.Quantity != nil || body.Note != nil {
		s.enqueueOrderSync("order.item_update", orderID, map[string]any{
			"item_id": itemID,
			"patch":   body,
		})
	}

	o, err := s.toHandyOrder(orderID)
	if err != nil {
		writeError(w, http.StatusNotFound, "order not found")
		return
	}
	s.hub.BroadcastEvent("order_updated", o)
	writeJSON(w, http.StatusOK, map[string]any{"data": o})
}

// DELETE /api/v1/handy/orders/{order}/items/{item}
func (s *Server) handleLocalHandyRemoveItem(w http.ResponseWriter, r *http.Request) {
	orderID := r.PathValue("order")
	itemID := r.PathValue("item")

	if err := s.requireHandyItemsMutable(orderID); err != nil {
		if errors.Is(err, service.ErrOrderNotOpen) {
			writeError(w, http.StatusConflict, err.Error())
			return
		}
		writeServerError(w, r, err)
		return
	}

	res, err := s.db.Exec(`DELETE FROM order_items WHERE id = ? AND customer_order_id = ?`, itemID, orderID)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	if n, _ := res.RowsAffected(); n == 0 {
		writeError(w, http.StatusNotFound, "item not found")
		return
	}
	if err := s.recalcOrderTotals(orderID); err != nil {
		writeServerError(w, r, err)
		return
	}

	o, err := s.toHandyOrder(orderID)
	if err != nil {
		writeError(w, http.StatusNotFound, "order not found")
		return
	}
	s.hub.BroadcastEvent("order_updated", o)
	// #1266 — same as the POS surface. Without it Cloud keeps a line the waiter
	// removed at the table.
	s.enqueueOrderSync("order.item_delete", orderID, map[string]any{"item_id": itemID})
	writeJSON(w, http.StatusOK, map[string]any{"data": o})
}

// DELETE /api/v1/handy/orders/{order}
//
// Body: { void_reason? }
func (s *Server) handleLocalHandyVoidOrder(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("order")
	var body struct {
		VoidReason string `json:"void_reason"`
	}
	if r.ContentLength > 0 {
		_ = readJSON(r, &body)
	}

	tableIDs := s.orderTableIDs(id)
	if err := s.orders.UpdateStatus(id, service.StatusVoided); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	if body.VoidReason != "" {
		_, _ = s.db.Exec(`UPDATE orders SET void_reason = ?, updated_at = datetime('now') WHERE id = ?`,
			body.VoidReason, id)
	}
	// Sync the void UP. The POS void does this; this handler did not, so an
	// order voided from a handheld stayed ACTIVE on Cloud forever — local said
	// voided, Cloud kept counting it in revenue. The table was freed on Cloud
	// (see below), which made the divergence harder to notice: the floor looked
	// right while the money did not.
	s.enqueueOrderSync("order.void", id, map[string]any{"void_reason": body.VoidReason})

	// Free the table(s) — guaranteed on Cloud via the table.status sync op.
	s.releaseTablesToFree(tableIDs, id)

	o, err := s.toHandyOrder(id)
	if err != nil {
		writeError(w, http.StatusNotFound, "order not found")
		return
	}
	s.hub.BroadcastEvent("order_voided", o)
	s.auditLogPOS(r, "order.void", "order", id, fmt.Sprintf(`{"source":"handy","reason":%q}`, body.VoidReason))
	writeJSON(w, http.StatusOK, map[string]any{"data": o})
}

// POST /api/v1/handy/orders/{order}/fire
//
// "Gửi bếp" — in kitchen ticket cho tất cả items chưa được in (print_status == "pending")
// của order. Sau khi in thành công, đánh dấu printed_at trên từng item.
//
// Response:
//
//	200 { "status": "ok",      "printed": N }
//	200 { "status": "partial", "printed": N, "errors": [...] }
//	422 { "message": "no unprinted items" }
//	404 { "message": "order not found" }
func (s *Server) handleLocalHandyFireOrder(w http.ResponseWriter, r *http.Request) {
	orderID := r.PathValue("order")
	slog.Info("handy fire order", "order_id", orderID)

	o, err := s.orders.GetByID(orderID)
	if err != nil {
		slog.Warn("handy fire: order not found", "order_id", orderID, "err", err)
		writeError(w, http.StatusNotFound, "order not found")
		return
	}
	slog.Info("handy fire: order loaded", "order_id", orderID, "item_count", len(o.Items))

	// Quick pre-check so we can emit the legacy 422 without invoking the
	// shared helper — the helper returns 0 printed AND 0 errors for that
	// case but the contract is a non-200 with a message body.
	hasPending := false
	for _, item := range o.Items {
		if needsFire(item) {
			hasPending = true
			break
		}
	}
	if !hasPending {
		slog.Warn("handy fire: no unprinted items", "order_id", orderID)
		writeError(w, http.StatusUnprocessableEntity, "no unprinted items")
		return
	}

	firedCount, groups, errors := s.fireKitchenForOrder(o, s.printLabelLocale())

	// Broadcast order.kitchen_printed so KDS renders the items live — same path
	// pos-web uses. firedCount > 0 covers the KDS-only / print-failed cases too.
	if firedCount > 0 {
		s.broadcastKitchenPrinted(o, groups, "handy")
	}

	// Project structured errors back into the legacy flat string list so the
	// mobile handy app's response shape is byte-identical to pre-refactor.
	// The new LAN endpoint (plan-038 T2.1) consumes the rich struct directly.
	flatErrors := make([]string, 0, len(errors))
	for _, e := range errors {
		switch {
		case e.Reason == "no_printer:kitchen_printer":
			flatErrors = append(flatErrors, "no kitchen printer configured")
		case e.Reason == "ticket_counter":
			flatErrors = append(flatErrors, fmt.Sprintf("ticket counter: %s", e.Detail))
		case e.Reason == "print":
			flatErrors = append(flatErrors, fmt.Sprintf("print: %s", e.Detail))
		default:
			flatErrors = append(flatErrors, e.Detail)
		}
	}

	s.auditLogPOS(r, "order.fire", "order", orderID,
		fmt.Sprintf(`{"source":"handy","printed":%d,"errors":%d}`, firedCount, len(flatErrors)))

	slog.Info("handy fire done", "order_id", orderID, "fired", firedCount, "errors", len(flatErrors))

	if len(flatErrors) > 0 {
		writeJSON(w, http.StatusOK, map[string]any{
			"status":  "partial",
			"printed": firedCount,
			"errors":  flatErrors,
		})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "printed": firedCount})
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

// toHandyOrder builds a full order response (with items) shaped like CustomerOrder.
func (s *Server) toHandyOrder(id string) (map[string]any, error) {
	row := s.db.QueryRow(`
		SELECT o.id, COALESCE(o.cloud_id,''), o.order_number,
		       COALESCE(t.name, COALESCE(o.table_number,'')),
		       o.status, COALESCE(o.guest_count,1), COALESCE(o.note,''),
		       o.subtotal, o.tax_amount, o.total_amount, COALESCE(o.payment_method,''),
		       COALESCE(o.closed_at,''), o.created_at, o.updated_at,
		       COALESCE(o.order_code,''), COALESCE(o.branch_id,''), COALESCE(o.brand_id,''),
		       COALESCE(o.opened_at, o.created_at), COALESCE(o.voided_at,''), COALESCE(o.void_reason,'')
		FROM orders o
		LEFT JOIN tables t ON t.id = o.table_id
		WHERE o.id = ?`, id)

	o, err := scanHandyOrder(row)
	if err != nil {
		return nil, err
	}

	items, err := s.queryHandyOrderItems(id)
	if err != nil {
		return nil, err
	}
	o["items"] = items
	return o, nil
}

func (s *Server) queryHandyOrderItems(orderID string) ([]map[string]any, error) {
	rows, err := s.db.Query(`
		SELECT oi.id, COALESCE(oi.menu_item_id,''), oi.menu_item_name, oi.quantity,
		       oi.unit_price, oi.subtotal, oi.status,
		       COALESCE(oi.note,''), COALESCE(oi.served_at,''), COALESCE(oi.voided_at,''),
		       COALESCE(oi.void_reason,''), oi.created_at, oi.updated_at,
		       COALESCE(oi.product_sku_id,''), COALESCE(oi.sku_variant_name,'')
		FROM order_items oi
		WHERE oi.customer_order_id = ?
		ORDER BY oi.created_at ASC`, orderID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	items := []map[string]any{}
	for rows.Next() {
		var id, menuItemID, name, status, note, createdAt, updatedAt, productSkuID, skuVariantName string
		var servedAt, voidedAt, voidReason string
		var qty, unitPrice, subtotal int
		if err := rows.Scan(
			&id, &menuItemID, &name, &qty, &unitPrice, &subtotal, &status,
			&note, &servedAt, &voidedAt, &voidReason, &createdAt, &updatedAt,
			&productSkuID, &skuVariantName,
		); err != nil {
			return nil, err
		}
		// product_sku_id is the canonical ID used by handy; fall back to menu_item_id for
		// legacy items created before migration 007 populated product_sku_id.
		skuID := productSkuID
		if skuID == "" {
			skuID = menuItemID
		}
		var servedAtVal, voidedAtVal, voidReasonVal any
		if servedAt != "" {
			servedAtVal = servedAt
		}
		if voidedAt != "" {
			voidedAtVal = voidedAt
		}
		if voidReason != "" {
			voidReasonVal = voidReason
		}
		toppings, err := s.queryHandyItemToppings(id)
		if err != nil {
			return nil, err
		}
		toppingSubtotal := 0
		for _, t := range toppings {
			if up, ok := t["unit_price"].(int); ok {
				if q, ok2 := t["quantity"].(int); ok2 {
					toppingSubtotal += up * q
				}
			}
		}
		var skuVariantVal any
		if skuVariantName != "" {
			skuVariantVal = skuVariantName
		}
		items = append(items, map[string]any{
			"id":                id,
			"customer_order_id": orderID,
			"product_sku_id":    skuID,
			"quantity":          qty,
			"unit_price":        unitPrice,
			"topping_subtotal":  toppingSubtotal,
			"subtotal":          subtotal,
			"status":            status,
			"note":              note,
			"served_at":         servedAtVal,
			"voided_at":         voidedAtVal,
			"void_reason":       voidReasonVal,
			"created_at":        createdAt,
			"updated_at":        updatedAt,
			"toppings":          toppings,
			"product_sku": map[string]any{
				"id":            skuID,
				"name":          skuVariantVal,
				"sku":           nil,
				"selling_price": unitPrice,
				"is_active":     true,
				"product": map[string]any{
					"id":   menuItemID,
					"name": name,
				},
			},
		})
	}
	return items, nil
}

func (s *Server) queryHandyItemToppings(itemID string) ([]map[string]any, error) {
	rows, err := s.db.Query(`
		SELECT id, order_item_id, topping_group_item_id, product_sku_id,
			COALESCE(name,''), modifier_type,
			COALESCE(topping_group_id,''), COALESCE(topping_group_name,''),
			quantity, unit_price, COALESCE(note,'')
		FROM order_item_toppings WHERE order_item_id = ? ORDER BY rowid
	`, itemID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	out := []map[string]any{}
	for rows.Next() {
		var id, orderItemID, toppingGroupItemID, productSkuID string
		var name, modifierType, toppingGroupID, toppingGroupName, note string
		var qty, unitPrice int
		if err := rows.Scan(
			&id, &orderItemID, &toppingGroupItemID, &productSkuID,
			&name, &modifierType, &toppingGroupID, &toppingGroupName,
			&qty, &unitPrice, &note,
		); err != nil {
			return nil, err
		}
		toNullable := func(s string) any {
			if s == "" {
				return nil
			}
			return s
		}
		out = append(out, map[string]any{
			"id":                    id,
			"topping_group_item_id": toppingGroupItemID,
			"product_sku_id":        productSkuID,
			"name":                  toNullable(name),
			"modifier_type":         modifierType,
			"topping_group_id":      toNullable(toppingGroupID),
			"topping_group_name":    toNullable(toppingGroupName),
			"quantity":              qty,
			"unit_price":            fmt.Sprintf("%d", unitPrice),
			"note":                  toNullable(note),
		})
	}
	return out, rows.Err()
}

func (s *Server) scanHandyOrders(rows *sql.Rows) ([]map[string]any, error) {
	out := []map[string]any{}
	for rows.Next() {
		var id, cloudID, tableNumber, status, notes, paymentMethod, paidAt, createdAt, updatedAt string
		var orderCode, branchID, brandID string
		var openedAt, voidedAt, voidReason string
		var orderNumber, customerCount, subtotal, tax, total int
		if err := rows.Scan(&id, &cloudID, &orderNumber, &tableNumber, &status,
			&customerCount, &notes, &subtotal, &tax, &total, &paymentMethod, &paidAt,
			&createdAt, &updatedAt, &orderCode, &branchID, &brandID,
			&openedAt, &voidedAt, &voidReason); err != nil {
			return nil, err
		}
		out = append(out, buildHandyOrderMap(id, cloudID, orderNumber, orderCode, tableNumber, status,
			customerCount, notes, subtotal, tax, total, paymentMethod, paidAt,
			createdAt, updatedAt, openedAt, voidedAt, voidReason, branchID, brandID))
	}
	return out, rows.Err()
}

func scanHandyOrder(row *sql.Row) (map[string]any, error) {
	var id, cloudID, tableNumber, status, notes, paymentMethod, paidAt, createdAt, updatedAt string
	var orderCode, branchID, brandID string
	var openedAt, voidedAt, voidReason string
	var orderNumber, customerCount, subtotal, tax, total int
	if err := row.Scan(&id, &cloudID, &orderNumber, &tableNumber, &status,
		&customerCount, &notes, &subtotal, &tax, &total, &paymentMethod, &paidAt,
		&createdAt, &updatedAt, &orderCode, &branchID, &brandID,
		&openedAt, &voidedAt, &voidReason); err != nil {
		return nil, err
	}
	return buildHandyOrderMap(id, cloudID, orderNumber, orderCode, tableNumber, status,
		customerCount, notes, subtotal, tax, total, paymentMethod, paidAt,
		createdAt, updatedAt, openedAt, voidedAt, voidReason, branchID, brandID), nil
}

func buildHandyOrderMap(id, cloudID string, orderNumber int, orderCode, tableNumber, status string,
	customerCount int, notes string, subtotal, tax, total int,
	paymentMethod, paidAt, createdAt, updatedAt string,
	openedAt, voidedAt, voidReason string,
	branchID, brandID string) map[string]any {

	// Fallback order_code for orders created before LocalCodeGenerator.
	code := orderCode
	if code == "" {
		code = fmt.Sprintf("WS-%04d", orderNumber)
	}

	orderType := "spot"
	if tableNumber != "" {
		orderType = "dine_in"
	}

	var closedAtVal any
	if paidAt != "" {
		closedAtVal = paidAt
	}
	var voidedAtVal any
	if voidedAt != "" {
		voidedAtVal = voidedAt
	}
	var voidReasonVal any
	if voidReason != "" {
		voidReasonVal = voidReason
	}

	remaining := total - subtotal
	if remaining < 0 {
		remaining = 0
	}

	m := map[string]any{
		"id":               id,
		"order_code":       code,
		"order_type":       orderType,
		"status":           status,
		"subtotal":         subtotal,
		"discount_amount":  0,
		"service_charge":   0,
		"tax_amount":       tax,
		"total_amount":     total,
		"paid_amount":      0,
		"total_tip":        0,
		"remaining_amount": fmt.Sprintf("%d", remaining),
		"opened_at":        openedAt,
		"checkout_at":      nil,
		"closed_at":        closedAtVal,
		"voided_at":        voidedAtVal,
		"void_reason":      voidReasonVal,
		"guest_count":      customerCount,
		"note":             notes,
		"branch_id":        branchID,
		"brand_id":         brandID,
		"created_at":       createdAt,
		"updated_at":       updatedAt,
		"deleted_at":       nil,
	}

	// tables[] — shape matches TableSummary (id, code, name, status, qr_token).
	if tableNumber != "" {
		m["tables"] = []map[string]any{
			{
				"id":       tableNumber,
				"code":     tableNumber,
				"name":     tableNumber,
				"status":   "occupied",
				"qr_token": "",
			},
		}
	} else {
		m["tables"] = []any{}
	}

	if cloudID != "" {
		m["cloud_id"] = cloudID
	}
	if paymentMethod != "" {
		m["payment_method"] = paymentMethod
	}
	return m
}

// recalcOrderTotals recomputes subtotal/tax_amount/total_amount from order_items.
//
// Prices are tax-INCLUDED (Japan standard): the line sum (subtotal) already
// contains the consumption tax, so it IS the gross total — tax is derived back
// OUT of it, never added on top:
//
//	total_amount = subtotal
//	tax_amount   = total - round(total / (1 + rate/100))
//
// tax_rate from shop_settings is a PERCENT decimal string ("10.00"), so we
// divide by 100. Empty/unparseable falls back to 10% (same default the print
// layer uses via fallbackTaxRate).
func (s *Server) recalcOrderTotals(orderID string) error {
	// Delegate to the plan-043 per-rate engine when it's wired (production + most
	// tests): per-rate order totals + allocated per-line tax_amount, so a handy
	// qty/note/status edit stays consistent with the POS/Cloud engine and the
	// per-line snapshots reconcile on reports. The legacy single-rate body below
	// runs only when the engine is absent (bare-Server handler unit tests).
	if s.orders != nil {
		return s.orders.RecalcOrderTotals(orderID)
	}

	var taxRateStr string
	_ = s.db.QueryRow(`SELECT value FROM shop_settings WHERE key = 'tax_rate'`).Scan(&taxRateStr)
	taxRate := 0.0
	fmt.Sscanf(taxRateStr, "%f", &taxRate)
	if taxRate <= 0 {
		taxRate = 10.0
	}
	factor := 1 + taxRate/100 // e.g. 1.10 for 10%

	_, err := s.db.Exec(`
		UPDATE orders SET
			subtotal = (
				SELECT COALESCE(SUM(quantity * unit_price), 0)
				FROM order_items
				WHERE customer_order_id = orders.id AND status != 'voided'
			),
			total_amount = (
				SELECT COALESCE(SUM(quantity * unit_price), 0)
				FROM order_items
				WHERE customer_order_id = orders.id AND status != 'voided'
			),
			tax_amount = (
				SELECT COALESCE(SUM(quantity * unit_price), 0)
				FROM order_items
				WHERE customer_order_id = orders.id AND status != 'voided'
			) - CAST(ROUND(
				(SELECT COALESCE(SUM(quantity * unit_price), 0)
				 FROM order_items
				 WHERE customer_order_id = orders.id AND status != 'voided') / ?
			) AS INTEGER),
			updated_at = datetime('now')
		WHERE id = ?`, factor, orderID)
	return err
}

func (s *Server) requireHandyItemsMutable(orderID string) error {
	o, err := s.orders.GetByID(orderID)
	if err != nil {
		return err
	}
	if !service.OrderItemsMutable(o.Status) {
		return fmt.Errorf("%w: cannot change items while order status is %q", service.ErrOrderNotOpen, o.Status)
	}
	return nil
}

// handleLocalHandyCreatePayment — #876, settle an order at the table from the
// Handy device on the LAN, mirroring Cloud's POST /handy/orders/{order}/payments.
//
// Gated by the per-shop `handy_allow_direct_payment` toggle mirrored into
// shop_settings by the branch pull (default absent/false → 403, so a stale
// app build cannot collect). Past the gate it delegates to the SAME LAN POS
// payment core every cashier tender uses — open-shift gate, overpay guard,
// auto-confirm close, sync UP — by rewriting the mux path value; the POS
// handler reads only the order id, body, and shop state, never the caller's
// auth identity.
func (s *Server) handleLocalHandyCreatePayment(w http.ResponseWriter, r *http.Request) {
	enabled := s.shopSetting("handy_allow_direct_payment", "false")
	if enabled != "true" && enabled != "1" {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusForbidden)
		_ = json.NewEncoder(w).Encode(map[string]any{
			"message": "Direct payment on Handy is disabled for this shop.",
			"code":    "HANDY_PAYMENT_DISABLED",
		})
		return
	}

	r.SetPathValue("id", r.PathValue("order"))
	s.handleLocalPosCreatePayment(w, r)
}
