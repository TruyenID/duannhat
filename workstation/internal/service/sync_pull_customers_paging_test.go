package service

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"sync"
	"testing"
	"time"
)

// #3200 — `PullCustomers` phải đi HẾT trang.
//
// Hình dạng nguy hiểm hơn `Recover()` (#3196), không nhẹ hơn: con trỏ được đẩy
// tới `max(updated_at)` của trang vừa nhận, nên nếu lượt đầu chạm trần 1000 thì
// phần khách cũ hơn KHÔNG BAO GIỜ được kéo về — không nhịp nào sau đó quay lại.
// `Recover()` chỉ sai ở một lượt khôi phục; chỗ này để lại hố vĩnh viễn.
//
// Cloud giả ở đây mô phỏng đúng `CustomerReplicaController`:
//
//	orderBy('updated_at')            → ASC (điều kiện đủ để con trỏ tiến)
//	where('updated_at', '>', $since) → NGHIÊM NGẶT, không trả lại hàng biên
//	->limit(1000)
//
// Bộ lọc `>` là chỗ riêng của feed này: không có hàng trùng giữa hai trang,
// nhưng một trang ĐẦY mà mọi hàng cùng một giây thì con trỏ không nhích được.
type customersFake struct {
	total     int
	pageLimit int
	// sameSecond: mọi hàng dùng chung một dấu thời gian — ca làm con trỏ kẹt.
	sameSecond bool

	mu    sync.Mutex
	calls int
}

func (f *customersFake) Calls() int {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.calls
}

func (f *customersFake) start(t *testing.T) *httptest.Server {
	t.Helper()

	base := time.Date(2026, 8, 1, 0, 0, 0, 0, time.UTC)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		f.calls++
		f.mu.Unlock()

		var cursor time.Time
		if v := r.URL.Query().Get("updated_since"); v != "" {
			cursor, _ = time.Parse(time.RFC3339, v)
		}

		out := make([]map[string]any, 0, f.pageLimit)
		for i := 0; i < f.total; i++ {
			stamp := base.Add(time.Duration(i) * time.Minute)
			if f.sameSecond {
				stamp = base
			}
			// `>` nghiêm ngặt, đúng như controller.
			if !cursor.IsZero() && !stamp.After(cursor) {
				continue
			}
			out = append(out, map[string]any{
				"id": fmt.Sprintf("cus-%05d", i), "first_name": "A", "last_name": "B",
				"full_name": "A B", "phone": "", "email": "",
				"organization_id": "o", "brand_id": "b", "branch_id": "br",
				"updated_at": stamp.Format(time.RFC3339),
			})
			if len(out) >= f.pageLimit {
				break
			}
		}

		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"data":         out,
			"generated_at": base.Add(1000 * time.Hour).Format(time.RFC3339),
		})
	}))
	t.Cleanup(srv.Close)

	return srv
}

func storedCustomers(t *testing.T, p *SyncPuller) int {
	t.Helper()
	var n int
	if err := p.db.QueryRow("SELECT COUNT(*) FROM customers").Scan(&n); err != nil {
		t.Fatalf("đếm customers: %v", err)
	}
	return n
}

// KÊU: quán vượt trần một trang ⇒ vẫn kéo về ĐỦ.
func TestPullCustomers_WalksPastTheServerCap(t *testing.T) {
	// Dùng ĐÚNG trần thật (`customersCloudLimit`), không phải một số nhỏ cho
	// nhanh. Bản đầu của bài này đặt `pageLimit: 3` và đỏ oan: phép kiểm "trang
	// đã đầy chưa" của bản vá so với hằng số 1000, nên một trang 3 dòng không
	// bao giờ chạm đường đang cần đo. Một bài test đặt trần riêng của nó sẽ đo
	// một thế giới không tồn tại.
	fake := &customersFake{total: customersCloudLimit + 500, pageLimit: customersCloudLimit}
	cloud := fake.start(t)

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	if err := p.PullCustomers(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	// Bản trước dừng ở trang đầu ⇒ 3/7, và phần còn lại không nhịp nào lấy lại
	// vì con trỏ đã nhảy qua.
	want := customersCloudLimit + 500
	if got := storedCustomers(t, p); got != want {
		t.Errorf("kéo về %d/%d khách — con trỏ đã nhảy qua phần còn lại, "+
			"không nhịp nào sau đó lấy lại được", got, want)
	}
}

// IM: quán nhỏ ⇒ vẫn đúng MỘT request. Vòng lặp không được biến mọi nhịp đồng
// bộ thành một chuỗi round-trip thừa.
func TestPullCustomers_SmallShopStillOneRequest(t *testing.T) {
	fake := &customersFake{total: 5, pageLimit: 1000}
	cloud := fake.start(t)

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	if err := p.PullCustomers(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}
	if fake.Calls() != 1 {
		t.Errorf("quán 5 khách tốn %d request, phải đúng 1 — trang chưa đầy nghĩa là Cloud đã đưa hết",
			fake.Calls())
	}
}

// Trang ĐẦY mà mọi hàng cùng một giây ⇒ dừng, KHÔNG nhích bừa qua giây đó.
//
// Bộ lọc `>` nghiêm ngặt nghĩa là nhích qua sẽ BỎ SÓT phần còn lại của giây ấy.
// Chọn dừng-và-kêu thay vì đoán, cùng cách `PullCustomerOrders` xử lý con trỏ kẹt.
func TestPullCustomers_StopsInsteadOfSkippingATiedSecond(t *testing.T) {
	fake := &customersFake{total: customersCloudLimit + 50, pageLimit: customersCloudLimit, sameSecond: true}
	cloud := fake.start(t)

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))

	done := make(chan error, 1)
	go func() { done <- p.PullCustomers(context.Background()) }()

	select {
	case err := <-done:
		if err != nil {
			t.Fatalf("pull: %v", err)
		}
	case <-time.After(20 * time.Second):
		t.Fatal("PullCustomers không dừng khi con trỏ không nhích được")
	}

	if fake.Calls() > 3 {
		t.Errorf("gọi %d lần cho một giây trùng — phải dừng ngay, không quay vòng", fake.Calls())
	}
}
