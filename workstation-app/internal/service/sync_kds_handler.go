package service

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// BroadcastHub is the subset of handler.Hub that KdsBumpSyncHandler needs to
// emit corrective WS events on cloud 409. In production this is *handler.Hub;
// in tests a stub implements the single method.
type BroadcastHub interface {
	BroadcastEventScoped(eventType string, payload any, branchID string)
}

// KdsBumpSyncHandler forwards KDS item-status bumps that are enqueued by the
// local PATCH /api/v1/kds/orders/{o}/items/{i}/status handler to the cloud
// PATCH /api/v1/workstation/orders/{o}/items/{i}/status endpoint.
//
// Cloud 200 → success, no further action.
//
// Cloud 409 → cloud rejected the transition (e.g., item was voided
// concurrently). Handler reverts workstation's local order_items.status to
// previous_status (rolling back updated_at too so pull-DOWN cursor queries
// can still catch genuinely-newer cloud changes) and broadcasts a corrective
// WS event so KDS clients show accurate state. Returns retryable=false —
// cloud is authoritative, no retry will help.
//
// 503 / network errors → retryable=true, sync worker retries with backoff.
type KdsBumpSyncHandler struct {
	httpClient  *http.Client
	cloudURLFn  func() string
	deviceToken func() string
	db          *store.DB
	hub         BroadcastHub
	branchIDFn  func() string
	// tracer records the cloud-409 revert distinctly — the sync worker sees a
	// 409 as terminal-success (no retry) so processQueue would otherwise log it
	// as a plain OK, hiding that the local state was rolled back. Optional.
	tracer *SyncTracer
}

// SetTracer wires the shared sync tracer so cloud-409 reverts surface in the
// merged sync feed. Pass syncEngine.Tracer().
func (h *KdsBumpSyncHandler) SetTracer(t *SyncTracer) { h.tracer = t }

// NewKdsBumpSyncHandler constructs the handler. All function arguments are
// read on every call so runtime re-pairing takes effect without restart.
func NewKdsBumpSyncHandler(
	db *store.DB,
	cloudURLFn func() string,
	deviceToken func() string,
	hub BroadcastHub,
	branchIDFn func() string,
) *KdsBumpSyncHandler {
	return &KdsBumpSyncHandler{
		httpClient:  &http.Client{Timeout: 15 * time.Second},
		cloudURLFn:  cloudURLFn,
		deviceToken: deviceToken,
		db:          db,
		hub:         hub,
		branchIDFn:  branchIDFn,
	}
}

// Handle processes a customer_order_item.update_status sync_queue entry.
// Signature matches the syncHandler type: (ctx, entityID, payload) → (resp, retryable, err).
func (h *KdsBumpSyncHandler) Handle(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
	orderID, _ := payload["order_id"].(string)
	itemID, _ := payload["item_id"].(string)
	status, _ := payload["status"].(string)
	idemKey, _ := payload["idempotency_key"].(string)
	prevStatus, _ := payload["previous_status"].(string)
	origUpdatedAt, _ := payload["original_updated_at"].(string)
	actorKds, _ := payload["actor_kds_device_id"].(string)

	baseURL := h.cloudURLFn()
	if baseURL == "" {
		return nil, true, fmt.Errorf("cloud URL not configured")
	}

	// Resolve cloud_id for the order — local SQLite IDs are not known to Cloud.
	// If cloud_id is empty the order hasn't synced UP yet; retry until it does.
	cloudOrderID := ""
	if err := h.db.QueryRow(`SELECT COALESCE(cloud_id,'') FROM orders WHERE id = ?`, orderID).Scan(&cloudOrderID); err != nil {
		return nil, true, fmt.Errorf("resolve cloud_id for order %s: %w", orderID, err)
	}
	if cloudOrderID == "" {
		// Local prerequisite: the order's own create push hasn't filled cloud_id
		// yet. Wrap errDependencyNotReady so processQueue treats this as a
		// head-of-line SKIP (continue to independent rows), NOT a Cloud-wide
		// outage (return, halting the cycle). Without the wrap, this priority-2
		// bump — processed before the priority-1 order.create — would `return`
		// out of every cycle and starve the create forever, so cloud_id never
		// fills and the bump loops indefinitely.
		return nil, true, fmt.Errorf("order %s not yet synced to cloud (cloud_id empty): %w", orderID, errDependencyNotReady)
	}

	reqBody := map[string]any{
		"status":              status,
		"actor_kds_device_id": actorKds,
	}
	if snapshot, ok := payload["item_snapshot"].(map[string]any); ok {
		reqBody["item_snapshot"] = snapshot
	}

	body, err := json.Marshal(reqBody)
	if err != nil {
		return nil, false, fmt.Errorf("marshal body: %w", err)
	}

	url := fmt.Sprintf("%s/api/v1/workstation/orders/%s/items/%s/status", baseURL, cloudOrderID, itemID)
	req, err := http.NewRequestWithContext(ctx, http.MethodPatch, url, bytes.NewReader(body))
	if err != nil {
		return nil, false, fmt.Errorf("build PATCH request: %w", err)
	}
	req.Header.Set("Authorization", "Bearer "+h.deviceToken())
	req.Header.Set("Idempotency-Key", idemKey)
	req.Header.Set("Content-Type", "application/json")

	resp, err := h.httpClient.Do(req)
	if err != nil {
		// Network / connection errors are transient — retry.
		return nil, true, fmt.Errorf("PATCH item status: %w", err)
	}
	defer resp.Body.Close()

	switch {
	case resp.StatusCode >= 200 && resp.StatusCode < 300:
		return nil, false, nil

	case resp.StatusCode == http.StatusConflict:
		// Cloud rejected the status transition — revert local replica so workstation
		// does not diverge from cloud's authoritative state.
		if _, dbErr := h.db.Exec(`
			UPDATE order_items
			SET status = ?, updated_at = ?
			WHERE id = ?
		`, prevStatus, origUpdatedAt, itemID); dbErr != nil {
			slog.Error("kds sync 409 revert failed", "item_id", itemID, "err", dbErr)
			// Revert is best-effort. The conflict itself is terminal; do not
			// return an error that would cause a retry (which won't fix anything).
		}

		if h.hub != nil && h.branchIDFn != nil {
			h.hub.BroadcastEventScoped("order_item.status_changed", map[string]any{
				"order_id":        orderID,
				"item_id":         itemID,
				"previous_status": status,     // the rejected attempted status
				"status":          prevStatus, // back to what it was before the bump
				"idempotency_key": idemKey,
				"source":          "revert",
				"reason":          "sync_conflict",
			}, h.branchIDFn())
		}

		slog.Warn("kds sync UP rejected by cloud — reverted locally",
			"item_id", itemID,
			"attempted_status", status,
			"reverted_to", prevStatus,
		)
		if h.tracer != nil {
			h.tracer.record(SyncTraceEvent{
				Flow:       string(FlowKds),
				Phase:      "push",
				TraceID:    idemKey,
				EntityType: "customer_order_item",
				Operation:  "update_status",
				EntityID:   itemID,
				Status:     statusError,
				Error:      fmt.Sprintf("cloud 409 — reverted %s→%s", status, prevStatus),
			})
		}
		// Terminal — no retry.
		return nil, false, nil

	case resp.StatusCode >= 500,
		resp.StatusCode == http.StatusRequestTimeout,
		resp.StatusCode == http.StatusTooManyRequests:
		return nil, true, fmt.Errorf("cloud %d (retryable)", resp.StatusCode)

	default:
		// Other 4xx errors are non-retryable (400, 404, 422…).
		return nil, false, fmt.Errorf("cloud %d (non-retryable)", resp.StatusCode)
	}
}
