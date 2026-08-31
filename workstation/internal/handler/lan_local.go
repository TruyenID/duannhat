package handler

import (
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/config"
	"github.com/dxs-platform/workstation-app/internal/printjob"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// ensureOrderLocal materialises an order the workstation does not hold yet, by
// force-pulling it from Cloud.
//
// pos-web creates and settles orders against Cloud, then asks the workstation —
// which owns the printers — to print them. Between those two facts sits the 5 s
// pull, so "print this order" routinely arrives before the order does. The
// kitchen-ticket and order-bill paths have closed that race since plan-038; the
// receipt paths never did and simply failed with "no rows in result set". That
// is how a shop ends up unable to print anything while every component reports
// healthy.
//
// Returns false after writing the error response. The shapes match the
// kitchen-ticket handler on purpose so pos-web keeps one retry policy: 404
// unknown order, 504 too slow, 503 Cloud down.
func (s *Server) ensureOrderLocal(w http.ResponseWriter, r *http.Request, orderID string) bool {
	// No order engine means the server isn't fully wired (headless boot, tests).
	// That is "nothing to print", not "no such order" — the print helpers below
	// already no-op in this state, and turning it into a 404 would invent a
	// failure the caller cannot act on.
	if s.orders == nil {
		return true
	}
	if o, err := s.orders.GetByID(orderID); err == nil && o != nil {
		// #3040 — có bản cục bộ CHƯA phải là có bản ĐÚNG.
		//
		// Vế `o != nil` chỉ đóng được nửa cuộc đua mà docblock trên mô tả: nửa
		// "đơn chưa về". Nửa còn lại là **đơn đã về từ lâu rồi Cloud thu tiền
		// sau đó** — khách đang ngồi ăn, quét QR trả online, Cloud ghi nhận,
		// còn máy trạm vẫn giữ bản `open` cho tới nhịp pull kế. Trong cửa sổ
		// đó, in biên lai sẽ dựng tờ giấy từ một đơn mà máy trạm tin là CHƯA
		// TRẢ.
		//
		// `closed` là trạng thái kết thúc và Cloud không lật ngược nó
		// (`sync_pull.go`: trạng thái kết thúc của Cloud luôn thắng), nên đơn
		// đã `closed` thì không cần hỏi lại — đó cũng là đường phổ biến nhất,
		// và giữ nó khỏi mọi lượt gọi Cloud là chủ đích.
		if o.Status == service.StatusClosed {
			return true
		}

		// Fail-OPEN, khác hẳn nhánh "đơn vắng mặt" ngay dưới. Vắng mặt nghĩa là
		// KHÔNG CÓ GÌ để in nên phải báo lỗi; còn ở đây ta đã có một bản dùng
		// được — Cloud hỏng không được biến một lượt in thành 503. Cùng tinh
		// thần "warn, never block" của plan-052 §4.
		if s.puller != nil {
			if err := s.puller.PullOrderNow(r.Context(), orderID); err != nil {
				slog.Warn("refresh before print failed (non-fatal)",
					"order", orderID, "status", string(o.Status))
			}
		}

		return true
	}
	if s.puller == nil {
		writeError(w, http.StatusNotFound, "order not found")
		return false
	}

	pullErr := s.puller.PullOrderNow(r.Context(), orderID)
	if errors.Is(pullErr, service.ErrOrderNotFoundOnCloud) {
		writeError(w, http.StatusNotFound, "order not found")
		return false
	}
	if pullErr != nil {
		if errors.Is(pullErr, r.Context().Err()) || isContextTimeout(pullErr) {
			writeJSON(w, http.StatusGatewayTimeout, map[string]any{
				"message":        "force-pull timed out",
				"retry_after_ms": 1500,
			})
			return false
		}
		slog.Warn("receipt force-pull failed", "order_id", orderID, "err", pullErr)
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{
			"message":        "cloud unavailable",
			"retry_after_ms": 3000,
		})
		return false
	}

	if o, err := s.orders.GetByID(orderID); err != nil || o == nil {
		writeError(w, http.StatusNotFound, "order not found")
		return false
	}
	return true
}

// autoPrintReceiptClaimed reports whether the auto-print path has already taken
// responsibility for this order's receipt.
//
// Materialising an order legitimately fires the auto-print hooks — that IS the
// delivery path for a payment settled in Cloud, and suppressing it loses the
// receipt outright, because those hooks fire exactly once (first insert, and
// the single open→closed transition). So the duplicate is resolved on the
// receipt handler's side instead: it asks whether the pull it just triggered
// already put this very sheet on paper.
//
// Only the receipt path needs this. Kitchen-ticket and order-bill emit a
// DIFFERENT document, so an auto-printed receipt alongside them is correct, not
// a duplicate — which is why this is not a flag on the pull.
func (s *Server) autoPrintReceiptClaimed(orderID string) bool {
	if s.idempotency == nil {
		return false
	}
	_, found, _ := s.idempotency.Get("autoprint:receipt:"+orderID, autoPrintDeviceID)
	return found
}

// cloudPaymentEntry finds ONE payment in this order's Cloud mirror, scoped to
// the order family so a payment belonging to a different order can never be
// matched by id alone.
func (s *Server) cloudPaymentEntry(orderID, paymentID string) (service.CloudPaymentSummaryEntry, bool) {
	if paymentID == "" {
		return service.CloudPaymentSummaryEntry{}, false
	}
	for _, e := range s.cloudPaymentSummaryEntries(orderID) {
		if e.ID == paymentID {
			return e, true
		}
	}
	return service.CloudPaymentSummaryEntry{}, false
}

// settledCloudAmount totals the payments Cloud considers settled on this order.
//
// Returns 0 when nothing is settled — including for an order nobody has paid.
// That 0 is load-bearing: it keeps an unpaid order printing exactly what it
// printed before rather than announcing its own total as money received.
func (s *Server) settledCloudAmount(orderID string) int {
	total := 0
	for _, e := range s.cloudPaymentSummaryEntries(orderID) {
		for _, part := range service.PaymentReportParts(e) {
			total += part.Amount
		}
	}
	return total
}

// cloudPaymentSummaryEntries decodes the mirror of Cloud's payment_summary that
// the order upsert stores on the order header.
//
// A Cloud-settled payment deliberately never becomes a local `payments` row —
// that table feeds the Z-report and the till reconciliation panel, and an
// online payment sitting there would present itself as claimable cash in the
// drawer. This is where those payments actually live.
func (s *Server) cloudPaymentSummaryEntries(orderID string) []service.CloudPaymentSummaryEntry {
	ids := s.linkedOrderIDs(orderID)
	ph, args := inPlaceholders(ids)

	var blob string
	// ORDER BY length(...) DESC: a family can hold both the workstation row and
	// its cloud-keyed sibling, and only one of them carries the summary. Picking
	// arbitrarily was fine when this only chose a label; it chooses money now.
	err := s.db.QueryRow(`
		SELECT COALESCE(cloud_payment_summary, '')
		FROM orders
		WHERE id IN (`+ph+`) AND COALESCE(cloud_payment_summary, '') NOT IN ('', '[]')
		ORDER BY length(cloud_payment_summary) DESC
		LIMIT 1`, args...).Scan(&blob)
	if err != nil || strings.TrimSpace(blob) == "" {
		return nil
	}
	var entries []service.CloudPaymentSummaryEntry
	if json.Unmarshal([]byte(blob), &entries) != nil {
		return nil
	}
	return entries
}

// GET /api/lan/health  (no auth)
//
// Lightweight liveness probe used by:
//   - mDNS discovery clients to confirm they've reached a real workstation
//   - POS-web/kiosk connection banners
//   - On-call dashboards / manual curl checks
//
// Response intentionally minimal to keep this cheap on the hot polling path.
//
// #2633 adds `expected_version` + `update_available` so pos-web can show a
// READ-ONLY "a newer build exists — update it at the workstation" line. It is a
// hint, not a control: the update itself stays on /api/update/{status,download,
// apply}, which are localOnly on purpose (whoever clicks apply must be standing
// at the machine that is about to restart). Nothing here opens a write path.
func (s *Server) handleLANHealth(w http.ResponseWriter, r *http.Request) {
	cloudConnected := false
	if s.sync != nil {
		cloudConnected = s.sync.IsOnline()
	}
	storeName := ""
	if s.config != nil {
		storeName = s.config.Get().StoreName
	}
	expectedVersion, updateAvailable := s.lanUpdateHint()
	dbDiagnostics := s.db.Diagnostics()
	writeJSON(w, http.StatusOK, map[string]any{
		"status":           "ok",
		"readiness":        dbDiagnostics.Status,
		"database":         dbDiagnostics,
		"pos_requests":     s.posRequestLatencySnapshot(),
		"workstation_name": storeName,
		"branch_id":        s.cachedWorkstationBranchID(),
		"version":          config.Version,
		"cloud_connected":  cloudConnected,
		"server_time":      time.Now().UTC().Format(time.RFC3339),
		"expected_version": expectedVersion,
		"update_available": updateAvailable,
	})
}

// lanUpdateHint reduces the updater's full Status to the two fields LAN clients
// are allowed to see.
//
// Two things this deliberately does NOT do:
//
//   - It does not marshal update.Status itself. That struct carries StagedPath
//     (a filesystem path on the workstation), ManualDownloadURL, BlockReason and
//     ShiftOpen. /api/lan/health is wrapped in corsForBrowser and needs no auth,
//     so EVERY device on the shop LAN reads whatever it returns — not just a
//     paired POS. Map the two fields by hand; do not widen this to a spread.
//   - It does not assume s.updater exists. The planner is only constructed when
//     a configDir is available (server.go), so a nil updater is a normal
//     configuration, not a bug. Health is how POS learns the workstation is
//     alive at all — panicking here to serve an advisory line would trade the
//     load-bearing signal for the decorative one.
func (s *Server) lanUpdateHint() (expectedVersion string, updateAvailable bool) {
	if s.updater == nil {
		return "", false
	}
	st := s.updater.Status()
	return st.ExpectedVersion, st.ExpectedVersion != "" && st.ExpectedVersion != st.CurrentVersion
}

// POST /api/lan/print/payment-receipt   (auth: Bearer, LAN)
//
// Prints the "DA THANH TOAN" receipt and the "PHAN CON LAI" remainder slip
// for a payment on an order. Plan-038 T2.2 extends the legacy body with
// optional `payment_id` (target a specific split row) and `reprint_reason`
// (audit copy) — without them the handler falls back to legacy behaviour
// (most-recent confirmed payment, legacy `lastConfirmedPaymentAmount`) so
// the existing kiosk "In lại" button keeps working.
//
// Body:
//
//	{ order_id: "uuid",
//	  payment_id?: "uuid",          // when set, print THIS payment's slip
//	  reprint_reason?: "string" }    // free-form; default "auto"
//
// Side effects:
//   - payments.metadata.print_history[] appended for the target payment
//     (or the legacy last-confirmed payment when payment_id is omitted).
//   - Audit log: action='payment.receipt_printed' with
//     {payment_id, reprint_no, reason}.
func (s *Server) handleLANPrintReceipt(w http.ResponseWriter, r *http.Request) {
	if _, ok := DeviceFromContext(r.Context()); !ok {
		writeError(w, http.StatusUnauthorized, "not authenticated")
		return
	}

	var body struct {
		OrderID   string `json:"order_id"`
		PaymentID string `json:"payment_id"`
		// plan-052 §4 — the reason is ASKED FOR, never required. An empty one
		// prints exactly the same slip; the ledger simply records that none was
		// given (Cloud derives `warned_without_reason` at ingest).
		ReprintReason string `json:"reprint_reason"`
		// ActorUserID — §4 point 2, WHO. The workstation authenticates the
		// TERMINAL, not the person, so pos-web names the cashier explicitly
		// (same shape as `performed_by_id` on the till endpoints). Empty is
		// allowed and means "nobody was signed in": an honest gap in the trail
		// is better than a fabricated name, and it never stops the print.
		ActorUserID string `json:"actor_user_id"`
	}
	if err := readJSON(r, &body); err != nil || body.OrderID == "" {
		writeError(w, http.StatusBadRequest, "order_id required")
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

	// The order must be here before anything below can resolve — the payment
	// lookup and the renderer both read local SQLite. pos-web settles against
	// Cloud, so on the happy path the order is still in flight at this point.
	//
	// Snapshot the auto-print claim first: if materialising the order is what
	// fires the receipt hook, that hook prints this exact sheet, and printing
	// again below would put two identical receipts in the customer's hand.
	autoPrintedBefore := s.autoPrintReceiptClaimed(body.OrderID)
	if !s.ensureOrderLocal(w, r, body.OrderID) {
		return // response already written
	}
	if !autoPrintedBefore && s.autoPrintReceiptClaimed(body.OrderID) {
		writeJSON(w, http.StatusOK, map[string]any{
			"status":           "ok",
			"slips_printed":    1,
			"reprint_no":       1,
			"remaining_amount": "0",
			"printed_by":       "auto",
		})
		return
	}

	// Resolve which payment to project the receipt around.
	paymentID := body.PaymentID
	amount := 0
	if paymentID != "" {
		// Targeted reprint — must be a settled payment ON THIS ORDER. The gate
		// below is the only thing standing between a reprint button and paper
		// that says money was received, so it is never widened: what changes
		// here is only WHERE the amount is read from, never WHO may be printed.
		//
		// Sibling-aware like every other payment query in this file: an order
		// can exist twice locally (workstation row + cloud-keyed copy), and
		// matching on the raw id alone both missed real payments and let one
		// belonging to another order through.
		ids := s.linkedOrderIDs(body.OrderID)
		ph, args := inPlaceholders(ids)
		var status string
		err := s.db.QueryRow(
			`SELECT status, COALESCE(amount, 0) FROM payments WHERE id = ? AND order_id IN (`+ph+`)`,
			append([]any{paymentID}, args...)...,
		).Scan(&status, &amount)
		switch {
		case err == nil:
			if status != "succeeded" && status != "confirmed" {
				writeError(w, http.StatusConflict, "payment not confirmed")
				return
			}
		default:
			// A payment settled in Cloud has no local row by design, so "not in
			// `payments`" is not the same as "does not exist". Fall through to
			// the order's own Cloud mirror — still scoped to this order, and
			// still refusing anything Cloud does not call settled, so a refund
			// can never print as 「お支払い済み」.
			entry, ok := s.cloudPaymentEntry(body.OrderID, paymentID)
			if !ok {
				writeError(w, http.StatusNotFound, "payment not found")
				return
			}
			if !service.PaymentSettled(entry.Status) {
				writeError(w, http.StatusConflict, "payment not confirmed")
				return
			}
			amount = entry.Amount
		}
	} else {
		// Legacy behaviour preserved — last confirmed payment.
		amount = s.lastConfirmedPaymentAmount(body.OrderID)
		if amount == 0 {
			// …and, when the payment lives only in Cloud, the settled total from
			// the order header. Deliberately NOT the order's own total: an
			// unpaid order must keep printing what it printed before rather
			// than announcing its bill as money already received.
			amount = s.settledCloudAmount(body.OrderID)
		}
	}

	s.rememberPrintLocale(localeFromRequest(r)) // keep the auto-print fallback fresh

	// plan-052 P-10b — the number is taken BEFORE the paper moves, because the
	// slip itself has to carry 「BAN IN #N」 from copy 2 onward. It used to be
	// appended afterwards, which meant every receipt printed with the PREVIOUS
	// copy's number available and none at all on the sheet: a reprinted receipt
	// was indistinguishable from the original. Taking it first also keeps the
	// count honest when the printer connection fails — the attempt happened,
	// and the next copy is N+1 (same reasoning as the debt slip).
	//
	// #1875 — the count is now per KIND and per SCOPE (print_scope.go). Two
	// things changed here. The receipt no longer shares one counter with the red
	// invoice and the debt slip, so printing one no longer marks the others as
	// copies. And untargeted callers no longer stay pinned at 1 — they used to,
	// for want of "a stable id to count", which meant a legacy reprint carried no
	// mark however many times it ran; they now count on the order itself.
	// #2593 vòng 2 — KHÔNG máy receipt ⇒ 503 NGAY, trước `beginMoneyPrint`.
	//
	// Thứ tự này là cả bản sửa. `beginMoneyPrint` đốt số copy và tạo hàng ledger
	// TRƯỚC mọi nil-check; `printPaymentReceipt` với `p == nil` trả `("", nil)`
	// — không lỗi — nên `finishMoneyPrint(printErr=nil)` đóng dấu hàng đó là
	// `StatusPrinted` và client nhận `200 {slips_printed:1}`.
	//
	// Kịch bản thật, đúng dân số PR này nhắm tới: quán hall-only deploy bản sửa
	// định tuyến ⇒ auto-print đứng im (đúng), thu ngân bấm "In biên lai" để cứu
	// ⇒ pos-web báo THÀNH CÔNG, sổ money-audit ghi một biên lai ĐÃ IN, bộ đếm
	// copy tăng — nên tờ giấy thật đầu tiên sau khi sửa config sẽ in 「BẢN IN #2」
	// — và không có tờ giấy nào tồn tại.
	//
	// Chứng từ tiền phải kêu to khi không in được, không được im lặng thành công.
	if s.devices == nil || s.resolveReceiptPrinter() == nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{
			"status": "no_printer",
			"errors": []string{"no printer with role receipt_printer"},
		})
		return
	}

	// #2063 — ĐƠN TREO không được in biên lai. ĐỨNG CÙNG CHỖ với rào 503 ở
	// trên, và vì cùng một lý do: `beginMoneyPrint` đốt số bản in TRƯỚC mọi
	// kiểm tra, nên một lần bị TỪ CHỐI mà đứng sau nó sẽ làm tờ giấy hợp lệ đầu
	// tiên — sau khi khách trả nợ — ra đời đã mang 「BẢN IN #2」, tức tự nhận
	// mình là bản sao của một tờ chưa từng tồn tại.
	//
	// Biên lai tuyên bố quán ĐÃ nhận tiền. Đơn treo thì tiền chưa vào, nên tờ
	// này chưa bao giờ được phép in — kể cả bản đầu. Đó là lý do luật plan-052
	// §4 ("in lại: cảnh báo, không chặn") KHÔNG mâu thuẫn: luật đó nói về in LẠI
	// một chứng từ quán CÓ QUYỀN in.
	//
	// Phiếu ghi nợ (`/print/debt-slip`) và phiếu order (`/print/order-bill`)
	// KHÔNG bị chặn — chúng là chứng từ đúng của trạng thái này.
	if s.orderIsOnHold(body.OrderID) {
		// `code` là HỢP ĐỒNG với pos-web, không phải `status`:
		// `isOnHoldError()` (`web/pos/src/app/pos/lib/on-hold.ts`) khớp trên
		// `body.code` và cố ý KHÔNG khớp trên `message` — message là câu
		// tiếng Anh cho log và được phép đổi.
		//
		// Sai khoá ở đây thì cổng vẫn chặn đúng (409, không đốt số bản in)
		// nhưng giao diện không nhận ra: thu ngân thấy một lỗi chung chung
		// thay vì "đơn còn treo tiền", và sẽ bấm lại.
		writeJSON(w, http.StatusConflict, map[string]any{
			"code":   "order_on_hold",
			"errors": []string{"order has unsettled payment; receipt not allowed"},
		})
		return
	}

	scope := s.resolvePrintScope(body.OrderID, paymentID)
	ledger := printjob.Entry{
		Kind:          printjob.KindReceipt,
		OrderID:       body.OrderID,
		PaymentID:     scope.PaymentID,
		RequestedByID: body.ActorUserID,
		RequestedVia:  "pos",
		Payload:       map[string]any{"template": "payment_receipt", "amount": amount},
	}
	res := s.beginMoneyPrint(r, ledger, scope)
	reprintNo := res.ReprintNo

	// Pass the targeted paymentID so THIS payer's split metadata drives the slip
	// (items/label/amount), not whichever payment happens to be latest.
	templateVersion, printErr := s.printPaymentReceipt(body.OrderID, amount, s.printLabelLocale(), paymentID, reprintNo)
	// TR-28 — the reservation above took the copy number before anything was
	// rendered; this is the first point the layout version exists.
	ledger.TemplateVersion = templateVersion

	if paymentID != "" && s.orders != nil {
		s.auditLogPOS(r, "payment.receipt_printed", "payment", paymentID, auditDetails(map[string]any{
			"payment_id":    paymentID,
			"reprint_no":    reprintNo,
			"reason":        reason,
			"actor_user_id": body.ActorUserID,
		}))
	}

	// plan-052 T1.2 — settle the ledger row the reservation already created, so
	// 「Bản in #N」 and the ledger row carry the same N by construction rather
	// than by two pieces of code agreeing (P-12).
	s.finishMoneyPrint(res, s.journalReceiptPrinter(), ledger, reason, printErr)

	if printErr != nil {
		writeServerError(w, r, printErr)
		return
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"status":           "ok",
		"slips_printed":    1,
		"reprint_no":       reprintNo,
		"remaining_amount": "0",
	})
}

// (#1875 removed `lastConfirmedPaymentID`. It existed so an untargeted red
// invoice could borrow a payment to count against — "most recently updated,
// non-failed" — which on a split bill meant the whole-order slip burned the LAST
// guest's number, and that guest's own first red invoice then printed
// 「BAN IN #2」. Scope resolution lives in print_scope.go now, where an order
// without a nominated payer counts on the ORDER, not on a stranger's payment.)

// settledPaymentAmount returns the settled amount of ONE payment on an order,
// scoped to the whole order family (linkedOrderIDs) like every other payment
// query in this file. ok is false when the payment is missing or not settled —
// the caller must never print money that was not received. Mirrors the
// resolution in handleLANPrintReceipt so the red invoice can target one split
// payer by id (#1779).
func (s *Server) settledPaymentAmount(orderID, paymentID string) (int, bool) {
	ids := s.linkedOrderIDs(orderID)
	ph, args := inPlaceholders(ids)
	var status string
	var amount int
	err := s.db.QueryRow(
		`SELECT status, COALESCE(amount, 0) FROM payments WHERE id = ? AND order_id IN (`+ph+`)`,
		append([]any{paymentID}, args...)...,
	).Scan(&status, &amount)
	if err == nil {
		if status == "succeeded" || status == "confirmed" {
			return amount, true
		}
		return 0, false
	}
	// A payment settled in Cloud has no local row by design; fall back to the
	// order's own Cloud mirror, still scoped to this order and still refusing
	// anything Cloud does not call settled.
	entry, ok := s.cloudPaymentEntry(orderID, paymentID)
	if !ok || !service.PaymentSettled(entry.Status) {
		return 0, false
	}
	return entry.Amount, true
}

// lastConfirmedPaymentAmount returns the amount of the most recently confirmed
// payment on an order (0 when none) — used as the "Da thanh toan" figure when
// reprinting a receipt.
func (s *Server) lastConfirmedPaymentAmount(orderID string) int {
	// Sibling-aware: the payment may be tied to the cloud-keyed copy of the
	// order rather than the row we're printing (see linkedOrderIDs). Look across
	// the whole order family so a reprint finds it regardless.
	ids := s.linkedOrderIDs(orderID)
	ph, args := inPlaceholders(ids)
	var amount int
	// Non-failed, not just 'confirmed': the kiosk leaves the WS payment PENDING
	// (it confirms against Cloud), so a reprint must still surface that amount.
	// #2656 — signed refund rows excluded: the newest row on a refunded order is
	// the refund, and printing its NEGATIVE amount as "paid" is not a slip.
	_ = s.db.QueryRow(`
		SELECT COALESCE(amount, 0)
		FROM payments
		WHERE order_id IN (`+ph+`) AND status != 'failed'
		  AND `+sqlOnlyOriginalPayments+`
		ORDER BY updated_at DESC
		LIMIT 1`, args...).Scan(&amount)
	return amount
}
