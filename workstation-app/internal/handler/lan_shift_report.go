package handler

import (
	"database/sql"
	"math"
	"net/http"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// POST /api/lan/print/shift-report   (auth: Bearer, LAN)
//
// Prints a 精算 (cashier-shift settlement / Z) thermal slip for a settled
// till session — the LAN-print counterpart to the pos-web "Kết ca" flow.
// Everything is aggregated from local SQLite (orders / payments / till_*),
// so the slip prints even when Cloud is unreachable, mirroring the offline
// close() path that produced the session.
//
// Body: { session_id: "uuid", shop_slug?: "..." }
//
// Responses:
//
//	200 { status:"ok", slips_printed:1 }
//	400 session_id required
//	404 session not found (locally)
//	503 { status:"no_printer" } — no receipt printer configured
func (s *Server) handleLANPrintShiftReport(w http.ResponseWriter, r *http.Request) {
	if _, ok := DeviceFromContext(r.Context()); !ok {
		writeError(w, http.StatusUnauthorized, "not authenticated")
		return
	}
	var body struct {
		SessionID  string `json:"session_id"`
		ShopSlug   string `json:"shop_slug"`
		ReportKind string `json:"report_kind"` // plan-046 — "handover" | "settlement" (default)
	}
	if err := readJSON(r, &body); err != nil || body.SessionID == "" {
		writeError(w, http.StatusBadRequest, "session_id required")
		return
	}

	info, err := s.buildShiftReport(body.SessionID)
	if err == sql.ErrNoRows {
		writeError(w, http.StatusNotFound, "session not found")
		return
	}
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	info.ReportKind = body.ReportKind // plan-046 — drives 引き継ぎ vs 精算 header

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
		// Locale from the cashier's pos-web selection (Accept-Language, always
		// sent by workstation-print-service). Labels render ja/en/vi; unknown → ja.
		Locale: localeFromRequest(r),
	}
	slip := service.FormatShiftReport(config, *info)

	if err := rp.Connect(); err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rp.Disconnect()
	if err := rp.Print(slip); err != nil {
		writeServerError(w, r, err)
		return
	}

	s.auditLogPOS(r, "till.shift_report_printed", "till_session", body.SessionID, auditDetails(map[string]any{
		"session_id": body.SessionID,
	}))

	writeJSON(w, http.StatusOK, map[string]any{
		"status":        "ok",
		"slips_printed": 1,
	})
}

// paidPaymentsPredicate builds the payment filter shared by the Z-report, the
// local settlement snapshot and reconcileSession. `alias` is the payments table
// alias ("" when unaliased). Placeholders, in order: sessionID, lower, upper.
//
// ATTRIBUTION-FIRST. A payment explicitly bound to this shift counts wherever it
// fell in time, and a payment bound to a DIFFERENT shift never counts here even
// when it landed inside this window. That is the same rule Cloud reconciles by
// (`order_payments.till_session_id`), so the printed slip and Cloud's
// settlement_snapshot — and therefore Σ per-shift slips vs the chain aggregate —
// describe the same money.
//
// A pure time window could not do that: a gap payment claimed at open (collected
// BEFORE opened_at, then stamped to this session) fell outside the window and was
// missing from the slip that owns it, while a payment already attributed to the
// previous shift was double-counted here. Both are money defects, not display
// ones — plan-044 built the attribution precisely to prevent them, and the slip
// was the one place still ignoring it.
//
// Unattributed rows (best-effort stamping failed at capture, or a pre-#817 legacy
// row) fall back to the shift window so no payment is ever silently dropped from
// the drawer.
//
// The window half compares substr(created_at,1,19) — the "YYYY-MM-DDTHH:MM:SS"
// prefix, which for the UTC-"Z" payment rows is the UTC wall clock — against
// bounds normalizeInstant() has converted to the SAME UTC second-precision form,
// so a bound carrying a Cloud +09:00 offset can no longer lexically exclude every
// UTC payment row.
func paidPaymentsPredicate(alias string) string {
	p := ""
	if alias != "" {
		p = alias + "."
	}
	return p + `status IN ('pending','confirmed') AND (` +
		p + `till_session_id = ? OR ((` + p + `till_session_id IS NULL OR ` + p + `till_session_id = '') ` +
		`AND substr(` + p + `created_at,1,19) >= ? AND substr(` + p + `created_at,1,19) <= ?))`
}

// buildShiftReport aggregates a settled session into a ShiftReportInfo from
// local SQLite. Returns sql.ErrNoRows when the session is unknown locally.
func (s *Server) buildShiftReport(sessionID string) (*service.ShiftReportInfo, error) {
	var openingFloat, countedCash, cashVariance sql.NullFloat64
	var openerName, closedAt sql.NullString
	var chainSequence sql.NullInt64
	var openedAt, tillID, currency string
	// 担当者 — a cashier who picked THEMSELVES at open is stored as
	// opened_by_id with NO opener_name (only the "Khác…" free-text path fills
	// that column), so the name has to be resolved through the local staff
	// mirror. The shift-OPEN slip already did this; the 精算 / 引き継ぎ slips did
	// not, which is why they printed 未設定 for every real shift.
	err := s.db.QueryRow(`
		SELECT ts.opening_float_amount, ts.counted_cash, ts.cash_variance, ts.opened_at,
		       ts.closed_at, ts.till_id, ts.default_currency_code,
		       COALESCE(NULLIF(ts.opener_name, ''), st.full_name),
		       ts.chain_sequence
		FROM till_sessions ts
		LEFT JOIN staff st ON st.id = ts.opened_by_id
		WHERE ts.id = ?`, sessionID).Scan(
		&openingFloat, &countedCash, &cashVariance, &openedAt,
		&closedAt, &tillID, &currency, &openerName, &chainSequence)
	if err != nil {
		return nil, err // sql.ErrNoRows bubbles up to a 404
	}

	// Upper bound of the shift window: the close timestamp, or "now" for a
	// still-open session (defensive — a settled session always has closed_at).
	upper := time.Now().UTC().Format(time.RFC3339Nano)
	if closedAt.Valid && closedAt.String != "" {
		upper = closedAt.String
	}

	// Canonical UTC second-precision bounds for the payment/void window queries
	// (compared via substr(...,1,19)). Display + the ZNumber closed_at compare
	// keep the raw strings below.
	lowerN := normalizeInstant(openedAt)
	upperN := normalizeInstant(upper)

	loc := s.shopLocation()
	info := &service.ShiftReportInfo{
		Currency:    currency,
		OpenedAt:    fmtShiftTime(openedAt, loc),
		ClosedAt:    fmtShiftTime(upper, loc),
		CountedCash: roundInt(countedCash.Float64),
		Phone:       s.settingValue("workstation_branch_phone"),
	}
	if openerName.Valid {
		info.Operator = openerName.String
	}
	// Chain position, so a stack of same-day handover slips is tellable apart.
	info.ChainSequence = int(chainSequence.Int64)

	// レジ code + No. (ordinal of settled shifts on this till, this one incl.).
	_ = s.db.QueryRow(`SELECT code FROM tills WHERE id = ?`, tillID).Scan(&info.TillCode)
	_ = s.db.QueryRow(`
		SELECT COUNT(*) FROM till_sessions
		WHERE till_id = ? AND status = 'settled'
		  AND (closed_at IS NULL OR closed_at <= ?)`, tillID, upper).Scan(&info.ZNumber)
	if info.ZNumber < 1 {
		info.ZNumber = 1
	}

	// Expected cash from the same reconcile the close screen used → 過不足
	// matches the persisted cash_variance rather than being recomputed loosely.
	if recon, rerr := s.reconcileSession(sessionID); rerr == nil {
		info.ExpectedCash = roundInt(recon.ExpectedCash)
	} else if countedCash.Valid && cashVariance.Valid {
		info.ExpectedCash = roundInt(countedCash.Float64 - cashVariance.Float64)
	}
	info.CashVariance = info.CountedCash - info.ExpectedCash

	// ─── sales summary over the orders paid within the window ───
	var discountTotal int
	err = s.db.QueryRow(`
		SELECT COALESCE(SUM(o.total_amount), 0),
		       COALESCE(SUM(o.tax_amount), 0),
		       COALESCE(SUM(o.discount_amount), 0),
		       COALESCE(SUM(CASE WHEN o.discount_amount > 0 THEN 1 ELSE 0 END), 0),
		       COALESCE(SUM(o.guest_count), 0),
		       COUNT(*)
		FROM orders o
		WHERE o.id IN (
			SELECT DISTINCT order_id FROM payments WHERE `+paidPaymentsPredicate("")+`
		)`, sessionID, lowerN, upperN).Scan(
		&info.GrossSales, &info.TaxTotal, &discountTotal,
		&info.DiscountTotalCount, &info.GuestCount, &info.CheckCount)
	if err != nil {
		return nil, err
	}
	info.NetSales = info.GrossSales - info.TaxTotal
	info.DiscountTotalAmount = discountTotal

	// Item count (点数) — sum of non-voided line quantities on the same set.
	_ = s.db.QueryRow(`
		SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi
		WHERE oi.status != 'voided' AND oi.customer_order_id IN (
			SELECT DISTINCT order_id FROM payments WHERE `+paidPaymentsPredicate("")+`
		)`, sessionID, lowerN, upperN).Scan(&info.ItemCount)

	// ─── per-rate tax breakdown (消費税内訳, plan-043 T4.2) ───
	// 課税売上 (taxable) = Σ line subtotal (qty×unit_price, void lines excluded).
	// 消費税 (tax) is RECOMPUTED once per (order, rate) group via the shared §8
	// engine — NOT summed from the per-line tax_amount snapshots, which are
	// rounded per line (端数処理は税率ごとに1回 requires per-group rounding, so a
	// mixed order like 3×¥333 @8% must show 80, never the per-line-summed 81).
	// Rounding once per order per rate then summing across the shift is the
	// インボイス-correct aggregate and reconciles to Σ order.tax_amount (bar the
	// order-level service-charge tax). Only non-null tax_rate lines contribute —
	// a legacy line (tax_rate NULL) leaves TaxBreakdown empty so the formatter
	// falls back to one figure. Gated on close_report_tax_breakdown (thermal).
	info.ShowTaxBreakdown = s.shopSetting("close_report_tax_breakdown", "true") != "false"
	if info.ShowTaxBreakdown {
		// Plan-046 T5.4 (P5-6): the per-rate computation lives in the shared
		// service.PerRateTaxBuckets so the Z-report and the settlement snapshot
		// agree to the yen. Byte-identical to the previous inline block.
		step := service.CurrencyStep(s.shopSetting("currency_code", "JPY"))
		info.TaxBreakdown = service.PerRateTaxBuckets(s.db, step, sessionID, lowerN, upperN)
	}

	// ─── payment methods (支払方法) ───
	// Group by the raw tender code so line.Code drives locale mapping in the
	// formatter; the Cloud name (pm.name) rides along as the fallback label.
	if rows, qerr := s.db.Query(`
		SELECT p.payment_method AS code,
		       COALESCE(NULLIF(pm.name, ''), p.payment_method) AS label,
		       COUNT(*) AS cnt,
		       COALESCE(SUM(p.amount - COALESCE(p.refunded_amount, 0)), 0) AS amt
		FROM payments p
		LEFT JOIN payment_methods pm ON pm.code = p.payment_method
		WHERE `+paidPaymentsPredicate("p")+`
		GROUP BY code, label
		ORDER BY amt DESC`, sessionID, lowerN, upperN); qerr == nil {
		for rows.Next() {
			var line service.ShiftPaymentLine
			if err := rows.Scan(&line.Code, &line.Label, &line.Count, &line.Amount); err == nil {
				info.Payments = append(info.Payments, line)
			}
		}
		rows.Close()
	}

	// ─── named discounts (割引・割増) from applied coupons ───
	if rows, qerr := s.db.Query(`
		SELECT oc.coupon_code, COUNT(*), COALESCE(SUM(oc.discount_applied), 0)
		FROM order_coupons oc
		WHERE oc.released_at IS NULL AND oc.order_id IN (
			SELECT DISTINCT order_id FROM payments WHERE `+paidPaymentsPredicate("")+`
		)
		GROUP BY oc.coupon_code
		ORDER BY 3 DESC`, sessionID, lowerN, upperN); qerr == nil {
		for rows.Next() {
			var line service.ShiftDiscountLine
			if err := rows.Scan(&line.Label, &line.Count, &line.Amount); err == nil {
				info.Discounts = append(info.Discounts, line)
			}
		}
		rows.Close()
	}

	// ─── cash in / out (入出金) ───
	if rows, qerr := s.db.Query(`
		SELECT event_type, COUNT(*), COALESCE(SUM(amount), 0)
		FROM till_cash_events WHERE session_id = ? GROUP BY event_type`, sessionID); qerr == nil {
		for rows.Next() {
			var t string
			var cnt int
			var amt float64
			if err := rows.Scan(&t, &cnt, &amt); err != nil {
				continue
			}
			switch t {
			case "paid_in", "loan_from_safe":
				info.PaidInCount += cnt
				info.PaidInAmount += roundInt(amt)
			case "paid_out", "pickup_to_safe":
				info.PaidOutCount += cnt
				info.PaidOutAmount += roundInt(amt)
			}
		}
		rows.Close()
	}

	// ─── voided bills (伝票削除) within the window, split by whether paid ───
	if rows, qerr := s.db.Query(`
		SELECT o.total_amount,
		       (SELECT COUNT(*) FROM payments p
		          WHERE p.order_id = o.id AND p.status IN ('pending','confirmed')) AS pays
		FROM orders o
		WHERE o.status = 'voided' AND o.voided_at IS NOT NULL
		  AND substr(o.voided_at,1,19) >= ? AND substr(o.voided_at,1,19) <= ?`, lowerN, upperN); qerr == nil {
		for rows.Next() {
			var total, pays int
			if err := rows.Scan(&total, &pays); err != nil {
				continue
			}
			if pays > 0 {
				info.VoidPaidCount++
				info.VoidPaidAmount += total
			} else {
				info.VoidUnpaidCount++
				info.VoidUnpaidAmount += total
			}
		}
		rows.Close()
	}

	// ─── optional-section toggles (shop_settings, pulled from Cloud) ───
	info.ShowPaymentMethods = s.shopSetting("close_report_payment_methods", "true") != "false"
	info.ShowServiceCharge = s.shopSetting("close_report_service_charge", "true") != "false"
	info.ShowDrawerCheck = s.shopSetting("close_report_drawer_check", "true") != "false"
	info.ShowDenominations = s.shopSetting("close_report_denominations", "true") != "false"

	// 金種 — closing cash-count breakdown (every active denomination, 0 for
	// uncounted), same shape as the shift-open report but phase='closing'.
	if info.ShowDenominations {
		if rows, qerr := s.db.Query(`
			SELECT d.value, COALESCE(c.quantity, 0), COALESCE(c.subtotal_amount, 0)
			FROM denominations d
			LEFT JOIN till_cash_denomination_counts c
			       ON c.denomination_id = d.id
			      AND c.session_id = ?
			      AND c.phase = 'closing'
			WHERE d.is_active = 1 AND d.currency_code = ?
			ORDER BY d.value DESC`, sessionID, currency); qerr == nil {
			for rows.Next() {
				var value, subtotal float64
				var qty int
				if err := rows.Scan(&value, &qty, &subtotal); err == nil {
					info.Denominations = append(info.Denominations, service.ShiftOpenDenomLine{
						Value:    roundInt(value),
						Quantity: qty,
						Subtotal: roundInt(subtotal),
					})
				}
			}
			rows.Close()
		}
	}

	return info, nil
}

// roundInt rounds a float amount to the nearest whole unit (yen).
func roundInt(f float64) int { return int(math.Round(f)) }

// fmtShiftTime turns a stored UTC timestamp into the "2006/01/02 15:04"
// wall-clock form used on the slip, converted into loc (the shop's timezone) so
// the printed period matches the clock on the floor. A nil loc falls back to
// UTC.
//
// Two things it gets right that the old code did not:
//
//  1. It uses t.In(loc), NOT t.Local(). t.Local() renders in the PROCESS
//     timezone, which on the desktop/server build is frequently UTC — so the
//     period printed 9h early in Japan.
//  2. It parses BOTH the zoned RFC3339 form (local writes + Cloud toIso8601) AND
//     the naive "2006-01-02 15:04:05" / "…T…" form (MySQL/Cloud datetime with no
//     zone), interpreting the naive form as UTC. Previously a naive value failed
//     RFC3339 parsing and fell through to the raw-substring branch, printing the
//     UTC wall clock unconverted — that was the actual shift-report clock bug.
//
// Every stored timestamp in this system is UTC (local writes use time.Now().UTC;
// Cloud stores UTC), so treating a zoneless value as UTC is correct.
//
// opened_at and closed_at can arrive in DIFFERENT shapes (Cloud sync stores its
// JSON verbatim; the local close path writes RFC3339Nano), so rather than
// enumerate every layout, it NORMALIZES the string to a single RFC3339 UTC form
// then parses — this is what makes the open and close times convert identically
// instead of one converting and the other printing raw UTC.
func fmtShiftTime(raw string, loc *time.Location) string {
	if raw == "" {
		return ""
	}
	if loc == nil {
		loc = time.UTC
	}
	s := strings.TrimSpace(raw)
	// "2026-07-03 07:25:00…" → "2026-07-03T07:25:00…" (space date/time sep → T).
	if len(s) >= 11 && s[10] == ' ' {
		s = s[:10] + "T" + s[11:]
	}
	// Try the string as-is (it may already carry a zone: Z or ±hh:mm), then with
	// a "Z" appended for the zoneless case (assume UTC). Covers: RFC3339(+Nano),
	// numeric offset, "…T…" with no zone, and the space-separated "…Z" hybrid.
	for _, cand := range []string{s, s + "Z"} {
		for _, layout := range []string{time.RFC3339Nano, time.RFC3339} {
			if t, err := time.Parse(layout, cand); err == nil {
				return t.In(loc).Format("2006/01/02 15:04")
			}
		}
	}
	if len(s) >= 16 {
		return s[:16]
	}
	return s
}

// normalizeInstant parses a stored timestamp in ANY format this system produces
// (RFC3339 with Z or a ±hh:mm offset, or a naive "space"/"T" value with no zone)
// and returns the canonical "2006-01-02T15:04:05" form in UTC — fixed-width,
// second-precision, zone-free. It's the bound form for window comparisons
// against substr(created_at,1,19), so a Cloud timestamp with a +09:00 offset is
// compared by INSTANT, not by raw string. Empty/unparseable → best-effort raw.
func normalizeInstant(raw string) string {
	if raw == "" {
		return ""
	}
	s := strings.TrimSpace(raw)
	if len(s) >= 11 && s[10] == ' ' {
		s = s[:10] + "T" + s[11:]
	}
	for _, cand := range []string{s, s + "Z"} {
		for _, layout := range []string{time.RFC3339Nano, time.RFC3339} {
			if t, err := time.Parse(layout, cand); err == nil {
				return t.UTC().Format("2006-01-02T15:04:05")
			}
		}
	}
	if len(s) >= 19 {
		return s[:19]
	}
	return s
}

// shopLocation resolves the timezone the shift-close slip prints its wall-clock
// times in. It reads the Cloud-registered branches.timezone and hands both it
// and the workstation OS zone to resolveShopLocation.
func (s *Server) shopLocation() *time.Location {
	var tz string
	_ = s.db.QueryRow(
		`SELECT timezone FROM branches WHERE timezone IS NOT NULL AND timezone != '' LIMIT 1`).Scan(&tz)
	return resolveShopLocation(time.Local, tz)
}

// resolveShopLocation picks the print timezone, preferring the workstation OS
// zone `local` — the clock on the shop PC where the register physically sits,
// which is exactly what the cashier reads off the slip — whenever that is a
// real (non-UTC) zone.
//
// It deliberately does NOT let the Cloud branches.timezone override a real
// machine zone: branches.timezone is the branch's REGISTERED zone, which can
// differ from where the terminal actually runs (a branch registered
// "Asia/Tokyo" but operated/tested from Vietnam at +07). Honoring it forced +09
// onto a +07 machine and printed the shift 2h ahead of the wall clock.
//
// Only when the machine clock is UTC (offset 0) — almost always a misconfigured
// POS PC rather than a genuinely-UTC shop — does it trust the registered branch
// timezone as a safety net. The embedded IANA tz database (cmd/workstation/
// main.go) makes LoadLocation resolve on any OS.
func resolveShopLocation(local *time.Location, branchTZ string) *time.Location {
	if local == nil {
		local = time.UTC
	}
	if _, offset := time.Now().In(local).Zone(); offset != 0 {
		return local
	}
	if branchTZ != "" {
		if loc, err := time.LoadLocation(branchTZ); err == nil {
			return loc
		}
	}
	return local
}
