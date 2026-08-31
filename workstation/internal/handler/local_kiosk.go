package handler

import (
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"sort"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/domain"
	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/google/uuid"
)

// GET /api/v1/kiosk/me
//
// Returns the authenticated device's info, sourced from the request context
// populated by AuthMiddleware (which itself talked to Cloud or hit the cache).
func (s *Server) handleLocalKioskMe(w http.ResponseWriter, r *http.Request) {
	d, ok := DeviceFromContext(r.Context())
	if !ok {
		writeError(w, http.StatusUnauthorized, "not authenticated")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"data": map[string]any{
			"id":        d.ID,
			"type":      d.Type,
			"status":    "active",
			"branch_id": d.BranchID,
		},
	})
}

// GET /api/v1/kiosk/orders?code=<order_code>   (or ?table_id=<uuid>)
//
// Resolves a SINGLE active order and returns it in the normalized kiosk
// shape that Cloud's KioskOrderResource emits and the kiosk bill screen
// (godx-tempo-kiosk-app) consumes: `{ data: { id, table_id, table_name,
// items[], subtotal, discount, tax_amount, service_charge, total,
// paid_amount, currency } }`.
//
// This deliberately mirrors the Cloud contract:
//   - ?code=     → the active order whose order_code matches
//   - ?table_id= → the active order currently open on that table (uuid)
//   - no match   → 404 (not an empty array)
//
// Reads from local SQLite (`orders` table) — workstation is source of truth
// for orders during Phase 1. Active = anything ListActive() returns
// (pending/open/dining/checkout/paying — closed/voided excluded), so a
// `status:"pending"` order still resolves, matching Cloud.
func (s *Server) handleLocalKioskOrders(w http.ResponseWriter, r *http.Request) {
	// Two-case routing (online proxy / offline local):
	//   - ONLINE and every local payment already pushed → Cloud is the
	//     authoritative source (A1 pricing + paid_amount). Proxy verbatim.
	//   - OFFLINE, or a payment is still queued (just paid, or accumulated
	//     while offline and not yet synced UP) → serve the local replica so the
	//     fresh local state isn't lost to a Cloud read that lags the async
	//     push. This is also the "pull must not override push" guard on the
	//     read side: while local writes are pending we never trust Cloud.
	if s.kioskReadsOnline() && !s.hasUnsyncedPayments() {
		s.cloudProxy().ServeHTTP(w, r)
		return
	}

	code := r.URL.Query().Get("code")
	tableID := r.URL.Query().Get("table_id")
	if code == "" && tableID == "" {
		writeError(w, http.StatusBadRequest, "code or table_id query parameter required")
		return
	}

	orders, err := s.orders.ListActive()
	if err != nil {
		writeServerError(w, r, err)
		return
	}

	// Branch scope: never surface an order from another branch. Auth already
	// enforces the kiosk's branch == workstation branch (branchOK), and a
	// single-branch workstation only ever replicates its own branch's orders,
	// so this is defense-in-depth on the data path. Fail-open when the order's
	// branch is blank (orders.branch_id defaults to '' for locally-created
	// rows) so legacy/local orders aren't dropped.
	wsBranch := s.workstationBranchID()

	// ListActive() is ordered by opened_at DESC, so the first match is the
	// most-recently-opened active order — the one the customer is sitting at.
	var match *service.Order
	for i := range orders {
		o := &orders[i]
		if wsBranch != "" && o.BranchID != "" && o.BranchID != wsBranch {
			continue
		}
		if code != "" && o.OrderCode == code {
			match = o
			break
		}
		if tableID != "" && o.TableID == tableID {
			match = o
			break
		}
	}
	if match == nil {
		writeError(w, http.StatusNotFound, "order not found")
		return
	}

	// Heal unresolved món names before shaping. A local order whose items
	// carry a blank menu_item_name AND whose SKU isn't in the local catalog
	// renders "(unknown)" — the kiosk bill drift vs pos-web (which reads the
	// món name straight off the order's nested product_sku.product.name from
	// Cloud). Force-pull the order once so the Cloud-resolved name lands in
	// the local snapshot, then re-read. Cloud's workstation/orders projection
	// eager-loads product_sku.product, and SyncPuller persists that nested name
	// (see resolveMenuItemName), so this closes the gap for already-synced
	// orders too. No-op when offline / puller absent — the SKU-code fallback
	// still applies.
	if healed := s.healKioskOrderNames(r, match); healed != nil {
		match = healed
	}

	writeJSON(w, http.StatusOK, map[string]any{"data": s.kioskOrderShape(match)})
}

// healKioskOrderNames force-pulls an order from Cloud when one or more items
// would render as "(unknown)" locally, returning the reloaded order (or nil if
// nothing was healed). Mirrors the force-pull-on-miss pattern in lan_print.go.
func (s *Server) healKioskOrderNames(r *http.Request, o *service.Order) *service.Order {
	if o == nil || s.puller == nil {
		return nil
	}
	needsHeal := false
	for i := range o.Items {
		if name, _, _, _ := s.resolveItemDisplay(o.Items[i]); name == "(unknown)" {
			needsHeal = true
			break
		}
	}
	if !needsHeal {
		return nil
	}
	// Only reach out when Cloud is actually reachable — offline kiosks keep
	// the local fallback rather than blocking on a doomed request.
	if s.cloudReachable != nil && !s.cloudReachable() {
		return nil
	}
	// Cooldown: skip if we force-pulled this order for healing in the last
	// 30s. Guards against a món that stays "(unknown)" after a pull (deleted
	// SKU on Cloud) re-triggering a 1.5s pull on every kiosk poll.
	if last, ok := s.kioskHealCooldown.Load(o.ID); ok {
		if t, ok := last.(time.Time); ok && time.Since(t) < 30*time.Second {
			return nil
		}
	}
	s.kioskHealCooldown.Store(o.ID, time.Now())
	if err := s.puller.PullOrderNow(r.Context(), o.ID); err != nil {
		slog.Warn("kiosk name heal: force-pull failed", "order_id", o.ID, "err", err)
		return nil
	}
	reloaded, err := s.orders.GetByID(o.ID)
	if err != nil || reloaded == nil {
		return nil
	}
	return reloaded
}

// kioskOrderShape transforms a workstation `service.Order` into the
// normalized kiosk Order shape (see godx-tempo-kiosk-app/src/types/kiosk.ts).
// It intentionally drops the workstation-internal item fields (printer_group,
// print_status, subtotal, topping_subtotal, …) the kiosk bill never reads.
func (s *Server) kioskOrderShape(o *service.Order) map[string]any {
	// Claimed units per item (by-items split) across the whole order family,
	// so each line reports how many món are already paid vs still owed.
	claimed := s.claimedUnitsByItem(o.ID)

	items := make([]map[string]any, 0, len(o.Items))
	for _, it := range o.Items {
		items = append(items, s.kioskOrderItemShape(it, claimed[it.ID]))
	}

	// Recompute order-level totals from items rather than trusting the stored
	// columns: orders that reach the local DB via sync-down carry zeroed
	// subtotal/total, so passing them through would render a ¥0 bill. The
	// engine uses Cloud's exact tax-EXCLUDED math + shop-setting rates, so the
	// numbers match Cloud (e.g. 7820/782/391/8993).
	subtotal, discount, tax, serviceCharge, total := s.orders.NormalizedTotals(o)

	// Payment progress (amount): how much is paid vs still owed. Sibling-aware
	// so it survives the pull-down re-import of the order.
	paid := s.sumNonFailedPayments(o.ID)
	remaining := total - paid
	if remaining < 0 {
		remaining = 0
	}

	return map[string]any{
		"id":               o.ID,
		"table_id":         nilIfEmpty(o.TableID),
		"table_name":       nilIfEmpty(o.TableNumber), // ListActive joins tables.name
		"items":            items,
		"subtotal":         subtotal,
		"discount":         discount,
		"tax_amount":       tax,
		"service_charge":   serviceCharge,
		"total":            total,
		"paid_amount":      paid,
		"remaining_amount": remaining,
		"is_tax_included":  o.IsTaxIncluded,
		// plan-043 audit B-I.2 (2026-07-14) — per-rate breakdown so the kiosk
		// bill can show 8%対象 / 10%対象 separately (parity with Cloud's
		// KioskOrderResource, which the kiosk also hits on cloud-fallback).
		"tax_breakdown": s.kioskTaxBreakdown(o),
		// plan-043 (T3.6) — the shop's SYNCED currency, not a hardcoded "JPY".
		// Fallback chain mirrors the pos-web order-settings endpoint:
		// currency_code → currency → "VND" (project default).
		"currency": s.shopCurrency(),
	}
}

// kioskTaxBreakdown groups the order's non-voided lines by their snapshot rate
// into {rate, taxable, tax} rows (ascending by rate), mirroring Cloud's
// KioskOrderResource shape. `taxable` is the NET base (net of the extracted
// 内税 in included mode); `tax` is the per-rate group tax — the per-line
// tax_amount snapshots are already allocated once-per-group, so summing them
// reproduces the group figure. Returns an empty slice when no line carries a
// rate (the kiosk falls back to the single tax_amount line).
func (s *Server) kioskTaxBreakdown(o *service.Order) []map[string]any {
	type agg struct {
		gross int
		tax   float64
	}
	byRate := map[float64]*agg{}
	order := make([]float64, 0, 2)
	for _, it := range o.Items {
		if string(it.Status) == "voided" || it.TaxRate == nil {
			continue
		}
		rate := *it.TaxRate
		a := byRate[rate]
		if a == nil {
			a = &agg{}
			byRate[rate] = a
			order = append(order, rate)
		}
		a.gross += it.Subtotal
		a.tax += it.TaxAmount
	}
	sort.Float64s(order)

	rows := make([]map[string]any, 0, len(order))
	for _, rate := range order {
		a := byRate[rate]
		taxable := float64(a.gross)
		if o.IsTaxIncluded {
			taxable = float64(a.gross) - a.tax // net of the extracted 内税
		}
		rows = append(rows, map[string]any{
			"rate":    rate,
			"taxable": taxable,
			"tax":     a.tax,
		})
	}
	return rows
}

// shopCurrency resolves the shop display currency from synced settings
// (currency_code → currency → "VND"). Single place so the kiosk bill, order
// shape, and any future surface agree.
func (s *Server) shopCurrency() string {
	if c := s.shopSetting("currency_code", ""); c != "" {
		return c
	}
	return s.shopSetting("currency", "VND")
}

// kioskReadsOnline reports whether kiosk order reads should be proxied to Cloud
// (Cloud is reachable). Defaults to the connectivity monitor; overridable in
// tests via Server.cloudReachable.
func (s *Server) kioskReadsOnline() bool {
	if s.cloudReachable != nil {
		return s.cloudReachable()
	}
	return s.sync != nil && s.sync.IsOnline()
}

// hasUnsyncedPayments reports whether any payment write is still queued for
// sync UP. While true, the kiosk read path stays LOCAL even when online — the
// just-made (or offline-accumulated) payments haven't reached Cloud yet, so a
// proxied Cloud read would under-report paid_amount. Prevents the pull/push
// race from surfacing stale data to the customer.
func (s *Server) hasUnsyncedPayments() bool {
	var n int
	_ = s.db.QueryRow(
		`SELECT COUNT(*) FROM sync_queue WHERE entity_type = 'payment' AND synced_at IS NULL`,
	).Scan(&n)
	return n > 0
}

// linkedOrderIDs returns every local order row that represents the SAME logical
// order as orderID. An order created on the workstation keeps its WS uuid as PK
// and gains cloud_id after sync-UP; the pull-down then re-imports the cloud copy
// as a sibling row keyed by the cloud uuid (see TestSyncRoundtrip_OrderPushThenPull).
// Payments may be tied to either row, so paid_amount / claimed units must be
// summed across the whole family — otherwise re-entering the order after a pull
// lands on the paymentless sibling and the prior payment looks lost.
func (s *Server) linkedOrderIDs(orderID string) []string {
	set := map[string]bool{orderID: true}
	var cloudID string
	_ = s.db.QueryRow(`SELECT COALESCE(cloud_id, '') FROM orders WHERE id = ?`, orderID).Scan(&cloudID)
	// Keys that identify the family: the row's own id and its cloud_id. The
	// sibling has id == cloud_id, the local row has cloud_id == cloud_id, so a
	// single lookup by cloud_id catches both; we also match where orderID is
	// itself a cloud_id of some local row.
	for _, key := range []string{orderID, cloudID} {
		if key == "" {
			continue
		}
		set[key] = true
		rows, err := s.db.Query(`SELECT id FROM orders WHERE cloud_id = ?`, key)
		if err != nil {
			continue
		}
		for rows.Next() {
			var id string
			if rows.Scan(&id) == nil {
				set[id] = true
			}
		}
		rows.Close()
	}
	out := make([]string, 0, len(set))
	for id := range set {
		out = append(out, id)
	}
	return out
}

// inPlaceholders renders "?,?,…" for an IN clause and the matching args slice.
func inPlaceholders(ids []string) (string, []any) {
	ph := ""
	args := make([]any, 0, len(ids))
	for i, id := range ids {
		if i > 0 {
			ph += ","
		}
		ph += "?"
		args = append(args, id)
	}
	return ph, args
}

// sumNonFailedPayments returns Σ amount of NON-failed payments across the whole
// order family — the kiosk's "đã thanh toán" figure. orders.paid_amount is only
// written on full close, so a partially-paid order would otherwise show 0.
func (s *Server) sumNonFailedPayments(orderID string) int {
	ids := s.linkedOrderIDs(orderID)
	ph, args := inPlaceholders(ids)
	args = append(args, string(domain.PaymentStatusFailed))
	var paid int
	_ = s.db.QueryRow(
		`SELECT COALESCE(SUM(amount), 0) FROM payments
		 WHERE order_id IN (`+ph+`) AND status != ?`, args...,
	).Scan(&paid)
	return paid
}

// sumConfirmedPayments returns Σ amount of captured payments across the order
// family — the figure that decides whether the order is fully paid. Captured =
// 'succeeded' plus legacy 'confirmed' rows from pre-#1120 builds.
func (s *Server) sumConfirmedPayments(orderID string) int {
	ids := s.linkedOrderIDs(orderID)
	ph, args := inPlaceholders(ids)
	var paid int
	_ = s.db.QueryRow(
		`SELECT COALESCE(SUM(amount), 0) FROM payments
		 WHERE order_id IN (`+ph+`) AND status IN ('succeeded', 'confirmed')`, args...,
	).Scan(&paid)
	return paid
}

// claimedUnitsByItem sums, per order_item id, the units already claimed by
// non-failed payments across the order family. The claim lives in each
// payment's metadata JSON as `item_allocations: [{item_id, units}]` (by-items
// split). Drives remaining_units so a món already paid isn't offered again.
func (s *Server) claimedUnitsByItem(orderID string) map[string]int {
	claimed := map[string]int{}
	ids := s.linkedOrderIDs(orderID)
	ph, args := inPlaceholders(ids)
	args = append(args, string(domain.PaymentStatusFailed))
	rows, err := s.db.Query(
		`SELECT COALESCE(metadata, '') FROM payments
		 WHERE order_id IN (`+ph+`) AND status != ?`, args...,
	)
	if err != nil {
		return claimed
	}
	defer rows.Close()
	for rows.Next() {
		var md string
		if rows.Scan(&md) != nil || md == "" {
			continue
		}
		var meta struct {
			ItemAllocations []struct {
				ItemID string `json:"item_id"`
				Units  int    `json:"units"`
			} `json:"item_allocations"`
		}
		if json.Unmarshal([]byte(md), &meta) != nil {
			continue
		}
		for _, a := range meta.ItemAllocations {
			if a.ItemID != "" {
				claimed[a.ItemID] += a.Units
			}
		}
	}
	return claimed
}

// orderTotalForClose returns the amount that must be captured before an order
// is considered fully paid. It prefers the items-derived total (same math the
// kiosk bill shows, via NormalizedTotals) because orders.total_amount is 0 for
// sync-down rows; it falls back to the stored column when the order has no
// items loaded or the engine isn't wired (tests).
func (s *Server) orderTotalForClose(orderID string) int {
	if s.orders != nil {
		if o, err := s.orders.GetByID(orderID); err == nil {
			if _, _, _, _, total := s.orders.NormalizedTotals(o); total > 0 {
				return total
			}
		}
	}
	var total int
	_ = s.db.QueryRow(`SELECT COALESCE(total_amount, 0) FROM orders WHERE id = ?`, orderID).Scan(&total)
	return total
}

func (s *Server) kioskOrderItemShape(it service.Item, paidUnits int) map[string]any {
	name, productName, skuCode, imageURL := s.resolveItemDisplay(it)
	// Per-món payment progress (by-items split): how many units are already
	// paid vs still owed. Clamp so an over-claim never renders a negative.
	if paidUnits > it.Quantity {
		paidUnits = it.Quantity
	}
	remainingUnits := it.Quantity - paidUnits
	out := map[string]any{
		"id":              it.ID,
		"name":            name,
		"product_name":    nilIfEmpty(productName),
		"sku_code":        nilIfEmpty(skuCode),
		"quantity":        it.Quantity,
		"unit_price":      it.UnitPrice,
		"image_url":       nilIfEmpty(imageURL),
		"paid_units":      paidUnits,
		"remaining_units": remainingUnits,
		// plan-043 (T3.6) — per-line consumption-tax snapshot for the kiosk bill.
		"tax_rate":   floatPtrOrNil(it.TaxRate),
		"tax_amount": it.TaxAmount,
	}
	if it.Note != "" {
		out["note"] = it.Note
	}
	// Toppings → kiosk `extras` ({label, price, quantity, modifier_type}).
	// price stays per-unit (matches Cloud KioskOrderResource); kiosk-app
	// multiplies by quantity for the line total. Forward "remove" modifiers
	// too — kiosk-app renders them as "− No onion" so the customer knows
	// the item was tweaked, not hidden silently.
	if len(it.Toppings) > 0 {
		extras := make([]map[string]any, 0, len(it.Toppings))
		for _, t := range it.Toppings {
			extras = append(extras, map[string]any{
				"label":         t.Name,
				"price":         t.UnitPrice,
				"quantity":      t.Quantity,
				"modifier_type": t.ModifierType,
			})
		}
		if len(extras) > 0 {
			out["extras"] = extras
		}
	}
	return out
}

// resolveItemNameAndImage resolves an order item's display name + image. Name
// priority follows plan W1's `menu_item_name ?: product.name`: the stored
// menu_item_name wins when present (it carries the exact label the order was
// placed with, incl. Cloud-sent names), then the product catalog name resolved
// by joining product_sku_id → product, then the sku variant name. The raw
// menu_item_name is frequently empty (the kiosk/pos add-item flow only sends
// product_sku_id), so the catalog join is what fills the line instead of a blank.
func (s *Server) resolveItemNameAndImage(it service.Item) (name, imageURL string) {
	name, _, _, imageURL = s.resolveItemDisplay(it)
	return name, imageURL
}

// resolveItemDisplay resolves the full Cloud-parity display fields for an order
// item from the local catalog (product_sku_id → product):
//   - name        : menu_item_name ?: product.name ?: variant ?: "(unknown)"
//   - productName : product.name (localized), independent of the variant name
//   - skuCode     : product_skus.sku (e.g. PV-1234-AB)
//   - imageURL    : the resolved cart-line image
//
// Mirrors the fields Cloud's KioskOrderResource emits (name / product_name /
// sku_code) so a WS-local response renders identically to a Cloud-proxied one.
func (s *Server) resolveItemDisplay(it service.Item) (name, productName, skuCode, imageURL string) {
	name = strings.TrimSpace(it.MenuItemName)
	if it.ProductSkuID != "" {
		if stub := s.loadProductSkuStub(it.ProductSkuID); stub != nil {
			productName = stubProductName(stub)
			skuCode = stubSkuCode(stub)
			if name == "" {
				name = skuStubName(stub)
			}
			if img, ok := stub["image_url"].(string); ok {
				imageURL = img
			}
		}
	}
	if name == "" {
		name = strings.TrimSpace(it.SkuVariantName)
	}
	if name == "" {
		// Before giving up: the human SKU code (e.g. PV-1234-AB) still identifies
		// the món for the customer — far better than "(unknown)". Covers the case
		// where the catalog has the sku row but no name in any synced locale.
		name = skuCode
	}
	if name == "" {
		// W1 final guard: an order item must never serialize a blank name (the
		// món isn't in the local catalog and carries no snapshot). "(unknown)"
		// keeps the line visible instead of rendering an empty row.
		name = "(unknown)"
	}
	return name, productName, skuCode, imageURL
}

// skuStubName extracts the display name from a loadProductSkuStub result,
// preferring the product name (the W1 `product.name`) and falling back to the
// sku variant name. Returns "" when neither is set.
func skuStubName(stub map[string]any) string {
	if n := stubProductName(stub); n != "" {
		return n
	}
	if vn, _ := stub["name"].(string); vn != "" {
		return vn
	}
	return ""
}

// stubProductName returns the catalog product name from a loadProductSkuStub
// result ("" when absent).
func stubProductName(stub map[string]any) string {
	if prod, ok := stub["product"].(map[string]any); ok {
		if pn, _ := prod["name"].(string); pn != "" {
			return pn
		}
	}
	return ""
}

// stubSkuCode returns the human SKU code (product_skus.sku) from a
// loadProductSkuStub result ("" when absent / legacy).
func stubSkuCode(stub map[string]any) string {
	if sc, _ := stub["sku_code"].(string); sc != "" {
		return sc
	}
	return ""
}

// POST /api/v1/kiosk/payments
//
// Header: Idempotency-Key: <uuid> (REQUIRED — idempotency guarantees safe retry)
// Body:   { order_id, payment_method, amount, terminal_response? }
//
// Inserts into local `payments` table, enqueues sync UP to Cloud.
// On duplicate Idempotency-Key, returns the existing payment (200, not 201).
func (s *Server) handleLocalCreatePayment(w http.ResponseWriter, r *http.Request) {
	idempKey := r.Header.Get("Idempotency-Key")
	if idempKey == "" {
		writeError(w, http.StatusBadRequest, "Idempotency-Key header required")
		return
	}

	// Idempotent fast-path — re-emit the same PaymentResult shape so a retried
	// POST is indistinguishable from the first.
	if existing, found := s.lookupPaymentByIdempotency(idempKey); found {
		writePaymentResult(w, http.StatusOK, existing, confirmTypeFor(existing.PaymentMethod))
		return
	}

	var input domain.CreatePaymentInput
	if err := readJSON(r, &input); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	if input.OrderID == "" || input.Amount <= 0 || input.PaymentMethod == "" {
		writeError(w, http.StatusBadRequest, "order_id, amount, payment_method required")
		return
	}

	// Payability + overpay guards (#551) — Cloud parity with
	// Customer\OrderPaymentService (409 on a settled order, 422 on overpay) and
	// the POS-local path (sumActivePaymentsForOrder). The kiosk-LAN path used to
	// skip both, so a re-scanned QR on an already-closed order minted a SECOND
	// real charge, and a split order could accept more than its outstanding
	// balance while paid_amount stayed pinned at total (invisible overpay).
	//
	// Both gates are best-effort: they only fire when the order row is present
	// AND carries a computable total. Kiosk callers legitimately POST before the
	// order has synced down (a payment can precede its order row), so a missing
	// order or a zero total keeps the historical accept-everything behaviour
	// rather than rejecting a valid offline payment.
	var orderStatus string
	if err := s.db.QueryRow(
		`SELECT status FROM orders WHERE id = ?`, input.OrderID,
	).Scan(&orderStatus); err == nil {
		// Terminal states are not payable — an order that already closed (fully
		// paid) or was voided must never accept a fresh charge on a QR re-scan.
		if orderStatus == "closed" || orderStatus == "voided" {
			writeError(w, http.StatusConflict,
				"Order is already settled and cannot accept a payment.")
			return
		}
		// Overpay: sibling-aware non-failed (pending + confirmed) sum + this
		// amount must not exceed the items-derived total. Only fires when the
		// total is known (> 0); sync-down rows store total_amount = 0 and are
		// covered once NormalizedTotals can compute from items.
		total := s.orderTotalForClose(input.OrderID)
		existingPaid := s.sumNonFailedPayments(input.OrderID)
		if total > 0 && existingPaid+input.Amount > total {
			writeError(w, http.StatusUnprocessableEntity,
				"Payment amount exceeds the outstanding order balance.")
			return
		}
	}

	now := time.Now().UTC()

	// W-PAY1 — finalize on create by tender. Cash / e-money settle instantly
	// (status SUCCEEDED, confirm_type auto); card / QR go through a terminal and
	// stay PENDING (confirm_type manual) until /confirm carries the terminal_ref.
	auto := autoConfirmMethod(input.PaymentMethod)
	status := domain.PaymentStatusPending
	var paidAt *time.Time
	if auto {
		status = domain.PaymentStatusSucceeded
		paidAt = &now
	}

	// W-PAY3 — mint a human-readable reference up front so the success screen and
	// printed slip never show "-". Honour a caller-supplied reference if present.
	refNo := input.ReferenceNo
	if refNo == "" {
		refNo = newPaymentReference(now)
	}

	// tendered_amount / change_amount are dropped-then-required: the kiosk sends
	// tendered for cash, but we never persisted it here, so the local payment
	// (and its sync-UP payload) carried a null tender → Cloud's kiosk pay endpoint
	// aborted 422 "Tendered amount must be provided and must be >= payment amount."
	// → the payment never synced → the Cloud order never closed ("Hoàn thành").
	var changeAmount *int
	if input.TenderedAmount != nil {
		c := *input.TenderedAmount - input.Amount
		if c < 0 {
			c = 0
		}
		changeAmount = &c
	}

	policyIdent := s.resolvePaymentPolicyIdentity(input.ResolvedPaymentOptionID(), idempKey)

	payment := domain.Payment{
		ID:                    uuid.NewString(),
		OrderID:               input.OrderID,
		PaymentMethod:         input.PaymentMethod,
		PaymentOptionID:       policyIdent.PaymentOptionID,
		PolicyRevision:        policyIdent.PolicyRevision,
		ConnectionID:          policyIdent.ConnectionID,
		ConnectionOptionID:    policyIdent.ConnectionOptionID,
		AttemptIdempotencyKey: policyIdent.AttemptIdempotencyKey,
		Amount:                input.Amount,
		TenderedAmount:        input.TenderedAmount,
		ChangeAmount:          changeAmount,
		ReferenceNo:           refNo,
		Status:                status,
		IdempotencyKey:        idempKey,
		TerminalResponse:      input.TerminalResponse,
		// MetadataString() normalises pos-web's RawMessage object / kiosk's legacy
		// JSON string into the single TEXT form payments.metadata stores.
		Metadata: input.MetadataString(),
		PaidAt:   paidAt, // set for auto-confirmed (cash / e-money) tenders
		// #817 Phase B — device-clock capture instant. Stamped locally for every
		// origin; only workstation-route payments forward it to Cloud (kiosk
		// attribution is out of scope per the workstation-only decision).
		CapturedAt: &now,
		CreatedAt:  now,
		UpdatedAt:  now,
	}

	if err := s.insertPayment(payment, "kiosk"); err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	// W-PAY2 — a payment born CONFIRMED immediately advances its order's
	// paid_amount / remaining and closes it once fully paid.
	if auto {
		s.applyConfirmedPaymentToOrder(payment.OrderID, payment.PaymentMethod, now.Format(time.RFC3339Nano))
	}

	// Enqueue sync UP — payload carries the original Idempotency-Key and the
	// kiosk's Bearer token so the Cloud POST can be replayed under the kiosk's
	// identity (Cloud's /api/v1/kiosk/* endpoints require kiosk-typed device.auth).
	syncPayload := map[string]any{
		"bearer_token":   extractBearer(r),
		"payment_id":     payment.ID,
		"order_id":       payment.OrderID,
		"payment_method": string(payment.PaymentMethod),
		"amount":         payment.Amount,
		// tendered_amount is REQUIRED by Cloud for cash-style methods
		// (KioskController::pay aborts 422 "Tendered amount must be provided
		// and must be >= payment amount." otherwise). Omitting it meant every
		// workstation-recorded cash payment printed its receipt locally but
		// failed to sync UP → the Cloud order never closed → status stuck at
		// checkout/confirmed instead of flipping to "Hoàn thành".
		"tendered_amount":   payment.TenderedAmount,
		"reference_no":      payment.ReferenceNo,
		"idempotency_key":   payment.IdempotencyKey,
		"terminal_response": payment.TerminalResponse,
		"metadata":          payment.Metadata,
	}
	appendPaymentPolicySyncFields(syncPayload, policyIdent)
	if err := s.sync.Enqueue("payment", payment.ID, "create", syncPayload, 1); err != nil {
		slog.Error("enqueue payment.create", "err", err, "payment", payment.ID)
		// Don't fail the request — payment is in local DB, sync will retry
	}
	// For an auto-confirmed tender, also replay the confirm UP so Cloud's copy
	// settles too (the create push fills cloud_id first, then confirm follows in
	// queue order). Terminal methods enqueue confirm later from /confirm.
	if auto {
		if err := s.sync.Enqueue("payment", payment.ID, "confirm", map[string]any{
			"bearer_token": extractBearer(r),
			"payment_id":   payment.ID,
		}, 1); err != nil {
			slog.Error("enqueue payment.confirm (auto)", "err", err, "payment", payment.ID)
		}
	}

	// Auto-print on an instantly-settled tender (issue #456). Takeaway fires the
	// kitchen ticket (Mode A) + receipt; non-takeaway keeps the auto_print_bill
	// gate. Best-effort: a print failure must never block the payment. Terminal
	// methods print later on /confirm.
	if auto {
		s.handleLocalPaymentAutoPrint(payment.OrderID, payment.Amount)
	}

	writePaymentResult(w, http.StatusCreated, &payment, confirmTypeFor(payment.PaymentMethod))
}

// confirmTypeFor maps a tender to its PaymentResult confirm_type: "auto" for
// instantly-settled methods (cash / e-money), "manual" for terminal-backed ones.
func confirmTypeFor(m domain.PaymentMethod) string {
	if autoConfirmMethod(m) {
		return "auto"
	}
	return "manual"
}

// GET /api/v1/kiosk/payments/{id}/status
func (s *Server) handleLocalPaymentStatus(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	p, err := s.getPayment(id)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, http.StatusNotFound, "payment not found")
			return
		}
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	writePaymentResult(w, http.StatusOK, p, confirmTypeFor(p.PaymentMethod))
}

// POST /api/v1/kiosk/payments/{id}/confirm
//
// Body (terminal-backed methods — card/qr/emoney): { terminal_ref, terminal_data }
func (s *Server) handleLocalConfirmPayment(w http.ResponseWriter, r *http.Request) {
	s.transitionPayment(w, r, domain.PaymentStatusSucceeded, "confirm", true)
}

// POST /api/v1/kiosk/payments/{id}/fail
//
// Body: { reason, error_code, terminal_ref, terminal_data }
func (s *Server) handleLocalFailPayment(w http.ResponseWriter, r *http.Request) {
	s.transitionPayment(w, r, domain.PaymentStatusFailed, "fail", false)
}

// transitionPayment moves a payment to a new terminal status and enqueues sync.
// If updateOrder is true (confirm only), also marks the linked order as paid.
//
// The request body mirrors Cloud's kiosk confirm/fail contract (plan A6):
// confirm carries { terminal_ref, terminal_data }; fail adds { reason,
// error_code }. These ride the sync-UP payload verbatim so Cloud's
// confirm/fail endpoints receive the same field names — see
// transitionPaymentCloud. `terminal_response` is still accepted for backward
// compat with the legacy single-field shape.
func (s *Server) transitionPayment(w http.ResponseWriter, r *http.Request, status domain.PaymentStatus, op string, updateOrder bool) {
	id := r.PathValue("id")

	var body struct {
		TerminalResponse string          `json:"terminal_response,omitempty"` // legacy
		TerminalRef      string          `json:"terminal_ref,omitempty"`
		TerminalData     json.RawMessage `json:"terminal_data,omitempty"`
		Reason           string          `json:"reason,omitempty"`     // fail only
		ErrorCode        string          `json:"error_code,omitempty"` // fail only
	}
	if r.ContentLength > 0 {
		_ = readJSON(r, &body)
	}

	// Idempotent transition: if the payment is already in the target terminal
	// state, this is a duplicate call — return it WITHOUT re-applying the order
	// update, re-enqueuing sync, or re-printing. This is the common case for a
	// cash payment that already auto-confirmed on create (W-PAY1) but whose kiosk
	// still POSTs /confirm: without this guard a second, identical "DA THANH
	// TOAN" bill prints (the reported duplicate slip on the order-closing món).
	// Legacy 'confirmed' rows (pre-#1120 builds; migration 058 rewrites them,
	// but a row can race the migration via a frozen replay) count as already
	// captured: re-confirming one must not re-print or re-enqueue.
	alreadyThere := func(prior domain.PaymentStatus) bool {
		if prior == status {
			return true
		}
		return status == domain.PaymentStatusSucceeded && prior == domain.PaymentStatusConfirmed
	}
	if prior, perr := s.getPayment(id); perr == nil && alreadyThere(prior.Status) {
		writePaymentResult(w, http.StatusOK, prior, confirmTypeFor(prior.PaymentMethod))
		return
	}

	now := time.Now().UTC().Format(time.RFC3339Nano)
	result, err := s.db.Exec(`
		UPDATE payments
		SET status = ?,
		    terminal_response = COALESCE(NULLIF(?, ''), terminal_response),
		    updated_at = ?,
		    synced_at = NULL
		WHERE id = ?`, string(status), body.TerminalResponse, now, id)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	n, _ := result.RowsAffected()
	if n == 0 {
		writeError(w, http.StatusNotFound, "payment not found")
		return
	}

	p, err := s.getPayment(id)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	if updateOrder {
		s.applyConfirmedPaymentToOrder(p.OrderID, p.PaymentMethod, now)
	}

	// Enqueue sync UP — bearer carried for Cloud auth (see handleLocalCreatePayment).
	// cloud_id is looked up at push time from `payments.cloud_id` (filled when
	// the payment.create sync succeeds). The terminal fields ride the payload
	// (not the payments table) so Cloud's confirm/fail receives the A6 shape
	// even though the push happens later, from the queue.
	syncPayload := map[string]any{
		"bearer_token":      extractBearer(r),
		"payment_id":        p.ID,
		"terminal_response": p.TerminalResponse,
	}
	if body.TerminalRef != "" {
		syncPayload["terminal_ref"] = body.TerminalRef
	}
	if len(body.TerminalData) > 0 {
		syncPayload["terminal_data"] = string(body.TerminalData)
	}
	if op == "fail" {
		if body.Reason != "" {
			syncPayload["reason"] = body.Reason
		}
		if body.ErrorCode != "" {
			syncPayload["error_code"] = body.ErrorCode
		}
	}
	if err := s.sync.Enqueue("payment", p.ID, op, syncPayload, 1); err != nil {
		slog.Error("enqueue payment."+op, "err", err, "payment", p.ID)
	}

	// Auto-print on terminal-payment confirm (issue #456). Takeaway fires the
	// kitchen ticket (Mode A) + receipt; non-takeaway keeps the auto_print_bill
	// gate. Best-effort: a print failure must never block payment confirmation.
	if updateOrder {
		s.handleLocalPaymentAutoPrint(p.OrderID, p.Amount)
	}

	// Terminal-backed methods reach here via the manual confirm/fail flow.
	writePaymentResult(w, http.StatusOK, p, "manual")
}

// applyConfirmedPaymentToOrder records captured money on the order family and
// closes the order once it is fully paid. It is the single place that mutates
// order.paid_amount on a confirmed payment — called both from the manual confirm
// endpoint (terminal-backed methods) and from auto-confirm on create (cash /
// e-money). `now` is RFC3339Nano; `method` records which tender closed the order.
//
// Close the order ONLY when fully paid. A partial / split payment (chia đều,
// chia theo món, trả một phần) must leave the order OPEN so the kiosk can
// re-enter and finish the remaining bills — otherwise the order drops out of
// ListActive (status='closed'), a re-scan of the table 404s, and the customer
// is forced to pay the whole bill again.
//
// Compare captured money (confirmed payments) against the items-derived total,
// NOT orders.total_amount: sync-down rows store 0 there, which would make every
// order look fully paid and close on the first partial. Apply to the WHOLE order
// family (local row + any pulled sibling sharing cloud_id) so progress is
// consistent no matter which row the kiosk re-reads.
func (s *Server) applyConfirmedPaymentToOrder(orderID string, method domain.PaymentMethod, now string) {
	confirmedPaid := s.sumConfirmedPayments(orderID)
	total := s.orderTotalForClose(orderID)

	ids := s.linkedOrderIDs(orderID)
	ph, idArgs := inPlaceholders(ids)

	if total > 0 && confirmedPaid >= total {
		// Fully paid → close (Sprint 4 schema: paid_at → closed_at, 'paid' → 'closed').
		args := append([]any{now, string(method), confirmedPaid, now}, idArgs...)
		if _, err := s.db.Exec(`
			UPDATE orders
			SET closed_at = ?, payment_method = ?, status = 'closed',
			    paid_amount = ?, updated_at = ?, synced_at = NULL
			WHERE id IN (`+ph+`)`, args...); err != nil {
			slog.Error("close order on full payment", "err", err, "order", orderID)
		}

		// #491 — apply the branch's table-status-after-payment policy locally.
		s.applyTableStatusAfterPayment(ids)
		return
	}
	// Partial → record progress, keep the order open.
	args := append([]any{confirmedPaid, now}, idArgs...)
	if _, err := s.db.Exec(`
		UPDATE orders
		SET paid_amount = ?, updated_at = ?, synced_at = NULL
		WHERE id IN (`+ph+`)`, args...); err != nil {
		slog.Error("update partial paid_amount", "err", err, "order", orderID)
	}
}

// applyTableStatusAfterPayment flips every dine-in table bound to the given
// orders to the branch's table-status-after-payment policy (`free` default, or
// `cleaning` when the branch opted in). #491 — runs on the LAN close paths
// (kiosk auto-confirm, manual confirm, AND the POS handler's inline close) so
// POS shows `Đang dọn` the instant a paid order closes, without waiting on the
// Cloud round-trip. Mirrors Cloud's OrderClosingService::close(); the effective
// value is synced from Cloud into shop_settings via PullBranch. A no-op for
// takeaway/spot orders (no bound table) and when the policy is `free`.
func (s *Server) applyTableStatusAfterPayment(orderIDs []string) {
	if len(orderIDs) == 0 {
		return
	}
	tableStatus := s.shopSetting("table_status_after_payment", "free")
	if tableStatus != "cleaning" {
		tableStatus = "free"
	}
	ph, idArgs := inPlaceholders(orderIDs)
	args := append([]any{tableStatus}, idArgs...)
	args = append(args, idArgs...)
	if _, err := s.db.Exec(`
		UPDATE tables SET status = ?
		WHERE id IN (
			SELECT ot.table_id FROM order_tables ot
			  JOIN orders o ON o.id = ot.order_id
			 WHERE ot.order_id IN (`+ph+`) AND o.order_type = 'dine_in'
			UNION
			SELECT o.table_id FROM orders o
			 WHERE o.id IN (`+ph+`) AND o.order_type = 'dine_in' AND o.table_id IS NOT NULL
		)`, args...); err != nil {
		slog.Error("apply table status after payment", "err", err, "orders", orderIDs)
	}
}

// autoConfirmMethod reports whether a tender settles instantly on the
// workstation (no terminal/gateway round-trip): cash and e-money are captured
// the moment the kiosk records them, so the payment is born CONFIRMED. Card / QR
// go through a terminal and stay PENDING until /confirm carries the terminal_ref.
func autoConfirmMethod(m domain.PaymentMethod) bool {
	return m == domain.PaymentMethodCash || m == domain.PaymentMethodEmoney
}

// newPaymentReference mints a short, human-readable transaction code shown on
// the success screen and printed slip (e.g. PAY-2026-9F3A1C). Cloud assigns its
// own reference on sync; this is the workstation's local identifier so the slip
// never shows "-" while the payment is still local/pending.
func newPaymentReference(now time.Time) string {
	return fmt.Sprintf("PAY-%d-%s", now.Year(), strings.ToUpper(uuid.NewString()[:6]))
}

// writePaymentResult renders a payment in the kiosk PaymentResult contract
// (plan W-PAY4): payment_id / reference_no / amount_paid named fields plus
// status (succeeded|pending|failed) and confirm_type (auto|manual). The kiosk's
// defensive id→payment_id / amount→amount_paid mapping becomes a no-op once this
// shape is returned. expires_at rides only when set (terminal/QR timeout).
func writePaymentResult(w http.ResponseWriter, code int, p *domain.Payment, confirmType string) {
	status := "pending"
	switch p.Status {
	case domain.PaymentStatusSucceeded, domain.PaymentStatusConfirmed:
		status = "succeeded"
	case domain.PaymentStatusFailed:
		status = "failed"
	}
	data := map[string]any{
		"payment_id":   p.ID,
		"reference_no": p.ReferenceNo,
		"status":       status,
		"confirm_type": confirmType,
		"method":       string(p.PaymentMethod),
		"amount_paid":  p.Amount,
	}
	if p.ExpiresAt != nil && !p.ExpiresAt.IsZero() {
		data["expires_at"] = p.ExpiresAt.UTC().Format(time.RFC3339)
	}
	writeJSON(w, code, map[string]any{"data": data})
}

// ─── DB helpers (payments table) ──────────────────────────────────────────

// insertPayment persists a payment. syncTarget records the origin so the
// payment reconciler can pick the right Cloud route/token on re-push after the
// sync_queue row is gone (plan-818): "workstation" (POS — re-stampable by
// cloudPost) or "kiosk" (terminal-baked token, out of the reconciler's scope).
func (s *Server) insertPayment(p domain.Payment, syncTarget string) error {
	intPtr := func(v *int) any {
		if v == nil {
			return nil
		}
		return *v
	}
	timePtr := func(t *time.Time) any {
		if t == nil || t.IsZero() {
			return nil
		}
		return t.UTC().Format(time.RFC3339Nano)
	}
	_, err := s.db.Exec(`
		INSERT INTO payments
			(id, order_id, payment_method_id, payment_method, amount,
			 tip_amount, tendered_amount, change_amount, reference_no, note,
			 status, idempotency_key, terminal_response, metadata,
			 paid_at, captured_at, expires_at, till_session_id, sync_target,
			 payment_option_id, policy_revision, connection_id, connection_option_id,
			 attempt_idempotency_key, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		p.ID, p.OrderID,
		nullIfEmpty(p.PaymentMethodID), string(p.PaymentMethod), p.Amount,
		p.TipAmount, intPtr(p.TenderedAmount), intPtr(p.ChangeAmount),
		nullIfEmpty(p.ReferenceNo), nullIfEmpty(p.Note),
		string(p.Status), p.IdempotencyKey,
		nullIfEmpty(p.TerminalResponse), nullIfEmpty(p.Metadata),
		timePtr(p.PaidAt), timePtr(p.CapturedAt), timePtr(p.ExpiresAt), nullIfEmpty(p.TillSessionID),
		nullIfEmpty(syncTarget),
		nullIfEmpty(p.PaymentOptionID), nullIntPtr(p.PolicyRevision),
		nullIfEmpty(p.ConnectionID), nullIfEmpty(p.ConnectionOptionID),
		nullIfEmpty(p.AttemptIdempotencyKey),
		p.CreatedAt.Format(time.RFC3339Nano), p.UpdatedAt.Format(time.RFC3339Nano))
	return err
}

func nullIntPtr(v int) any {
	if v == 0 {
		return nil
	}
	return v
}

const paymentSelectCols = `
	id, COALESCE(cloud_id, ''), order_id,
	COALESCE(payment_method_id, ''), payment_method, amount,
	COALESCE(tip_amount, 0), tendered_amount, change_amount,
	COALESCE(reference_no, ''), COALESCE(note, ''),
	status,
	COALESCE(idempotency_key, ''), COALESCE(terminal_response, ''), COALESCE(metadata, ''),
	paid_at, captured_at, expires_at, COALESCE(till_session_id, ''),
	COALESCE(payment_option_id, ''), COALESCE(policy_revision, 0),
	COALESCE(connection_id, ''), COALESCE(connection_option_id, ''),
	COALESCE(attempt_idempotency_key, ''),
	created_at, updated_at, synced_at
`

func (s *Server) getPayment(id string) (*domain.Payment, error) {
	return scanPayment(s.db.QueryRow(
		`SELECT `+paymentSelectCols+` FROM payments WHERE id = ?`, id))
}

func (s *Server) lookupPaymentByIdempotency(key string) (*domain.Payment, bool) {
	p, err := scanPayment(s.db.QueryRow(
		`SELECT `+paymentSelectCols+` FROM payments WHERE idempotency_key = ?`, key))
	if err != nil {
		return nil, false
	}
	return p, true
}

func scanPayment(row *sql.Row) (*domain.Payment, error) {
	var p domain.Payment
	var method, status string
	var createdRaw, updatedRaw string
	var syncedRaw, paidAtRaw, capturedAtRaw, expiresAtRaw sql.NullString
	var tendered, change sql.NullInt64
	var policyRevision sql.NullInt64
	if err := row.Scan(
		&p.ID, &p.CloudID, &p.OrderID,
		&p.PaymentMethodID, &method, &p.Amount,
		&p.TipAmount, &tendered, &change,
		&p.ReferenceNo, &p.Note,
		&status,
		&p.IdempotencyKey, &p.TerminalResponse, &p.Metadata,
		&paidAtRaw, &capturedAtRaw, &expiresAtRaw, &p.TillSessionID,
		&p.PaymentOptionID, &policyRevision,
		&p.ConnectionID, &p.ConnectionOptionID, &p.AttemptIdempotencyKey,
		&createdRaw, &updatedRaw, &syncedRaw,
	); err != nil {
		return nil, err
	}
	p.PaymentMethod = domain.PaymentMethod(method)
	p.Status = domain.PaymentStatus(status)
	if policyRevision.Valid {
		p.PolicyRevision = int(policyRevision.Int64)
	}
	p.CreatedAt = parseTime(createdRaw)
	p.UpdatedAt = parseTime(updatedRaw)
	if tendered.Valid {
		v := int(tendered.Int64)
		p.TenderedAmount = &v
	}
	if change.Valid {
		v := int(change.Int64)
		p.ChangeAmount = &v
	}
	if paidAtRaw.Valid && paidAtRaw.String != "" {
		t := parseTime(paidAtRaw.String)
		p.PaidAt = &t
	}
	if capturedAtRaw.Valid && capturedAtRaw.String != "" {
		t := parseTime(capturedAtRaw.String)
		p.CapturedAt = &t
	}
	if expiresAtRaw.Valid && expiresAtRaw.String != "" {
		t := parseTime(expiresAtRaw.String)
		p.ExpiresAt = &t
	}
	if syncedRaw.Valid && syncedRaw.String != "" {
		t := parseTime(syncedRaw.String)
		p.SyncedAt = &t
	}
	return &p, nil
}

// parseTime tries RFC3339Nano, RFC3339, then SQLite default. Returns zero time on failure.
func parseTime(s string) time.Time {
	for _, layout := range []string{time.RFC3339Nano, time.RFC3339, "2006-01-02 15:04:05"} {
		if t, err := time.Parse(layout, s); err == nil {
			return t
		}
	}
	return time.Time{}
}

func nullIfEmpty(s string) any {
	if s == "" {
		return nil
	}
	return s
}

// Avoid unused import warning if json isn't used directly elsewhere in this file.
var _ = json.Marshal
