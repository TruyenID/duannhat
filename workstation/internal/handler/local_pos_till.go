package handler

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/google/uuid"
)

// ============================================================================
// Local cashier-shift surface — LAN-offline mở-ca / kết-ca / rút-két.
//
// Workstation owns the lifecycle in SQLite; sync UP fires async via the
// sync_queue. Pos-web URL surface is unchanged: requests come into
// /api/v1/pos/till/* and pos-web sees a normal Cloud-shape response.
//
// Endpoints handled here (registered in routes.go BEFORE the catch-all
// cloud proxy):
//
//   GET    /api/v1/pos/till/current
//   GET    /api/v1/pos/till/gap-preview
//   GET    /api/v1/pos/till/unresolved-orders
//   GET    /api/v1/pos/till/denominations
//   GET    /api/v1/pos/till/tender-types
//   GET    /api/v1/pos/till/tender-categories
//   POST   /api/v1/pos/till/sessions
//   GET    /api/v1/pos/till/sessions/{id}
//   GET    /api/v1/pos/till/sessions/{id}/reconciliation
//   POST   /api/v1/pos/till/sessions/{id}/cash-events
//   POST   /api/v1/pos/till/sessions/{id}/draft
//   POST   /api/v1/pos/till/sessions/{id}/close
//   POST   /api/v1/pos/till/sessions/{id}/abandon
//
// "Authoritative" replica tables seeded by sync_pull (tills, denominations,
// till_tender_categories, till_tender_types). Sync UP queues:
//   shift.open / shift.cash_event / shift.draft / shift.close / shift.abandon
// ============================================================================

// ─── Read endpoints ─────────────────────────────────────────────────────────

func (s *Server) handleLocalPosTillCurrent(w http.ResponseWriter, r *http.Request) {
	till, err := s.loadTill()
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	if till == nil {
		writeJSON(w, http.StatusOK, map[string]any{"data": map[string]any{
			"till":         nil,
			"open_session": nil,
		}})
		return
	}

	var openSession map[string]any
	if till["current_session_id"] != nil {
		id, _ := till["current_session_id"].(string)
		sess, err := s.loadSession(id)
		if err == nil && sess != nil {
			openSession = sess
		}
	}

	writeJSON(w, http.StatusOK, map[string]any{"data": map[string]any{
		"till":         till,
		"open_session": openSession,
	}})
}

func (s *Server) handleLocalPosTillDenominations(w http.ResponseWriter, r *http.Request) {
	currency := r.URL.Query().Get("currency")
	q := `SELECT id, currency_code, value, kind, label, sort_order
	      FROM denominations WHERE is_active = 1`
	args := []any{}
	if currency != "" {
		q += " AND currency_code = ?"
		args = append(args, strings.ToUpper(currency))
	}
	q += " ORDER BY currency_code, sort_order, value DESC"

	rows, err := s.db.Query(q, args...)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var id, cur, kind string
		var label sql.NullString
		var value float64
		var sortOrder int
		if err := rows.Scan(&id, &cur, &value, &kind, &label, &sortOrder); err != nil {
			writeServerError(w, r, err)
			return
		}
		out = append(out, map[string]any{
			"id":            id,
			"currency_code": cur,
			"value":         value,
			"kind":          kind,
			"label":         nullableStringValue(label),
			"sort_order":    sortOrder,
		})
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": out})
}

// Tender masters for the close screen's reconcile grid and the POS payment
// dialog's brand chips.
//
// `name` is resolved PER REQUEST from Accept-Language, not once at pull time.
// One workstation serves every terminal on the floor and each has its own
// cashier, so a single mirrored string would have to be somebody's wrong
// language — and was: the sync client sends no Accept-Language, so Cloud
// resolved these against `config('app.locale')` and a cashier who had picked
// 日本語 read "Credit" / "Transit IC". The per-locale columns come from the
// same feed; the base column stays the fallback for anything untranslated.
//
// dataLocaleFromRequest, NOT localeFromRequest: a caller that states no
// language gets the untranslated base column, never a silent "ja".
func (s *Server) handleLocalPosTillTenderTypes(w http.ResponseWriter, r *http.Request) {
	nameExpr := localizedNameExpr("", "name", dataLocaleFromRequest(r))
	rows, err := s.db.Query(`
		SELECT id, tender_key, ` + nameExpr + `, category, parent_tender_key,
		       currency_code, payment_method_code,
		       is_expected_anchor, requires_terminal_total, sort_order
		FROM till_tender_types WHERE is_active = 1
		ORDER BY sort_order, tender_key`)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var id, key, name, category, currency string
		var parent, methodCode sql.NullString
		var anchor, terminal, sortOrder int
		if err := rows.Scan(&id, &key, &name, &category, &parent, &currency,
			&methodCode, &anchor, &terminal, &sortOrder); err != nil {
			writeServerError(w, r, err)
			return
		}
		out = append(out, map[string]any{
			"id":                      id,
			"tender_key":              key,
			"name":                    name,
			"category":                category,
			"parent_tender_key":       nullableStringValue(parent),
			"currency_code":           currency,
			"payment_method_code":     nullableStringValue(methodCode),
			"is_expected_anchor":      anchor == 1,
			"requires_terminal_total": terminal == 1,
			"sort_order":              sortOrder,
		})
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": out})
}

func (s *Server) handleLocalPosTillTenderCategories(w http.ResponseWriter, r *http.Request) {
	rows, err := s.db.Query(`
		SELECT id, key, name, sort_order, is_system
		FROM till_tender_categories
		ORDER BY sort_order, key`)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var id, key, name string
		var sortOrder, system int
		if err := rows.Scan(&id, &key, &name, &sortOrder, &system); err != nil {
			writeServerError(w, r, err)
			return
		}
		out = append(out, map[string]any{
			"id":         id,
			"key":        key,
			"name":       name,
			"sort_order": sortOrder,
			"is_system":  system == 1,
		})
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": out})
}

func (s *Server) handleLocalPosTillSessionShow(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	sess, err := s.loadSession(id)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	if sess == nil {
		writeError(w, http.StatusNotFound, "session not found")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": sess})
}

// ─── Write endpoints ────────────────────────────────────────────────────────

type openShiftInput struct {
	TillCode      string `json:"till_code"`
	CurrencyCode  string `json:"currency_code"`
	OpeningCounts []struct {
		DenominationID string `json:"denomination_id"`
		Quantity       int    `json:"quantity"`
	} `json:"opening_counts"`
	OpeningNote string `json:"opening_note"`
	OpenedByID  string `json:"opened_by_id"`
	OpenerName  string `json:"opener_name"`
	// plan-044 R2 — gap reconciliation at open. The cashier confirms which
	// NULL-attributed close-gap payments (from GET /pos/till/gap-preview) belong
	// to THIS shift; those ids are stamped to the new session locally + synced UP.
	// gap_cash_held_separately_ack is the required UI acknowledgement that any
	// claimed cash was physically held aside (not folded into the opening float),
	// so re-attributing it here can't double-count against the drawer.
	ClaimedGapPaymentIDs     []string `json:"claimed_gap_payment_ids"`
	GapCashHeldSeparatelyAck bool     `json:"gap_cash_held_separately_ack"`
}

func (s *Server) handleLocalPosTillOpenSession(w http.ResponseWriter, r *http.Request) {
	var in openShiftInput
	if err := readJSON(r, &in); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	if len(in.OpeningCounts) == 0 {
		writeError(w, http.StatusBadRequest, "opening_counts required")
		return
	}

	till, err := s.loadTill()
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	if till == nil {
		writeError(w, http.StatusFailedDependency, "till has not synced from cloud yet; check connectivity then retry")
		return
	}

	if till["current_session_id"] != nil {
		writeJSON(w, http.StatusConflict, map[string]any{
			"message": "A shift is already open on this till.",
			"code":    "SHIFT_ALREADY_OPEN",
		})
		return
	}

	// Compute opening float = Σ denomination.value × quantity.
	float := 0.0
	type countRow struct {
		ID             string
		DenominationID string
		Quantity       int
		Subtotal       float64
	}
	counts := []countRow{}
	for _, c := range in.OpeningCounts {
		var value float64
		err := s.db.QueryRow("SELECT value FROM denominations WHERE id = ?", c.DenominationID).Scan(&value)
		if err != nil {
			writeError(w, http.StatusBadRequest, fmt.Sprintf("unknown denomination_id %q", c.DenominationID))
			return
		}
		subtotal := value * float64(c.Quantity)
		float += subtotal
		counts = append(counts, countRow{
			ID:             uuid.NewString(),
			DenominationID: c.DenominationID,
			Quantity:       c.Quantity,
			Subtotal:       subtotal,
		})
	}

	now := time.Now().UTC()
	currency := strings.ToUpper(in.CurrencyCode)
	if currency == "" {
		if c, ok := till["default_currency_code"].(string); ok {
			currency = c
		} else {
			currency = "JPY"
		}
	}

	sessionID := uuid.NewString()
	tillID, _ := till["id"].(string)
	branchID, _ := till["branch_id"].(string)
	sessionCode := s.generateSessionCode(now)

	// Plan-046 (T5.3, R1) — chain continuation. Continue the chain iff the till's
	// most-recent terminal session was a handover; else start a fresh chain. P7-A:
	// the chain columns are WRITTEN here at open (else the local chain never forms).
	chainID := uuid.NewString()
	chainSeq := 1
	continuesChain := false
	if pcid, pseq, pkind, ok := s.latestTerminalChainForTill(tillID); ok && pkind == "handover" {
		if pcid != "" {
			chainID = pcid
		}
		chainSeq = pseq + 1
		continuesChain = true
	}

	tx, err := s.db.Conn().Begin()
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	defer tx.Rollback()

	if _, err := tx.Exec(`
		INSERT INTO till_sessions (id, session_code, status, business_date,
		    default_currency_code, opening_float_amount, opening_note,
		    opened_by_id, opener_name, opened_at, till_id, branch_id,
		    chain_id, chain_sequence)
		VALUES (?, ?, 'open', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		sessionID, sessionCode, now.Format("2006-01-02"), currency, float,
		nullIfEmpty(in.OpeningNote), nullIfEmpty(in.OpenedByID),
		nullIfEmpty(in.OpenerName), now.Format(time.RFC3339Nano),
		tillID, branchID, chainID, chainSeq); err != nil {
		writeServerError(w, r, err)
		return
	}

	for _, c := range counts {
		if _, err := tx.Exec(`
			INSERT INTO till_cash_denomination_counts (id, session_id,
			    denomination_id, phase, quantity, subtotal_amount)
			VALUES (?, ?, ?, 'opening', ?, ?)`,
			c.ID, sessionID, c.DenominationID, c.Quantity, c.Subtotal); err != nil {
			writeServerError(w, r, err)
			return
		}
	}

	if _, err := tx.Exec(
		"UPDATE tills SET current_session_id = ? WHERE id = ?",
		sessionID, tillID); err != nil {
		writeServerError(w, r, err)
		return
	}

	// plan-044 R2 (T-R2.10) — attribute the cashier-confirmed gap payments to
	// this shift. Eligibility mirrors handleLocalPosTillGapPreview exactly so a
	// claim can never stamp a row the preview didn't offer. Returns the ids
	// actually stamped (for the sync-UP enqueue after commit).
	claimed, err := s.stampClaimedGapPayments(tx, sessionID, in.ClaimedGapPaymentIDs, now)
	if err != nil {
		writeServerError(w, r, err)
		return
	}

	if err := tx.Commit(); err != nil {
		writeServerError(w, r, err)
		return
	}

	s.enqueueShiftOpen(r, sessionID, in, float, sessionCode, currency, now, tillID, branchID, chainID, chainSeq)
	// T-R2.D2.2 — propagate each local gap-claim to Cloud (endpoint D). Enqueued
	// after shift.open so the session exists on Cloud first; endpoint D is
	// idempotent + branch-guarded so an early/duplicate arrival still converges.
	s.enqueueGapAttributions(r, sessionID, claimed)

	sess, _ := s.loadSession(sessionID)
	if sess != nil {
		// Plan-046 — pos-web shows the "Tiếp tục chuỗi — ca N" banner + blind
		// re-count when this open continued a chain.
		sess["continues_chain"] = continuesChain
	}
	s.auditLogPOS(r, "till.open", "till_session", sessionID,
		fmt.Sprintf(`{"session_code":"%s","float":%v}`, sessionCode, float))
	if len(claimed) > 0 {
		s.auditLogPOS(r, "till.gap_claim", "till_session", sessionID,
			auditDetails(map[string]any{
				"claimed_payment_ids": claimed,
				"cash_held_ack":       in.GapCashHeldSeparatelyAck,
			}))
	}
	writeJSON(w, http.StatusCreated, map[string]any{"data": sess})
}

type cashEventInput struct {
	EventType     string  `json:"event_type"`
	Amount        float64 `json:"amount"`
	CurrencyCode  string  `json:"currency_code"`
	Reason        string  `json:"reason"`
	ReferenceNo   string  `json:"reference_no"`
	PerformedByID string  `json:"performed_by_id"`
	OccurredAt    string  `json:"occurred_at"`
}

func (s *Server) handleLocalPosTillCashEvent(w http.ResponseWriter, r *http.Request) {
	sessionID := r.PathValue("id")
	var in cashEventInput
	if err := readJSON(r, &in); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	if in.Amount <= 0 || in.EventType == "" {
		writeError(w, http.StatusBadRequest, "event_type and positive amount required")
		return
	}
	switch in.EventType {
	case "paid_in", "paid_out", "loan_from_safe", "pickup_to_safe":
		// ok
	default:
		writeError(w, http.StatusBadRequest, "invalid event_type")
		return
	}

	status, currency, err := s.sessionStatusAndCurrency(sessionID)
	if err != nil {
		writeError(w, http.StatusNotFound, "session not found")
		return
	}
	if status != "open" {
		writeJSON(w, http.StatusConflict, map[string]any{
			"message": "Cash events can only be recorded on an open shift.",
			"code":    "SHIFT_NOT_OPEN",
		})
		return
	}

	now := time.Now().UTC()
	occurredAt := now.Format(time.RFC3339Nano)
	if in.OccurredAt != "" {
		occurredAt = in.OccurredAt
	}
	if in.CurrencyCode == "" {
		in.CurrencyCode = currency
	}

	eventID := uuid.NewString()
	if _, err := s.db.Exec(`
		INSERT INTO till_cash_events (id, session_id, event_type, amount,
		    currency_code, reason, reference_no, performed_by_id, occurred_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		eventID, sessionID, in.EventType, in.Amount,
		strings.ToUpper(in.CurrencyCode), nullIfEmpty(in.Reason),
		nullIfEmpty(in.ReferenceNo), nullIfEmpty(in.PerformedByID),
		occurredAt); err != nil {
		writeServerError(w, r, err)
		return
	}

	s.enqueueShiftCashEvent(r, sessionID, eventID, in, occurredAt)

	s.auditLogPOS(r, "till.cash_event", "till_cash_event", eventID,
		fmt.Sprintf(`{"event_type":"%s","amount":%v}`, in.EventType, in.Amount))

	writeJSON(w, http.StatusCreated, map[string]any{"data": map[string]any{
		"id":              eventID,
		"session_id":      sessionID,
		"event_type":      in.EventType,
		"amount":          in.Amount,
		"currency_code":   strings.ToUpper(in.CurrencyCode),
		"reason":          in.Reason,
		"reference_no":    in.ReferenceNo,
		"occurred_at":     occurredAt,
		"performed_by_id": in.PerformedByID,
	}})
}

type draftInput struct {
	ClosingCounts []struct {
		DenominationID string `json:"denomination_id"`
		Quantity       int    `json:"quantity"`
	} `json:"closing_counts"`
	TenderDetails []tenderDetailInput `json:"tender_details"`
	ClosingNote   string              `json:"closing_note"`

	// ClosingCashAdjustment is 「tiền lẻ」 — cash the drawer holds below the
	// smallest denomination the count sheet can express. pos-web has always sent
	// it and Cloud has always used it (`countedCash = denomCash + adjustment`,
	// TillSessionService.php); this struct simply had no field for it, so
	// `encoding/json` dropped it on the floor without a word.
	//
	// That silence is the whole defect (#1986). The local settle is not a
	// preview — it computes the variance, GATES the close on it, and writes the
	// immutable `settlement_snapshot` that plan-046 sums into the chain
	// aggregate. Dropping the adjustment made every one of those wrong by
	// exactly its amount, and wrong in the direction that DEMANDS A REASON for a
	// discrepancy that does not exist: the cashier is asked to explain a
	// shortfall the drawer does not have.
	//
	// It also did not stay local. `enqueueShiftSettle` sent the counts without
	// the adjustment, so Cloud recomputed the same wrong figure from its own
	// `?? 0` default and the R7 write-back cemented it. Both sides agreed, and
	// agreement is exactly what makes this class of bug survive: there is no
	// disagreement anywhere for a reconciliation to catch.
	//
	// A POINTER, not a float. `0` and "not sent" are different: an older pos-web
	// build that omits the key must not be read as "the cashier declared zero
	// loose change", which is a claim about the drawer.
	ClosingCashAdjustment *float64 `json:"closing_cash_adjustment"`
}

// cashAdjustment returns the declared loose-change amount, or 0 when the client
// sent none. Negative is clamped away: Cloud validates `min:0`, and a negative
// adjustment would silently REDUCE counted cash below what the cashier actually
// counted — a shortfall invented by the software.
func (in draftInput) cashAdjustment() float64 {
	if in.ClosingCashAdjustment == nil || *in.ClosingCashAdjustment < 0 {
		return 0
	}

	return *in.ClosingCashAdjustment
}

type tenderDetailInput struct {
	TenderKey          string   `json:"tender_key"`
	GrossAmount        *float64 `json:"gross_amount"`
	CancelAmount       *float64 `json:"cancel_amount"`
	TerminalBatchTotal *float64 `json:"terminal_batch_total"`
	VarianceReason     *string  `json:"variance_reason"`
}

func (s *Server) handleLocalPosTillDraft(w http.ResponseWriter, r *http.Request) {
	sessionID := r.PathValue("id")
	var in draftInput
	if err := readJSON(r, &in); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}

	// Persist intermediate state locally so refresh keeps the cashier's
	// input. Mirror status → 'closing' (matches Cloud's state machine).
	if _, err := s.db.Exec(
		"UPDATE till_sessions SET status='closing', closing_note=?, closing_cash_adjustment_amount=?, updated_at=datetime('now') WHERE id=? AND status IN ('open','closing')",
		nullIfEmpty(in.ClosingNote), in.ClosingCashAdjustment, sessionID); err != nil {
		writeServerError(w, r, err)
		return
	}

	// Drafts intentionally do NOT sync UP — they're an editing snapshot.
	// Cloud only sees the final close payload. We still persist the
	// closing_counts so a refresh+close uses the user's last input.
	if err := s.replaceClosingCounts(sessionID, in.ClosingCounts); err != nil {
		writeServerError(w, r, err)
		return
	}

	sess, _ := s.loadSession(sessionID)
	writeJSON(w, http.StatusOK, map[string]any{"data": sess})
}

// handleLocalPosTillClose serves POST /pos/till/sessions/{id}/close locally —
// a FINAL close that ends the chain.
func (s *Server) handleLocalPosTillClose(w http.ResponseWriter, r *http.Request) {
	s.settleLocalShift(w, r, "final")
}

// handleLocalPosTillHandover serves POST /pos/till/sessions/{id}/handover locally
// (plan-046 T5.1) — settles the shift but keeps the chain open. Mirrors the close
// settle body exactly; only settlement_kind + the sync op differ.
func (s *Server) handleLocalPosTillHandover(w http.ResponseWriter, r *http.Request) {
	s.settleLocalShift(w, r, "handover")
}

// settleLocalShift is the shared offline settle body for close(final) and
// handover (plan-046 T5.1/T5.2). It stores settlement_kind + the provisional
// settlement_snapshot (R7) and enqueues the kind-aware sync-UP.
func (s *Server) settleLocalShift(w http.ResponseWriter, r *http.Request, kind string) {
	sessionID := r.PathValue("id")
	var in draftInput
	if err := readJSON(r, &in); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}

	status, _, err := s.sessionStatusAndCurrency(sessionID)
	if err != nil {
		writeError(w, http.StatusNotFound, "session not found")
		return
	}
	if status == "settled" || status == "abandoned" {
		writeJSON(w, http.StatusConflict, map[string]any{
			"message": "Shift is already finalized.",
			"code":    "SHIFT_ALREADY_FINALIZED",
		})
		return
	}

	recon, err := s.reconcileSession(sessionID)
	if err != nil {
		writeServerError(w, r, err)
		return
	}

	// Compute counted cash from submitted closing_counts.
	counted := 0.0
	for _, c := range in.ClosingCounts {
		var value float64
		if err := s.db.QueryRow("SELECT value FROM denominations WHERE id = ?", c.DenominationID).Scan(&value); err != nil {
			writeError(w, http.StatusBadRequest, fmt.Sprintf("unknown denomination_id %q", c.DenominationID))
			return
		}
		counted += value * float64(c.Quantity)
	}
	// `denomCash + adjustment` — the same formula as Cloud
	// (TillSessionService::settle). The adjustment is the loose change no
	// denomination row can express; leaving it out understated counted cash by
	// its amount and made the tolerance gate below demand a reason for a
	// shortfall that was never in the drawer (#1986).
	counted = round2(counted + in.cashAdjustment())
	cashVariance := round2(counted - recon.ExpectedCash)

	// WS-6 — offline variance-reason enforcement (#817 Phase B). Cloud is the
	// authority (settleFromWorkstation 422s an unreasoned out-of-tolerance
	// close), but when Cloud is unreachable the WS is the ONLY gate at close
	// time — so enforce the same rule here: a cash or per-tender variance beyond
	// tolerance may not close without a reason. Cash reason → closing_note;
	// per-tender reason → tender_details[].variance_reason. counted_cash stays
	// the device drawer figure (Phase A signed-adjustment invariant). This runs
	// BEFORE any settlement write so a rejected close mutates nothing.
	var tolerance float64
	_ = s.db.QueryRow("SELECT variance_tolerance_amount FROM tills LIMIT 1").Scan(&tolerance)
	overTolerance := func(v float64) bool {
		if v < 0 {
			v = -v
		}
		return v > tolerance
	}
	if overTolerance(cashVariance) && strings.TrimSpace(in.ClosingNote) == "" {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{
			"message": "Cash variance exceeds tolerance; a reason (closing_note) is required.",
			"code":    "VARIANCE_REASON_REQUIRED",
		})
		return
	}
	for _, td := range in.TenderDetails {
		expected, ok := recon.TenderExpected[td.TenderKey]
		if !ok {
			continue
		}
		declared := pickFloat(td.GrossAmount) - pickFloat(td.CancelAmount)
		reason := ""
		if td.VarianceReason != nil {
			reason = strings.TrimSpace(*td.VarianceReason)
		}
		if overTolerance(round2(declared-expected)) && reason == "" {
			writeJSON(w, http.StatusUnprocessableEntity, map[string]any{
				"message": fmt.Sprintf("Variance for tender %q exceeds tolerance; a reason is required.", td.TenderKey),
				"code":    "VARIANCE_REASON_REQUIRED",
			})
			return
		}
	}

	now := time.Now().UTC()

	// Plan-046 (T5.4) — provisional snapshot from the same reconcile + shared
	// per-rate tax, over the window [opened_at, now]. Built BEFORE status flips
	// to settled so the paid-orders window is the shift's own. The submitted
	// closing counts are handed in explicitly: they are not persisted to
	// till_cash_denomination_counts until the transaction below, so the snapshot
	// must read the 金種 mix from the request, not from the (still-stale) table.
	snapshotJSON, serr := s.buildLocalSettlementSnapshot(sessionID, recon, counted, cashVariance, now, in.ClosingCounts)
	if serr != nil {
		writeServerError(w, r, serr)
		return
	}

	tx, err := s.db.Conn().Begin()
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	defer tx.Rollback()

	if _, err := tx.Exec(`
		UPDATE till_sessions SET status='settled', settlement_kind=?,
		    settlement_snapshot=?, closed_at=?, closing_note=?,
		    counted_cash=?, cash_variance=?,
		    closing_cash_adjustment_amount=?, updated_at=datetime('now')
		WHERE id=?`,
		kind, snapshotJSON,
		now.Format(time.RFC3339Nano), nullIfEmpty(in.ClosingNote),
		counted, cashVariance, in.ClosingCashAdjustment, sessionID); err != nil {
		writeServerError(w, r, err)
		return
	}

	// Replace closing counts.
	if _, err := tx.Exec(
		"DELETE FROM till_cash_denomination_counts WHERE session_id=? AND phase='closing'",
		sessionID); err != nil {
		writeServerError(w, r, err)
		return
	}
	for _, c := range in.ClosingCounts {
		var value float64
		if err := tx.QueryRow("SELECT value FROM denominations WHERE id = ?", c.DenominationID).Scan(&value); err != nil {
			writeServerError(w, r, err)
			return
		}
		if _, err := tx.Exec(`
			INSERT INTO till_cash_denomination_counts (id, session_id,
			    denomination_id, phase, quantity, subtotal_amount)
			VALUES (?, ?, ?, 'closing', ?, ?)`,
			uuid.NewString(), sessionID, c.DenominationID, c.Quantity,
			value*float64(c.Quantity)); err != nil {
			writeServerError(w, r, err)
			return
		}
	}

	// Materialize settlement details — one row per tender_key submitted.
	if _, err := tx.Exec(
		"DELETE FROM till_settlement_tender_details WHERE session_id=?",
		sessionID); err != nil {
		writeServerError(w, r, err)
		return
	}
	for _, td := range in.TenderDetails {
		gross := pickFloat(td.GrossAmount)
		cancel := pickFloat(td.CancelAmount)
		declared := gross - cancel

		var category, currency string
		err := tx.QueryRow(
			"SELECT category, currency_code FROM till_tender_types WHERE tender_key=?",
			td.TenderKey).Scan(&category, &currency)
		if err != nil {
			category = ""
			currency = "JPY"
		}

		var expected *float64
		if e, ok := recon.TenderExpected[td.TenderKey]; ok {
			expected = &e
		}
		var variance *float64
		if expected != nil {
			v := round2(declared - *expected)
			variance = &v
		}

		if _, err := tx.Exec(`
			INSERT INTO till_settlement_tender_details (id, session_id,
			    tender_key, category, currency_code, expected_amount,
			    declared_gross_amount, declared_cancel_amount,
			    declared_amount, terminal_batch_total, variance_amount,
			    variance_reason)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
			uuid.NewString(), sessionID, td.TenderKey, category, currency,
			expected, gross, cancel, declared,
			pickFloatPtr(td.TerminalBatchTotal),
			variance, pickStringPtr(td.VarianceReason)); err != nil {
			writeServerError(w, r, err)
			return
		}
	}

	// Clear till.current_session_id — frees the till for the next shift.
	if _, err := tx.Exec(
		"UPDATE tills SET current_session_id=NULL WHERE current_session_id=?",
		sessionID); err != nil {
		writeServerError(w, r, err)
		return
	}

	if err := tx.Commit(); err != nil {
		writeServerError(w, r, err)
		return
	}

	s.enqueueShiftSettle(r, sessionID, in, counted, cashVariance, now, kind)
	auditAction := "till.close"
	if kind == "handover" {
		auditAction = "till.handover"
	}
	s.auditLogPOS(r, auditAction, "till_session", sessionID,
		fmt.Sprintf(`{"kind":%q,"counted":%v,"variance":%v}`, kind, counted, cashVariance))

	sess, _ := s.loadSession(sessionID)
	writeJSON(w, http.StatusOK, map[string]any{"data": sess})
}

type abandonInput struct {
	AbandonReason string `json:"abandon_reason"`
}

func (s *Server) handleLocalPosTillAbandon(w http.ResponseWriter, r *http.Request) {
	sessionID := r.PathValue("id")
	var in abandonInput
	if err := readJSON(r, &in); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}

	// Backend block: only allow abandon when no payments have been stamped
	// against this shift. Workstation mirrors the rule against local
	// payments+orders within the shift window.
	var paymentCount int
	if err := s.db.QueryRow(`
		SELECT COUNT(*) FROM payments p
		WHERE p.created_at >= (SELECT opened_at FROM till_sessions WHERE id = ?)
		  AND p.status IN ('pending','confirmed','succeeded')`, sessionID).Scan(&paymentCount); err == nil && paymentCount > 0 {
		writeJSON(w, http.StatusConflict, map[string]any{
			"message": "Cannot abandon a shift that already has payments.",
			"code":    "SHIFT_HAS_PAYMENTS",
		})
		return
	}

	now := time.Now().UTC()
	tx, err := s.db.Conn().Begin()
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	defer tx.Rollback()

	if _, err := tx.Exec(`
		UPDATE till_sessions SET status='abandoned', closed_at=?,
		    abandon_reason=?, updated_at=datetime('now')
		WHERE id=? AND status IN ('open','closing')`,
		now.Format(time.RFC3339Nano), nullIfEmpty(in.AbandonReason),
		sessionID); err != nil {
		writeServerError(w, r, err)
		return
	}
	if _, err := tx.Exec(
		"UPDATE tills SET current_session_id=NULL WHERE current_session_id=?",
		sessionID); err != nil {
		writeServerError(w, r, err)
		return
	}
	if err := tx.Commit(); err != nil {
		writeServerError(w, r, err)
		return
	}

	s.enqueueShiftAbandon(r, sessionID, in.AbandonReason, now)
	s.auditLogPOS(r, "till.abandon", "till_session", sessionID,
		fmt.Sprintf(`{"reason":%q}`, in.AbandonReason))

	sess, _ := s.loadSession(sessionID)
	writeJSON(w, http.StatusOK, map[string]any{"data": sess})
}

// ─── Reconciliation ─────────────────────────────────────────────────────────

type reconResult struct {
	ExpectedCash     float64
	CashSales        float64
	PaidIn           float64
	PaidOut          float64
	OpeningFloat     float64
	CategoryExpected map[string]float64
	TenderExpected   map[string]float64
}

// stampClaimedGapPayments (plan-044 R2 T-R2.10) attributes the cashier-confirmed
// close-gap payments to the freshly-opened session. Eligibility MUST mirror
// handleLocalPosTillGapPreview exactly — NULL attribution, status pending/confirmed,
// created in the window (prev_end, opened_at] — so a claim can never stamp a row the
// preview didn't offer. Cash IS included (DESIGN R2 §3: it was held separately by
// staff, so it is claimable to this shift; the UI required the held-separately ack).
// ids that fall outside the window / are already attributed / have a non-claimable
// status are silently skipped — the update never nullifies or mis-stamps. Returns the
// ids actually stamped, which drive the payment.attribute sync-UP enqueue.
// latestTerminalClose returns the closed_at of the most recent terminal
// (settled/abandoned) session — the lower bound of every gap window.
//
// It picks the maximum in Go over normalizeInstant instead of letting SQLite do
// `ORDER BY substr(closed_at,1,19) DESC`. That ORDER BY is lexical, so the
// moment two rows disagree on format — one DATETIME `2026-08-13 02:00:00`, one
// ISO `2026-08-13T01:00:00` — the T-form sorts above every space-form of the
// same date and the WRONG session is chosen as "previous". Today local-born
// rows are format-uniform so it happens to be right; `PullTillSessions` upserts
// Cloud rows into the same table, so "happens to be" is the whole risk.
//
// Dropping `LIMIT 1` and scanning every terminal row is a DELIBERATE trade, not
// an oversight: with mixed formats there is no ORDER BY that sorts correctly, so
// the max has to be taken in Go. The bound is small and known — `till_sessions`
// grows ~2 rows/day/till and is never pruned, so a shop reaches ~1.5k rows after
// two years, read once per shift open and once per preview. If a shop ever runs
// enough tills to make that hurt, normalize `closed_at` on write instead of
// re-introducing a lexical ORDER BY.
func latestTerminalClose(q interface {
	Query(string, ...any) (*sql.Rows, error)
}) (string, bool, error) {
	rows, err := q.Query(`
		SELECT closed_at FROM till_sessions
		WHERE status IN ('settled','abandoned') AND closed_at IS NOT NULL AND closed_at != ''`)
	if err != nil {
		return "", false, err
	}
	defer rows.Close()

	best, bestN := "", ""
	for rows.Next() {
		var closedAt string
		if err := rows.Scan(&closedAt); err != nil {
			return "", false, err
		}
		n := normalizeInstant(closedAt)
		if n == "" {
			continue
		}
		if bestN == "" || n > bestN {
			best, bestN = closedAt, n
		}
	}
	if err := rows.Err(); err != nil {
		return "", false, err
	}

	return best, bestN != "", nil
}

func (s *Server) stampClaimedGapPayments(tx *sql.Tx, sessionID string, claimedIDs []string, openedAt time.Time) ([]string, error) {
	if len(claimedIDs) == 0 {
		return nil, nil
	}
	// prev_end bounds the window low side — same window as gap-preview, and it
	// must stay EXACTLY the same: a payment the preview showed but this refuses
	// to stamp is money the cashier ticked and the till never received.
	prevEnd, found, err := latestTerminalClose(tx)
	if err != nil {
		return nil, err
	}
	if !found {
		// No prior terminal session → the window is unbounded and nothing was a
		// "gap" payment.
		return nil, nil
	}
	lowerN := normalizeInstant(prevEnd)
	upperN := openedAt.UTC().Format("2006-01-02T15:04:05")

	placeholders := make([]string, len(claimedIDs))
	for i := range claimedIDs {
		placeholders[i] = "?"
	}
	inClause := strings.Join(placeholders, ",")

	// The window test is done in Go on normalizeInstant, NOT in SQL on
	// substr(created_at,1,19): the stored value may be a DATETIME with a space
	// while the bound is ISO with a T, and ' ' (0x20) < 'T' (0x54) at char 11
	// makes the comparison lexical nonsense — a payment taken AFTER the close
	// compares as BEFORE it and silently fails to be stamped.
	eligible := make([]string, 0, len(claimedIDs))
	{
		selArgs := make([]any, 0, len(claimedIDs))
		for _, id := range claimedIDs {
			selArgs = append(selArgs, id)
		}
		rows, err := tx.Query(`
			SELECT id, COALESCE(created_at,'') FROM payments
			WHERE id IN (`+inClause+`)
			  AND till_session_id IS NULL
			  AND status IN ('pending','confirmed','succeeded')`, selArgs...)
		if err != nil {
			return nil, err
		}
		for rows.Next() {
			var id, createdAt string
			if err := rows.Scan(&id, &createdAt); err != nil {
				rows.Close()
				return nil, err
			}
			n := normalizeInstant(createdAt)
			if n > lowerN && n <= upperN {
				eligible = append(eligible, id)
			}
		}
		if err := rows.Err(); err != nil {
			rows.Close()
			return nil, err
		}
		rows.Close()
	}
	if len(eligible) == 0 {
		return nil, nil
	}

	updPlaceholders := make([]string, len(eligible))
	for i := range eligible {
		updPlaceholders[i] = "?"
	}
	updArgs := make([]any, 0, len(eligible)+1)
	updArgs = append(updArgs, sessionID)
	for _, id := range eligible {
		updArgs = append(updArgs, id)
	}
	if _, err := tx.Exec(`
		UPDATE payments SET till_session_id = ?
		WHERE id IN (`+strings.Join(updPlaceholders, ",")+`)
		  AND till_session_id IS NULL
		  AND status IN ('pending','confirmed','succeeded')`, updArgs...); err != nil {
		return nil, err
	}

	// Read back exactly which claimed ids landed on this session → the sync-UP set.
	selArgs := make([]any, 0, len(claimedIDs)+1)
	selArgs = append(selArgs, sessionID)
	for _, id := range claimedIDs {
		selArgs = append(selArgs, id)
	}
	rows, err := tx.Query(`SELECT id FROM payments WHERE till_session_id = ? AND id IN (`+inClause+`)`, selArgs...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var applied []string
	for rows.Next() {
		var id string
		if err := rows.Scan(&id); err != nil {
			return nil, err
		}
		applied = append(applied, id)
	}
	return applied, rows.Err()
}

// enqueueGapAttributions (plan-044 R2 T-R2.D2.2) syncs each locally-claimed gap
// payment's new till_session_id UP to Cloud via
// POST /api/v1/workstation/payments/{cloud_id}/attribution. The session id is verbatim
// local↔cloud (Cloud upserts sessions by the workstation-supplied id), so only the
// payment needs a cloud_id remap — done inside handlePaymentAttribute. Best-effort:
// an enqueue error is logged, not fatal, and the payment resurfaces in the next shift's
// gap-preview so nothing is lost.
func (s *Server) enqueueGapAttributions(r *http.Request, sessionID string, paymentIDs []string) {
	if s.sync == nil || len(paymentIDs) == 0 {
		return
	}
	bearer := bearerFromRequest(r)
	for _, pid := range paymentIDs {
		payload := map[string]any{
			"bearer_token":    bearer,
			"till_session_id": sessionID,
		}
		if err := s.sync.Enqueue("payment", pid, "attribute", payload, 1); err != nil {
			slog.Error("enqueue payment.attribute", "err", err, "payment", pid, "session", sessionID)
		}
	}
}

// handleLocalPosTillGapPreview — plan-044 R2. Lists local NULL-attributed
// payments taken during the previous shift's close-gap (created_at > prev_end)
// so the cashier can reconcile + confirm which to claim at the next open.
// is_cash = payment_method == 'cash' (matches reconcileSession's drawer bucket,
// so it flags exactly the rows a naive re-stamp would double-count vs the
// opening float — DESIGN R2 §3). No prior terminal session -> empty (window
// unbounded). Served from the LOCAL replica so kiosk/customer LAN payments not
// yet synced UP are still visible (a Cloud-only query would miss them).
func (s *Server) handleLocalPosTillGapPreview(w http.ResponseWriter, r *http.Request) {
	currency := s.shopSetting("currency", "JPY")
	empty := map[string]any{"data": map[string]any{
		"previous_session": nil,
		"gap_window":       nil,
		"currency_code":    currency,
		"payments":         []map[string]any{},
		"totals":           map[string]any{"count": 0, "cash_amount": 0.0, "non_cash_amount": 0.0},
	}}

	// prev_end = latest close of a terminal (settled/abandoned) session. The
	// workstation stamps closed_at on both settle and abandon (local_pos_till).
	prevEndRaw, found, err := latestTerminalClose(s.db)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	if !found {
		writeJSON(w, http.StatusOK, empty)
		return
	}

	var prevID, prevCode sql.NullString
	if err := s.db.QueryRow(`
		SELECT id, session_code FROM till_sessions
		WHERE closed_at = ? AND status IN ('settled','abandoned')
		LIMIT 1`, prevEndRaw).Scan(&prevID, &prevCode); err != nil && err != sql.ErrNoRows {
		writeServerError(w, r, err)
		return
	}

	lowerN := normalizeInstant(prevEndRaw)
	nowN := time.Now().UTC().Format("2006-01-02T15:04:05")

	// The window test is in Go over normalizeInstant, NOT in SQL over
	// substr(p.created_at,1,19). Both sides must be normalized or neither: a
	// payment stored as `2026-08-13 02:00:00` compared against an ISO bound
	// `2026-08-13T01:00:00` loses at character 11 (' ' 0x20 < 'T' 0x54), so a
	// payment taken AFTER the close reads as before it and drops out of the gap
	// list — money the cashier is never offered a chance to attribute.
	rows, err := s.db.Query(`
		SELECT p.id, COALESCE(p.order_id,''), p.amount, p.payment_method,
		       COALESCE(p.created_at,''), COALESCE(o.order_code,'')
		FROM payments p
		LEFT JOIN orders o ON o.id = p.order_id
		WHERE p.till_session_id IS NULL
		  AND p.status IN ('pending','confirmed','succeeded')
		ORDER BY p.created_at`)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rows.Close()

	payments := []map[string]any{}
	var cashAmt, nonCashAmt float64
	for rows.Next() {
		var id, orderID, method, createdAt, orderCode string
		var amount float64
		if err := rows.Scan(&id, &orderID, &amount, &method, &createdAt, &orderCode); err != nil {
			writeServerError(w, r, err)
			return
		}
		if n := normalizeInstant(createdAt); n <= lowerN || n > nowN {
			continue
		}
		isCash := strings.EqualFold(method, "cash")
		if isCash {
			cashAmt += amount
		} else {
			nonCashAmt += amount
		}
		payments = append(payments, map[string]any{
			"id":          id,
			"order_id":    orderID,
			"order_code":  orderCode,
			"amount":      amount,
			"method_code": method,
			"is_cash":     isCash,
			"created_at":  createdAt,
		})
	}
	if err := rows.Err(); err != nil {
		writeServerError(w, r, err)
		return
	}

	writeJSON(w, http.StatusOK, map[string]any{"data": map[string]any{
		"previous_session": map[string]any{
			"id":           prevID.String,
			"session_code": prevCode.String,
			"ended_at":     prevEndRaw,
		},
		"gap_window":    map[string]any{"from": prevEndRaw, "to": time.Now().UTC().Format(time.RFC3339)},
		"currency_code": currency,
		"payments":      payments,
		"totals": map[string]any{
			"count":           len(payments),
			"cash_amount":     cashAmt,
			"non_cash_amount": nonCashAmt,
		},
	}})
}

// handleLocalPosTillUnresolvedOrders — #2696/#2716. Same JSON shape as Cloud
// TillSessionService::unresolvedOrdersPreview. Scan local orders still
// paying/checkout whose created_at is before the previous terminal close.
//
// Compare instants via normalizeInstant, not raw substr: a DATETIME with a
// space vs an ISO string with T is lexical (` ` < `T`) and would list EVERY
// order, including ones opened after the close. Fail-open: no prior session
// → empty list, same convention as gap-preview.
func (s *Server) handleLocalPosTillUnresolvedOrders(w http.ResponseWriter, r *http.Request) {
	currency := s.shopSetting("currency", "JPY")
	empty := map[string]any{"data": map[string]any{
		"previous_session": nil,
		"currency_code":    currency,
		"orders":           []map[string]any{},
		"totals":           map[string]any{"count": 0, "outstanding_amount": 0.0},
	}}

	// Same selector as gap-preview — deliberately the SAME function, not a
	// second query that happens to agree. These two endpoints render adjacent
	// screens of one shift-open flow; if they disagree on which session was
	// "previous", the cashier sees two different windows for the same moment.
	prevEndRaw, found, err := latestTerminalClose(s.db)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	if !found {
		writeJSON(w, http.StatusOK, empty)
		return
	}

	var prevID, prevCode sql.NullString
	if err := s.db.QueryRow(`
		SELECT id, session_code FROM till_sessions
		WHERE closed_at = ? AND status IN ('settled','abandoned')
		LIMIT 1`, prevEndRaw).Scan(&prevID, &prevCode); err != nil && err != sql.ErrNoRows {
		writeServerError(w, r, err)
		return
	}

	cutoff := normalizeInstant(prevEndRaw)

	paidByOrder := map[string]float64{}
	payRows, err := s.db.Query(`
		SELECT order_id, COALESCE(SUM(amount - COALESCE(refunded_amount,0)),0)
		FROM payments
		WHERE (refund_of_id IS NULL OR refund_of_id = '')
		  AND status IN ('succeeded','confirmed')
		GROUP BY order_id`)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	for payRows.Next() {
		var orderID string
		var paid float64
		if err := payRows.Scan(&orderID, &paid); err != nil {
			payRows.Close()
			writeServerError(w, r, err)
			return
		}
		paidByOrder[orderID] = paid
	}
	if err := payRows.Err(); err != nil {
		payRows.Close()
		writeServerError(w, r, err)
		return
	}
	payRows.Close()

	rows, err := s.db.Query(`
		SELECT id, COALESCE(order_code,''), status, total_amount,
		       table_id, COALESCE(created_at, opened_at, '')
		FROM orders
		WHERE status IN ('paying','checkout')
		ORDER BY created_at`)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rows.Close()

	orders := []map[string]any{}
	var outstandingTotal float64
	for rows.Next() {
		var id, code, status, createdAt string
		var total float64
		var tableID sql.NullString
		if err := rows.Scan(&id, &code, &status, &total, &tableID, &createdAt); err != nil {
			writeServerError(w, r, err)
			return
		}
		if normalizeInstant(createdAt) >= cutoff {
			continue
		}
		paid := paidByOrder[id]
		outstanding := total - paid
		if outstanding < 0 {
			outstanding = 0
		}
		outstanding = float64(int(outstanding*100+0.5)) / 100
		outstandingTotal += outstanding
		var orderCode any
		if code == "" {
			orderCode = nil
		} else {
			orderCode = code
		}
		orders = append(orders, map[string]any{
			"id":                 id,
			"order_code":         orderCode,
			"status":             status,
			"total_amount":       total,
			"paid_amount":        paid,
			"outstanding_amount": outstanding,
			"table_released":     !tableID.Valid || tableID.String == "",
			"created_at":         createdAt,
		})
	}
	if err := rows.Err(); err != nil {
		writeServerError(w, r, err)
		return
	}

	writeJSON(w, http.StatusOK, map[string]any{"data": map[string]any{
		"previous_session": map[string]any{
			"id":           prevID.String,
			"session_code": prevCode.String,
			"ended_at":     prevEndRaw,
		},
		"currency_code": currency,
		"orders":        orders,
		"totals": map[string]any{
			"count":              len(orders),
			"outstanding_amount": outstandingTotal,
		},
	}})
}

// handleLocalPosTillOrderSummary — plan-044 R2. Close-screen paid / unpaid-carry
// counts from the local replica. paid = distinct orders with a payment attributed
// to this session; unpaid_carry = active orders (not closed/voided) with NO such
// payment — the set that simply stays open into the next shift (R9, never voided
// at close). Read-only; the reconcile money math is unchanged.
func (s *Server) handleLocalPosTillOrderSummary(w http.ResponseWriter, r *http.Request) {
	sessionID := r.PathValue("id")

	var paidCount int
	var paidTotal float64
	// #2580 — same status rule as paidPaymentsPredicate / paidOrderIDsSQL: the
	// original of a refunded sale still counts (its net is 0 via refunded_amount,
	// or via its signed refund row), and a signed refund row subtracts. This one
	// cannot call the shared predicate because it attributes on till_session_id
	// ALONE (no window fallback) — but the status half must not drift from it.
	if err := s.db.QueryRow(`
		SELECT COUNT(DISTINCT order_id), COALESCE(SUM(amount - COALESCE(refunded_amount,0)),0)
		FROM payments
		WHERE till_session_id = ?
		  AND ((refund_of_id IS NULL AND status IN ('pending','confirmed','succeeded','refunded'))
		       OR (refund_of_id IS NOT NULL AND status = 'succeeded'))`,
		sessionID).Scan(&paidCount, &paidTotal); err != nil {
		writeServerError(w, r, err)
		return
	}

	rows, err := s.db.Query(`
		SELECT o.id, COALESCE(o.order_code,''), o.status, COALESCE(o.total_amount,0), COALESCE(o.opened_at,'')
		FROM orders o
		WHERE o.status NOT IN ` + service.SQLStatusTerminal + `
		  AND NOT EXISTS (
		      SELECT 1 FROM payments p
		      WHERE p.order_id = o.id AND p.status IN ('pending','confirmed','succeeded'))
		ORDER BY o.opened_at`)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rows.Close()

	unpaid := []map[string]any{}
	for rows.Next() {
		var id, code, status, openedAt string
		var total float64
		if err := rows.Scan(&id, &code, &status, &total, &openedAt); err != nil {
			writeServerError(w, r, err)
			return
		}
		unpaid = append(unpaid, map[string]any{
			"id":                 id,
			"order_code":         code,
			"status":             status,
			"total_amount":       total,
			"outstanding_amount": total,
			"created_at":         openedAt,
		})
	}
	if err := rows.Err(); err != nil {
		writeServerError(w, r, err)
		return
	}

	writeJSON(w, http.StatusOK, map[string]any{"data": map[string]any{
		"paid_orders_count":   paidCount,
		"paid_orders_total":   paidTotal,
		"unpaid_carry_count":  len(unpaid),
		"unpaid_carry_orders": unpaid,
	}})
}

// reconcileSession aggregates the local `payments` table by mapping payment
// method codes → categories via till_tender_types, then layers cash
// movements (opening float + cash sales + paid_in - paid_out) for the
// expected_cash figure. Mirrors backend TillSessionService::reconcile()
// for the LAN-offline case where Cloud is unreachable.
func (s *Server) reconcileSession(sessionID string) (*reconResult, error) {
	var openingFloat float64
	var openedAt string
	var closedAt sql.NullString
	if err := s.db.QueryRow(`
		SELECT opening_float_amount, opened_at, closed_at
		FROM till_sessions WHERE id = ?`, sessionID).Scan(&openingFloat, &openedAt, &closedAt); err != nil {
		return nil, err
	}

	upperBound := time.Now().UTC().Format(time.RFC3339Nano)
	if closedAt.Valid && closedAt.String != "" {
		upperBound = closedAt.String
	}
	// Canonical UTC second-precision bounds — compared via substr(created_at,1,19)
	// so a Cloud opened_at with a +09:00 offset can't lexically exclude the
	// UTC-"Z" payment rows (same fix as buildShiftReport's window).
	lowerN := normalizeInstant(openedAt)
	upperN := normalizeInstant(upperBound)

	// Sum confirmed payments by payment_method (string code), tracking tips
	// separately. Cash tips physically land in the drawer, so expected_cash must
	// add them back (parity with backend TillSessionService::reconcile cash-tip
	// add-back); non-cash tips ride the terminal batch and are not drawer cash.
	//
	// ATTRIBUTION (#1219) — via paidPaymentsPredicate, the SAME filter the
	// Z-report and the settlement snapshot use. Its docstring already named
	// reconcileSession as a consumer; it was not one, and summed by a bare time
	// window instead, so the close screen's money disagreed with the slip printed
	// from it — and with Cloud, which reconciles purely on
	// `order_payments.till_session_id`.
	//
	// Sharing the predicate rather than restating it is the point: two copies of
	// a money filter drift, and this bug is what that drift looks like.
	paymentSums := map[string]float64{}
	tipSums := map[string]float64{}
	rows, err := s.db.Query(`
		SELECT payment_method,
		       SUM(amount - COALESCE(refunded_amount,0)),
		       SUM(COALESCE(tip_amount,0))
		FROM payments
		WHERE `+paidPaymentsPredicate("")+`
		GROUP BY payment_method`, sessionID, lowerN, upperN)
	if err != nil {
		return nil, err
	}
	for rows.Next() {
		var method string
		var sum, tip float64
		if err := rows.Scan(&method, &sum, &tip); err != nil {
			rows.Close()
			return nil, err
		}
		paymentSums[strings.ToLower(method)] = sum
		tipSums[strings.ToLower(method)] = tip
	}
	rows.Close()

	// `card_terminal` (決済端末 — bank payment terminal, e.g. Stera) is swiped on
	// the SAME physical device as `card` and settles in the same credit batch, so
	// it MUST reconcile as one `card` line — exactly what Cloud
	// TillSessionService::reconcile does. Without this merge the terminal's money
	// sits under its own key, invisible to BOTH the card anchor tender AND
	// category_expected['card'], so the close report shows expected card = 0
	// against a real batch slip — a phantom shortfall the cashier can never
	// settle (money leak). Fold it in BEFORE the category + tender rollups below.
	if v, ok := paymentSums["card_terminal"]; ok {
		paymentSums["card"] += v
	}

	paidIn, paidOut := 0.0, 0.0
	evRows, err := s.db.Query(`
		SELECT event_type, SUM(amount) FROM till_cash_events
		WHERE session_id = ? GROUP BY event_type`, sessionID)
	if err != nil {
		return nil, err
	}
	for evRows.Next() {
		var t string
		var sum float64
		if err := evRows.Scan(&t, &sum); err != nil {
			evRows.Close()
			return nil, err
		}
		switch t {
		case "paid_in", "loan_from_safe":
			paidIn += sum
		case "paid_out", "pickup_to_safe":
			paidOut += sum
		}
	}
	evRows.Close()

	// Drawer cash from a cash payment = amount + tip (the customer tenders both,
	// change is returned, tip stays in the drawer). Add cash tips back so the
	// expected drawer figure matches what the cashier physically counts.
	cashTips := tipSums["cash"]
	cashSales := round2(paymentSums["cash"] + cashTips)
	expectedCash := round2(openingFloat + cashSales + paidIn - paidOut)

	// Category expected — MUST mirror Cloud TillSessionService::reconcile exactly
	// so a shift reconciles identically whether closed on LAN or Cloud:
	//   cash    → cash payments + cash tips (drawer cash)
	//   card    → card + card_terminal (merged above)
	//   qr      → transfer (振込)
	//   emoney  → e_wallet (電子マネー — iD/WAON/nanaco…)
	// Custom categories: 0 (manual reconcile bucket). Pre-fix this mapped
	// e_wallet into qr and hardcoded emoney = 0 — the old bug Cloud already fixed
	// (#…): any e-money declared at close produced an unbalanceable variance.
	categoryExpected := map[string]float64{
		"cash":   cashSales,
		"card":   paymentSums["card"],
		"qr":     paymentSums["transfer"],
		"emoney": paymentSums["e_wallet"],
	}

	// Per-tender expected — only anchor tenders carry per-row expected;
	// anchor's expected_amount = paymentSums[anchor.payment_method_code]
	// (fallback to category_expected if method code unset).
	tenderExpected := map[string]float64{}
	ttRows, err := s.db.Query(`
		SELECT tender_key, category, payment_method_code, is_expected_anchor
		FROM till_tender_types`)
	if err != nil {
		return nil, err
	}
	for ttRows.Next() {
		var key, category string
		var methodCode sql.NullString
		var anchor int
		if err := ttRows.Scan(&key, &category, &methodCode, &anchor); err != nil {
			ttRows.Close()
			return nil, err
		}
		if anchor != 1 {
			continue
		}
		if methodCode.Valid && methodCode.String != "" {
			tenderExpected[key] = paymentSums[strings.ToLower(methodCode.String)]
		} else {
			tenderExpected[key] = categoryExpected[category]
		}
	}
	ttRows.Close()

	return &reconResult{
		ExpectedCash:     expectedCash,
		CashSales:        round2(cashSales),
		PaidIn:           round2(paidIn),
		PaidOut:          round2(paidOut),
		OpeningFloat:     round2(openingFloat),
		CategoryExpected: categoryExpected,
		TenderExpected:   tenderExpected,
	}, nil
}

func (s *Server) handleLocalPosTillReconciliation(w http.ResponseWriter, r *http.Request) {
	sessionID := r.PathValue("id")
	recon, err := s.reconcileSession(sessionID)
	if err != nil {
		writeServerError(w, r, err)
		return
	}

	// Shape mirrors Cloud's payload so pos-web close-page reads it without
	// branching on whether the response came from LAN or Cloud.
	tenders := []map[string]any{}
	ttRows, err := s.db.Query(`
		SELECT tender_key, category, parent_tender_key
		FROM till_tender_types
		ORDER BY sort_order`)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	for ttRows.Next() {
		var key, category string
		var parent sql.NullString
		if err := ttRows.Scan(&key, &category, &parent); err != nil {
			ttRows.Close()
			writeServerError(w, r, err)
			return
		}
		var expected any
		if v, ok := recon.TenderExpected[key]; ok {
			expected = round2(v)
		}
		tenders = append(tenders, map[string]any{
			"tender_key":      key,
			"category":        category,
			"parent":          nullableStringValue(parent),
			"expected_amount": expected,
		})
	}
	ttRows.Close()

	currency := "JPY"
	_ = s.db.QueryRow(
		"SELECT default_currency_code FROM till_sessions WHERE id = ?",
		sessionID).Scan(&currency)

	// Surface the till's variance tolerance so pos-web's offline close-gate
	// (outOfToleranceMissingReason) has the same threshold Cloud enforces —
	// keeps the client gate and the WS server-side enforcement (handleLocalPos
	// TillClose) in agreement. Default 0 = any non-zero variance needs a reason.
	var tolerance float64
	_ = s.db.QueryRow("SELECT variance_tolerance_amount FROM tills LIMIT 1").Scan(&tolerance)

	writeJSON(w, http.StatusOK, map[string]any{
		"data": map[string]any{
			"revenue":                   map[string]any{"currency_code": currency},
			"variance_tolerance_amount": round2(tolerance),
			"cash": map[string]any{
				"opening_float": recon.OpeningFloat,
				"cash_sales":    recon.CashSales,
				"paid_in":       recon.PaidIn,
				"paid_out":      recon.PaidOut,
				"expected_cash": recon.ExpectedCash,
			},
			"tenders": tenders,
			"category_expected": map[string]any{
				"cash":   round2(recon.CategoryExpected["cash"]),
				"card":   round2(recon.CategoryExpected["card"]),
				"qr":     round2(recon.CategoryExpected["qr"]),
				"emoney": round2(recon.CategoryExpected["emoney"]),
			},
		},
	})
}

// ─── Sync UP queue enqueuers ────────────────────────────────────────────────

func (s *Server) enqueueShiftOpen(r *http.Request, sessionID string, in openShiftInput, float float64, sessionCode, currency string, now time.Time, tillID, branchID, chainID string, chainSeq int) {
	if s.sync == nil {
		return
	}
	bearer := bearerFromRequest(r)
	payload := map[string]any{
		"bearer_token":         bearer,
		"id":                   sessionID,
		"session_code":         sessionCode,
		"till_id":              tillID,
		"branch_id":            branchID,
		"currency_code":        currency,
		"opening_float_amount": float,
		"opening_note":         in.OpeningNote,
		"opened_by_id":         in.OpenedByID,
		"opener_name":          in.OpenerName,
		"opened_at":            now.Format(time.RFC3339Nano),
		"opening_counts":       in.OpeningCounts,
		// plan-046 — chain grouping so Cloud stores the same chain (verbatim).
		"chain_id":       chainID,
		"chain_sequence": chainSeq,
	}
	if err := s.sync.Enqueue("till_session", sessionID, "open", payload, 1); err != nil {
		slog.Error("enqueue shift.open", "err", err, "session", sessionID)
	}
}

func (s *Server) enqueueShiftCashEvent(r *http.Request, sessionID, eventID string, in cashEventInput, occurredAt string) {
	if s.sync == nil {
		return
	}
	payload := map[string]any{
		"bearer_token":    bearerFromRequest(r),
		"session_id":      sessionID,
		"id":              eventID,
		"event_type":      in.EventType,
		"amount":          in.Amount,
		"currency_code":   strings.ToUpper(in.CurrencyCode),
		"reason":          in.Reason,
		"reference_no":    in.ReferenceNo,
		"performed_by_id": in.PerformedByID,
		"occurred_at":     occurredAt,
	}
	if err := s.sync.Enqueue("till_cash_event", eventID, "create", payload, 1); err != nil {
		slog.Error("enqueue shift.cash_event", "err", err, "event", eventID)
	}
}

// latestTerminalChainForTill returns the chain_id / chain_sequence / settlement_kind
// of the till's most-recent terminal session (plan-046 T5.3, R1). Deterministic
// tie-break: closed_at DESC, then chain_sequence DESC, then id DESC — mirrors the
// Cloud previousTerminalSessionForTill P6-4 fix. found=false when the till has no
// terminal session (→ a fresh chain). Locally, terminal = settled|abandoned, both
// stamped with closed_at (see the abandon handler).
func (s *Server) latestTerminalChainForTill(tillID string) (chainID string, chainSeq int, kind string, found bool) {
	var cid, k sql.NullString
	var seq sql.NullInt64
	err := s.db.QueryRow(`
		SELECT chain_id, chain_sequence, settlement_kind
		FROM till_sessions
		WHERE till_id = ? AND status IN ('settled','abandoned')
		  AND closed_at IS NOT NULL AND closed_at != ''
		ORDER BY substr(closed_at,1,19) DESC,
		         CAST(chain_sequence AS INTEGER) DESC, id DESC
		LIMIT 1`, tillID).Scan(&cid, &seq, &k)
	if err != nil {
		return "", 0, "", false
	}
	return cid.String, int(seq.Int64), k.String, true
}

// buildLocalSettlementSnapshot builds the PROVISIONAL per-shift snapshot at
// offline settle (plan-046 T5.4, R7). Same JSON shape as Cloud's authoritative
// snapshot (which overwrites this via the sync-UP response — adopt-if-present).
// Cash/tenders from the reconcileSession result; per-rate tax from the shared
// service.PerRateTaxBuckets (P5-6); revenue + order counts from the paid-orders
// window [opened_at, settledAt]. Returns a JSON string for the settlement_snapshot
// TEXT column.
func (s *Server) buildLocalSettlementSnapshot(sessionID string, recon *reconResult, counted, cashVariance float64, settledAt time.Time, closingCounts []struct {
	DenominationID string `json:"denomination_id"`
	Quantity       int    `json:"quantity"`
}) (string, error) {
	var openedAt string
	if err := s.db.QueryRow(`SELECT opened_at FROM till_sessions WHERE id = ?`, sessionID).Scan(&openedAt); err != nil {
		return "", err
	}
	lowerN := normalizeInstant(openedAt)
	upperN := normalizeInstant(settledAt.Format(time.RFC3339Nano))
	paidOrderIDs, cloudPayments, err := s.shiftReportScope(sessionID, lowerN, upperN)
	if err != nil {
		return "", err
	}
	paidOrderMarks, paidOrderArgs := stringPlaceholders(paidOrderIDs)
	if paidOrderMarks == "" {
		paidOrderMarks = "NULL"
	}

	step := service.CurrencyStep(s.shopSetting("currency_code", "JPY"))
	buckets := service.PerRateTaxBucketsForOrders(s.db, step, paidOrderIDs)
	taxBreakdown := make([]map[string]any, 0, len(buckets))
	for _, b := range buckets {
		taxBreakdown = append(taxBreakdown, map[string]any{
			"rate":    b.Rate,
			"taxable": float64(b.TaxableSales),
			"tax":     float64(b.Tax),
		})
	}

	var gross, tax, discount, paidCount float64
	// #2140 — như lan_shift_report: sổ thắng cột, fallback theo CÓ DÒNG.
	// Ảnh chụp này đi vào `till_sessions.settlement_snapshot` và là bất biến.
	_ = s.db.QueryRow(`
		SELECT COALESCE(SUM(o.total_amount),0),
		       COALESCE(SUM(`+orderLedgerValueSQL("tax", "o.tax_amount")+`),0),
		       COALESCE(SUM(`+orderLedgerValueSQL("discount", "o.discount_amount")+`),0),
		       COUNT(*)
		FROM orders o`+orderLedgerJoinSQL+` WHERE o.id IN (`+paidOrderMarks+`)`,
		paidOrderArgs...).Scan(&gross, &tax, &discount, &paidCount)

	tenders := make([]map[string]any, 0, len(recon.TenderExpected))
	for key, exp := range recon.TenderExpected {
		tenders = append(tenders, map[string]any{"tender_key": key, "expected": exp})
	}

	// plan-046 step 2 — the sections the chain aggregate could not reconstruct
	// from the money keys alone. Same queries the single-shift 精算 slip runs, so
	// a shift's own report and its block in the chain report describe one thing.
	// Key names + types must stay byte-identical to Cloud's builder: Cloud
	// overwrites this provisional snapshot via the sync-UP response (R7), and a
	// silent shape drift would make sections vanish once the op drains.
	var itemCount, guestCount float64
	_ = s.db.QueryRow(`
		SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi
		WHERE oi.status != 'voided' AND oi.customer_order_id IN (`+paidOrderMarks+`)`,
		paidOrderArgs...).Scan(&itemCount)
	_ = s.db.QueryRow(`
		SELECT COALESCE(SUM(o.guest_count), 0) FROM orders o WHERE o.id IN (`+paidOrderMarks+`)`,
		paidOrderArgs...).Scan(&guestCount)

	// 支払方法 — the ACTUAL payment-method split with transaction counts, the
	// same query the single-shift 精算 slip runs. `tenders` above cannot serve
	// this: it is keyed by till TENDER TYPE with an expected amount and carries
	// no count, so a chain slip built from it could not reproduce the per-shift
	// slip's payment section.
	paymentLines, err := s.shiftPaymentLines(sessionID, lowerN, upperN, cloudPayments)
	if err != nil {
		return "", err
	}
	payments := make([]map[string]any, 0, len(paymentLines))
	for _, line := range paymentLines {
		payments = append(payments, map[string]any{
			"code": line.Code, "label": line.Label, "count": line.Count, "amount": line.Amount,
		})
	}

	discounts := make([]map[string]any, 0)
	if rows, qerr := s.db.Query(`
		SELECT oc.coupon_code, COUNT(*), COALESCE(SUM(oc.discount_applied), 0)
		FROM order_coupons oc
		WHERE oc.released_at IS NULL AND oc.order_id IN (`+paidOrderMarks+`)
		GROUP BY oc.coupon_code
		ORDER BY 3 DESC`, paidOrderArgs...); qerr == nil {
		for rows.Next() {
			var label string
			var cnt int
			var amt float64
			if rows.Scan(&label, &cnt, &amt) == nil {
				discounts = append(discounts, map[string]any{
					"label": label, "count": cnt, "amount": amt,
				})
			}
		}
		rows.Close()
	}

	// 伝票削除 — a voided order carries no payment, so it can never be attributed
	// to a shift; the time window is the only signal available and is correct here.
	//
	// The window test runs in Go over normalizeInstant, not in SQL over
	// substr(voided_at,1,19): the bounds are normalized, so a raw column with a
	// space separator loses at character 11 and a void lands in the wrong shift
	// (#2736, same family as #2730).
	var voidUnpaidCount, voidPaidCount int
	var voidUnpaidAmount, voidPaidAmount float64
	if rows, qerr := s.db.Query(`
		SELECT o.total_amount,
		       (SELECT COUNT(*) FROM payments p
		          WHERE p.order_id = o.id AND p.status IN ('pending','confirmed','succeeded')) AS pays,
		       COALESCE(o.voided_at,'')
		FROM orders o
		WHERE o.status = 'voided' AND o.voided_at IS NOT NULL`); qerr == nil {
		for rows.Next() {
			var total float64
			var pays int
			var voidedAt string
			if rows.Scan(&total, &pays, &voidedAt) != nil {
				continue
			}
			if n := normalizeInstant(voidedAt); n < lowerN || n > upperN {
				continue
			}
			if pays > 0 {
				voidPaidCount++
				voidPaidAmount += total
			} else {
				voidUnpaidCount++
				voidUnpaidAmount += total
			}
		}
		rows.Close()
	}

	var paidInCount, paidOutCount int
	if rows, qerr := s.db.Query(`
		SELECT event_type, COUNT(*) FROM till_cash_events
		WHERE session_id = ? GROUP BY event_type`, sessionID); qerr == nil {
		for rows.Next() {
			var t string
			var cnt int
			if rows.Scan(&t, &cnt) != nil {
				continue
			}
			switch t {
			case "paid_in", "loan_from_safe":
				paidInCount += cnt
			case "paid_out", "pickup_to_safe":
				paidOutCount += cnt
			}
		}
		rows.Close()
	}

	// 金種 — the closing cash-count mix, built from the SUBMITTED counts. The
	// caller has NOT yet written them to till_cash_denomination_counts (that
	// happens in the settle transaction, AFTER this snapshot is built), so
	// re-reading the table here would capture it as it stood before this settle
	// — the previous draft, or nothing — and every quantity would come out 0.
	// That is exactly the drift the chain report (which sums these snapshots)
	// exposed: its 金種 quantities printed blank while the single-shift 精算 slip,
	// which reads the table after the write, showed the real mix. Every active
	// denomination is listed (0 for uncounted), value-desc, so the chain slip
	// and the single-shift slip that print together match row-for-row, and the
	// subtotal is value×quantity exactly as the persisted row records it.
	qtyByDenom := make(map[string]int, len(closingCounts))
	for _, c := range closingCounts {
		qtyByDenom[c.DenominationID] += c.Quantity
	}
	denominations := make([]map[string]any, 0)
	if rows, qerr := s.db.Query(`
		SELECT id, value FROM denominations
		WHERE is_active = 1 AND currency_code = ?
		ORDER BY value DESC`, s.shopSetting("currency_code", "JPY")); qerr == nil {
		for rows.Next() {
			var id string
			var value float64
			if rows.Scan(&id, &value) != nil {
				continue
			}
			qty := qtyByDenom[id]
			denominations = append(denominations, map[string]any{
				"value": value, "quantity": qty, "subtotal": value * float64(qty),
			})
		}
		rows.Close()
	}

	snapshot := map[string]any{
		"counts": map[string]any{
			"item_count":  int(itemCount),
			"guest_count": int(guestCount),
		},
		"payments":  payments,
		"discounts": discounts,
		"voids": map[string]any{
			"unpaid_count":  voidUnpaidCount,
			"unpaid_amount": voidUnpaidAmount,
			"paid_count":    voidPaidCount,
			"paid_amount":   voidPaidAmount,
		},
		"cash_events": map[string]any{
			"paid_in_count":  paidInCount,
			"paid_out_count": paidOutCount,
		},
		"denominations": denominations,
		"opening_float": recon.OpeningFloat,
		"cash": map[string]any{
			"expected": recon.ExpectedCash,
			"counted":  counted,
			"variance": cashVariance,
			"sales":    recon.CashSales,
			"paid_in":  recon.PaidIn,
			"paid_out": recon.PaidOut,
		},
		"tenders":       tenders,
		"tax_breakdown": taxBreakdown,
		"revenue": map[string]any{
			"gross":    gross,
			"net":      gross - tax,
			"tax":      tax,
			"discount": discount,
		},
		"orders": map[string]any{
			"paid_count": int(paidCount),
			"paid_total": gross,
		},
	}
	b, err := json.Marshal(snapshot)
	if err != nil {
		return "", err
	}
	return string(b), nil
}

// enqueueShiftSettle queues the sync-UP for a settle (close=final or handover).
// Plan-046 (T5.5, P7-B/C): chain fields live on the session ROW (written at open),
// NOT in the request, so they are read here and threaded onto the payload. The
// provisional snapshot is NOT sent — Cloud recomputes the authoritative one and
// returns it in the response (R7). The handover op POSTs to the same /close Cloud
// route (P7-C) with settlement_kind=handover; settleFromWorkstation branches on it.
func (s *Server) enqueueShiftSettle(r *http.Request, sessionID string, in draftInput, counted, variance float64, now time.Time, kind string) {
	if s.sync == nil {
		return
	}
	var chainID sql.NullString
	var chainSeq sql.NullInt64
	_ = s.db.QueryRow(`SELECT chain_id, chain_sequence FROM till_sessions WHERE id = ?`, sessionID).
		Scan(&chainID, &chainSeq)

	payload := map[string]any{
		"bearer_token":    bearerFromRequest(r),
		"session_id":      sessionID,
		"closed_at":       now.Format(time.RFC3339Nano),
		"closing_counts":  in.ClosingCounts,
		"tender_details":  in.TenderDetails,
		"closing_note":    in.ClosingNote,
		"counted_cash":    counted,
		"cash_variance":   variance,
		"settlement_kind": kind,

		// Must ride along, and this is the half that is easy to forget because
		// the local numbers already look right once `counted` is fixed. Cloud
		// does not adopt `counted_cash` — it RECOMPUTES from the counts it is
		// given (`$data['closing_cash_adjustment'] ?? 0`). Send the counts
		// without the adjustment and Cloud lands on the pre-fix figure, then R7
		// writes its snapshot back over the correct local one. The bug would
		// come back through the sync layer, looking like a sync bug (#1986).
		"closing_cash_adjustment": in.cashAdjustment(),
	}
	if chainID.Valid {
		payload["chain_id"] = chainID.String
	}
	if chainSeq.Valid {
		payload["chain_sequence"] = chainSeq.Int64
	}
	op := "close"
	if kind == "handover" {
		op = "handover"
	}
	if err := s.sync.Enqueue("till_session", sessionID, op, payload, 1); err != nil {
		slog.Error("enqueue shift settle", "err", err, "session", sessionID, "kind", kind)
	}
}

func (s *Server) enqueueShiftAbandon(r *http.Request, sessionID, reason string, now time.Time) {
	if s.sync == nil {
		return
	}
	payload := map[string]any{
		"bearer_token":   bearerFromRequest(r),
		"session_id":     sessionID,
		"abandon_reason": reason,
		"closed_at":      now.Format(time.RFC3339Nano),
	}
	if err := s.sync.Enqueue("till_session", sessionID, "abandon", payload, 1); err != nil {
		slog.Error("enqueue shift.abandon", "err", err, "session", sessionID)
	}
}

// ─── Helpers ────────────────────────────────────────────────────────────────

// currentTillSessionID returns the id of the till's currently-open shift, or
// "" when no shift is open. Used to stamp till_session_id onto workstation
// payments at capture time (#817 Phase B) so the close manifest and Cloud's
// captured_at attribution can bind the payment to the shift it was collected
// in. Best-effort: any error yields "" (payment still records, just unbound).
func (s *Server) currentTillSessionID() string {
	var sessionID sql.NullString
	_ = s.db.QueryRow(
		"SELECT current_session_id FROM tills WHERE current_session_id IS NOT NULL LIMIT 1").
		Scan(&sessionID)
	if sessionID.Valid {
		return sessionID.String
	}
	return ""
}

// hasInProgressShift reports whether a cashier shift is currently in progress
// (status open OR closing) on this workstation's till — the NO_OPEN_SHIFT gate
// predicate for LAN POS order + payment creation (plan-044 DESIGN Decision 6;
// parity with Cloud currentForBranch()/inProgress()). 'closing' still passes so
// a cashier can finish ringing up while counting; a settled/abandoned/expired
// till (or no session at all) blocks. The workstation serves a single branch, so
// a global status check is equivalent to the per-branch predicate on Cloud.
func (s *Server) hasInProgressShift() bool {
	var n int
	_ = s.db.QueryRow(
		"SELECT COUNT(*) FROM till_sessions WHERE status IN ('open','closing')").Scan(&n)
	return n > 0
}

func (s *Server) loadTill() (map[string]any, error) {
	var id, branchID, code, currency string
	var tolerance float64
	var currentSessionID sql.NullString
	err := s.db.QueryRow(`
		SELECT id, branch_id, code, default_currency_code,
		    variance_tolerance_amount, current_session_id
		FROM tills LIMIT 1`).Scan(&id, &branchID, &code, &currency,
		&tolerance, &currentSessionID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	out := map[string]any{
		"id":                        id,
		"branch_id":                 branchID,
		"code":                      code,
		"default_currency_code":     currency,
		"variance_tolerance_amount": tolerance,
		"current_session_id":        nil,
	}
	if currentSessionID.Valid {
		out["current_session_id"] = currentSessionID.String
	}
	return out, nil
}

func (s *Server) loadSession(id string) (map[string]any, error) {
	var sessCode, status, businessDate, currency string
	var openingFloat, countedCash, cashVariance, cashAdjustment sql.NullFloat64
	var openingNote, openedByID, openerName, closingNote, abandonReason sql.NullString
	var openedAt string
	var closedAt sql.NullString
	var tillID, branchID string
	var chainID, settlementKind sql.NullString
	var chainSeq sql.NullInt64

	err := s.db.QueryRow(`
		SELECT session_code, status, business_date, default_currency_code,
		    opening_float_amount, opening_note, opened_by_id, opener_name,
		    opened_at, closed_at, closing_note, abandon_reason,
		    counted_cash, cash_variance, closing_cash_adjustment_amount,
		    till_id, branch_id,
		    chain_id, chain_sequence, settlement_kind
		FROM till_sessions WHERE id = ?`, id).Scan(
		&sessCode, &status, &businessDate, &currency,
		&openingFloat, &openingNote, &openedByID, &openerName,
		&openedAt, &closedAt, &closingNote, &abandonReason,
		&countedCash, &cashVariance, &cashAdjustment, &tillID, &branchID,
		&chainID, &chainSeq, &settlementKind)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}

	out := map[string]any{
		"id":                    id,
		"session_code":          sessCode,
		"status":                status,
		"business_date":         businessDate,
		"default_currency_code": currency,
		"opening_float_amount":  nullableFloatValue(openingFloat),
		"opening_note":          nullableStringValue(openingNote),
		"opened_by_id":          nullableStringValue(openedByID),
		"opener_name":           nullableStringValue(openerName),
		"opened_at":             openedAt,
		"closed_at":             nullableStringValue(closedAt),
		"closing_note":          nullableStringValue(closingNote),
		"abandon_reason":        nullableStringValue(abandonReason),
		// `_amount`, matching Cloud's TillSessionResource. These used to be
		// emitted as `counted_cash` / `cash_variance` — names pos-web does not
		// read (its `TillSession` type declares `counted_cash_amount` /
		// `cash_variance_amount`), so every locally-served session handed the
		// close screen `undefined` for both. Nothing threw: `undefined` renders
		// as a blank, and a blank reads as "not counted yet" rather than as a
		// field the two sides named differently (#1986).
		//
		// The old names are GONE rather than kept alongside. Nothing consumes
		// them — measured across pos-web and the Wails frontend — and carrying a
		// second spelling is how the two shapes drifted this far apart to begin
		// with. Cloud's resource is the contract; this replica either matches it
		// or the proxy fallback silently changes the response shape depending on
		// whether the shop happened to be online.
		"counted_cash_amount":  nullableFloatValue(countedCash),
		"cash_variance_amount": nullableFloatValue(cashVariance),

		// 「tiền lẻ」. Without this key the close screen cannot restore what the
		// cashier typed, so a reload silently reset it to zero and the variance
		// it then displayed was short by that amount (SC-11).
		"closing_cash_adjustment_amount": nullableFloatValue(cashAdjustment),

		"till_id":   tillID,
		"branch_id": branchID,
		// plan-046 chain fields (P7-A) — the "Ca N" badge data source.
		"chain_id":        nullableStringValue(chainID),
		"chain_sequence":  int(chainSeq.Int64),
		"settlement_kind": nullableStringValue(settlementKind),
	}

	// Eager-load cash events for parity with Cloud's TillSessionResource.
	evs := []map[string]any{}
	rows, err := s.db.Query(`
		SELECT id, event_type, amount, currency_code, reason, reference_no,
		    performed_by_id, occurred_at
		FROM till_cash_events WHERE session_id = ? ORDER BY occurred_at`, id)
	if err == nil {
		defer rows.Close()
		for rows.Next() {
			var eid, et, cur string
			var amount float64
			var reason, refNo, perf sql.NullString
			var occ string
			if err := rows.Scan(&eid, &et, &amount, &cur, &reason, &refNo, &perf, &occ); err == nil {
				evs = append(evs, map[string]any{
					"id":              eid,
					"event_type":      et,
					"amount":          amount,
					"currency_code":   cur,
					"reason":          nullableStringValue(reason),
					"reference_no":    nullableStringValue(refNo),
					"performed_by_id": nullableStringValue(perf),
					"occurred_at":     occ,
				})
			}
		}
	}
	out["cash_events"] = evs

	// Denomination counts, per phase — the DRAFT's whole reason to exist.
	//
	// A cashier counts the drawer, is called away, comes back and reloads. The
	// counts were being written to `till_cash_denomination_counts` correctly and
	// then never read back out, so the close screen restarted from empty and
	// showed a variance against a drawer it believed held nothing. Saving state
	// nobody reads is worse than not saving it: the cashier is told their work
	// was kept.
	//
	// Cloud's resource carries both phases, so both are here — opening counts
	// are what the blind re-count at handover is checked against (plan-046).
	out["closing_counts"] = s.loadSessionCounts(id, "closing")
	out["opening_counts"] = s.loadSessionCounts(id, "opening")

	// DELIBERATELY ABSENT: `expected_cash_amount`. Cloud computes it from the
	// shift's payments and cash events, which here means running the whole
	// reconciliation — too much work to hang on every session read, on the LAN
	// path, when pos-web already has `GET …/{id}/reconciliation` for exactly
	// this number. Named rather than merely omitted, because the next person to
	// diff this map against Cloud's resource should find a decision here instead
	// of assuming it is one more field that was forgotten.

	return out, nil
}

// loadSessionCounts returns one phase's denomination counts in Cloud's shape.
//
// Errors yield an EMPTY slice, never nil and never a propagated failure: a
// session that cannot list its counts is still a session the cashier must be
// able to see, and failing the whole read would black out the close screen over
// a detail panel. `[]` also marshals as `[]` rather than `null`, which keeps the
// client's `?.length` checks from having to care.
func (s *Server) loadSessionCounts(sessionID, phase string) []map[string]any {
	out := []map[string]any{}

	rows, err := s.db.Query(`
		SELECT c.id, c.denomination_id, c.quantity, c.subtotal_amount,
		    d.value, d.kind
		FROM till_cash_denomination_counts c
		LEFT JOIN denominations d ON d.id = c.denomination_id
		WHERE c.session_id = ? AND c.phase = ?
		ORDER BY d.value DESC`, sessionID, phase)
	if err != nil {
		return out
	}
	defer rows.Close()

	for rows.Next() {
		var cid, denomID string
		var qty int
		var subtotal float64
		var value sql.NullFloat64
		var kind sql.NullString
		if err := rows.Scan(&cid, &denomID, &qty, &subtotal, &value, &kind); err != nil {
			continue
		}
		out = append(out, map[string]any{
			"id":                 cid,
			"denomination_id":    denomID,
			"quantity":           qty,
			"subtotal_amount":    subtotal,
			"denomination_value": nullableFloatValue(value),
			"denomination_kind":  nullableStringValue(kind),
		})
	}

	return out
}

func (s *Server) sessionStatusAndCurrency(id string) (string, string, error) {
	var status, currency string
	err := s.db.QueryRow(
		"SELECT status, default_currency_code FROM till_sessions WHERE id = ?",
		id).Scan(&status, &currency)
	return status, currency, err
}

func (s *Server) replaceClosingCounts(sessionID string, counts []struct {
	DenominationID string `json:"denomination_id"`
	Quantity       int    `json:"quantity"`
}) error {
	tx, err := s.db.Conn().Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if _, err := tx.Exec(
		"DELETE FROM till_cash_denomination_counts WHERE session_id=? AND phase='closing'",
		sessionID); err != nil {
		return err
	}
	for _, c := range counts {
		var value float64
		if err := tx.QueryRow("SELECT value FROM denominations WHERE id = ?", c.DenominationID).Scan(&value); err != nil {
			return err
		}
		if _, err := tx.Exec(`
			INSERT INTO till_cash_denomination_counts (id, session_id,
			    denomination_id, phase, quantity, subtotal_amount)
			VALUES (?, ?, ?, 'closing', ?, ?)`,
			uuid.NewString(), sessionID, c.DenominationID, c.Quantity,
			value*float64(c.Quantity)); err != nil {
			return err
		}
	}
	return tx.Commit()
}

// generateSessionCode produces a sortable, workstation-traceable code.
// Format: WS-<YYYYMMDD>-<HHMMSS>-<short>. The short suffix is the first 6
// chars of a UUIDv4 so back-to-back opens within the same second don't
// collide.
func (s *Server) generateSessionCode(now time.Time) string {
	suffix := strings.ReplaceAll(uuid.NewString(), "-", "")[:6]
	return fmt.Sprintf("WS-%s-%s", now.Format("20060102-150405"), suffix)
}

func bearerFromRequest(r *http.Request) string {
	auth := r.Header.Get("Authorization")
	if strings.HasPrefix(auth, "Bearer ") {
		return strings.TrimPrefix(auth, "Bearer ")
	}
	return ""
}

func nullableFloatValue(v sql.NullFloat64) any {
	if !v.Valid {
		return nil
	}
	return round2(v.Float64)
}

func nullableStringValue(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}

func pickFloat(p *float64) float64 {
	if p == nil {
		return 0
	}
	return *p
}

func pickFloatPtr(p *float64) any {
	if p == nil {
		return nil
	}
	return *p
}

func pickStringPtr(p *string) any {
	if p == nil {
		return nil
	}
	return *p
}

// round2 rounds to 2 decimals, half away from zero. The +0.5 bias only rounds
// correctly for positives; for a NEGATIVE value (e.g. a cash shortage's
// cash_variance) int64 truncates toward zero, so a −200 shortage rounded once
// became −199.99 and, rounded again on load, −199.98 — a real money-display
// defect surfaced by the #817 close flow. Bias away from zero on each side.
func round2(v float64) float64 {
	if v < 0 {
		return float64(int64(v*100-0.5)) / 100
	}
	return float64(int64(v*100+0.5)) / 100
}

// Sentinel used by the sync_service handlers below.
var _ = json.Unmarshal
