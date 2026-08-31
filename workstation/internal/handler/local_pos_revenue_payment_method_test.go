package handler

import (
	"net/http/httptest"
	"testing"
	"time"
)

// #2665 — `revenueByPaymentMethod` lọc theo ba trạng thái KHÔNG TỒN TẠI
// (`paid` · `completed` · `success`). Từ vựng thật của `payments` phía máy trạm
// là pending · confirmed · succeeded · refunded · failed, nên truy vấn chưa bao
// giờ khớp một hàng nào và panel "doanh thu theo phương thức" chưa bao giờ hiện
// (pos-web `reports/revenue/page.tsx:3200` rơi sang EmptyState khi mảng rỗng).
//
// Test dựng DỮ LIỆU THẬT rồi đọc con số, không chỉ khẳng định truy vấn chạy —
// một truy vấn lọc sai vẫn "chạy được", nó chỉ trả rỗng.

func revenueSeed(t *testing.T, s *Server) {
	t.Helper()
	exec := func(q string, args ...any) {
		t.Helper()
		if _, err := s.db.Exec(q, args...); err != nil {
			t.Fatalf("seed: %v", err)
		}
	}

	ins := func(id, method, status string, amount, refunded int, refundOf any) {
		exec(`INSERT INTO payments
			(id, order_id, payment_method, status, amount, refunded_amount, refund_of_id, created_at)
			VALUES (?,?,?,?,?,?,?, '2026-08-13T03:00:00Z')`,
			id, "ord-1", method, status, amount, refunded, refundOf)
	}

	// Tiền thật, đủ các trạng thái mà từ vựng cho phép.
	ins("p-cash", "cash", "succeeded", 3000, 0, nil)
	ins("p-card", "credit", "confirmed", 2000, 0, nil)
	ins("p-qr", "paypay", "pending", 1000, 0, nil)

	// Hoàn tiền kiểu CỤC BỘ: hàng gốc mang `refunded_amount`, status `refunded`.
	ins("p-loc", "cash", "refunded", 5000, 5000, nil)

	// Hoàn tiền kiểu CLOUD: hàng ký hiệu ÂM riêng (#2656). Phải được TÍNH VÀO —
	// amount âm nên tổng tự net; loại nó ra là báo doanh thu gộp.
	ins("p-orig", "credit", "succeeded", 4000, 0, nil)
	ins("p-neg", "credit", "succeeded", -1500, 0, "p-orig")

	// KHÔNG phải tiền: failed, và một hàng refund chưa succeeded.
	ins("p-fail", "cash", "failed", 9999, 0, nil)
	ins("p-negpend", "credit", "pending", -7777, 0, "p-orig")
}

func revenueByMethod(t *testing.T, s *Server) map[string]int64 {
	t.Helper()
	from := time.Date(2026, 8, 13, 0, 0, 0, 0, time.UTC)
	to := time.Date(2026, 8, 13, 23, 59, 59, 0, time.UTC)

	got, err := s.revenueByPaymentMethod(httptest.NewRequest("GET", "/x", nil), from, to)
	if err != nil {
		t.Fatalf("revenueByPaymentMethod: %v", err)
	}
	out := map[string]int64{}
	for _, r := range got {
		code := ""
		if r.Code != nil {
			code = *r.Code
		}
		out[code] += r.Amount
	}
	return out
}

func TestRevenueByPaymentMethod_CountsRealVocabularyAndNetsRefunds(t *testing.T) {
	s := newLANPrintTestServer(t)
	revenueSeed(t, s)

	got := revenueByMethod(t, s)

	// Panel phải có dữ liệu — đây là chính cái chưa bao giờ xảy ra.
	if len(got) == 0 {
		t.Fatalf("không có phương thức nào — panel vẫn rỗng, bản vá chưa có tác dụng")
	}

	// cash: 3000 (succeeded) + (5000 − 5000 hoàn cục bộ) = 3000
	if got["cash"] != 3000 {
		t.Errorf("cash = %d, muốn 3000 (3000 + hàng hoàn cục bộ net 0)", got["cash"])
	}
	// credit: 2000 (confirmed) + 4000 (gốc) + (−1500 hàng hoàn Cloud) = 4500
	if got["credit"] != 4500 {
		t.Errorf("credit = %d, muốn 4500 (2000 + 4000 − 1500)", got["credit"])
	}
	// paypay: 1000 (pending vẫn là tiền đã nhận theo vị ngữ dùng chung)
	if got["paypay"] != 1000 {
		t.Errorf("paypay = %d, muốn 1000", got["paypay"])
	}
}

// Chiều PHẢI IM: hàng không-phải-tiền không được lọt vào.
//
// Nếu bản vá nới lỏng bộ lọc thành "mọi hàng" thì cash sẽ nhảy lên 12999
// (dính `failed` 9999) và credit tụt vì dính hàng refund `pending` −7777.
func TestRevenueByPaymentMethod_ExcludesFailedAndUnsettledRefundRows(t *testing.T) {
	s := newLANPrintTestServer(t)
	revenueSeed(t, s)

	got := revenueByMethod(t, s)

	if got["cash"] == 12999 || got["cash"] > 3000 {
		t.Errorf("cash = %d — hàng `failed` (9999) đã lọt vào doanh thu", got["cash"])
	}
	if got["credit"] < 4500 {
		t.Errorf("credit = %d — hàng refund chưa `succeeded` (−7777) đã bị trừ oan", got["credit"])
	}
}

// Từ vựng cũ phải THỰC SỰ không tồn tại — nếu một ngày nào đó có ai ghi
// `status='paid'` vào bảng này thì giả định của bản vá sai, và test phải nói ra
// thay vì để bộ lọc mới im lặng bỏ sót hàng đó.
func TestPaymentsTableNeverUsesTheThreeGhostStatuses(t *testing.T) {
	s := newLANPrintTestServer(t)
	revenueSeed(t, s)

	var n int
	if err := s.db.QueryRow(
		`SELECT COUNT(*) FROM payments WHERE status IN ('paid','completed','success')`,
	).Scan(&n); err != nil {
		t.Fatalf("scan: %v", err)
	}
	if n != 0 {
		t.Fatalf("có %d hàng mang trạng thái ma — từ vựng đã đổi, đọc lại #2665", n)
	}
}

// #2665 — việc tách `paidPaymentsStatusPredicate` ra khỏi `paidPaymentsPredicate`
// phải là REFACTOR THUẦN: chuỗi SQL mà Z-report / settlement snapshot /
// reconcileSession dùng không được đổi một ký tự.
//
// Ghim bằng chuỗi golden chứ không bằng "test khác vẫn xanh": vị ngữ này quyết
// định tiền nào vào phiếu 精算, nên "chắc là không đổi" không đủ.
//
// #2736 — golden ĐÃ ĐỔI MỘT LẦN, CÓ CHỦ ĐÍCH, và đây là chỗ ghi lại lý do.
// Nửa cửa sổ nay so trên `replace(created_at,' ','T')` thay vì cột thô: bound đã
// normalize sẵn, nên một hàng lưu dạng DATETIME có dấu cách thua ở ký tự 11 và
// **rơi khỏi tổng ngăn kéo** — vị ngữ này quyết định tiền nào vào phiếu 精算.
// Rào đã kêu đúng lúc bản sửa chạm vào nó; cập nhật golden là kết luận sau khi
// xác nhận thay đổi là cố ý, KHÔNG phải cách làm nó im.
// Nửa TRẠNG THÁI vẫn bất biến — đó mới là thứ #2665 ghim, và assert tiền tố
// bên dưới vẫn canh nguyên vẹn.
func TestPaidPaymentsPredicate_UnchangedByTheExtraction(t *testing.T) {
	const want = `((p.refund_of_id IS NULL AND p.status IN ('pending','confirmed','succeeded','refunded')) OR ` +
		`(p.refund_of_id IS NOT NULL AND p.status = 'succeeded')) AND ` +
		`(p.till_session_id = ? OR ((p.till_session_id IS NULL OR p.till_session_id = '') ` +
		`AND substr(replace(p.created_at,' ','T'),1,19) >= ? ` +
		`AND substr(replace(p.created_at,' ','T'),1,19) <= ?))`

	if got := paidPaymentsPredicate("p"); got != want {
		t.Fatalf("vị ngữ Z-report đã ĐỔI — tách nửa trạng thái không còn là refactor thuần.\ngot:  %s\nwant: %s", got, want)
	}

	// Và nửa trạng thái phải là TIỀN TỐ đúng nghĩa của chuỗi đầy đủ.
	if half := paidPaymentsStatusPredicate("p"); len(half) == 0 || want[:len(half)] != half {
		t.Fatalf("nửa trạng thái không phải tiền tố của vị ngữ đầy đủ:\nhalf: %s", half)
	}
}
