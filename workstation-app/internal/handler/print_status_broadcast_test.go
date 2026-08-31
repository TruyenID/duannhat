package handler

import (
	"encoding/json"
	"path/filepath"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// TestBroadcastPrintStatus verifies WS-1: workstation emits a 'print_status'
// event after the blind auto-print at payment confirm so the kiosk learns
// whether the "DA THANH TOAN" bill actually printed.
func TestBroadcastPrintStatus(t *testing.T) {
	db, err := store.Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	if _, err := db.Exec(`INSERT OR REPLACE INTO settings (key, value) VALUES ('workstation_branch_id', 'branch-1')`); err != nil {
		t.Fatalf("seed branch: %v", err)
	}

	hub := NewHub()
	go hub.Run()
	defer hub.Stop()

	client := &Client{authedBy: "kiosk-1", branchID: "branch-1", send: make(chan []byte, 4)}
	hub.register <- client
	time.Sleep(10 * time.Millisecond)

	s := &Server{db: db, hub: hub}

	recv := func() Message {
		t.Helper()
		select {
		case raw := <-client.send:
			var m Message
			if err := json.Unmarshal(raw, &m); err != nil {
				t.Fatalf("decode message: %v", err)
			}
			return m
		case <-time.After(200 * time.Millisecond):
			t.Fatalf("kiosk did not receive print_status event")
			return Message{}
		}
	}

	// Success: no error → status "success", no reason/detail.
	s.broadcastPrintStatus("order-1", "payment_receipt", nil)
	m := recv()
	if m.Type != "print_status" {
		t.Fatalf("type = %q, want print_status", m.Type)
	}
	p := m.Payload.(map[string]any)
	if p["status"] != "success" {
		t.Fatalf("status = %v, want success", p["status"])
	}
	if p["order_id"] != "order-1" || p["kind"] != "payment_receipt" {
		t.Fatalf("unexpected payload: %v", p)
	}
	if _, ok := p["reason"]; ok {
		t.Fatalf("success event should carry no reason: %v", p)
	}

	// Failure (connection error) → status "failed", reason printer_offline.
	s.broadcastPrintStatus("order-1", "payment_receipt", errConnRefused{})
	m = recv()
	p = m.Payload.(map[string]any)
	if p["status"] != "failed" {
		t.Fatalf("status = %v, want failed", p["status"])
	}
	if p["reason"] != "printer_offline" {
		t.Fatalf("reason = %v, want printer_offline", p["reason"])
	}
	if p["detail"] == "" || p["detail"] == nil {
		t.Fatalf("failed event should carry a detail string")
	}
}

func TestClassifyPrintError(t *testing.T) {
	cases := []struct {
		msg  string
		want string
	}{
		{"dial tcp 192.168.1.50:9100: connect: connection refused", "printer_offline"},
		{"write tcp: broken pipe", "printer_offline"},
		{"i/o timeout", "printer_offline"},
		{"ESC/POS status: cover open", "printer_error"},
		{"unexpected reply byte", "printer_error"},
	}
	for _, c := range cases {
		if got := classifyPrintError(errString(c.msg)); got != c.want {
			t.Errorf("classifyPrintError(%q) = %q, want %q", c.msg, got, c.want)
		}
	}
	if got := classifyPrintError(nil); got != "" {
		t.Errorf("classifyPrintError(nil) = %q, want empty", got)
	}
}

type errConnRefused struct{}

func (errConnRefused) Error() string { return "dial tcp 10.0.0.5:9100: connect: connection refused" }

type errString string

func (e errString) Error() string { return string(e) }
