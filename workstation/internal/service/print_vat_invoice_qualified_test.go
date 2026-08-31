package service

import (
	"bytes"
	"testing"
)

// #1734 — tờ giấy không được vừa nhận vừa phủ nhận là chứng từ đủ điều kiện.
//
// Theo インボイス制度, 登録番号 của NGƯỜI BÁN là thứ làm một tờ giấy trở thành
// 適格簡易請求書. Trước bản này slip Nhật luôn in nhãn 適格簡易請求書 Ở TRÊN và
// luôn in 「※適格請求書等の代替ではありません」 Ở DƯỚI — hai câu không thể cùng
// đúng.
func TestVatInvoiceJA_QualifiedClaimFollowsRegistrationNumber_1734(t *testing.T) {
	// Luồng in là Shift_JIS (cp932), không phải UTF-8. So bằng chuỗi Go sẽ KHÔNG
	// BAO GIỜ khớp, và test sẽ đỏ với một thông điệp nói về nội dung chứng từ —
	// dẫn người đọc đi sai hướng hoàn toàn. Đã vấp đúng chỗ này khi viết nó.
	label := []byte{0x93, 0x4b, 0x8a, 0x69, 0x8a, 0xc8, 0x88, 0xd5, 0x90, 0xbf, 0x8b, 0x81, 0x8f, 0x91}                                                                                                                              // 適格簡易請求書
	disclaimer := []byte{0x81, 0xa6, 0x93, 0x4b, 0x8a, 0x69, 0x90, 0xbf, 0x8b, 0x81, 0x8f, 0x91, 0x93, 0x99, 0x82, 0xcc, 0x91, 0xe3, 0x91, 0xd6, 0x82, 0xc5, 0x82, 0xcd, 0x82, 0xa0, 0x82, 0xe8, 0x82, 0xdc, 0x82, 0xb9, 0x82, 0xf1} // ※適格請求書等の代替ではありません
	internalCopy := []byte{0x8e, 0xd0, 0x93, 0xe0, 0x8e, 0x51, 0x8f, 0xc6, 0x97, 0x70, 0x81, 0x69, 0x8d, 0x54, 0x82, 0xa6, 0x81, 0x6a}                                                                                               // 社内参照用（控え）
	receiptHeading := []byte{0x97, 0xcc, 0x8e, 0xfb, 0x8f, 0x91}                                                                                                                                                                     // 領収書

	t.Run("có 登録番号 ⇒ là chứng từ đủ điều kiện, KHÔNG phủ nhận", func(t *testing.T) {
		info := goldenVatInvoice("ja")
		info.SellerRegistrationNumber = "T1234567890123"
		cfg := goldenConfig("ja", 48)
		cfg.OperatingCountry = "JP"

		out := FormatVatInvoice(info, cfg)

		if !bytes.Contains(out, label) {
			t.Error("có số đăng ký mà thiếu nhãn 適格簡易請求書")
		}
		if bytes.Contains(out, disclaimer) {
			t.Error("có số đăng ký mà VẪN in câu miễn trừ — tờ giấy tự phủ nhận mình")
		}
		if bytes.Contains(out, internalCopy) {
			t.Error("có số đăng ký mà vẫn ghi 社内参照用（控え）")
		}
	})

	t.Run("thiếu 登録番号 ⇒ KHÔNG được dán nhãn, và phải nói rõ", func(t *testing.T) {
		info := goldenVatInvoice("ja")
		info.SellerRegistrationNumber = ""
		cfg := goldenConfig("ja", 48)
		cfg.OperatingCountry = "JP"

		out := FormatVatInvoice(info, cfg)

		if bytes.Contains(out, label) {
			t.Error("KHÔNG có số đăng ký mà vẫn dán nhãn 適格簡易請求書 — tuyên bố sai")
		}
		if !bytes.Contains(out, disclaimer) {
			t.Error("KHÔNG có số đăng ký thì phải giữ câu miễn trừ")
		}
		// 領収書 canh giữa ở đầu slip vẫn còn — tờ giấy vẫn là một biên lai hợp lệ.
		if !bytes.Contains(out, receiptHeading) {
			t.Error("mất luôn tiêu đề 領収書 — tờ giấy không còn là biên lai nào cả")
		}
	})
}

// Hai renderer phải nói CÙNG một điều. Nhãn do brand soạn trong template, nên
// đây là chỗ một brand có thể dán nhãn pháp lý lên tờ giấy không đủ điều kiện.
func TestVatInvoiceJA_TemplateCannotAuthorFalseQualifiedLabel_1734(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	for _, reg := range []string{"T1234567890123", ""} {
		info := goldenVatInvoice("ja")
		info.SellerRegistrationNumber = reg
		cfg := goldenConfig("ja", 48)
		cfg.OperatingCountry = "JP"

		def, err := SystemPrintTemplate("qualified_simplified_invoice")
		if err != nil {
			t.Fatalf("system def: %v", err)
		}
		data := NewVatInvoiceRenderData(info, cfg)

		res, err := RenderPrintTemplate(def, data, PrintRenderProfile{Columns: 48}, "ja")
		if err != nil {
			t.Fatalf("render: %v", err)
		}

		legacy := FormatVatInvoice(info, cfg)
		if !bytes.Equal(legacy, res.Bytes()) {
			t.Errorf("reg=%q: template và legacy KHÔNG khớp byte — một trong hai đang nói sai về tư cách pháp lý của tờ giấy", reg)
		}
	}
}
