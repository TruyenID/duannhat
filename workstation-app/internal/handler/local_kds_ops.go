package handler

// KDS Operation Endpoints — Phase 5 plan-028
//
// Mirrors cloud /api/v1/kds/orders/{customerOrder}/items/{item}/mark-* and
// /api/v1/kds/orders/{customerOrder}/bump-all onto the workstation LAN server.
//
// All endpoints:
//   - Require auth (device context via AuthMiddleware)
//   - Require Idempotency-Key header (422 if missing)
//   - Deduplicate via idempotency cache (replay returns cached body)
//   - Enforce branch ownership (403 if order.branch_id != device.branch_id)
//   - Reject operations on voided/closed orders (409 KDS_E001)
//   - Write updated status to order_items table (post-Sprint-4 schema)
//   - Enqueue sync_queue entry for cloud sync UP
//   - Broadcast WS event to KDS clients in branch
//
// Schema note (post-Phase-5.4):
//   The local order_items table stores started_preparing_at, ready_at,
//   served_at (migration 011 added the first two; 007 added served_at). Mark-*
//   handlers persist them when transitioning, and KdsItemResource computes
//   aging_minutes + time_in_current_status_seconds using the same COALESCE
//   chain cloud uses (KdsItemResource.php::computeTimeInCurrentStatusSeconds).
//
//   Clock skew note: workstation uses its own UTC clock for both timestamp
//   writes and aging reads. When cloud reconciles via sync UP, cloud may
//   overwrite with its own clock — drift > 30s could show different aging in
//   LAN vs Cloud mode. Acceptable per kitchen UX; document in plan-028
//   INTEGRATION_GAPS if a real customer hits it.
//
// KdsItemResource shape mirrors cloud JSON:
//   id, menu_item_name, quantity, status, note
//   aging_minutes (computed: now - created_at, in minutes, clamped >=0)
//   time_in_current_status_seconds (computed per current-status anchor)
//   is_blocked_by_toppings (always false — no local toppings table, tech debt)
//   allowed_transitions (computed from current status)
//   started_preparing_at, ready_at, served_at (ISO8601 or nil)
//   toppings (empty array — no local order_item_toppings table, tech debt)

import (
	"database/sql"
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"time"
)

// markServedMinAfterReady is the anti-misclick window a kitchen item must sit
// in `ready` before it may be marked `served` — mirrors cloud
// KdsBusinessRules::MARK_SERVED_MIN_SECONDS_AFTER_READY (KDS_E003).
const markServedMinAfterReady = 30 * time.Second

// ─── Shared helpers ───────────────────────────────────────────────────────────

// kdsItemResource builds a KdsItemResource-shaped map for a single order item
// row. Mirrors cloud's JSON shape and computes aging server-side.
//
// All timestamp args are SQLite TEXT (RFC3339); pass sql.NullString to express
// NULL. `createdAt` should always be set (NOT NULL in schema) so the aging
// fallbacks work.
func kdsItemResource(
	id, menuItemName string,
	quantity int64,
	status string,
	note sql.NullString,
	createdAt, startedPreparingAt, readyAt, servedAt sql.NullString,
	printStatus sql.NullString,
) map[string]any {
	now := time.Now().UTC()
	ps := printStatus.String
	if !printStatus.Valid || ps == "" {
		ps = "pending"
	}
	return map[string]any{
		// Core identity
		"id":             id,
		"menu_item_name": menuItemName,
		"quantity":       quantity,
		"status":         status,
		"note":           nullStrToAny(note),

		// Kitchen transition timestamps (ISO8601 or nil).
		"started_preparing_at": nullStrToAny(startedPreparingAt),
		"ready_at":             nullStrToAny(readyAt),
		"served_at":            nullStrToAny(servedAt),

		// Derived timing — mirrors cloud's KdsItemResource::computeTimeInCurrentStatusSeconds.
		"aging_minutes":                  agingMinutes(createdAt, now),
		"time_in_current_status_seconds": timeInCurrentStatusSeconds(status, createdAt, startedPreparingAt, readyAt, servedAt, now),

		// Transition helpers — computed from current status.
		"allowed_transitions":    allowedKdsTransitions(status),
		"is_blocked_by_toppings": false,

		// Plan-038 T9.8 — surface print_status so godx-kds can render the
		// "MÁY IN LỖI" badge when a fire failed.
		"print_status": ps,

		// Toppings — no local order_item_toppings table yet (tech debt: workstation
		// dual-table issue #294). FE accepts empty array.
		"toppings": []any{},
	}
}

// parseSqlTime parses a workstation TEXT timestamp ("YYYY-MM-DD HH:MM:SS" from
// SQLite's `datetime('now')` default, or RFC3339 from handlers using
// time.Now().UTC().Format(time.RFC3339)). Returns zero time + false on miss.
func parseSqlTime(s sql.NullString) (time.Time, bool) {
	if !s.Valid || s.String == "" {
		return time.Time{}, false
	}
	for _, layout := range []string{time.RFC3339, "2006-01-02 15:04:05"} {
		if t, err := time.Parse(layout, s.String); err == nil {
			return t.UTC(), true
		}
	}
	return time.Time{}, false
}

// agingMinutes returns minutes since createdAt, clamped to >=0 (defensive for
// clock skew). Cloud's KdsItemResource uses created_at->diffInMinutes(now()).
func agingMinutes(createdAt sql.NullString, now time.Time) int {
	t, ok := parseSqlTime(createdAt)
	if !ok {
		return 0
	}
	m := int(now.Sub(t).Minutes())
	if m < 0 {
		return 0
	}
	return m
}

// timeInCurrentStatusSeconds mirrors cloud's COALESCE chain in
// KdsItemResource::computeTimeInCurrentStatusSeconds. Clamped to >=0.
//
//	preparing → started_preparing_at ?? created_at
//	ready     → ready_at ?? started_preparing_at ?? created_at
//	served    → served_at ?? ready_at ?? created_at
//	other     → created_at (pending falls here; voided/unknown returns 0)
func timeInCurrentStatusSeconds(
	status string,
	createdAt, startedPreparingAt, readyAt, servedAt sql.NullString,
	now time.Time,
) int {
	candidates := []sql.NullString{}
	switch status {
	case "preparing":
		candidates = []sql.NullString{startedPreparingAt, createdAt}
	case "ready":
		candidates = []sql.NullString{readyAt, startedPreparingAt, createdAt}
	case "served":
		candidates = []sql.NullString{servedAt, readyAt, createdAt}
	case "pending":
		candidates = []sql.NullString{createdAt}
	default:
		return 0
	}
	for _, c := range candidates {
		if t, ok := parseSqlTime(c); ok {
			s := int(now.Sub(t).Seconds())
			if s < 0 {
				return 0
			}
			return s
		}
	}
	return 0
}

// allowedKdsTransitions returns the operation names valid for the current
// status, matching cloud's KdsItemResource::computeAllowedTransitions exactly.
//
//	pending    → mark-preparing
//	preparing  → mark-ready, revert
//	ready      → mark-served, revert
//	served     → (empty — terminal)
//	voided/etc → (empty)
//
// Served advertises NO transitions: the revert handler rejects served with
// KDS_E002 ("served is terminal"), so advertising "revert" here would render a
// dead FE button that always 422s. allowed_transitions must match the enforced
// guard.
func allowedKdsTransitions(status string) []string {
	switch status {
	case "pending":
		return []string{"mark-preparing"}
	case "preparing":
		return []string{"mark-ready", "revert"}
	case "ready":
		return []string{"mark-served", "revert"}
	default:
		return []string{}
	}
}

// kdsItemRow is the join result for KDS handlers; populated by kdsLookupOrderItem.
type kdsItemRow struct {
	orderBranchID      string
	orderStatus        string
	itemStatus         sql.NullString
	itemUpdatedAt      sql.NullString
	menuItemName       string
	quantity           int64
	unitPrice          int64
	productSkuID       sql.NullString
	note               sql.NullString
	createdAt          sql.NullString
	startedPreparingAt sql.NullString
	readyAt            sql.NullString
	servedAt           sql.NullString
}

// kdsLookupOrderItem looks up an order item joined with its order, returning
// everything kdsItemResource needs plus branch + prev-status for guard checks.
// err is sql.ErrNoRows when not found.
func (s *Server) kdsLookupOrderItem(orderID, itemID string) (kdsItemRow, error) {
	var r kdsItemRow
	err := s.db.QueryRow(`
		SELECT o.branch_id, o.status,
		       oi.status, oi.updated_at,
		       oi.menu_item_name, oi.quantity, oi.unit_price, oi.product_sku_id, oi.note,
		       oi.created_at, oi.started_preparing_at, oi.ready_at, oi.served_at
		FROM order_items oi
		JOIN orders o ON o.id = oi.customer_order_id
		WHERE oi.id = ? AND oi.customer_order_id = ?
	`, itemID, orderID).Scan(
		&r.orderBranchID, &r.orderStatus,
		&r.itemStatus, &r.itemUpdatedAt,
		&r.menuItemName, &r.quantity, &r.unitPrice, &r.productSkuID, &r.note,
		&r.createdAt, &r.startedPreparingAt, &r.readyAt, &r.servedAt,
	)
	return r, err
}

// kdsCheckOrderActive returns (orderBranchID, orderStatus, err) for the given order.
func (s *Server) kdsCheckOrder(orderID string) (branchID, orderStatus string, err error) {
	err = s.db.QueryRow(`
		SELECT branch_id, status FROM orders WHERE id = ?
	`, orderID).Scan(&branchID, &orderStatus)
	return
}

// idemReplayOrProceed returns true + writes cached response if the key was
// already processed. Returns false if caller should proceed with the operation.
func (s *Server) idemReplayOrProceed(w http.ResponseWriter, idemKey, deviceID string) bool {
	if s.idempotency == nil {
		return false
	}
	cached, hit, err := s.idempotency.Get(idemKey, deviceID)
	if err == nil && hit {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(cached))
		return true
	}
	return false
}

// idemCachePut stores the response bytes in the idempotency cache (best-effort).
func (s *Server) idemCachePut(idemKey, deviceID, respBytes string) {
	if s.idempotency == nil {
		return
	}
	if err := s.idempotency.Put(idemKey, deviceID, "", respBytes); err != nil {
		slog.Warn("kds ops idempotency cache put failed", "key", idemKey, "err", err)
	}
}

// kdsSyncEnqueue enqueues a sync UP entry for a KDS operation (best-effort).
func (s *Server) kdsSyncEnqueue(operation, itemID string, payload map[string]any) {
	if s.sync == nil {
		return
	}
	if err := s.sync.Enqueue("customer_order_item", itemID, operation, payload, 2); err != nil {
		slog.Warn("kds ops sync enqueue failed", "item_id", itemID, "operation", operation, "err", err)
	}
}

// writeKdsItemResponse marshals the item resource, caches it, and writes HTTP 200.
func (s *Server) writeKdsItemResponse(w http.ResponseWriter, idemKey, deviceID string, item map[string]any) {
	response := map[string]any{"data": item}
	respBytes, _ := json.Marshal(response)
	s.idemCachePut(idemKey, deviceID, string(respBytes))
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(respBytes)
}

// ─── POST /api/v1/kds/orders/{customerOrder}/items/{item}/mark-preparing ─────

func (s *Server) handleLocalKdsMarkPreparing(w http.ResponseWriter, r *http.Request) {
	s.handleLocalKdsMarkItem(w, r, "preparing")
}

// ─── POST /api/v1/kds/orders/{customerOrder}/items/{item}/mark-ready ──────────

func (s *Server) handleLocalKdsMarkReady(w http.ResponseWriter, r *http.Request) {
	s.handleLocalKdsMarkItem(w, r, "ready")
}

// ─── POST /api/v1/kds/orders/{customerOrder}/items/{item}/mark-served ─────────

func (s *Server) handleLocalKdsMarkServed(w http.ResponseWriter, r *http.Request) {
	s.handleLocalKdsMarkItem(w, r, "served")
}

// handleLocalKdsMarkItem is the shared implementation for mark-preparing /
// mark-ready / mark-served. Each performs the same pipeline with a different
// target status value.
func (s *Server) handleLocalKdsMarkItem(w http.ResponseWriter, r *http.Request, targetStatus string) {
	dev, ok := DeviceFromContext(r.Context())
	if !ok || dev == nil {
		writeError(w, http.StatusUnauthorized, "no device context")
		return
	}

	orderID := r.PathValue("customerOrder")
	itemID := r.PathValue("item")

	idemKey := r.Header.Get("Idempotency-Key")
	if idemKey == "" {
		writeError(w, http.StatusUnprocessableEntity, "Idempotency-Key header required")
		return
	}

	if s.idemReplayOrProceed(w, idemKey, dev.ID) {
		return
	}

	row, err := s.kdsLookupOrderItem(orderID, itemID)
	if errors.Is(err, sql.ErrNoRows) {
		writeError(w, http.StatusNotFound, "item not found")
		return
	}
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	// Branch ownership check — mirrors cloud KDS_E006.
	if dev.BranchID != "" && row.orderBranchID != dev.BranchID {
		writeError(w, http.StatusForbidden, "order belongs to a different branch")
		return
	}

	// Order active check — mirrors cloud KDS_E001.
	switch row.orderStatus {
	case "voided", "closed":
		writeError(w, http.StatusConflict, "order is not active")
		return
	}

	// Forward-transition guard — mirrors cloud KDS_E002
	// (KdsBusinessRules::assertForwardTransition). Each mark-* op requires the
	// item to currently be in the EXACT predecessor status its
	// allowed_transitions contract advertises. Without this, mark-preparing
	// could drag a ready/served item backward and mark-ready could skip
	// preparing or resurrect a served item — the raw UPDATE below sets status
	// unconditionally. Cloud returns 409 for KDS_E002.
	expectedFrom := map[string]string{
		"preparing": "pending",
		"ready":     "preparing",
		"served":    "ready",
	}[targetStatus]
	current := row.itemStatus.String
	if current != expectedFrom {
		writeError(w, http.StatusConflict,
			"invalid transition: item is "+current+", must be "+expectedFrom+" before "+targetStatus)
		return
	}

	occurredAt := time.Now().UTC()
	occurredAtStr := occurredAt.Format(time.RFC3339)

	// Anti-misclick guard for mark-served — mirrors cloud KDS_E003
	// (KdsBusinessRules::assertMarkServedAllowed). The item must have been
	// ready for at least 30s before it can be served; a missing ready_at means
	// the item never legitimately reached ready. Cloud returns 409.
	if targetStatus == "served" {
		readyTime, ok := parseSqlTime(row.readyAt)
		if !ok {
			writeError(w, http.StatusConflict, "item has no ready_at — mark-ready before mark-served")
			return
		}
		if elapsed := occurredAt.Sub(readyTime); elapsed < markServedMinAfterReady {
			writeError(w, http.StatusConflict,
				"too soon after mark-ready — wait 30s before mark-served")
			return
		}
	}

	// Set the transition timestamp corresponding to the target status. Each
	// column is COALESCE-d so we don't clobber an earlier non-NULL value (e.g.,
	// hitting mark-preparing twice keeps the first started_preparing_at, which
	// is what idempotency replay returns anyway).
	occurredAtNS := sql.NullString{String: occurredAtStr, Valid: true}
	switch targetStatus {
	case "preparing":
		row.startedPreparingAt = occurredAtNS
	case "ready":
		row.readyAt = occurredAtNS
	case "served":
		row.servedAt = occurredAtNS
	}

	_, err = s.db.Exec(`
		UPDATE order_items
		SET status               = ?,
		    started_preparing_at = COALESCE(started_preparing_at, ?),
		    ready_at             = COALESCE(ready_at, ?),
		    served_at            = COALESCE(served_at, ?),
		    updated_at           = ?
		WHERE id = ? AND customer_order_id = ?
	`,
		targetStatus,
		row.startedPreparingAt, row.readyAt, row.servedAt,
		occurredAtStr, itemID, orderID,
	)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	// Broadcast WS event to branch-scoped KDS clients.
	if s.hub != nil {
		s.hub.BroadcastEventScoped("order_item.status_changed", map[string]any{
			"order_id":        orderID,
			"item_id":         itemID,
			"previous_status": row.itemStatus.String,
			"status":          targetStatus,
			"served_at":       nullStrToAny(row.servedAt),
			"idempotency_key": idemKey,
			"occurred_at":     occurredAtStr,
			"source":          "local",
		}, dev.BranchID)
	}

	// Enqueue sync UP.
	// item_snapshot carries enough data for the cloud to ghost-create the item
	// row when it doesn't exist yet (order synced without items).
	s.kdsSyncEnqueue("update_status", itemID, map[string]any{
		"order_id":            orderID,
		"item_id":             itemID,
		"status":              targetStatus,
		"idempotency_key":     idemKey,
		"previous_status":     row.itemStatus.String,
		"original_updated_at": row.itemUpdatedAt.String,
		"actor_kds_device_id": dev.ID,
		"occurred_at":         occurredAtStr,
		"item_snapshot": map[string]any{
			"product_sku_id": row.productSkuID.String,
			"quantity":       row.quantity,
			"unit_price":     row.unitPrice,
			"name":           row.menuItemName,
		},
	})

	item := kdsItemResource(
		itemID, row.menuItemName, row.quantity, targetStatus, row.note,
		row.createdAt, row.startedPreparingAt, row.readyAt, row.servedAt,
		sql.NullString{},
	)
	s.writeKdsItemResponse(w, idemKey, dev.ID, item)
}

// ─── POST /api/v1/kds/orders/{customerOrder}/items/{item}/revert ──────────────

// handleLocalKdsRevert reverts an item's status one step backward in the KDS
// workflow: served → ready, ready → preparing, preparing → pending.
// Returns 422 when the item is already at the initial state (pending / voided).
func (s *Server) handleLocalKdsRevert(w http.ResponseWriter, r *http.Request) {
	dev, ok := DeviceFromContext(r.Context())
	if !ok || dev == nil {
		writeError(w, http.StatusUnauthorized, "no device context")
		return
	}

	orderID := r.PathValue("customerOrder")
	itemID := r.PathValue("item")

	idemKey := r.Header.Get("Idempotency-Key")
	if idemKey == "" {
		writeError(w, http.StatusUnprocessableEntity, "Idempotency-Key header required")
		return
	}

	if s.idemReplayOrProceed(w, idemKey, dev.ID) {
		return
	}

	row, err := s.kdsLookupOrderItem(orderID, itemID)
	if errors.Is(err, sql.ErrNoRows) {
		writeError(w, http.StatusNotFound, "item not found")
		return
	}
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	if dev.BranchID != "" && row.orderBranchID != dev.BranchID {
		writeError(w, http.StatusForbidden, "order belongs to a different branch")
		return
	}

	switch row.orderStatus {
	case "voided", "closed":
		writeError(w, http.StatusConflict, "order is not active")
		return
	}

	// Honor the FE's `{to: "pending"|"preparing"}` body — matching cloud's
	// revert contract. Timestamps stay as historical anchors so the COALESCE
	// chain in kdsItemResource keeps producing stable aging across a
	// revert → re-bump cycle (re-entering ready preserves the original
	// ready_at, matching the "first time this item reached ready" semantic).
	//
	// Served is terminal per cloud KDS_E002 — reject regardless of body so
	// LAN and Cloud agree. FE hides the revert button for served items via
	// allowed_transitions; callers reaching here are out of policy.
	if row.itemStatus.String == "served" {
		writeError(w, http.StatusUnprocessableEntity, "served is terminal — cannot revert")
		return
	}

	var body struct {
		To string `json:"to"`
	}
	if r.ContentLength > 0 {
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			writeError(w, http.StatusBadRequest, "invalid JSON body: "+err.Error())
			return
		}
	}
	if body.To != "pending" && body.To != "preparing" {
		writeError(w, http.StatusUnprocessableEntity, "body.to must be one of: pending, preparing")
		return
	}
	revertTo := body.To

	// Sanity-check the transition makes sense for the current status. Going
	// from pending → anywhere is a no-op revert (pending is the initial
	// state); allow it through 422 here rather than silently no-op.
	if row.itemStatus.String == "pending" {
		writeError(w, http.StatusUnprocessableEntity, "item is already pending — nothing to revert")
		return
	}

	occurredAt := time.Now().UTC()
	occurredAtStr := occurredAt.Format(time.RFC3339)

	_, err = s.db.Exec(`
		UPDATE order_items
		SET status     = ?,
		    updated_at = ?
		WHERE id = ? AND customer_order_id = ?
	`, revertTo, occurredAtStr, itemID, orderID)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	if s.hub != nil {
		s.hub.BroadcastEventScoped("order_item.status_changed", map[string]any{
			"order_id":        orderID,
			"item_id":         itemID,
			"previous_status": row.itemStatus.String,
			"status":          revertTo,
			"served_at":       nullStrToAny(row.servedAt),
			"idempotency_key": idemKey,
			"occurred_at":     occurredAtStr,
			"source":          "local",
			"reason":          "revert",
		}, dev.BranchID)
	}

	s.kdsSyncEnqueue("revert_status", itemID, map[string]any{
		"order_id":            orderID,
		"item_id":             itemID,
		"status":              revertTo,
		"idempotency_key":     idemKey,
		"previous_status":     row.itemStatus.String,
		"original_updated_at": row.itemUpdatedAt.String,
		"actor_kds_device_id": dev.ID,
		"occurred_at":         occurredAtStr,
	})

	item := kdsItemResource(
		itemID, row.menuItemName, row.quantity, revertTo, row.note,
		row.createdAt, row.startedPreparingAt, row.readyAt, row.servedAt,
		sql.NullString{},
	)
	s.writeKdsItemResponse(w, idemKey, dev.ID, item)
}

// ─── POST /api/v1/kds/orders/{customerOrder}/bump-all ─────────────────────────

// handleLocalKdsBumpAll advances all pending/preparing items in the order to
// their next status in one atomic operation:
//
//	pending    → preparing
//	preparing  → ready
//
// Items already at ready/served/voided are skipped.
// Returns the full list of affected items in the response.
func (s *Server) handleLocalKdsBumpAll(w http.ResponseWriter, r *http.Request) {
	dev, ok := DeviceFromContext(r.Context())
	if !ok || dev == nil {
		writeError(w, http.StatusUnauthorized, "no device context")
		return
	}

	orderID := r.PathValue("customerOrder")

	idemKey := r.Header.Get("Idempotency-Key")
	if idemKey == "" {
		writeError(w, http.StatusUnprocessableEntity, "Idempotency-Key header required")
		return
	}

	if s.idemReplayOrProceed(w, idemKey, dev.ID) {
		return
	}

	// Lookup order for branch check + active status check.
	orderBranchID, orderStatus, err := s.kdsCheckOrder(orderID)
	if errors.Is(err, sql.ErrNoRows) {
		writeError(w, http.StatusNotFound, "order not found")
		return
	}
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	if dev.BranchID != "" && orderBranchID != dev.BranchID {
		writeError(w, http.StatusForbidden, "order belongs to a different branch")
		return
	}

	switch orderStatus {
	case "voided", "closed":
		writeError(w, http.StatusConflict, "order is not active")
		return
	}

	// Fetch all bumpable items for this order.
	// Concurrency contract: bump-all is idempotent within a single
	// Idempotency-Key (the cache replay above guards against duplicates), but
	// is NOT reentrant across DIFFERENT keys. Two callers with different
	// Idempotency-Keys racing on the same order each read their own pre-state
	// and bump only items still eligible at that moment. Per-item UPDATEs
	// serialize under SQLite WAL, so no double-bump; the WS event count may
	// differ from a single-shot sum (item-1 advances pending→preparing under
	// call A, then preparing→ready under call B). FE callers MUST serialize
	// bump-all requests per order if they want a deterministic count.
	rows, err := s.db.Query(`
		SELECT id, menu_item_name, quantity, unit_price, COALESCE(product_sku_id,''), status, note,
		       created_at, started_preparing_at, ready_at, served_at, updated_at
		FROM order_items
		WHERE customer_order_id = ?
		  AND status IN ('pending', 'preparing')
		ORDER BY created_at ASC
	`, orderID)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	defer rows.Close()

	type bumpableRow struct {
		id                 string
		menuItemName       string
		qty                int64
		unitPrice          int64
		productSkuID       string
		status             string
		note               sql.NullString
		createdAt          sql.NullString
		startedPreparingAt sql.NullString
		readyAt            sql.NullString
		servedAt           sql.NullString
		updatedAt          sql.NullString
	}

	var bumpable []bumpableRow
	for rows.Next() {
		var row bumpableRow
		if err := rows.Scan(
			&row.id, &row.menuItemName, &row.qty, &row.unitPrice, &row.productSkuID, &row.status, &row.note,
			&row.createdAt, &row.startedPreparingAt, &row.readyAt, &row.servedAt,
			&row.updatedAt,
		); err != nil {
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}
		bumpable = append(bumpable, row)
	}
	if err := rows.Err(); err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	occurredAt := time.Now().UTC()
	occurredAtStr := occurredAt.Format(time.RFC3339)
	occurredAtNS := sql.NullString{String: occurredAtStr, Valid: true}

	// Advance each item and collect results.
	resultItems := make([]any, 0, len(bumpable))
	for _, row := range bumpable {
		var newStatus string
		switch row.status {
		case "pending":
			newStatus = "preparing"
			row.startedPreparingAt = occurredAtNS
		case "preparing":
			newStatus = "ready"
			row.readyAt = occurredAtNS
		default:
			continue
		}

		_, err := s.db.Exec(`
			UPDATE order_items
			SET status               = ?,
			    started_preparing_at = COALESCE(started_preparing_at, ?),
			    ready_at             = COALESCE(ready_at, ?),
			    updated_at           = ?
			WHERE id = ? AND customer_order_id = ?
		`,
			newStatus,
			row.startedPreparingAt, row.readyAt,
			occurredAtStr, row.id, orderID,
		)
		if err != nil {
			slog.Warn("kds bump-all item update failed", "item_id", row.id, "err", err)
			continue
		}

		// FE↔BE CONTRACT: cloud derives per-item idempotency_key as
		// `${batchKey}:${itemId}` (single colon). godx-kds useBumpAll records
		// keys in that exact format for self-echo dedup. Workstation must match
		// or LAN-mode bumps double-flicker after the local optimistic update.
		perItemKey := idemKey + ":" + row.id

		if s.hub != nil {
			s.hub.BroadcastEventScoped("order_item.status_changed", map[string]any{
				"order_id":        orderID,
				"item_id":         row.id,
				"previous_status": row.status,
				"status":          newStatus,
				"idempotency_key": perItemKey,
				"occurred_at":     occurredAtStr,
				"source":          "local",
				"reason":          "bump_all",
			}, dev.BranchID)
		}

		s.kdsSyncEnqueue("update_status", row.id, map[string]any{
			"order_id":            orderID,
			"item_id":             row.id,
			"status":              newStatus,
			"idempotency_key":     perItemKey,
			"previous_status":     row.status,
			"original_updated_at": row.updatedAt.String,
			"actor_kds_device_id": dev.ID,
			"occurred_at":         occurredAtStr,
			"item_snapshot": map[string]any{
				"product_sku_id": row.productSkuID,
				"quantity":       row.qty,
				"unit_price":     row.unitPrice,
				"name":           row.menuItemName,
			},
		})

		resultItems = append(resultItems, kdsItemResource(
			row.id, row.menuItemName, row.qty, newStatus, row.note,
			row.createdAt, row.startedPreparingAt, row.readyAt, row.servedAt,
			sql.NullString{},
		))
	}

	response := map[string]any{
		"data": map[string]any{
			"order_id":     orderID,
			"bumped_count": len(resultItems),
			"bumped_items": resultItems,
			"occurred_at":  occurredAtStr,
		},
	}
	respBytes, _ := json.Marshal(response)
	s.idemCachePut(idemKey, dev.ID, string(respBytes))
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(respBytes)
}
