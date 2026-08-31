package service

import (
	"strings"
	"time"
	"unicode/utf8"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// DebtSlipInfo carries the per-print debt context used by FormatDebtSlip.
// All fields are optional; missing values render as "-" so the slip still
// produces a usable record even when the customer profile is incomplete.
type DebtSlipInfo struct {
	CustomerName    string // primary print line under "Khách hàng"
	CustomerPhone   string // optional second line
	CustomerTaxCode string // MST line when set
	DebtAmount      int    // amount owed (this debt payment's amount)
	ReprintNumber   int    // 0/1 = first print; >1 prints "Bản in #N" header
}

// FormatDebtSlip renders a "PHIẾU GHI NỢ" slip — proof-of-debt thermal
// receipt the cashier hands to a customer when an order is settled with
// an on_account payment (plan-038 T10.6).
//
// Layout (42-col paper width, matches FormatPaidTicket conventions):
//
//	Store Name              PHIEU GHI NO
//	(SubName)
//	Bản in #2   <only when reprint>
//	2026/06/20 10:21                   #525
//
//	So HD            01
//	Ban               B-08
//	Khach hang   Cty ABC
//	SDT          0901234567
//	MST          0312345678
//
//	- - - - - - - - - - - - - - - - - - - - -
//	San pham                          Thanh tien
//
//	... items ...
//
//	- - - - - - - - - - - - - - - - - - - - -
//	Tong                              ¥250,000
//	Da thanh toan                          ¥0
//	GHI NO                            ¥250,000
//
//	Chu ky khach hang:
//
//	_________________________
//
//	Khach hang xac nhan da nhan no
//
// The slip does NOT print a QR (no e-payment for debt) and DOES print a
// signature line so the customer can acknowledge the obligation.
func FormatDebtSlip(order *Order, items []Item, config PrintJobConfig, info DebtSlipInfo) []byte {
	w := config.PaperWidth
	if w == 0 {
		w = 48
	}

	e := escpos.New()
	e.SetLeftMargin(config.leftMargin(w))
	e.Align(escpos.AlignLeft)

	storeName := config.StoreName
	if storeName == "" {
		storeName = "Store"
	}
	title := "PHIEU GHI NO"
	titleW := utf8.RuneCountInString(title)
	storeDispW := displayWidth(storeName)
	e.Bold(true)
	if storeDispW+1+titleW <= w {
		gap := w - storeDispW - titleW
		e.Line(storeName + spaces(gap) + title)
	} else {
		e.Line(storeName)
		e.Line(spaces(w-titleW) + title)
	}
	e.Bold(false)
	if strings.TrimSpace(config.StoreSubName) != "" {
		e.Line(config.StoreSubName)
	}

	// Plan-038 Q4 — "Bản in #N" reprint header.
	if info.ReprintNumber >= 2 {
		reprint := "BAN IN #" + itoa(info.ReprintNumber)
		e.Line(spaces(w-utf8.RuneCountInString(reprint)) + reprint)
	}

	suffix := OrderCodeSuffix(order.OrderCode)
	dateStr := time.Now().Format("2006/01/02 15:04")
	codeStr := "#" + suffix
	codeW := utf8.RuneCountInString(codeStr)
	dateW := w - codeW
	if dateW < 1 {
		dateW = 1
	}
	e.Line(padRight(dateStr, dateW) + codeStr)

	e.Feed(1)

	tableStr := order.TableNumber
	if tableStr == "" {
		tableStr = "-"
	}
	footerRow := func(label, value string) {
		gap := w - utf8.RuneCountInString(label) - utf8.RuneCountInString(value)
		if gap < 1 {
			gap = 1
		}
		e.Line(label + spaces(gap) + value)
	}
	footerRow("So HD", suffix)
	footerRow("Ban", tableStr)

	customerName := strings.TrimSpace(info.CustomerName)
	if customerName == "" {
		customerName = "-"
	}
	footerRow("Khach hang", customerName)
	if phone := strings.TrimSpace(info.CustomerPhone); phone != "" {
		footerRow("SDT", phone)
	}
	if tax := strings.TrimSpace(info.CustomerTaxCode); tax != "" {
		footerRow("MST", tax)
	}

	e.Feed(1)
	e.Line(dashedLine(w))
	e.Feed(1)
	colRight := "Thanh tien"
	colLeft := "San pham"
	e.Line(padRight(colLeft, w-utf8.RuneCountInString(colRight)) + colRight)
	e.Feed(1)

	for _, item := range items {
		// Debt slip (PHIEU GHI NO) is a receivable record, not an インボイス
		// receipt — no per-rate ※ marker (reduced=false).
		printRunnerItem(e, w, item, config.cur(), false, config.Locale, printLabelsFor(config.Locale).NotePrefix)
	}

	e.Feed(1)
	e.Line(dashedLine(w))
	e.Feed(1)

	total := orderGrossTotal(order, items)
	debt := info.DebtAmount
	if debt <= 0 {
		debt = total
	}
	paid := total - debt
	if paid < 0 {
		paid = 0
	}

	e.Bold(true)
	footerRow("Tong", config.cur()+formatPrice(total))
	e.Bold(false)
	footerRow("Da thanh toan", config.cur()+formatPrice(paid))
	e.Bold(true)
	footerRow("GHI NO", config.cur()+formatPrice(debt))
	e.Bold(false)

	e.Feed(2)
	e.Line("Chu ky khach hang:")
	e.Feed(2)
	// 25-char underline for the signature row.
	e.Line(strings.Repeat("_", 25))
	e.Feed(1)
	e.Line("Khach hang xac nhan da nhan no")
	e.Feed(3)
	e.FullCut()
	return e.Bytes()
}

// itoa is a tiny stand-in to avoid importing strconv just for a single
// reprint-header conversion. Caller never passes negative values.
func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	digits := []byte{}
	for n > 0 {
		digits = append([]byte{byte('0' + n%10)}, digits...)
		n /= 10
	}
	return string(digits)
}
