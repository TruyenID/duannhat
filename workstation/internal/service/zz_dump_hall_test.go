package service

import (
	"os"
	"testing"
)

// Throwaway: renders the two candidate "Print Hall" slips for ONE takeaway
// order so a human can compare paper. Delete after use.
func TestZZDumpHallCandidates(t *testing.T) {
	dir := os.Getenv("DUMP_HALL_DIR")
	if dir == "" {
		t.Skip("set DUMP_HALL_DIR")
	}

	o := &Order{
		ID:                    "order-uuid-tk",
		OrderCode:             "ORD-2026-3099",
		OrderType:             "takeaway",
		Subtotal:              1991,
		ServiceCharge:         0,
		TaxAmount:             199,
		TotalAmount:           2190,
		PaidAmount:            2190,
		CustomerTakeawayName:  "Nguyen Van A",
		CustomerTakeawayPhone: "090-1234-5678",
		Items: []Item{
			{MenuItemName: "Classic Beef Pho", Quantity: 1, UnitPrice: 1120, Status: ItemStatusServed},
			{MenuItemName: "Shank Beef Pho", Quantity: 1, UnitPrice: 1020, Status: ItemStatusServed},
		},
		TaxLines: []OrderTaxLine{{Rate: 10, Taxable: 1991, Tax: 199}},
	}

	cfg := PrintJobConfig{
		StoreName:                "ベト屋 本郷店",
		StoreSubName:             "VIET ORIGIN",
		PaperWidth:               48,
		PhysicalWidth:            48,
		TaxRate:                  10,
		Currency:                 "¥",
		CurrencyCode:             "JPY",
		Locale:                   "ja",
		SellerRegistrationNumber: "T1234567890123",
	}

	unpaid := *o
	unpaid.PaidAmount = 0
	files := map[string][]byte{
		"hall-paid":     FormatRunnerTicket(o, o.Items, 0, cfg),
		"hall-unpaid":   FormatRunnerTicket(&unpaid, unpaid.Items, 0, cfg),
		"receipt-final": FormatPaidTicket(o, o.Items, 0, cfg, PaymentSlipInfo{PaymentMethod: "stripe", AmountPaid: 2190}),
		"kitchen-final": FormatKitchenTicket(&unpaid, unpaid.Items, 319, cfg),
	}
	for name, b := range files {
		if err := os.WriteFile(dir+"/"+name+".bin", b, 0o644); err != nil {
			t.Fatal(err)
		}
	}
}
