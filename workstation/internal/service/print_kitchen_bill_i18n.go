package service

import "strings"

// printLabels is the locale catalog for the kitchen ticket + the bill-style
// slips (runner / delta-QR / paid / remaining). A missing label is a compile
// error, not a blank line on a printed slip.
//
// Shift_JIS note: the thermal encoder is japanese.ShiftJIS. Japanese (kanji /
// kana) and ASCII encode fine, but Vietnamese combining diacritics do NOT — an
// accented rune fails to encode and prints garbage. So the Vietnamese set below
// is intentionally ASCII-FOLDED ("Thanh tien", "Phi phuc vu"), matching the
// existing print_shift_report_i18n.go / print_tax_i18n.go catalogs.
type printLabels struct {
	// Kitchen ticket header + order type
	OrderMethod string // "Cach dat" column
	TicketSeq   string // "STT" column (daily kitchen counter)
	OrderNo     string // "So HD" (order-code suffix) — shared with the bill footer
	Table       string // "Ban" — shared with the bill footer
	DineIn      string // order_type spot/dine_in
	Takeaway    string // order_type takeaway
	Spot        string // order_type spot label variant

	// PaymentState labels the takeaway-only slot the 卓 column leaves empty, on
	// the two slips that travel WITH THE FOOD (kitchen ticket + delta-QR hall
	// slip). A dine-in order settles at the table after eating, so its slips say
	// nothing about payment; a takeaway order is handed over at a counter, and
	// whoever hands it over has to know whether money was already taken.
	//
	// PaymentPaid / PaymentUnpaid are the two VALUES. Two, not three: takeaway
	// carries no split bill and no partial settlement (chủ dự án 2026-08-17), so
	// a "partly paid" label would name a state this flow cannot reach. Anything
	// short of the full amount therefore prints UNPAID — the direction that
	// makes staff ask rather than assume.
	//
	// They are deliberately SHORT, and that is a layout constraint rather than a
	// wording preference: the kitchen meta block enlarges its values ×2 and
	// steps the WHOLE row down to ×1 when any column stops fitting. A 13-column
	// "DA THANH TOAN" here would demote the row on every takeaway ticket —
	// including 伝票番号, the field the block exists to make legible.
	PaymentState  string // "Thanh toan" — nhãn ô trạng thái
	PaymentPaid   string // "DA TRA"
	PaymentUnpaid string // "CHUA TRA"

	// Item table (both kitchen + bill)
	Item  string // "San pham"
	Price string // "Thanh tien"

	KitchenTotal string // "Tong cong" — kitchen batch total
	NotePrefix   string // "Ghi chu: " — free-note prefix (both)

	// ToppingWaived labels the row that reconciles an item's topping rows with
	// what the shop actually charged for them, when a `free_up_to_n` group
	// waived some of them. See `printToppingWaiver`.
	//
	// It says WHAT HAPPENED ("free toppings"), not "adjustment": the whole
	// point of the row is that a customer reading the slip can see the discount
	// they were given, and a vague word turns a benefit into something that
	// looks like a rounding artefact. vi/en stay ASCII for Shift_JIS.
	ToppingWaived string

	// Bill titles (header right side)
	TitleKitchen   string // "PHIEU BEP" — the kitchen ticket shares the bill template
	TitleTableBill string // "HOA DON BAN"
	TitleNewItems  string // "MON VUA THEM"
	TitlePaid      string // "DA THANH TOAN"
	TitleRemaining string // "PHAN CON LAI"
	// TitleRedInvoice (#2062): "PHIEU THANH TOAN" in vi, "PAYMENT RECEIPT" in
	// en, 「領収書」 in ja.
	//
	// #1445 had made it "HOA DON DO" in EVERY locale, reasoning that the red
	// invoice is a Vietnamese statutory form and a form's name is part of the
	// form. That reasoning was RIGHT, and being right is exactly why the name
	// had to go: after #1779 this slip is no longer a hoá đơn GTGT at all — no
	// invoice number, no form/series code, no digital signature, no tax-authority
	// code, nothing transmitted. Wearing a statutory form's name while not being
	// that form is a false statement, not a wording preference.
	//
	// `ja` has been honest since #1890 (領収書 = a receipt, not an invoice); vi
	// and en now follow the same principle.
	//
	// NOT reusing the `receipt` kind's strings ("DA THANH TOAN" / "PAID"): the
	// two kinds print different paper (red_invoice always carries a customer-name
	// line), so a shared heading would make them indistinguishable in the till.
	//
	// vi/en stay ASCII, and that part is NOT a preference: half the fleet's
	// printers have no kanji ROM (DESIGN §3b) and would print boxes.
	//
	// 赤伝 was NOT an option: this system already uses it for 適格返還請求書
	// (the return invoice, #1123). Two different documents under one label.
	TitleRedInvoice string

	// ReprintMark is the copy marker's wording — "BAN IN" (vi/en) or 「再印刷」
	// (ja), printed as `<mark> #N` from copy 2 onward on all four money
	// documents. It lives in the catalog rather than in the renderer so the
	// legacy formatter and the template renderer read ONE source; the golden
	// gate compares their bytes.
	ReprintMark string

	// Bill footer rows
	CustomerLabel string // "Khach hang" — customer name line (takeaway + red-invoice)
	Phone         string // "SDT" — customer phone line (takeaway)
	PickupTime    string // "Gio lay" — takeaway scheduled pickup time
	Guest         string // "Khach" (split label)
	Subtotal      string // "Tam tinh"
	// Discount heads one ledger discount row (#2071) — `Giam gia (10%)  -¥91`.
	// The word comes from THIS catalog, never from the ledger row's frozen
	// `label` (unlocalized, possibly not Shift_JIS-encodable — see
	// OrderDiscountLine).
	Discount      string // "Giam gia"
	ServiceCharge string // "Phi phuc vu"
	Tax           string // "Thue" (legacy single-line fallback)
	Total         string // "Tong"
	PaidAmount    string // "Da thanh toan"
	PaymentMethod string // "Phuong thuc"
	Tendered      string // "Tien khach dua" — cash received from the customer
	Change        string // "Tien thoi lai" — change returned
	Remaining     string // "Con lai"

	// Split-bill visualization (share receipts)
	SplitTitle        string // "HOA DON CHIA" — top banner title
	SplitModeEqual    string // "Chia deu"
	SplitModeByItems  string // "Chia theo mon"
	SplitModeByAmount string // "Chia theo so tien"
	SplitPeople       string // "nguoi" — count unit ("2 nguoi")
	OrderTotal        string // "Tong don" — whole-order total context line
	SplitShare        string // "Phan chia" — this payer's share
}

// splitModeText resolves the per-mode banner text (Chia deu / Chia theo mon /
// Chia theo so tien) for a slip's split mode kind (equal | by_items | by_amount).
func (l printLabels) splitModeText(kind string) string {
	switch kind {
	case "by_items":
		return l.SplitModeByItems
	case "by_amount":
		return l.SplitModeByAmount
	default:
		return l.SplitModeEqual
	}
}

// printLabelsFor selects the catalog for a print locale. "en" / "vi" switch
// explicitly; everything else — empty / unknown / "ja" — falls back to Japanese
// (the store's native/accounting locale), so a slip with no resolvable locale is
// Japanese rather than blank. pos-web sends the operator's locale via Accept-
// Language on every LAN print call, resolved by handler.localeFromRequest (which
// also defaults unknown → "ja") before it reaches config.Locale.
func printLabelsFor(locale string) printLabels {
	switch strings.ToLower(strings.TrimSpace(locale)) {
	case "en":
		return printLabelsEN
	case "vi":
		return printLabelsVI
	default:
		return printLabelsJA
	}
}

var printLabelsVI = printLabels{
	OrderMethod: "Cach dat",
	TicketSeq:   "STT",
	OrderNo:     "So phieu",
	Table:       "Ban",
	DineIn:      "Tai ban",
	Takeaway:    "Mang ve",
	Spot:        "Dat cho",

	PaymentState:  "Thanh toan",
	PaymentPaid:   "DA TRA",
	PaymentUnpaid: "CHUA TRA",

	Item:  "San pham",
	Price: "Tong",

	KitchenTotal:  "Tong cong",
	NotePrefix:    "Ghi chu: ",
	ToppingWaived: "Topping mien phi",

	TitleKitchen:    "PHIEU BEP",
	TitleTableBill:  "HOA DON BAN",
	TitleNewItems:   "MON VUA THEM",
	TitlePaid:       "DA THANH TOAN",
	TitleRemaining:  "PHAN CON LAI",
	TitleRedInvoice: "PHIEU THANH TOAN",
	ReprintMark:     "BAN IN",
	CustomerLabel:   "Khach hang",
	Phone:           "SDT",
	PickupTime:      "Gio lay",

	Guest:         "Khach",
	Subtotal:      "Tam tinh",
	Discount:      "Giam gia",
	ServiceCharge: "Phi phuc vu",
	Tax:           "Thue",
	Total:         "Tong (da VAT)",
	PaidAmount:    "Da thanh toan",
	PaymentMethod: "Phuong thuc",
	Tendered:      "Tien khach dua",
	Change:        "Tien thoi lai",
	Remaining:     "Con lai",

	SplitTitle:        "HOA DON CHIA",
	SplitModeEqual:    "Chia deu",
	SplitModeByItems:  "Chia theo mon",
	SplitModeByAmount: "Chia theo so tien",
	SplitPeople:       "nguoi",
	OrderTotal:        "Tong don",
	SplitShare:        "Phan chia",
}

var printLabelsEN = printLabels{
	OrderMethod: "Type",
	TicketSeq:   "No.",
	OrderNo:     "Bill No.",
	Table:       "Table",
	DineIn:      "Dine-in",
	Takeaway:    "Takeaway",
	Spot:        "Spot",

	PaymentState:  "Payment",
	PaymentPaid:   "PAID",
	PaymentUnpaid: "UNPAID",

	Item:  "Item",
	Price: "Total",

	KitchenTotal:  "Total",
	NotePrefix:    "Note: ",
	ToppingWaived: "Free toppings",

	TitleKitchen:    "KITCHEN",
	TitleTableBill:  "TABLE BILL",
	TitleNewItems:   "NEW ITEMS",
	TitlePaid:       "PAID",
	TitleRedInvoice: "PAYMENT RECEIPT",
	ReprintMark:     "BAN IN",
	CustomerLabel:   "Customer",
	Phone:           "Phone",
	PickupTime:      "Pickup",
	TitleRemaining:  "REMAINING",

	Guest:         "Guest",
	Subtotal:      "Subtotal",
	Discount:      "Discount",
	ServiceCharge: "Service",
	Tax:           "Tax",
	Total:         "Total (incl. tax)",
	PaidAmount:    "Paid",
	PaymentMethod: "Method",
	Tendered:      "Tendered",
	Change:        "Change",
	Remaining:     "Balance due",

	SplitTitle:        "SPLIT BILL",
	SplitModeEqual:    "Split evenly",
	SplitModeByItems:  "Split by items",
	SplitModeByAmount: "Split by amount",
	SplitPeople:       "people",
	OrderTotal:        "Order total",
	SplitShare:        "Share",
}

var printLabelsJA = printLabels{
	OrderMethod: "提供",
	TicketSeq:   "番号",
	OrderNo:     "伝票番号",
	Table:       "テーブル",
	DineIn:      "店内",
	Takeaway:    "持ち帰り",
	Spot:        "予約",

	PaymentState:  "支払",
	PaymentPaid:   "済み",
	PaymentUnpaid: "未払",

	Item:  "商品",
	Price: "合計",

	KitchenTotal:  "合計",
	NotePrefix:    "備考: ",
	ToppingWaived: "トッピング無料分",

	TitleKitchen:    "厨房伝票",
	TitleTableBill:  "テーブル伝票",
	TitleNewItems:   "追加商品",
	TitlePaid:       "支払済",
	TitleRemaining:  "残額",
	TitleRedInvoice: "領収書",
	ReprintMark:     "再印刷",
	CustomerLabel:   "お客様",
	Phone:           "電話",
	PickupTime:      "受取時間",

	Guest:         "お客",
	Subtotal:      "小計",
	Discount:      "割引",
	ServiceCharge: "サービス料",
	Tax:           "税金",
	Total:         "合計 (税込)",
	PaidAmount:    "支払済",
	PaymentMethod: "支払方法",
	Tendered:      "お預かり",
	Change:        "お釣り",
	Remaining:     "会計残高",

	SplitTitle:        "分割会計",
	SplitModeEqual:    "均等割",
	SplitModeByItems:  "品目割",
	SplitModeByAmount: "金額割",
	SplitPeople:       "名",
	OrderTotal:        "注文合計",
	SplitShare:        "取り分",
}
