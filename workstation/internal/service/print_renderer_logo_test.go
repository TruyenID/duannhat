package service

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
	"github.com/dxs-platform/workstation-app/internal/store"
)

// #1957 mảnh C — emitter khối `logo` phía Go.
//
// Đối xứng với `LogoBlockTest` bên PHP, và canh cùng hai thứ: TR-40 (chưa ai tải
// logo ⇒ byte y hệt hôm nay) và hợp đồng thứ tự lệnh `align → raster → align(left)`.

// emittedLogo trả phần byte mà emitter THÊM VÀO, không phải cả luồng: một
// `escpos.New()` đã mang sẵn lệnh init, nên so với rỗng là so nhầm mốc.
func emittedLogo(t *testing.T, images *PrintImageStore, wallClock string, b *PrintTemplateBlock) []byte {
	t.Helper()

	c := &printRenderCtx{e: escpos.New(), images: images, branchWallClock: wallClock}
	before := len(c.e.Bytes())
	emitLogo(c, b)

	return c.e.Bytes()[before:]
}

// Ca quan trọng nhất của cả mảnh C: mọi quán chưa tải logo phải in ra byte y hệt
// hôm nay. Một lệnh align lạc vào đây cũng là một byte đổi trên MỌI phiếu.
func TestEmitLogo_TR40_NoImageEmitsNothing(t *testing.T) {
	db := newPrintTemplateTestDB(t)

	out := emittedLogo(t, NewPrintImageStore(db), "", &PrintTemplateBlock{
		ID: "logo", Source: "brand_logo", MaxWidthDots: 576,
	})

	if len(out) != 0 {
		t.Errorf("không có ảnh mà phát %d byte: %x", len(out), out)
	}
}

// Store chưa nối dây (bề mặt xem trước, test cũ) cũng phải im lặng.
func TestEmitLogo_NilStoreEmitsNothing(t *testing.T) {
	out := emittedLogo(t, nil, "", &PrintTemplateBlock{ID: "logo", Source: "brand_logo"})

	if len(out) != 0 {
		t.Errorf("store nil mà phát %d byte", len(out))
	}
}

func TestEmitLogo_NoSourceEmitsNothing(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	seedLogo(t, db, 576, 200, 4)

	out := emittedLogo(t, NewPrintImageStore(db), "", &PrintTemplateBlock{ID: "logo", MaxWidthDots: 576})

	if len(out) != 0 {
		t.Errorf("không có source mà phát %d byte", len(out))
	}
}

func TestEmitLogo_EmitsAlignRasterAlign(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	seedLogo(t, db, 576, 200, 4)

	out := emittedLogo(t, NewPrintImageStore(db), "", &PrintTemplateBlock{
		ID: "logo", Source: "brand_logo", MaxWidthDots: 576,
	})

	if !bytes.HasPrefix(out, escpos.AlignCenter) {
		t.Errorf("không mở bằng align(center): %x", out[:min(8, len(out))])
	}
	if !bytes.HasSuffix(out, escpos.AlignLeft) {
		t.Errorf("không đóng bằng align(left): %x", out[max(0, len(out)-8):])
	}
	// `GS v 0` — lệnh raster. Mất byte này thì logo không in ra.
	if !bytes.Contains(out, []byte{0x1D, 0x76, 0x30}) {
		t.Error("không có lệnh GS v 0")
	}
}

// Mặc định CĂN GIỮA, khác mọi khối chữ. Một logo lệch trái trên giấy 80mm trông
// như lỗi in — mặc định phải là thứ người thiết kế phiếu sẽ chọn.
func TestEmitLogo_AlignDefaultsToCenter(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	seedLogo(t, db, 576, 200, 2)

	for _, tc := range []struct {
		align string
		want  []byte
	}{
		{"", escpos.AlignCenter},
		{"center", escpos.AlignCenter},
		{"left", escpos.AlignLeft},
		{"right", escpos.AlignRight},
	} {
		out := emittedLogo(t, NewPrintImageStore(db), "", &PrintTemplateBlock{
			ID: "logo", Source: "brand_logo", MaxWidthDots: 576, Align: tc.align,
		})
		if !bytes.HasPrefix(out, tc.want) {
			t.Errorf("align=%q: mở bằng %x, muốn %x", tc.align, out[:min(4, len(out))], tc.want)
		}
	}
}

// Không khai bề rộng = "to hết mức giấy cho phép" (576), không phải bề rộng tình
// cờ của tệp được tải lên — nếu không thì đổi ảnh sẽ làm đổi bố cục.
func TestEmitLogo_DefaultWidthIs576(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	seedLogo(t, db, 576, 200, 2)

	out := emittedLogo(t, NewPrintImageStore(db), "", &PrintTemplateBlock{ID: "logo", Source: "brand_logo"})
	if len(out) == 0 {
		t.Error("bề rộng mặc định không rơi vào 576")
	}

	// Và một ảnh CHỈ có ở 384 không được dùng nhầm cho một khối khai 576.
	db2 := newPrintTemplateTestDB(t)
	seedLogo(t, db2, 384, 384, 2)
	out2 := emittedLogo(t, NewPrintImageStore(db2), "", &PrintTemplateBlock{
		ID: "logo", Source: "brand_logo", MaxWidthDots: 576,
	})
	if len(out2) != 0 {
		t.Error("dùng ảnh ở bề rộng khác — sẽ in ra logo sai kích thước mà không báo gì")
	}
}

// Mọi kind đều phải có emitter, đúng như phía PHP. Thiếu một kind nghĩa là phiếu
// đó lặng lẽ mất khối logo — chính con bug #1949.
func TestEmitLogo_RegisteredForEveryKind(t *testing.T) {
	var missing []string
	for kind, plan := range printKindPlans {
		if plan.emitters["logo"] == nil {
			missing = append(missing, kind)
		}
	}
	if len(missing) > 0 {
		t.Errorf("kind thiếu emitter logo: %v", missing)
	}
}

// seedLogo gieo một bitmap hợp lệ cho `brand_logo` ở một bề rộng.
//
// Hash phải ĐÚNG: `Lookup` tự băm lại byte trước khi trả về, nên một fixture đặt
// hash bừa sẽ bị chính lớp phòng vệ đó loại — và test sẽ xanh vì lý do sai.
func seedLogo(t *testing.T, db *store.DB, maxWidthDots, widthDots, rows int) {
	t.Helper()

	bytesPerRow := (widthDots + 7) / 8
	raw := bytes.Repeat([]byte{0xF0}, bytesPerRow*rows)
	sum := sha256.Sum256(raw)
	hash := hex.EncodeToString(sum[:])

	if _, err := db.Exec(`INSERT INTO print_image_blobs
		(content_hash, width_dots, height_dots, byte_length, data, fetched_at)
		VALUES (?, ?, ?, ?, ?, ?)`,
		hash, widthDots, rows, len(raw), raw, "2026-08-07T00:00:00Z"); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`INSERT INTO print_image_current
		(source, max_width_dots, content_hash, version, effective_from, cloud_updated_at, fetched_at)
		VALUES (?, ?, ?, 1, NULL, NULL, ?)`,
		"brand_logo", maxWidthDots, hash, "2026-08-07T00:00:00Z"); err != nil {
		t.Fatal(err)
	}
}
