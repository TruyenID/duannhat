package service

import "strings"

// shiftLabels is the full label set for one language of the 精算 (cashier-shift
// settlement / Z) slip. Using a typed struct instead of a map[string]string
// means a forgotten label is a COMPILE error, not a blank line on a printed
// accounting document.
//
// Encoding constraint (critical): the thermal path encodes every string with
// japanese.ShiftJIS (see internal/printer/escpos/encoder.go). Shift_JIS covers
// ASCII + kana/kanji but NOT Vietnamese combining diacritics — an accented
// rune fails to encode and falls back to raw UTF-8 bytes, printing garbage.
// Therefore the Vietnamese label set below is intentionally ASCII-FOLDED
// (no dấu), matching the existing kitchen/debt/paid templates ("Tong cong",
// "Thanh tien", "Phuong thuc"). Do not add diacritics here.
type shiftLabels struct {
	Title         string // big centered header (精算 / SETTLEMENT)
	HandoverTitle string // plan-046 — big centered header for a handover slip (引き継ぎ)
	Till          string // レジ{code} prefix
	Period        string // 対象期間 — opened-at line prefix (closed-at prints bare, no prefix)
	// plan-046 chain — position prefix + the chain-slip header/index labels.
	ServiceChargeTax string // サービス料消費税 — tax on the service charge
	ChainTitle       string // 精算（チェーン） — chain-aggregate slip title
	ChainLabel       string // チェーン — chain-id prefix
	ChainShift       string // "シフト" / "Shift " / "Ca "
	ChainShiftUnit   string // " シフト" / " shifts" / " ca" — the "N shifts" suffix
	ChainHandover    string // 引き継ぎ
	ChainFinal       string // 精算
	Phone            string // 電話番号 prefix

	GrossSales string // 総売上
	ItemUnit   string // 個 — trails the item count on its own right-aligned line
	NetSales   string // 純売上
	GuestUnit  string // 人 — trails the guest count
	TaxTotal   string // 消費税総額

	SalesBreakdown string // 売上内訳
	TaxableSales   string // 課税売上
	TaxBreakdown   string // 消費税内訳
	Tax            string // 消費税
	// RateTarget heads a per-rate row in the 消費税内訳 / 売上内訳 breakdown
	// ("8%対象" / "10%対象"). Sprintf template consuming the rate (plan-043
	// T4.2). Only used when close_report_tax_breakdown is on AND the shift's
	// orders carry per-line rate snapshots.
	RateTarget string // "%s%%対象"

	PaymentMethods string // 支払方法
	NonCashChange  string // 現金以外おつり
	NonCashUnpaid  string // 不支払額
	NonCashPaid    string // 支払額

	Discounts       string // 割引・割増
	DiscountGeneric string // 割引 — the collapsed "no named coupons" total row
	ItemDiscount    string // 個別割引
	ItemSurcharge   string // 個別割増

	ServiceCharge string // サービス料
	Denomination  string // 金種 — closing cash-count section header
	PieceUnit     string // 枚 — trails a denomination piece count

	AcctCorrection string // 会計修正
	CheckCount     string // 会計回数

	CashMovementTotal string // 入出金合計金額
	PaidIn            string // 入金
	PaidOut           string // 出金

	VoidBills  string // 伝票削除
	VoidUnpaid string // 未会計
	VoidPaid   string // 会計済

	DrawerCheck  string // レジ点検
	CountedCash  string // レジ金額
	ExpectedCash string // 想定レジ金額
	Variance     string // 過不足
	Operator     string // 担当者
	OperatorNone string // 未設定 — operator fallback when unset

	CountUnit string // 件 — trails a transaction count inside the right-hand block
}

// shiftLabelsJA is the reference layout. Title reuses the shiftReportTitle
// package const so the existing reference-slip test keeps its single anchor.
var shiftLabelsJA = shiftLabels{
	Title:         shiftReportTitle,
	HandoverTitle: "引き継ぎ",
	Till:          "レジ",
	Period:        "対象期間",
	Phone:         "電話番号",

	GrossSales: "総売上",
	ItemUnit:   "個",
	NetSales:   "純売上",
	GuestUnit:  "人",
	TaxTotal:   "消費税総額",

	SalesBreakdown: "売上内訳",
	TaxableSales:   "課税売上",
	TaxBreakdown:   "消費税内訳",
	Tax:            "消費税",
	RateTarget:     "%s%%対象",

	PaymentMethods: "支払方法",
	NonCashChange:  "現金以外おつり",
	NonCashUnpaid:  "不支払額",
	NonCashPaid:    "支払額",

	Discounts:       "割引・割増",
	DiscountGeneric: "割引",
	ItemDiscount:    "個別割引",
	ItemSurcharge:   "個別割増",

	ServiceCharge: "サービス料",
	Denomination:  "金種",
	PieceUnit:     "枚",

	AcctCorrection: "会計修正",
	CheckCount:     "会計回数",

	CashMovementTotal: "入出金合計金額",
	PaidIn:            "入金",
	PaidOut:           "出金",

	VoidBills:  "伝票削除",
	VoidUnpaid: "未会計",
	VoidPaid:   "会計済",

	DrawerCheck:  "レジ点検",
	CountedCash:  "レジ金額",
	ExpectedCash: "想定レジ金額",
	Variance:     "過不足",
	Operator:     "担当者",
	OperatorNone: "未設定",

	CountUnit:        "件",
	ChainShift:       "シフト",
	ChainShiftUnit:   " シフト",
	ChainHandover:    "引き継ぎ",
	ChainFinal:       "精算",
	ChainTitle:       "精算（チェーン）",
	ChainLabel:       "チェーン",
	ServiceChargeTax: "サービス料消費税",
}

// shiftLabelsEN — English. Kept concise so long labels + a right-aligned
// amount still fit a 42-col slip.
var shiftLabelsEN = shiftLabels{
	Title:         "SETTLEMENT",
	HandoverTitle: "HANDOVER",
	Till:          "Reg",
	Period:        "Period",
	Phone:         "Tel",

	GrossSales: "Gross sales",
	ItemUnit:   " items",
	NetSales:   "Net sales",
	GuestUnit:  " guests",
	TaxTotal:   "Total tax",

	SalesBreakdown: "Sales breakdown",
	TaxableSales:   "Taxable sales",
	TaxBreakdown:   "Tax breakdown",
	Tax:            "Tax",
	RateTarget:     "%s%% taxable",

	PaymentMethods: "Payment methods",
	NonCashChange:  "Non-cash change",
	NonCashUnpaid:  "Unpaid",
	NonCashPaid:    "Paid",

	Discounts:       "Discounts",
	DiscountGeneric: "Discount",
	ItemDiscount:    "Item discount",
	ItemSurcharge:   "Item surcharge",

	ServiceCharge: "Service charge",
	Denomination:  "Denomination",
	PieceUnit:     "x",

	AcctCorrection: "Correction",
	CheckCount:     "Check count",

	CashMovementTotal: "Cash in/out total",
	PaidIn:            "Cash in",
	PaidOut:           "Cash out",

	VoidBills:  "Voided bills",
	VoidUnpaid: "Unpaid",
	VoidPaid:   "Paid",

	DrawerCheck:  "Drawer check",
	CountedCash:  "Counted cash",
	ExpectedCash: "Expected cash",
	Variance:     "Variance",
	Operator:     "Operator",
	OperatorNone: "(not set)",

	CountUnit:        "x",
	ChainShift:       "Shift ",
	ChainShiftUnit:   " shifts",
	ChainHandover:    "handover",
	ChainFinal:       "final",
	ChainTitle:       "CHAIN SETTLEMENT",
	ChainLabel:       "Chain",
	ServiceChargeTax: "Service charge tax",
}

// shiftLabelsVI — Vietnamese, ASCII-folded (no diacritics — see type doc).
var shiftLabelsVI = shiftLabels{
	Title:         "KET CA",
	HandoverTitle: "BAN GIAO CA",
	Till:          "Quay",
	Period:        "Ky",
	Phone:         "SDT",

	GrossSales: "Tong doanh thu",
	ItemUnit:   " mon",
	NetSales:   "Doanh thu thuan",
	GuestUnit:  " khach",
	TaxTotal:   "Tong thue",

	SalesBreakdown: "Chi tiet doanh thu",
	TaxableSales:   "DT chiu thue",
	TaxBreakdown:   "Chi tiet thue",
	Tax:            "Thue",
	RateTarget:     "Chiu thue %s%%",

	PaymentMethods: "Phuong thuc TT",
	NonCashChange:  "Tien thoi khac",
	NonCashUnpaid:  "Chua tra",
	NonCashPaid:    "Da tra",

	Discounts:       "Giam gia",
	DiscountGeneric: "Giam gia",
	ItemDiscount:    "Giam gia mon",
	ItemSurcharge:   "Phu thu mon",

	ServiceCharge: "Phi phuc vu",
	Denomination:  "Menh gia",
	PieceUnit:     "x",

	AcctCorrection: "Dieu chinh",
	CheckCount:     "So lan TT",

	CashMovementTotal: "Tong thu chi",
	PaidIn:            "Thu tien",
	PaidOut:           "Chi tien",

	VoidBills:  "Huy hoa don",
	VoidUnpaid: "Chua TT",
	VoidPaid:   "Da TT",

	DrawerCheck:  "Kiem quy",
	CountedCash:  "Tien dem",
	ExpectedCash: "Tien du kien",
	Variance:     "Chenh lech",
	Operator:     "Nguoi phu trach",
	OperatorNone: "(chua dat)",

	CountUnit:        "x",
	ChainShift:       "Ca ",
	ChainShiftUnit:   " ca",
	ChainHandover:    "ban giao",
	ChainFinal:       "ket ca cuoi",
	ChainTitle:       "KET CA CUOI (CHUOI)",
	ChainLabel:       "Chuoi",
	ServiceChargeTax: "Thue phi phuc vu",
}

// labelsFor returns the label set for a locale, defaulting to Japanese for
// empty/unknown input (mirrors normalizePrintLocale on the handler side, so a
// nil-safe path exists even if a caller builds PrintJobConfig directly).
func labelsFor(locale string) shiftLabels {
	switch strings.ToLower(strings.TrimSpace(locale)) {
	case "en":
		return shiftLabelsEN
	case "vi":
		return shiftLabelsVI
	default:
		return shiftLabelsJA
	}
}

// paymentMethodLabels localizes the GENERIC tender codes the POS emits. Brand
// wallets (paypay, linepay, …) are intentionally absent — they carry their own
// proper noun and fall through to the Cloud-synced payment_methods.name.
//
// Keyed by the lowercased payment_method code, then by locale. A code present
// here but missing a given locale, or absent entirely, falls back to the
// Cloud name (see localizePaymentLabel).
var paymentMethodLabels = map[string]map[string]string{
	"cash":          {"ja": "現金", "en": "Cash", "vi": "Tien mat"},
	"card":          {"ja": "カード", "en": "Card", "vi": "The"},
	"credit_card":   {"ja": "クレジットカード", "en": "Credit card", "vi": "The tin dung"},
	"credit":        {"ja": "クレジットカード", "en": "Credit card", "vi": "The tin dung"},
	"debit_card":    {"ja": "デビットカード", "en": "Debit card", "vi": "The ghi no"},
	"qr":            {"ja": "QRコード", "en": "QR code", "vi": "Ma QR"},
	"qr_code":       {"ja": "QRコード", "en": "QR code", "vi": "Ma QR"},
	"e_wallet":      {"ja": "電子マネー", "en": "E-wallet", "vi": "Vi dien tu"},
	"e_money":       {"ja": "電子マネー", "en": "E-money", "vi": "Tien dien tu"},
	"emoney":        {"ja": "電子マネー", "en": "E-money", "vi": "Tien dien tu"},
	"bank_transfer": {"ja": "銀行振込", "en": "Bank transfer", "vi": "Chuyen khoan"},
	"transfer":      {"ja": "振込", "en": "Transfer", "vi": "Chuyen khoan"},
	"on_account":    {"ja": "掛売", "en": "On account", "vi": "Ghi no"},
	"voucher":       {"ja": "商品券", "en": "Voucher", "vi": "Phieu mua hang"},
	"points":        {"ja": "ポイント", "en": "Points", "vi": "Diem"},
	"point":         {"ja": "ポイント", "en": "Points", "vi": "Diem"},
}

// localizePaymentLabel picks the display label for one payment line. It prefers
// a localized generic label (by tender code), else the Cloud-synced name
// (payment_methods.name, already the code-fallback per the SQL), so brand
// wallets and shop-custom methods print exactly as configured upstream.
func localizePaymentLabel(locale, code, cloudName string) string {
	if m, ok := paymentMethodLabels[strings.ToLower(strings.TrimSpace(code))]; ok {
		if v := m[locale]; v != "" {
			return v
		}
	}
	if strings.TrimSpace(cloudName) != "" {
		return cloudName
	}
	return code
}
