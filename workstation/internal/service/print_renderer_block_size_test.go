package service

import (
	"bytes"
	"encoding/json"
	"testing"
)

/*
Vế Go của `"size": "tall"` — khả năng ĐÃ SHIP mà chưa có rào.

`print_renderer.go` bọc mỗi khối bằng cỡ chữ của chính nó (#3082): `tall` ⇒
`ESC i 1 0` (×2 CAO, ×1 rộng), rồi trả về `NormalSize` ngay sau khối. Cố ý
không có double-width — nhân đôi chiều rộng thì mỗi glyph ăn hai cột và
`wrapText`, căn giữa/phải, hiệu chỉnh lề trái đều phải tính lại.

Trạng thái trước bộ test này, đo 2026-08-18:

	PHP  CÓ rào  — KitchenSlipTopFeedAndItemSizeTest, ghim cả enum ['normal','tall']
	Go   KHÔNG   — `b.Size == "tall"` không bài nào chạm
	mẫu  không mẫu nào đặt `size` (#3082 từng đặt rồi gỡ)

Vế cuối là lý do rào so byte PHP↔Go cũng không phủ gián tiếp: không có gì để
so. Nên nếu #2930 chốt dùng `tall` cho phiếu khách, đó là lần ĐẦU khả năng này
được bật ngoài đời — và bật một thứ chưa ai đo là cách nó hỏng lặng lẽ.
*/

// renderWithBlockSize dựng lại mẫu hệ thống `kind`, đặt `size` cho khối `blockID`,
// rồi render ra byte.
func renderWithBlockSize(t *testing.T, kind, blockID, size string) []byte {
	t.Helper()

	raw, err := SystemPrintTemplateRaw(kind)
	if err != nil {
		t.Fatal(err)
	}
	var doc map[string]any
	if err := json.Unmarshal(raw, &doc); err != nil {
		t.Fatal(err)
	}

	found := false
	for _, b := range doc["blocks"].([]any) {
		blk := b.(map[string]any)
		if blk["id"] != blockID {
			continue
		}
		found = true
		if size == "" {
			delete(blk, "size")
		} else {
			blk["size"] = size
		}
	}
	// Mẫu số bằng không: nếu khối đổi tên thì bài này đo khoảng không và vẫn
	// xanh. Bắt nó ĐỎ thay vì im.
	if !found {
		t.Fatalf("mẫu %q không có khối %q — bố cục đổi, sửa bài test chứ đừng xoá", kind, blockID)
	}

	body, _ := json.Marshal(doc)
	def, err := ParsePrintTemplateDefinition(body)
	if err != nil {
		t.Fatal(err)
	}

	cfg := goldenConfig("ja", 42)
	res, err := RenderPrintTemplate(def, goldenRenderData(kind, cfg), PrintRenderProfile{Columns: 42}, "ja")
	if err != nil {
		t.Fatal(err)
	}

	return res.Bytes()
}

func TestBlockSizeTallEmitsDoubleHeightAroundTheBlock(t *testing.T) {
	// ESC i 1 0 — ×2 cao. KHÔNG phải ESC ! của Epson: trên máy Star, `ESC !`
	// bị bỏ và byte tham số in ra thành ký tự lạ trên phiếu (án lệ đã ghi ở
	// escpos/encoder.go).
	doubleHeight := []byte{0x1B, 0x69, 0x01, 0x00}
	normal := []byte{0x1B, 0x69, 0x00, 0x00}

	plain := renderWithBlockSize(t, "receipt", "order_meta", "")
	tall := renderWithBlockSize(t, "receipt", "order_meta", "tall")

	if bytes.Equal(plain, tall) {
		t.Fatal("đặt size=tall KHÔNG đổi một byte nào — khối đang bỏ qua `b.Size`")
	}
	if !bytes.Contains(tall, doubleHeight) {
		t.Errorf("không thấy ESC i 1 0 trong phiếu — `tall` không phát lệnh phóng cao")
	}
	// Trả về cỡ thường NGAY SAU khối. Để rò rỉ sang khối kế là biến một lựa
	// chọn cục bộ thành trạng thái toàn phiếu — chính điều comment ở
	// print_renderer.go cảnh báo.
	if i := bytes.Index(tall, doubleHeight); i >= 0 {
		if !bytes.Contains(tall[i:], normal) {
			t.Errorf("sau khối `tall` không thấy ESC i 0 0 — cỡ chữ rò rỉ sang khối sau")
		}
	}
}

func TestBlockSizeUnknownValueIsIgnoredNotGuessed(t *testing.T) {
	// Chỉ `tall` được công nhận. Một giá trị lạ phải phát ra ĐÚNG byte như khi
	// không đặt gì — đoán bừa một cỡ chữ từ chuỗi không hiểu là cách một lỗi gõ
	// đi thẳng lên giấy của quán.
	plain := renderWithBlockSize(t, "receipt", "order_meta", "")
	weird := renderWithBlockSize(t, "receipt", "order_meta", "HUGE")

	if !bytes.Equal(plain, weird) {
		t.Error("giá trị `size` lạ làm đổi byte — nó phải bị bỏ qua, không được đoán")
	}
}

func TestBlockSizeAbsentKeepsTodaysBytes(t *testing.T) {
	// Vế IM. Khối không đặt `size` phải phát ra đúng byte như hôm nay, nếu
	// không thì #3082 đã âm thầm đổi mọi phiếu đang chạy.
	raw, err := SystemPrintTemplateRaw("receipt")
	if err != nil {
		t.Fatal(err)
	}
	def, err := ParsePrintTemplateDefinition(raw)
	if err != nil {
		t.Fatal(err)
	}
	cfg := goldenConfig("ja", 42)
	res, err := RenderPrintTemplate(def, goldenRenderData("receipt", cfg), PrintRenderProfile{Columns: 42}, "ja")
	if err != nil {
		t.Fatal(err)
	}

	if got := renderWithBlockSize(t, "receipt", "order_meta", ""); !bytes.Equal(res.Bytes(), got) {
		t.Error("gỡ khoá `size` khỏi khối làm đổi byte — đường dựng lại mẫu của bài test không trung thực")
	}
}
