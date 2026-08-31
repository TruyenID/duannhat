package handler

import (
	"database/sql"
	"encoding/json"
	"log/slog"
	"net/http"
	"strings"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/printjob"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// splitState is the workstation-derived equivalent of the backend
// CustomerOrderSplitStatusController: split metadata comes from the FIRST
// confirmed payment, paid_count is the number of confirmed payments, and
// remaining is order.total - sum(confirmed amounts).
type splitState struct {
	splitCount    int            // total_bills (0/1 → not a split)
	splitMode     string         // split_mode: "by_items" | "even" | "by_amount" | ""
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
		st.splitMode == "by_items" || st.splitMode == "even" ||
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
		// #2656 — skip signed refund rows: they carry no split metadata, and being
		// the NEWEST row on the order they would win this pick and blank out the
		// split context of the slip being reprinted.
		_ = s.db.QueryRow(`
			SELECT COALESCE(metadata, '')
			FROM payments
			WHERE order_id IN (`+ph+`) AND status != 'failed'
			  AND `+sqlOnlyOriginalPayments+`
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

// resolveReceiptPrinter picks the printer for money documents — the payment
// receipt, the red invoice, and the drawer pulse that goes with them. It is
// STRICT: only a device carrying the receipt_printer role qualifies. Returns
// nil when no such device is configured, and every caller already treats nil as
// "do not print" (the auto path stands down without consuming its claim, the
// LAN endpoint answers 503 no_printer, the drawer reports no_receipt_printer).
//
// It used to fall back receipt → hall → kitchen "so a one-printer shop still
// gets paper". That reasoning inverted the meaning of the role checkboxes in
// Settings: the roles are what an operator DECLARED this machine may print, so
// a shop that unticks 「Hóa đơn」 is saying this machine must not produce bills —
// and the fallback answered by sending the customer's money document to the
// runner station anyway. A checkbox that changes nothing is worse than no
// checkbox. A one-printer shop ticks every role it wants and gets exactly that.
func (s *Server) resolveReceiptPrinter() *printer.Printer {
	return s.devices.GetPrinterByRole(printer.TypeReceiptPrinter)
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
// this slip settles. Both zero for non-cash methods (no tendered_amount
// recorded) → the slip omits the tendered/change lines.
//
// paymentID is the ONLY reliable key and takes precedence: matching by amount
// picks the newest row of that value, and in a split bill several guests
// routinely owe the same amount — chia đều makes that the normal case, not an
// edge one. Once each guest tenders their own note (pos-web
// `lib/cash-tender.ts`), an amount match prints guest #3's お預かり/お釣り on
// guest #1's slip: plausible numbers, wrong guest, and the customer holding the
// paper is the one who finds out. Both callers already know the id — the
// targeted reprint and the red invoice each resolve it before getting here.
//
// Amount then latest-cash remain as fallbacks for the untargeted auto-print
// path (payment confirm), which has no id to pass.
func (s *Server) loadTenderedChange(orderID string, amount int, paymentID string) (tendered, change int) {
	var t, c sql.NullInt64
	if paymentID = strings.TrimSpace(paymentID); paymentID != "" {
		// Sibling-aware, like every other payment query in this file. A merged
		// table (gộp bàn) carries several linked order rows and the payment can
		// be tied to the cloud-keyed copy; matching the raw id alone would miss
		// it and drop through to the amount match — straight back to the wrong
		// guest's お預かり, in exactly the case where several guests owe the
		// same amount.
		ids := s.linkedOrderIDs(orderID)
		ph, args := inPlaceholders(ids)

		err := s.db.QueryRow(`
			SELECT tendered_amount, COALESCE(change_amount, 0) FROM payments
			WHERE id = ? AND order_id IN (`+ph+`) AND status IN ('succeeded', 'confirmed')
			  AND tendered_amount IS NOT NULL AND tendered_amount > 0`,
			append([]any{paymentID}, args...)...).Scan(&t, &c)
		if err == nil {
			return int(t.Int64), int(c.Int64)
		}
		// A targeted slip whose payment carries no tender is a NON-CASH row
		// (card / transfer). Falling through to the amount match would borrow
		// some other guest's cash figures onto a card receipt, so stop here.
		var exists int
		if s.db.QueryRow(`SELECT 1 FROM payments WHERE id = ? AND order_id IN (`+ph+`)`,
			append([]any{paymentID}, args...)...).Scan(&exists) == nil {
			return 0, 0
		}
	}
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
// whose row is absent.
//
// #1282 — when the LOCAL payments table knows nothing (the money was taken
// ONLINE: customer-web / Stripe / PayPay / konbini, confirmed in Cloud and
// never recorded here), it falls back to the order header's Cloud-supplied
// summary, and then to a neutral "paid online" label. Returns "" only when the
// order genuinely has no settled payment anywhere.
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
			if name := s.printedPaymentMethodName(methodID, code, locale); name != "" {
				return name
			}
		}
	}

	// 2. All distinct methods on the order (whole-order invoice / split pay).
	rows, err := s.db.Query(`
		SELECT DISTINCT COALESCE(payment_method_id, ''), COALESCE(payment_method, '')
		FROM payments
		WHERE order_id IN (`+ph+`) AND status IN ('succeeded', 'confirmed')`, args...)
	if err == nil {
		defer rows.Close()
		seen := map[string]bool{}
		var names []string
		for rows.Next() {
			var methodID, code string
			if rows.Scan(&methodID, &code) != nil {
				continue
			}
			if name := s.printedPaymentMethodName(methodID, code, locale); name != "" && !seen[name] {
				seen[name] = true
				names = append(names, name)
			}
		}
		if len(names) > 0 {
			return strings.Join(names, ", ")
		}
	}

	// 3. #1282 — nothing local. The payment was confirmed in Cloud, so read the
	// method identity Cloud mirrored onto the order header (display-only; these
	// are deliberately not rows in `payments`, which would move till money).
	return s.cloudPaymentMethodDisplay(ids, amount, locale)
}

// cloudPaymentMethodDisplay names the method(s) from Cloud's payment_summary
// mirror (orders.cloud_payment_summary) for an order paid online, where the
// workstation holds no local payments row (#1282).
//
// Same shape as the local lookup: the entry matching this slip's amount wins,
// otherwise every distinct method is comma-joined. Each name is resolved through
// the LOCAL payment_methods replica first (so the slip follows the operator's
// print locale) and only then falls back to the name Cloud sent.
//
// Last resort: an order that Cloud says is paid but whose method cannot be named
// at all — a legacy order synced before this column existed — prints a neutral
// "paid online" label rather than dropping the line, which is what made the gap
// invisible in the first place.
func (s *Server) cloudPaymentMethodDisplay(orderIDs []string, amount int, locale string) string {
	ph, args := inPlaceholders(orderIDs)

	var blob string
	err := s.db.QueryRow(`
		SELECT COALESCE(cloud_payment_summary, '')
		FROM orders
		WHERE id IN (`+ph+`) AND COALESCE(cloud_payment_summary, '') != ''
		LIMIT 1`, args...).Scan(&blob)
	if err != nil || strings.TrimSpace(blob) == "" {
		return s.onlinePaidFallbackLabel(orderIDs, locale)
	}

	var entries []service.CloudPaymentSummaryEntry
	if json.Unmarshal([]byte(blob), &entries) != nil || len(entries) == 0 {
		return s.onlinePaidFallbackLabel(orderIDs, locale)
	}

	resolve := func(e service.CloudPaymentSummaryEntry) string {
		if name := s.printedPaymentMethodName(e.PaymentMethodID, e.PaymentMethodCode, locale); name != "" {
			return name
		}
		return strings.TrimSpace(e.PaymentMethodName)
	}

	// The entry for exactly this slip's amount, when there is one.
	if amount > 0 {
		for _, e := range entries {
			if e.Amount == amount {
				if name := resolve(e); name != "" {
					return name
				}
			}
		}
	}

	seen := map[string]bool{}
	var names []string
	for _, e := range entries {
		if name := resolve(e); name != "" && !seen[name] {
			seen[name] = true
			names = append(names, name)
		}
	}
	if len(names) == 0 {
		return s.onlinePaidFallbackLabel(orderIDs, locale)
	}
	return strings.Join(names, ", ")
}

// onlinePaidFallbackLabel returns the neutral "paid online" label for an order
// that carries money the workstation never took itself. Returns "" — no payment
// line at all, as before — in the two cases where that claim would be wrong or
// unfounded (#1282):
//
//   - the order was never paid: nothing to state.
//   - a LOCAL settled payment exists but couldn't be named (empty code and a
//     payment_methods replica that hasn't pulled). That money came through this
//     till, so labelling it "online" would be a lie on the customer's receipt.
//     Stay silent, exactly like before this change.
func (s *Server) onlinePaidFallbackLabel(orderIDs []string, locale string) string {
	ph, args := inPlaceholders(orderIDs)

	var paid int
	if err := s.db.QueryRow(
		`SELECT COALESCE(MAX(paid_amount), 0) FROM orders WHERE id IN (`+ph+`)`, args...,
	).Scan(&paid); err != nil || paid <= 0 {
		return ""
	}

	var localSettled int
	if err := s.db.QueryRow(
		`SELECT COUNT(*) FROM payments
		 WHERE order_id IN (`+ph+`) AND status IN ('succeeded', 'confirmed')`, args...,
	).Scan(&localSettled); err != nil || localSettled > 0 {
		return ""
	}

	switch locale {
	case "en":
		return "Online"
	case "vi":
		// ASCII-folded to match the Shift_JIS print catalog, like the built-in
		// code labels below.
		return "Thanh toan online"
	default:
		return "オンライン決済"
	}
}

// printedPaymentMethodName is resolvePaymentMethodName for the SLIP.
//
// Identical for every method but the Stripe codes, where the synced
// `payment_methods.name` is our PROCESSOR's brand — Cloud ships that row named
// "Stripe" in all three locales (PaymentMethodSeeder) — instead of how the
// customer paid. paymentMethodCodeLabel already carries the right word.
//
// That label was unreachable, which is why the slip kept printing "Stripe" even
// once the label said "カード": it lives in the LAST-RESORT ladder, consulted
// only when no synced payment_methods row matches, and a shop with a synced
// catalogue always has one.
//
// Print-only ON PURPOSE. paymentMethodDisplay, and cloudPaymentMethodDisplay
// under it, feed nothing but the receipt and the red invoice — whereas
// resolvePaymentMethodName is ALSO read by customerOrderShape for the POS
// order-history screen, where "Stripe Card" is pinned by
// TestCustomerOrderShape_CloudPaymentSummaryEdgeMatrix. Putting the override in
// the shared resolver would change that screen too, which nobody asked for.
func (s *Server) printedPaymentMethodName(methodID, code, locale string) string {
	switch strings.ToLower(strings.TrimSpace(code)) {
	case "stripe", "stripe_card":
		return paymentMethodCodeLabel(code, locale)
	}

	return s.resolvePaymentMethodName(methodID, code, locale)
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
		// Stripe is the PROCESSOR, not the tender. The customer tapped a card;
		// "Stripe" on the slip names a vendor neither they nor the cashier
		// transacted with, so it reads as the same word as `card` (chủ dự án
		// 2026-08-17). The CODE stays `stripe` — it is what reconciles against
		// the gateway — only the printed word changes.
		"stripe":      {"カード", "Card", "The"},
		"stripe_card": {"カード", "Card", "The"},
		"paypay":      {"PayPay", "PayPay", "PayPay"},
		"linepay":     {"LINE Pay", "LINE Pay", "LINE Pay"},
		"line_pay":    {"LINE Pay", "LINE Pay", "LINE Pay"},
		"rakuten_pay": {"楽天ペイ", "Rakuten Pay", "Rakuten Pay"},
		"aupay":       {"au PAY", "au PAY", "au PAY"},
		"dbarai":      {"d払い", "d Payment", "d Payment"},
		"merpay":      {"メルペイ", "Merpay", "Merpay"},
		"wechat_pay":  {"WeChat Pay", "WeChat Pay", "WeChat Pay"},
		"alipay":      {"Alipay", "Alipay", "Alipay"},
		"debt":        {"ツケ", "On account", "Ghi no"},
		"on_account":  {"ツケ", "On account", "Ghi no"},
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

// nonVoidedItems bỏ các dòng đã HUỶ khỏi một chứng từ TIỀN (#3044).
//
// Ca thật, 人形町店 19:17 ngày 2026-08-16 (`ORD-2026-0651`): khách gọi một tô,
// không thích, quán huỷ dòng đó lúc 19:19:43 rồi làm lại tô khác. Thanh toán
// lúc 19:22:33 — gần ba phút sau — và tờ biên lai in **cả hai** dòng:
//
//	1 野菜フォー ¥1,100 … / 1 野菜フォー ¥1,100 …     ← cộng lại ¥2,500
//	小計 ¥1,250   合計 ¥1,250   支払済 ¥1,250          ← nhưng tổng là ¥1,250
//
// Tiền KHÔNG sai: `total_amount` chỉ tính dòng còn sống. Sai là tờ giấy tự mâu
// thuẫn — phần dòng món và phần tổng đi hai đường dữ liệu khác nhau, nên phần
// dòng nói "hai tô" còn phần tổng nói "một tô".
//
// Vì sao nó sống lâu mà không ai thấy: nó **im lặng tuyệt đối**. Tiền đúng nên
// không sổ nào lệch, không cảnh báo nào kêu, không test nào đỏ. Chỉ khách cầm
// tờ giấy mới phát hiện — và điều họ kết luận là "quán tính thiếu tiền".
//
// Chủ dự án chốt 2026-08-16: dòng đã huỷ **biến mất hẳn** khỏi chứng từ khách
// cầm. Dấu vết huỷ vẫn còn nguyên chỗ của nó — phiếu huỷ (`emitVoidVoidedAt`),
// `void_reason` trong DB, và sổ kiểm toán — nhưng biên lai là tờ nói "khách đã
// mua gì và trả bao nhiêu", nên một món khách KHÔNG mua không thuộc về nó.
func nonVoidedItems(items []service.Item) []service.Item {
	out := make([]service.Item, 0, len(items))
	for _, it := range items {
		if it.Status == service.ItemStatusVoided {
			continue
		}
		out = append(out, it)
	}

	return out
}

func (s *Server) paidSlipInputs(o *service.Order, st splitState, amountThisSlip int) (*service.Order, []service.Item, service.PaymentSlipInfo) {
	slipOrder := o
	// #3044 — lọc NGAY đầu hàm: đây là chỗ DUY NHẤT sinh ra danh sách món cho
	// cả biên lai lẫn hoá đơn đỏ, nên một phép lọc ở đây phủ cả hai. Lọc ở
	// tầng nạp đơn thì rộng quá — POS, báo cáo huỷ và sổ kiểm toán đều cần
	// nhìn thấy dòng đã huỷ.
	slipItems := nonVoidedItems(o.Items)
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
		// #3044 — nhánh này trả danh sách RIÊNG, không đi qua `slipItems` ở
		// trên, nên nó cần phép lọc của chính nó. Đây là lỗ thứ BA của cùng một
		// lỗi: ba chế độ chia bill dựng danh sách món theo ba đường khác nhau
		// (`even` dùng `slipItems`, `by_items` dựng lại từ phân bổ, `by_amount`
		// trả thẳng `o.Items`), nên một phép lọc không phủ được cả ba.
		return o, nonVoidedItems(o.Items), service.PaymentSlipInfo{
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
		// #3044 — nhánh chia-theo-món dựng lại danh sách từ `o.Items` GỐC, nên
		// nó bỏ qua phép lọc ở đầu hàm. Lọc lại ở đây, nếu không một đơn chia
		// bill mà có dòng đã huỷ sẽ in lại đúng lỗi vừa sửa.
		if filtered := nonVoidedItems(s.allocatedItems(o, st.allocations)); len(filtered) > 0 && s.orders != nil {
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
	case st.splitMode == "even" || st.splitCount > 1:
		splitMode = "even"
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
	// #2071 — the per-rate discount rows the `discounts` block prints, read
	// from the `order_conditions` ledger. Loaded HERE (the one normalization
	// funnel every money-slip path goes through) so the template renderer and
	// the legacy formatter see the same rows. No ledger rows → empty slice →
	// the slip prints no discount block; it never falls back to the
	// DiscountAmount column above, which holds the REQUESTED figure (#2031).
	o.Discounts = s.loadOrderDiscountLines(o.ID)
	// #2170 — the per-rate TAX rows the breakdown block prints, read from the
	// same ledger. When present they replace the print layer's recompute (which
	// zeroes discount + service charge and therefore disagrees with the
	// CloudPRNT slip on any discounted order); an empty ledger falls back to
	// the recompute so an unpriced/offline order still prints.
	if s.orders != nil {
		o.TaxLines = s.orders.OrderTaxLines(o.ID)
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
// reprintNo carries the copy number AppendPrintHistory already assigned, so the
// slip can print 「BAN IN #N」 from copy 2 (plan-052 P-10b). 0/1 both mean "this
// is the original" — the auto-print paths pass 1 because a receipt fired by a
// payment landing is by definition the first one.
//
// The FIRST return value is the layout version that drew the 「DA THANH TOAN」
// sheet (TR-28), for the caller to stamp onto its ledger row. It has to travel
// out of here because the reservation lives one level UP — in
// `autoPrintPaymentReceipt` and the reprint handler — while the only code that
// knows which template was used is in here.
//
// It reports the PAID slip, not the "PHAN CON LAI" QR slip: the ledger row this
// feeds is the receipt's, and one row may only carry a claim about its own
// sheet. Every path that prints nothing returns "" — that is the honest answer
// (no paper, no layout), and it is the same "" the legacy formatter yields, both
// stored as NULL.
func (s *Server) printPaymentReceipt(orderID string, amountThisSlip int, locale string, paymentID string, reprintNo int) (string, error) {
	// Best-effort path — when the order engine or device registry isn't wired
	// (e.g. unit tests, headless boot) there's nothing to print. Never panic.
	if s.orders == nil || s.devices == nil {
		return "", nil
	}
	o, err := s.orders.GetByID(orderID)
	if err != nil {
		return "", err
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
		// #1806 — subject là VAI TRÒ máy in, không phải đơn hàng: thiếu máy in
		// bếp là MỘT sự cố của quán, không phải 200 sự cố của 200 đơn.
		s.raiseAlert(service.KindNoPrinter, "receipt_printer",
			"Chưa cấu hình máy in hoá đơn", map[string]any{"order_id": orderID})
		slog.Warn("payment receipt: no printer configured", "order", orderID)
		return "", nil
	}

	config := service.PrintJobConfig{
		PaperWidth:        42,
		Locale:            locale, // pos-web operator's language → receipt labels
		PhysicalWidth:     p.CharWidth(),
		StoreName:         s.storeName(),
		StoreSubName:      s.settingValue("workstation_brand_name"),
		StoreAddress:      s.settingValue("workstation_branch_address"),
		StorePhone:        s.settingValue("workstation_branch_phone"),
		StoreOrganization: s.settingValue("workstation_organization_name"),
		TaxRate:           s.shopTaxRate(),
		Currency:          s.printCurrencySymbol(),
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
	slip.Tendered, slip.Change = s.loadTenderedChange(orderID, amountThisSlip, paymentID)
	// Payment method — resolved from the payments table (the deprecated
	// order.payment_method column is usually empty), so the receipt shows how
	// the order was actually paid.
	slip.PaymentMethod = s.paymentMethodDisplay(orderID, amountThisSlip, locale)
	// plan-052 P-10b — the locked reprint mark. Nothing here decides the
	// number; it was minted by AppendPrintHistory before the paper moved (P-12).
	slip.ReprintNumber = reprintNo
	// plan-053 T3.6 tầng 2 (#1914) — call site 9/13. Đây là tờ giấy KHÁCH cầm,
	// nên đường lùi phải nguyên vẹn: cờ tắt ⇒ byte y hệt hôm qua.
	paid, templateVersion := s.renderMoneySlip(
		service.NewPaidRenderData(slipOrder, slipItems, 0, config, slip),
		service.PrintRenderProfileFor(p.Profile(), ""),
		config.Locale,
		func() []byte { return service.FormatPaidTicket(slipOrder, slipItems, 0, config, slip) },
	)

	// The "PHAN CON LAI" + QR slip is for the NEXT payer to scan & pay the
	// remainder — only meaningful on the auto-print-on-payment path (paymentID
	// == ""). A TARGETED per-person reprint (pos-web "In biên lai", paymentID
	// set) is a POS-driven split where every share is already collected at the
	// register; the QR slip is redundant there — and by-items per-bill tax
	// rounding can leave a ¥1-2 residual `remaining` that would wrongly trigger
	// it. So skip the QR slip entirely on a targeted reprint.
	var remainingTicket []byte
	if shouldPrintRemainingQRSlip(paymentID, st) {
		// call site 10/13. Phiếu QR "phần còn lại" KHÔNG có dòng ledger riêng —
		// dấu phiên bản trả về là của tờ 「DA THANH TOAN」, và gán đè bằng tờ này
		// sẽ khiến hàng nhật ký của biên lai khai một layout không vẽ ra nó.
		// #3044 — tờ QR 「PHAN CON LAI」 cũng là giấy đưa cho khách (người trả
		// tiếp cầm nó để quét), nên nó chịu cùng luật. Nó dựng danh sách từ
		// `o.Items` GỐC chứ không qua `slipItems`, nên phép lọc ở
		// `paidSlipInputs` không với tới đây.
		//
		// ⚠️ CHƯA CÓ RÀO cho riêng dòng này, nói ra thay vì để nó vô hình. Tờ
		// này chỉ in khi `shouldPrintRemainingQRSlip` đúng, tức cần một trạng
		// thái CHIA BILL còn dư — dựng được trạng thái đó trong test tốn hơn
		// nhiều so với bản vá, và tôi đã dừng lại thay vì ship một bài test
		// xanh không chạm tới nhánh này. Đổi dòng này thì không gì đỏ; ai sửa
		// ở đây phải tự đọc lại luật: dòng đã huỷ KHÔNG lên giấy của khách.
		remainingItems := nonVoidedItems(o.Items)
		remainingTicket, _ = s.renderMoneySlip(
			service.NewRemainingRenderData(o, remainingItems, 0, config, st.remaining),
			service.PrintRenderProfileFor(p.Profile(), ""),
			config.Locale,
			func() []byte { return service.FormatRemainingTicket(o, remainingItems, 0, config, st.remaining) },
		)
	}

	if err := p.Connect(); err != nil {
		return templateVersion, err
	}
	defer p.Disconnect()

	if err := p.Print(paid); err != nil {
		// The version is reported even on failure: the ledger row records the
		// ATTEMPT (§4), and "which layout we tried to print" is exactly as true
		// of a jam as of a clean sheet.
		return templateVersion, err
	}
	if remainingTicket != nil {
		_ = p.Print(remainingTicket)
	}
	return templateVersion, nil
}

// POST /api/lan/print/red-invoice
//
// Body: { order_id, customer_name }
//
// Prints the hoá đơn đỏ — the same content as the paid receipt (items, totals,
// payment method, cash tendered/change) plus a named-customer line. Reprints
// stay UNLIMITED and are never refused (plan-052 §4 ruling); what changed with
// #1166 is that each one is now COUNTED, MARKED on the paper from copy 2, and
// written to the ledger with whoever asked for it.
func (s *Server) handleLANPrintRedInvoice(w http.ResponseWriter, r *http.Request) {
	if s.orders == nil || s.devices == nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{"message": "workstation not ready"})
		return
	}
	var body struct {
		OrderID      string `json:"order_id"`
		CustomerName string `json:"customer_name"`
		// #1779 — target ONE split payment (per guest) when chia bill. Absent →
		// the whole order, so every existing caller keeps its behaviour.
		PaymentID string `json:"payment_id"`
		// plan-052 §4 — asked for, never required; an empty reason prints the
		// same slip and the ledger records that none was given.
		ReprintReason string `json:"reprint_reason"`
		ActorUserID   string `json:"actor_user_id"`
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
		StoreName:                s.storeName(),
		StoreSubName:             s.settingValue("workstation_brand_name"),
		StoreAddress:             s.settingValue("workstation_branch_address"),
		StorePhone:               s.settingValue("workstation_branch_phone"),
		StoreOrganization:        s.settingValue("workstation_organization_name"),
		TaxRate:                  s.shopTaxRate(),
		Currency:                 s.printCurrencySymbol(),
		CurrencyCode:             s.shopSetting("currency_code", "JPY"),
		SellerRegistrationNumber: s.shopSetting("seller_registration_number", ""),
	}

	// #1779 — a red invoice can name ONE split payer (chia bill) via payment_id,
	// exactly like the paid-receipt path: its split metadata drives which items
	// and amount land on the slip. Absent → the whole order.
	targetPaymentID := strings.TrimSpace(body.PaymentID)
	amount := o.PaidAmount
	if amount <= 0 {
		amount = o.TotalAmount
	}
	if targetPaymentID != "" {
		payAmount, ok := s.settledPaymentAmount(o.ID, targetPaymentID)
		if !ok {
			writeError(w, http.StatusConflict, "payment not confirmed")
			return
		}
		amount = payAmount
	}
	// #2063 — ĐƠN TREO không được xuất hoá đơn đỏ. TRƯỚC `beginMoneyPrint` bên
	// dưới, vì bước đó đốt số bản in cả khi in lỗi (P-10b, cố ý) — nhưng một
	// lần bị TỪ CHỐI thì không có tờ giấy nào ra, nên đốt số ở đó sẽ làm tờ hợp
	// lệ đầu tiên (sau khi khách trả nợ) mang 「BAN IN #2」.
	//
	// Hoá đơn đỏ là chứng từ pháp định tuyên bố quán đã nhận tiền. Đơn treo thì
	// tiền chưa vào — tờ này chưa bao giờ được phép in, kể cả bản đầu.
	if s.orderIsOnHold(o.ID) {
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
			"errors": []string{"order has unsettled payment; red invoice not allowed"},
		})
		return
	}

	st := s.deriveSplitState(o.ID, o.TotalAmount, targetPaymentID)
	slipOrder, slipItems, slip := s.paidSlipInputs(o, st, amount)
	slip.Tendered, slip.Change = s.loadTenderedChange(o.ID, amount, targetPaymentID)
	// Payment method(s) the order was paid with — the red invoice must state it.
	slip.PaymentMethod = s.paymentMethodDisplay(o.ID, amount, locale)
	slip.CustomerName = strings.TrimSpace(body.CustomerName)

	// plan-052 P-10b — take the copy number BEFORE the paper moves, so copy ≥2
	// carries 「BAN IN #N」. Counting first also means a failed print still burns
	// its number: the attempt happened, and the shop must not be able to reset
	// the counter by unplugging the machine.
	//
	// #1875 — that number is now the RED INVOICE's own, on its own scope. It used
	// to come from a counter shared with the receipt and the debt slip, so a
	// customer whose receipt had been printed got their FIRST red invoice stamped
	// 「BAN IN #2」; and a whole-order slip counted against `lastConfirmedPaymentID`,
	// i.e. it silently burned the LAST guest's number.
	reason := strings.TrimSpace(body.ReprintReason)
	if reason == "" {
		reason = "auto"
	}
	scope := s.resolvePrintScope(o.ID, targetPaymentID)
	ledger := printjob.Entry{
		Kind:          printjob.KindRedInvoice,
		OrderID:       o.ID,
		PaymentID:     scope.PaymentID,
		RequestedByID: strings.TrimSpace(body.ActorUserID),
		RequestedVia:  "pos",
		Payload:       map[string]any{"template": "red_invoice"},
	}
	res := s.beginMoneyPrint(r, ledger, scope)
	reprintNo := res.ReprintNo
	slip.ReprintNumber = reprintNo

	// plan-053 T3.6 tầng 2 (#1914) — call site 13/13.
	//
	// Suýt bị bỏ sót: rào phủ ban đầu viết tên "FormatRedInvoice" trong khi hàm
	// thật tên `FormatRedInvoiceTicket`, nên nó MÙ với đúng chứng từ này —
	// hoá đơn đỏ, tờ giấy có giá trị pháp lý cao nhất trong bộ.
	invoice, templateVersion := s.renderMoneySlip(
		service.NewRedInvoiceRenderData(slipOrder, slipItems, config, slip),
		service.PrintRenderProfileFor(p.Profile(), ""),
		config.Locale,
		func() []byte { return service.FormatRedInvoiceTicket(slipOrder, slipItems, config, slip) },
	)
	// TR-28 — đây là tờ giấy có giá trị pháp lý cao nhất trong bộ, nên nó là tờ
	// mà câu "in lại bằng đúng layout gốc" đắt nhất khi trả lời sai. Reservation
	// lấy số bản in trước khi có slip, nên phiên bản chỉ biết được ở đây.
	ledger.TemplateVersion = templateVersion

	printErr := p.Connect()
	if printErr == nil {
		defer p.Disconnect()
		printErr = p.Print(invoice)
	}

	// §4 point 2 — WHO, on every print, success or failure. A red invoice that
	// jammed still happened as far as the counter is concerned.
	s.finishMoneyPrint(res, p, ledger, reason, printErr)

	if printErr != nil {
		writeServerError(w, r, printErr)
		return
	}

	s.auditLogPOS(r, "print.red_invoice", "order", o.ID, auditDetails(map[string]any{
		"customer":      strings.TrimSpace(body.CustomerName),
		"reprint_no":    reprintNo,
		"reason":        reason,
		"actor_user_id": strings.TrimSpace(body.ActorUserID),
	}))
	writeJSON(w, http.StatusOK, map[string]any{"status": "printed", "reprint_no": reprintNo})
}
