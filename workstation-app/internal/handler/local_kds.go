package handler

import (
	"database/sql"
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"time"
)

// KDS LAN endpoints — serve kitchen display screens connected to the restaurant
// LAN. KDS devices authenticate with a bearer device token (type="kds"). Orders
// are read from the local orders table (post-Sprint-4 legacy, cloud-shape columns,
// populated by order_service.go for POS-created orders and sync_pull.go for cloud
// recovery). order_items.customer_order_id references orders.id.
// GET /me  → fast-path identity from DeviceContext (no extra DB hit).
// GET /orders → branch-scoped active orders with embedded items.

// GET /api/v1/kds/me
//
// Returns the KDS device's identity from the auth cache / request context.
// No DB hit — identity is already resolved by AuthMiddleware.
func (s *Server) handleLocalKdsMe(w http.ResponseWriter, r *http.Request) {
	ctx, ok := DeviceFromContext(r.Context())
	if !ok || ctx == nil {
		writeError(w, http.StatusUnauthorized, "no device context")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"data": map[string]any{
			"id":        ctx.ID,
			"type":      ctx.Type,
			"branch_id": ctx.BranchID,
		},
	})
}

// GET /api/v1/kds/orders
//
// Returns active orders for the KDS device's branch, with embedded order items.
// Reads from the post-Sprint-4 orders + order_items tables (migration 007):
//   - orders: cloud-shape columns (branch_id, status, opened_at, order_code, etc.)
//   - order_items: status, served_at, voided_at, printer_group, menu_item_name, etc.
//
// Returns 503 when orders is empty — KDS-web falls back to cloud in that case.
// POS-created orders are visible immediately (order_service.go writes here).
// Cloud orders appear after sync_pull DOWN (Task 2.8).
//
// Status filter: open, dining, checkout, paying.
// closed / voided orders are excluded so kitchen only sees active work.
func (s *Server) handleLocalKdsOrders(w http.ResponseWriter, r *http.Request) {
	ctx, ok := DeviceFromContext(r.Context())
	if !ok || ctx == nil {
		writeError(w, http.StatusUnauthorized, "no device context")
		return
	}

	// Detect empty orders table — KDS-web falls back to cloud so it never shows stale.
	var count int
	if err := s.db.QueryRow(`SELECT COUNT(*) FROM orders`).Scan(&count); err == nil && count == 0 {
		writeError(w, http.StatusServiceUnavailable, "replica not yet populated; cloud-fallback expected")
		return
	}

	rows, err := s.db.Query(`
		SELECT o.id, o.order_code, COALESCE(o.order_type, 'spot'), o.opened_at,
		       COALESCE(o.note, ''), o.guest_count,
		       o.table_id, o.table_number
		FROM orders o
		WHERE o.branch_id = ?
		  AND o.status IN ('open','dining','checkout','paying')
		ORDER BY o.opened_at ASC
		LIMIT 200
	`, ctx.BranchID)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	defer rows.Close()

	// Plan-038 T9.4 — per-branch setting kds_show_only_fired narrows the item
	// filter to fired items (print_status in 'sent_to_kitchen' / 'failed') so
	// KDS only sees what's been explicitly sent to the kitchen via "gửi bếp".
	// Default false for upgrade installs (existing shops keep the old behaviour
	// where cart items appear immediately). Read once per request.
	showOnlyFired := s.settingValue("kds_show_only_fired") == "true"

	now := time.Now().UTC()
	orders := make([]map[string]any, 0)
	for rows.Next() {
		var id, code, orderType, openedAt, note string
		var guestCount sql.NullInt64
		var tableID, tableNumber sql.NullString
		if err := rows.Scan(&id, &code, &orderType, &openedAt, &note, &guestCount, &tableID, &tableNumber); err != nil {
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}

		itemRows, err := s.db.Query(`
			SELECT id, menu_item_name, quantity, note, status,
			       created_at, started_preparing_at, ready_at, served_at,
			       COALESCE(print_status, 'pending') AS print_status
			FROM order_items
			WHERE customer_order_id = ?
			  AND status != 'voided'
			  AND (
			    ? = 0
			    OR print_status IN ('sent_to_kitchen','failed')
			  )
			ORDER BY created_at ASC
		`, id, btoi(showOnlyFired))
		if err != nil {
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}
		items := make([]map[string]any, 0)
		var pendingCount, preparingCount, readyCount int
		var oldestPendingAgeMin int
		for itemRows.Next() {
			var iid, itemName string
			var qty int64
			var inote, status, createdAt, spa, rdy, srv sql.NullString
			var printStatus sql.NullString
			if err := itemRows.Scan(&iid, &itemName, &qty, &inote, &status, &createdAt, &spa, &rdy, &srv, &printStatus); err != nil {
				itemRows.Close()
				writeError(w, http.StatusInternalServerError, err.Error())
				return
			}
			itemAgeMin := agingMinutes(createdAt, now)
			switch status.String {
			case "pending":
				pendingCount++
				if itemAgeMin > oldestPendingAgeMin {
					oldestPendingAgeMin = itemAgeMin
				}
			case "preparing":
				preparingCount++
			case "ready":
				readyCount++
			}
			items = append(items, kdsItemResource(iid, itemName, qty, status.String, inote, createdAt, spa, rdy, srv, printStatus))
		}
		itemRows.Close()

		// In show-only-fired mode, an order with nothing fired yet must not
		// appear on KDS at all — no empty card. It surfaces the moment its
		// first item is sent to the kitchen (pos-web "gửi bếp" →
		// order.kitchen_printed → KDS re-fetch → this order now has items).
		if showOnlyFired && len(items) == 0 {
			continue
		}

		orderAgeMin := agingMinutes(sql.NullString{String: openedAt, Valid: openedAt != ""}, now)
		isLate := orderAgeMin > 10
		var priority string
		switch {
		case orderAgeMin >= 10:
			priority = "critical"
		case orderAgeMin >= 5:
			priority = "warning"
		default:
			priority = "normal"
		}

		// Map workstation table_number → cloud-shaped table {id, code, zone}.
		// Workstation doesn't store zone locally, so it's null. Code falls back
		// to table_number for display continuity.
		var table any
		if tableID.Valid || tableNumber.Valid {
			table = map[string]any{
				"id":   nullStrToAny(tableID),
				"code": nullStrToAny(tableNumber),
				"zone": nil,
			}
		} else {
			table = nil
		}

		orders = append(orders, map[string]any{
			"id":                         id,
			"order_code":                 code,
			"order_type":                 orderType,
			"guest_count":                nullInt64ToAny(guestCount),
			"note":                       nullStringOrEmpty(note),
			"opened_at":                  openedAt,
			"aging_minutes":              orderAgeMin,
			"is_late":                    isLate,
			"priority":                   priority,
			"pending_items_count":        pendingCount,
			"preparing_items_count":      preparingCount,
			"ready_items_count":          readyCount,
			"oldest_pending_age_minutes": oldestPendingAgeMin,
			"can_bump_all":               pendingCount > 0 || preparingCount > 0,
			"table":                      table,
			"items":                      items,
		})
	}
	if err := rows.Err(); err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	// Aggregate meta — mirrors cloud KdsAggregateMeta::compute. Workstation
	// version is a best-effort subset; cloud has the authoritative numbers.
	var activeCount, lateCount, criticalCount int
	var totalAge int
	itemsByStatus := map[string]int{"pending": 0, "preparing": 0, "ready": 0, "served": 0}
	for _, o := range orders {
		activeCount++
		age, _ := o["aging_minutes"].(int)
		totalAge += age
		if late, _ := o["is_late"].(bool); late {
			lateCount++
		}
		if prio, _ := o["priority"].(string); prio == "critical" {
			criticalCount++
		}
		itemsByStatus["pending"] += o["pending_items_count"].(int)
		itemsByStatus["preparing"] += o["preparing_items_count"].(int)
		itemsByStatus["ready"] += o["ready_items_count"].(int)
	}
	var avgAge float64
	if activeCount > 0 {
		avgAge = float64(totalAge) / float64(activeCount)
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"data": orders,
		"meta": map[string]any{
			"active_count":                    activeCount,
			"late_count":                      lateCount,
			"critical_count":                  criticalCount,
			"avg_age_minutes":                 avgAge,
			"oldest_pending_item_age_minutes": maxOldestPending(orders),
			"items_by_status":                 itemsByStatus,
			"fetched_at":                      now.Format(time.RFC3339),
		},
	})
}

func nullInt64ToAny(n sql.NullInt64) any {
	if !n.Valid {
		return nil
	}
	return n.Int64
}

func nullStringOrEmpty(s string) any {
	if s == "" {
		return nil
	}
	return s
}

func maxOldestPending(orders []map[string]any) int {
	max := 0
	for _, o := range orders {
		if v, ok := o["oldest_pending_age_minutes"].(int); ok && v > max {
			max = v
		}
	}
	return max
}

// nullStrToAny returns the string if valid, or nil for JSON null.
// Defined here to avoid duplicate declaration across kds/pos handlers.
// If local_pos.go also declares this, rename or remove one.
func nullStrToAny(s sql.NullString) any {
	if !s.Valid {
		return nil
	}
	return s.String
}

// PATCH /api/v1/kds/orders/{order}/items/{item}/status
//
// Bump a kitchen item's status (preparing → ready → served). Requires the
// Idempotency-Key header so replayed requests return the cached response
// without double-applying the UPDATE or double-enqueuing to sync_queue.
//
// Valid transitions (initiated by KDS staff):
//   - preparing — kitchen has started working on the item
//   - ready     — item is plated and waiting for service
//   - served    — item has been delivered to the table
//
// Returns 422 for missing Idempotency-Key, invalid status, or bad body.
// Returns 404 when the item/order pair doesn't exist.
// Returns 200 (idempotent) on replay via the same Idempotency-Key.
func (s *Server) handleLocalKdsBumpItem(w http.ResponseWriter, r *http.Request) {
	ctx, ok := DeviceFromContext(r.Context())
	if !ok || ctx == nil {
		writeError(w, http.StatusUnauthorized, "no device context")
		return
	}

	orderID := r.PathValue("order")
	itemID := r.PathValue("item")

	idemKey := r.Header.Get("Idempotency-Key")
	if idemKey == "" {
		writeError(w, http.StatusUnprocessableEntity, "Idempotency-Key header required")
		return
	}

	// Idempotency replay check — return cached response if key already processed.
	if s.idempotency != nil {
		if cached, hit, err := s.idempotency.Get(idemKey, ctx.ID); err == nil && hit {
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte(cached))
			return
		}
	}

	var body struct {
		Status string `json:"status"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	switch body.Status {
	case "preparing", "ready", "served":
		// valid
	default:
		writeError(w, http.StatusUnprocessableEntity, "status must be one of: preparing, ready, served")
		return
	}

	// Look up item + capture previous state for sync payload (Task 2.7 revert path).
	var prevStatus, origUpdatedAt sql.NullString
	err := s.db.QueryRow(`
		SELECT status, updated_at FROM order_items WHERE id = ? AND customer_order_id = ?
	`, itemID, orderID).Scan(&prevStatus, &origUpdatedAt)
	if errors.Is(err, sql.ErrNoRows) {
		writeError(w, http.StatusNotFound, "item not found")
		return
	}
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	occurredAt := time.Now().UTC()
	occurredAtStr := occurredAt.Format(time.RFC3339)

	// Only set served_at when status transitions to "served".
	var servedAtStr sql.NullString
	if body.Status == "served" {
		servedAtStr = sql.NullString{String: occurredAtStr, Valid: true}
	}

	_, err = s.db.Exec(`
		UPDATE order_items
		SET status = ?, served_at = COALESCE(?, served_at), updated_at = ?
		WHERE id = ? AND customer_order_id = ?
	`, body.Status, servedAtStr, occurredAtStr, itemID, orderID)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	// Broadcast WS event scoped to the item's branch — KDS clients in the same
	// branch can dedup via idempotency_key in the payload.
	if s.hub != nil {
		s.hub.BroadcastEventScoped("order_item.status_changed", map[string]any{
			"order_id":        orderID,
			"item_id":         itemID,
			"previous_status": prevStatus.String,
			"status":          body.Status,
			"served_at":       nullStrToAny(servedAtStr),
			"idempotency_key": idemKey,
			"occurred_at":     occurredAtStr,
			"source":          "local",
		}, ctx.BranchID)
	}

	// Enqueue sync UP — Task 2.7 will register the handler for this resource type.
	// Payload carries previous_status + original_updated_at so the sync handler
	// can detect cloud 409 conflicts and revert local replica.
	if s.sync != nil {
		syncPayload := map[string]any{
			"order_id":            orderID,
			"item_id":             itemID,
			"status":              body.Status,
			"idempotency_key":     idemKey,
			"previous_status":     prevStatus.String,
			"original_updated_at": origUpdatedAt.String,
			"actor_kds_device_id": ctx.ID,
			"occurred_at":         occurredAtStr,
		}
		if err := s.sync.Enqueue("customer_order_item", itemID, "update_status", syncPayload, 2); err != nil {
			slog.Warn("kds bump sync enqueue failed", "item_id", itemID, "err", err)
			// Don't fail the request — item is updated locally, sync will retry.
		}
	}

	response := map[string]any{
		"data": map[string]any{
			"id":         itemID,
			"status":     body.Status,
			"served_at":  nullStrToAny(servedAtStr),
			"updated_at": occurredAtStr,
		},
	}
	respBytes, _ := json.Marshal(response)

	// Cache response for idempotency replay.
	if s.idempotency != nil {
		if err := s.idempotency.Put(idemKey, ctx.ID, "", string(respBytes)); err != nil {
			slog.Warn("kds bump idempotency cache put failed", "key", idemKey, "err", err)
		}
	}

	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(respBytes)
}
