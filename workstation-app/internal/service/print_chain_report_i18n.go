package service

import "strings"

// chainLabels is the label set for one language of the plan-046 chain aggregate
// (kết ca cuối / 精算 chain) slip. Same encoding constraint as shiftLabels: the
// Vietnamese set is ASCII-folded (Shift_JIS can't encode diacritics).
type chainLabels struct {
	Title      string // big centered header (chain aggregate)
	Chain      string // "Chuỗi" / チェーン — chain-id prefix
	Shift      string // per-shift block header prefix ("Ca")
	Handover   string // settlement_kind=handover label
	Final      string // settlement_kind=final label
	Period     string // 対象期間 — opened→closed span
	ShiftCount string // "%d ca" total-shift line
	Counted    string // レジ金額 / counted cash
	Variance   string // 過不足
	Revenue    string // 総売上 (gross)
	GrandTotal string // GRAND TOTAL block header
	NetSales   string // 純売上
	TaxTotal   string // 消費税総額
	TaxBreak   string // 消費税内訳
	RateTarget string // "%s%%対象"

	// Cumulative sections — every figure below comes from the per-shift
	// settlement_snapshot, so the chain slip shows the same detail the operator
	// saw on each shift's own 精算 rather than three summary rows.
	SalesBreak     string // 売上内訳 — per-rate taxable base
	Discount       string // 割引 (total, from revenue.discount)
	ServiceCharge  string // サービス料 — the part of net sales outside the rate buckets
	SvcChargeTax   string // サービス料消費税
	Checks         string // 会計回数
	PaymentMethods string // 支払方法
	Expected       string // 想定レジ金額
	OpeningFloat   string // 釣銭準備金 (chain start — first shift's float)
	CashSales      string // 現金売上
	PaidIn         string // 入金
	PaidOut        string // 出金
	Period2        string // per-shift time span label (indented)

	// plan-046 step 2 — sections recovered from the enriched snapshot.
	ItemCount     string // 点数
	GuestCount    string // 人 (guests)
	DiscountBreak string // 割引・割増 — named coupon rows
	Voids         string // 伝票削除
	VoidUnpaid    string // 未会計 (voided before payment)
	VoidPaid      string // 会計済 (voided after payment)
	Denominations string // 金種
	CountUnit     string // 件 / x
	PieceUnit     string // 枚 / pcs
}

var chainLabelsJA = chainLabels{
	Title:          "精算（チェーン）",
	Chain:          "チェーン",
	Shift:          " シフト",
	Handover:       "引き継ぎ",
	Final:          "精算",
	Period:         "対象期間",
	ShiftCount:     "%d シフト",
	Counted:        "レジ金額",
	Variance:       "過不足",
	Revenue:        "総売上",
	GrandTotal:     "合計",
	NetSales:       "純売上",
	TaxTotal:       "消費税総額",
	TaxBreak:       "消費税内訳",
	RateTarget:     "%s%%対象",
	SalesBreak:     "売上内訳",
	Discount:       "割引",
	ServiceCharge:  "サービス料",
	SvcChargeTax:   "サービス料消費税",
	Checks:         "会計回数",
	PaymentMethods: "支払方法",
	Expected:       "想定レジ金額",
	OpeningFloat:   "釣銭準備金",
	CashSales:      "現金売上",
	PaidIn:         "入金",
	PaidOut:        "出金",
	Period2:        "期間",
	ItemCount:      "点数",
	GuestCount:     "客数",
	DiscountBreak:  "割引・割増",
	Voids:          "伝票削除",
	VoidUnpaid:     "未会計",
	VoidPaid:       "会計済",
	Denominations:  "金種",
	CountUnit:      "件",
	PieceUnit:      "枚",
}

var chainLabelsEN = chainLabels{
	Title:          "CHAIN SETTLEMENT",
	Chain:          "Chain",
	Shift:          "Shift",
	Handover:       "handover",
	Final:          "final",
	Period:         "Period",
	ShiftCount:     "%d shifts",
	Counted:        "Counted",
	Variance:       "Variance",
	Revenue:        "Gross",
	GrandTotal:     "GRAND TOTAL",
	NetSales:       "Net sales",
	TaxTotal:       "Total tax",
	TaxBreak:       "Tax breakdown",
	RateTarget:     "%s%% taxable",
	SalesBreak:     "Sales breakdown",
	Discount:       "Discount",
	ServiceCharge:  "Service charge",
	SvcChargeTax:   "Service charge tax",
	Checks:         "Checks",
	PaymentMethods: "Payment methods",
	Expected:       "Expected cash",
	OpeningFloat:   "Opening float",
	CashSales:      "Cash sales",
	PaidIn:         "Paid in",
	PaidOut:        "Paid out",
	Period2:        "Period",
	ItemCount:      "Items",
	GuestCount:     "Guests",
	DiscountBreak:  "Discounts",
	Voids:          "Voided bills",
	VoidUnpaid:     "Unpaid",
	VoidPaid:       "Paid",
	Denominations:  "Denominations",
	CountUnit:      "x",
	PieceUnit:      "pcs",
}

var chainLabelsVI = chainLabels{
	Title:          "KET CA CUOI (CHUOI)",
	Chain:          "Chuoi",
	Shift:          "Ca",
	Handover:       "ban giao",
	Final:          "ket ca cuoi",
	Period:         "Ky",
	ShiftCount:     "%d ca",
	Counted:        "Tien dem",
	Variance:       "Chenh lech",
	Revenue:        "Tong DT",
	GrandTotal:     "TONG CONG",
	NetSales:       "Doanh thu thuan",
	TaxTotal:       "Tong thue",
	TaxBreak:       "Chi tiet thue",
	RateTarget:     "Chiu thue %s%%",
	SalesBreak:     "Chi tiet doanh thu",
	Discount:       "Giam gia",
	ServiceCharge:  "Phi phuc vu",
	SvcChargeTax:   "Thue phi phuc vu",
	Checks:         "So hoa don",
	PaymentMethods: "Phuong thuc TT",
	Expected:       "Tien mat du kien",
	OpeningFloat:   "Quy dau ca",
	CashSales:      "Doanh thu tien mat",
	PaidIn:         "Nop vao",
	PaidOut:        "Rut ra",
	Period2:        "Ky",
	ItemCount:      "So mon",
	GuestCount:     "So khach",
	DiscountBreak:  "Giam gia chi tiet",
	Voids:          "Huy don",
	VoidUnpaid:     "Chua thanh toan",
	VoidPaid:       "Da thanh toan",
	Denominations:  "Menh gia",
	CountUnit:      "x",
	PieceUnit:      "to",
}

func chainLabelsFor(locale string) chainLabels {
	switch strings.ToLower(strings.TrimSpace(locale)) {
	case "en":
		return chainLabelsEN
	case "vi":
		return chainLabelsVI
	default:
		return chainLabelsJA
	}
}
