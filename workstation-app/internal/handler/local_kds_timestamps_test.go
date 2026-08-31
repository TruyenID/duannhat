package handler

// Tests for Phase 5.4 plan-028 — KDS item timestamp persistence + aging
// computation + FE↔BE contract on bump-all per-item idempotency key format.

import (
	"database/sql"
	"encoding/json"
	"net/http"
	"strings"
	"testing"
	"time"
)

// getItemTimestamp reads a single timestamp column from order_items.
func getItemTimestamp(t *testing.T, s *Server, itemID, column string) sql.NullString {
	t.Helper()
	var ts sql.NullString
	if err := s.db.QueryRow(`SELECT `+column+` FROM order_items WHERE id = ?`, itemID).Scan(&ts); err != nil {
		t.Fatalf("read %s: %v", column, err)
	}
	return ts
}

// ─── Phase 5.4: mark-* sets the corresponding timestamp column ────────────────

func TestKds_MarkPreparing_SetsStartedPreparingAt(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	ids := seedKdsOpsData(t, s, "order-ts1", []string{"pending"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-ts1/items/"+ids[0]+"/mark-preparing",
		"kds-device-token", "idem-ts-1")
	if w.Code != http.StatusOK {
		t.Fatalf("status=%d body=%s", w.Code, w.Body.String())
	}

	ts := getItemTimestamp(t, s, ids[0], "started_preparing_at")
	if !ts.Valid || ts.String == "" {
		t.Fatalf("expected started_preparing_at populated, got NULL/empty")
	}

	// Response should also carry the timestamp (non-nil).
	var resp map[string]any
	_ = json.NewDecoder(w.Body).Decode(&resp)
	data := resp["data"].(map[string]any)
	if data["started_preparing_at"] == nil {
		t.Errorf("expected response.data.started_preparing_at populated, got nil")
	}
}

func TestKds_MarkReady_SetsReadyAt(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	ids := seedKdsOpsData(t, s, "order-ts2", []string{"preparing"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-ts2/items/"+ids[0]+"/mark-ready",
		"kds-device-token", "idem-ts-2")
	if w.Code != http.StatusOK {
		t.Fatalf("status=%d body=%s", w.Code, w.Body.String())
	}

	if ts := getItemTimestamp(t, s, ids[0], "ready_at"); !ts.Valid {
		t.Fatalf("expected ready_at populated")
	}
}

func TestKds_MarkServed_SetsServedAt(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	ids := seedKdsOpsData(t, s, "order-ts3", []string{"ready"})
	// Satisfy the 30s anti-misclick window (KDS_E003) so mark-served proceeds.
	_, _ = s.db.Exec(`UPDATE order_items SET ready_at = ? WHERE id = ?`,
		time.Now().UTC().Add(-60*time.Second).Format(time.RFC3339), ids[0])

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPost(t, mux, "/api/v1/kds/orders/order-ts3/items/"+ids[0]+"/mark-served",
		"kds-device-token", "idem-ts-3")
	if w.Code != http.StatusOK {
		t.Fatalf("status=%d body=%s", w.Code, w.Body.String())
	}

	if ts := getItemTimestamp(t, s, ids[0], "served_at"); !ts.Valid {
		t.Fatalf("expected served_at populated")
	}
}

// ─── Revert preserves timestamps as historical anchors ───────────────────────

// Timestamps record "when did this item first reach status X", not "is the
// item currently in status X". Revert → re-bump cycles preserve them so the
// COALESCE chain in kdsItemResource keeps producing stable aging. Cloud uses
// the same semantic; reviewer caught the original "clear on revert" attempt.
func TestKds_RevertPreservesTimestamps(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	ids := seedKdsOpsData(t, s, "order-rvts", []string{"ready"})
	_, _ = s.db.Exec(`UPDATE order_items SET started_preparing_at='2026-05-28T09:50:00Z', ready_at='2026-05-28T09:55:00Z' WHERE id=?`, ids[0])

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	w := doPostBody(t, mux, "/api/v1/kds/orders/order-rvts/items/"+ids[0]+"/revert",
		"kds-device-token", "idem-rvts-1", `{"to":"preparing"}`)
	if w.Code != http.StatusOK {
		t.Fatalf("status=%d body=%s", w.Code, w.Body.String())
	}
	if getItemStatus(t, s, ids[0]) != "preparing" {
		t.Errorf("expected status=preparing after revert from ready")
	}
	if ts := getItemTimestamp(t, s, ids[0], "started_preparing_at"); !ts.Valid {
		t.Errorf("started_preparing_at must survive revert")
	}
	if ts := getItemTimestamp(t, s, ids[0], "ready_at"); !ts.Valid {
		t.Errorf("ready_at must survive revert — historical anchor stays even after status change")
	}
}

// ─── Clock skew defensive: aging never goes negative ─────────────────────────

func TestKds_AgingMinutes_ClampsNegative(t *testing.T) {
	// Item "created" in the future relative to "now" (workstation clock went
	// backwards via NTP correction or DST screwup). Aging must clamp to 0
	// rather than emit a negative integer FE has no way to render sanely.
	createdAt := sql.NullString{String: "2026-05-28T12:00:00Z", Valid: true}
	earlier, _ := time.Parse(time.RFC3339, "2026-05-28T11:50:00Z")
	if m := agingMinutes(createdAt, earlier); m != 0 {
		t.Errorf("clock skew: expected aging=0, got %d", m)
	}
	// Same for time_in_current_status_seconds.
	if s := timeInCurrentStatusSeconds("pending", createdAt, sql.NullString{}, sql.NullString{}, sql.NullString{}, earlier); s != 0 {
		t.Errorf("clock skew: expected status_seconds=0, got %d", s)
	}
}

func TestKds_AgingMinutes_NullCreatedAt(t *testing.T) {
	// Defensive: created_at is NOT NULL in schema but the resource builder
	// shouldn't panic if a future migration relaxes that.
	if m := agingMinutes(sql.NullString{}, time.Now()); m != 0 {
		t.Errorf("expected 0 for null created_at, got %d", m)
	}
}

// ─── allowed_transitions emits operation names, not status names ──────────────

func TestKds_AllowedTransitions_MatchesCloudFormat(t *testing.T) {
	tests := []struct {
		status string
		want   []string
	}{
		{"pending", []string{"mark-preparing"}},
		{"preparing", []string{"mark-ready", "revert"}},
		{"ready", []string{"mark-served", "revert"}},
		{"served", []string{}}, // terminal — revert handler rejects served (KDS_E002)
		{"voided", []string{}},
	}
	for _, tt := range tests {
		got := allowedKdsTransitions(tt.status)
		if len(got) != len(tt.want) {
			t.Errorf("status %s: got %v, want %v", tt.status, got, tt.want)
			continue
		}
		for i, v := range got {
			if v != tt.want[i] {
				t.Errorf("status %s: pos %d got %s want %s", tt.status, i, v, tt.want[i])
			}
		}
	}
}

// ─── FE↔BE CONTRACT: bump-all per-item idempotency_key = "${batchKey}:${itemId}" ─

// godx-kds useBumpAll pre-records `${batchKey}:${itemId}` for every item it
// expects cloud to bump. The workstation handler must enqueue + broadcast in
// the same format or LAN-mode bumps double-flicker on the tablet. We assert
// the format via sync_queue rows — broadcast capture would require a Hub test
// hook that doesn't exist yet.
func TestKds_BumpAll_PerItemIdempotencyKeyFormat(t *testing.T) {
	cloud := mockKdsMeCloud(t, "kds-1", "branch-A")
	s, _ := newServerWithAuth(t, cloud.URL)
	ids := seedKdsOpsData(t, s, "order-bk", []string{"pending", "pending", "preparing"})

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	batchKey := "batch-uuid-abc"
	w := doPost(t, mux, "/api/v1/kds/orders/order-bk/bump-all", "kds-device-token", batchKey)
	if w.Code != http.StatusOK {
		t.Fatalf("status=%d body=%s", w.Code, w.Body.String())
	}

	// Each bumped item should have a sync_queue row whose payload contains
	// idempotency_key = "${batchKey}:${itemId}" — single colon, matching cloud
	// KdsController::bumpAll + OrderItemStatusChanged broadcast format.
	rows, err := s.db.Query(`SELECT payload FROM sync_queue WHERE entity_type = 'customer_order_item'`)
	if err != nil {
		t.Fatalf("query sync_queue: %v", err)
	}
	defer rows.Close()

	gotKeys := map[string]bool{}
	for rows.Next() {
		var payloadJSON string
		if err := rows.Scan(&payloadJSON); err != nil {
			t.Fatalf("scan: %v", err)
		}
		var p map[string]any
		if err := json.Unmarshal([]byte(payloadJSON), &p); err != nil {
			continue
		}
		k, ok := p["idempotency_key"].(string)
		if !ok {
			continue
		}
		if strings.Contains(k, "::") {
			t.Errorf("found double-colon in idempotency_key %q — must match cloud single-colon format", k)
		}
		gotKeys[k] = true
	}
	for _, id := range ids {
		expected := batchKey + ":" + id
		if !gotKeys[expected] {
			t.Errorf("expected sync_queue idempotency_key %q, got keys %v", expected, gotKeys)
		}
	}
}
