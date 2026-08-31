package printer

import (
	"path/filepath"
	"sort"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
)

func newTestManager(t *testing.T) *Manager {
	t.Helper()
	db, err := store.Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return NewManager(db)
}

// A printer with multiple roles answers GetPrinterByRole for each of its roles.
func TestGetPrinterByRole_MultiRole(t *testing.T) {
	m := newTestManager(t)

	p, err := m.AddPrinter("All-in-one",
		[]DeviceType{TypeKitchenPrinter, TypeHoldPrinter, TypeBarPrinter, TypeReceiptPrinter},
		ConnNetwork, "192.168.1.50:9100", PrinterConfig{PaperWidth: 80})
	if err != nil {
		t.Fatalf("add printer: %v", err)
	}

	for _, role := range PrinterRoles {
		got := m.GetPrinterByRole(role)
		if got == nil {
			t.Fatalf("role %s: expected printer, got nil", role)
		}
		if got.ID() != p.ID() {
			t.Errorf("role %s: resolved to %s, want %s", role, got.ID(), p.ID())
		}
	}
}

// Distinct single-role printers each resolve to their own device.
func TestGetPrinterByRole_SeparateDevices(t *testing.T) {
	m := newTestManager(t)

	kitchen, _ := m.AddPrinter("Kitchen", []DeviceType{TypeKitchenPrinter},
		ConnNetwork, "192.168.1.10:9100", PrinterConfig{})
	bar, _ := m.AddPrinter("Bar", []DeviceType{TypeBarPrinter},
		ConnNetwork, "192.168.1.11:9100", PrinterConfig{})

	if got := m.GetPrinterByRole(TypeKitchenPrinter); got == nil || got.ID() != kitchen.ID() {
		t.Errorf("kitchen role resolved to %v, want %s", got, kitchen.ID())
	}
	if got := m.GetPrinterByRole(TypeBarPrinter); got == nil || got.ID() != bar.ID() {
		t.Errorf("bar role resolved to %v, want %s", got, bar.ID())
	}
	// Hold has no printer → nil (no settings fallback anymore).
	if got := m.GetPrinterByRole(TypeHoldPrinter); got != nil {
		t.Errorf("hold role should be unassigned, got %s", got.ID())
	}
}

// AddPrinter requires at least one role.
func TestAddPrinter_RequiresRole(t *testing.T) {
	m := newTestManager(t)
	if _, err := m.AddPrinter("x", nil, ConnNetwork, "1.2.3.4:9100", PrinterConfig{}); err == nil {
		t.Fatal("expected error for empty roles, got nil")
	}
}

func TestRolesWithoutPrinter(t *testing.T) {
	m := newTestManager(t)

	// No printers → every role missing.
	if got := m.RolesWithoutPrinter(); len(got) != len(PrinterRoles) {
		t.Errorf("expected all %d roles missing, got %d", len(PrinterRoles), len(got))
	}

	// One device covers kitchen + hold → bar + receipt remain missing.
	if _, err := m.AddPrinter("KH", []DeviceType{TypeKitchenPrinter, TypeHoldPrinter},
		ConnNetwork, "192.168.1.20:9100", PrinterConfig{}); err != nil {
		t.Fatalf("add printer: %v", err)
	}

	missing := m.RolesWithoutPrinter()
	sort.Slice(missing, func(i, j int) bool { return missing[i] < missing[j] })
	want := []DeviceType{TypeBarPrinter, TypeReceiptPrinter}
	sort.Slice(want, func(i, j int) bool { return want[i] < want[j] })
	if len(missing) != len(want) {
		t.Fatalf("missing roles = %v, want %v", missing, want)
	}
	for i := range want {
		if missing[i] != want[i] {
			t.Errorf("missing[%d] = %s, want %s", i, missing[i], want[i])
		}
	}
}

// LoadFromDB rehydrates the roles list from the persisted JSON column.
func TestLoadFromDB_RestoresRoles(t *testing.T) {
	dir := t.TempDir()
	dbPath := filepath.Join(dir, "test.db")

	db1, err := store.Open(dbPath)
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	m1 := NewManager(db1)
	if _, err := m1.AddPrinter("Multi", []DeviceType{TypeKitchenPrinter, TypeReceiptPrinter},
		ConnNetwork, "192.168.1.30:9100", PrinterConfig{PaperWidth: 58}); err != nil {
		t.Fatalf("add printer: %v", err)
	}
	db1.Close()

	db2, err := store.Open(dbPath)
	if err != nil {
		t.Fatalf("reopen db: %v", err)
	}
	defer db2.Close()
	m2 := NewManager(db2)
	if err := m2.LoadFromDB(); err != nil {
		t.Fatalf("load from db: %v", err)
	}

	if got := m2.GetPrinterByRole(TypeReceiptPrinter); got == nil {
		t.Fatal("receipt role not restored after LoadFromDB")
	}
	if got := m2.GetPrinterByRole(TypeKitchenPrinter); got == nil {
		t.Fatal("kitchen role not restored after LoadFromDB")
	}
}

// UpdateDeviceRoles is the fix for "a group with no printer prints nothing":
// the operator points the group at a device they already have. It must persist
// (survive LoadFromDB) and take effect in routing immediately.
func TestUpdateDeviceRoles(t *testing.T) {
	dir := t.TempDir()
	dbPath := filepath.Join(dir, "test.db")

	db1, err := store.Open(dbPath)
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	m1 := NewManager(db1)
	p, err := m1.AddPrinter("Kitchen", []DeviceType{TypeKitchenPrinter},
		ConnNetwork, "192.168.251.170:9100", PrinterConfig{PaperWidth: 80})
	if err != nil {
		t.Fatalf("add printer: %v", err)
	}

	// No bar station: the bar group has no printer at all.
	if got := m1.GetPrinterByRole(TypeBarPrinter); got != nil {
		t.Fatal("bar role should start unassigned")
	}

	// Give the kitchen printer the bar role too.
	if err := m1.UpdateDeviceRoles(p.ID(), []DeviceType{TypeKitchenPrinter, TypeBarPrinter}); err != nil {
		t.Fatalf("update roles: %v", err)
	}
	if got := m1.GetPrinterByRole(TypeBarPrinter); got == nil {
		t.Error("bar role must resolve immediately after the update")
	}
	if got := m1.GetPrinterByRole(TypeKitchenPrinter); got == nil {
		t.Error("kitchen role must survive the update")
	}
	// The gap warning must clear for bar.
	for _, r := range m1.RolesWithoutPrinter() {
		if r == TypeBarPrinter {
			t.Error("bar still reported as missing after assignment")
		}
	}

	// Rejections: empty list and unknown role leave the device untouched.
	if err := m1.UpdateDeviceRoles(p.ID(), nil); err == nil {
		t.Error("empty role list must be rejected")
	}
	if err := m1.UpdateDeviceRoles(p.ID(), []DeviceType{"not_a_role"}); err == nil {
		t.Error("unknown role must be rejected")
	}
	if err := m1.UpdateDeviceRoles("no-such-device", []DeviceType{TypeBarPrinter}); err == nil {
		t.Error("unknown device must be rejected")
	}
	if got := m1.GetPrinterByRole(TypeBarPrinter); got == nil {
		t.Error("a rejected update must not clear the existing roles")
	}
	db1.Close()

	// Persisted, not just in-memory.
	db2, err := store.Open(dbPath)
	if err != nil {
		t.Fatalf("reopen db: %v", err)
	}
	defer db2.Close()
	m2 := NewManager(db2)
	if err := m2.LoadFromDB(); err != nil {
		t.Fatalf("load from db: %v", err)
	}
	if got := m2.GetPrinterByRole(TypeBarPrinter); got == nil {
		t.Error("bar role not restored after restart — the update did not persist")
	}
}

// GetPrinterByRole prefers a cloud-origin printer over a local one covering the
// same role — admin-web is source of truth, local is only the fallback.
func TestGetPrinterByRolePrefersCloud(t *testing.T) {
	m := newTestManager(t)
	// Two printers, same role, different origin. Insert directly so we control
	// origin (AddPrinter always writes local).
	m.db.Exec(`INSERT INTO printers (id,type,name,connection_type,address,roles,is_active,origin)
		VALUES ('local-k','kitchen_printer','Local K','network','10.0.0.1:9100','["kitchen_printer"]',1,'local')`)
	m.db.Exec(`INSERT INTO printers (id,type,name,connection_type,address,roles,is_active,origin)
		VALUES ('cloud-k','kitchen_printer','Cloud K','network','10.0.0.2:9100','["kitchen_printer"]',1,'cloud')`)
	if err := m.Reload(); err != nil {
		t.Fatalf("reload: %v", err)
	}

	// Run several times — map iteration is random, so a wrong impl that returns
	// "first match" would flake. Cloud must win every time.
	for i := 0; i < 20; i++ {
		p := m.GetPrinterByRole(TypeKitchenPrinter)
		if p == nil {
			t.Fatalf("expected a printer for kitchen role")
		}
		if p.Origin() != "cloud" {
			t.Fatalf("expected cloud printer, got origin=%q id=%s", p.Origin(), p.ID())
		}
	}
}

// When only a local printer covers the role, it is returned as the fallback.
func TestGetPrinterByRoleFallsBackToLocal(t *testing.T) {
	m := newTestManager(t)
	m.db.Exec(`INSERT INTO printers (id,type,name,connection_type,address,roles,is_active,origin)
		VALUES ('local-k','kitchen_printer','Local K','network','10.0.0.1:9100','["kitchen_printer"]',1,'local')`)
	if err := m.Reload(); err != nil {
		t.Fatalf("reload: %v", err)
	}
	p := m.GetPrinterByRole(TypeKitchenPrinter)
	if p == nil || p.Origin() != "local" {
		t.Fatalf("expected local fallback, got %v", p)
	}
}
