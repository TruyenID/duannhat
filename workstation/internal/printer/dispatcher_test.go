package printer

import (
	"testing"
)

func TestRoleForGroup(t *testing.T) {
	cases := []struct {
		input    string
		wantRole DeviceType
		wantFell bool
	}{
		{"kitchen", TypeKitchenPrinter, false},
		{"bar", TypeBarPrinter, false},
		{"hold", TypeHallPrinter, false},
		{"Kitchen", TypeKitchenPrinter, false}, // case-insensitive
		{" bar ", TypeBarPrinter, false},       // whitespace trimmed
		{"", TypeKitchenPrinter, true},         // empty → fallback
		{"sushi", TypeKitchenPrinter, true},    // unknown → fallback
		{"PASTRY", TypeKitchenPrinter, true},   // unknown case-insens → fallback
	}
	for _, tc := range cases {
		t.Run(tc.input, func(t *testing.T) {
			role, fell := RoleForGroup(tc.input)
			if role != tc.wantRole {
				t.Errorf("group=%q: want role=%v, got %v", tc.input, tc.wantRole, role)
			}
			if fell != tc.wantFell {
				t.Errorf("group=%q: want fellBack=%v, got %v", tc.input, tc.wantFell, fell)
			}
		})
	}
}

func TestRouteKitchenItem_DispatchesToConfiguredRole(t *testing.T) {
	m := &Manager{}
	kitchen := &Printer{id: "k", roles: []DeviceType{TypeKitchenPrinter}}
	bar := &Printer{id: "b", roles: []DeviceType{TypeBarPrinter}}
	hold := &Printer{id: "h", roles: []DeviceType{TypeHallPrinter}}
	// Wire the manager's devices map directly — the real installer goes
	// through Manager.Add… but for routing logic we just need the lookup.
	m.devices = map[string]Device{
		"k": kitchen,
		"b": bar,
		"h": hold,
	}

	d := NewDispatcher(m)
	if p, fell := d.RouteKitchenItem("kitchen"); p == nil || p.id != "k" || fell {
		t.Errorf("kitchen group → kitchen printer: got %+v fell=%v", p, fell)
	}
	if p, fell := d.RouteKitchenItem("bar"); p == nil || p.id != "b" || fell {
		t.Errorf("bar group → bar printer: got %+v fell=%v", p, fell)
	}
	if p, fell := d.RouteKitchenItem("hold"); p == nil || p.id != "h" || fell {
		t.Errorf("hold group → hold printer: got %+v fell=%v", p, fell)
	}
	// Unknown group falls back to kitchen
	if p, fell := d.RouteKitchenItem("dessert"); p == nil || p.id != "k" || !fell {
		t.Errorf("unknown group → kitchen fallback: got %+v fell=%v", p, fell)
	}
}

func TestRouteKitchenItem_ReturnsNilWhenRoleMissing(t *testing.T) {
	m := &Manager{devices: map[string]Device{}}
	d := NewDispatcher(m)
	if p, _ := d.RouteKitchenItem("kitchen"); p != nil {
		t.Errorf("expected nil when no device carries kitchen role, got %+v", p)
	}
}

func TestRouteReceipt_PrefersReceiptPrinter(t *testing.T) {
	m := &Manager{}
	kitchen := &Printer{id: "k", roles: []DeviceType{TypeKitchenPrinter}}
	receipt := &Printer{id: "r", roles: []DeviceType{TypeReceiptPrinter}}
	m.devices = map[string]Device{"k": kitchen, "r": receipt}

	d := NewDispatcher(m)
	got, fell := d.RouteReceipt()
	if got == nil || got.id != "r" || fell {
		t.Errorf("receipt configured → receipt printer: got %+v fell=%v", got, fell)
	}
}

func TestRouteReceipt_FallsBackToKitchen(t *testing.T) {
	m := &Manager{}
	kitchen := &Printer{id: "k", roles: []DeviceType{TypeKitchenPrinter}}
	m.devices = map[string]Device{"k": kitchen}

	d := NewDispatcher(m)
	got, fell := d.RouteReceipt()
	if got == nil || got.id != "k" || !fell {
		t.Errorf("no receipt → kitchen fallback: got %+v fell=%v", got, fell)
	}
}

func TestRouteReceipt_NoPrinterReturnsNil(t *testing.T) {
	m := &Manager{devices: map[string]Device{}}
	d := NewDispatcher(m)
	if got, _ := d.RouteReceipt(); got != nil {
		t.Errorf("no roles configured → nil: got %+v", got)
	}
}
