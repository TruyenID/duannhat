package handler

import (
	"database/sql"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/domain"
	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/google/uuid"
)

// Local POS endpoints — mirror /kiosk/* pattern but accept SSO user tokens
// instead of device tokens. Workstation reads/writes local SQLite (same tables
// as kiosk endpoints) and enqueues sync UP to Cloud.

// GET /api/v1/pos/me
//
// Returns the authenticated user (SSO) or device's info from request context.
// Workstation acts as gateway — Cloud is the source of truth, this is just a
// fast-path read of the cached identity.
func (s *Server) handleLocalPosMe(w http.ResponseWriter, r *http.Request) {
	d, ok := DeviceFromContext(r.Context())
	if !ok {
		writeError(w, http.StatusUnauthorized, "not authenticated")
		return
	}

	resp := map[string]any{
		"identity_type": d.IdentityType,
		"branch_id":     d.BranchID,
	}
	if d.IdentityType == domain.IdentityTypeUser {
		resp["user"] = map[string]any{
			"id":    d.ID,
			"name":  d.UserName,
			"email": d.UserEmail,
		}
	} else {
		resp["device"] = map[string]any{
			"id":   d.ID,
			"type": d.Type,
		}
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": resp})
}

// GET /api/v1/pos/menus
//
// Returns the local menu_items table (synced DOWN from Cloud by sync_pull).
// Workstation is the local replica — POS reads here, not directly from Cloud.
func (s *Server) handleLocalPosMenus(w http.ResponseWriter, r *http.Request) {
	rows, err := s.db.Query(`
		SELECT id, COALESCE(category, ''), name, COALESCE(name_ja, ''),
		       price, COALESCE(printer_group, ''), is_active,
		       COALESCE(local_updated_at, '')
		FROM menu_items
		WHERE is_active = 1
		ORDER BY category, name`)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	defer rows.Close()

	type menuItem struct {
		ID           string `json:"id"`
		Category     string `json:"category,omitempty"`
		Name         string `json:"name"`
		NameJA       string `json:"name_ja,omitempty"`
		Price        int    `json:"price"`
		PrinterGroup string `json:"printer_group,omitempty"`
		IsActive     bool   `json:"is_active"`
		UpdatedAt    string `json:"updated_at,omitempty"`
	}

	items := []menuItem{}
	for rows.Next() {
		var m menuItem
		var active int
		if err := rows.Scan(&m.ID, &m.Category, &m.Name, &m.NameJA,
			&m.Price, &m.PrinterGroup, &active, &m.UpdatedAt); err != nil {
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}
		m.IsActive = active == 1
		items = append(items, m)
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": items})
}

// GET /api/v1/pos/tables
//
// Returns tables in Cloud's TableResource shape so pos-web's CreateOrderDialog
// + BindTableDialog can group by `table.zone.id` and render properly.
// Specifically:
//   - The locally-stored `tables.name` column holds the table CODE (e.g.
//     "B-03") because the original schema had no separate code field; we
//     surface it as `code` to match Cloud and leave `name` null.
//   - `zone` is emitted as a NESTED object {id, code, name} rather than
//     flat zone_id/zone_name — pos-web reads `table.zone.id` directly.
//   - `seat_count` mirrors Cloud's column name (workstation stores it as
//     `capacity`).
//   - `current_order_id` is joined from local orders so pos-web's
//     TablesOverview can colour-code occupied tables.
//   - `is_active` defaults to true; workstation doesn't yet track soft
//     delete for tables — once it does, expose the real flag.
//   - Wraps in the paginated envelope (per_page huge) so the
//     PaginatedResponse<TableResource> consumer in shop-menu hooks works
//     without branching on whether the response was paginated or not.
func (s *Server) handleLocalPosTables(w http.ResponseWriter, r *http.Request) {
	// current_order_id derived from order_tables pivot so secondary
	// tables in a merged / multi-table order also surface as occupied.
	// Without the pivot join only the order's primary table (the one
	// written to orders.table_id) would render with a current_order_id
	// — the other rows would look free to the cashier and could be
	// grabbed by a second open. The OR clause keeps the legacy single-
	// table case working when order_tables hasn't been populated yet
	// (rows created before the pivot landed).
	rows, err := s.db.Query(`
		SELECT t.id,
		       COALESCE(t.zone_id, ''),
		       t.name,
		       COALESCE(t.qr_token, ''),
		       COALESCE(t.status, 'free'),
		       COALESCE(t.capacity, 0),
		       COALESCE(z.name, ''),
		       (SELECT o.id FROM orders o
		         LEFT JOIN order_tables ot ON ot.order_id = o.id
		         WHERE (ot.table_id = t.id OR o.table_id = t.id)
		           AND o.status NOT IN ` + service.SQLStatusTerminal + `
		         ORDER BY o.opened_at DESC LIMIT 1) AS current_order_id
		FROM tables t
		LEFT JOIN zones z ON z.id = t.zone_id
		ORDER BY COALESCE(z.sort_order, 999), t.name`)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	defer rows.Close()

	type tableZone struct {
		ID   string `json:"id"`
		Code string `json:"code"`
		Name string `json:"name"`
	}
	type table struct {
		ID             string     `json:"id"`
		Code           string     `json:"code"`
		Name           *string    `json:"name"`
		SeatCount      int        `json:"seat_count"`
		Status         string     `json:"status"`
		IsActive       bool       `json:"is_active"`
		QrToken        string     `json:"qr_token"`
		CurrentOrderID *string    `json:"current_order_id"`
		Zone           *tableZone `json:"zone,omitempty"`
		CreatedAt      *string    `json:"created_at"`
		UpdatedAt      *string    `json:"updated_at"`
		DeletedAt      *string    `json:"deleted_at"`
	}

	out := []table{}
	for rows.Next() {
		var (
			id, zoneID, codeName, qrToken, status, zoneName string
			capacity                                        int
			currentOrderID                                  sql.NullString
		)
		if err := rows.Scan(&id, &zoneID, &codeName, &qrToken, &status,
			&capacity, &zoneName, &currentOrderID); err != nil {
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}
		t := table{
			ID:        id,
			Code:      codeName, // locally stored as `name`, semantically the code
			Name:      nil,      // workstation has no separate name; null matches Cloud's nullable column
			SeatCount: capacity,
			Status:    status,
			IsActive:  true,
			QrToken:   qrToken,
		}
		if currentOrderID.Valid && currentOrderID.String != "" {
			s := currentOrderID.String
			t.CurrentOrderID = &s
			// Override the synced status until Cloud catches up. The
			// `tables` row is wiped + re-inserted every 5 s from
			// Cloud's view (PullTables), so a LAN-only order whose
			// sync UP hasn't completed leaves the row still tagged
			// `available` — pos-web would render the table free even
			// though there's an active local order bound to it.
			// Surfacing `occupied` from the pivot keeps the UI honest
			// across the sync window.
			t.Status = "occupied"
		}
		if zoneID != "" {
			// Zones schema lacks a `code` column; fall back to the name so the
			// nested shape stays valid (pos-web doesn't crash on equal code/name).
			t.Zone = &tableZone{ID: zoneID, Code: zoneName, Name: zoneName}
		}
		out = append(out, t)
	}

	// Wrap in the Laravel paginator envelope shape so consumers that read
	// `.meta.total` (e.g. table-service's PaginatedResponse) keep working.
	writeJSON(w, http.StatusOK, map[string]any{
		"data": out,
		"meta": map[string]any{
			"current_page": 1,
			"last_page":    1,
			"per_page":     len(out),
			"total":        len(out),
		},
	})
}

// validTableStatus mirrors backend TableStatusEnum — the whitelist a LAN table
// status change is allowed to set.
func validTableStatus(status string) bool {
	switch status {
	case "free", "occupied", "reserved", "cleaning", "out_of_service":
		return true
	}
	return false
}

// POST /api/v1/pos/tables/{table}/status
//
// LAN-mode table status change. Updates the local `tables` mirror
// optimistically, enqueues a `table.status` sync op, and WAKES the sync engine
// so the change reaches Cloud immediately (not on the next tick). Cloud is the
// source of truth (TMS + customer apps read it); the sync op adopts Cloud's
// returned status back onto the mirror, and PullTables preserves this row's
// status while the op is unsynced so the periodic pull-DOWN can't revert it.
func (s *Server) handleLocalPosTableStatus(w http.ResponseWriter, r *http.Request) {
	tableID := r.PathValue("table")
	if tableID == "" {
		writeError(w, http.StatusBadRequest, "table id required")
		return
	}
	var body struct {
		Status string `json:"status"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, "invalid body")
		return
	}
	body.Status = strings.TrimSpace(body.Status)
	if !validTableStatus(body.Status) {
		writeError(w, http.StatusUnprocessableEntity, "invalid status")
		return
	}

	res, err := s.db.Exec(
		`UPDATE tables SET status = ?, local_synced_at = datetime('now') WHERE id = ?`,
		body.Status, tableID)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	if n, _ := res.RowsAffected(); n == 0 {
		writeError(w, http.StatusNotFound, "table not found")
		return
	}

	// Immediate sync UP to Cloud — enqueue THEN wake so the drain the wake
	// schedules sees the row. Enqueue failure is non-fatal: the local mirror
	// already reflects the change and the sync reconcile retries.
	if s.sync != nil {
		if err := s.sync.Enqueue("table", tableID, "status", map[string]any{"status": body.Status}, 2); err != nil {
			slog.Warn("enqueue table.status failed (non-fatal)", "table", tableID, "err", err)
		} else {
			s.sync.Wake()
		}
	}

	// Nudge other LAN clients (pos-web tabs, KDS) to refresh their table map.
	if s.hub != nil {
		s.hub.BroadcastEvent("table_status_changed", map[string]any{"id": tableID, "status": body.Status})
	}

	shape := s.posTableShape(tableID)
	if shape == nil {
		shape = map[string]any{"id": tableID, "status": body.Status}
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": shape})
}

// posTableShape returns one table row in Cloud's TableResource shape (the
// single-row twin of handleLocalPosTables). nil when the id is unknown.
func (s *Server) posTableShape(id string) map[string]any {
	var (
		zoneID, codeName, qrToken, status, zoneName string
		capacity                                    int
		currentOrderID                              sql.NullString
	)
	err := s.db.QueryRow(`
		SELECT COALESCE(t.zone_id, ''), t.name, COALESCE(t.qr_token, ''),
		       COALESCE(t.status, 'free'), COALESCE(t.capacity, 0), COALESCE(z.name, ''),
		       (SELECT o.id FROM orders o
		         LEFT JOIN order_tables ot ON ot.order_id = o.id
		         WHERE (ot.table_id = t.id OR o.table_id = t.id)
		           AND o.status NOT IN `+service.SQLStatusTerminal+`
		         ORDER BY o.opened_at DESC LIMIT 1)
		FROM tables t
		LEFT JOIN zones z ON z.id = t.zone_id
		WHERE t.id = ?`, id).Scan(&zoneID, &codeName, &qrToken, &status, &capacity, &zoneName, &currentOrderID)
	if err != nil {
		return nil
	}
	out := map[string]any{
		"id":               id,
		"code":             codeName,
		"name":             nil,
		"seat_count":       capacity,
		"status":           status,
		"is_active":        true,
		"qr_token":         qrToken,
		"current_order_id": nil,
		"created_at":       nil,
		"updated_at":       nil,
		"deleted_at":       nil,
	}
	if currentOrderID.Valid && currentOrderID.String != "" {
		out["current_order_id"] = currentOrderID.String
		out["status"] = "occupied" // derived override — matches the list endpoint
	}
	if zoneID != "" {
		out["zone"] = map[string]any{"id": zoneID, "code": zoneName, "name": zoneName}
	}
	return out
}

// GET /api/v1/pos/orders
//
// Mirrors Cloud's CustomerOrderController::index — paginated list of
// orders in the active set (open/dining/checkout/paying by default), with
// status / order_type / customer_id / search / date_from / date_to / sort
// filters that pos-web's PosTabBar relies on. Each row is run through
// `customerOrderShape` so the response is byte-compatible with what
// pos-web parses out of the cloud endpoint. Items[] is intentionally
// omitted on the list view (pos-web's CustomerOrderItem[] is optional and
// the list never iterates it — saves N+1 queries on every tab refresh).
func (s *Server) handleLocalPosOrders(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	filters := service.ListFilters{
		// Scope to the paired branch — the local DB may hold other branches'
		// orders (dev seed / kept across re-pair), and without this the
		// overview + takeaway feed would show orders that don't belong to this
		// shop (Cloud scopes the same list by shop slug).
		BranchID:   s.workstationBranchID(),
		OrderType:  strings.TrimSpace(q.Get("order_type")),
		TableID:    strings.TrimSpace(q.Get("table_id")),
		CustomerID: strings.TrimSpace(q.Get("customer_id")),
		Search:     strings.TrimSpace(q.Get("search")),
		DateFrom:   strings.TrimSpace(q.Get("date_from")),
		DateTo:     strings.TrimSpace(q.Get("date_to")),
		Sort:       strings.TrimSpace(q.Get("sort")),
	}
	if raw := strings.TrimSpace(q.Get("status")); raw != "" {
		for _, st := range strings.Split(raw, ",") {
			if st = strings.TrimSpace(st); st != "" {
				filters.Statuses = append(filters.Statuses, st)
			}
		}
	}
	if pageStr := q.Get("page"); pageStr != "" {
		if v, err := strconv.Atoi(pageStr); err == nil {
			filters.Page = v
		}
	}
	if perPageStr := q.Get("per_page"); perPageStr != "" {
		if v, err := strconv.Atoi(perPageStr); err == nil {
			filters.PerPage = v
		}
	}
	if filters.Page < 1 {
		filters.Page = 1
	}
	if filters.PerPage < 1 {
		filters.PerPage = 20
	}

	orders, total, err := s.orders.ListByFilters(filters)
	if err != nil {
		writeServerError(w, r, err)
		return
	}

	locale := localeFromRequest(r)
	batch := s.loadOrderShapeBatch(orders, locale)
	shaped := make([]map[string]any, 0, len(orders))
	for i := range orders {
		// Pass-by-address so customerOrderShape can read without copying
		// the struct; Items is already empty here because ListByFilters
		// skips item hydration on the list view. shapeOrderForResponse
		// applies the same image-URL rewrite the detail endpoints do,
		// so the list-view thumbnails (if any) follow the LAN cache.
		shaped = append(shaped, s.shapeOrderForResponseWithBatch(r, &orders[i], batch))
	}
	lastPage := (total + filters.PerPage - 1) / filters.PerPage
	if lastPage < 1 {
		lastPage = 1
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"data": shaped,
		"meta": map[string]any{
			"current_page": filters.Page,
			"last_page":    lastPage,
			"per_page":     filters.PerPage,
			"total":        total,
		},
	})
}

// GET /api/v1/pos/orders/{id}
func (s *Server) handleLocalPosGetOrder(w http.ResponseWriter, r *http.Request) {
	o, err := s.orders.GetByID(r.PathValue("id"))
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": s.shapeOrderForResponse(r, o)})
}

// POST /api/v1/pos/orders
//
// Body: { table_id?, order_type?, guest_count, note?, items: [{menu_item_id, quantity, note?}] }
//
// Creates a new order in local SQLite + enqueues sync UP. Workstation is the
// source of truth for order id + order_code (offline-safe via LocalCodeGenerator).
// writeNoOpenShift emits the 409 NO_OPEN_SHIFT body used by the LAN POS gate —
// byte-parity with Cloud's ResolveOpenTillSession middleware so pos-web renders
// the same "open a shift first" message whether it hit LAN or Cloud (plan-044
// DESIGN Decision 6).
func writeNoOpenShift(w http.ResponseWriter) {
	writeJSON(w, http.StatusConflict, map[string]any{
		"message": "No cashier shift is currently open on this till.",
		"code":    "NO_OPEN_SHIFT",
	})
}

func (s *Server) handleLocalPosCreateOrder(w http.ResponseWriter, r *http.Request) {
	// plan-044 (R1, kept in R2 — DESIGN Decision 6) — LAN POS order creation is
	// gated on an in-progress shift. Kiosk/customer/handy surfaces stay ungated
	// (self-service can transact during the close→open gap; those become gap
	// payments reconciled at the next open).
	if !s.hasInProgressShift() {
		writeNoOpenShift(w)
		return
	}
	var input service.CreateOrderInput
	if err := readJSON(r, &input); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	// POS is a staff-driven counter: every order it creates is confirmed on
	// the spot, so takeaway opens directly instead of sitting in `pending`
	// (which is for self-service kiosk / customer orders a staff member must
	// accept). dine_in / spot are already `open`, so this is a no-op for them.
	input.Status = string(service.StatusOpen)
	o, err := s.orders.Create(input, s.codeGen)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	s.hub.BroadcastEvent("order_created", o)
	s.auditLogPOS(r, "order.create", "order", o.ID, fmt.Sprintf(`{"total":%d}`, o.TotalAmount))

	if s.sync != nil {
		// plan-041 — Cloud is the single authority for the gapless ORD-####
		// code; it is minted at insert, NOT sent from here. We send
		// client_order_id (this order's local UUID) as the durable idempotency
		// key so a re-sync (restart re-enqueue) maps to the same Cloud order
		// without minting a second number. The local o.OrderCode stays as the
		// provisional value until the sync response is reconciled back in.
		orderShape := map[string]any{
			"client_order_id": o.ID,
			"order_type":      o.OrderType,
			// Send the local status so Cloud creates the order with the SAME
			// state — a POS takeaway is `open`, not the self-service `pending`
			// default. Backend honors an explicit status (it only defaults when
			// absent), so this keeps local + Cloud from diverging on checkout.
			"status":                  string(o.Status),
			"guest_count":             o.GuestCount,
			"note":                    o.Note,
			"customer_takeaway_name":  o.CustomerTakeawayName,
			"customer_takeaway_phone": o.CustomerTakeawayPhone,
		}
		if o.CustomerID != "" {
			orderShape["customer_id"] = o.CustomerID
			// Cloud can't resolve a workstation-minted local customer UUID — it
			// has never seen it (LAN /pos/customers/find-or-create mints the id
			// locally). Send the linked customer's phone so Cloud's
			// /workstation/orders can find-or-create the canonical customer
			// server-side and link THAT, instead of 422-ing on
			// `exists:customers,id` and dead-lettering the whole order.
			if phone := s.customerPhoneByID(o.CustomerID); phone != "" {
				orderShape["customer_phone"] = phone
			}
		}
		// Resolve the full table set from the pivot — orders.table_id
		// only holds the primary. Sync UP needs every binding so
		// Cloud's table_ids[] write hits the same physical rows the
		// LAN-side cashier picked.
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
			"bearer_token":    s.GetDeviceToken(), // workstation's own token for /workstation/orders
			"idempotency_key": uuid.NewString(),
			"order":           orderShape,
		}
		if err := s.sync.Enqueue("order", o.ID, "create", syncPayload, 1); err != nil {
			s.auditLogPOS(r, "order.sync_enqueue_failed", "order", o.ID,
				fmt.Sprintf(`{"err":%q}`, err.Error()))
		} else {
			// LAN-mode UX: push the new order to Cloud immediately instead of
			// waiting up to 5s for the next sync tick. Non-blocking — if the
			// workstation is offline the row stays queued for the next online
			// tick (Wake's online gate + the queue's retry machinery cover it).
			s.sync.Wake()
		}
	}

	writeJSON(w, http.StatusCreated, map[string]any{"data": s.shapeOrderForResponse(r, o)})
}

// loadOrderTableIDs returns the table_ids bound to an order via the
// order_tables pivot in sort_order then bound_at order, matching the
// shape Cloud expects in POST /api/v1/workstation/orders.table_ids.
func (s *Server) loadOrderTableIDs(orderID string) ([]string, error) {
	rows, err := s.db.Query(`
		SELECT table_id FROM order_tables
		WHERE order_id = ?
		ORDER BY sort_order, bound_at`, orderID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []string{}
	for rows.Next() {
		var id string
		if err := rows.Scan(&id); err != nil {
			return nil, err
		}
		out = append(out, id)
	}
	return out, rows.Err()
}

// POST /api/v1/pos/orders/{id}/items
//
// Body: { items: [{menu_item_id, quantity, note?}] }
//
// Appends items to an existing order. Returns the full updated order so the
// client doesn't need a separate refetch (matches pos-web's optimistic flow).
func (s *Server) handleLocalPosAddItems(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
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
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	o, err := s.orders.GetByID(id)
	if err != nil {
		writeError(w, http.StatusNotFound, "order not found")
		return
	}
	s.hub.BroadcastEvent("order_updated", o)
	// Sync UP — pre-fix this handler wrote SQLite + broadcast over the WS hub
	// but never enqueued, so Cloud (and therefore shop/HQ order views) never
	// learned about items added in LAN mode. Enqueue an order.item_add carrying
	// the touched line IDs; the sync worker re-reads each line's current state
	// (a BR-OI06 merge may have bumped an existing line rather than inserted a
	// new one) and POSTs to /api/v1/workstation/orders/{cloud_id}/items. The
	// enqueue Wakes the worker so it drains immediately instead of on the 5s
	// tick — items land on Cloud right after the tap. Depends on the order's
	// own create having synced first (needs cloud_id); the worker retries
	// transiently until it has.
	itemIDs := make([]string, 0, len(created))
	for _, it := range created {
		itemIDs = append(itemIDs, it.ID)
	}
	// Dedup against lines already sitting in an unsynced item_add row so a
	// resend / double-tap doesn't stack another row that fails in lockstep.
	if s.sync != nil {
		itemIDs = s.sync.FilterNewItemAddIDs(id, itemIDs)
	}
	if len(itemIDs) > 0 {
		s.enqueueOrderSync("order.item_add", id, map[string]any{"item_ids": itemIDs})
	}
	s.auditLogPOS(r, "order.add_items", "order", id, fmt.Sprintf(`{"count":%d}`, len(body.Items)))
	writeJSON(w, http.StatusOK, map[string]any{"data": s.shapeOrderForResponse(r, o)})
}

// POST /api/v1/pos/orders/{id}/confirm
//
// Accepts a customer-submitted takeaway order (pending|confirmed → open) so
// it can flow through the regular checkout pipeline — the POS "Tiếp nhận
// đơn" button. Mirrors Cloud's Shop confirm endpoint, with one deliberate
// difference: an already-open order responds 200 (idempotent) instead of
// 409, so two terminals racing on the same accept both land on the goal
// state without surfacing an error.
func (s *Server) handleLocalPosConfirmOrder(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	o, err := s.orders.GetByID(id)
	if err != nil {
		writeError(w, http.StatusNotFound, "order not found")
		return
	}

	switch o.Status {
	case service.StatusOpen:
		// Already accepted (possibly by another terminal) — the intent is
		// satisfied; no write, no sync, no broadcast.
		writeJSON(w, http.StatusOK, map[string]any{"data": s.shapeOrderForResponse(r, o)})
		return
	case service.StatusPending, service.StatusConfirmed:
		// fall through to the transition below
	default:
		writeError(w, http.StatusConflict,
			"only a pending or confirmed order can be accepted")
		return
	}

	if err := s.orders.UpdateStatus(id, service.StatusOpen); err != nil {
		writeError(w, http.StatusConflict, err.Error())
		return
	}
	o, err = s.orders.GetByID(id)
	if err != nil {
		writeError(w, http.StatusNotFound, "order not found")
		return
	}
	s.hub.BroadcastEvent("order_updated", o)
	// Sync UP — POST /api/v1/workstation/orders/{cloud_id}/confirm. The Cloud
	// endpoint is idempotent by status (any non-confirmable status is a 200
	// no-op), so a queue retry after later transitions can never dead-letter.
	s.enqueueOrderSync("order.confirm", id, nil)
	s.auditLogPOS(r, "order.confirm", "order", id, "")
	writeJSON(w, http.StatusOK, map[string]any{"data": s.shapeOrderForResponse(r, o)})
}

// POST /api/v1/pos/orders/{id}/checkout
//
// Transitions an open/dining order to checkout state. POS uses this before
// triggering split-bill calculator or accepting payment.
func (s *Server) handleLocalPosCheckout(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if err := s.orders.UpdateStatus(id, service.StatusCheckout); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	o, err := s.orders.GetByID(id)
	if err != nil {
		writeError(w, http.StatusNotFound, "order not found")
		return
	}
	s.hub.BroadcastEvent("order_updated", o)
	// Sync UP — pre-fix this handler only audited locally + broadcast
	// over the WS hub but never enqueued, so Cloud never learned about
	// the state transition. handleOrderCheckout in sync_service.go was
	// effectively dead code. Enqueue now so the catch-all 5s worker
	// drains it to POST /api/v1/workstation/orders/{id}/checkout.
	s.enqueueOrderSync("order.checkout", id, nil)
	s.auditLogPOS(r, "order.checkout", "order", id, "")
	writeJSON(w, http.StatusOK, map[string]any{"data": s.shapeOrderForResponse(r, o)})
}

// POST /api/v1/pos/orders/{id}/payments
//
// Header: Idempotency-Key: <uuid>
// Body:   { payment_method, amount, terminal_response? }
//
// Order id is taken from the URL path to match Cloud's contract; pos-web does
// not send order_id in the body. The sync UP payload carries the workstation's
// device token (not the user's SSO token) because Cloud's
// POST /api/v1/workstation/payments is gated by device.auth:workstation.
func (s *Server) handleLocalPosCreatePayment(w http.ResponseWriter, r *http.Request) {
	orderID := r.PathValue("id")
	if orderID == "" {
		writeError(w, http.StatusBadRequest, "order id required in path")
		return
	}

	// plan-044 (R1, kept in R2 — DESIGN Decision 6) — LAN POS payment creation is
	// gated on an in-progress shift (parity with the order gate above). The kiosk
	// payment route (handleLocalCreatePayment) is intentionally NOT gated.
	if !s.hasInProgressShift() {
		writeNoOpenShift(w)
		return
	}

	// Read body FIRST so we can fall back to the body's
	// `idempotency_key` field when the HTTP header is absent. Pos-web's
	// useMutation hooks (see pos-web/CLAUDE.md §Idempotency) embed the
	// key inside the JSON payload rather than as the canonical
	// `Idempotency-Key` header; Cloud has always accepted both shapes,
	// so workstation does too — otherwise PaymentDialog surfaces
	// "Idempotency-Key header required" and staff retry-loops the
	// payment.
	var input domain.CreatePaymentInput
	if err := readJSON(r, &input); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}

	idempKey := r.Header.Get("Idempotency-Key")
	if idempKey == "" {
		idempKey = input.IdempotencyKey
	}
	if idempKey == "" {
		writeError(w, http.StatusBadRequest,
			"Idempotency-Key required (as HTTP header or `idempotency_key` body field)")
		return
	}

	if existing, found := s.lookupPaymentByIdempotency(idempKey); found {
		writeJSON(w, http.StatusOK, map[string]any{"data": existing})
		return
	}
	if input.OrderID == "" || input.OrderID != orderID {
		input.OrderID = orderID
	}
	if input.Amount <= 0 {
		writeError(w, http.StatusBadRequest, "amount must be positive")
		return
	}

	// Resolve the payment method. Pos-web sends `payment_method_id`
	// (UUID); legacy callers (kiosk fallback) may send the enum string
	// in `payment_method`. Look up the row so we know requires_tendered
	// + is_auto_confirm — those drive the auto-close + change_amount
	// math below. When both are absent we fall back to a "cash" stub so
	// kiosk's existing flow still works.
	method, methodErr := s.resolvePaymentMethod(input.PaymentMethodID, string(input.PaymentMethod))
	if methodErr != nil {
		writeError(w, http.StatusUnprocessableEntity, methodErr.Error())
		return
	}

	// Read just the columns the lifecycle gate needs — querying through
	// OrderEngine would couple this handler to the engine's hydration
	// path (items[] / pivot / etc.) that tests of the payment surface
	// shouldn't have to seed. 404 when the order isn't present.
	var orderStatus string
	var orderTotal int
	if err := s.db.QueryRow(
		`SELECT status, COALESCE(total_amount, 0) FROM orders WHERE id = ?`, orderID,
	).Scan(&orderStatus, &orderTotal); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	// expected_total_amount drift guard (Cloud's split_bill_total_drift
	// 422) — when staff voids an item between the snapshot pos-web took
	// for the split and the POST, the row amount is computed against a
	// stale total and would otherwise silently overpay or short the last
	// row. Reject with 422 so PaymentDialog can re-fetch + recalc.
	if input.ExpectedTotalAmount != nil && *input.ExpectedTotalAmount != orderTotal {
		writeError(w, http.StatusUnprocessableEntity,
			fmt.Sprintf("split_bill_total_drift: expected_total_amount %d != order.total_amount %d",
				*input.ExpectedTotalAmount, orderTotal))
		return
	}

	// Overpayment guard (Cloud parity): pending + succeeded + this
	// amount cannot exceed total_amount. Only fires when total_amount
	// > 0 — legacy tests seed orders without a precomputed total and
	// would otherwise trip this gate.
	existingPaid, err := s.sumActivePaymentsForOrder(orderID)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	if orderTotal > 0 && existingPaid+input.Amount > orderTotal {
		writeError(w, http.StatusUnprocessableEntity,
			"Payment amount exceeds the outstanding order balance.")
		return
	}

	// Cash flow: tendered_amount is mandatory when method requires it,
	// and change_amount derives from `tendered − (amount + tip)`. Cloud
	// throws InvalidArgumentException → 422; we surface the same body.
	var tendered, change *int
	if method.RequiresTendered {
		if input.TenderedAmount == nil ||
			*input.TenderedAmount < input.Amount+input.TipAmount {
			writeError(w, http.StatusUnprocessableEntity,
				"Tendered amount must be provided and must be >= payment amount + tip amount.")
			return
		}
		tendered = input.TenderedAmount
		c := *tendered - input.Amount - input.TipAmount
		change = &c
	}

	now := time.Now().UTC()
	status := domain.PaymentStatusPending
	var paidAt, expiresAt *time.Time
	if method.IsAutoConfirm {
		// Auto-confirm methods (cash, manual card) mark themselves
		// `succeeded` immediately. Terminal-driven methods (Stripe)
		// stay pending and wait for the confirm/fail webhook.
		status = domain.PaymentStatusSucceeded
		t := now
		paidAt = &t
	} else {
		// 15 min window mirrors Cloud's pending lifetime — gives the
		// terminal user enough time to finish without leaving an
		// orphan row open forever.
		t := now.Add(15 * time.Minute)
		expiresAt = &t
	}

	policyIdent := s.resolvePaymentPolicyIdentity(input.ResolvedPaymentOptionID(), idempKey)

	payment := domain.Payment{
		ID:                    uuid.NewString(),
		OrderID:               orderID,
		PaymentMethodID:       method.ID,
		PaymentMethod:         domain.PaymentMethod(method.Code),
		PaymentOptionID:       policyIdent.PaymentOptionID,
		PolicyRevision:        policyIdent.PolicyRevision,
		ConnectionID:          policyIdent.ConnectionID,
		ConnectionOptionID:    policyIdent.ConnectionOptionID,
		AttemptIdempotencyKey: policyIdent.AttemptIdempotencyKey,
		Amount:                input.Amount,
		TipAmount:             input.TipAmount,
		TenderedAmount:        tendered,
		ChangeAmount:          change,
		ReferenceNo:           input.ReferenceNo,
		Note:                  input.Note,
		Status:                status,
		IdempotencyKey:        idempKey,
		TerminalResponse:      input.TerminalResponse,
		// Split-bill metadata: pos-web sends {split_mode, bill_index,
		// total_bills, label?, item_allocations?} so per-bill audits +
		// receipt reprints survive on Cloud. The kiosk path already
		// forwards this; the POS path was missing it before now.
		Metadata: input.MetadataString(),
		PaidAt:   paidAt,
		// #817 Phase B — captured_at is the device-clock attribution key; the
		// open shift (if any) binds this payment to the cashier's drawer for the
		// close manifest + Cloud's captured_at re-attribution of late stragglers.
		CapturedAt:    &now,
		TillSessionID: s.currentTillSessionID(),
		ExpiresAt:     expiresAt,
		CreatedAt:     now,
		UpdatedAt:     now,
	}
	if err := s.insertPayment(payment, "workstation"); err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	// Drive order lifecycle. checkout → paying on first payment;
	// when auto-confirm + paid_amount >= total → close. Cloud does
	// this inside the same transaction; workstation does it post-
	// insert + the sync queue replays the same transitions.
	//
	// `orderJustClosed` tracks whether THIS payment finished the order
	// (so the WS broadcast at the bottom can fire the right event:
	// order_paid kicks the row out of pos-web's open-list cache, but
	// for split-bill partial payments we need order_updated instead so
	// the tab stays alive while staff collects from the remaining
	// guests). Pre-fix the handler fired order_paid on EVERY payment,
	// so pos-web removed the order from its cache after the first
	// split-bill row succeeded, reconcileWithServer dropped the tab,
	// and the SplitBillDialog auto-closed — the user-reported
	// "thanh toán 1 người → đóng luôn order" bug.
	// #555 M13 — paid_amount and the close decision count CAPTURED money
	// only (succeeded/confirmed net of refunds), never this payment when it
	// was born pending. Pre-fix `existingPaid + input.Amount` wrote pending
	// rows into paid_amount (a later /fail never reversed it) and could
	// close an order on in-flight terminal money. Cloud only counts
	// captured; the recompute also self-heals rows inflated pre-fix.
	capturedAfter, err := s.sumCapturedPaymentsForOrder(orderID)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	orderJustClosed := false
	if orderStatus == "checkout" {
		_ = s.transitionOrderStatus(orderID, "paying")
	}
	if method.IsAutoConfirm && orderTotal > 0 && capturedAfter >= orderTotal {
		_ = s.transitionOrderStatus(orderID, "closed")
		_, _ = s.db.Exec(
			`UPDATE orders SET closed_at = ?, paid_amount = ? WHERE id = ?`,
			now.Format(time.RFC3339), capturedAfter, orderID)
		// #491 — POS is LAN-first: apply the branch's table-status-after-payment
		// policy here (this handler closes inline, NOT via
		// applyConfirmedPaymentToOrder) so a paid dine-in table shows `cleaning`
		// immediately when the branch opted in, instead of falling back to free.
		s.applyTableStatusAfterPayment([]string{orderID})
		orderJustClosed = true
	} else {
		_, _ = s.db.Exec(
			`UPDATE orders SET paid_amount = ? WHERE id = ?`,
			capturedAfter, orderID)
	}

	if s.sync != nil {
		// `target: "workstation"` tells the sync worker to POST to
		// /api/v1/workstation/payments (gated by device.auth:workstation)
		// rather than /api/v1/kiosk/payments. POS terminals authenticate via
		// SSO + the workstation forwards under its own device token, so the
		// kiosk-typed route would 403 the bearer.
		syncPayload := map[string]any{
			"bearer_token":      s.GetDeviceToken(), // workstation token, NOT the user's SSO token
			"target":            "workstation",
			"payment_id":        payment.ID,
			"order_id":          payment.OrderID,
			"payment_method_id": payment.PaymentMethodID,
			"payment_method":    string(payment.PaymentMethod),
			"amount":            payment.Amount,
			"tip_amount":        payment.TipAmount,
			"tendered_amount":   payment.TenderedAmount,
			"change_amount":     payment.ChangeAmount,
			"reference_no":      payment.ReferenceNo,
			"note":              payment.Note,
			"idempotency_key":   payment.IdempotencyKey,
			"terminal_response": payment.TerminalResponse,
			"metadata":          payment.Metadata,
			// #817 Phase B — captured_at (device clock) drives Cloud's
			// cashier-shift re-attribution of this payment; forwarded only on
			// the workstation route (see handlePaymentCreate).
			"captured_at": now.Format(time.RFC3339Nano),
		}
		appendPaymentPolicySyncFields(syncPayload, policyIdent)
		if err := s.sync.Enqueue("payment", payment.ID, "create", syncPayload, 1); err != nil {
			slog.Error("enqueue payment.create (pos)", "err", err, "payment", payment.ID)
		}
	}

	// Two distinct events depending on whether the order finished here:
	//
	//   * order_paid    — order closed in this call. pos-web's WS
	//                     handler at use-workstation-socket.ts:128
	//                     REMOVES the order from the open-list cache,
	//                     reconcileWithServer drops the tab, and the
	//                     dialog auto-closes. Correct + desired here.
	//   * order_updated — partial payment (split-bill row, deposit,
	//                     pre-auth). pos-web at line 94 keeps the order
	//                     in the list and rewrites the cache with the
	//                     full new shape, so the tab + dialog survive
	//                     for the next collection.
	//
	// Both are listened to by every connected POS device + the KDS.
	if orderJustClosed {
		s.hub.BroadcastEvent("order_paid", map[string]any{
			"order_id":   payment.OrderID,
			"payment_id": payment.ID,
		})
	} else if s.orders != nil {
		// Broadcast the full shaped order so listeners can patch
		// their cache without a round-trip. shapeOrderForResponse
		// returns the same `data:` envelope GET /orders/{id} emits.
		// Guarded against `s.orders == nil` so legacy test harnesses
		// that build a thin Server without an OrderEngine still pass.
		if o, err := s.orders.GetByID(orderID); err == nil {
			s.hub.BroadcastEvent("order_updated", s.shapeOrderForResponse(r, o))
		}
	}
	s.auditLogPOS(r, "payment.create", "payment", payment.ID, fmt.Sprintf(`{"amount":%d}`, payment.Amount))
	// Capture the operator's language so an auto-printed receipt (which has no
	// request context) still prints in it — labels AND item names.
	//
	// BEFORE the auto-print below, never after: the slips this payment triggers
	// resolve their locale through exactly this value, so capturing it later
	// prints THIS order in whatever language the previous operator used.
	s.rememberPrintLocale(localeFromRequest(r))

	// Mode A (`prep_before_payment` — pay before the kitchen starts) defers the
	// kitchen to the payment event: `handleOrderArrivedAutoPrint` deliberately
	// prints NOTHING for a takeaway order on arrival. Every path that settles
	// one closes that loop — kiosk (local_kiosk.go), card terminal
	// (card_terminal_recorder.go), 釣銭機 (cash_changer_recorder.go), and the
	// pull-down `onOrderPaid` hook for a Cloud-settled order.
	//
	// The POS counter closes the order LOCALLY and inline, right above, so the
	// pull-down hook never fires for it — and this handler fired nothing. A
	// takeaway order paid at the till therefore reached the kitchen NEVER: not
	// late, not on the next tick, and no reprint button covers it because the
	// items still read as un-fired.
	//
	// `autoPrintKitchen`, NOT `handleLocalPaymentAutoPrint` — the counter is
	// deliberately not a caller of the latter, and that is pinned by
	// `TestTablePaid_PosCounterSettleDoesNotFire`: the table-paid slip exists
	// for a SELF-served table, and a cashier standing at the till witnessed the
	// payment themselves. The receipt is likewise pos-web's own explicit call.
	// Only the kitchen deferral belongs here.
	//
	// Guarded on `orderJustClosed` so a split-bill partial collection cannot
	// fire the kitchen early — the same condition the order_paid broadcast
	// above uses. Firing twice is harmless anyway: `fireKitchenForOrder` sends
	// only the unprinted `printed_quantity` delta.
	// `s.orders != nil` first: thin test harnesses (and the handy transport)
	// build a Server without an OrderEngine, and the sibling broadcast branch
	// above guards the same way for the same reason.
	if orderJustClosed && s.orders != nil && s.prepBeforePayment() {
		if o, err := s.orders.GetByID(payment.OrderID); err == nil && o != nil &&
			string(o.OrderType) == "takeaway" {
			s.autoPrintKitchen(payment.OrderID)
		}
	}

	writeJSON(w, http.StatusCreated, map[string]any{"data": payment})
}

// resolvedPaymentMethod is the subset of payment_methods workstation
// needs for the create-payment business rules. Mirrors the columns
// PullPaymentMethods writes (see internal/service/sync_pull.go).
type resolvedPaymentMethod struct {
	ID               string
	Code             string
	RequiresTendered bool
	IsAutoConfirm    bool
}

// resolvePaymentMethod looks up payment_methods by id (preferred) or
// by code (legacy). Returns a stub when both are absent — kiosk's
// older flow doesn't carry either and assumes cash auto-confirm.
func (s *Server) resolvePaymentMethod(id, code string) (*resolvedPaymentMethod, error) {
	if id == "" && code == "" {
		// Legacy kiosk fallback: assume cash auto-confirm so the
		// existing path still works (it never sent a method id).
		return &resolvedPaymentMethod{Code: "cash", RequiresTendered: false, IsAutoConfirm: true}, nil
	}
	query := `SELECT id, code, COALESCE(requires_tendered, 0), COALESCE(is_auto_confirm, 0)
	          FROM payment_methods WHERE `
	args := []any{}
	if id != "" {
		query += `id = ?`
		args = append(args, id)
	} else {
		query += `code = ?`
		args = append(args, code)
	}
	query += ` LIMIT 1`

	var m resolvedPaymentMethod
	var reqT, autoC int
	if err := s.db.QueryRow(query, args...).Scan(&m.ID, &m.Code, &reqT, &autoC); err != nil {
		// Method row missing locally (replica race / legacy kiosk
		// payload). Fall back to "auto-confirm, no tendered required"
		// so cashier flow keeps moving — Cloud re-validates on sync
		// UP and will reject if truly bogus. We deliberately do NOT
		// require tendered_amount here even for code="cash" because
		// legacy callers don't pre-stage it.
		return &resolvedPaymentMethod{
			ID:               id,
			Code:             code,
			RequiresTendered: false,
			IsAutoConfirm:    true,
		}, nil
	}
	m.RequiresTendered = reqT == 1
	m.IsAutoConfirm = autoC == 1
	return &m, nil
}

// sumActivePaymentsForOrder returns the total of pending + succeeded
// payment rows on an order. Refunded / failed rows don't count toward
// the outstanding balance.
//
// Refunds count NET (#555 M4): a refund reduces the order's paid_amount, so
// summing the gross here left the guard thinking the refunded portion was still
// in hand — re-collecting it 422'd forever while Cloud accepted it.
//
// #2656 — both representations net here, and neither needs a special case:
//   - a signed refund row carries `status='succeeded'` and a NEGATIVE amount, so
//     the status filter below already subtracts it;
//   - `refunded_amount` is subtracted for rows a binary rolled back past #2656
//     may have written the old way (migration 083 zeroed every other row).
//
// They never coexist on one row, so there is no double subtraction — and that is
// only true because the write path leaves a refunded ORIGINAL at 'succeeded'
// (payment_refund_shape.go). Flip the original to 'refunded' and this query
// drops the sale while keeping the refund: a negative balance on a paid order.
//
// Expired pending rows are excluded (mirrors Cloud's payments:expire-stale
// sweeper, which fails any pending past its expires_at every minute).
// Without this, an abandoned non-auto-confirm payment leaves a "phantom
// pending" row that the overpay guard keeps counting, so the order gets
// stuck at `paying` — takeable on Cloud but 422 on the workstation (#562).
// Only pending expires: succeeded/confirmed rows are real money and always
// count, even if they retain a stale expires_at from their pending phase.
func (s *Server) sumActivePaymentsForOrder(orderID string) (int, error) {
	var sum int
	now := time.Now().UTC().Format(time.RFC3339Nano)
	err := s.db.QueryRow(`
		SELECT COALESCE(SUM(amount - COALESCE(refunded_amount, 0)), 0) FROM payments
		WHERE order_id = ?
		  AND status IN ('pending', 'succeeded', 'confirmed')
		  AND (
		        status IN ('succeeded', 'confirmed')
		     OR expires_at IS NULL
		     OR expires_at = ''
		     OR datetime(expires_at) > datetime(?)
		  )`,
		orderID, now,
	).Scan(&sum)
	return sum, err
}

// sumCapturedPaymentsForOrder returns the CAPTURED money on an order —
// succeeded/confirmed rows net of refunds, both representations, exactly as
// sumActivePaymentsForOrder above (#2656). This is the only figure
// that may be written to orders.paid_amount (#555 M13): pending rows are
// in-flight, not captured, and Cloud only counts captured money. The overpay
// guard (sumActivePaymentsForOrder) intentionally still counts pending so an
// in-flight terminal payment blocks a duplicate collection.
func (s *Server) sumCapturedPaymentsForOrder(orderID string) (int, error) {
	var sum int
	err := s.db.QueryRow(`
		SELECT COALESCE(SUM(amount - COALESCE(refunded_amount, 0)), 0) FROM payments
		WHERE order_id = ?
		  AND status IN ('succeeded', 'confirmed')`,
		orderID,
	).Scan(&sum)
	return sum, err
}

// transitionOrderStatus stamps a new status on the order row + an
// updated_at marker. Intentionally NOT going through OrderEngine
// because the engine's status mutators have their own pre/post hooks
// that conflict with the payment-driven lifecycle (KDS broadcast,
// audit shape).
func (s *Server) transitionOrderStatus(orderID, status string) error {
	_, err := s.db.Exec(
		`UPDATE orders SET status = ?, updated_at = ? WHERE id = ?`,
		status, time.Now().UTC().Format(time.RFC3339), orderID)
	return err
}

// auditLogPOS records POS actions with the user/device id from request context.
// Mirrors s.auditLog but uses the DeviceContext.ID (user_id for SSO sessions)
// so the audit trail captures "who" not just "what".
func (s *Server) auditLogPOS(r *http.Request, action, entityType, entityID, details string) {
	if s.audit == nil {
		return
	}
	d, _ := DeviceFromContext(r.Context())
	actor := "unknown"
	if d != nil {
		actor = fmt.Sprintf("%s:%s", d.IdentityType, d.ID)
	}
	s.audit.Log(actor, action, entityType, entityID, details, clientIP(r))
}
