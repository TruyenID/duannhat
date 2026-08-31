package service

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
	"time"
)

// plan-053 M3 (#1171) — TESTS §5 G1 + G6, EDGE-CASES TR-40.
//
// THE MIGRATION GATE. For every kind that has a hard-coded formatter today, the
// SYSTEM DEFAULT definition must render BYTE-IDENTICAL output. Not "similar",
// not "same information" — the same bytes, because the day the registry ships
// every shop keeps printing exactly what it printed the day before.
//
// If a kind fails here, the fix is the DEFINITION, never the assertion. A
// loosened comparison would convert a silent change to thousands of receipts
// into a green build.
//
// Two assertions run over the same matrix:
//
//	G6  renderer(system default) == Format*<kind>()      ← the migration gate
//	G1  renderer(system default) == recorded golden hash ← locks the bytes so a
//	                                                       future edit to BOTH
//	                                                       paths still trips
var updateGolden = flag.Bool("update-print-golden", false, "rewrite testdata/print_golden.json")

const printGoldenPath = "testdata/print_golden.json"

// goldenClock is the frozen instant every golden render is stamped with. The
// legacy formatters read the wall clock, so without freezing it the gate would
// fail whenever the two renders straddled a minute boundary.
var goldenClock = time.Date(2026, 7, 20, 14, 32, 9, 0, time.UTC)

// goldenShopLocation is the zone the golden shop's slips are printed in (#2572).
//
// It is NOT UTC, and that is the whole point. Every timestamp in this fixture
// family is built with `time.UTC`, so while the print layer converted nothing a
// UTC fixture asserted "UTC == the right display time" — a gate that cannot
// distinguish the correct answer from the defect it exists to catch. Measured
// before the fix: `shopLocation|businessDayRangeUTC` matched 0 times in
// `internal/service/*_test.go`, and the bill family had been printing UTC
// 取引年月日 since it was written.
//
// +07 (Vietnam, one of the two countries this product ships to) puts
// `goldenSaleClock` on a DIFFERENT CALENDAR DAY from its UTC instant, so the
// recorded bytes now carry the exact fact the issue is about.
//
// A FixedZone rather than `time.LoadLocation("Asia/Ho_Chi_Minh")` because these
// bytes are hashed: a fixture must not depend on the runner having a tz
// database, and VN has no DST for a real zone to add. The zone NAME never
// reaches the paper — the layout is "2006/01/02 15:04".
//
// Every config that feeds a recorded hash must pin this explicitly. Leaving it
// nil would fall back to `time.Local` and make the fixture depend on the
// machine that generated it — see TestPrintGolden_DoesNotDependOnMachineZone.
var goldenShopLocation = time.FixedZone("ICT", 7*60*60)

type goldenCase struct {
	Kind   string
	Locale string
	Paper  int
	// Variant selects a non-default ORDER for the same kind; empty for every
	// cell that existed before one was needed.
	//
	// It exists because a kind's bytes can branch on the ORDER rather than on
	// the template, and a matrix keyed by kind alone records only one side of
	// that branch. Measured cost of not having it: `PrintLabels.php` carried
	// paymentState/paymentPaid/paymentUnpaid in all three locales while NO PHP
	// emitter referenced them — Cloud printed takeaway slips with no payment row
	// at all — and both parity gates stayed green. `PrintLabelsParityTest`
	// compares label STRINGS (it cannot see that nobody calls them) and every
	// cell here rendered the dine-in fixture, so the row never appeared in a
	// gated byte.
	//
	// The variant rides in the key as `kind~variant` so the kind segment stays a
	// real kind: PHP resolves a template from it, and a compound name there
	// would resolve to nothing and skip the cell in silence — the exact failure
	// shape this file's header warns about.
	Variant string
}

// goldenMatrix is the selective cross-product TESTS §5 asks for: every kind at
// every locale on both real paper widths, plus the 42-column content width the
// LAN print handlers actually send today.
func goldenMatrix() []goldenCase {
	kinds := []string{
		"receipt", "kitchen", "runner", "delta_qr", "remaining",
		"vat_invoice", "red_invoice", "void_notice", "debt_slip",
		// #1493 — chứng từ Nhật được ghim byte như mọi kind khác. Trước đó nó chỉ
		// tồn tại như một NHÁNH của vat_invoice ở locale `ja`, nên không có ô
		// golden nào mang tên nó và không gì chặn được một thay đổi âm thầm.
		"qualified_simplified_invoice",
		"shift_open", "shift_report", "chain_report", "table_paid",
	}
	var out []goldenCase
	for _, k := range kinds {
		for _, loc := range []string{"ja", "en", "vi"} {
			for _, paper := range []int{32, 42, 48} {
				out = append(out, goldenCase{Kind: k, Locale: loc, Paper: paper})
			}
		}
	}
	// The three sheets that carry the takeaway payment row. UNPAID on purpose:
	// the shared fixture is a SETTLED sale, so an unpaid cell differs from it in
	// the very byte the branch decides — an emitter that ignored the money, or
	// inverted the comparison, moves this hash. A settled variant would add nine
	// more cells to re-state what the labels fixture already pins.
	for _, k := range []string{"kitchen", "delta_qr", "runner"} {
		for _, loc := range []string{"ja", "en", "vi"} {
			for _, paper := range []int{32, 42, 48} {
				out = append(out, goldenCase{Kind: k, Locale: loc, Paper: paper, Variant: "takeaway"})
			}
		}
	}
	return out
}

// goldenOrderFor applies a case's variant to the shared fixture order.
//
// It mutates a COPY: `goldenOrder()` is the engine-derived fixture every money
// assertion in this package is written against (TestGoldenOrderIsEngineDerived),
// and a variant reaching back into it would move numbers no variant is entitled
// to touch.
func goldenOrderFor(variant string) (*Order, []Item) {
	order, items := goldenOrder()
	if variant != "takeaway" {
		return order, items
	}
	clone := *order
	clone.OrderType = "takeaway"
	// PaidAmount 0 against a 3685 total — a pickup order whose money has not
	// been collected. The table number is left ALONE: takeaway is supposed to
	// ignore it, and clearing it could not tell "ignored" from "absent".
	clone.PaidAmount = 0
	return &clone, items
}

func TestPrintRenderer_MigrationGate_ByteIdenticalWithLegacyFormatter(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	for _, tc := range goldenMatrix() {
		t.Run(fmt.Sprintf("%s/%s/%dcol", goldenKindLabel(tc), tc.Locale, tc.Paper), func(t *testing.T) {
			cfg := goldenConfigFor(tc.Kind, tc.Locale, tc.Paper)
			legacy := renderLegacyFor(t, tc, cfg)
			data := goldenRenderDataFor(tc, cfg)

			def, err := SystemPrintTemplate(tc.Kind)
			if err != nil {
				t.Fatalf("system default for %q: %v", tc.Kind, err)
			}
			got, err := RenderPrintTemplate(def, data, PrintRenderProfile{Columns: tc.Paper}, tc.Locale)
			if err != nil {
				t.Fatalf("render %q: %v", tc.Kind, err)
			}
			if !bytes.Equal(legacy, got.Bytes()) {
				t.Fatalf("TR-40 migration gate FAILED for %s (%s, %d cols)\n%s",
					tc.Kind, tc.Locale, tc.Paper, diffBytes(legacy, got.Bytes()))
			}
		})
	}
}

// #1169 — the amount-split footer (Tổng món / Khách thanh toán (phần chia)) is
// the one VAT-invoice branch the golden matrix does NOT cover — its fixture is a
// whole-order invoice. That footer is hand-mirrored between FormatVatInvoice
// (legacy) and emitVat{Subtotal,TaxBreakdown,GrandTotal} (template), so assert
// the two still agree byte-for-byte across locale × width.
func TestFormatVatInvoice_AmountSplit_LegacyTemplateParity(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	def, err := SystemPrintTemplate("vat_invoice")
	if err != nil {
		t.Fatalf("system template: %v", err)
	}
	for _, locale := range []string{"ja", "en", "vi"} {
		for _, cols := range []int{32, 42, 48} {
			// #1493 — đây là hoá đơn GTGT, tức quán VIỆT. Không có quốc gia thì
			// renderer legacy rơi về nhánh locale và ở `ja` nó dựng layout Nhật,
			// trong khi template dựng layout Việt theo kind — hai bên so nhau
			// trên hai tờ giấy khác nhau.
			cfg := goldenConfigFor("vat_invoice", locale, cols)
			info := VatInvoiceInfo{
				InvoiceNo:      "HN1-202607-00099",
				IssuedAt:       goldenClock,
				CompanyName:    "Cty TNHH ABC",
				TaxCode:        "0312345678",
				CurrencyPrefix: "d",
				Subtotal:       40000,
				Total:          40000,
				IsAmountSplit:  true,
				Locale:         locale,
				Items: []VatInvoiceLine{
					{Name: "Pho bo dac biet", Quantity: 1, UnitPrice: 50000, LineTotal: 50000, VariantName: "To lon", Note: "It hanh"},
					{Name: "Ca phe sua da", Quantity: 1, UnitPrice: 30000, LineTotal: 30000},
				},
			}
			legacy := FormatVatInvoice(info, cfg)
			got, err := RenderPrintTemplate(def, NewVatInvoiceRenderData(info, cfg), PrintRenderProfile{Columns: cols}, locale)
			if err != nil {
				t.Fatalf("render (%s, %dcol): %v", locale, cols, err)
			}
			if !bytes.Equal(legacy, got.Bytes()) {
				t.Fatalf("amount-split legacy≠template (%s, %dcol)\n%s", locale, cols, diffBytes(legacy, got.Bytes()))
			}
		}
	}
}

func TestPrintRenderer_Golden_G1_ByteStreamLocked(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	recorded := map[string]string{}
	if raw, err := os.ReadFile(printGoldenPath); err == nil {
		if err := json.Unmarshal(raw, &recorded); err != nil {
			t.Fatalf("read %s: %v", printGoldenPath, err)
		}
	} else if !*updateGolden {
		t.Fatalf("missing %s — rerun with -update-print-golden", printGoldenPath)
	}

	produced := map[string]string{}
	for _, tc := range goldenMatrix() {
		key := goldenKey(tc)
		// #1493 — quốc gia theo kind. Không có nó thì `NewVatInvoiceRenderData`
		// suy kind từ locale (đường lui "chưa biết quốc gia") và ô
		// `vat_invoice|ja` được GHI bằng plan Nhật — golden sai ngay từ lúc sinh.
		cfg := goldenConfigFor(tc.Kind, tc.Locale, tc.Paper)
		def, err := SystemPrintTemplate(tc.Kind)
		if err != nil {
			t.Fatalf("system default for %q: %v", tc.Kind, err)
		}
		res, err := RenderPrintTemplate(def, goldenRenderDataFor(tc, cfg), PrintRenderProfile{Columns: tc.Paper}, tc.Locale)
		if err != nil {
			t.Fatalf("render %q: %v", tc.Kind, err)
		}
		sum := sha256.Sum256(res.Bytes())
		produced[key] = hex.EncodeToString(sum[:])
	}

	if *updateGolden {
		if err := os.MkdirAll(filepath.Dir(printGoldenPath), 0o755); err != nil {
			t.Fatal(err)
		}
		body, _ := json.MarshalIndent(produced, "", "  ")
		if err := os.WriteFile(printGoldenPath, append(body, '\n'), 0o644); err != nil {
			t.Fatal(err)
		}
		t.Logf("rewrote %s with %d entries", printGoldenPath, len(produced))
		return
	}

	keys := make([]string, 0, len(produced))
	for k := range produced {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		want, ok := recorded[k]
		if !ok {
			t.Errorf("golden %s missing — rerun with -update-print-golden if this fixture is new", k)
			continue
		}
		if want != produced[k] {
			t.Errorf("golden %s changed: recorded %s, produced %s", k, want, produced[k])
		}
	}
}

// TestPrintRenderer_EveryKindHasASystemDefault guards the other direction: a
// kind the binary can render but ships no default for would print nothing on a
// machine that has never been online (TR-05).
func TestPrintRenderer_EveryKindHasASystemDefault(t *testing.T) {
	for kind := range printKindPlans {
		if _, err := SystemPrintTemplate(kind); err != nil {
			t.Errorf("kind %q has a renderer but no embedded system default: %v", kind, err)
		}
	}
	for _, kind := range SystemPrintTemplateKinds() {
		if _, ok := printKindPlans[kind]; !ok {
			t.Errorf("kind %q ships a system default but has no renderer", kind)
		}
	}
}

// ─── fixtures ─────────────────────────────────────────────────────────────

func freezePrintClock(t *testing.T) func() {
	t.Helper()
	prev := printNow
	printNow = func() time.Time { return goldenClock }
	return func() { printNow = prev }
}

// goldenConfigFor là goldenConfig kèm QUỐC GIA suy ra từ kind (#1493).
//
// Từ #1493 chứng từ được chọn theo quốc gia của shop, nên một fixture không có
// quốc gia mô tả một cửa hàng không tồn tại. Cloud đã ràng kind theo quốc gia
// (`PrintTemplateKind::countries()`), nên ánh xạ này chỉ phát biểu lại điều đó:
// `qualified_simplified_invoice` chỉ tới được quán JP, mọi kind khác thì không.
//
// Có nó thì hai bên của cổng TR-40 nói cùng một thứ: renderer LEGACY rẽ theo
// `cfg.OperatingCountry`, renderer TEMPLATE rẽ theo kind — và với fixture này
// hai tín hiệu đó luôn chỉ về cùng một chứng từ.
func goldenConfigFor(kind, locale string, paper int) PrintJobConfig {
	cfg := goldenConfig(locale, paper)
	if kind == "qualified_simplified_invoice" {
		cfg.OperatingCountry = "JP"
	} else {
		cfg.OperatingCountry = "VN"
	}
	return cfg
}

func goldenConfig(locale string, paper int) PrintJobConfig {
	return PrintJobConfig{
		StoreName:                "ベト屋",
		StoreSubName:             "VIET ORIGIN",
		StoreAddress:             "Tokyo, Shinjuku 1-2-3",
		PaperWidth:               paper,
		PhysicalWidth:            paper,
		TaxRate:                  10,
		Currency:                 "¥",
		CurrencyCode:             "JPY",
		Locale:                   locale,
		SellerRegistrationNumber: "T1234567890123",
	}.WithSlipLocation(goldenShopLocation)
}

// goldenOrder is the TR-33 "standard sample": several lines, TWO tax rates (so
// the ※ marker and the per-rate blocks both fire), a topping, a removal, a free
// note, a service charge and a discount, plus a name long enough to wrap on
// 32-column paper.
// goldenSaleClock is when the golden order was SOLD — deliberately a different
// day from goldenClock (the moment the fixture is "printed"), and deliberately
// late at night (#2065).
//
// Both properties are load-bearing. Different day: if the sale and the print
// fell on the same instant, a renderer that regressed to the print clock would
// produce identical bytes and every golden would stay green — the gate would
// assert nothing about the field it exists to protect (the #2059 lesson). Late
// at night: this is the night-shift case where "sale day" and "print day" are
// genuinely different business dates, which is the whole reason the field can
// be wrong in a way that matters.
//
// #2572 moved it 5h20m earlier and added a THIRD property, without weakening
// either of the first two. Read in `goldenShopLocation` (+07):
//
//	sale   2026-07-18 18:30 UTC → 2026/07/19 01:30 shop   ← UTC day ≠ shop day
//	print  2026-07-20 14:32 UTC → 2026/07/20 21:32 shop   ← ≠ sale's shop day
//
// The new property is the first line: the sale's UTC calendar day and its shop
// calendar day now DISAGREE, so the recorded bytes cannot be produced by a
// renderer that prints the raw UTC timestamp. Under the old value (23:50 UTC)
// the +07 conversion landed at 06:50 the NEXT morning — same shop day as the
// print, and no longer a night shift — which would have quietly retired both
// original properties in the act of fixing the zone.
var goldenSaleClock = time.Date(2026, 7, 18, 18, 30, 0, 0, time.UTC)

func goldenOrder() (*Order, []Item) {
	rate10 := 10.0
	rate8 := 8.0
	openedAt := goldenSaleClock.Add(-95 * time.Minute)
	checkoutAt := goldenSaleClock.Add(-3 * time.Minute)
	order := &Order{
		// #2065 — the fixture is a CLOSED sale, because that is what a receipt or
		// a red invoice is printed from. Without these the order carried no
		// instant at all and the resolver fell through to the print clock, which
		// is precisely the behaviour under test.
		OpenedAt:    openedAt,
		CheckoutAt:  &checkoutAt,
		ClosedAt:    &goldenSaleClock,
		ID:          "0199aa11-2233-4455-6677-8899aabbccdd",
		OrderCode:   "WS-019e-20260720-004",
		OrderType:   "dine_in",
		TableNumber: "A-1",
		Note:        "Khong hanh, it cay",
		// #2169 — mọi con số tiền ở đây phải là số ENGINE SINH RA cho đúng bộ
		// (items, discount, cấu hình phí dịch vụ, ảnh chụp làm tròn), không phải
		// số gõ tay. `TestGoldenOrderIsEngineDerived` ngay dưới ghim điều đó.
		//
		// Cấu hình mà fixture này biểu diễn: phí dịch vụ **10%**, thuế phí dịch
		// vụ **10%**, 総額表示, làm tròn `round` bước 1.
		//
		//	priceGroups({8:300, 10:3150}, discount=100, sc=10%, scTax=10%, incl=true, 1, 1, "round")
		//	→ ServiceCharge 335 · TaxAmount 330 · TotalAmount 3685
		//
		// Bản cũ ghi `ServiceCharge: 330` / `TotalAmount: 3530` — phí tính trên
		// **3300 GỘP** thay vì trên `3300 − 100` như engine làm, nên đơn tự mâu
		// thuẫn: không cấu hình nào sinh ra được bộ số đó.
		//
		// **2026-08-13 — cả bộ số dời một lần nữa, và lần này vì FIXTURE ĐANG
		// GHIM MỘT LỖI.** Nhóm 10% ở dòng trên từng là **3000**, tức
		// `2×980 + 150 + 890`: phần topping cộng MỘT lần cho cả dòng thay vì
		// một lần mỗi suất. `itemTaxableSubtotal` tính đúng như vậy, fixture
		// được dựng cho khớp, rồi mọi hash vàng được ghi lại từ nó — nên lỗi
		// tự chứng minh mình đúng ở cả ba chỗ. Cơ sở đúng là **3150**
		// (`2×(980+150) + 890`), và `Subtotal` đi từ 3300 lên **3450** cùng lý
		// do. Số dưới đây in ra từ chính `priceGroups`, không gõ tay.
		Subtotal:              3450,
		ServiceCharge:         335,
		DiscountAmount:        100,
		TaxAmount:             330,
		TotalAmount:           3685,
		PaidAmount:            3685,
		IsTaxIncluded:         true,
		CustomerTakeawayName:  "",
		CustomerTakeawayPhone: "",
		// #2071 — the ledger's per-rate discount rows for this basket, exactly
		// as writeOrderConditionsTx allocates ¥100 over the gross rate groups
		// {8%: 300, 10%: 3150} on subtotal 3450 (pro-rata half-up to step 1,
		// remainder on the last group): 8% → −9, 10% → −91. Ordered rate-ASC,
		// the order loadOrderDiscountLines returns. Engine-derived like every
		// other figure here — TestGoldenOrderIsEngineDerived pins the split.
		Discounts: []OrderDiscountLine{
			{Rate: &rate8, Amount: -9},
			{Rate: &rate10, Amount: -91},
		},
		// #2170 — the ledger's per-rate TAX rows for this basket, exactly as
		// writeOrderConditionsTx records res.Groups for
		// priceGroups({8:300, 10:3150}, discount=100, sc=10%, scTax=10%,
		// incl=true, 1, 1, "round"): post-discount, service-charge tax merged
		// into the 10% group (gap #7), rounded half-away-from-zero to minor
		// units like Cloud's `(int) round`. Ordered rate-ASC, as OrderTaxLines
		// returns. Σ Tax = 330 = TaxAmount; Σ(Taxable+Tax) = 3685 =
		// TotalAmount. Engine-derived like every other figure here —
		// TestGoldenOrderIsEngineDerived pins the rows.
		TaxLines: []OrderTaxLine{
			{Rate: 8, Taxable: 269, Tax: 22},
			{Rate: 10, Taxable: 3086, Tax: 308},
		},
	}
	items := []Item{
		{
			ID:             "item-1",
			MenuItemName:   "Bun bo Hue dac biet thap cam",
			SkuVariantName: "Large",
			Quantity:       2,
			UnitPrice:      980,
			TaxRate:        &rate10,
			Toppings: []ItemTopping{
				{Name: "Them rau mui", Quantity: 1, UnitPrice: 150, ModifierType: "add"},
				{Name: "Khong hanh", Quantity: 1, UnitPrice: 0, ModifierType: "remove"},
			},
			ToppingSubtotal: 150,
			Note:            "It cay",
		},
		{
			ID:           "item-2",
			MenuItemName: "緑茶",
			Quantity:     1,
			UnitPrice:    300,
			TaxRate:      &rate8,
		},
		{
			ID:           "item-3",
			MenuItemName: "Cafe sua da",
			Quantity:     1,
			UnitPrice:    890,
			TaxRate:      &rate10,
		},
	}
	return order, items
}

func goldenSlip() PaymentSlipInfo {
	return PaymentSlipInfo{
		PaymentMethod: "cash",
		// #2169 — đi theo `goldenOrder().TotalAmount` (3530 → 3520 → **3685**
		// khi cơ sở chịu thuế thôi bỏ rơi phần topping, 2026-08-13). Khách vẫn
		// đưa ¥4.000, nên tiền thối đi ngược đúng chừng ấy: 480 → 315. Để lệch ở
		// đây thì phiếu in ra một dòng お釣り không bằng `Tendered − AmountPaid`.
		AmountPaid: 3685,
		Tendered:   4000,
		Change:     315,
		Remaining:  0,
		// plan-052 P-10b (#1166) — DELIBERATE golden change. The receipt and the
		// red invoice carry a reprint number here for the same reason
		// goldenVatInvoice and goldenDebtInfo always have: it is the only way
		// the fixture locks the 「BAN IN #N」 mark into the recorded bytes. Before
		// #1166 those two kinds declared the `reprint_marker` block and printed
		// nothing for it, so copy 2 of a receipt was indistinguishable from the
		// original — the exact hole the removed 422 was pretending to cover.
		// The receipt/red_invoice hashes in testdata/print_golden.json therefore
		// changed on purpose; every other kind's is byte-identical.
		ReprintNumber: 2,
	}
}

func goldenVatInvoice(locale string) VatInvoiceInfo {
	return VatInvoiceInfo{
		InvoiceNo:                "HN1-202607-00042",
		IssuedAt:                 goldenClock,
		TaxCode:                  "0312345678",
		CompanyName:              "Cty TNHH ABC Foods",
		BillingAddress:           "123 Le Loi, Q.1, TP.HCM",
		Subtotal:                 3215,
		TaxAmount:                315,
		Total:                    3530,
		TaxRatePercent:           10,
		CurrencyPrefix:           "¥",
		PaymentMethod:            "cash",
		Status:                   "issued",
		ReprintNumber:            2,
		Locale:                   locale,
		SellerRegistrationNumber: "T1234567890123",
		Items: []VatInvoiceLine{
			// #1225 — this line carries toppings ON PURPOSE. Without one the
			// TR-40 gate compared two item loops over topping-free data and
			// passed while one of them silently dropped every topping. A gate
			// that cannot see the field it is meant to protect is not a gate.
			// #1169 — this line also carries an option/variant label AND a per-line
			// note so the migration gate (legacy vs template) covers those indented
			// sub-lines byte-for-byte, not just the topping rows.
			{Name: "Bun bo Hue dac biet thap cam", Quantity: 2, UnitPrice: 980, LineTotal: 2260, VariantName: "To lon", Note: "It cay", Toppings: []VatInvoiceTopping{
				{Name: "Them thit bo", Quantity: 1, UnitPrice: 200},
				{Name: "Trung chan", Quantity: 2, UnitPrice: 50},
			}},
			{Name: "緑茶", Quantity: 1, UnitPrice: 300, LineTotal: 300},
			{Name: "Cafe sua da", Quantity: 1, UnitPrice: 890, LineTotal: 890},
		},
		TaxBreakdown: []VatInvoiceTaxLine{
			{Rate: 8, Taxable: 300, Tax: 22},
			{Rate: 10, Taxable: 2915, Tax: 265},
		},
	}
}

func goldenDebtInfo() DebtSlipInfo {
	return DebtSlipInfo{
		CustomerName:    "Cty TNHH ABC Foods",
		CustomerPhone:   "0901234567",
		CustomerTaxCode: "0312345678",
		DebtAmount:      2530,
		ReprintNumber:   2,
	}
}

func goldenShiftOpen() ShiftOpenReportInfo {
	return ShiftOpenReportInfo{
		DeviceName: "POS-01",
		Operator:   "田中",
		OpenedAt:   "2026/07/20 09:00",
		Denominations: []ShiftOpenDenomLine{
			{Value: 10000, Quantity: 1, Subtotal: 10000},
			{Value: 5000, Quantity: 0, Subtotal: 0},
			{Value: 1000, Quantity: 3, Subtotal: 3000},
		},
		OpeningFloat: 13000,
		Note:         "Ca sang, da kiem tra ket va may in nhiet truoc khi mo cua",
		Currency:     "JPY",
	}
}

func goldenShiftReport() ShiftReportInfo {
	return ShiftReportInfo{
		TillCode:      "0001",
		ZNumber:       3,
		ChainSequence: 2,
		Phone:         "0368457586",
		OpenedAt:      "2026/07/20 09:00",
		ClosedAt:      "2026/07/20 17:09",
		GrossSales:    3775,
		ItemCount:     5,
		NetSales:      3460,
		GuestCount:    4,
		TaxTotal:      315,
		Payments: []ShiftPaymentLine{
			{Code: "cash", Label: "現金", Count: 2, Amount: 2000},
			{Code: "paypay", Label: "PayPay", Count: 1, Amount: 800},
		},
		Discounts:           []ShiftDiscountLine{{Label: "WELCOME10", Count: 1, Amount: 100}},
		DiscountTotalCount:  1,
		DiscountTotalAmount: 100,
		CheckCount:          3,
		PaidInCount:         1,
		PaidInAmount:        5000,
		PaidOutCount:        1,
		PaidOutAmount:       2000,
		VoidUnpaidCount:     1,
		VoidUnpaidAmount:    500,
		VoidPaidCount:       0,
		VoidPaidAmount:      0,
		CountedCash:         16000,
		ExpectedCash:        16100,
		CashVariance:        -100,
		Operator:            "田中",
		Currency:            "JPY",
		ServiceCharge:       330,
		ServiceChargeTax:    30,
		Denominations: []ShiftOpenDenomLine{
			{Value: 10000, Quantity: 1, Subtotal: 10000},
			{Value: 1000, Quantity: 6, Subtotal: 6000},
		},
		TaxBreakdown: []ShiftTaxRateLine{
			{Rate: 8, TaxableSales: 300, Tax: 22},
			{Rate: 10, TaxableSales: 2830, Tax: 263},
		},
		ShowPaymentMethods: true,
		ShowServiceCharge:  true,
		ShowDrawerCheck:    true,
		ShowDenominations:  true,
		ShowTaxBreakdown:   true,
	}
}

func goldenChainReport() ChainReportInfo {
	s := goldenShiftReport()
	return ChainReportInfo{
		ChainID:    "0199bb22-3344-5566-7788-99aabbccddee",
		TillCode:   "0001",
		OpenedAt:   s.OpenedAt,
		ClosedAt:   s.ClosedAt,
		ShiftCount: 2,
		Shifts: []ChainShiftLine{
			{Sequence: 1, Kind: "handover", CountedCash: 9000, Variance: 0, Gross: 1500, Operator: "佐藤"},
			{Sequence: 2, Kind: "final", CountedCash: 16000, Variance: -100, Gross: 2275, Operator: "田中"},
		},
		CountedCash:        16000,
		Gross:              3775,
		Net:                3460,
		TaxTotal:           315,
		TaxByRate:          s.TaxBreakdown,
		ExpectedCash:       16100,
		Variance:           -100,
		Discount:           100,
		CheckCount:         3,
		PaidIn:             5000,
		PaidOut:            2000,
		OpeningFloat:       13000,
		ServiceCharge:      330,
		ServiceChargeTax:   30,
		Operator:           "田中",
		Payments:           s.Payments,
		HasDetail:          true,
		ItemCount:          5,
		GuestCount:         4,
		VoidUnpaidCount:    1,
		VoidUnpaidAmount:   500,
		PaidInCount:        1,
		PaidOutCount:       1,
		Discounts:          s.Discounts,
		Denominations:      s.Denominations,
		Currency:           "JPY",
		ShowTaxBreakdown:   true,
		ShowPaymentMethods: true,
		ShowServiceCharge:  true,
		ShowDrawerCheck:    true,
		ShowDenominations:  true,
	}
}

func goldenTablePaid() TablePaidInfo {
	return TablePaidInfo{TableNumber: "A-01", OrderCode: "WS-019e-20260720-004", PaidAt: "2026/07/20 14:32"}
}

// renderLegacy produces the slip the hard-coded formatter makes today.
// renderLegacy keeps the kind-only shape every existing caller uses; the
// variant-aware matrix goes through renderLegacyFor.
func renderLegacy(t *testing.T, kind string, cfg PrintJobConfig) []byte {
	t.Helper()

	return renderLegacyFor(t, goldenCase{Kind: kind}, cfg)
}

func renderLegacyFor(t *testing.T, tc goldenCase, cfg PrintJobConfig) []byte {
	t.Helper()
	kind := tc.Kind
	order, items := goldenOrderFor(tc.Variant)
	switch kind {
	case "receipt":
		return FormatPaidTicket(order, items, 7, cfg, goldenSlip())
	case "kitchen":
		return FormatKitchenTicket(order, items, 319, cfg)
	case "runner":
		return FormatRunnerTicket(order, items, 7, cfg)
	case "delta_qr":
		return FormatDeltaQRTicket(order, items, cfg)
	case "remaining":
		return FormatRemainingTicket(order, items, 7, cfg, 1200)
	case "vat_invoice":
		return FormatVatInvoice(goldenVatInvoice(cfg.Locale), cfg)
	// #1493 — CÙNG hàm legacy: `FormatVatInvoice` nay rẽ theo
	// `cfg.OperatingCountry`, và `goldenConfigFor` đặt "JP" cho kind này. Nên
	// cổng TR-40 so chính bản Nhật của formatter cũ với template mới — đúng thứ
	// nó sinh ra để chứng minh.
	case "qualified_simplified_invoice":
		return FormatVatInvoice(goldenVatInvoice(cfg.Locale), cfg)
	case "red_invoice":
		return FormatRedInvoiceTicket(order, items, cfg, goldenSlip())
	case "void_notice":
		return FormatVoidNotice(goldenVatInvoice(cfg.Locale), "Khach tra lai hang", goldenClock, cfg)
	case "debt_slip":
		return FormatDebtSlip(order, items, cfg, goldenDebtInfo())
	case "shift_open":
		return FormatShiftOpenReport(cfg, goldenShiftOpen())
	case "shift_report":
		return FormatShiftReport(cfg, goldenShiftReport())
	case "chain_report":
		return FormatChainReport(cfg, goldenChainReport())
	case "table_paid":
		return FormatTablePaid(cfg, goldenTablePaid())
	}
	t.Fatalf("no legacy formatter wired for kind %q", kind)
	return nil
}

// goldenRenderData builds the SAME payload through the production builders, so
// the gate compares two renderers rather than two sets of numbers.
// goldenRenderData keeps the kind-only shape every existing caller uses; the
// variant-aware matrix goes through goldenRenderDataFor.
func goldenRenderData(kind string, cfg PrintJobConfig) *PrintRenderData {
	return goldenRenderDataFor(goldenCase{Kind: kind}, cfg)
}

func goldenRenderDataFor(tc goldenCase, cfg PrintJobConfig) *PrintRenderData {
	kind := tc.Kind
	order, items := goldenOrderFor(tc.Variant)
	switch kind {
	case "receipt":
		return NewPaidRenderData(order, items, 7, cfg, goldenSlip())
	case "kitchen":
		return NewKitchenRenderData(order, items, 319, cfg)
	case "runner":
		return NewRunnerRenderData(order, items, 7, cfg)
	case "delta_qr":
		return NewDeltaQRRenderData(order, items, cfg)
	case "remaining":
		return NewRemainingRenderData(order, items, 7, cfg, 1200)
	case "vat_invoice":
		return NewVatInvoiceRenderData(goldenVatInvoice(cfg.Locale), cfg)
	// #1492/#1493 — 適格簡易請求書 dùng chung dữ liệu dựng của vat_invoice: cùng
	// bộ block, cùng emitter (xem registerPrintKind trong print_renderer_docs.go).
	// Không có case này thì goldenRenderData trả nil và mọi test lặp theo
	// SystemPrintTemplateKinds() chết với "print render: nil data".
	//
	// KHÔNG ghi đè `Kind` ở đây: từ #1493 `NewVatInvoiceRenderData` tự suy kind
	// theo `cfg.OperatingCountry` (mà `goldenConfigFor` đặt là "JP"), đúng cùng
	// luật mà `FormatVatInvoice` dùng để chọn layout. Ghi đè tay sẽ che mất
	// chính cái liên kết đó — và liên kết ấy là thứ giữ hai renderer không lệch.
	case "qualified_simplified_invoice":
		return NewVatInvoiceRenderData(goldenVatInvoice(cfg.Locale), cfg)
	case "red_invoice":
		return NewRedInvoiceRenderData(order, items, cfg, goldenSlip())
	case "void_notice":
		return NewVoidNoticeRenderData(goldenVatInvoice(cfg.Locale), "Khach tra lai hang", goldenClock, cfg)
	case "debt_slip":
		return NewDebtSlipRenderData(order, items, cfg, goldenDebtInfo())
	case "shift_open":
		return NewShiftOpenRenderData(cfg, goldenShiftOpen())
	case "shift_report":
		return NewShiftReportRenderData(cfg, goldenShiftReport())
	case "chain_report":
		return NewChainReportRenderData(cfg, goldenChainReport())
	case "table_paid":
		return NewTablePaidRenderData(cfg, goldenTablePaid())
	}
	return nil
}

// diffBytes renders the first differing line of two ESC/POS streams in a form a
// human can act on: the byte offset, then both sides printed with control bytes
// escaped so a stray Bold(off) is visible.
func diffBytes(want, got []byte) string {
	i := 0
	for i < len(want) && i < len(got) && want[i] == got[i] {
		i++
	}
	start := i - 60
	if start < 0 {
		start = 0
	}
	var b strings.Builder
	fmt.Fprintf(&b, "first difference at byte %d (legacy %d bytes, rendered %d bytes)\n", i, len(want), len(got))
	fmt.Fprintf(&b, "legacy   : %s\n", escapeBytes(want, start, i+80))
	fmt.Fprintf(&b, "rendered : %s\n", escapeBytes(got, start, i+80))
	return b.String()
}

func escapeBytes(b []byte, from, to int) string {
	if from > len(b) {
		from = len(b)
	}
	if to > len(b) {
		to = len(b)
	}
	var out strings.Builder
	for _, c := range b[from:to] {
		switch {
		case c == '\n':
			out.WriteString("⏎")
		case c < 0x20 || c > 0x7e:
			fmt.Fprintf(&out, "<%02X>", c)
		default:
			out.WriteByte(c)
		}
	}
	return out.String()
}
