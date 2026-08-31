package handler

import (
	"database/sql"
	"net/http"
	"strings"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/printjob"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// POST /api/lan/print/shift-open-report   (auth: Bearer, LAN)
//
// Prints a レジ開け (shift-open / opening cash count) thermal slip for a just-
// opened till session — the LAN-print counterpart to the pos-web "Mở ca" flow.
// Everything is aggregated from local SQLite (till_sessions + the opening-phase
// till_cash_denomination_counts), so the slip prints even when Cloud is
// unreachable, mirroring the offline open() path that produced the session.
//
// Body: { session_id: "uuid", shop_slug?: "..." }
//
// Responses:
//
//	200 { status:"ok", slips_printed:1 }
//	400 session_id required
//	404 session not found (locally)
//	503 { status:"no_printer" } — no receipt printer configured
func (s *Server) handleLANPrintShiftOpenReport(w http.ResponseWriter, r *http.Request) {
	if _, ok := DeviceFromContext(r.Context()); !ok {
		writeError(w, http.StatusUnauthorized, "not authenticated")
		return
	}
	var body struct {
		SessionID  string `json:"session_id"`
		ShopSlug   string `json:"shop_slug"`
		DeviceName string `json:"device_name"`
	}
	if err := readJSON(r, &body); err != nil || body.SessionID == "" {
		writeError(w, http.StatusBadRequest, "session_id required")
		return
	}

	// Shop-setting gate (pulled from Cloud into shop_settings via PullBranch):
	// when the branch has opted out, opening a shift prints nothing. Returned as
	// a non-fatal "disabled" status the caller already tolerates.
	if s.shopSetting("print_shift_open_report", "true") == "false" {
		writeJSON(w, http.StatusOK, map[string]any{
			"status": "disabled",
			"detail": "print_shift_open_report is off",
		})
		return
	}

	// Device name = the POS terminal that opened the shift, NOT the workstation.
	// pos-web sends its own device name; fall back to the local devices mirror
	// keyed by the authenticated device id when the client didn't send one.
	deviceName := strings.TrimSpace(body.DeviceName)
	if deviceName == "" {
		if dev, ok := DeviceFromContext(r.Context()); ok {
			deviceName = s.deviceNameByID(dev.ID)
		}
	}

	info, err := s.buildShiftOpenReport(body.SessionID, deviceName)
	if err == sql.ErrNoRows {
		writeError(w, http.StatusNotFound, "session not found")
		return
	}
	if err != nil {
		writeServerError(w, r, err)
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

	config := service.PrintJobConfig{
		PaperWidth: 42,
		// #2084 — máy in tự khai vừa bao nhiêu ký tự một dòng. Thiếu dòng này
		// thì phép kẹp trong `resolvePrintWidth` không có gì để kẹp, và phiếu
		// mở ca in tràn trên giấy 58mm y như trước.
		PhysicalWidth: rp.CharWidth(),
		StoreName:     s.storeName(),
		StoreSubName:  s.settingValue("workstation_brand_name"),
		// Resolved HQ→Shop→workstation-override chain (see printLabelLocale),
		// same source every other slip type uses. ja/en/vi.
		Locale: s.printLabelLocale(),
	}
	// plan-053 T3.6 tầng 2 (#1914) — call site 7/13.
	slip, templateVersion := s.renderMoneySlip(
		service.NewShiftOpenRenderData(config, *info),
		service.PrintRenderProfileFor(rp.Profile(), ""),
		config.Locale,
		func() []byte { return service.FormatShiftOpenReport(config, *info) },
	)

	printErr := rp.Connect()
	if printErr == nil {
		defer rp.Disconnect()
		printErr = rp.Print(slip)
	}

	// plan-052 T1.2 — a 精算/Z report that never printed is a shift with no
	// paper trail; the ledger makes that visible instead of silent.
	s.journalPrintFor(r, rp, printjob.Entry{
		Kind:            printjob.KindReport,
		RequestedVia:    "pos",
		TemplateVersion: templateVersion,
		Payload:         map[string]any{"template": "shift_open_report", "ref": body.SessionID},
	}, printErr)

	if printErr != nil {
		writeServerError(w, r, printErr)
		return
	}

	s.auditLogPOS(r, "till.shift_open_report_printed", "till_session", body.SessionID, auditDetails(map[string]any{
		"session_id": body.SessionID,
	}))

	writeJSON(w, http.StatusOK, map[string]any{
		"status":        "ok",
		"slips_printed": 1,
	})
}

// deviceNameByID resolves a device id to its human name from the local devices
// mirror (synced from Cloud on auth). Returns "" if unknown / table absent —
// the query error is intentionally swallowed.
func (s *Server) deviceNameByID(id string) string {
	if id == "" {
		return ""
	}
	var name string
	_ = s.db.QueryRow(`SELECT name FROM devices WHERE id = ?`, id).Scan(&name)
	return name
}

// buildShiftOpenReport aggregates a session's OPENING data into a
// ShiftOpenReportInfo from local SQLite. deviceName is the POS terminal name
// (resolved by the handler). Returns sql.ErrNoRows when the session is unknown
// locally.
func (s *Server) buildShiftOpenReport(sessionID, deviceName string) (*service.ShiftOpenReportInfo, error) {
	var openingFloat float64
	var openedAt, currency string
	var operator, note sql.NullString
	// Operator = the explicit opener_name if present, else resolve opened_by_id
	// through the local staff mirror (a staff picked by id carries no name on the
	// row). LEFT JOIN so a session with neither still returns (operator = NULL).
	err := s.db.QueryRow(`
		SELECT ts.opening_float_amount, ts.opened_at,
		       COALESCE(NULLIF(ts.opener_name, ''), st.full_name),
		       ts.default_currency_code, ts.opening_note
		FROM till_sessions ts
		LEFT JOIN staff st ON st.id = ts.opened_by_id
		WHERE ts.id = ?`, sessionID).Scan(
		&openingFloat, &openedAt, &operator, &currency, &note)
	if err != nil {
		return nil, err // sql.ErrNoRows bubbles up to a 404
	}

	loc := s.shopLocation()
	info := &service.ShiftOpenReportInfo{
		DeviceName:   deviceName,
		OpenedAt:     fmtShiftTime(openedAt, loc),
		OpeningFloat: roundInt(openingFloat),
		Currency:     currency,
	}
	if operator.Valid {
		info.Operator = operator.String
	}
	if note.Valid {
		info.Note = note.String
	}

	// ─── opening cash breakdown (金種) ───
	// List EVERY active denomination for the shift's currency (same set the
	// cashier saw in the count UI — see handleLocalPosTillDenominations), LEFT
	// JOINing the opening counts so a denomination the cashier didn't enter
	// still prints with quantity 0 / amount 0 rather than being omitted.
	if rows, qerr := s.db.Query(`
		SELECT d.value,
		       COALESCE(c.quantity, 0),
		       COALESCE(c.subtotal_amount, 0)
		FROM denominations d
		LEFT JOIN till_cash_denomination_counts c
		       ON c.denomination_id = d.id
		      AND c.session_id = ?
		      AND c.phase = 'opening'
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

	return info, nil
}
