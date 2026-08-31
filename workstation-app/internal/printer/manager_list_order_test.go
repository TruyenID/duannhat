package printer

import (
	"path/filepath"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// ListDevices used to range over the manager's map, so Go's randomised map
// iteration handed the UI a different order on every 5s poll. A device you had
// just added landed in a random row among the others, which is why adding one
// read as "nothing happened".
func TestListDevices_OrderIsStableAcrossCalls(t *testing.T) {
	m := newTestManager(t)

	for _, name := range []string{"Alpha", "Bravo", "Charlie", "Delta", "Echo"} {
		if _, err := m.AddPrinter(name, []DeviceType{TypeKitchenPrinter},
			ConnNetwork, "192.168.1.10:9100", PrinterConfig{}); err != nil {
			t.Fatalf("add %s: %v", name, err)
		}
	}

	first := m.ListDevices()
	if len(first) != 5 {
		t.Fatalf("expected 5 devices, got %d", len(first))
	}
	// Enough repeats that a map-iteration order would almost surely differ.
	for i := range 25 {
		got := m.ListDevices()
		for j := range got {
			if got[j].ID != first[j].ID {
				t.Fatalf("call %d position %d: id %s, want %s — order is not stable",
					i, j, got[j].ID, first[j].ID)
			}
		}
	}
}

// A newly added device must surface at the top, not wherever the sort happens
// to drop it — that is the whole point of the ordering for the operator.
func TestListDevices_NewestFirst(t *testing.T) {
	m := newTestManager(t)

	older, err := m.AddPrinter("Older", []DeviceType{TypeKitchenPrinter},
		ConnNetwork, "192.168.1.10:9100", PrinterConfig{})
	if err != nil {
		t.Fatalf("add older: %v", err)
	}
	// AddPrinter stamps RFC3339 (second precision), so force a later stamp
	// rather than sleeping a full second in a unit test.
	m.mu.Lock()
	m.devices[older.ID()].(*Printer).createdAt = "2020-01-01T00:00:00Z"
	m.mu.Unlock()

	newer, err := m.AddPrinter("Newer", []DeviceType{TypeBarPrinter},
		ConnNetwork, "192.168.1.11:9100", PrinterConfig{})
	if err != nil {
		t.Fatalf("add newer: %v", err)
	}

	list := m.ListDevices()
	if list[0].ID != newer.ID() {
		t.Errorf("first device = %s (%s), want the newest %s",
			list[0].ID, list[0].Name, newer.ID())
	}
}

// Same-second adds still need a total order, otherwise the tie falls back to
// map iteration and the list reshuffles again.
func TestListDevices_TieBreaksOnID(t *testing.T) {
	m := newTestManager(t)

	for i := range 6 {
		if _, err := m.AddPrinter("Same", []DeviceType{TypeKitchenPrinter},
			ConnNetwork, "192.168.1.10:9100", PrinterConfig{}); err != nil {
			t.Fatalf("add %d: %v", i, err)
		}
	}
	// Collapse every createdAt so only the ID tie-break can order them.
	m.mu.Lock()
	for _, dev := range m.devices {
		dev.(*Printer).createdAt = "2026-01-01T00:00:00Z"
	}
	m.mu.Unlock()

	first := m.ListDevices()
	for i := 1; i < len(first); i++ {
		if first[i-1].ID > first[i].ID {
			t.Fatalf("ids not ascending on tie: %s before %s", first[i-1].ID, first[i].ID)
		}
	}
	for range 25 {
		got := m.ListDevices()
		for j := range got {
			if got[j].ID != first[j].ID {
				t.Fatalf("tie order not stable at position %d", j)
			}
		}
	}
}

// createdAt has to survive a restart, or the order changes every time the app
// reopens.
func TestListDevices_OrderSurvivesReload(t *testing.T) {
	dbPath := filepath.Join(t.TempDir(), "test.db")

	db1, err := store.Open(dbPath)
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	m1 := NewManager(db1)
	for _, name := range []string{"One", "Two", "Three"} {
		if _, err := m1.AddPrinter(name, []DeviceType{TypeKitchenPrinter},
			ConnNetwork, "192.168.1.10:9100", PrinterConfig{}); err != nil {
			t.Fatalf("add %s: %v", name, err)
		}
	}
	before := m1.ListDevices()
	db1.Close()

	db2, err := store.Open(dbPath)
	if err != nil {
		t.Fatalf("reopen db: %v", err)
	}
	t.Cleanup(func() { db2.Close() })
	m2 := NewManager(db2)
	if err := m2.LoadFromDB(); err != nil {
		t.Fatalf("load: %v", err)
	}

	after := m2.ListDevices()
	if len(after) != len(before) {
		t.Fatalf("reloaded %d devices, want %d", len(after), len(before))
	}
	for i := range before {
		if after[i].ID != before[i].ID {
			t.Errorf("position %d: %s after reload, want %s", i, after[i].ID, before[i].ID)
		}
		if after[i].CreatedAt == "" {
			t.Errorf("position %d: created_at empty after reload", i)
		}
	}
}
