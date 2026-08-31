package service

import (
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// #2162 (phần B của #2127) — dấu vết kiểm toán `order_money_overwrites`.
//
// Alert (#2127 A) cho người vận hành thấy LÚC Cloud ghi đè tiền, và đóng lại
// khi có người ack. Sau đó — alert đã đóng, log đã xoay vòng — vẫn phải đọc
// lại được TỪ CHÍNH ĐƠN HÀNG là nó đã từng lệch bao nhiêu. Đó là toàn bộ lý do
// phần B tồn tại, nên bài đầu tiên đo đúng vế ấy: dòng audit sống QUA cái ack.
//
// Dùng chung harness với bản A (`sync_money_overwrite_test.go`):
// newSyncTestEngine · seedMoneyOrder · wireAlerts · openAlertFor.

type moneyOverwriteRow struct {
	totalLocal, totalCloud       int
	subtotalLocal, subtotalCloud int
	taxLocal, taxCloud           int
	serviceLocal, serviceCloud   int
	discountLocal, discountCloud int
	paidLocally                  int
	createdAt                    string
}

func auditRowsFor(t *testing.T, db *store.DB, orderID string) []moneyOverwriteRow {
	t.Helper()

	rows, err := db.Query(`SELECT
			total_amount_local, total_amount_cloud,
			subtotal_local, subtotal_cloud,
			tax_amount_local, tax_amount_cloud,
			service_charge_local, service_charge_cloud,
			discount_amount_local, discount_amount_cloud,
			paid_locally, created_at
		FROM order_money_overwrites WHERE order_id = ? ORDER BY id`, orderID)
	if err != nil {
		t.Fatalf("read order_money_overwrites: %v", err)
	}
	defer rows.Close()

	var out []moneyOverwriteRow
	for rows.Next() {
		var r moneyOverwriteRow
		if err := rows.Scan(
			&r.totalLocal, &r.totalCloud,
			&r.subtotalLocal, &r.subtotalCloud,
			&r.taxLocal, &r.taxCloud,
			&r.serviceLocal, &r.serviceCloud,
			&r.discountLocal, &r.discountCloud,
			&r.paidLocally, &r.createdAt,
		); err != nil {
			t.Fatalf("scan: %v", err)
		}
		out = append(out, r)
	}
	if err := rows.Err(); err != nil {
		t.Fatalf("rows: %v", err)
	}

	return out
}

func TestOrderMoneyOverwriteAudit_RowReadableAfterAlertAck(t *testing.T) {
	e, db := newSyncTestEngine(t, "")
	seedMoneyOrder(t, e, 1100) // tiền ĐÃ vào két — khoảng lệch là 過不足 thật
	em := wireAlerts(t, e)

	// Cloud ghi đè: thuế 100 → 91, giảm giá 0 → 9 (tổng giữ nguyên — đúng chế
	// độ lệch mà cảnh báo cũ #2087 từng nuốt).
	e.reconcileOrderFromCloud("o1", map[string]any{
		"total_amount":    "1100.00",
		"subtotal":        "1000.00",
		"tax_amount":      "91.00",
		"service_charge":  "0.00",
		"discount_amount": "9.00",
	})

	got := auditRowsFor(t, db, "o1")
	if len(got) != 1 {
		t.Fatalf("có %d dòng audit, muốn 1 — Cloud ghi đè tiền mà sổ không giữ vết nào", len(got))
	}
	r := got[0]
	if r.taxLocal != 100 || r.taxCloud != 91 {
		t.Errorf("tax local/cloud = %d/%d, muốn 100/91", r.taxLocal, r.taxCloud)
	}
	if r.discountLocal != 0 || r.discountCloud != 9 {
		t.Errorf("discount local/cloud = %d/%d, muốn 0/9", r.discountLocal, r.discountCloud)
	}
	// Trường KHÔNG đổi cũng phải có mặt — dòng audit tự đứng được, không join
	// ngược về orders (bảng ấy đã bị ghi đè rồi).
	if r.totalLocal != 1100 || r.totalCloud != 1100 {
		t.Errorf("total local/cloud = %d/%d, muốn 1100/1100", r.totalLocal, r.totalCloud)
	}
	if r.subtotalLocal != 1000 || r.subtotalCloud != 1000 {
		t.Errorf("subtotal local/cloud = %d/%d, muốn 1000/1000", r.subtotalLocal, r.subtotalCloud)
	}
	if r.paidLocally != 1100 {
		t.Errorf("paid_locally = %d, muốn 1100 — snapshot lúc ghi đè quyết định đây là 過不足 thật hay phiếu sắp in sai", r.paidLocally)
	}
	if r.createdAt == "" {
		t.Error("created_at rỗng — không xếp được dòng nào xảy ra trước dòng nào")
	}

	// Người vận hành ack alert — alert ĐÓNG, và đây chính là khoảnh khắc mà
	// trước #2162 mọi dấu vết biến mất.
	a, ok := openAlertFor(t, em, KindCloudMoneyOverwrite, "o1")
	if !ok {
		t.Fatal("không có alert để ack — harness đo nhầm thứ khác")
	}
	if err := em.Ack(a.ID, "manager"); err != nil {
		t.Fatalf("Ack: %v", err)
	}
	if _, stillOpen := openAlertFor(t, em, KindCloudMoneyOverwrite, "o1"); stillOpen {
		t.Fatal("alert vẫn mở sau ack — test chưa tái lập được đúng tình huống của phần B")
	}

	after := auditRowsFor(t, db, "o1")
	if len(after) != 1 {
		t.Fatalf("sau ack còn %d dòng audit, muốn 1 — alert đóng lại thì dấu vết PHẢI ở lại", len(after))
	}
	if after[0] != r {
		t.Errorf("dòng audit đổi nội dung sau ack: %+v → %+v — bảng phải append-only", r, after[0])
	}
}

func TestOrderMoneyOverwriteAudit_NoRowWhenCloudAgrees(t *testing.T) {
	// Vế cần thiết: thiếu nó thì một hàm ghi-mọi-lượt-reconcile vẫn qua bài
	// trên, và bảng audit thành bản sao của lịch sử pull — sự có mặt của một
	// dòng không còn mang nghĩa "đã từng lệch".
	e, db := newSyncTestEngine(t, "")
	seedMoneyOrder(t, e, 1100)
	wireAlerts(t, e)

	e.reconcileOrderFromCloud("o1", map[string]any{
		"total_amount":    "1100.00",
		"subtotal":        "1000.00",
		"tax_amount":      "100.00",
		"service_charge":  "0.00",
		"discount_amount": "0.00",
	})

	if got := auditRowsFor(t, db, "o1"); len(got) != 0 {
		t.Errorf("Cloud trả về ĐÚNG số của máy trạm mà vẫn có %d dòng audit", len(got))
	}
}

func TestOrderMoneyOverwriteAudit_DeadbandOverwriteStillLeavesTrail(t *testing.T) {
	// Deadband #2167 (lệch ±1, đơn chưa thu) hạ mức khẩn của ALERT — nó không
	// được xoá VẾT. Ghi đè là ghi đè: cùng hợp đồng với "Vẫn LOG đủ số" của
	// chính nhánh deadband, vì bảng này là chỗ người điều tra đọc lại khi log
	// đã xoay vòng.
	e, db := newSyncTestEngine(t, "")
	seedMoneyOrder(t, e, 0)
	em := wireAlerts(t, e)

	e.reconcileOrderFromCloud("o1", map[string]any{
		"total_amount":    "1099.00", // -1
		"subtotal":        "1000.00",
		"tax_amount":      "100.00",
		"service_charge":  "0.00",
		"discount_amount": "1.00", // +1
	})

	if _, ok := openAlertFor(t, em, KindCloudMoneyOverwrite, "o1"); ok {
		t.Fatal("lệch ±1 dựng alert — deadband #2167 hỏng, test này đang đo sai tiền đề")
	}
	got := auditRowsFor(t, db, "o1")
	if len(got) != 1 {
		t.Fatalf("deadband nuốt luôn dòng audit (%d dòng, muốn 1) — alert im được, vết thì không", len(got))
	}
	if got[0].totalLocal != 1100 || got[0].totalCloud != 1099 {
		t.Errorf("total local/cloud = %d/%d, muốn 1100/1099", got[0].totalLocal, got[0].totalCloud)
	}
}

func TestOrderMoneyOverwriteAudit_EachOverwriteIsItsOwnRow(t *testing.T) {
	// Khác bảng `alerts` (gộp (kind, subject) thành một dòng có count tăng):
	// audit trả lời "chính xác chuyện gì, TỪNG LẦN một". Hai lần ghi đè cùng
	// một đơn là hai dòng, mỗi dòng mang ảnh chụp của đúng lần ấy.
	e, db := newSyncTestEngine(t, "")
	seedMoneyOrder(t, e, 0)
	wireAlerts(t, e)

	for _, tax := range []string{"91.00", "94.00"} {
		e.reconcileOrderFromCloud("o1", map[string]any{
			"total_amount":    "1100.00",
			"subtotal":        "1000.00",
			"tax_amount":      tax,
			"service_charge":  "0.00",
			"discount_amount": "9.00",
		})
	}

	got := auditRowsFor(t, db, "o1")
	if len(got) != 2 {
		t.Fatalf("có %d dòng audit cho 2 lần ghi đè, muốn 2 — append-only, không upsert", len(got))
	}
	// Lần hai: máy trạm đã adopt số của lần một (tax 91) trước khi bị ghi đè
	// tiếp — `*_local` là ảnh chụp TRƯỚC của từng lần, không phải số gốc.
	if got[0].taxLocal != 100 || got[0].taxCloud != 91 {
		t.Errorf("lần 1: tax local/cloud = %d/%d, muốn 100/91", got[0].taxLocal, got[0].taxCloud)
	}
	if got[1].taxLocal != 91 || got[1].taxCloud != 94 {
		t.Errorf("lần 2: tax local/cloud = %d/%d, muốn 91/94", got[1].taxLocal, got[1].taxCloud)
	}
}
