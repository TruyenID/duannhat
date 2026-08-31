package handler

import (
	"database/sql"
	"encoding/json"
	"net/http"
	"sort"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/printjob"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// buildChainReport aggregates a chain's SETTLED members (handover + final) by
// summing each immutable settlement_snapshot into a condensed per-shift block +
// grand total (plan-046 T5.8). Members are ordered int-wise by chain_sequence
// (P5-5); a member with a NULL snapshot (abandoned mid-chain, R5) is excluded by
// the settlement_kind filter so the Σ never mis-adds. Returns sql.ErrNoRows when
// the chain has no settled member (→ 404).
func (s *Server) buildChainReport(chainID string) (*service.ChainReportInfo, error) {
	rows, err := s.db.Query(`
		SELECT ts.chain_sequence, ts.settlement_kind, ts.settlement_snapshot,
		       ts.opened_at, ts.closed_at, ts.till_id,
		       COALESCE(NULLIF(ts.opener_name, ''), st.full_name, '')
		FROM till_sessions ts
		LEFT JOIN staff st ON st.id = ts.opened_by_id
		WHERE ts.chain_id = ? AND ts.settlement_kind IN ('handover','final')
		  AND ts.settlement_snapshot IS NOT NULL AND ts.settlement_snapshot != ''
		ORDER BY CAST(ts.chain_sequence AS INTEGER) ASC`, chainID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	type snapCash struct {
		Counted  float64 `json:"counted"`
		Expected float64 `json:"expected"`
		Variance float64 `json:"variance"`
		Sales    float64 `json:"sales"`
		PaidIn   float64 `json:"paid_in"`
		PaidOut  float64 `json:"paid_out"`
	}
	type snapRev struct {
		Gross    float64 `json:"gross"`
		Net      float64 `json:"net"`
		Tax      float64 `json:"tax"`
		Discount float64 `json:"discount"`
	}
	type snapRate struct {
		Rate    float64 `json:"rate"`
		Taxable float64 `json:"taxable"`
		Tax     float64 `json:"tax"`
	}
	type snapOrders struct {
		PaidCount float64 `json:"paid_count"`
	}
	// The two stacks write `tenders` differently: Cloud emits
	// {tender_key, category, parent, expected_amount}, the workstation's local
	// provisional emits {tender_key, expected}. Cloud's version overwrites the
	// local one on sync-UP (R7), so a chain can legitimately hold BOTH shapes —
	// decode both and take whichever is populated.
	type snapTender struct {
		TenderKey      string   `json:"tender_key"`
		Category       string   `json:"category"`
		ExpectedAmount *float64 `json:"expected_amount"`
		Expected       *float64 `json:"expected"`
	}
	// plan-046 step 2 sections. Pointers so a chain settled BEFORE the
	// enrichment (keys absent) is distinguishable from one that genuinely
	// recorded zeros — the slip omits a section it has no data for rather than
	// printing a confident 0.
	type snapCounts struct {
		ItemCount  int `json:"item_count"`
		GuestCount int `json:"guest_count"`
	}
	type snapVoids struct {
		UnpaidCount  int     `json:"unpaid_count"`
		UnpaidAmount float64 `json:"unpaid_amount"`
		PaidCount    int     `json:"paid_count"`
		PaidAmount   float64 `json:"paid_amount"`
	}
	type snapEvents struct {
		PaidInCount  int `json:"paid_in_count"`
		PaidOutCount int `json:"paid_out_count"`
	}
	type snapDiscount struct {
		Label  string  `json:"label"`
		Count  int     `json:"count"`
		Amount float64 `json:"amount"`
	}
	type snapPayment struct {
		Code   string  `json:"code"`
		Label  string  `json:"label"`
		Count  int     `json:"count"`
		Amount float64 `json:"amount"`
	}
	type snapDenom struct {
		Value    float64 `json:"value"`
		Quantity int     `json:"quantity"`
		Subtotal float64 `json:"subtotal"`
	}
	type snap struct {
		Cash          snapCash       `json:"cash"`
		Revenue       snapRev        `json:"revenue"`
		TaxBreakdown  []snapRate     `json:"tax_breakdown"`
		Orders        snapOrders     `json:"orders"`
		Tenders       []snapTender   `json:"tenders"`
		OpeningFloat  float64        `json:"opening_float"`
		Counts        *snapCounts    `json:"counts"`
		Voids         *snapVoids     `json:"voids"`
		CashEvents    *snapEvents    `json:"cash_events"`
		Discounts     []snapDiscount `json:"discounts"`
		Payments      []snapPayment  `json:"payments"`
		Denominations []snapDenom    `json:"denominations"`
	}

	info := &service.ChainReportInfo{ChainID: chainID}
	byRate := map[float64]*service.ShiftTaxRateLine{}
	var rateOrder []float64
	byTender := map[string]*service.ShiftPaymentLine{}
	var tenderOrder []string
	byDiscount := map[string]*service.ShiftDiscountLine{}
	var discountOrder []string
	byDenom := map[float64]*service.ShiftOpenDenomLine{}
	var denomOrder []float64
	var tillID, firstOpened, lastClosed string
	firstFloatSeen := false

	for rows.Next() {
		var seq int
		var kind, snapJSON, openedAt, tid, operator string
		var closedAt sql.NullString
		if err := rows.Scan(&seq, &kind, &snapJSON, &openedAt, &closedAt, &tid, &operator); err != nil {
			continue
		}
		// The chain's 担当者 is whoever ENDED it — the same shift the drawer
		// figures come from, so the name sits beside the count that person made.
		info.Operator = operator
		tillID = tid
		if firstOpened == "" {
			firstOpened = openedAt
		}
		if closedAt.Valid {
			lastClosed = closedAt.String
		}

		var sp snap
		_ = json.Unmarshal([]byte(snapJSON), &sp)

		loc := s.shopLocation()
		shiftClosed := ""
		if closedAt.Valid {
			shiftClosed = fmtShiftTime(closedAt.String, loc)
		}
		info.Shifts = append(info.Shifts, service.ChainShiftLine{
			Sequence:     seq,
			Kind:         kind,
			CountedCash:  int(sp.Cash.Counted),
			Variance:     int(sp.Cash.Variance),
			Gross:        int(sp.Revenue.Gross),
			OpenedAt:     fmtShiftTime(openedAt, loc),
			ClosedAt:     shiftClosed,
			Net:          int(sp.Revenue.Net),
			Tax:          int(sp.Revenue.Tax),
			Discount:     int(sp.Revenue.Discount),
			ExpectedCash: int(sp.Cash.Expected),
			CheckCount:   int(sp.Orders.PaidCount),
			Operator:     operator,
		})
		// The drawer is ONE physical till handed from cashier to cashier: each
		// shift's opening float IS the previous shift's closing count (verified
		// on real data: 10,000 → 11,081 → 16,677 → 22,744). Summing the counts
		// would report the same banknotes once per shift — 50,502 for a drawer
		// physically holding 22,744. The chain's drawer figures are therefore the
		// LAST shift's: the count that was actually made to end the chain.
		info.CountedCash = int(sp.Cash.Counted)
		info.ExpectedCash = int(sp.Cash.Expected)
		info.Variance = int(sp.Cash.Variance)
		info.Gross += int(sp.Revenue.Gross)
		info.Net += int(sp.Revenue.Net)
		info.TaxTotal += int(sp.Revenue.Tax)
		info.Discount += int(sp.Revenue.Discount)
		info.CheckCount += int(sp.Orders.PaidCount)
		info.CashSales += int(sp.Cash.Sales)
		info.PaidIn += int(sp.Cash.PaidIn)
		info.PaidOut += int(sp.Cash.PaidOut)

		// The chain's opening float is the FIRST shift's — each handover passes
		// the same physical drawer on, so summing every shift's float would
		// count the same cash once per handover.
		if !firstFloatSeen {
			info.OpeningFloat = int(sp.OpeningFloat)
			firstFloatSeen = true
		}

		// R4 — sum per rate BUCKET (8% with 8%), never merged.
		for _, tb := range sp.TaxBreakdown {
			a := byRate[tb.Rate]
			if a == nil {
				a = &service.ShiftTaxRateLine{Rate: tb.Rate}
				byRate[tb.Rate] = a
				rateOrder = append(rateOrder, tb.Rate)
			}
			a.TaxableSales += int(tb.Taxable)
			a.Tax += int(tb.Tax)
		}

		// plan-046 step 2 — each section is aggregated only from members that
		// actually recorded it; HasDetail flips once ANY member did, so the
		// formatter can omit a section a legacy chain never captured.
		if sp.Counts != nil {
			info.HasDetail = true
			info.ItemCount += sp.Counts.ItemCount
			info.GuestCount += sp.Counts.GuestCount
		}
		if sp.Voids != nil {
			info.HasDetail = true
			info.VoidUnpaidCount += sp.Voids.UnpaidCount
			info.VoidUnpaidAmount += int(sp.Voids.UnpaidAmount)
			info.VoidPaidCount += sp.Voids.PaidCount
			info.VoidPaidAmount += int(sp.Voids.PaidAmount)
		}
		if sp.CashEvents != nil {
			info.HasDetail = true
			info.PaidInCount += sp.CashEvents.PaidInCount
			info.PaidOutCount += sp.CashEvents.PaidOutCount
		}
		for _, d := range sp.Discounts {
			info.HasDetail = true
			a := byDiscount[d.Label]
			if a == nil {
				a = &service.ShiftDiscountLine{Label: d.Label}
				byDiscount[d.Label] = a
				discountOrder = append(discountOrder, d.Label)
			}
			a.Count += d.Count
			a.Amount += int(d.Amount)
		}
		// 金種 is the LAST count for the same reason as レジ金額: the notes were
		// re-counted at every handover, so merging the mixes would multiply the
		// same physical cash. Replace, never accumulate.
		if len(sp.Denominations) > 0 {
			info.HasDetail = true
			byDenom = map[float64]*service.ShiftOpenDenomLine{}
			denomOrder = denomOrder[:0]
			for _, dn := range sp.Denominations {
				byDenom[dn.Value] = &service.ShiftOpenDenomLine{
					Value: int(dn.Value), Quantity: dn.Quantity, Subtotal: int(dn.Subtotal),
				}
				denomOrder = append(denomOrder, dn.Value)
			}
		}

		// 支払方法 — prefer the snapshot's REAL payment-method split, which
		// carries the transaction count and only the methods actually used.
		// `tenders` is the reconcile output: one row per till tender TYPE with an
		// expected amount and no count, so it lists every configured type
		// including the untouched ones (17 rows for a shop that took cash twice)
		// and can never reproduce the per-shift slip's 件数 column. It stays only
		// as the fallback for snapshots written before `payments` existed.
		if len(sp.Payments) > 0 {
			info.HasDetail = true
			for _, pm := range sp.Payments {
				key := pm.Code
				if key == "" {
					key = pm.Label
				}
				if key == "" {
					continue
				}
				p := byTender[key]
				if p == nil {
					p = &service.ShiftPaymentLine{Code: pm.Code, Label: pm.Label}
					byTender[key] = p
					tenderOrder = append(tenderOrder, key)
				}
				if p.Label == "" {
					p.Label = pm.Label
				}
				p.Count += pm.Count
				p.Amount += int(pm.Amount)
			}
		} else {
			for _, td := range sp.Tenders {
				if td.TenderKey == "" {
					continue
				}
				amt := 0.0
				switch {
				case td.ExpectedAmount != nil:
					amt = *td.ExpectedAmount
				case td.Expected != nil:
					amt = *td.Expected
				}
				if amt == 0 {
					continue // an untouched tender type is not a payment row
				}
				p := byTender[td.TenderKey]
				if p == nil {
					p = &service.ShiftPaymentLine{Code: td.TenderKey}
					byTender[td.TenderKey] = p
					tenderOrder = append(tenderOrder, td.TenderKey)
				}
				p.Amount += int(amt)
			}
		}
	}
	if len(info.Shifts) == 0 {
		return nil, sql.ErrNoRows
	}

	info.ShiftCount = len(info.Shifts)
	sort.Float64s(rateOrder)
	for _, r := range rateOrder {
		info.TaxByRate = append(info.TaxByRate, *byRate[r])
	}

	// The per-rate buckets are built from ITEM lines only, so the service charge
	// and its tax sit outside them. Recover both as the residual against the
	// figures that DO include them, or the printed columns cannot be added up:
	//
	//   net    = (subtotal − discount) + service        → service = net − Σ taxable
	//   tax    = item tax + service tax                 → svc tax = tax − Σ tax
	//
	// Both are clamped at 0 so a legacy snapshot (whose `taxable` was the raw
	// subtotal, before the 課税売上 fix) can't print a negative service charge.
	var sumTaxable, sumRateTax int
	for _, t := range info.TaxByRate {
		sumTaxable += t.TaxableSales
		sumRateTax += t.Tax
	}
	if len(info.TaxByRate) > 0 {
		if v := info.Net - sumTaxable; v > 0 {
			info.ServiceCharge = v
		}
		if v := info.TaxTotal - sumRateTax; v > 0 {
			info.ServiceChargeTax = v
		}
	}

	// Named discounts, largest first.
	sort.SliceStable(discountOrder, func(i, j int) bool {
		return byDiscount[discountOrder[i]].Amount > byDiscount[discountOrder[j]].Amount
	})
	for _, k := range discountOrder {
		info.Discounts = append(info.Discounts, *byDiscount[k])
	}
	// Denominations, largest face value first — the order a drawer is counted in.
	sort.Sort(sort.Reverse(sort.Float64Slice(denomOrder)))
	for _, v := range denomOrder {
		info.Denominations = append(info.Denominations, *byDenom[v])
	}

	// Tender rows, largest first, labelled from the local payment-method mirror
	// (the snapshot carries only the tender key).
	sort.SliceStable(tenderOrder, func(i, j int) bool {
		return byTender[tenderOrder[i]].Amount > byTender[tenderOrder[j]].Amount
	})
	for _, k := range tenderOrder {
		p := byTender[k]
		var label sql.NullString
		_ = s.db.QueryRow(
			`SELECT name FROM payment_methods WHERE code = ? LIMIT 1`, p.Code).Scan(&label)
		if label.Valid && label.String != "" {
			p.Label = label.String
		}
		info.Payments = append(info.Payments, *p)
	}

	_ = s.db.QueryRow(`SELECT code FROM tills WHERE id = ?`, tillID).Scan(&info.TillCode)
	loc := s.shopLocation()
	info.OpenedAt = fmtShiftTime(firstOpened, loc)
	info.ClosedAt = fmtShiftTime(lastClosed, loc)
	info.Currency = s.shopSetting("currency_code", "JPY")

	// Section toggles read LIVE at print time, same as the single-shift slip.
	info.ShowPaymentMethods = s.shopSetting("close_report_payment_methods", "true") != "false"
	info.ShowServiceCharge = s.shopSetting("close_report_service_charge", "true") != "false"
	info.ShowDrawerCheck = s.shopSetting("close_report_drawer_check", "true") != "false"
	info.ShowDenominations = s.shopSetting("close_report_denominations", "true") != "false"
	// P5-9 — the toggle is read LIVE at print time (same as FormatShiftReport).
	info.ShowTaxBreakdown = s.shopSetting("close_report_tax_breakdown", "true") != "false"
	return info, nil
}

// handleLANPrintChainReport prints the plan-046 chain aggregate (kết ca cuối)
// slip. POST /api/lan/print/chain-report {chain_id, shop_slug}.
func (s *Server) handleLANPrintChainReport(w http.ResponseWriter, r *http.Request) {
	if _, ok := DeviceFromContext(r.Context()); !ok {
		writeError(w, http.StatusUnauthorized, "not authenticated")
		return
	}
	var body struct {
		ChainID  string `json:"chain_id"`
		ShopSlug string `json:"shop_slug"`
	}
	if err := readJSON(r, &body); err != nil || body.ChainID == "" {
		writeError(w, http.StatusBadRequest, "chain_id required")
		return
	}

	info, err := s.buildChainReport(body.ChainID)
	if err == sql.ErrNoRows {
		writeError(w, http.StatusNotFound, "chain not found")
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
		PaperWidth:        42,
		PhysicalWidth:     rp.CharWidth(),
		StoreName:         s.storeName(),
		StoreSubName:      s.settingValue("workstation_brand_name"),
		StoreAddress:      s.settingValue("workstation_branch_address"),
		StorePhone:        s.settingValue("workstation_branch_phone"),
		StoreOrganization: s.settingValue("workstation_organization_name"),
		// Resolved HQ→Shop→workstation-override chain (see printLabelLocale),
		// same source every other slip type uses.
		Locale: s.printLabelLocale(),
	}
	// plan-053 T3.6 tầng 2 (#1914) — call site 4/13.
	slip, templateVersion := s.renderMoneySlip(
		service.NewChainReportRenderData(config, *info),
		service.PrintRenderProfileFor(rp.Profile(), ""),
		config.Locale,
		func() []byte { return service.FormatChainReport(config, *info) },
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
		Payload:         map[string]any{"template": "chain_report", "ref": body.ChainID},
	}, printErr)

	if printErr != nil {
		writeServerError(w, r, printErr)
		return
	}

	s.auditLogPOS(r, "till.chain_report_printed", "till_session", body.ChainID, auditDetails(map[string]any{
		"chain_id":    body.ChainID,
		"shift_count": info.ShiftCount,
	}))
	writeJSON(w, http.StatusOK, map[string]any{"status": "printed", "shift_count": info.ShiftCount})
}
