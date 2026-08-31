package service

import "time"

// plan-053 M3 (#1171) — the ENGINE side of the render contract (DESIGN §7).
//
// "The renderer computes nothing" only means something if the computing happens
// somewhere visible. These builders are that somewhere: each one mirrors, line
// for line, the arithmetic the matching Format*Ticket entry point does today
// before it starts laying bytes down.
//
// Keeping them here (rather than inside a test helper) is deliberate — the
// golden gate must compare the renderer against the formatter using the SAME
// numbers a production caller would supply. A test that computed its own totals
// would prove the test right, not the migration safe.

// NewKitchenRenderData mirrors FormatKitchenTicket: the headline figure is the
// sum of THIS BATCH's lines, never the whole order, so a partial fire's ticket
// and its tax blocks agree with each other.
func NewKitchenRenderData(order *Order, items []Item, ticketNo int, config PrintJobConfig) *PrintRenderData {
	batchTotal := 0
	for _, item := range items {
		batchTotal += item.UnitPrice * item.Quantity
		for _, t := range item.Toppings {
			batchTotal += t.UnitPrice * t.Quantity
		}
	}
	return &PrintRenderData{
		Kind:   "kitchen",
		Config: config,
		Order:  order,
		Items:  items,
		// DeltaBill is what keeps the shared bill template honest on this sheet:
		// it suppresses the whole-order rows (subtotal, ledger discounts, service
		// charge) and routes the per-rate tax block to a batch recompute (#2170).
		// Without it the kitchen ticket would set the ORDER's money beside a
		// list holding only the items just fired.
		DeltaBill: true,
		TicketNo:  ticketNo,
		Total:     batchTotal,
	}
}

// NewRunnerRenderData mirrors FormatRunnerTicket.
func NewRunnerRenderData(order *Order, items []Item, ticketNo int, config PrintJobConfig) *PrintRenderData {
	total := orderPrintTotal(order)
	return &PrintRenderData{
		Kind:      "runner",
		Config:    config,
		Order:     order,
		Items:     items,
		TicketNo:  ticketNo,
		Total:     total,
		Remaining: total - order.PaidAmount,
		ShowQR:    true,
	}
}

// NewDeltaQRRenderData mirrors FormatDeltaQRTicket: the total is the sum of the
// newly-fired lines (the same basis as the kitchen ticket's batch total), and
// DeltaBill suppresses the whole-order subtotal/service rows that would dwarf it.
func NewDeltaQRRenderData(order *Order, items []Item, config PrintJobConfig) *PrintRenderData {
	total := 0
	for _, it := range items {
		total += it.UnitPrice * it.Quantity
		for _, t := range it.Toppings {
			total += t.UnitPrice * t.Quantity
		}
	}
	return &PrintRenderData{
		Kind:      "delta_qr",
		Config:    config,
		Order:     order,
		Items:     items,
		Total:     total,
		ShowQR:    true,
		DeltaBill: true,
	}
}

// NewPaidRenderData mirrors FormatPaidTicket: the headline is this slip's
// sub-bill when the payment carries one (a split), else the order's gross —
// without that distinction every split slip would print the whole bill.
func NewPaidRenderData(order *Order, items []Item, ticketNo int, config PrintJobConfig, slip PaymentSlipInfo) *PrintRenderData {
	total := slip.BillTotal
	if total <= 0 {
		total = orderPrintTotal(order)
	}
	paid := slip.AmountPaid
	if paid <= 0 {
		paid = total
	}
	return &PrintRenderData{
		Kind:      "receipt",
		Config:    config,
		Order:     order,
		Items:     items,
		TicketNo:  ticketNo,
		Total:     total,
		Remaining: slip.Remaining,
		PaidShown: paid,
		Slip:      &slip,
		// plan-052 P-10b — carries the number AppendPrintHistory assigned to
		// the `reprint_marker` block. The renderer never mints one (P-12).
		ReprintNumber: slip.ReprintNumber,
	}
}

// NewRedInvoiceRenderData mirrors FormatRedInvoiceTicket — the paid receipt plus
// a customer-name line that is always present (entered, or a blank underline to
// hand-write).
func NewRedInvoiceRenderData(order *Order, items []Item, config PrintJobConfig, slip PaymentSlipInfo) *PrintRenderData {
	d := NewPaidRenderData(order, items, 0, config, slip)
	d.Kind = "red_invoice"
	return d
}

// NewRemainingRenderData mirrors FormatRemainingTicket: the headline stays the
// order's gross total; only the 残額 row carries the outstanding amount.
func NewRemainingRenderData(order *Order, items []Item, ticketNo int, config PrintJobConfig, remaining int) *PrintRenderData {
	if remaining < 0 {
		remaining = 0
	}
	return &PrintRenderData{
		Kind:      "remaining",
		Config:    config,
		Order:     order,
		Items:     items,
		TicketNo:  ticketNo,
		Total:     orderPrintTotal(order),
		Remaining: remaining,
		ShowQR:    true,
	}
}

// NewVatInvoiceRenderData mirrors FormatVatInvoice.
//
// #1493 — KIND suy ra theo CÙNG luật mà `FormatVatInvoice` dùng để chọn layout
// (`printCountryPicksJapaneseInvoice`): quán JP ⇒ 適格簡易請求書, quán VN ⇒ hoá
// đơn GTGT, chưa biết quốc gia ⇒ giữ nguyên nhánh locale cũ.
//
// Ghim cứng "vat_invoice" ở đây là một cái bẫy đặt sẵn cho tầng sau:
// `RenderPrintTemplate` chọn plan theo `data.Kind` TRƯỚC `def.Kind`, nên một
// quán Nhật sẽ được render bằng plan Việt trong khi Cloud đã gửi đúng template
// Nhật — và triệu chứng chỉ là "thiếu 領収書", không nói vì sao.
func NewVatInvoiceRenderData(info VatInvoiceInfo, config PrintJobConfig) *PrintRenderData {
	kind := "vat_invoice"
	if printCountryPicksJapaneseInvoice(config.OperatingCountry, info.Locale) {
		kind = "qualified_simplified_invoice"
	}

	return &PrintRenderData{
		Kind:          kind,
		Config:        config,
		Vat:           &info,
		ReprintNumber: info.ReprintNumber,
	}
}

// NewVoidNoticeRenderData mirrors FormatVoidNotice.
func NewVoidNoticeRenderData(info VatInvoiceInfo, voidReason string, voidedAt time.Time, config PrintJobConfig) *PrintRenderData {
	return &PrintRenderData{
		Kind:       "void_notice",
		Config:     config,
		Vat:        &info,
		VoidReason: voidReason,
		VoidedAt:   voidedAt,
	}
}

// NewDebtSlipRenderData mirrors FormatDebtSlip: an unset debt amount means the
// whole bill went on account, and "already paid" is whatever is left over.
func NewDebtSlipRenderData(order *Order, items []Item, config PrintJobConfig, info DebtSlipInfo) *PrintRenderData {
	total := orderPrintTotal(order)
	debt := info.DebtAmount
	if debt <= 0 {
		debt = total
	}
	paid := total - debt
	if paid < 0 {
		paid = 0
	}
	return &PrintRenderData{
		Kind:          "debt_slip",
		Config:        config,
		Order:         order,
		Items:         items,
		Total:         total,
		PaidShown:     paid,
		Debt:          &info,
		ReprintNumber: info.ReprintNumber,
	}
}

// NewShiftOpenRenderData mirrors FormatShiftOpenReport.
func NewShiftOpenRenderData(config PrintJobConfig, info ShiftOpenReportInfo) *PrintRenderData {
	return &PrintRenderData{Kind: "shift_open", Config: config, ShiftOpen: &info}
}

// NewShiftReportRenderData mirrors FormatShiftReport.
func NewShiftReportRenderData(config PrintJobConfig, info ShiftReportInfo) *PrintRenderData {
	return &PrintRenderData{Kind: "shift_report", Config: config, Shift: &info}
}

// NewChainReportRenderData mirrors FormatChainReport, which is FormatShiftReport
// fed the chain's projection — one renderer, cumulative figures. Two layouts for
// "the same report, totalled" is how the two drift apart.
func NewChainReportRenderData(config PrintJobConfig, info ChainReportInfo) *PrintRenderData {
	shift := info.asShiftReport()
	return &PrintRenderData{Kind: "chain_report", Config: config, Shift: &shift}
}

// NewTablePaidRenderData mirrors FormatTablePaid.
func NewTablePaidRenderData(config PrintJobConfig, info TablePaidInfo) *PrintRenderData {
	return &PrintRenderData{Kind: "table_paid", Config: config, TablePaid: &info}
}
