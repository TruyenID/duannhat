package handler

import (
	"net/http"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store"
)

// #2063 — đơn TREO không được in biên lai / hoá đơn đỏ, và lần bị chặn KHÔNG
// được đốt số bản in.
//
// Bẫy số 3 của issue nằm trọn ở phép đo `print_jobs = 0`: `beginMoneyPrint` cố
// ý đốt số cả khi in LỖI (P-10b) — đúng, vì lượt in đó đã xảy ra. Nhưng lượt bị
// TỪ CHỐI thì không có tờ giấy nào, nên đốt số ở đó làm tờ hợp lệ đầu tiên (sau
// khi khách trả nợ) ra đời mang 「BAN IN #2」, tự nhận là bản sao của một tờ
// chưa từng tồn tại.
func seedHoldOrder(t *testing.T, db *store.DB, orderID string, cloudFlag any) {
	t.Helper()
	if _, err := db.Exec(
		`INSERT INTO orders (id, order_code, status, total_amount, paid_amount, is_on_hold, created_at, updated_at)
		 VALUES (?, 'ORD-HOLD', 'closed', 3000, 3000, ?, datetime('now'), datetime('now'))`,
		orderID, cloudFlag,
	); err != nil {
		t.Fatal(err)
	}
}

func TestReceipt_OrderOnHoldIs409AndBurnsNothing(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.idempotency = service.NewIdempotencyStore(db)
	seedReceiptPrinter(t, s, db) // máy CÓ, để 503 không che mất cổng đang đo
	seedHoldOrder(t, db, "o-hold", 1)

	rec := postReceipt(t, s, `{"order_id":"o-hold"}`)

	if rec.Code != http.StatusConflict {
		t.Fatalf("code = %d, want 409 — đơn treo mà vẫn in biên lai (body=%s)", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), `"code":"order_on_hold"`) {
		t.Errorf("body = %s, want `\"code\":\"order_on_hold\"` — pos-web `isOnHoldError()` khớp trên `code`, sai khoá thì UI không nhận ra", rec.Body.String())
	}

	var jobs int
	if err := db.QueryRow(`SELECT COUNT(*) FROM print_jobs`).Scan(&jobs); err != nil {
		t.Fatal(err)
	}
	if jobs != 0 {
		t.Fatalf("print_jobs = %d, want 0 — lần bị TỪ CHỐI đã đốt số bản in; tờ hợp lệ đầu tiên sẽ mang 「BAN IN #2」", jobs)
	}
}

// CHIỀU PHẢI IM. Không có bài này thì bài trên xanh kể cả khi cổng chặn MỌI đơn,
// và quán không in được biên lai nào — hỏng nặng hơn lỗi nó sửa.
func TestReceipt_CleanOrderStillPrints(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.idempotency = service.NewIdempotencyStore(db)
	seedReceiptPrinter(t, s, db)
	seedHoldOrder(t, db, "o-clean", 0) // Cloud nói KHÔNG treo

	rec := postReceipt(t, s, `{"order_id":"o-clean"}`)

	if rec.Code == http.StatusConflict {
		t.Fatalf("đơn sạch bị chặn oan: %s", rec.Body.String())
	}
}

// Bẫy số 2 mang xuống tầng này: NULL = "Cloud CHƯA nói", không phải "không
// treo". Với đơn Cloud chưa nói gì và không có nợ local, máy trạm không có căn
// cứ để chặn — nên nó KHÔNG chặn.
func TestReceipt_CloudSilentAndNoLocalDebtDoesNotBlock(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.idempotency = service.NewIdempotencyStore(db)
	seedReceiptPrinter(t, s, db)
	seedHoldOrder(t, db, "o-silent", nil) // NULL

	if rec := postReceipt(t, s, `{"order_id":"o-silent"}`); rec.Code == http.StatusConflict {
		t.Fatalf("Cloud chưa nói + không nợ local ⇒ không được chặn: %s", rec.Body.String())
	}
}

// BẪY SỐ 1, vế hai: nợ ghi sổ vừa thu ở quầy, CHƯA sync UP nên Cloud chưa thể
// biết. `cloud_id` rỗng là dấu duy nhất phân biệt "Cloud chưa thấy" với "Cloud
// thấy rồi và đã trả lời".
func TestReceipt_UnsyncedLocalDebtBlocksEvenWhenCloudSaysClean(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.idempotency = service.NewIdempotencyStore(db)
	seedReceiptPrinter(t, s, db)
	seedHoldOrder(t, db, "o-debt", 0) // Cloud nói KHÔNG treo — nhưng nó chưa thấy khoản dưới

	if _, err := db.Exec(
		`INSERT INTO payment_methods (id, code, name, type) VALUES ('pm-debt', 'debt', 'On account', 'on_account')`,
	); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(
		`INSERT INTO payments (id, order_id, payment_method, payment_method_id, amount, status, cloud_id)
		 VALUES ('pay-debt', 'o-debt', 'debt', 'pm-debt', 3000, 'succeeded', NULL)`,
	); err != nil {
		t.Fatal(err)
	}

	rec := postReceipt(t, s, `{"order_id":"o-debt"}`)
	if rec.Code != http.StatusConflict {
		t.Fatalf("code = %d, want 409 — nợ local chưa sync phải chặn (body=%s)", rec.Code, rec.Body.String())
	}
}

// Vế hai phải TỰ TẮT khi payment sync xong — nếu không, một đơn đã trả nợ sẽ
// vĩnh viễn không in được hoá đơn, đúng cái bẫy 1 cảnh báo.
func TestReceipt_SyncedDebtLetsCloudDecide(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.idempotency = service.NewIdempotencyStore(db)
	seedReceiptPrinter(t, s, db)
	seedHoldOrder(t, db, "o-settled", 0)

	if _, err := db.Exec(
		`INSERT INTO payment_methods (id, code, name, type) VALUES ('pm-debt', 'debt', 'On account', 'on_account')`,
	); err != nil {
		t.Fatal(err)
	}
	// `cloud_id` CÓ ⇒ Cloud đã thấy khoản này và đã trả lời "không treo".
	if _, err := db.Exec(
		`INSERT INTO payments (id, order_id, payment_method, payment_method_id, amount, status, cloud_id)
		 VALUES ('pay-settled', 'o-settled', 'debt', 'pm-debt', 3000, 'succeeded', 'cloud-pay-1')`,
	); err != nil {
		t.Fatal(err)
	}

	if rec := postReceipt(t, s, `{"order_id":"o-settled"}`); rec.Code == http.StatusConflict {
		t.Fatalf("nợ đã sync + Cloud nói sạch ⇒ phải in được: %s", rec.Body.String())
	}
}

// Hợp đồng với pos-web, ghim ở PHÍA SERVER.
//
// `isOnHoldError()` (`web/pos/src/app/pos/lib/on-hold.ts`) khớp trên `body.code`
// và docblock của nó nói rõ vì sao: *"khớp trên `code`, không khớp trên
// `message`: message là câu tiếng Anh dành cho log và nó được phép đổi; `code`
// là hợp đồng."*
//
// Bài này tồn tại vì hai đầu ĐÃ lệch một lần: cổng phát `status:
// "order_on_hold"` trong khi client đọc `code`. Cổng vẫn chặn đúng — 409, không
// đốt số bản in — nhưng giao diện không nhận ra, nên thu ngân thấy một lỗi
// chung chung thay vì "đơn còn treo tiền", và sẽ bấm lại. Cả hai phía test đều
// xanh riêng lẻ; chỉ chỗ NỐI là sai.
func TestReceipt_OnHoldErrorUsesCodeKeyForPosWeb(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	s.idempotency = service.NewIdempotencyStore(db)
	seedReceiptPrinter(t, s, db)
	seedHoldOrder(t, db, "o-contract", 1)

	body := postReceipt(t, s, `{"order_id":"o-contract"}`).Body.String()

	if !strings.Contains(body, `"code":"order_on_hold"`) {
		t.Fatalf("body = %s\nthiếu khoá `code` — pos-web `isOnHoldError()` sẽ không nhận ra lời từ chối này", body)
	}
	// `status` KHÔNG được dùng cho việc này: nó là khoá của đường 503
	// `no_printer`, vốn được đọc như một response THÀNH CÔNG có hình dạng khác.
	if strings.Contains(body, `"status":"order_on_hold"`) {
		t.Errorf("body = %s — đừng phát cả hai khoá; một hợp đồng, một nguồn sự thật", body)
	}
}
