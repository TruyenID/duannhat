package service

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

/*
#3196 — `Recover()` lấy đúng 500 đơn rồi dừng.

Đường này chạy sau khi pair lại hoặc crash, tức đúng lúc máy KHÔNG còn state
cục bộ và phụ thuộc hoàn toàn vào lượt kéo. Hàm chỉ trả về số dòng nó nhận
được, nên một lượt khôi phục THIẾU đọc lên y hệt một lượt khôi phục ĐỦ.

Đo 2026-08-18 trên production: 本郷 391 đơn/30 ngày, 人形町 **421** — 84% của
trần — và `tổng = 30 ngày` ở cả hai quán, nên tốc độ ~400 đơn/tháng. Chạm 500
trong khoảng một tuần. Bộ test này ra đời TRƯỚC khi nó nổ, không phải sau.

Cùng lớp lỗi #3159, chỉ khác feed.
*/

const recoverWindow = 30 * 24 * time.Hour

// fakeOrder trả về một dòng đơn tối thiểu mà `Recover` parse được.
func fakeOrder(id string) string {
	return fmt.Sprintf(`{"id":%q,"order_code":%q,"status":"closed","order_type":"dine_in",
		"total_amount":"1000.00","subtotal":"1000.00","tax_amount":"0.00",
		"created_at":"2026-08-01T00:00:00Z","updated_at":"2026-08-01T00:00:00Z"}`, id, id)
}

// pagedCloud dựng một Cloud giả phát `total` đơn theo trang `pageSize`, có
// `has_more` đúng như controller sau #3196.
func pagedCloud(t *testing.T, total, pageSize int, calls *[]string) *httptest.Server {
	t.Helper()

	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		q := r.URL.Query()
		*calls = append(*calls, q.Get("offset"))

		offset := 0
		fmt.Sscanf(q.Get("offset"), "%d", &offset)

		var rows []string
		for i := offset; i < offset+pageSize && i < total; i++ {
			rows = append(rows, fakeOrder(fmt.Sprintf("o-%04d", i)))
		}
		hasMore := offset+pageSize < total

		w.Header().Set("Content-Type", "application/json")
		fmt.Fprintf(w, `{"data":[%s],"count":%d,"has_more":%t}`,
			strings.Join(rows, ","), len(rows), hasMore)
	}))
}

func TestRecoverDrainsEveryPage(t *testing.T) {
	// 1 200 đơn — vượt trần 500 cũ. Trước bản vá, hàm này trả về 500 và con số
	// đó đọc như thành công.
	var calls []string
	cloud := pagedCloud(t, 1200, recoverPageSize, &calls)
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))

	n, err := p.Recover(context.Background(), recoverWindow)
	if err != nil {
		t.Fatalf("Recover: %v", err)
	}
	if n != 1200 {
		t.Fatalf("khôi phục %d đơn, mong đợi 1200 — lượt kéo bị cắt mà không nói", n)
	}
	if len(calls) != 3 {
		t.Fatalf("gọi %d lượt (%v), mong đợi 3 trang", len(calls), calls)
	}
	if calls[0] != "0" || calls[1] != "500" || calls[2] != "1000" {
		t.Errorf("offset không tiến đúng: %v", calls)
	}

	var stored int
	_ = db.QueryRow(`SELECT COUNT(*) FROM orders`).Scan(&stored)
	if stored != 1200 {
		t.Errorf("SQLite giữ %d đơn, mong đợi 1200", stored)
	}
}

func TestRecoverStopsAfterOnePageWhenNoMore(t *testing.T) {
	// Quán nhỏ vẫn phải tốn ĐÚNG một request. Bản vá không được biến mọi lượt
	// khôi phục thành nhiều vòng gọi thừa.
	var calls []string
	cloud := pagedCloud(t, 120, recoverPageSize, &calls)
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))

	n, err := p.Recover(context.Background(), recoverWindow)
	if err != nil {
		t.Fatalf("Recover: %v", err)
	}
	if n != 120 {
		t.Fatalf("khôi phục %d, mong đợi 120", n)
	}
	if len(calls) != 1 {
		t.Fatalf("gọi %d lượt (%v), mong đợi ĐÚNG 1", len(calls), calls)
	}
}

func TestRecoverTolerAtesCloudWithoutHasMore(t *testing.T) {
	// Cloud CHƯA deploy bản #3196 không phát `has_more`; JSON thiếu khoá ⇒
	// false ⇒ dừng sau trang đầu, đúng hành vi cũ.
	//
	// Vế này là điều kiện để bản vá không đòi hai đầu deploy cùng lúc — và
	// luật của repo là backend đi TRƯỚC, nên trạng thái "máy trạm mới, Cloud
	// cũ" tồn tại thật trong cửa sổ giữa hai lượt deploy.
	var calls []string
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls = append(calls, r.URL.Query().Get("offset"))
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprintf(w, `{"data":[%s],"count":1}`, fakeOrder("o-legacy"))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))

	n, err := p.Recover(context.Background(), recoverWindow)
	if err != nil {
		t.Fatalf("Recover: %v", err)
	}
	if n != 1 {
		t.Fatalf("khôi phục %d, mong đợi 1", n)
	}
	if len(calls) != 1 {
		t.Fatalf("Cloud cũ mà gọi %d lượt — bản vá phải dừng khi thiếu `has_more`", len(calls))
	}
}

func TestRecoverHitsCeilingLoudly(t *testing.T) {
	// Một Cloud trả `has_more` MÃI MÃI. Trước hết: lượt đi phải HỮU HẠN. Sau
	// đó — và đây mới là phần đáng giá — nó phải KÊU, vì cắt có trần mà im
	// lặng chính là lỗi #3196 lùi xa hơn một bậc.
	var calls []string
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls = append(calls, r.URL.Query().Get("offset"))
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprintf(w, `{"data":[%s],"count":1,"has_more":true}`,
			fakeOrder(fmt.Sprintf("o-%d", len(calls))))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	p.SetAlerts(NewAlertEmitter(NewAlertStore(db), nil))

	if _, err := p.Recover(context.Background(), recoverWindow); err != nil {
		t.Fatalf("Recover: %v", err)
	}

	if len(calls) != recoverMaxPages {
		t.Fatalf("gọi %d lượt, trần là %d — lượt đi không hữu hạn", len(calls), recoverMaxPages)
	}

	var n int
	_ = db.QueryRow(`SELECT COUNT(*) FROM alerts WHERE kind = ?`, string(KindSyncStalled)).Scan(&n)
	if n == 0 {
		t.Error("chạm trần mà KHÔNG có alert — một lượt khôi phục thiếu trong im lặng đọc y hệt một lượt đủ")
	}
}

// TestRecoverStopsWhenCloudIgnoresOffset — lỗ do một phiên Claude khác chỉ ra
// khi soát bản vá #3196, và nó CÓ THẬT.
//
// Cloud giả trong các bài trên LUÔN tôn trọng `offset`, nên chúng không chứng
// minh được gì về một đầu kia LỜ nó. Mà chuyện đó xảy ra được thật: proxy nuốt
// query param, `validate()` strip vì thiếu rule (#2622), hoặc đơn giản là Cloud
// chưa cài bản này.
//
// Đo trên bản vá đầu (đã merge ở #3197): Cloud trả cùng 2 dòng cho mọi trang
// ⇒ `Recover` trả về **n = 40** trong khi SQLite chỉ có **2** đơn. Con số đó
// đọc y hệt một lượt khôi phục thành công — đúng lớp lỗi mà #3196 sinh ra để
// chặn, chỉ lùi thêm một bậc.
//
// Đây cũng là bài học của chính tôi: #3159 phía TypeScript đã giải đúng bài
// này bằng `barrenPages`, và tôi không mang sang Go.
func TestRecoverStopsWhenCloudIgnoresOffset(t *testing.T) {
	var calls int
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		calls++
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprintf(w, `{"data":[%s,%s],"count":2,"has_more":true}`,
			fakeOrder("o-1"), fakeOrder("o-2"))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	p.SetAlerts(NewAlertEmitter(NewAlertStore(db), nil))

	n, err := p.Recover(context.Background(), recoverWindow)
	if err != nil {
		t.Fatalf("Recover: %v", err)
	}

	var stored int
	_ = db.QueryRow(`SELECT COUNT(*) FROM orders`).Scan(&stored)

	if n != stored {
		t.Errorf("Recover trả về %d nhưng SQLite chỉ có %d đơn — con số đọc như thành công", n, stored)
	}
	if n != 2 {
		t.Errorf("khôi phục %d, mong đợi 2 (đúng những gì trang đầu có)", n)
	}
	// Dừng sớm: trang 2 đã cho thấy đầu kia không phân trang. Không được nướng
	// hết 20 lượt tải cùng một payload.
	if calls > 2 {
		t.Errorf("gọi %d lượt — phải dừng ngay khi một trang không thêm được dòng nào", calls)
	}

	var alerts int
	_ = db.QueryRow(`SELECT COUNT(*) FROM alerts WHERE kind = ?`, string(KindSyncStalled)).Scan(&alerts)
	if alerts == 0 {
		t.Error("không có alert — một lượt khôi phục chỉ có trang đầu mà im lặng đọc y hệt một lượt đủ")
	}
}
