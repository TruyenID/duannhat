package printer

import (
	"log/slog"
	"strings"
)

// Dispatcher is the single source of truth for routing a print job to its
// physical device. Both the LAN kitchen-ticket endpoint (plan-038 T2.1) and
// the legacy mobile-handy fire path (plan-038 T1.3) call into this so the
// `printer_group → role` mapping lives in one place.
//
// Historical context: `handleLocalHandyFireOrder` previously called
// `GetPrinterByRole(TypeKitchenPrinter)` unconditionally for every group,
// so bar items printed on the kitchen printer. Centralising the mapping
// fixes the bug without forking it across endpoints.
type Dispatcher struct {
	manager *Manager
}

// NewDispatcher wires the role resolver to the existing device Manager.
// Manager owns lifecycle / connection / status; Dispatcher owns the
// printer-group routing decision.
func NewDispatcher(m *Manager) *Dispatcher {
	return &Dispatcher{manager: m}
}

// RouteKitchenItem returns the printer for a given `printer_group` taken
// from an `order_items` row. The mapping is:
//
//	"kitchen"   → kitchen_printer
//	"bar"       → bar_printer
//	"hall" ("hold" legacy) → hall_printer
//	""          → kitchen_printer (default; logged as a warn)
//	unknown     → kitchen_printer (default; logged as a warn)
//
// A group whose role has no device attached returns nil — it does NOT silently
// borrow another role's printer. The caller treats nil as "no printer for this
// group" and surfaces a structured error (503 from
// /api/lan/print/kitchen-ticket) / a `no_printer:<role>` soft error on the fire
// path, which is what drives the "Chưa có máy in cho: Bar" operator warning.
//
// This is deliberate. Routing a bar item onto the kitchen printer to avoid an
// empty result is worse than printing nothing: the drink ticket vanishes into
// the kitchen queue, the bar never sees it, and the operator gets no signal
// that anything is wrong — the shop only finds out when the customer asks where
// their drink is. A missing printer must stay visible, not be papered over.
//
// The returned bool reports whether the GROUP was unclassified (empty/unknown
// and therefore defaulted to kitchen_printer). It is not set merely because the
// resolved role has no device.
func (d *Dispatcher) RouteKitchenItem(printerGroup string) (*Printer, bool) {
	group := strings.ToLower(strings.TrimSpace(printerGroup))
	role, fellBack := roleForGroup(group)
	if fellBack {
		slog.Warn("printer dispatcher: unclassified printer_group, defaulting to kitchen_printer",
			"printer_group", printerGroup)
	}
	p := d.manager.GetPrinterByRole(role)
	if p == nil {
		// Surface the gap instead of rerouting. The caller decides what to do
		// (KDS-only fire + operator warning); it must not become a silent
		// mis-delivery to whichever device happens to be configured.
		slog.Warn("printer dispatcher: no device configured for role, not rerouting",
			"printer_group", printerGroup, "role", string(role))
	}
	return p, fellBack
}

// RouteReceipt returns the printer for the customer-bill / payment-receipt
// flow. Today the workstation has only one receipt printer slot
// (`receipt_printer`), but the indirection lets us layer fallback ladders
// without touching every caller — e.g. fall back to `kitchen_printer` when
// `receipt_printer` is offline (a single-station shop with one device
// carrying both roles).
//
// Fallback ladder:
//  1. receipt_printer (configured + has a Printer instance)
//  2. kitchen_printer (sane single-station default)
//
// Returns (nil, false) when no role is configured — caller emits 503.
func (d *Dispatcher) RouteReceipt() (*Printer, bool) {
	if p := d.manager.GetPrinterByRole(TypeReceiptPrinter); p != nil {
		return p, false
	}
	if p := d.manager.GetPrinterByRole(TypeKitchenPrinter); p != nil {
		// Single-station fallback — emit a warn so ops can see when receipts
		// are sharing the kitchen printer.
		slog.Warn("printer dispatcher: receipt_printer not configured, falling back to kitchen_printer")
		return p, true
	}
	return nil, false
}

// roleForGroup maps a normalised printer_group value to its target role.
// `fellBack=true` means the input wasn't a known group and the default was
// used. Pure function; safe to test without a Manager.
//
// A KNOWN group always resolves to its own role and never falls back here.
// Whether that role has a device attached is a separate question, answered by
// the Manager — conflating the two is what made bar items print in the kitchen
// (see RouteKitchenItem).
func roleForGroup(group string) (role DeviceType, fellBack bool) {
	switch group {
	case "kitchen":
		return TypeKitchenPrinter, false
	case "bar":
		return TypeBarPrinter, false
	// "hold" is the legacy spelling of this group, still stored on menu products
	// in Cloud. Accept both so existing catalogue data keeps routing.
	case "hall", "hold":
		return TypeHallPrinter, false
	case "":
		return TypeKitchenPrinter, true
	default:
		return TypeKitchenPrinter, true
	}
}

// RoleForGroup is the exported variant of roleForGroup for callers that
// want to introspect the routing decision (tests, logs) without going
// through a Dispatcher / Manager.
func RoleForGroup(group string) (role DeviceType, fellBack bool) {
	return roleForGroup(strings.ToLower(strings.TrimSpace(group)))
}
