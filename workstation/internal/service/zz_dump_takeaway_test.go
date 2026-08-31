package service

import (
	"os"
	"testing"
)

// Throwaway dump helper — renders the takeaway slips so a human can look at the
// paper. Not a gate; delete after use.
func TestZZDumpTakeaway(t *testing.T) {
	dir := os.Getenv("DUMP_TAKEAWAY_DIR")
	if dir == "" {
		t.Skip("set DUMP_TAKEAWAY_DIR")
	}

	base := func() *Order {
		return &Order{
			ID:                    "order-uuid-tk",
			OrderCode:             "WS-019e-20260817-004",
			OrderType:             "takeaway",
			TotalAmount:           3520,
			TaxAmount:             295,
			Note:                  "Khong hanh",
			CustomerTakeawayName:  "Nguyen Van A",
			CustomerTakeawayPhone: "090-1234-5678",
			ScheduledPickupTime:   "2026-08-17T12:30:00+09:00",
			Items: []Item{
				{MenuItemName: "Bun ga la chanh", Quantity: 1, UnitPrice: 1780, Status: ItemStatusPending,
					Toppings: []ItemTopping{{Name: "Them thit", ModifierType: "add", Quantity: 1, UnitPrice: 300}}},
				{MenuItemName: "フォーボー", Quantity: 2, UnitPrice: 620, Status: ItemStatusPending},
				{MenuItemName: "Tra da", Quantity: 1, UnitPrice: 200, Status: ItemStatusPending, Note: "it da"},
			},
			TaxLines: []OrderTaxLine{{Rate: 10, Taxable: 3225, Tax: 295}},
		}
	}

	cfg := func(locale string, cols int) PrintJobConfig {
		return PrintJobConfig{
			StoreOrganization:        "株式会社ベトナムオリジン",
			StoreSubName:             "VIET ORIGIN",
			StoreName:                "ベト屋 本郷店",
			StoreAddress:             "東京都文京区本郷3-1-1",
			StorePhone:               "03-1234-5678",
			PaperWidth:               cols,
			PhysicalWidth:            cols,
			TaxRate:                  10,
			Currency:                 "¥",
			CurrencyCode:             "JPY",
			Locale:                   locale,
			SellerRegistrationNumber: "T1234567890123",
		}
	}

	type job struct {
		name   string
		locale string
		cols   int
		paid   int
	}
	jobs := []job{
		{"kitchen-ja-unpaid", "ja", 48, 0},
		{"kitchen-ja-paid", "ja", 48, 3520},
		{"kitchen-vi-unpaid", "vi", 48, 0},
		{"kitchen-ja-58mm-unpaid", "ja", 32, 0},
		{"hall-ja-unpaid", "ja", 48, 0},
		{"hall-ja-paid", "ja", 48, 3520},
		{"hall-vi-unpaid", "vi", 48, 0},
		{"dinein-kitchen-ja", "ja", 48, 0},
	}

	for _, j := range jobs {
		o := base()
		o.PaidAmount = j.paid
		c := cfg(j.locale, j.cols)

		var b []byte
		switch {
		case j.name == "dinein-kitchen-ja":
			o.OrderType = "dine_in"
			o.TableNumber = "C-07"
			b = FormatKitchenTicket(o, o.Items, 319, c)
		case len(j.name) >= 7 && j.name[:7] == "kitchen":
			b = FormatKitchenTicket(o, o.Items, 319, c)
		default:
			b = FormatDeltaQRTicket(o, o.Items, c)
		}
		if err := os.WriteFile(dir+"/"+j.name+".bin", b, 0o644); err != nil {
			t.Fatal(err)
		}
	}
}
