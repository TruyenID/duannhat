package handler

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// #1080 — the LAN read must preserve device restrictions and channel gating.

func seedEffectiveOptionRow(t *testing.T, s *Server, deviceID any, channel, optionID string, effective int) {
	t.Helper()
	if _, err := s.db.Exec(`
		INSERT INTO effective_payment_options
			(device_id, channel, id, display_name, provider, rail, effective, sort_order)
		VALUES (?, ?, ?, 'Cash', 'internal', 'cash', ?, 0)`,
		deviceID, channel, optionID, effective,
	); err != nil {
		t.Fatalf("seed row: %v", err)
	}
	if _, err := s.db.Exec(`
		INSERT INTO payment_policy_snapshot (id, revision, snapshot_hash)
		VALUES (1, 3, 'h') ON CONFLICT(id) DO NOTHING`); err != nil {
		t.Fatalf("seed snapshot: %v", err)
	}
}

func effectiveOptionsVia(t *testing.T, s *Server, path, deviceType, deviceID string) []map[string]any {
	t.Helper()
	req := httptest.NewRequest(http.MethodGet, path, nil)
	ctx := context.WithValue(req.Context(), deviceCtxKey, &DeviceContext{
		ID: deviceID, Type: deviceType, BranchID: "br-1", IdentityType: "device",
	})
	w := httptest.NewRecorder()
	s.handleLocalEffectivePaymentOptions(w, req.WithContext(ctx))
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d (%s)", w.Code, w.Body.String())
	}
	var resp struct {
		Data struct {
			Options []map[string]any `json:"options"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("decode: %v", err)
	}
	return resp.Data.Options
}

func TestLocalEffectiveOptions_DeviceDisableHoldsOnLAN(t *testing.T) {
	s := newFireTestServer(t)

	// Branch fallbacks: option effective on both channels.
	seedEffectiveOptionRow(t, s, nil, "pos", "opt-cash", 1)
	seedEffectiveOptionRow(t, s, nil, "kiosk", "opt-cash", 1)
	// The kiosk terminal has a device-specific row disabling it.
	seedEffectiveOptionRow(t, s, "kiosk-1", "kiosk", "opt-cash", 0)

	kioskOpts := effectiveOptionsVia(t, s, "/api/v1/kiosk/effective-payment-options", "kiosk", "kiosk-1")
	if len(kioskOpts) != 1 || kioskOpts[0]["effective"] != false {
		t.Errorf("kiosk-1 must see its device-scoped DISABLED row, got %v", kioskOpts)
	}

	// A different kiosk without a device row falls back to the branch set.
	otherOpts := effectiveOptionsVia(t, s, "/api/v1/kiosk/effective-payment-options", "kiosk", "kiosk-2")
	if len(otherOpts) != 1 || otherOpts[0]["effective"] != true {
		t.Errorf("kiosk-2 must fall back to the effective branch row, got %v", otherOpts)
	}

	// POS keeps its channel's own set — the kiosk disable must not leak.
	posOpts := effectiveOptionsVia(t, s, "/api/v1/pos/effective-payment-options", "pos", "pos-1")
	if len(posOpts) != 1 || posOpts[0]["effective"] != true {
		t.Errorf("pos must keep the effective pos row, got %v", posOpts)
	}
}

func TestLocalEffectiveOptions_LegacyRowsServeWhenNoChannelData(t *testing.T) {
	s := newFireTestServer(t)

	// Old-Cloud fallback data: channel '' rows only.
	seedEffectiveOptionRow(t, s, nil, "", "opt-legacy", 1)

	posOpts := effectiveOptionsVia(t, s, "/api/v1/pos/effective-payment-options", "pos", "pos-1")
	if len(posOpts) != 1 || posOpts[0]["id"] != "opt-legacy" {
		t.Errorf("pos must fall back to legacy channel='' rows, got %v", posOpts)
	}
}

// Regression: pos-web reads option.client.supports_pos_checkout unconditionally
// (checkoutCapableOptions). A LAN read that omitted the `client` block
// white-screened the whole POS the instant an effective tender was returned.
// Every option row must therefore carry a `client` object — even rows the
// enricher never touched, which default to all-false.
func TestLocalEffectiveOptions_ClientBlockAlwaysPresent(t *testing.T) {
	s := newFireTestServer(t)

	// A bare row (no client_json → DEFAULT '{}') stands in for a kiosk-channel
	// or legacy old-Cloud option the enricher never enriched.
	seedEffectiveOptionRow(t, s, nil, "pos", "opt-cash", 1)

	posOpts := effectiveOptionsVia(t, s, "/api/v1/pos/effective-payment-options", "pos", "pos-1")
	if len(posOpts) != 1 {
		t.Fatalf("want 1 option, got %d (%v)", len(posOpts), posOpts)
	}
	client, ok := posOpts[0]["client"].(map[string]any)
	if !ok {
		t.Fatalf("client must be an object, got %T (%v)", posOpts[0]["client"], posOpts[0]["client"])
	}
	for _, key := range []string{"requires_tendered", "immediate_settlement", "supports_pos_checkout"} {
		if _, present := client[key]; !present {
			t.Errorf("client.%s missing — pos-web dereferences it, got %v", key, client)
		}
	}
	if client["supports_pos_checkout"] != false {
		t.Errorf("un-enriched row must default supports_pos_checkout=false, got %v", client["supports_pos_checkout"])
	}
}

// A stored client block (the pos/self_regi enrichment synced DOWN) must
// round-trip verbatim through the LAN read, alongside method_type + legacy
// identity — full parity with the direct-Cloud POS endpoint.
func TestLocalEffectiveOptions_ClientBlockRoundTrips(t *testing.T) {
	s := newFireTestServer(t)

	if _, err := s.db.Exec(`
		INSERT INTO effective_payment_options
			(device_id, channel, id, display_name, provider, rail, effective, sort_order,
			 method_type, legacy_payment_method_id, legacy_payment_method_code, client_json)
		VALUES (NULL, 'pos', 'opt-cash', 'Cash', 'internal', 'cash', 1, 0,
			'cash', 'pm-cash-1', 'cash',
			'{"requires_tendered":true,"immediate_settlement":true,"supports_pos_checkout":true}')`,
	); err != nil {
		t.Fatalf("seed enriched row: %v", err)
	}
	if _, err := s.db.Exec(`
		INSERT INTO payment_policy_snapshot (id, revision, snapshot_hash)
		VALUES (1, 3, 'h') ON CONFLICT(id) DO NOTHING`); err != nil {
		t.Fatalf("seed snapshot: %v", err)
	}

	posOpts := effectiveOptionsVia(t, s, "/api/v1/pos/effective-payment-options", "pos", "pos-1")
	if len(posOpts) != 1 {
		t.Fatalf("want 1 option, got %d (%v)", len(posOpts), posOpts)
	}
	opt := posOpts[0]
	if opt["method_type"] != "cash" || opt["legacy_payment_method_id"] != "pm-cash-1" || opt["legacy_payment_method_code"] != "cash" {
		t.Errorf("enricher identity fields must round-trip, got %v", opt)
	}
	client, ok := opt["client"].(map[string]any)
	if !ok {
		t.Fatalf("client must be an object, got %T", opt["client"])
	}
	if client["requires_tendered"] != true || client["immediate_settlement"] != true || client["supports_pos_checkout"] != true {
		t.Errorf("stored client block must round-trip verbatim, got %v", client)
	}
}
