package service

import (
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// #1957 mảnh B — cache ảnh in ở máy trạm.
//
// Điều được canh ở đây KHÔNG phải "tải được ảnh" mà là **một vấn đề về ảnh không
// bao giờ chặn một lần bán hàng** (TR-05), cộng với **byte hỏng không bao giờ
// thay được byte tốt** (cùng luật TR-24 của print_templates).

func imageBytes(fill byte, n int) []byte {
	b := make([]byte, n)
	for i := range b {
		b[i] = fill
	}

	return b
}

func hashOf(b []byte) string {
	sum := sha256.Sum256(b)

	return hex.EncodeToString(sum[:])
}

// imageServer phục vụ danh mục một-ảnh cùng byte của nó. `blobOverride` khác nil
// thì endpoint byte trả nội dung đó thay vì nội dung thật — dùng để dựng trường
// hợp tải về không khớp hash.
func imageServer(t *testing.T, raw []byte, effectiveFrom string, blobOverride []byte, hits *int) *httptest.Server {
	t.Helper()
	hash := hashOf(raw)

	eff := "null"
	if effectiveFrom != "" {
		eff = `"` + effectiveFrom + `"`
	}

	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")

		if strings.HasSuffix(r.URL.Path, "/print-images") {
			fmt.Fprintf(w, `{"data":[{"source":"brand_logo","scope":"brand","version":3,
				"effective_from":%s,"updated_at":"2026-08-06T00:00:00Z",
				"variants":[{"max_width_dots":576,"width_dots":200,"height_dots":40,
				"content_hash":%q,"byte_length":%d}]}],"generated_at":"2026-08-06T00:00:00Z"}`,
				eff, hash, len(raw))

			return
		}

		if hits != nil {
			*hits++
		}
		body := raw
		if blobOverride != nil {
			body = blobOverride
		}
		fmt.Fprintf(w, `{"data":{"content_hash":%q,"max_width_dots":576,"width_dots":200,
			"height_dots":40,"byte_length":%d,"data":%q}}`,
			hash, len(body), base64.StdEncoding.EncodeToString(body))
	}))
}

func TestPrintImageCache_PullStoresBlobAndPointer(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	raw := imageBytes(0xF0, 1000)

	srv := imageServer(t, raw, "", nil, nil)
	defer srv.Close()

	p := NewSyncPuller(db, srv.URL, func() string { return "device-token" })
	if err := p.PullPrintImages(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	img, err := NewPrintImageStore(db).Lookup("brand_logo", 576, "2026-08-06 12:00:00")
	if err != nil {
		t.Fatalf("lookup: %v", err)
	}
	if img == nil {
		t.Fatal("không tra được ảnh vừa kéo về")
	}
	if got := hashOf(img.Data); got != hashOf(raw) {
		t.Errorf("byte lệch: %s != %s", got, hashOf(raw))
	}
	if img.WidthDots != 200 || img.HeightDots != 40 {
		t.Errorf("kích thước sai: %dx%d", img.WidthDots, img.HeightDots)
	}
}

// Byte bất biến theo hash, nên lượt kéo thứ hai KHÔNG được tải lại. Đây là điều
// khiến việc đổi logo tốn đúng một lượt tải chứ không phải một lượt mỗi tick —
// và đó là toàn bộ lý do danh mục và byte được tách làm hai endpoint.
func TestPrintImageCache_SecondPullDoesNotRefetchBytes(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	raw := imageBytes(0x0F, 512)
	hits := 0

	srv := imageServer(t, raw, "", nil, &hits)
	defer srv.Close()

	p := NewSyncPuller(db, srv.URL, func() string { return "device-token" })
	for i := 0; i < 3; i++ {
		if err := p.PullPrintImages(context.Background()); err != nil {
			t.Fatalf("pull %d: %v", i, err)
		}
	}

	if hits != 1 {
		t.Errorf("tải byte %d lần, chỉ được phép 1 — hash đã có là câu trả lời cuối cùng", hits)
	}
}

// TR-24 cho ảnh: một lượt tải đứt gánh KHÔNG được lưu. Hash cũng chính là KHOÁ,
// nên tin nó mà không kiểm nghĩa là để một dãy byte cụt sống dưới tên của dãy
// byte đúng, vĩnh viễn.
func TestPrintImageCache_HashMismatchIsRejected(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	raw := imageBytes(0xAA, 800)

	srv := imageServer(t, raw, "", imageBytes(0xAA, 400), nil) // cụt một nửa
	defer srv.Close()

	p := NewSyncPuller(db, srv.URL, func() string { return "device-token" })
	// Lượt pull KHÔNG hỏng — một biến thể hỏng không được làm hỏng cả tick.
	if err := p.PullPrintImages(context.Background()); err != nil {
		t.Fatalf("một biến thể hỏng không được làm hỏng cả lượt pull: %v", err)
	}

	var blobs int
	if err := db.QueryRow(`SELECT COUNT(*) FROM print_image_blobs`).Scan(&blobs); err != nil {
		t.Fatal(err)
	}
	if blobs != 0 {
		t.Errorf("byte không khớp hash vẫn được lưu: %d hàng", blobs)
	}

	img, _ := NewPrintImageStore(db).Lookup("brand_logo", 576, "")
	if img != nil {
		t.Error("tra ra ảnh từ một lượt tải chưa xác minh")
	}
}

// TR-05 — không có gì trong cache là trạng thái HỢP LỆ, không phải lỗi. Một lỗi
// ở đây sẽ leo lên thành "không in được phiếu", tức lấy doanh thu của quán đổi
// lấy một cái logo.
func TestPrintImageCache_EmptyCacheReturnsNilNotError(t *testing.T) {
	db := newPrintTemplateTestDB(t)

	img, err := NewPrintImageStore(db).Lookup("brand_logo", 576, "2026-08-06 12:00:00")
	if err != nil {
		t.Fatalf("cache rỗng phải là nil,nil — nhận err: %v", err)
	}
	if img != nil {
		t.Error("cache rỗng mà tra ra ảnh")
	}
}

// effective_from là giờ treo tường của CHI NHÁNH (#1091), so bằng so chuỗi.
func TestPrintImageCache_EffectiveFromIsBranchWallClock(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	raw := imageBytes(0x33, 256)

	srv := imageServer(t, raw, "2026-09-01 00:00:00", nil, nil)
	defer srv.Close()

	p := NewSyncPuller(db, srv.URL, func() string { return "device-token" })
	if err := p.PullPrintImages(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	s := NewPrintImageStore(db)

	if img, _ := s.Lookup("brand_logo", 576, "2026-08-31 23:59:59"); img != nil {
		t.Error("ảnh có hiệu lực TRƯỚC mốc đã khai")
	}
	if img, _ := s.Lookup("brand_logo", 576, "2026-09-01 00:00:00"); img == nil {
		t.Error("ảnh không có hiệu lực đúng mốc đã khai")
	}

	// Chuỗi rỗng = chưa biết giờ chi nhánh. Bỏ qua effective_from thay vì đoán:
	// đoán sai sẽ lật logo lệch đúng bằng độ lệch múi giờ, cả một ngày in sai.
	if img, _ := s.Lookup("brand_logo", 576, ""); img == nil {
		t.Error("chưa biết giờ chi nhánh thì phải bỏ qua effective_from, không được nuốt ảnh")
	}
}

// Byte trên đĩa hỏng (đĩa lỗi, ghi dở, một lượt tải cũ lọt qua trước khi có kiểm
// tra) — thà không có logo còn hơn in ra một dải mực đen.
func TestPrintImageCache_CorruptBytesOnDiskAreIgnored(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	raw := imageBytes(0x77, 300)
	hash := hashOf(raw)

	if _, err := db.Exec(`INSERT INTO print_image_blobs
		(content_hash, width_dots, height_dots, byte_length, data, fetched_at)
		VALUES (?, ?, ?, ?, ?, ?)`,
		hash, 200, 40, len(raw), imageBytes(0x00, 300), "2026-08-06T00:00:00Z"); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`INSERT INTO print_image_current
		(source, max_width_dots, content_hash, version, effective_from, cloud_updated_at, fetched_at)
		VALUES (?, ?, ?, ?, NULL, NULL, ?)`,
		"brand_logo", 576, hash, 1, "2026-08-06T00:00:00Z"); err != nil {
		t.Fatal(err)
	}

	img, err := NewPrintImageStore(db).Lookup("brand_logo", 576, "")
	if err != nil {
		t.Fatalf("byte hỏng phải là nil,nil — nhận err: %v", err)
	}
	if img != nil {
		t.Error("trả về byte không khớp hash của chính nó")
	}
}

// Cloud chết: cache trên đĩa CHÍNH LÀ câu trả lời. Không ghi gì, không xoá gì.
func TestPrintImageCache_CloudDownKeepsCache(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	raw := imageBytes(0x5A, 600)

	srv := imageServer(t, raw, "", nil, nil)
	p := NewSyncPuller(db, srv.URL, func() string { return "device-token" })
	if err := p.PullPrintImages(context.Background()); err != nil {
		t.Fatalf("pull đầu: %v", err)
	}
	srv.Close()

	if err := p.PullPrintImages(context.Background()); err == nil {
		t.Error("Cloud chết mà pull báo thành công")
	}

	if img, _ := NewPrintImageStore(db).Lookup("brand_logo", 576, ""); img == nil {
		t.Error("Cloud chết làm mất cache đang có")
	}
}

// Đổi logo: con trỏ chuyển sang hash mới, byte CŨ vẫn nằm lại. Đó là điều khiến
// hai bảng tách nhau — gộp lại thì mỗi lần đổi logo ghi đè byte cũ.
func TestPrintImageCache_NewLogoKeepsOldBytes(t *testing.T) {
	db := newPrintTemplateTestDB(t)

	old := imageBytes(0x11, 200)
	srv1 := imageServer(t, old, "", nil, nil)
	p := NewSyncPuller(db, srv1.URL, func() string { return "device-token" })
	if err := p.PullPrintImages(context.Background()); err != nil {
		t.Fatalf("pull cũ: %v", err)
	}
	srv1.Close()

	fresh := imageBytes(0x22, 200)
	srv2 := imageServer(t, fresh, "", nil, nil)
	defer srv2.Close()
	p2 := NewSyncPuller(db, srv2.URL, func() string { return "device-token" })
	if err := p2.PullPrintImages(context.Background()); err != nil {
		t.Fatalf("pull mới: %v", err)
	}

	img, _ := NewPrintImageStore(db).Lookup("brand_logo", 576, "")
	if img == nil || hashOf(img.Data) != hashOf(fresh) {
		t.Error("con trỏ không chuyển sang logo mới")
	}

	var blobs int
	if err := db.QueryRow(`SELECT COUNT(*) FROM print_image_blobs`).Scan(&blobs); err != nil {
		t.Fatal(err)
	}
	if blobs != 2 {
		t.Errorf("byte cũ bị mất: còn %d blob, muốn 2", blobs)
	}
}
