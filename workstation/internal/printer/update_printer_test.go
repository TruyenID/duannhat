package printer

import (
	"path/filepath"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

func newManagerWithPrinter(t *testing.T) (*Manager, *Printer) {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), "p.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	m := NewManager(db)
	p, err := m.AddPrinter("Kitchen", []DeviceType{TypeKitchenPrinter},
		ConnNetwork, "192.168.1.19:9100", PrinterConfig{PaperWidth: 80})
	if err != nil {
		t.Fatalf("add printer: %v", err)
	}
	return m, p
}

// The point of UpdatePrinter: an address typo or a DHCP move must be fixable
// WITHOUT delete + re-add, which mints a new id and orphans everything keyed on
// the old one. So the id has to survive the edit.
func TestUpdatePrinter_EditsInPlaceKeepingIDAndRoles(t *testing.T) {
	m, p := newManagerWithPrinter(t)
	originalID := p.ID()

	if err := m.UpdatePrinter(originalID, "Bếp", ConnNetwork, "10.0.0.5:9101",
		PrinterConfig{PaperWidth: 58}); err != nil {
		t.Fatalf("UpdatePrinter: %v", err)
	}

	dev, ok := m.GetPrinter(originalID)
	if !ok {
		t.Fatal("printer disappeared after an edit")
	}
	if dev.Name() != "Bếp" || dev.address != "10.0.0.5:9101" {
		t.Errorf("in-memory device = %q @ %q, want the edited values", dev.Name(), dev.address)
	}
	if dev.config.PaperWidth != 58 {
		t.Errorf("paper width = %d, want 58", dev.config.PaperWidth)
	}
	// Roles belong to the other endpoint and must be left alone.
	if len(dev.Roles()) != 1 || dev.Roles()[0] != TypeKitchenPrinter {
		t.Errorf("roles = %v, want them untouched", dev.Roles())
	}

	// Persisted, not just swapped in memory — a restart must see the edit.
	var name, address string
	if err := m.db.QueryRow(
		`SELECT name, address FROM printers WHERE id = ?`, originalID,
	).Scan(&name, &address); err != nil {
		t.Fatalf("read back: %v", err)
	}
	if name != "Bếp" || address != "10.0.0.5:9101" {
		t.Errorf("persisted %q @ %q, want the edited values", name, address)
	}
}

// An edit must not be able to store an address the add path would have refused
// — otherwise the SSRF/arbitrary-path gate (#85) is bypassable by creating a
// valid printer and then editing it.
func TestUpdatePrinter_RejectsInvalidAddressAndKeepsTheOldOne(t *testing.T) {
	m, p := newManagerWithPrinter(t)

	if err := m.UpdatePrinter(p.ID(), "Kitchen", ConnNetwork, "http://evil.example.com",
		PrinterConfig{PaperWidth: 80}); err == nil {
		t.Fatal("accepted an address the add path rejects")
	}

	var address string
	_ = m.db.QueryRow(`SELECT address FROM printers WHERE id = ?`, p.ID()).Scan(&address)
	if address != "192.168.1.19:9100" {
		t.Errorf("persisted address = %q, want the original — a rejected edit must not write", address)
	}
	if p.address != "192.168.1.19:9100" {
		t.Errorf("in-memory address = %q, want the original", p.address)
	}
}

func TestUpdatePrinter_RejectsEmptyNameAndUnknownID(t *testing.T) {
	m, p := newManagerWithPrinter(t)

	if err := m.UpdatePrinter(p.ID(), "   ", ConnNetwork, "10.0.0.5:9100", PrinterConfig{}); err == nil {
		t.Error("accepted a blank name")
	}
	if err := m.UpdatePrinter("no-such-id", "X", ConnNetwork, "10.0.0.5:9100", PrinterConfig{}); err == nil {
		t.Error("accepted an unknown device id")
	}
}

// A DB restored from a pre-051 backup still carries "hold_printer". Migration
// 051 won't re-run on it (its version is already recorded), so the read path
// has to normalise, or that printer matches no role and the station silently
// stops printing.
func TestParseRoles_NormalisesLegacyHoldPrinter(t *testing.T) {
	got := parseRoles(`["kitchen_printer","hold_printer"]`, "")
	if len(got) != 2 || got[1] != TypeHallPrinter {
		t.Errorf("roles = %v, want the legacy value mapped to %q", got, TypeHallPrinter)
	}

	// Same for the legacy `type` fallback when roles is empty.
	if got := parseRoles("", "hold_printer"); len(got) != 1 || got[0] != TypeHallPrinter {
		t.Errorf("fallback roles = %v, want [%s]", got, TypeHallPrinter)
	}
}
