package service

import (
	"context"
	"encoding/base64"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// #1957 mảnh B — các đường HỎNG của cache ảnh.
//
// Mọi ca dưới đây là TR-05: "một vấn đề về ảnh không bao giờ được chặn một lần
// bán hàng". Chúng cũng là những đường ÍT được chạy nhất trước khi thật sự cần
// tới — một cache hỏng, một DB mất bảng, một lượt tải đứt gánh. Không test thì
// lần đầu chúng chạy là lúc quán đang bán và renderer đang hỏng.

// faultServer trả về danh mục một-ảnh, với `hash`/`blobBody` do người gọi quyết
// định để dựng từng kiểu hỏng.
func faultServer(hash, blobBody string, blobStatus int) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/print-images") {
			w.Header().Set("Content-Type", "application/json")
			fmt.Fprintf(w, `{"data":[{"source":"brand_logo","scope":"brand","version":1,
				"effective_from":null,"updated_at":null,
				"variants":[{"max_width_dots":576,"width_dots":8,"height_dots":1,
				"content_hash":%q,"byte_length":1}]}],"generated_at":"2026-08-07T00:00:00Z"}`, hash)

			return
		}
		if blobStatus != 200 {
			w.WriteHeader(blobStatus)

			return
		}
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprint(w, blobBody)
	}))
}

// Biến thể không có `content_hash` — không có địa chỉ thì không tải được, và
// không được kéo cả tick xuống theo.
func TestPrintImageFaults_EmptyHashSkipped(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	srv := faultServer("", "", 200)
	defer srv.Close()

	p := NewSyncPuller(db, srv.URL, func() string { return "tok" })
	if err := p.PullPrintImages(context.Background()); err != nil {
		t.Fatalf("một biến thể thiếu hash không được làm hỏng cả lượt pull: %v", err)
	}

	var n int
	_ = db.QueryRow(`SELECT COUNT(*) FROM print_image_blobs`).Scan(&n)
	if n != 0 {
		t.Errorf("lưu %d blob cho một biến thể không có hash", n)
	}
}

// Endpoint byte chết giữa chừng: bỏ qua biến thể, giữ nguyên cache đang có.
func TestPrintImageFaults_BlobFetchFails(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	srv := faultServer(hashOf(imageBytes(0x11, 8)), "", 500)
	defer srv.Close()

	p := NewSyncPuller(db, srv.URL, func() string { return "tok" })
	if err := p.PullPrintImages(context.Background()); err != nil {
		t.Fatalf("blob 500 không được làm hỏng cả lượt pull: %v", err)
	}

	var n int
	_ = db.QueryRow(`SELECT COUNT(*) FROM print_image_blobs`).Scan(&n)
	if n != 0 {
		t.Errorf("lưu %d blob từ một lượt tải thất bại", n)
	}
}

// Base64 hỏng (proxy cắt, ký tự lạ) — cùng luật: bỏ qua, không ném lên.
func TestPrintImageFaults_BadBase64(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	h := hashOf(imageBytes(0x22, 8))
	body := fmt.Sprintf(`{"data":{"content_hash":%q,"max_width_dots":576,"width_dots":8,
		"height_dots":1,"byte_length":8,"data":"!!!khong-phai-base64!!!"}}`, h)

	srv := faultServer(h, body, 200)
	defer srv.Close()

	p := NewSyncPuller(db, srv.URL, func() string { return "tok" })
	if err := p.PullPrintImages(context.Background()); err != nil {
		t.Fatalf("base64 hỏng không được làm hỏng cả lượt pull: %v", err)
	}

	var n int
	_ = db.QueryRow(`SELECT COUNT(*) FROM print_image_blobs`).Scan(&n)
	if n != 0 {
		t.Errorf("lưu %d blob từ base64 hỏng", n)
	}
}

// Mất bảng blob: lượt kiểm "đã có chưa" hỏng. Vẫn không được làm hỏng tick.
func TestPrintImageFaults_MissingBlobTable(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	if _, err := db.Exec(`DROP TABLE print_image_blobs`); err != nil {
		t.Fatal(err)
	}

	raw := imageBytes(0x33, 8)
	h := hashOf(raw)
	body := fmt.Sprintf(`{"data":{"content_hash":%q,"max_width_dots":576,"width_dots":8,
		"height_dots":1,"byte_length":8,"data":%q}}`, h, base64.StdEncoding.EncodeToString(raw))

	srv := faultServer(h, body, 200)
	defer srv.Close()

	p := NewSyncPuller(db, srv.URL, func() string { return "tok" })
	if err := p.PullPrintImages(context.Background()); err != nil {
		t.Fatalf("DB mất bảng không được làm hỏng cả lượt pull: %v", err)
	}
}

// Mất bảng con trỏ: blob lưu được nhưng upsert hỏng. Chỉ log, không ném.
func TestPrintImageFaults_MissingCurrentTable(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	if _, err := db.Exec(`DROP TABLE print_image_current`); err != nil {
		t.Fatal(err)
	}

	raw := imageBytes(0x44, 8)
	h := hashOf(raw)
	body := fmt.Sprintf(`{"data":{"content_hash":%q,"max_width_dots":576,"width_dots":8,
		"height_dots":1,"byte_length":8,"data":%q}}`, h, base64.StdEncoding.EncodeToString(raw))

	srv := faultServer(h, body, 200)
	defer srv.Close()

	p := NewSyncPuller(db, srv.URL, func() string { return "tok" })
	if err := p.PullPrintImages(context.Background()); err != nil {
		t.Fatalf("mất bảng con trỏ không được làm hỏng cả lượt pull: %v", err)
	}

	var n int
	if err := db.QueryRow(`SELECT COUNT(*) FROM print_image_blobs`).Scan(&n); err != nil {
		t.Fatal(err)
	}
	if n != 1 {
		t.Errorf("blob phải lưu được dù con trỏ hỏng, có %d", n)
	}
}

// Store chưa nối dây (máy chưa pair, bề mặt xem trước): `nil, nil`, không panic.
func TestPrintImageFaults_NilStoreLookup(t *testing.T) {
	for _, s := range []*PrintImageStore{nil, {db: nil}, NewPrintImageStore(nil)} {
		img, err := s.Lookup("brand_logo", 576, "")
		if err != nil || img != nil {
			t.Errorf("store rỗng phải trả nil,nil — nhận (%v, %v)", img, err)
		}
	}
}

// Cache mất bảng lúc TRA: log rồi in phiếu không có logo. Một lỗi trả lên đây sẽ
// leo thành "không in được phiếu", tức lấy doanh thu của quán đổi lấy một logo.
func TestPrintImageFaults_LookupOnBrokenCache(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	if _, err := db.Exec(`DROP TABLE print_image_current`); err != nil {
		t.Fatal(err)
	}

	img, err := NewPrintImageStore(db).Lookup("brand_logo", 576, "")
	if err != nil {
		t.Fatalf("cache hỏng phải là nil,nil — nhận err: %v", err)
	}
	if img != nil {
		t.Error("tra ra ảnh từ một cache đã mất bảng")
	}
}

// Context rỗng ở emitter: không panic, không in gì.
func TestPrintImageFaults_NilRenderCtx(t *testing.T) {
	emitStoreAbove(nil)
	emitStoreBelow(nil)
	emitStoreAbove(&printRenderCtx{})
	emitStoreBelow(&printRenderCtx{})
}
