package handler

import (
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// /api/lan/print/* — LAN print namespace owned by plan-038.
//
// Three browser-callable endpoints used by pos-web:
//
//	POST /api/lan/print/kitchen-ticket   T2.1 — fire kitchen tickets per group
//	POST /api/lan/print/payment-receipt  T2.2 — already at lan_local.go, extended
//	GET  /api/lan/print/status           T2.3 — printer + sync probe
//
// All three sit inside the `lanOnly + corsMiddleware + corsForBrowser + authed`
// middleware ring. CORS preflight for pos-web origins is registered in
// routes.go alongside the handler.

// POST /api/lan/print/kitchen-ticket   (auth: Bearer, LAN)
//
// Fires the kitchen ticket(s) for an open order. Items grouped by
// printer_group, each group dispatched to its resolved role
// (kitchen/bar/hold via printer/dispatcher.go).
//
// Body: { order_id: "uuid", idempotency_key?: "uuid" }
//
// Response shapes:
//
//	200 { status:"ok",      printed:N, groups:[{printer_group, ticket_no, items}] }
//	200 { status:"partial", printed:N, groups:[...], errors:[{printer_group, reason, detail?}] }
//	400 { message:"order_id required" }
//	401 { message:"not authenticated" }
//	404 { message:"order not found" } — local missing AND force-pull 404
//	422 { message:"no unprinted items" }
//	503 { status:"fire_failed", errors:[...] } — nothing reached the kitchen (e.g.
//	     ticket-counter failure on every group). A missing printer is NOT this:
//	     the KDS display is the kitchen ticket, so a printer-less group still
//	     fires (200 partial with a no_printer note).
//	504 { message:"force-pull timed out", retry_after_ms:1500 }
//
// Side effects:
//   - order_items.print_status flips to 'sent_to_kitchen' (printed / no-printer)
//     or 'failed' (print errored, delta kept open for retry) per fired item.
//   - One audit log row written: action='order.fire', details={source:"pos-web",...}.
//   - WS hub broadcast: 'order.kitchen_printed' scoped to the order's branch_id
//     whenever firedCount>0 — including the no-printer / print-failed cases — so
//     KDS always learns about the items (it re-fetches on the event).
func (s *Server) handleLANPrintKitchenTicket(w http.ResponseWriter, r *http.Request) {
	if _, ok := DeviceFromContext(r.Context()); !ok {
		writeError(w, http.StatusUnauthorized, "not authenticated")
		return
	}

	var body struct {
		OrderID        string `json:"order_id"`
		IdempotencyKey string `json:"idempotency_key"`
	}
	if err := readJSON(r, &body); err != nil || body.OrderID == "" {
		writeError(w, http.StatusBadRequest, "order_id required")
		return
	}

	o, err := s.orders.GetByID(body.OrderID)
	if err != nil || o == nil {
		// Force-pull on miss — closes the 5 s sync race per plan-038 T1.1.
		if s.puller == nil {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		pullErr := s.puller.PullOrderNow(r.Context(), body.OrderID)
		if errors.Is(pullErr, service.ErrOrderNotFoundOnCloud) {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		if pullErr != nil {
			// Timeout vs. Cloud 5xx — surface both as 504/503 respectively.
			if errors.Is(pullErr, r.Context().Err()) || isContextTimeout(pullErr) {
				writeJSON(w, http.StatusGatewayTimeout, map[string]any{
					"message":        "force-pull timed out",
					"retry_after_ms": 1500,
				})
				return
			}
			slog.Warn("kitchen-ticket force-pull failed", "order_id", body.OrderID, "err", pullErr)
			writeJSON(w, http.StatusServiceUnavailable, map[string]any{
				"message":        "cloud unavailable",
				"retry_after_ms": 3000,
			})
			return
		}
		o, err = s.orders.GetByID(body.OrderID)
		if err != nil || o == nil {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
	}

	// Check for an unprinted delta BEFORE invoking the helper so the 422
	// contract stays consistent with the handy endpoint. Counts a bumped
	// quantity on an already-fired line, not just brand-new lines.
	hasPending := false
	for _, item := range o.Items {
		if needsFire(item) {
			hasPending = true
			break
		}
	}
	if !hasPending {
		writeError(w, http.StatusUnprocessableEntity, "no unprinted items")
		return
	}

	firedCount, groups, fireErrors := s.fireKitchenForOrder(o, s.printLabelLocale())

	s.auditLogPOS(r, "order.fire", "order", body.OrderID, auditDetails(map[string]any{
		"source":  "pos-web",
		"printed": firedCount,
		"errors":  len(fireErrors),
	}))

	// Broadcast order.kitchen_printed so KDS clients render the items live.
	// Plan-038 T2.7 + T9.2. firedCount > 0 covers a KDS-only kitchen (no
	// printer) and a failed print too — the KDS display is the authoritative
	// kitchen ticket, so it must always be notified, not only when paper came
	// out. KDS re-fetches on this event, so a stale payload is fine.
	if firedCount > 0 {
		s.broadcastKitchenPrinted(o, groups, "pos-web")
	}

	// firedCount == 0 only when every group failed BEFORE the items could be
	// dispatched (e.g. ticket-counter error) — nothing reached the kitchen.
	if firedCount == 0 {
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{
			"status": "fire_failed",
			"errors": fireErrors,
		})
		return
	}

	// Items reached the kitchen (KDS notified). `partial` flags that some/all
	// didn't physically print (no_printer / print error) so pos-web can warn the
	// cashier no paper came out — but it is NOT a failure: the kitchen has them.
	if len(fireErrors) > 0 {
		writeJSON(w, http.StatusOK, map[string]any{
			"status":  "partial",
			"printed": firedCount,
			"groups":  groups,
			"errors":  fireErrors,
		})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"status":  "ok",
		"printed": firedCount,
		"groups":  groups,
	})
}

// handleLANPrintOrderBill prints the FULL order as a bill with a QR code
// (order.ID) — the "print order bill" button. Unlike the kitchen fire (which
// prints only the newly-added delta), this is an on-demand full snapshot of
// every item added to the order so far. It has NO reprint limit: the operator
// can print it any number of times and no print counter is recorded.
//
// Body: { order_id: "uuid" }
func (s *Server) handleLANPrintOrderBill(w http.ResponseWriter, r *http.Request) {
	if _, ok := DeviceFromContext(r.Context()); !ok {
		writeError(w, http.StatusUnauthorized, "not authenticated")
		return
	}
	var body struct {
		OrderID string `json:"order_id"`
	}
	if err := readJSON(r, &body); err != nil || body.OrderID == "" {
		writeError(w, http.StatusBadRequest, "order_id required")
		return
	}

	o, err := s.orders.GetByID(body.OrderID)
	if err != nil || o == nil {
		// Force-pull on miss — mirrors the kitchen-ticket handler so a bill can
		// print right after an offline-created order syncs.
		if s.puller == nil {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		pullErr := s.puller.PullOrderNow(r.Context(), body.OrderID)
		if errors.Is(pullErr, service.ErrOrderNotFoundOnCloud) {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		if pullErr != nil {
			if errors.Is(pullErr, r.Context().Err()) || isContextTimeout(pullErr) {
				writeJSON(w, http.StatusGatewayTimeout, map[string]any{"message": "force-pull timed out", "retry_after_ms": 1500})
				return
			}
			writeJSON(w, http.StatusServiceUnavailable, map[string]any{"message": "cloud unavailable", "retry_after_ms": 3000})
			return
		}
		o, err = s.orders.GetByID(body.OrderID)
		if err != nil || o == nil {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
	}

	// Item/topping/variant names on the bill follow the print locale.
	s.localizeOrderForPrint(o, s.printLabelLocale())

	// Bill goes to the receipt printer; fall back to the hold, then kitchen
	// printer so a single-printer shop still gets the slip.
	rp := s.devices.GetPrinterByRole(printer.TypeReceiptPrinter)
	if rp == nil {
		rp = s.devices.GetPrinterByRole(printer.TypeHoldPrinter)
	}
	if rp == nil {
		rp = s.devices.GetPrinterByRole(printer.TypeKitchenPrinter)
	}
	if rp == nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{"status": "no_printer", "detail": "no printer configured"})
		return
	}

	config := service.PrintJobConfig{
		PaperWidth:    42,
		Locale:        s.printLabelLocale(), // shop-wide print language → bill labels
		PhysicalWidth: rp.CharWidth(),
		StoreName:     s.settingValue("workstation_branch_name"),
		StoreSubName:  s.settingValue("workstation_brand_name"),
		TaxRate:       s.shopTaxRate(),
		Currency:      s.printCurrencySymbol(),
	}

	// ticketNo is unused by the bill formatter (formatBillTicket ignores it).
	slip := service.FormatRunnerTicket(o, o.Items, 0, config)

	if err := rp.Connect(); err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rp.Disconnect()
	if err := rp.Print(slip); err != nil {
		writeServerError(w, r, err)
		return
	}

	s.auditLogPOS(r, "order.bill_printed", "order", body.OrderID, auditDetails(map[string]any{
		"source": "pos-web",
		"items":  len(o.Items),
	}))

	writeJSON(w, http.StatusOK, map[string]any{
		"status": "ok",
		"items":  len(o.Items),
	})
}

// broadcastKitchenPrinted emits 'order.kitchen_printed' on the WS hub
// scoped to the order's branch. KDS clients pre-subscribe and append the
// order card live (plan-038 T9.x). Payload mirrors the response groups so
// downstream consumers can render without an extra GET.
func (s *Server) broadcastKitchenPrinted(o *service.Order, groups []fireGroupResult, source string) {
	if s.hub == nil {
		return
	}
	itemsPayload := make([]map[string]any, 0, len(o.Items))
	for _, it := range o.Items {
		// Skip voided lines so a cancelled item never appears on a kitchen
		// ticket / KDS card. (KDS re-fetches on this event anyway, so the
		// per-item print_status below may be the stale pre-fire value — the
		// payload is a hint, not the source of truth.)
		if it.Status == service.ItemStatusVoided {
			continue
		}
		itemsPayload = append(itemsPayload, map[string]any{
			"id":            it.ID,
			"name":          it.MenuItemName,
			"qty":           it.Quantity,
			"note":          it.Note,
			"printer_group": it.PrinterGroup,
			"print_status":  string(it.PrintStatus),
		})
	}
	payload := map[string]any{
		"order_id":   o.ID,
		"order_code": o.OrderCode,
		"table_no":   o.TableNumber,
		"groups":     groups,
		"items":      itemsPayload,
		"fired_at":   time.Now().UTC().Format(time.RFC3339),
		"source":     source,
		"branch_id":  s.workstationBranchID(),
	}
	s.hub.BroadcastEventScoped("order.kitchen_printed", payload, s.workstationBranchID())
}

// classifyPrintError maps a receipt-print failure to a coarse machine reason
// for the print_status WS event. ESC/POS can't reliably distinguish paper-out
// from a dead link, so we only separate "can't reach the printer" from
// "printer rejected the job" — enough for the kiosk to phrase its banner.
func classifyPrintError(err error) string {
	if err == nil {
		return ""
	}
	msg := strings.ToLower(err.Error())
	switch {
	case strings.Contains(msg, "connect"),
		strings.Contains(msg, "dial"),
		strings.Contains(msg, "refused"),
		strings.Contains(msg, "timeout"),
		strings.Contains(msg, "no route"),
		strings.Contains(msg, "unreachable"),
		strings.Contains(msg, "broken pipe"),
		strings.Contains(msg, "reset by peer"):
		return "printer_offline"
	default:
		return "printer_error"
	}
}

// broadcastPrintStatus emits 'print_status' on the WS hub so a kiosk that asked
// workstation to auto-print a slip learns whether the printer actually produced
// it. Workstation is the single print authority and auto-prints the "DA THANH
// TOAN" bill on payment confirm — but until now that print was blind: a printer
// offline / out of paper left the kiosk showing "payment complete" with no slip
// and no warning. The kiosk pre-subscribes to this event and surfaces a reprint
// banner on `status: "failed"`. See plan §5 WS-1.
func (s *Server) broadcastPrintStatus(orderID, kind string, printErr error) {
	if s.hub == nil {
		return
	}
	payload := map[string]any{
		"order_id":   orderID,
		"kind":       kind,
		"status":     "success",
		"printed_at": time.Now().UTC().Format(time.RFC3339),
		"branch_id":  s.workstationBranchID(),
	}
	if printErr != nil {
		payload["status"] = "failed"
		payload["reason"] = classifyPrintError(printErr)
		payload["detail"] = printErr.Error()
	}
	s.hub.BroadcastEventScoped("print_status", payload, s.workstationBranchID())
}

// POST /api/lan/print/debt-slip   (auth: Bearer, LAN)
//
// Prints a "PHIEU GHI NO" thermal slip for a confirmed on_account payment.
// Plan-038 T10.6.
//
// Body:
//
//	{ order_id: "uuid",
//	  payment_id: "uuid",       // must reference an on_account payment
//	  reprint_reason?: "string" }
//
// Side effects:
//   - payments.metadata.print_history[] appended via AppendPrintHistory.
//   - Audit log: payment.debt_slip_printed
func (s *Server) handleLANPrintDebtSlip(w http.ResponseWriter, r *http.Request) {
	if _, ok := DeviceFromContext(r.Context()); !ok {
		writeError(w, http.StatusUnauthorized, "not authenticated")
		return
	}
	var body struct {
		OrderID       string `json:"order_id"`
		PaymentID     string `json:"payment_id"`
		ReprintReason string `json:"reprint_reason"`
	}
	if err := readJSON(r, &body); err != nil || body.OrderID == "" || body.PaymentID == "" {
		writeError(w, http.StatusBadRequest, "order_id and payment_id required")
		return
	}
	if len(body.ReprintReason) > 256 {
		writeError(w, http.StatusBadRequest, "reprint_reason too long")
		return
	}
	reason := body.ReprintReason
	if reason == "" {
		reason = "auto"
	}

	o, err := s.orders.GetByID(body.OrderID)
	if err != nil || o == nil {
		writeError(w, http.StatusNotFound, "order not found")
		return
	}
	// Item/topping/variant names on the debt slip follow the print locale.
	s.localizeOrderForPrint(o, s.printLabelLocale())

	// Resolve the payment, status, amount, and method type. The handler
	// only proceeds when type='on_account' so a kitchen-staff misclick on
	// a cash payment doesn't print a misleading debt receipt.
	var status, methodType string
	var amount int
	var customerID string
	if err := s.db.QueryRow(
		`SELECT p.status, COALESCE(p.amount, 0), COALESCE(pm.type, ''), COALESCE(o.customer_id, '')
		   FROM payments p
		   LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
		   LEFT JOIN orders o ON o.id = p.order_id
		  WHERE p.id = ? AND p.order_id = ?`,
		body.PaymentID, body.OrderID,
	).Scan(&status, &amount, &methodType, &customerID); err != nil {
		writeError(w, http.StatusNotFound, "payment not found")
		return
	}
	if status != "succeeded" && status != "confirmed" {
		writeError(w, http.StatusConflict, "payment not confirmed")
		return
	}
	if methodType != "on_account" {
		writeError(w, http.StatusUnprocessableEntity, "payment_method_not_on_account")
		return
	}

	customerName, customerPhone, customerTax := s.customerInfo(customerID)

	rp, _ := printer.NewDispatcher(s.devices).RouteReceipt()
	if rp == nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{
			"status": "no_printer",
			"detail": "no receipt_printer configured",
		})
		return
	}

	config := service.PrintJobConfig{
		PaperWidth:    42,
		PhysicalWidth: rp.CharWidth(),
		StoreName:     s.settingValue("workstation_branch_name"),
		StoreSubName:  s.settingValue("workstation_brand_name"),
		TaxRate:       s.shopTaxRate(),
		Currency:      s.printCurrencySymbol(),
	}

	// Append print_history BEFORE printing so the reprint number reflects
	// any prior attempts even when the printer connection fails.
	reprintNo := 1
	if s.orders != nil {
		entry, err := s.orders.AppendPrintHistory(body.PaymentID, reason)
		if err == nil {
			reprintNo = entry.ReprintNo
		} else {
			slog.Warn("AppendPrintHistory failed (non-fatal)", "payment_id", body.PaymentID, "err", err)
		}
	}

	slip := service.FormatDebtSlip(o, o.Items, config, service.DebtSlipInfo{
		CustomerName:    customerName,
		CustomerPhone:   customerPhone,
		CustomerTaxCode: customerTax,
		DebtAmount:      amount,
		ReprintNumber:   reprintNo,
	})

	if err := rp.Connect(); err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rp.Disconnect()
	if err := rp.Print(slip); err != nil {
		writeServerError(w, r, err)
		return
	}

	s.auditLogPOS(r, "payment.debt_slip_printed", "payment", body.PaymentID, auditDetails(map[string]any{
		"payment_id": body.PaymentID,
		"reprint_no": reprintNo,
		"reason":     reason,
	}))

	writeJSON(w, http.StatusOK, map[string]any{
		"status":        "ok",
		"slips_printed": 1,
		"reprint_no":    reprintNo,
	})
}

// POST /api/lan/print/vat-invoice   (auth: Bearer, LAN)
//
// Prints a "HOA DON GIA TRI GIA TANG" thermal slip from the local mirror.
// Plan-038 T11.4. NOT an e-invoice — slip footer carries a disclaimer.
//
// Body: { invoice_id: "uuid", reprint_reason?: "string" }
func (s *Server) handleLANPrintVatInvoice(w http.ResponseWriter, r *http.Request) {
	if _, ok := DeviceFromContext(r.Context()); !ok {
		writeError(w, http.StatusUnauthorized, "not authenticated")
		return
	}
	var body struct {
		InvoiceID     string `json:"invoice_id"`
		ReprintReason string `json:"reprint_reason"`
	}
	if err := readJSON(r, &body); err != nil || body.InvoiceID == "" {
		writeError(w, http.StatusBadRequest, "invoice_id required")
		return
	}
	if len(body.ReprintReason) > 256 {
		writeError(w, http.StatusBadRequest, "reprint_reason too long")
		return
	}
	reason := body.ReprintReason
	if reason == "" {
		reason = "auto"
	}

	info, status, err := s.loadInvoiceForPrint(body.InvoiceID)
	if err != nil {
		writeError(w, http.StatusNotFound, "invoice not found")
		return
	}
	if status == "voided" {
		writeError(w, http.StatusGone, "invoice voided")
		return
	}

	rp, _ := printer.NewDispatcher(s.devices).RouteReceipt()
	if rp == nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{
			"status": "no_printer",
			"detail": "no receipt_printer configured",
		})
		return
	}

	// Reprint counter — read prior print history from the local mirror's
	// notes JSON (we don't have a payments.metadata equivalent here, so
	// the counter is best-effort and lives on a side column we update via
	// the same path below).
	reprintNo := s.bumpInvoiceReprint(body.InvoiceID, reason)
	info.ReprintNumber = reprintNo
	// plan-043 T4.4 — locale drives the per-rate block labels (8%対象 / 内消費税 /
	// 登録番号). The reg number is carried on the invoice snapshot itself (from the
	// mirror), not the shop setting, so a reprint shows what was frozen at issue.
	info.Locale = s.printLabelLocale()

	config := service.PrintJobConfig{
		PaperWidth:    42,
		PhysicalWidth: rp.CharWidth(),
		StoreName:     s.settingValue("workstation_branch_name"),
		StoreSubName:  s.settingValue("workstation_brand_name"),
		TaxRate:       s.shopTaxRate(),
		Currency:      s.printCurrencySymbol(),
		CurrencyCode:  s.shopSetting("currency_code", "JPY"),
		Locale:        s.printLabelLocale(),
	}
	slip := service.FormatVatInvoice(info, config)

	if err := rp.Connect(); err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rp.Disconnect()
	if err := rp.Print(slip); err != nil {
		writeServerError(w, r, err)
		return
	}

	s.auditLogPOS(r, "invoice.printed", "invoice", body.InvoiceID, auditDetails(map[string]any{
		"invoice_id": body.InvoiceID,
		"reprint_no": reprintNo,
		"reason":     reason,
	}))

	writeJSON(w, http.StatusOK, map[string]any{
		"status":        "ok",
		"slips_printed": 1,
		"reprint_no":    reprintNo,
		"invoice_no":    info.InvoiceNo,
	})
}

// loadInvoiceForPrint reads a row from the local customer_invoices mirror
// and decodes items_json into VatInvoiceLine. Returns the canonical status
// so the caller can shortcut voided invoices.
func (s *Server) loadInvoiceForPrint(invoiceID string) (service.VatInvoiceInfo, string, error) {
	var (
		invoiceNo, taxCode, companyName, billing string
		issuedAtStr, status, currency            string
		subtotal, taxAmount, total, taxRate      float64
		itemsJSON                                string
		// plan-043 T4.4 — per-rate breakdown JSON + T+13 registration number.
		// COALESCE keeps old rows (pre-migration 039) reading a safe empty
		// breakdown; the reg number stays "" (→ no reg-no line printed).
		taxBreakdownJSON string
		sellerRegNo      string
	)
	if err := s.db.QueryRow(
		`SELECT invoice_no, COALESCE(tax_code, ''), COALESCE(company_name, ''),
		        COALESCE(billing_address, ''), issued_at, status,
		        COALESCE(currency_code, 'VND'),
		        subtotal, tax_amount, total, tax_rate,
		        COALESCE(items_json, '[]'),
		        COALESCE(tax_breakdown, '[]'),
		        COALESCE(seller_registration_number, '')
		   FROM customer_invoices WHERE id = ?`,
		invoiceID,
	).Scan(
		&invoiceNo, &taxCode, &companyName, &billing,
		&issuedAtStr, &status, &currency,
		&subtotal, &taxAmount, &total, &taxRate, &itemsJSON,
		&taxBreakdownJSON, &sellerRegNo,
	); err != nil {
		return service.VatInvoiceInfo{}, "", err
	}

	issuedAt, _ := time.Parse(time.RFC3339, issuedAtStr)
	currencyPrefix := "¥"
	switch strings.ToUpper(currency) {
	case "VND":
		currencyPrefix = "đ"
	case "USD":
		currencyPrefix = "$"
	case "EUR":
		currencyPrefix = "€"
	}

	var rawItems []map[string]any
	_ = jsonUnmarshalSafe([]byte(itemsJSON), &rawItems)
	items := make([]service.VatInvoiceLine, 0, len(rawItems))
	for _, m := range rawItems {
		items = append(items, service.VatInvoiceLine{
			Name:      asString(m["name"]),
			Quantity:  asInt(m["qty"]),
			UnitPrice: asInt(m["unit_price"]),
			LineTotal: asInt(m["line_total"]),
		})
	}

	// plan-043 T4.4 — decode the per-rate breakdown [{rate, taxable, tax}].
	// A malformed / empty array yields no blocks → the formatter falls back to
	// the single "Thue X%" line.
	var rawBreakdown []map[string]any
	_ = jsonUnmarshalSafe([]byte(taxBreakdownJSON), &rawBreakdown)
	breakdown := make([]service.VatInvoiceTaxLine, 0, len(rawBreakdown))
	for _, m := range rawBreakdown {
		breakdown = append(breakdown, service.VatInvoiceTaxLine{
			Rate:    asFloat(m["rate"]),
			Taxable: asInt(m["taxable"]),
			Tax:     asInt(m["tax"]),
		})
	}

	info := service.VatInvoiceInfo{
		InvoiceNo:                invoiceNo,
		IssuedAt:                 issuedAt,
		TaxCode:                  taxCode,
		CompanyName:              companyName,
		BillingAddress:           billing,
		Subtotal:                 int(subtotal),
		TaxAmount:                int(taxAmount),
		Total:                    int(total),
		TaxRatePercent:           int(taxRate),
		CurrencyPrefix:           currencyPrefix,
		Items:                    items,
		Status:                   status,
		TaxBreakdown:             breakdown,
		SellerRegistrationNumber: sellerRegNo,
	}
	return info, status, nil
}

// bumpInvoiceReprint increments the per-invoice reprint counter we keep
// on the mirror row. Lossy across workstation restarts (settings table
// would be a safer home) but adequate for the v1 UX — when the counter
// rolls back to 1 the slip simply prints without the "Bản in #N" header
// and the cashier can re-issue.
func (s *Server) bumpInvoiceReprint(invoiceID, _reason string) int {
	if s.db == nil {
		return 1
	}
	var noteCount int
	_ = s.db.QueryRow(
		`SELECT COALESCE(CAST(notes AS INTEGER), 0) FROM customer_invoices WHERE id = ?`,
		invoiceID,
	).Scan(&noteCount)
	next := noteCount + 1
	_, _ = s.db.Exec(
		`UPDATE customer_invoices SET notes = ?, updated_at = ? WHERE id = ?`,
		itoaInt(next), time.Now().UTC().Format(time.RFC3339), invoiceID,
	)
	return next
}

// itoaInt avoids importing strconv in this file.
func itoaInt(n int) string {
	if n == 0 {
		return "0"
	}
	digits := []byte{}
	for n > 0 {
		digits = append([]byte{byte('0' + n%10)}, digits...)
		n /= 10
	}
	return string(digits)
}

func asString(v any) string {
	if s, ok := v.(string); ok {
		return s
	}
	return ""
}

func asInt(v any) int {
	switch x := v.(type) {
	case float64:
		return int(x)
	case int:
		return x
	case string:
		// Cloud emits stringy decimals like "100000.00". Parse leading digits.
		n := 0
		neg := false
		for i, r := range x {
			if i == 0 && r == '-' {
				neg = true
				continue
			}
			if r < '0' || r > '9' {
				break
			}
			n = n*10 + int(r-'0')
		}
		if neg {
			return -n
		}
		return n
	}
	return 0
}

// asFloat coerces a decoded JSON value (float64, int, or a stringy decimal
// like "8.00") to a float64 — used for the per-rate breakdown's rate field.
func asFloat(v any) float64 {
	switch x := v.(type) {
	case float64:
		return x
	case int:
		return float64(x)
	case string:
		f, _ := strconv.ParseFloat(strings.TrimSpace(x), 64)
		return f
	}
	return 0
}

func jsonUnmarshalSafe(data []byte, dst any) error {
	return json.Unmarshal(data, dst)
}

// customerInfo resolves the customer display fields for a debt slip. All
// returned values are best-effort; missing customer rows return empties.
func (s *Server) customerInfo(customerID string) (name, phone, tax string) {
	if customerID == "" || s.db == nil {
		return "", "", ""
	}
	_ = s.db.QueryRow(
		`SELECT COALESCE(first_name, '') || COALESCE(' ' || last_name, ''),
		        COALESCE(phone, ''),
		        COALESCE(tax_code, '')
		   FROM customers
		  WHERE id = ?`,
		customerID,
	).Scan(&name, &phone, &tax)
	return name, phone, tax
}

// GET /api/lan/print/status[?order_id=...]   (auth: Bearer, LAN)
//
// Probe used by pos-web's order cart to render the printer-status pill
// ("Bếp: ● online · Bar: ✗ offline") and to drive the "Gửi bếp" button
// badge count. Returns:
//
//	{
//	  "printer_roles": { "kitchen_printer": {configured, online, last_error?},
//	                     "bar_printer":     {...},
//	                     "hold_printer":    {...},
//	                     "receipt_printer": {...} },
//	  "sync": { "last_pulled_at": "...", "cursor_age_s": N },
//	  "order": { id, in_local, open_items_pending_print }   // when order_id set
//	}
func (s *Server) handleLANPrintStatus(w http.ResponseWriter, r *http.Request) {
	if _, ok := DeviceFromContext(r.Context()); !ok {
		writeError(w, http.StatusUnauthorized, "not authenticated")
		return
	}

	roles := map[string]map[string]any{
		"kitchen_printer": printerRoleStatus(s, printer.TypeKitchenPrinter),
		"bar_printer":     printerRoleStatus(s, printer.TypeBarPrinter),
		"hold_printer":    printerRoleStatus(s, printer.TypeHoldPrinter),
		"receipt_printer": printerRoleStatus(s, printer.TypeReceiptPrinter),
	}

	resp := map[string]any{
		"printer_roles": roles,
		"sync":          syncStatusSummary(s),
	}

	if orderID := r.URL.Query().Get("order_id"); orderID != "" {
		o, err := s.orders.GetByID(orderID)
		ord := map[string]any{
			"id":       orderID,
			"in_local": err == nil && o != nil,
		}
		if o != nil {
			// Count lines with an unprinted delta (new lines AND quantity
			// bumps on already-fired lines) so the pos-web badge lights up
			// the moment staff adds units to an existing item.
			pending := 0
			for _, it := range o.Items {
				if needsFire(it) {
					pending++
				}
			}
			ord["open_items_pending_print"] = pending
		}
		resp["order"] = ord
	}

	writeJSON(w, http.StatusOK, resp)
}

func printerRoleStatus(s *Server, role printer.DeviceType) map[string]any {
	if s.devices == nil {
		return map[string]any{"configured": false}
	}
	p := s.devices.GetPrinterByRole(role)
	if p == nil {
		return map[string]any{"configured": false}
	}
	st := map[string]any{
		"configured": true,
		// We don't actively probe TCP/USB here — Manager-tracked status
		// reflects the last observed state. A `last_error` is surfaced
		// when available so pos-web can show a hint.
		"online": p.Status() != printer.StatusOffline && p.Status() != printer.StatusError,
	}
	if p.Status() == printer.StatusError {
		st["last_error"] = "device reported error"
	}
	return st
}

func syncStatusSummary(s *Server) map[string]any {
	out := map[string]any{}
	var lastPulled string
	_ = s.db.QueryRow(
		`SELECT COALESCE(value,'') FROM settings WHERE key = 'sync.customer_orders.last_pulled'`,
	).Scan(&lastPulled)
	out["last_pulled_at"] = lastPulled
	if lastPulled != "" {
		if t, err := time.Parse(time.RFC3339, lastPulled); err == nil {
			out["cursor_age_s"] = int(time.Since(t).Seconds())
		}
	}
	return out
}

// isContextTimeout detects a wrapped context.DeadlineExceeded that error
// wrapping might bury inside fmt.Errorf chains.
func isContextTimeout(err error) bool {
	if err == nil {
		return false
	}
	// Check for direct timeout signal.
	type timeoutInterface interface {
		Timeout() bool
	}
	var t timeoutInterface
	if errors.As(err, &t) && t.Timeout() {
		return true
	}
	// fmt.Errorf("force-pull %s: %w", ...) chains the inner err — match the
	// well-known string from SyncPuller.PullOrderNow timeout path.
	return contains(err.Error(), "context deadline exceeded")
}

func contains(s, sub string) bool {
	return len(s) >= len(sub) && (s == sub || indexOf(s, sub) >= 0)
}

func indexOf(s, sub string) int {
	for i := 0; i+len(sub) <= len(s); i++ {
		if s[i:i+len(sub)] == sub {
			return i
		}
	}
	return -1
}

// jsonOK is a small helper so callers can keep payloads in literal form
// without writing the wrap each time.
//
//nolint:unused // shared utility for future T2.x handlers
func jsonOK(v map[string]any) []byte {
	b, _ := json.Marshal(v)
	return b
}

// Compile-time check: lan_print.go uses fmt only for handler-not-found
// fallback messages — keeping the import for symmetry with other handler
// files.
var _ = fmt.Sprintf
