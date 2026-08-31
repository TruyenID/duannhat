package handler

import (
	"bytes"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// by_amount now shows the FULL món list (requirement: every split bill lists
// all món; only the amount differs). paidSlipInputs returns all items,
// BillTotal 0 (order gross drives "Tong don"), AmountPaid = this person's
// share ("Phan chia"), OrderGrossTotal = the whole order, SplitMode by_amount,
// and the per-person Label.
//
// We exercise paidSlipInputs directly (it's the pure projection function)
// instead of round-tripping through HTTP — the splitState is constructible,
// and the formatter is asserted in a sibling test.

func TestPaidSlipInputs_ByAmountShowsFullItemsAndShare(t *testing.T) {
	s := &Server{}
	o := &service.Order{
		ID:          "ord-1",
		OrderCode:   "OC-001",
		TotalAmount: 250000,
		Items: []service.Item{
			{ID: "it-1", MenuItemName: "Phở", Quantity: 2, UnitPrice: 50000, Subtotal: 100000},
			{ID: "it-2", MenuItemName: "Cafe", Quantity: 3, UnitPrice: 50000, Subtotal: 150000},
		},
	}
	st := splitState{
		splitCount:     3,
		splitMode:      "by_amount",
		slipIndex:      1,
		expectedTotal:  100000,
		paidCount:      1,
		remaining:      150000,
		byAmountLabel:  "Người 1",
		byAmountAmount: 100000,
	}

	_, slipItems, info := s.paidSlipInputs(o, st, 100000)

	if len(slipItems) != 2 {
		t.Errorf("by_amount slip should show all 2 items, got %d", len(slipItems))
	}
	if info.BillTotal != 0 {
		t.Errorf("BillTotal: want 0 (order gross drives Tong don), got %d", info.BillTotal)
	}
	if info.AmountPaid != 100000 {
		t.Errorf("AmountPaid (share): want 100000, got %d", info.AmountPaid)
	}
	if info.OrderGrossTotal != 250000 {
		t.Errorf("OrderGrossTotal: want 250000, got %d", info.OrderGrossTotal)
	}
	if info.SplitMode != "by_amount" {
		t.Errorf("SplitMode: want 'by_amount', got %q", info.SplitMode)
	}
	if info.Label != "Người 1" {
		t.Errorf("Label: want 'Người 1', got %q", info.Label)
	}
	if info.SplitCount != 3 || info.SlipIndex != 1 {
		t.Errorf("SplitCount/SlipIndex: want 3/1, got %d/%d", info.SplitCount, info.SlipIndex)
	}
	if info.Remaining != 150000 {
		t.Errorf("Remaining: want 150000, got %d", info.Remaining)
	}
	if len(o.Items) != 2 {
		t.Errorf("caller's order mutated: items len = %d", len(o.Items))
	}
}

func TestPaidSlipInputs_ByAmountFallsBackToAmountThisSlip(t *testing.T) {
	s := &Server{}
	o := &service.Order{ID: "ord-2", TotalAmount: 250000}
	st := splitState{
		splitCount:     3,
		splitMode:      "by_amount",
		slipIndex:      2,
		byAmountAmount: 0, // simulate metadata loss — fall back to amountThisSlip
		byAmountLabel:  "Người 2",
		remaining:      100000,
	}
	_, _, info := s.paidSlipInputs(o, st, 80000)
	if info.AmountPaid != 80000 {
		t.Errorf("AmountPaid fallback (share): want 80000, got %d", info.AmountPaid)
	}
	if info.BillTotal != 0 {
		t.Errorf("BillTotal: want 0 (order gross drives Tong don), got %d", info.BillTotal)
	}
	if info.OrderGrossTotal != 250000 {
		t.Errorf("OrderGrossTotal: want 250000, got %d", info.OrderGrossTotal)
	}
}

// The "PHAN CON LAI" + QR slip prints ONLY on the auto-print path (empty
// paymentID). A targeted per-person reprint never prints it — the redundant
// kiosk-QR slip the operator saw after per-món reprints (a ¥1-2 rounding
// residual triggered it).
func TestShouldPrintRemainingQRSlip(t *testing.T) {
	split := splitState{splitMode: "by_items", splitCount: 2, remaining: 2}
	notSplit := splitState{remaining: 100}
	splitPaid := splitState{splitMode: "by_items", splitCount: 2, remaining: 0}

	cases := []struct {
		name      string
		paymentID string
		st        splitState
		want      bool
	}{
		{"auto-print split with remainder → QR", "", split, true},
		{"targeted reprint (paymentID set) → NO QR", "pay-1", split, false},
		{"auto-print but not a split → no QR", "", notSplit, false},
		{"auto-print split fully paid → no QR", "", splitPaid, false},
		{"targeted reprint fully paid → no QR", "pay-1", splitPaid, false},
	}
	for _, c := range cases {
		if got := shouldPrintRemainingQRSlip(c.paymentID, c.st); got != c.want {
			t.Errorf("%s: got %v, want %v", c.name, got, c.want)
		}
	}
}

func TestSplitState_IsSplitCoversByAmount(t *testing.T) {
	if !(splitState{splitMode: "by_amount"}).isSplit() {
		t.Error("by_amount should report isSplit() == true")
	}
	if !(splitState{splitMode: "by_amount"}).isByAmount() {
		t.Error("by_amount should report isByAmount() == true")
	}
	if (splitState{splitMode: "equal"}).isByAmount() {
		t.Error("equal should NOT report isByAmount() == true")
	}
}

// Sibling formatter test — assert FormatPaidTicket emits the by_amount slip
// with the label header and NO item rows.
func TestFormatPaidTicket_ByAmountRendersLabelAndNoItems(t *testing.T) {
	o := &service.Order{
		ID:          "ord-3",
		OrderCode:   "OC-002",
		TableNumber: "T-12",
		TotalAmount: 250000,
		Items:       nil, // by_amount: no items on the slip-order copy
	}
	// Locale pinned — the header assertion below matches the Vietnamese title
	// ("DA THANH TOAN"). The subject of this test is the by_amount slip shape
	// (no items, per-person label), not the language.
	config := service.PrintJobConfig{
		PaperWidth: 42,
		StoreName:  "Quán Test",
		Locale:     "vi",
	}
	slip := service.PaymentSlipInfo{
		PaymentMethod: "cash",
		AmountPaid:    100000,
		SlipIndex:     1,
		SplitCount:    3,
		BillTotal:     100000,
		Remaining:     150000,
		Label:         "Người 1",
	}
	out := service.FormatPaidTicket(o, nil, 0, config, slip)

	// Header should carry the title verbatim.
	if !bytes.Contains(out, []byte("DA THANH TOAN")) {
		t.Error("slip missing 'DA THANH TOAN' header")
	}
	// Label MUST surface as "Khach …". The printer has no Vietnamese glyphs, so
	// the label is de-accented to ASCII ("Người 1" → "Nguoi 1") before encoding.
	if !bytes.Contains(out, []byte("Nguoi 1")) {
		t.Error("slip missing per-person label 'Nguoi 1' (de-accented)")
	}
	// The numeric "1/3" suffix should appear alongside the label.
	if !bytes.Contains(out, []byte("1/3")) {
		t.Error("slip missing split index '1/3'")
	}
	// No item table — "San pham" + "Thanh tien" header still prints as
	// the column row, but there should be no item lines following it.
	if strings.Count(string(out), "Pho") != 0 {
		t.Error("slip should not contain item names — by_amount strips them")
	}
}
