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

	// Item table (both kitchen + bill)
	Item  string // "San pham"
	Price string // "Thanh tien"

	KitchenTotal string // "Tong cong" — kitchen batch total
	NotePrefix   string // "Ghi chu: " — free-note prefix (both)

	// Bill titles (header right side)
	TitleTableBill  string // "HOA DON BAN"
	TitleNewItems   string // "MON VUA THEM"
	TitlePaid       string // "DA THANH TOAN"
	TitleRemaining  string // "PHAN CON LAI"
	TitleRedInvoice string // "HOA DON DO" — named-customer red invoice

	// Bill footer rows
	CustomerLabel string // "Khach hang" — customer name line (takeaway + red-invoice)
	Phone         string // "SDT" — customer phone line (takeaway)
	PickupTime    string // "Gio lay" — takeaway scheduled pickup time
	Guest         string // "Khach" (split label)
	Subtotal      string // "Tam tinh"
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
	OrderNo:     "So HD",
	Table:       "Ban",
	DineIn:      "Tai ban",
	Takeaway:    "Mang ve",
	Spot:        "Dat cho",

	Item:  "San pham",
	Price: "Thanh tien",

	KitchenTotal: "Tong cong",
	NotePrefix:   "Ghi chu: ",

	TitleTableBill:  "HOA DON BAN",
	TitleNewItems:   "MON VUA THEM",
	TitlePaid:       "DA THANH TOAN",
	TitleRemaining:  "PHAN CON LAI",
	TitleRedInvoice: "HOA DON DO",
	CustomerLabel:   "Khach hang",
	Phone:           "SDT",
	PickupTime:      "Gio lay",

	Guest:         "Khach",
	Subtotal:      "Tam tinh",
	ServiceCharge: "Phi phuc vu",
	Tax:           "Thue",
	Total:         "Tong",
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
	OrderNo:     "Order#",
	Table:       "Table",
	DineIn:      "Dine-in",
	Takeaway:    "Takeaway",
	Spot:        "Spot",

	Item:  "Item",
	Price: "Price",

	KitchenTotal: "Total",
	NotePrefix:   "Note: ",

	TitleTableBill:  "TABLE BILL",
	TitleNewItems:   "NEW ITEMS",
	TitlePaid:       "PAID",
	TitleRedInvoice: "RED INVOICE",
	CustomerLabel:   "Customer",
	Phone:           "Phone",
	PickupTime:      "Pickup",
	TitleRemaining:  "REMAINING",

	Guest:         "Guest",
	Subtotal:      "Subtotal",
	ServiceCharge: "Service",
	Tax:           "Tax",
	Total:         "Total",
	PaidAmount:    "Paid",
	PaymentMethod: "Method",
	Tendered:      "Tendered",
	Change:        "Change",
	Remaining:     "Remaining",

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
	OrderNo:     "伝票",
	Table:       "卓",
	DineIn:      "店内",
	Takeaway:    "持ち帰り",
	Spot:        "予約",

	Item:  "商品",
	Price: "金額",

	KitchenTotal: "合計",
	NotePrefix:   "備考: ",

	TitleTableBill:  "会計伝票",
	TitleNewItems:   "追加商品",
	TitlePaid:       "支払済",
	TitleRemaining:  "残額",
	TitleRedInvoice: "領収書",
	CustomerLabel:   "お客様",
	Phone:           "電話",
	PickupTime:      "受取時間",

	Guest:         "お客",
	Subtotal:      "小計",
	ServiceCharge: "サービス料",
	Tax:           "税",
	Total:         "合計",
	PaidAmount:    "支払済",
	PaymentMethod: "支払方法",
	Tendered:      "お預かり",
	Change:        "お釣り",
	Remaining:     "残額",

	SplitTitle:        "分割会計",
	SplitModeEqual:    "均等割",
	SplitModeByItems:  "品目割",
	SplitModeByAmount: "金額割",
	SplitPeople:       "名",
	OrderTotal:        "注文合計",
	SplitShare:        "取り分",
}
