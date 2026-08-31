package service

// #2901 — allowlist các dòng log được phép rời khỏi quán.
//
// # Vì sao ALLOWLIST, không phải blocklist
//
// Đo trên cây này ngày 2026-08-15: **305 thông điệp log khác nhau**, và các
// trường đính kèm gồm `"name"` 348 chỗ · `"note"` 77 · `"customer_id"` 20 ·
// `"email"` 17 · `"address"` 17 · `"phone"` 15 · `"qr_token"` 11.
//
// Blocklist (bắt regex email/điện thoại, xoá các tên trường đã biết) **fail-open**:
// thêm một trường PII mới ở bất kỳ đâu trong 305 thông điệp thì nó tự chảy lên
// Cloud, và không ai biết. Repo này đã trả giá đúng kiểu đó ở #2220 — 11 cặp
// token còn sống + 287 `qr_token` + PII khách vào git, **revert không thu hồi
// được**, xoay khoá là việc TAY ở IdP.
//
// Nên: chỉ những gì khai ở đây mới đi. Thêm một dòng log mới ⇒ mặc định KHÔNG
// lên Cloud cho tới khi có người khai nó. Fail-closed, đúng chiều.
//
// # Luật khai một attr: xét GIÁ TRỊ, không xét TÊN
//
// Đây là phần dễ làm sai nhất, và nó đã suýt lọt ở chính lượt viết này.
// `"err"` là một cái tên vô hại; nhưng ở `sync push failed` giá trị của nó là
// **thân phản hồi nguyên văn của Cloud** cho một `order.create` — tức là chính
// payload đơn hàng dội ngược, kèm tên khách. Vì thế `sync push failed` khai
// `id`/`entity`/`retryable` và **cố ý KHÔNG khai `error`**: vẫn trả lời được
// "dòng nào hỏng, có thử lại được không", mà không mang khách hàng theo.
//
// Quy tắc: một attr chỉ được khai khi **mọi đường sinh ra giá trị của nó** đều
// không thể mang dữ liệu khách. Lỗi từ driver SQLite / máy in / websocket thì
// được; thân phản hồi HTTP của một endpoint mang đơn hàng thì không.
//
// # Đợt đầu chỉ bốn nhóm
//
// `sync` · `alert` · thu tiền mặt (Glory) · `print` — theo nhu cầu điều tra đã
// có thật. 305 thông điệp là quá nhiều để khai một lượt, và bắt đầu hẹp đúng
// tinh thần fail-closed. Đánh đổi nói thẳng: người điều tra sẽ THIẾU một số
// dòng và phải bổ sung bảng rồi chờ lượt sau — chiều ngược lại là rò PII khách
// không thu hồi được.
//
// # Một nguồn, hai đầu
//
// Nguồn chân lý theo hợp đồng wire là `docs/reference/workstation-log-allowlist.md`.
// Bảng Go dưới đây là bản chạy được của chính nó, và
// `TestLogAllowlist_MatchesTheSharedContractDocument` đối chiếu HAI CHIỀU với
// file ấy — thêm một dòng ở một bên mà quên bên kia thì test đỏ, không phải
// trôi im lặng như bảy cách viết `split_mode` ở #2860.

// logAllowRule là một dòng của bảng hợp đồng.
type logAllowRule struct {
	Group   string
	Message string
	Attrs   []string
}

// logAllowRules — thứ tự ở đây khớp thứ tự trong tài liệu, để người đọc đối
// chiếu được bằng mắt chứ không chỉ bằng test.
var logAllowRules = []logAllowRule{
	// ─── sync ──────────────────────────────────────────────────────────────────
	{"sync", "sync engine started", nil},
	{"sync", "sync engine stopped", nil},
	{"sync", "sync puller started", nil},
	{"sync", "sync puller stopped", nil},
	{"sync", "sync poke connected", nil},
	{"sync", "sync poke disconnected — pull ticks unaffected, reconnecting with backoff", nil},
	{"sync", "sync manifest available — manifest-driven pull active", nil},
	{"sync", "sync manifest unavailable — falling back to legacy full pull (old Cloud?)", nil},
	{"sync", "sync push failed", []string{"id", "entity", "retryable"}},
	{"sync", "sync row dead-lettered", []string{"id", "reason"}},
	{"sync", "sync throttled — backing off", []string{"retry_after", "cooldown_until"}},
	{"sync", "sync: no handler", []string{"key", "entity_id"}},
	{"sync", "sync: invalid payload", []string{"key"}},
	{"sync", "sync: non-retryable failure", []string{"key", "entity_id"}},
	{"sync", "sync_queue purged", []string{"rows"}},
	{"sync", "device token cleared after cloud 401 — sync stopped, workstation must re-pair", nil},
	{"sync", "cascade dead-lettered order children", []string{"order_id", "rows"}},
	{"sync", "cascade dead-lettered till children", []string{"session_id", "rows"}},
	{"sync", "upsert order failed", []string{"order_id"}},
	{"sync", "pull cursor was ahead of Cloud's clock — healing", []string{"key", "was", "now", "ahead_by"}},
	{"sync", "heal cursor failed", []string{"key"}},
	{"sync", "customer_orders cursor stalled on a full page — stepping past it; rows sharing this second may be skipped", []string{"cursor", "stepped_to", "rows", "limit"}},
	{"sync", "bulk order pull — auto-print suppressed (likely Cloud re-seed/backfill)", []string{"firing", "tick", "max"}},
	{"sync", "stamp feed version", []string{"feed"}},
	{"sync", "stamp manifest version", nil},
	{"sync", "sync_pull menu_catalog", nil},
	{"sync", "sync_pull menu_schedules", nil},
	{"sync", "sync_pull promotions", nil},
	{"sync", "sync_pull coupons", nil},
	{"sync", "sync_pull customers", nil},
	{"sync", "sync_pull staff", nil},
	{"sync", "sync_pull printers", nil},
	{"sync", "sync_pull peripheral_devices", nil},
	{"sync", "sync_pull payment_methods", nil},
	{"sync", "sync_pull tender_types", nil},
	{"sync", "sync_pull tender_categories", nil},
	{"sync", "sync_pull denominations", nil},
	{"sync", "sync_pull effective_payment_options", nil},
	{"sync", "sync_pull till", nil},
	{"sync", "sync_pull till_sessions", nil},

	// ─── alert ─────────────────────────────────────────────────────────────────
	{"alert", "alert raised", []string{"kind", "subject", "severity", "audience"}},
	{"alert", "alerts purged (closed rows past retention)", []string{"rows"}},

	// ─── thu tiền mặt (Glory 釣銭機) ──────────────────────────────────────────────
	{"cash", "cash drawer: opened for cash payment", []string{"order"}},
	{"cash", "cash drawer: kick failed", []string{"order", "reason"}},
	{"cash", "cash drawer: could not read payment methods", []string{"order"}},
	{"cash", "phục hồi lượt thu tiền mặt sau restart", []string{"session", "order", "glory_txn", "payment"}},
	{"cash", "không ghi được phiên thu tiền mặt — lượt này sẽ không phục hồi được nếu máy trạm tắt", []string{"session", "order"}},
	{"cash", "không đóng được phiên thu tiền mặt", []string{"session"}},
	{"cash", "không ghi được sổ máy thu tiền", []string{"session"}},
	{"cash", "không ghi được sự cố máy thu tiền", []string{"title"}},
	{"cash", "không đóng được sự cố máy thu tiền", []string{"title"}},
	{"cash", "không đóng dấu được mã giao dịch 釣銭機 — lượt này sẽ không hỏi lại được máy nếu máy trạm tắt", []string{"session", "glory_txn"}},
	{"cash", "đã đối soát phiên thu tiền mặt còn dở từ lần chạy trước", []string{"count"}},
	{"cash", "đối soát phiên 釣銭機 thất bại (không chặn khởi động)", nil},
	{"cash", "enqueue payment.create (cash_changer)", []string{"payment"}},

	// ─── in ấn ─────────────────────────────────────────────────────────────────
	{"print", "printer dispatcher: unclassified printer_group, defaulting to kitchen_printer", []string{"printer_group"}},
	{"print", "printer dispatcher: no device configured for role, not rerouting", []string{"printer_group", "role"}},
	{"print", "printer dispatcher: receipt_printer not configured, falling back to kitchen_printer", nil},
	{"print", "device connected", []string{"device"}},
	{"print", "connect device failed", []string{"device"}},
	{"print", "scan device", nil},
	{"print", "auto-print payment receipt failed", []string{"order"}},
	{"print", "table-paid slip: order not found locally", []string{"order"}},
	{"print", "table-paid slip: no printer configured", []string{"order"}},
	{"print", "table-paid slip: printer connect failed", []string{"order"}},
	{"print", "table-paid slip: print failed", []string{"order"}},
	{"print", "kitchen-ticket force-pull failed", []string{"order_id"}},
	{"print", "reprintKitchenForOrder: print failed", []string{"printer_group"}},
	{"print", "print counts failed (non-fatal)", []string{"kind"}},
	{"print", "print: tax row omitted — the order carries no tax fact to print", []string{"kind", "order_id", "order_code"}},
	{"print", "print: order has no positive total — the slip prints 0, not a recomputed figure", []string{"order_id", "order_code"}},
	{"print", "print journal reserve failed (non-fatal)", []string{"kind", "order", "payment"}},
	{"print", "print journal confirm failed (non-fatal)", []string{"job", "kind"}},
	{"print", "print reservation sweep failed (non-fatal)", nil},
	{"print", "print reservations abandoned by a previous run", []string{"rows", "action"}},
	{"print", "refresh before print failed (non-fatal)", []string{"order", "status"}},
}

// logAllowIndex là bảng tra lúc chạy. Dựng một lần ở `init` vì `Handle` chạy
// trên MỌI dòng log của một máy POS đang bán — dựng lại map ở đó là đốt CPU của
// quán để trả lời một câu hỏi không bao giờ đổi.
var logAllowIndex = func() map[string]map[string]struct{} {
	idx := make(map[string]map[string]struct{}, len(logAllowRules))
	for _, r := range logAllowRules {
		attrs := make(map[string]struct{}, len(r.Attrs))
		for _, a := range r.Attrs {
			attrs[a] = struct{}{}
		}
		idx[r.Message] = attrs
	}

	return idx
}()

// logAllowedAttrs trả tập attr được phép của một message.
//
// `ok == false` nghĩa là **không ghi gì cả** — không phải "ghi dòng, bỏ hết
// attr". Hai thứ đó khác nhau ở đúng chỗ quan trọng: một message chưa khai có
// thể mang PII ngay trong CHÍNH thông điệp (ví dụ một dòng dựng bằng
// `fmt.Sprintf` kèm tên khách), nên giữ lại dòng cũng là rò.
func logAllowedAttrs(message string) (map[string]struct{}, bool) {
	attrs, ok := logAllowIndex[message]

	return attrs, ok
}
