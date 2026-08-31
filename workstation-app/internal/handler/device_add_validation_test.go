package handler

import (
	"bytes"
	"encoding/json"
	"net"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/store"
)

// newDeviceServer builds the minimum Server handleAddDevice touches: a printer
// manager over a temp DB. audit/monitor stay nil (auditLog is nil-safe).
func newDeviceServer(t *testing.T) *Server {
	t.Helper()
	db, err := store.Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return &Server{db: db, devices: printer.NewManager(db)}
}

func postDevice(t *testing.T, s *Server, payload map[string]any) *httptest.ResponseRecorder {
	t.Helper()
	body, _ := json.Marshal(payload)
	req := httptest.NewRequest("POST", "/api/devices", bytes.NewReader(body))
	w := httptest.NewRecorder()
	s.handleAddDevice(w, req)
	return w
}

// A rejected address is the caller's mistake, not a workstation fault. Routing
// ValidateAddress's error through writeServerError answered 500 with a generic
// "internal error" body, so the UI could not tell the operator what was wrong
// with what they typed — and a 500 reads as "the app is broken".
func TestAddDevice_InvalidAddressIsBadRequestWithReason(t *testing.T) {
	cases := []struct {
		name     string
		connType string
		address  string
		wantSubs string
	}{
		{"public IP is refused", "network", "8.8.8.8:9100", "private"},
		{"missing port", "network", "192.168.1.50", "host:port"},
		{"port out of range", "network", "192.168.1.50:99999", "port"},
		{"arbitrary hostname", "network", "printer.example.com:9100", "private IP or .local"},
		{"usb path traversal", "usb", "/dev/../etc/passwd", "device node"},
		{"unknown connection type", "carrier-pigeon", "somewhere", "unsupported"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			s := newDeviceServer(t)
			w := postDevice(t, s, map[string]any{
				"name":            "P",
				"roles":           []string{"kitchen_printer"},
				"connection_type": tc.connType,
				"address":         tc.address,
				"paper_width":     80,
			})

			if w.Code != http.StatusBadRequest {
				t.Fatalf("status = %d, want 400 — body=%s", w.Code, w.Body.String())
			}
			// `message` is the key writeError emits and the first key the
			// frontend's api.ts reads — asserting on it keeps the reason
			// actually reaching the operator, not just the status code.
			var body struct {
				Message string `json:"message"`
			}
			if err := json.Unmarshal(w.Body.Bytes(), &body); err != nil {
				t.Fatalf("decode body %q: %v", w.Body.String(), err)
			}
			if body.Message == "" {
				t.Fatal("error message is empty — the operator learns nothing")
			}
			if !strings.Contains(body.Message, tc.wantSubs) {
				t.Errorf("error %q does not explain the problem (want it to mention %q)",
					body.Message, tc.wantSubs)
			}

			// A rejected request must not leave a half-created row behind.
			if got := len(s.devices.ListDevices()); got != 0 {
				t.Errorf("%d device(s) persisted despite rejection", got)
			}
		})
	}
}

// The valid path must still work, and must report the created device.
func TestAddDevice_ValidAddressIsCreated(t *testing.T) {
	s := newDeviceServer(t)

	// handleAddDevice kicks off `go p.Probe()`, which dials for up to
	// probeTimeout. Pointing it at a live loopback listener makes that
	// goroutine finish immediately — an unreachable address would leave it
	// running for 2s, long enough for TestServerStop_NoGoroutineLeak to run
	// inside that window and report it as a leak.
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	defer ln.Close()
	go func() {
		for {
			conn, err := ln.Accept()
			if err != nil {
				return
			}
			conn.Close()
		}
	}()

	w := postDevice(t, s, map[string]any{
		"name":            "Kitchen",
		"roles":           []string{"kitchen_printer", "receipt_printer"},
		"connection_type": "network",
		"address":         ln.Addr().String(),
		"paper_width":     80,
	})

	if w.Code != http.StatusCreated {
		t.Fatalf("status = %d, want 201 — body=%s", w.Code, w.Body.String())
	}
	var body struct {
		Device struct {
			ID      string   `json:"id"`
			Name    string   `json:"name"`
			Roles   []string `json:"roles"`
			Address string   `json:"address"`
		} `json:"device"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &body); err != nil {
		t.Fatalf("decode body %q: %v", w.Body.String(), err)
	}
	if body.Device.ID == "" {
		t.Error("created device has no id")
	}
	if body.Device.Name != "Kitchen" {
		t.Errorf("name = %q, want Kitchen", body.Device.Name)
	}
	if len(body.Device.Roles) != 2 {
		t.Errorf("roles = %v, want both roles echoed back", body.Device.Roles)
	}
	if got := len(s.devices.ListDevices()); got != 1 {
		t.Errorf("%d devices persisted, want 1", got)
	}
}

// Roles are required, and that rejection was already a 400 — keep it that way.
func TestAddDevice_MissingRoleIsBadRequest(t *testing.T) {
	s := newDeviceServer(t)
	w := postDevice(t, s, map[string]any{
		"name":            "P",
		"connection_type": "network",
		"address":         "192.168.1.50:9100",
	})
	if w.Code != http.StatusBadRequest {
		t.Fatalf("status = %d, want 400 — body=%s", w.Code, w.Body.String())
	}
}
