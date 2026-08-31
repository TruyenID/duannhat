package service

import (
	"bytes"
	"testing"
)

// #1493 — CHỨNG TỪ đi theo QUỐC GIA của shop, không theo ngôn ngữ thu ngân.
//
// Đây là bảng đúng/sai của #1459, dựng thành test. Trước bản này hai ô sai:
// quán VN để giao diện tiếng Nhật in ra chứng từ NHẬT, và quán JP để giao diện
// tiếng Việt in ra hoá đơn GTGT VIỆT NAM. Bốn trục độc lập, cấm suy diễn chéo —
// compliance-country ≠ currency ≠ timezone ≠ print locale.
func TestVatInvoice_DocumentFollowsCountryNotLocale_1493(t *testing.T) {
	// 領収書 là tiêu đề riêng của layout Nhật; hoá đơn GTGT không bao giờ in nó.
	japaneseHeading := []byte{0x97, 0xcc, 0x8e, 0xfb, 0x8f, 0x91}

	cases := []struct {
		country  string
		locale   string
		wantJA   bool
		whyWrong string
	}{
		{"VN", "vi", false, ""},
		{"VN", "en", false, ""},
		{"VN", "ja", false, "quán VIỆT mà thu ngân để tiếng Nhật vẫn phải ra hoá đơn GTGT"},
		{"JP", "ja", true, ""},
		{"JP", "vi", true, "quán NHẬT mà thu ngân để tiếng Việt vẫn phải ra 適格簡易請求書"},
		{"JP", "en", true, "quán NHẬT với giao diện tiếng Anh vẫn phải ra chứng từ Nhật"},
		// Chữ thường / khoảng trắng thừa từ một feed cẩu thả không được đổi chứng từ.
		{" jp ", "vi", true, "quốc gia phải so không phân biệt hoa thường và bỏ khoảng trắng"},
	}

	for _, tc := range cases {
		info := goldenVatInvoice(tc.locale)
		cfg := goldenConfig(tc.locale, 48)
		cfg.OperatingCountry = tc.country

		out := FormatVatInvoice(info, cfg)
		gotJA := bytes.Contains(out, japaneseHeading)

		if gotJA != tc.wantJA {
			t.Errorf("country=%q locale=%q: chứng từ Nhật = %v, muốn %v — %s",
				tc.country, tc.locale, gotJA, tc.wantJA, tc.whyWrong)
		}
	}
}

// Quốc gia RỖNG là trạng thái thật, không phải lỗi: bản chưa pull lần nào, hoặc
// Cloud cũ hơn #1490. Khi đó phải giữ NGUYÊN hành vi trước #1493 — mặc định một
// quốc gia ở đây sẽ làm một quán mất chứng từ luật định giữa chừng, hỏng nặng
// hơn hẳn việc chọn sai bằng locale (vốn ít nhất đang chạy cho quán Nhật).
func TestVatInvoice_UnknownCountryKeepsLocaleFallback_1493(t *testing.T) {
	japaneseHeading := []byte{0x97, 0xcc, 0x8e, 0xfb, 0x8f, 0x91}

	for _, tc := range []struct {
		locale string
		wantJA bool
	}{
		{"ja", true},
		{"vi", false},
		{"en", false},
		{"", true}, // normalizePrintLocale: rỗng/không rõ → "ja", hành vi cũ từng chữ
	} {
		cfg := goldenConfig(tc.locale, 48)
		cfg.OperatingCountry = ""

		out := FormatVatInvoice(goldenVatInvoice(tc.locale), cfg)
		if got := bytes.Contains(out, japaneseHeading); got != tc.wantJA {
			t.Errorf("quốc gia rỗng, locale=%q: chứng từ Nhật = %v, muốn %v", tc.locale, got, tc.wantJA)
		}
	}
}

// Đường TEMPLATE phải chọn cùng chứng từ với đường LEGACY. Hai renderer rẽ theo
// hai tín hiệu khác nhau (legacy: quốc gia trong config; template: kind của
// data), nên đây là chỗ chúng có thể lệch mà không ai thấy.
func TestVatInvoiceRenderData_KindFollowsCountry_1493(t *testing.T) {
	for _, tc := range []struct {
		country string
		locale  string
		want    string
	}{
		{"JP", "vi", "qualified_simplified_invoice"},
		{"VN", "ja", "vat_invoice"},
		{"", "ja", "qualified_simplified_invoice"}, // đường lui theo locale
		{"", "vi", "vat_invoice"},
	} {
		cfg := goldenConfig(tc.locale, 48)
		cfg.OperatingCountry = tc.country

		got := NewVatInvoiceRenderData(goldenVatInvoice(tc.locale), cfg).Kind
		if got != tc.want {
			t.Errorf("country=%q locale=%q: kind=%q, muốn %q", tc.country, tc.locale, got, tc.want)
		}
	}
}
