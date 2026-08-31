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
	"github.com/dxs-platform/workstation-app/internal/printjob"
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

	// `ensureOrderLocal`, not a pull-on-miss: this sheet travels WITH THE FOOD
	// and now carries the takeaway payment word, so a locally-present but STALE
	// order prints a statement about money that is already false.
	//
	// The window is real and ordinary — customer scans the QR and pays online
	// while the workstation still holds the pre-payment copy — and the pull-on-
	// miss shape cannot see it, because the order IS present. #3040 closed
	// exactly this half for the receipt; the kitchen sheet kept the old shape,
	// and it did not matter until this sheet started naming payment.
	//
	// Fail-open on a Cloud outage is inherited from the helper, and is the point:
	// the kitchen must never stop getting tickets because Cloud is unreachable.
	if !s.ensureOrderLocal(w, r, body.OrderID) {
		return // response already written
	}
	o, err := s.orders.GetByID(body.OrderID)
	if err != nil || o == nil {
		writeError(w, http.StatusNotFound, "order not found")
		return
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

// POST /api/lan/print/kitchen-reprint   (auth: Bearer, LAN)
//
// Re-prints an order's kitchen ticket(s) without firing anything — "in lại
// phiếu bếp" from the pos-web order-history screen.
//
// This is a SEPARATE route from /kitchen-ticket rather than a flag on it,
// because the two do opposite things. /kitchen-ticket is dispatch: it sends the
// unprinted delta, closes it, and broadcasts `order.kitchen_printed` so every
// KDS re-fetches. This one only pushes paper — see reprintKitchenForOrder for
// why re-firing a finished order is how a shop cooks a plate twice.
//
// It follows from that opposition that this route needs NO 422 "no unprinted
// items" gate: on a closed order the delta is 0 by definition, which is exactly
// the state a cashier reprints from.
//
// Body: { order_id: "uuid" }
//
// Response shapes:
//
//	200 { status:"ok",         printed:N, groups:[{printer_group, ticket_no, items}] }
//	200 { status:"partial",    printed:N, groups:[...], errors:[{printer_group, reason, detail?}] }
//	400 { message:"order_id required" }
//	401 { message:"not authenticated" }
//	404 { message:"order not found" } — local missing AND force-pull 404
//	422 { message:"no items to print" } — every line voided, or an empty order
//	503 { status:"no_printer", errors:[...] } — nothing came out anywhere. Unlike
//	     the fire path a printer-less shop IS a failure here: there is no KDS
//	     fallback to make a paperless reprint mean anything.
//	504 { message:"force-pull timed out", retry_after_ms:1500 }
//
// Side effects: print-ledger rows only. No print_status write, no KDS
// broadcast, no order.fire audit — one `order.kitchen_reprint` row instead, so
// the trail says which of the two things happened.
func (s *Server) handleLANPrintKitchenReprint(w http.ResponseWriter, r *http.Request) {
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

	// Same Cloud-settles-then-workstation-prints race every other print path
	// handles: a history reprint is the LEAST likely to find the order already
	// local, since it can be days old and long since pruned from the hot set.
	if !s.ensureOrderLocal(w, r, body.OrderID) {
		return // response already written
	}
	o, err := s.orders.GetByID(body.OrderID)
	if err != nil || o == nil {
		writeError(w, http.StatusNotFound, "order not found")
		return
	}

	s.localizeOrderForPrint(o, s.printLabelLocale())
	groups, reprintErrors := s.reprintKitchenForOrder(o, s.printLabelLocale())

	s.auditLogPOS(r, "order.kitchen_reprint", "order", body.OrderID, auditDetails(map[string]any{
		"source": "pos-web",
		"groups": len(groups),
		"errors": len(reprintErrors),
	}))

	if len(groups) == 0 && len(reprintErrors) == 0 {
		writeError(w, http.StatusUnprocessableEntity, "no items to print")
		return
	}
	if len(groups) == 0 {
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{
			"status": "no_printer",
			"errors": reprintErrors,
		})
		return
	}
	if len(reprintErrors) > 0 {
		writeJSON(w, http.StatusOK, map[string]any{
			"status":  "partial",
			"printed": len(groups),
			"groups":  groups,
			"errors":  reprintErrors,
		})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"status":  "ok",
		"printed": len(groups),
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

	// `ensureOrderLocal`, mirroring the kitchen ticket — and for the same reason.
	// This is the hall/runner sheet, so it carries the takeaway payment word too,
	// and a locally-present but STALE order makes it announce CHUA TRA about
	// money Cloud already took.
	if !s.ensureOrderLocal(w, r, body.OrderID) {
		return // response already written
	}
	o, err := s.orders.GetByID(body.OrderID)
	if err != nil || o == nil {
		writeError(w, http.StatusNotFound, "order not found")
		return
	}

	// Item/topping/variant names on the bill follow the print locale.
	s.localizeOrderForPrint(o, s.printLabelLocale())

	// #2170 — the runner bill prints the whole-order tax table, so it reads
	// the `order_conditions` ledger like the receipt does (this path skips
	// normalizeOrderForPrint, hence the explicit load). Empty ledger → the
	// formatter recomputes, as before.
	o.TaxLines = s.orders.OrderTaxLines(o.ID)

	// Bill goes to the receipt printer; fall back to the hold, then kitchen
	// printer so a single-printer shop still gets the slip.
	rp := s.devices.GetPrinterByRole(printer.TypeReceiptPrinter)
	if rp == nil {
		rp = s.devices.GetPrinterByRole(printer.TypeHallPrinter)
	}
	if rp == nil {
		rp = s.devices.GetPrinterByRole(printer.TypeKitchenPrinter)
	}
	if rp == nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{"status": "no_printer", "detail": "no printer configured"})
		return
	}

	// #3044 — phiếu order là chứng từ khách cầm, nên nó chịu đúng luật của biên
	// lai: dòng đã huỷ BIẾN MẤT. Bản vá đầu của #3044 lọc ở `paidSlipInputs`,
	// chỗ dựng danh sách cho biên lai và hoá đơn đỏ — đường này không đi qua đó
	// (nó cũng bỏ qua `normalizeOrderForPrint`, xem chú thích #2170 ngay trên),
	// nên nó vẫn in dòng huỷ sau bản vá ấy. Đúng phạm vi issue đã liệt kê.
	billItems := nonVoidedItems(o.Items)

	config := service.PrintJobConfig{
		PaperWidth:        42,
		Locale:            s.printLabelLocale(), // shop-wide print language → bill labels
		PhysicalWidth:     rp.CharWidth(),
		StoreName:         s.storeName(),
		StoreSubName:      s.settingValue("workstation_brand_name"),
		StoreAddress:      s.settingValue("workstation_branch_address"),
		StorePhone:        s.settingValue("workstation_branch_phone"),
		StoreOrganization: s.settingValue("workstation_organization_name"),
		TaxRate:           s.shopTaxRate(),
		Currency:          s.printCurrencySymbol(),
	}

	// ticketNo is unused by the bill formatter (formatBillTicket ignores it).
	// plan-053 T3.6 tầng 2 (#1914) — call site 5/13.
	slip, templateVersion := s.renderMoneySlip(
		service.NewRunnerRenderData(o, billItems, 0, config),
		service.PrintRenderProfileFor(rp.Profile(), ""),
		config.Locale,
		func() []byte { return service.FormatRunnerTicket(o, billItems, 0, config) },
	)

	printErr := rp.Connect()
	if printErr == nil {
		defer rp.Disconnect()
		printErr = rp.Print(slip)
	}

	s.journalPrintFor(r, rp, printjob.Entry{
		Kind:            printjob.KindReport,
		OrderID:         body.OrderID,
		RequestedVia:    "pos",
		TemplateVersion: templateVersion,
		Payload:         map[string]any{"template": "bill"},
	}, printErr)

	if printErr != nil {
		writeServerError(w, r, printErr)
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
	// #2593 — "chưa tick role" là lỗi CẤU HÌNH, không phải lỗi máy. Không tách
	// ra thì nó rơi vào `printer_error` mặc định, và kiosk bảo nhân viên đi
	// kiểm máy in trong khi cách sửa nằm ở màn Settings. Dùng đúng từ vựng mà
	// endpoint LAN đã trả (`no_printer`) thay vì đẻ thêm cái thứ ba.
	if errors.Is(err, errNoReceiptPrinter) {
		return "no_printer"
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
		PaperWidth:        42,
		PhysicalWidth:     rp.CharWidth(),
		StoreName:         s.storeName(),
		StoreSubName:      s.settingValue("workstation_brand_name"),
		StoreAddress:      s.settingValue("workstation_branch_address"),
		StorePhone:        s.settingValue("workstation_branch_phone"),
		StoreOrganization: s.settingValue("workstation_organization_name"),
		TaxRate:           s.shopTaxRate(),
		Currency:          s.printCurrencySymbol(),
	}

	// Reserve the copy number BEFORE printing so it reflects any prior attempts
	// even when the printer connection fails.
	//
	// #1875 — the debt slip counts on its OWN kind now. It used to share one
	// counter with the receipt and the red invoice, so a customer who had been
	// handed a receipt received their first PHIEU GHI NO already stamped as a
	// copy.
	scope := s.resolvePrintScope(o.ID, body.PaymentID)
	ledger := printjob.Entry{
		Kind:         printjob.KindDebtSlip,
		OrderID:      o.ID,
		PaymentID:    scope.PaymentID,
		RequestedVia: "pos",
		Payload:      map[string]any{"template": "debt_slip"},
	}
	res := s.beginMoneyPrint(r, ledger, scope)
	reprintNo := res.ReprintNo

	// call site 6/13.
	debtInfo := service.DebtSlipInfo{
		CustomerName:    customerName,
		CustomerPhone:   customerPhone,
		CustomerTaxCode: customerTax,
		DebtAmount:      amount,
		ReprintNumber:   reprintNo,
	}

	slip, templateVersion := s.renderMoneySlip(
		service.NewDebtSlipRenderData(o, o.Items, config, debtInfo),
		service.PrintRenderProfileFor(rp.Profile(), ""),
		config.Locale,
		func() []byte { return service.FormatDebtSlip(o, o.Items, config, debtInfo) },
	)
	// TR-28 — the reservation above minted the copy number BEFORE the slip
	// existed, so this is the first moment the layout version is knowable.
	// `finishMoneyPrint` carries it onto the row the reservation is holding.
	ledger.TemplateVersion = templateVersion

	printErr := rp.Connect()
	if printErr == nil {
		defer rp.Disconnect()
		printErr = rp.Print(slip)
	}

	// plan-052 T1.2 — the ledger records the attempt either way. `reason` carries
	// the operator's reprint justification (P-10); the row already holds the N
	// that went on the paper, because the reservation minted both (P-12).
	s.finishMoneyPrint(res, rp, ledger, reason, printErr)

	if printErr != nil {
		writeServerError(w, r, printErr)
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
//	                     "hall_printer":    {...},   // + "hold_printer" legacy alias
//	                     "receipt_printer": {...} },
//	  "sync": { "last_pulled_at": "...", "cursor_age_s": N },
//	  "order": { id, in_local, open_items_pending_print }   // when order_id set
//	}
func (s *Server) handleLANPrintStatus(w http.ResponseWriter, r *http.Request) {
	if _, ok := DeviceFromContext(r.Context()); !ok {
		writeError(w, http.StatusUnauthorized, "not authenticated")
		return
	}

	hall := printerRoleStatus(s, printer.TypeHallPrinter)
	roles := map[string]map[string]any{
		"kitchen_printer": printerRoleStatus(s, printer.TypeKitchenPrinter),
		"bar_printer":     printerRoleStatus(s, printer.TypeBarPrinter),
		"hall_printer":    hall,
		"receipt_printer": printerRoleStatus(s, printer.TypeReceiptPrinter),
		// Legacy alias: a pos-web deployed before the rename still reads
		// `hold_printer`, and workstations update independently of it. Drop once
		// the fleet is past that build.
		"hold_printer": hall,
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

			// Mode A ("thanh toán trước khi chuẩn bị món"): the shop declared the
			// kitchen must not start until the money is in, and the auto-print
			// path has always honoured it — a takeaway order prints NOTHING on
			// arrival and fires on the payment event instead.
			//
			// The manual "Gửi bếp" button was the door left open, and firing
			// through it is what stamps CHUA TRA on the sheet that travels WITH
			// THE FOOD: the early fire closes the delta, so the settle-time fire
			// finds nothing to print and no corrected sheet is ever produced.
			//
			// Reported as a FACT, never enforced here. The endpoint that prints
			// must keep printing whatever it is asked to: a workstation that
			// refuses is a kitchen that silently stops cooking, which is a worse
			// failure than a wrong word on paper — measured, on a live shop. The
			// client defers; nothing blocks.
			//
			// Takeaway only, and that is load-bearing: `prep_before_payment` is a
			// shop-wide row whose meaning is takeaway-only (see auto_print.go).
			// A dine-in table pays after eating, so reading the flag without the
			// order type would grey out the fire button on every dine-in order.
			//
			// Absent when it does not apply, so the client's "field missing ⇒ I
			// cannot tell ⇒ do nothing" rule — the same one red_invoice and
			// untargeted_scope rely on — keeps an older or unsure workstation
			// from disabling anything.
			if o.OrderType == "takeaway" && s.prepBeforePayment() && !service.OrderIsSettled(o) {
				ord["awaiting_prepayment"] = true
			}
		}
		resp["order"] = ord

		// #1875 — "has this order had a red invoice printed, and for which
		// payer?" There is no stored flag anywhere and deliberately so: on a
		// split bill one boolean on the order cannot say WHICH guest already has
		// paper, and a second copy of that truth would drift from the ledger —
		// drift that shows up as the POS telling a cashier "not printed yet"
		// about a sheet the customer is holding.
		//
		// Served from local SQLite, so it stays right with the internet down.
		for _, kind := range []printjob.Kind{
			printjob.KindRedInvoice, printjob.KindReceipt, printjob.KindDebtSlip,
		} {
			if block := s.printCountsBlock(kind, s.linkedOrderIDs(orderID)); block != nil {
				resp[string(kind)] = block
			}
		}

		// #2535 A7 — WHICH scope an UNTARGETED print lands on.
		//
		// The client needs this to read the right tally, and it cannot work it
		// out: `resolvePrintScope` branch ② puts a one-payer order's untargeted
		// print on THE PAYMENT, not on `order_scope`. A client that assumed
		// `order_scope` reads a permanent 0 there, so "In gốc" stays lit forever
		// and "In lại" never lights up — on exactly the ordinary single-payment
		// order, which is most of them.
		//
		// Reported by the server rather than mirrored in the client: the rule
		// has three branches and one of them reads payment metadata the client
		// never sees. A second copy of it would drift, and the drift would show
		// up as a button lying about what is on paper.
		scope := s.resolvePrintScope(orderID, "")
		untargeted := map[string]any{"payment_id": nil}
		if scope.PaymentID != "" {
			untargeted["payment_id"] = scope.PaymentID
		}
		resp["untargeted_scope"] = untargeted
	}

	writeJSON(w, http.StatusOK, resp)
}

// printCountsBlock renders one kind's per-scope tally for the print-status
// response. Returns nil when there is no journal to ask, so an older client
// simply sees the field absent rather than a block claiming zero prints.
func (s *Server) printCountsBlock(kind printjob.Kind, orderIDs []string) map[string]any {
	if s.printJournal == nil {
		return nil
	}
	orderScope, byPayment, err := s.printJournal.CountsForOrder(kind, orderIDs)
	if err != nil {
		slog.Warn("print counts failed (non-fatal)", "kind", kind, "err", err)
		return nil
	}

	payments := make([]map[string]any, 0, len(byPayment))
	for _, sc := range byPayment {
		payments = append(payments, map[string]any{
			"payment_id":      sc.PaymentID,
			"count":           sc.Count,
			"last_printed_at": sc.LastPrintedAt,
			"last_status":     string(sc.LastStatus),
		})
	}

	return map[string]any{
		// `printed` is the order-level answer the CTA badge needs; `by_payment`
		// is the per-guest one the split-bill dialog needs. Both, because a
		// split order can have paper for guest #2 and none for the rest.
		"printed": orderScope.Count > 0 || len(payments) > 0,
		"order_scope": map[string]any{
			"count":           orderScope.Count,
			"last_printed_at": orderScope.LastPrintedAt,
			"last_status":     string(orderScope.LastStatus),
		},
		"by_payment": payments,
	}
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
