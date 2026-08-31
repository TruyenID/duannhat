package service

// ChainShiftLine is one CONDENSED per-shift block in the chain aggregate slip
// (plan-046 T5.8, P5-8 — a compact 3-line summary per shift, NOT the full 9-section
// FormatShiftReport, so a 10-shift chain doesn't blow the paper roll).
type ChainShiftLine struct {
	Sequence    int
	Kind        string // "handover" | "final"
	CountedCash int
	Variance    int
	Gross       int

	// Per-shift detail — every field below already lives in that shift's
	// immutable settlement_snapshot, so showing it costs no new data and stays
	// R2-safe (a later refund can never retro-change a printed block).
	OpenedAt     string // preformatted
	ClosedAt     string // preformatted
	Net          int
	Tax          int
	Operator     string // 担当者 for THIS shift — who was accountable for it
	Discount     int
	ExpectedCash int
	CheckCount   int
}

// ChainReportInfo is the fully-aggregated chain (kết ca cuối) payload. The handler
// builds it by summing each chain member's immutable settlement_snapshot; the
// grand total is Σ(already-rounded per-shift figures) per rate bucket (R4).
type ChainReportInfo struct {
	ChainID    string
	TillCode   string
	OpenedAt   string // preformatted, first shift
	ClosedAt   string // preformatted, last (final) shift
	ShiftCount int
	Shifts     []ChainShiftLine

	// Grand total.
	CountedCash int
	Gross       int
	Net         int
	TaxTotal    int
	TaxByRate   []ShiftTaxRateLine // per-rate buckets (R4)

	// Cumulative detail — Σ of the per-shift snapshots, never re-derived from
	// live orders (R2/R4), so the grand total always reconciles with the paper
	// slips already filed for each shift.
	ExpectedCash int
	Variance     int
	Discount     int
	CheckCount   int
	CashSales    int
	PaidIn       int
	PaidOut      int
	// OpeningFloat is the FIRST shift's float — the cash the chain started with.
	// Summing every shift's float would be meaningless: each handover hands the
	// same physical drawer on, and the incoming cashier blind re-counts it (R3).
	OpeningFloat int
	// ServiceCharge is the part of net sales that sits OUTSIDE the per-rate
	// buckets, with its own tax. Without these two rows the slip cannot be added
	// up: Σ TaxByRate.Tax is short of TaxTotal by exactly the service-charge tax
	// (the rate buckets are built from item lines only), and an auditor totalling
	// the columns would find them disagreeing.
	ServiceCharge    int
	ServiceChargeTax int
	// Operator is the cashier who ENDED the chain. It pairs with the drawer
	// block, which reports that same final count — so the name printed beside
	// 過不足 is the person who made it. Every shift's own operator stays visible
	// in the per-shift index.
	Operator string

	// Payments is the per-tender split summed across the chain. Amounts only —
	// the snapshot's `tenders` carries no transaction count.
	Payments []ShiftPaymentLine

	// plan-046 step 2 — sections a chain settled BEFORE the enrichment simply
	// has no data for. HasDetail is false for such a chain and every section
	// below is omitted, because printing "0" for a figure nobody ever recorded
	// would be a false statement on an accounting document.
	HasDetail        bool
	ItemCount        int
	GuestCount       int
	VoidUnpaidCount  int
	VoidUnpaidAmount int
	VoidPaidCount    int
	VoidPaidAmount   int
	PaidInCount      int
	PaidOutCount     int
	Discounts        []ShiftDiscountLine
	Denominations    []ShiftOpenDenomLine

	Currency string
	// Section toggles, all read LIVE at print time exactly as the single-shift
	// slip does (P5-9) — never snapshotted, so a shop that turns a section off
	// sees it disappear from reprints too.
	ShowTaxBreakdown   bool
	ShowPaymentMethods bool
	ShowServiceCharge  bool
	ShowDrawerCheck    bool
	ShowDenominations  bool
}

// countAmount renders "3件 1,500円" when the chain recorded a transaction count,
// and just "1,500円" when it did not (a pre-enrichment chain) — so a legacy slip
// never implies a count of zero it never measured.
func countAmount(hasCount bool, count int, unit, amount string) string {
	if !hasCount {
		return amount
	}
	return formatPrice(count) + unit + " " + amount
}

// FormatChainReport renders the plan-046 chain aggregate thermal slip: a condensed
// block per shift + one GRAND TOTAL block. Pure/testable (mirrors FormatShiftReport).
func FormatChainReport(config PrintJobConfig, info ChainReportInfo) []byte {
	return FormatShiftReport(config, info.asShiftReport())
}

// asShiftReport projects the chain aggregate onto the 精算 payload so the chain
// slip renders through the SAME formatter as a handover slip: identical
// sections, identical labels, only the figures are cumulative. That equality is
// the requirement — a second hand-written layout would drift the moment either
// report was next edited.
func (info ChainReportInfo) asShiftReport() ShiftReportInfo {
	return ShiftReportInfo{
		IsChain:    true,
		ChainID:    info.ChainID,
		ShiftCount: info.ShiftCount,
		ChainIndex: info.Shifts,
		TillCode:   info.TillCode,
		OpenedAt:   info.OpenedAt,
		ClosedAt:   info.ClosedAt,
		Currency:   info.Currency,
		Operator:   info.Operator,

		GrossSales: info.Gross,
		NetSales:   info.Net,
		TaxTotal:   info.TaxTotal,
		ItemCount:  info.ItemCount,
		GuestCount: info.GuestCount,

		TaxBreakdown:        info.TaxByRate,
		Payments:            info.Payments,
		Discounts:           info.Discounts,
		DiscountTotalAmount: info.Discount,
		ServiceCharge:       info.ServiceCharge,
		ServiceChargeTax:    info.ServiceChargeTax,

		CheckCount:    info.CheckCount,
		PaidInCount:   info.PaidInCount,
		PaidInAmount:  info.PaidIn,
		PaidOutCount:  info.PaidOutCount,
		PaidOutAmount: info.PaidOut,

		VoidUnpaidCount:  info.VoidUnpaidCount,
		VoidUnpaidAmount: info.VoidUnpaidAmount,
		VoidPaidCount:    info.VoidPaidCount,
		VoidPaidAmount:   info.VoidPaidAmount,

		CountedCash:   info.CountedCash,
		ExpectedCash:  info.ExpectedCash,
		CashVariance:  info.Variance,
		Denominations: info.Denominations,

		ShowTaxBreakdown:   info.ShowTaxBreakdown,
		ShowPaymentMethods: info.ShowPaymentMethods,
		ShowServiceCharge:  info.ShowServiceCharge,
		ShowDrawerCheck:    info.ShowDrawerCheck,
		ShowDenominations:  info.ShowDenominations,
	}
}
