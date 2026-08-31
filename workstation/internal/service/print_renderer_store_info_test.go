package service

import (
	"bytes"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// #2000 bước 2 — `store_info.fields` giờ CÓ hiệu lực ở phía Go.
//
// Điều đáng canh nhất không phải "in được địa chỉ" mà là **quán đang chạy không
// đổi byte**: bản mặc định không khai `store_sub_name`/`store_address`, nên một
// hệ thống chưa ai sửa mẫu phải in ra đúng tờ giấy hôm qua.

func storeInfoCtx(t *testing.T, fields []string, enabled *bool) []byte {
	t.Helper()

	def := &PrintTemplateDefinition{
		Blocks: []PrintTemplateBlock{{ID: "store_info", Type: "params", Fields: fields, Enabled: enabled}},
	}
	c := &printRenderCtx{
		e:   escpos.New(),
		def: def,
		cfg: PrintJobConfig{StoreSubName: "SUBNAME-X", StoreAddress: "ADDRESS-X", StorePhone: "PHONE-X", StoreOrganization: "ORG-X"},
	}

	before := len(c.e.Bytes())
	emitStoreBelow(c)

	return c.e.Bytes()[before:]
}

func TestStoreDetailLines_NoFieldsEmitsNothing(t *testing.T) {
	// Đây là ca giữ cho mọi quán đang chạy không đổi giấy.
	if out := storeInfoCtx(t, nil, nil); len(out) != 0 {
		t.Errorf("không khai field nào mà phát %d byte: %q", len(out), out)
	}
	if out := storeInfoCtx(t, []string{"store_name"}, nil); len(out) != 0 {
		t.Errorf("chỉ khai store_name mà phát thêm %d byte: %q", len(out), out)
	}
}

func TestStoreDetailLines_DrawsDeclaredFields(t *testing.T) {
	out := storeInfoCtx(t, []string{"store_sub_name", "store_address"}, nil)

	if !bytes.Contains(out, []byte("SUBNAME-X")) {
		t.Error("khai store_sub_name mà không in ra")
	}
	if !bytes.Contains(out, []byte("ADDRESS-X")) {
		t.Error("khai store_address mà không in ra")
	}
}

// Thứ tự là của DEFINITION, không phải của hàm: người thiết kế phiếu quyết định
// địa chỉ đứng trên hay dưới tên thương hiệu.
func TestStoreDetailLines_HonoursDeclaredOrder(t *testing.T) {
	forward := storeInfoCtx(t, []string{"store_sub_name", "store_address"}, nil)
	reverse := storeInfoCtx(t, []string{"store_address", "store_sub_name"}, nil)

	if bytes.Index(forward, []byte("SUBNAME-X")) > bytes.Index(forward, []byte("ADDRESS-X")) {
		t.Error("thứ tự xuôi bị đảo")
	}
	if bytes.Index(reverse, []byte("ADDRESS-X")) > bytes.Index(reverse, []byte("SUBNAME-X")) {
		t.Error("thứ tự ngược không được tôn trọng")
	}
}

func TestStoreDetailLines_DisabledBlockEmitsNothing(t *testing.T) {
	off := false
	if out := storeInfoCtx(t, []string{"store_sub_name", "store_address"}, &off); len(out) != 0 {
		t.Errorf("khối tắt mà vẫn phát %d byte", len(out))
	}
}

// #2000 bước 3 đã dựng đường cho `store_phone`: Cloud gửi `phone` trong feed
// branch, `PullBranch` lưu vào `workstation_branch_phone`, `PrintJobConfig` có ô
// `StorePhone`. Giờ khai field này phải RA GIẤY.
func TestStoreDetailLines_DrawsPhone(t *testing.T) {
	out := storeInfoCtx(t, []string{"store_phone"}, nil)

	if !bytes.Contains(out, []byte("PHONE-X")) {
		t.Errorf("khai store_phone mà không in ra: %q", out)
	}
}

// #2000 bước 4 — 法人名 là ô RIÊNG, không phải `store_sub_name` đổi tên. Ba thứ
// khác nhau: pháp nhân · thương hiệu · chi nhánh.
func TestStoreDetailLines_DrawsOrganization(t *testing.T) {
	out := storeInfoCtx(t, []string{"store_organization"}, nil)

	if !bytes.Contains(out, []byte("ORG-X")) {
		t.Errorf("khai store_organization mà không in ra: %q", out)
	}
	if bytes.Contains(out, []byte("SUBNAME-X")) {
		t.Error("in nhầm tên thương hiệu khi được hỏi tên pháp nhân")
	}
}
