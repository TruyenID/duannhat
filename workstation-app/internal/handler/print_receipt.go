package handler

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"strings"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// splitState is the workstation-derived equivalent of the backend
// CustomerOrderSplitStatusController: split metadata comes from the FIRST
// confirmed payment, paid_count is the number of confirmed payments, and
// remaining is order.total - sum(confirmed amounts).
type splitState struct {
	splitCount    int            // total_bills (0/1 → not a split)
	splitMode     string         // split_mode: "by_items" | "even"/"equal" | "by_amount" | ""
	slipIndex     int            // 1-based index of THIS slip (bill_index+1; 0 = unknown)
	expectedTotal int            // expected_total_amount of THIS payment = the sub-bill total
	allocations   map[string]int // by-items split: order_item id → units on THIS slip
	paidCount     int            // confirmed payments so far (this slip included)
	remaining     int            // WHOLE-order remaining = order total - sum(confirmed)
	// Plan-038 T2.4 — by_amount split fields. Populated only when
	// splitMode == "by_amount" so the formatter can render a slip that
	// shows ONLY the per-person label + amount (no item list, no tax
	// breakdown — the split is fundamentally non-itemised).
	byAmountLabel  string
	byAmountAmount int
}

// isSplit reports whether THIS slip is one bill of a split payment. A by-items
// split (chia theo món) carries item_allocations but often NO total_bills, so we
// must not key the split decision on splitCount alone — otherwise the món-filter
// is skipped and the slip prints the whole order ("in hết tất cả món").
func (st splitState) isSplit() bool {
	return st.splitCount > 1 || len(st.allocations) > 0 ||
		st.splitMode == "by_items" || st.splitMode == "even" || st.splitMode == "equal" ||
		st.splitMode == "by_amount"
}

// isByAmount reports whether THIS slip is a by_amount per-person split.
// Drives the slip-mode branch in paidSlipInputs that hides the item list.
func (st splitState) isByAmount() bool {
	return st.splitMode == "by_amount"
}

// shouldPrintRemainingQRSlip reports whether to also print the "PHAN CON LAI" +
// QR slip (for the next payer to scan & pay the remainder).
//
// ONLY on the auto-print-on-payment path (empty paymentID). A TARGETED
// per-person reprint (pos-web "In biên lai", paymentID set) is a POS-driven
// split where every share is already collected at the register — the QR slip is
// redundant, and by-items per-bill tax rounding can leave a ¥1-2 residual
// `remaining` that would otherwise print a spurious QR slip.
func shouldPrintRemainingQRSlip(paymentID string, st splitState) bool {
	return strings.TrimSpace(paymentID) == "" && st.isSplit() && st.remaining > 0
}

// deriveSplitState reads the local payments table to reconstruct split-bill
// progress for an order. The per-slip fields (splitCount/slipIndex/expectedTotal)
// come from the LATEST confirmed payment — the one whose slip we're printing —
// so each split bill prints ITS OWN sub-total, not the whole order.
//
// Reads the field names the clients ACTUALLY send (kiosk + pos-web):
// `total_bills`, `bill_index` (0-based), `expected_total_amount`. The legacy
// `split_count` is kept only as a fallback. Without this, splitCount was always
// 0 (nobody sends split_count) so every split slip fell back to the full order
// total — the "chia bill mà vẫn để tổng bill" bug.
//
// When paymentID is non-empty the per-slip metadata is read from THAT payment
// (per-person reprint: pos-web prints one slip per payer, each keyed on its own
// payment id). Empty paymentID falls back to the LATEST non-failed payment —
// the auto-print-on-confirm + whole-order reprint paths. Reading the latest for
// a targeted per-person reprint was the bug that stamped every payer's slip with
// the last person's items/label.
func (s *Server) deriveSplitState(orderID string, orderTotal int, paymentID string) splitState {
	st := splitState{remaining: orderTotal}

	// Sibling-aware: the payment (and its split metadata) may be tied to the
	// cloud-keyed copy of the order, not the row we're printing. Look across the
	// whole order family — otherwise the slip can't tell it's a split, prints
	// every món, and shows ¥0 paid.
	ids := s.linkedOrderIDs(orderID)
	ph, args := inPlaceholders(ids)

	// Non-failed (pending + confirmed): the kiosk creates the payment on WS and
	// often leaves it PENDING here (the confirm step goes to Cloud), so keying
	// off 'confirmed' would see ¥0 paid and miss the split metadata entirely —
	// the slip would then print every món at full price. A failed payment is the
	// only one that doesn't count.
	var paidSum int
	_ = s.db.QueryRow(`
		SELECT COALESCE(SUM(amount), 0), COUNT(*)
		FROM payments WHERE order_id IN (`+ph+`) AND status != 'failed'`, args...).Scan(&paidSum, &st.paidCount)

	// The local payments table only reliably records workstation-originated
	// payments. A Cloud-settled order — e.g. a Stripe-paid takeaway — syncs its
	// paid_amount onto the local orders row but carries NO local payments row, so
	// paidSum stays 0 and the receipt would print "Con lai = the full total" on a
	// fully-paid order. The order's own paid_amount is authoritative (RecordPayment
	// only sets it = total on a full settle; partial splits leave it 0 until close),
	// so fold it in — it can only ever REDUCE a stale remaining, never inflate it.
	var orderPaid int
	_ = s.db.QueryRow(`
		SELECT COALESCE(MAX(paid_amount), 0)
		FROM orders WHERE id IN (`+ph+`)`, args...).Scan(&orderPaid)
	if orderPaid > paidSum {
		paidSum = orderPaid
	}

	st.remaining = orderTotal - paidSum
	if st.remaining < 0 {
		st.remaining = 0
	}

	// Split context. For a targeted per-person reprint, read the metadata of the
	// EXACT payment being printed so each payer's slip carries their own
	// items/label/amount. Otherwise fall back to the latest non-failed payment.
	var metaRaw string
	if strings.TrimSpace(paymentID) != "" {
		_ = s.db.QueryRow(`
			SELECT COALESCE(metadata, '')
			FROM payments
			WHERE id = ? AND order_id IN (`+ph+`) AND status != 'failed'`,
			append([]any{paymentID}, args...)...).Scan(&metaRaw)
	} else {
		_ = s.db.QueryRow(`
			SELECT COALESCE(metadata, '')
			FROM payments
			WHERE order_id IN (`+ph+`) AND status != 'failed'
			ORDER BY created_at DESC
			LIMIT 1`, args...).Scan(&metaRaw)
	}
	if metaRaw != "" {
		var meta struct {
			TotalBills      int    `json:"total_bills"`
			SplitCount      int    `json:"split_count"` // legacy fallback
			SplitMode       string `json:"split_mode"`
			BillIndex       int    `json:"bill_index"` // 0-based
			ExpectedTotal   int    `json:"expected_total_amount"`
			ItemAllocations []struct {
				ItemID string `json:"item_id"`
				Units  int    `json:"units"`
			} `json:"item_allocations"`
			// Plan-038 T2.4 — by_amount split per-person fields. Sit
			// alongside the legacy ones so the same decode covers all
			// three split modes.
			Label  string `json:"label"`
			Amount int    `json:"amount"`
		}
		if json.Unmarshal([]byte(metaRaw), &meta) == nil {
			st.splitCount = meta.TotalBills
			if st.splitCount == 0 {
				st.splitCount = meta.SplitCount
			}
			st.splitMode = strings.TrimSpace(meta.SplitMode)
			st.slipIndex = meta.BillIndex + 1
			st.expectedTotal = meta.ExpectedTotal
			if len(meta.ItemAllocations) > 0 {
				st.allocations = make(map[string]int, len(meta.ItemAllocations))
				for _, a := range meta.ItemAllocations {
					if a.ItemID != "" && a.Units > 0 {
						st.allocations[a.ItemID] += a.Units
					}
				}
			}
			if st.splitMode == "by_amount" {
				st.byAmountLabel = strings.TrimSpace(meta.Label)
				st.byAmountAmount = meta.Amount
			}
		}
	}
	// Diagnostic: surfaces exactly what the client sent so a "still prints every
	// món" report can be traced to a missing/renamed allocation field vs. a
	// resolution miss. metaRaw is logged verbatim (split payloads are small).
	slog.Info("payment_receipt.split",
		"order", orderID, "split_count", st.splitCount, "split_mode", st.splitMode,
		"allocations", len(st.allocations), "expected_total", st.expectedTotal,
		"paid_count", st.paidCount, "meta", metaRaw)
	return st
}

// resolveReceiptPrinter picks the printer for payment receipts:
//  1. a device carrying the receipt_printer role, else
//  2. the hold_printer (runner) device, else
//  3. the kitchen_printer (single-device shop).
//
// Returns nil when no printer is configured at all.
func (s *Server) resolveReceiptPrinter() *printer.Printer {
	for _, role := range []printer.DeviceType{
		printer.TypeReceiptPrinter,
		printer.TypeHoldPrinter,
		printer.TypeKitchenPrinter,
	} {
		if p := s.devices.GetPrinterByRole(role); p != nil {
			return p
		}
	}
	return nil
}

// paidSlipInputs computes what the "DA THANH TOAN" slip renders for a payment.
// The four split cases pos-web / kiosk produce map to three rendering shapes:
//
//   - chia theo món (by-items): print ONLY the món this person chose. A sub-order
//     of the allocated món is built and NormalizedTotals re-derives a món-based
//     Tam tinh / Phi / Thue / Tong, so every printed line + the total belong to
//     this person. Detected by the presence of item_allocations.
//   - chia theo số tiền/người (by_amount, plan-038 T2.4): print NO item list at
//     all — the slip carries just the per-person label + amount + payment
//     method + remaining. Distinguished by splitMode=="by_amount".
//   - chia đều (equal) AND thanh toán 1 phần (partial / tự chia): print the WHOLE
//     order — all món + the order's own gross breakdown — plus "Da thanh toan"
//     (this slip's amount) and "Con lai" (whole-order remaining). Nothing is
//     filtered; the customer sees the full bill and how much was just paid.
//   - not a split: identical to the equal/partial shape (whole order + paid).
//
// So món-filtering happens ONLY for by-items; by_amount strips the item list
// entirely; every other mode falls through with the full order untouched
// (BillTotal 0 → formatter uses the gross total).
// loadTenderedChange returns the cash tendered + change recorded on the payment
// this slip settles (matched by amount, else the order's latest cash payment).
// Both zero for non-cash methods (no tendered_amount recorded) → the slip omits
// the tendered/change lines.
func (s *Server) loadTenderedChange(orderID string, amount int) (tendered, change int) {
	var t, c sql.NullInt64
	err := s.db.QueryRow(`
		SELECT tendered_amount, COALESCE(change_amount, 0) FROM payments
		WHERE order_id = ? AND amount = ? AND status IN ('succeeded', 'confirmed')
		  AND tendered_amount IS NOT NULL AND tendered_amount > 0
		ORDER BY COALESCE(paid_at, created_at) DESC LIMIT 1`, orderID, amount).Scan(&t, &c)
	if err != nil {
		_ = s.db.QueryRow(`
			SELECT tendered_amount, COALESCE(change_amount, 0) FROM payments
			WHERE order_id = ? AND status IN ('succeeded', 'confirmed')
			  AND tendered_amount IS NOT NULL AND tendered_amount > 0
			ORDER BY COALESCE(paid_at, created_at) DESC LIMIT 1`, orderID).Scan(&t, &c)
	}
	return int(t.Int64), int(c.Int64)
}

// paymentMethodDisplay resolves the human-readable payment method printed on a
// receipt / red invoice. It targets the payment matching `amount` (a single
// slip) first; failing that it lists every distinct method the order was paid
// with (comma-joined) so a split-paid order shows them all. The name comes from
// the synced payment_methods table (by id, then code) — the SAME label the
// cashier saw at pay time — with a localized fallback for a well-known code
// whose row is absent. Returns "" when the order has no confirmed payment.
//
// Sibling-aware (linkedOrderIDs) so a payment tied to the Cloud-keyed copy of
// the order is still found, exactly like lastConfirmedPaymentAmount.
func (s *Server) paymentMethodDisplay(orderID string, amount int, locale string) string {
	ids := s.linkedOrderIDs(orderID)
	ph, args := inPlaceholders(ids)

	// 1. The exact payment for this slip's amount.
	if amount > 0 {
		a := append(append([]any{}, args...), amount)
		var methodID, code string
		err := s.db.QueryRow(`
			SELECT COALESCE(payment_method_id, ''), COALESCE(payment_method, '')
			FROM payments
			WHERE order_id IN (`+ph+`) AND amount = ? AND status IN ('succeeded', 'confirmed')
			ORDER BY COALESCE(paid_at, created_at) DESC LIMIT 1`, a...).Scan(&methodID, &code)
		if err == nil {
			if name := s.resolvePaymentMethodName(methodID, code, locale); name != "" {
				return name
			}
		}
	}

	// 2. All distinct methods on the order (whole-order invoice / split pay).
	rows, err := s.db.Query(`
		SELECT DISTINCT COALESCE(payment_method_id, ''), COALESCE(payment_method, '')
		FROM payments
		WHERE order_id IN (`+ph+`) AND status IN ('succeeded', 'confirmed')`, args...)
	if err != nil {
		return ""
	}
	defer rows.Close()
	seen := map[string]bool{}
	var names []string
	for rows.Next() {
		var methodID, code string
		if rows.Scan(&methodID, &code) != nil {
			continue
		}
		if name := s.resolvePaymentMethodName(methodID, code, locale); name != "" && !seen[name] {
			seen[name] = true
			names = append(names, name)
		}
	}
	return strings.Join(names, ", ")
}

// resolvePaymentMethodName returns the display name for a payment method,
// preferring the synced payment_methods.name (by id, then code) and falling
// back to a localized label for a well-known built-in code.
func (s *Server) resolvePaymentMethodName(methodID, code, locale string) string {
	var name string
	if methodID != "" {
		if err := s.db.QueryRow(`SELECT name FROM payment_methods WHERE id = ?`, methodID).Scan(&name); err == nil {
			if n := strings.TrimSpace(name); n != "" {
				return n
			}
		}
	}
	if code != "" {
		if err := s.db.QueryRow(
			`SELECT name FROM payment_methods WHERE code = ? ORDER BY is_active DESC LIMIT 1`, code,
		).Scan(&name); err == nil {
			if n := strings.TrimSpace(name); n != "" {
				return n
			}
		}
	}
	return paymentMethodCodeLabel(code, locale)
}

// paymentMethodCodeLabel gives a localized label for the well-known built-in
// payment codes, used only when the synced payment_methods row is missing
// (offline / legacy payment). Vietnamese is pre-folded to ASCII to match the
// Shift_JIS print catalog. Unknown codes return the raw code.
func paymentMethodCodeLabel(code, locale string) string {
	code = strings.TrimSpace(strings.ToLower(code))
	if code == "" {
		return ""
	}
	type label struct{ ja, en, vi string }
	known := map[string]label{
		"cash":          {"現金", "Cash", "Tien mat"},
		"card":          {"カード", "Card", "The"},
		"card_terminal": {"カード端末", "Card terminal", "May POS"},
		"credit_card":   {"クレジットカード", "Credit card", "The tin dung"},
		"qr":            {"QRコード", "QR code", "QR"},
		"transfer":      {"振込", "Bank transfer", "Chuyen khoan"},
		"bank_transfer": {"振込", "Bank transfer", "Chuyen khoan"},
		"e_wallet":      {"電子マネー", "E-wallet", "Vi dien tu"},
		"stripe":        {"Stripe", "Stripe", "Stripe"},
		"debt":          {"ツケ", "On account", "Ghi no"},
		"on_account":    {"ツケ", "On account", "Ghi no"},
	}
	l, ok := known[code]
	if !ok {
		return code
	}
	switch locale {
	case "en":
		return l.en
	case "vi":
		return l.vi
	default:
		return l.ja
	}
}

func (s *Server) paidSlipInputs(o *service.Order, st splitState, amountThisSlip int) (*service.Order, []service.Item, service.PaymentSlipInfo) {
	slipOrder := o
	slipItems := o.Items
	billTotal := 0 // 0 → formatter uses the order's own gross total + shows breakdown
	remaining := st.remaining

	// by-amount split — show the FULL món list (per requirement: every split
	// bill lists all món/sku/topping/option; only the amount differs). The
	// footer shows Tong don (order gross) + Phan chia (this person's amount).
	if st.isByAmount() {
		amount := st.byAmountAmount
		if amount <= 0 {
			amount = amountThisSlip
		}
		return o, o.Items, service.PaymentSlipInfo{
			PaymentMethod:   o.PaymentMethod,
			AmountPaid:      amount, // → "Phan chia (i/N)"
			SlipIndex:       st.slipIndex,
			SplitCount:      st.splitCount,
			BillTotal:       0, // full order gross drives the "Tong don" line
			Remaining:       st.remaining,
			Label:           st.byAmountLabel,
			SplitMode:       "by_amount",
			OrderGrossTotal: o.TotalAmount,
		}
	}

	// by-items only: a món allocation means "print just these món". Keyed on the
	// allocation itself, NOT splitCount — the kiosk often omits total_bills for a
	// by-items split, and gating on the count printed the whole order.
	if len(st.allocations) > 0 {
		if filtered := s.allocatedItems(o, st.allocations); len(filtered) > 0 && s.orders != nil {
			sub := *o
			sub.Items = filtered
			sSub, sDisc, sTax, sSvc, sTot := s.orders.NormalizedTotals(&sub)
			sub.Subtotal, sub.DiscountAmount, sub.TaxAmount, sub.ServiceCharge, sub.TotalAmount = sSub, sDisc, sTax, sSvc, sTot
			slipOrder, slipItems = &sub, filtered
			// Con lai = |tổng món - đã thanh toán| (over-payment shows the excess).
			remaining = sTot - amountThisSlip
			if remaining < 0 {
				remaining = -remaining
			}
		}
	}

	// SplitMode drives the banner + share breakdown for the remaining modes
	// (by_items → filtered món above; even/equal → full món). Empty when this
	// isn't a split so the formatter renders a normal single receipt.
	splitMode := ""
	switch {
	case len(st.allocations) > 0 || st.splitMode == "by_items":
		splitMode = "by_items"
	case st.splitMode == "even" || st.splitMode == "equal" || st.splitCount > 1:
		splitMode = "equal"
	}

	return slipOrder, slipItems, service.PaymentSlipInfo{
		PaymentMethod:   o.PaymentMethod,
		SplitMode:       splitMode,
		OrderGrossTotal: o.TotalAmount,
		AmountPaid:      amountThisSlip,
		SlipIndex:       st.slipIndex,
		SplitCount:      st.splitCount,
		BillTotal:       billTotal,
		Remaining:       remaining,
	}
}

// allocatedItems resolves a by-items split's allocation (order_item id → units)
// to THIS order's local món. It first matches by item id directly (the order was
// read locally → ids line up). If that finds nothing, the allocation ids came
// from a different id space — typically the Cloud copy of the order, whose item
// ids differ from the local row's. It then resolves each allocation id to its
// product_sku via order_items (which holds both the local row and any pulled
// sibling) and matches the local món by product_sku. Returns nil when nothing
// resolves, so the caller falls back to the proportional-share slip rather than
// printing món that don't belong to this person.
func (s *Server) allocatedItems(o *service.Order, alloc map[string]int) []service.Item {
	if direct := filterItemsByAllocation(o.Items, alloc); len(direct) > 0 {
		return direct
	}

	skuUnits := map[string]int{}
	for itemID, units := range alloc {
		if units <= 0 {
			continue
		}
		var sku string
		_ = s.db.QueryRow(`SELECT COALESCE(product_sku_id, '') FROM order_items WHERE id = ?`, itemID).Scan(&sku)
		if sku != "" {
			skuUnits[sku] += units
		}
	}
	if len(skuUnits) == 0 {
		return nil
	}

	out := make([]service.Item, 0, len(skuUnits))
	for _, it := range o.Items {
		if it.ProductSkuID == "" {
			continue
		}
		units, ok := skuUnits[it.ProductSkuID]
		if !ok || units <= 0 {
			continue
		}
		clone := it
		if units < clone.Quantity {
			clone.Quantity = units
		}
		out = append(out, clone)
	}
	return out
}

// filterItemsByAllocation returns the order items that appear in alloc, each
// with its quantity reduced to the units paid on this sub-bill (by-items split).
// Items not in alloc are dropped; original order preserved. The source items are
// copied, so the caller's order is left untouched.
func filterItemsByAllocation(items []service.Item, alloc map[string]int) []service.Item {
	out := make([]service.Item, 0, len(alloc))
	for _, it := range items {
		units, ok := alloc[it.ID]
		if !ok || units <= 0 {
			continue
		}
		clone := it
		if units < clone.Quantity {
			clone.Quantity = units
		}
		out = append(out, clone)
	}
	return out
}

// normalizeOrderForPrint mutates the order in place so a printed slip matches
// the kiosk screen + Cloud. Two fixes, same root cause as the WS raw-order bug:
//   - resolve each item's display name via the shared W1 resolver
//     (menu_item_name ?: product.name via product_sku join, with an "(unknown)"
//     guard) when menu_item_name is blank; otherwise printRunnerItem skips the
//     line and the bill prints empty.
//   - recompute the additive money breakdown (subtotal/discount/tax/service/
//     total) via the order engine, since sync-down orders store zeros — so the
//     printed Tam tinh/Phi phuc vu/Thue/Tong agree with the screen instead of
//     the old tax-included line sum (which dropped service charge entirely).
func (s *Server) normalizeOrderForPrint(o *service.Order) {
	if o == nil {
		return
	}
	for i := range o.Items {
		if strings.TrimSpace(o.Items[i].MenuItemName) != "" {
			continue
		}
		// resolveItemNameAndImage guarantees a non-empty name ("(unknown)" guard).
		name, _ := s.resolveItemNameAndImage(o.Items[i])
		o.Items[i].MenuItemName = name
	}
	if s.orders != nil {
		subtotal, discount, tax, service, total := s.orders.NormalizedTotals(o)
		if total > 0 {
			o.Subtotal = subtotal
			o.DiscountAmount = discount
			o.TaxAmount = tax
			o.ServiceCharge = service
			o.TotalAmount = total
		}
	}
}

// autoPrintBillEnabled reports whether bills/receipts should print
// automatically on payment (create/confirm) and on pull-down of a paid order.
// Disabled by default — a bill only prints when a user presses a print button
// (POS "Print Receipt", /api/lan/print/*). Re-enable by setting the
// `auto_print_bill` setting to "true".
func (s *Server) autoPrintBillEnabled() bool {
	return s.settingValue("auto_print_bill") == "true"
}

// printPaymentReceipt formats and prints the receipt(s) for a confirmed
// payment on orderID. amountThisSlip is the amount settled on the triggering
// payment (used for the split "Da thanh toan" line). Used by both the
// auto-print-on-confirm path and the reprint endpoint — it reads everything
// from local SQLite and never creates a payment.
//
// Output:
//   - always: a "DA THANH TOAN" slip (no QR) for the amount just paid.
//   - additionally, when this is a split with money still owed: a "PHAN CON LAI"
//     slip (with QR) so the next person can scan + pay the remainder.
//
// Returns an error only on hard failures (order not found, no printer,
// connection error). Print errors are best-effort and must NOT block the
// caller (payment confirm already succeeded).
func (s *Server) printPaymentReceipt(orderID string, amountThisSlip int, locale string, paymentID string) error {
	// Best-effort path — when the order engine or device registry isn't wired
	// (e.g. unit tests, headless boot) there's nothing to print. Never panic.
	if s.orders == nil || s.devices == nil {
		return nil
	}
	o, err := s.orders.GetByID(orderID)
	if err != nil {
		return err
	}

	// Normalize before rendering so the slip matches the kiosk screen + Cloud:
	// resolve blank item names from the product catalog and recompute the
	// additive money breakdown. Without this the bill prints empty product rows
	// and a tax/service total that disagrees with what the customer saw.
	s.normalizeOrderForPrint(o)
	// …then rewrite item/topping/variant names into the print locale so the
	// receipt follows the operator's language instead of the add-time snapshot.
	s.localizeOrderForPrint(o, locale)

	p := s.resolveReceiptPrinter()
	if p == nil {
		slog.Warn("payment receipt: no printer configured", "order", orderID)
		return nil
	}

	config := service.PrintJobConfig{
		PaperWidth:    42,
		Locale:        locale, // pos-web operator's language → receipt labels
		PhysicalWidth: p.CharWidth(),
		StoreName:     s.settingValue("workstation_branch_name"),
		StoreSubName:  s.settingValue("workstation_brand_name"),
		TaxRate:       s.shopTaxRate(),
		Currency:      s.printCurrencySymbol(),
		// plan-043 T4.1 — ISO code drives the per-rate tax-block rounding step;
		// the T+13 registration number prints only when the shop carries one
		// (Q5 — the source setting is empty for now, so it won't print).
		CurrencyCode:             s.shopSetting("currency_code", "JPY"),
		SellerRegistrationNumber: s.shopSetting("seller_registration_number", ""),
	}

	st := s.deriveSplitState(orderID, o.TotalAmount, paymentID)
	slipOrder, slipItems, slip := s.paidSlipInputs(o, st, amountThisSlip)
	// Cash tendered + change on the receipt so the customer sees what they gave
	// and what they got back.
	slip.Tendered, slip.Change = s.loadTenderedChange(orderID, amountThisSlip)
	// Payment method — resolved from the payments table (the deprecated
	// order.payment_method column is usually empty), so the receipt shows how
	// the order was actually paid.
	slip.PaymentMethod = s.paymentMethodDisplay(orderID, amountThisSlip, locale)
	paid := service.FormatPaidTicket(slipOrder, slipItems, 0, config, slip)

	// The "PHAN CON LAI" + QR slip is for the NEXT payer to scan & pay the
	// remainder — only meaningful on the auto-print-on-payment path (paymentID
	// == ""). A TARGETED per-person reprint (pos-web "In biên lai", paymentID
	// set) is a POS-driven split where every share is already collected at the
	// register; the QR slip is redundant there — and by-items per-bill tax
	// rounding can leave a ¥1-2 residual `remaining` that would wrongly trigger
	// it. So skip the QR slip entirely on a targeted reprint.
	var remainingTicket []byte
	if shouldPrintRemainingQRSlip(paymentID, st) {
		remainingTicket = service.FormatRemainingTicket(o, o.Items, 0, config, st.remaining)
	}

	if err := p.Connect(); err != nil {
		return err
	}
	defer p.Disconnect()

	if err := p.Print(paid); err != nil {
		return err
	}
	if remainingTicket != nil {
		_ = p.Print(remainingTicket)
	}
	return nil
}

// POST /api/lan/print/red-invoice
//
// Body: { order_id, customer_name }
//
// Prints the hoá đơn đỏ — the same content as the paid receipt (items, totals,
// payment method, cash tendered/change) plus a named-customer line. Manual and
// unlimited reprints; safe to call any time after the order exists.
func (s *Server) handleLANPrintRedInvoice(w http.ResponseWriter, r *http.Request) {
	if s.orders == nil || s.devices == nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{"message": "workstation not ready"})
		return
	}
	var body struct {
		OrderID      string `json:"order_id"`
		CustomerName string `json:"customer_name"`
	}
	if err := readJSON(r, &body); err != nil || strings.TrimSpace(body.OrderID) == "" {
		writeError(w, http.StatusBadRequest, "order_id required")
		return
	}

	o, err := s.orders.GetByID(body.OrderID)
	if err != nil || o == nil {
		writeError(w, http.StatusNotFound, "order not found")
		return
	}

	locale := s.printLabelLocale()
	s.normalizeOrderForPrint(o)
	s.localizeOrderForPrint(o, locale)

	p := s.resolveReceiptPrinter()
	if p == nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{"status": "no_printer", "detail": "receipt_printer"})
		return
	}

	config := service.PrintJobConfig{
		PaperWidth:               42,
		Locale:                   locale,
		PhysicalWidth:            p.CharWidth(),
		StoreName:                s.settingValue("workstation_branch_name"),
		StoreSubName:             s.settingValue("workstation_brand_name"),
		TaxRate:                  s.shopTaxRate(),
		Currency:                 s.printCurrencySymbol(),
		CurrencyCode:             s.shopSetting("currency_code", "JPY"),
		SellerRegistrationNumber: s.shopSetting("seller_registration_number", ""),
	}

	amount := o.PaidAmount
	if amount <= 0 {
		amount = o.TotalAmount
	}
	st := s.deriveSplitState(o.ID, o.TotalAmount, "")
	slipOrder, slipItems, slip := s.paidSlipInputs(o, st, amount)
	slip.Tendered, slip.Change = s.loadTenderedChange(o.ID, amount)
	// Payment method(s) the order was paid with — the red invoice must state it.
	slip.PaymentMethod = s.paymentMethodDisplay(o.ID, amount, locale)
	slip.CustomerName = strings.TrimSpace(body.CustomerName)

	invoice := service.FormatRedInvoiceTicket(slipOrder, slipItems, config, slip)

	if err := p.Connect(); err != nil {
		writeServerError(w, r, err)
		return
	}
	defer p.Disconnect()
	if err := p.Print(invoice); err != nil {
		writeServerError(w, r, err)
		return
	}
	s.auditLogPOS(r, "print.red_invoice", "order", o.ID,
		fmt.Sprintf(`{"customer":%q}`, strings.TrimSpace(body.CustomerName)))
	writeJSON(w, http.StatusOK, map[string]any{"status": "printed"})
}
