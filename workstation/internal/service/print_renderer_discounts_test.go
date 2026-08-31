package service

import (
	"bytes"
	"strings"
	"testing"
)

// #2071 — khối `discounts` của phiếu bill in SỔ, không in cột.
//
// Ruling: giảm giá lên giấy theo TỪNG MỨC THUẾ, đọc thẳng các dòng
// `order_conditions` (type='discount') mà engine đã ghi (#2031). Tầng in không
// cộng, không phân bổ lại, không rơi về `orders.discount_amount` — cột đó giữ
// số YÊU CẦU, sổ giữ số ĐÃ ÁP DỤNG, và khi hai bên khác nhau thì sổ mới là
// bên nói về tiền.
//
// Các bài dưới đây là rào HÀNH VI, bổ sung cho ba cổng byte (TR-40 ·
// print_golden.json · SlipByteParityTest): cổng byte đóng băng NGUYÊN TRẠNG,
// còn ở đây khẳng định nguyên trạng nói đúng điều gì — và mỗi bài đã được thử
// đột biến (đổi nguồn đọc của emitter) để chắc nó đỏ đúng chỗ.

func renderReceiptFor(t *testing.T, order *Order, items []Item, slip PaymentSlipInfo, locale string) []byte {
	t.Helper()
	restore := freezePrintClock(t)
	defer restore()

	cfg := goldenConfigFor("receipt", locale, 48)
	def, err := SystemPrintTemplate("receipt")
	if err != nil {
		t.Fatal(err)
	}
	res, err := RenderPrintTemplate(def, NewPaidRenderData(order, items, 7, cfg, slip),
		PrintRenderProfile{Columns: 48}, locale)
	if err != nil {
		t.Fatal(err)
	}
	return res.Bytes()
}

// Phiếu receipt in MỖI DÒNG SỔ một dòng giấy, kèm nhóm mức — không phải một
// dòng tổng.
func TestBillDiscounts_ReceiptPrintsOneRowPerLedgerRow(t *testing.T) {
	order, items := goldenOrder()
	got := decodeSJIS(t, renderReceiptFor(t, order, items, goldenSlip(), "vi"))

	for _, want := range []string{"Giam gia (8%)", "Giam gia (10%)", "-\\9", "-\\91"} {
		// decodeSJIS trả 0x5C thành `\` (Shift_JIS đặt ¥ ở 0x5C).
		if !strings.Contains(got, want) {
			t.Errorf("receipt thiếu %q trong:\n%s", want, got)
		}
	}
}

// Nguồn là SỔ, không phải cột: đổi `DiscountAmount` mà giữ nguyên sổ thì byte
// không đổi; đổi SỔ thì byte đổi. Đây là phát biểu trực tiếp của ruling #2071
// ("cột là số yêu cầu, sổ là số áp dụng") dưới dạng một phép đo.
func TestBillDiscounts_LedgerWinsOverColumn(t *testing.T) {
	order, items := goldenOrder()
	base := renderReceiptFor(t, order, items, goldenSlip(), "vi")

	mutatedColumn, _ := goldenOrder()
	mutatedColumn.DiscountAmount = 9999
	if !bytes.Equal(base, renderReceiptFor(t, mutatedColumn, items, goldenSlip(), "vi")) {
		t.Error("đổi cột discount_amount làm phiếu đổi byte — khối discounts đang đọc CỘT, phải đọc SỔ")
	}

	mutatedLedger, _ := goldenOrder()
	mutatedLedger.Discounts[0].Amount = -10
	if bytes.Equal(base, renderReceiptFor(t, mutatedLedger, items, goldenSlip(), "vi")) {
		t.Error("đổi dòng sổ mà phiếu không đổi byte — khối discounts không đọc sổ")
	}
}

// Dòng sổ không nhóm mức (đơn không có dòng chịu thuế) in nhãn TRẦN — không
// suffix, không bịa một mức.
func TestBillDiscounts_NilRateRowPrintsBareLabel(t *testing.T) {
	order, items := goldenOrder()
	order.Discounts = []OrderDiscountLine{{Rate: nil, Amount: -100}}
	got := decodeSJIS(t, renderReceiptFor(t, order, items, goldenSlip(), "vi"))

	if !strings.Contains(got, "Giam gia") {
		t.Fatalf("thiếu dòng giảm giá:\n%s", got)
	}
	if strings.Contains(got, "Giam gia (") {
		t.Errorf("dòng không nhóm mức lại mang suffix mức:\n%s", got)
	}
}

// Phiếu con của một lần chia bill KHÔNG in khối giảm giá — nó hiển thị một
// PHẦN tiền mà các dòng sổ mức-đơn không mô tả, cùng luật với `subtotal`.
func TestBillDiscounts_SplitSubBillSuppressesRows(t *testing.T) {
	order, items := goldenOrder()
	slip := goldenSlip()
	slip.SplitCount = 2
	slip.SlipIndex = 1
	slip.BillTotal = 1760
	got := decodeSJIS(t, renderReceiptFor(t, order, items, slip, "vi"))

	if strings.Contains(got, "Giam gia") {
		t.Errorf("phiếu con chia bill vẫn in dòng giảm giá của CẢ đơn:\n%s", got)
	}
}

// Chỉ `receipt` khai block trong catalog — các kind bill khác nhận cùng
// dữ liệu Discounts phải ra byte Y HỆT khi có hay không có nó (khối không
// render ⇒ không đọc).
func TestBillDiscounts_OtherBillKindsDoNotPrintThem(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	for _, kind := range []string{"runner", "delta_qr", "remaining", "red_invoice"} {
		cfg := goldenConfigFor(kind, "vi", 48)
		def, err := SystemPrintTemplate(kind)
		if err != nil {
			t.Fatal(err)
		}

		withRows := goldenRenderData(kind, cfg)
		res1, err := RenderPrintTemplate(def, withRows, PrintRenderProfile{Columns: 48}, "vi")
		if err != nil {
			t.Fatal(err)
		}

		bare := goldenRenderData(kind, cfg)
		bare.Order.Discounts = nil
		res2, err := RenderPrintTemplate(def, bare, PrintRenderProfile{Columns: 48}, "vi")
		if err != nil {
			t.Fatal(err)
		}

		if !bytes.Equal(res1.Bytes(), res2.Bytes()) {
			t.Errorf("%s: byte đổi theo Order.Discounts dù kind không khai block `discounts`", kind)
		}
	}
}
