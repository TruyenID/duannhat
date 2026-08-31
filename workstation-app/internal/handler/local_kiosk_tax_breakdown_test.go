package handler

import (
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// Round-4 audit B-I.2 — the kiosk order shape now carries a per-rate
// tax_breakdown so the bill can show 8%対象 / 10%対象 separately (parity with
// Cloud's KioskOrderResource). kioskTaxBreakdown reads only the order's items,
// so it can be exercised without a DB.

func rp(v float64) *float64 { return &v }

func TestKioskTaxBreakdown_GroupsByRateExcluded(t *testing.T) {
	s := &Server{}
	order := &service.Order{
		IsTaxIncluded: false,
		Items: []service.Item{
			{Subtotal: 1000, TaxAmount: 80, TaxRate: rp(8), Status: "served"},
			{Subtotal: 500, TaxAmount: 50, TaxRate: rp(10), Status: "served"},
			{Subtotal: 999, TaxAmount: 80, TaxRate: rp(8), Status: "served"},  // merges into 8%
			{Subtotal: 300, TaxAmount: 30, TaxRate: rp(10), Status: "voided"}, // excluded
			{Subtotal: 200, TaxAmount: 0, TaxRate: nil, Status: "served"},     // unstamped → skipped
		},
	}

	rows := s.kioskTaxBreakdown(order)

	if len(rows) != 2 {
		t.Fatalf("rows = %d, want 2 (8%% + 10%%): %+v", len(rows), rows)
	}
	// Ascending by rate: 8% first.
	if rows[0]["rate"].(float64) != 8 || rows[0]["taxable"].(float64) != 1999 || rows[0]["tax"].(float64) != 160 {
		t.Errorf("8%% row = %+v, want rate 8 / taxable 1999 / tax 160", rows[0])
	}
	if rows[1]["rate"].(float64) != 10 || rows[1]["taxable"].(float64) != 500 || rows[1]["tax"].(float64) != 50 {
		t.Errorf("10%% row = %+v, want rate 10 / taxable 500 / tax 50", rows[1])
	}
}

func TestKioskTaxBreakdown_IncludedModeNetsOutTheTax(t *testing.T) {
	s := &Server{}
	order := &service.Order{
		IsTaxIncluded: true,
		Items: []service.Item{
			// gross ¥1080 @8% included → net taxable 1000, tax 80.
			{Subtotal: 1080, TaxAmount: 80, TaxRate: rp(8), Status: "served"},
		},
	}

	rows := s.kioskTaxBreakdown(order)
	if len(rows) != 1 {
		t.Fatalf("rows = %d, want 1", len(rows))
	}
	if rows[0]["taxable"].(float64) != 1000 || rows[0]["tax"].(float64) != 80 {
		t.Errorf("included row = %+v, want taxable 1000 / tax 80 (net of 内税)", rows[0])
	}
}

func TestKioskTaxBreakdown_EmptyWhenNoStampedLines(t *testing.T) {
	s := &Server{}
	order := &service.Order{Items: []service.Item{
		{Subtotal: 1000, TaxAmount: 0, TaxRate: nil, Status: "served"},
	}}
	if rows := s.kioskTaxBreakdown(order); len(rows) != 0 {
		t.Errorf("rows = %+v, want empty (kiosk falls back to single tax line)", rows)
	}
}
