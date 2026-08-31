package handler

import (
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
	"golang.org/x/text/encoding/japanese"
)

// decodeSJIS decodes the ESC/POS output (Shift_JIS text + control bytes) back to
// UTF-8 so Japanese labels/names can be asserted. Control bytes (<0x80) pass
// through as ASCII; 2-byte kanji decode correctly.
func decodeSJIS(b []byte) string {
	out, _ := japanese.ShiftJIS.NewDecoder().Bytes(b)
	return string(out)
}

// The paid receipt AND the red invoice must follow the pos-web operator's
// language for BOTH the static labels (支払方法 / Method / Phuong thuc) and the
// item / variant / topping names — resolved from the localized catalog and
// re-applied at print time by localizeOrderForPrint. Vietnamese diacritics are
// auto-folded to ASCII by the Shift_JIS encoder ("Nước mắm" → "Nuoc mam").
func TestReceiptAndRedInvoice_LocalizeLabelsAndNames(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO pos_products (id, name, name_ja, name_en, name_vi) VALUES ('p1','X','ベトナムコーヒー','Vietnamese Coffee','Cà phê')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, name_ja, name_en, name_vi, sku, selling_price) VALUES ('sk1','p1','X','アイス','Iced','Đá','C-1',450)`)
	mustExec(t, db, `INSERT INTO pos_products (id, name, name_ja, name_en, name_vi) VALUES ('tp','X','ヌクマム','Fish sauce','Nước mắm')`)
	mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, name_ja, name_en, name_vi, sku, selling_price) VALUES ('tsk','tp','X','ヌクマム','Fish sauce','Nước mắm','FS',0)`)

	newOrder := func() *service.Order {
		return &service.Order{
			ID:          "o1",
			OrderCode:   "ORD-1",
			TableNumber: "A-01",
			PaidAmount:  450,
			TotalAmount: 450,
			Items: []service.Item{{
				ID:             "it1",
				ProductSkuID:   "sk1",
				MenuItemName:   "snapshot",
				SkuVariantName: "snapshot",
				Quantity:       1,
				UnitPrice:      450,
				Toppings:       []service.ItemTopping{{ProductSkuID: "tsk", Name: "snapshot", Quantity: 1}},
			}},
		}
	}

	cases := []struct {
		locale                                           string
		wantProduct, wantTopping, wantLabel, absentLabel string
	}{
		{"ja", "ベトナムコーヒー", "ヌクマム", "支払方法", "Phuong thuc"},
		{"en", "Vietnamese Coffee", "Fish sauce", "Method", "支払方法"},
		{"vi", "Ca phe", "Nuoc mam", "Phuong thuc", "Method"},
	}
	for _, c := range cases {
		t.Run(c.locale, func(t *testing.T) {
			cfg := service.PrintJobConfig{Locale: c.locale, PaperWidth: 48}

			// --- paid receipt ---
			o := newOrder()
			srv.localizeOrderForPrint(o, c.locale)
			receipt := decodeSJIS(service.FormatPaidTicket(o, o.Items, 0, cfg,
				service.PaymentSlipInfo{PaymentMethod: "cash", AmountPaid: 450, Tendered: 500, Change: 50}))
			for _, want := range []string{c.wantProduct, c.wantTopping, c.wantLabel} {
				if !strings.Contains(receipt, want) {
					t.Errorf("[%s receipt] missing %q in:\n%s", c.locale, want, receipt)
				}
			}
			if strings.Contains(receipt, c.absentLabel) {
				t.Errorf("[%s receipt] must NOT contain other-locale label %q", c.locale, c.absentLabel)
			}

			// --- red invoice ---
			o2 := newOrder()
			srv.localizeOrderForPrint(o2, c.locale)
			red := decodeSJIS(service.FormatRedInvoiceTicket(o2, o2.Items, cfg,
				service.PaymentSlipInfo{PaymentMethod: "cash", AmountPaid: 450, CustomerName: "Khach A"}))
			for _, want := range []string{c.wantProduct, c.wantTopping, c.wantLabel} {
				if !strings.Contains(red, want) {
					t.Errorf("[%s red-invoice] missing %q", c.locale, want)
				}
			}
		})
	}
}
