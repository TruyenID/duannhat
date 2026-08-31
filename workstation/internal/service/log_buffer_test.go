package service

import (
	"bytes"
	"encoding/json"
	"log/slog"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

// #2901 — handler `slog` có ghi lại + vòng đệm cục bộ.
//
// Bộ bài dưới đây xếp theo sức nặng của cái có thể mất:
//
//  1. **fail-closed**: một message chưa khai KHÔNG để lại gì cả. Đây là bài
//     quan trọng nhất của cả issue — chiều hỏng của nó là PII khách rời khỏi
//     quán, và #2220 đã chứng minh rằng chiều đó không revert được;
//  2. `debug` không bao giờ vào đệm — ngưỡng mức log là quyết định PII đã chốt,
//     nên nó phải nằm ở NGUỒN chứ không ở một cờ lúc gửi;
//  3. attr chưa khai bị bỏ nhưng DÒNG vẫn sống — nửa còn lại của cùng một luật;
//  4. vòng đệm có trần và cắt bản ghi CŨ NHẤT — nó chạy trên máy của quán.

// ─── Khung dựng ──────────────────────────────────────────────────────────────

func newLogRecorderForTest(t *testing.T) (*LogRecorder, *store.DB, *bytes.Buffer) {
	t.Helper()

	db, err := storetest.Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	var stderr bytes.Buffer
	inner := slog.NewTextHandler(&stderr, &slog.HandlerOptions{Level: slog.LevelDebug})

	return NewLogRecorder(inner, db), db, &stderr
}

type bufferedRow struct {
	level   string
	message string
	attrs   map[string]any
}

func readLogBuffer(t *testing.T, db *store.DB) []bufferedRow {
	t.Helper()

	rows, err := db.Query(`SELECT level, message, attrs FROM log_records ORDER BY id`)
	if err != nil {
		t.Fatalf("read log_records: %v", err)
	}
	defer rows.Close()

	var out []bufferedRow
	for rows.Next() {
		var r bufferedRow
		var raw string
		if err := rows.Scan(&r.level, &r.message, &raw); err != nil {
			t.Fatalf("scan: %v", err)
		}
		if err := json.Unmarshal([]byte(raw), &r.attrs); err != nil {
			t.Fatalf("attrs %q không phải JSON: %v", raw, err)
		}
		out = append(out, r)
	}

	return out
}

// ─── 1. Fail-closed — bài quan trọng nhất ────────────────────────────────────

// Một message chưa khai không để lại **gì cả**: không dòng, không attr.
//
// Không phải "ghi dòng, bỏ hết attr". Hai thứ đó khác nhau ở đúng chỗ quan
// trọng: một message chưa khai có thể mang PII ngay trong CHÍNH thông điệp (một
// dòng dựng bằng `fmt.Sprintf` kèm tên khách), nên giữ lại dòng cũng là rò.
func TestLogRecorder_MessageOutsideAllowlistWritesNothing(t *testing.T) {
	rec, db, stderr := newLogRecorderForTest(t)
	log := slog.New(rec)

	// Đúng hình dạng nguy hiểm mà #2220 đã trả giá: PII nằm trong CẢ message lẫn
	// attr, và cả hai đều không có trong bảng.
	log.Warn("customer checkout failed for Nguyễn Văn A",
		"phone", "090-1234-5678", "email", "a@example.com", "qr_token", "tok-live-1")

	// Dòng thứ hai là một thông điệp CÓ THẬT trong mã máy trạm mà bảng KHÔNG
	// khai — quan trọng hơn dòng bịa ở trên, vì phần lớn trong 305 thông điệp
	// của máy trạm nằm ở nhóm này và chúng phải im lặng theo mặc định.
	//
	// Bài này từng dùng `upsert order failed`, và nó đỏ đúng lúc bảng mở từ 34
	// lên 75 dòng (#2901 lượt gộp) vì thông điệp ấy **đã được khai**. Đó là
	// hành vi đúng của rào, không phải hỏng: chọn chứng cứ cho một bài "ngoài
	// bảng" thì phải chọn thứ ở ngoài bảng. Nếu dòng dưới có ngày được khai,
	// hãy đổi sang một thông điệp chưa khai khác — đừng nới bài test.
	log.Error("handy fire: order not found", "name", "Nguyễn Văn A", "note", "dị ứng tôm")

	rec.sink.Flush()

	if got := readLogBuffer(t, db); len(got) != 0 {
		t.Fatalf("vòng đệm giữ %d dòng chưa khai: %+v — fail-closed đã vỡ", len(got), got)
	}

	// Và stderr KHÔNG đổi: người ngồi trước máy vẫn thấy đủ mọi dòng như trước
	// khi #2901 tồn tại. Một tính năng quan sát làm mất khả năng quan sát sẵn có
	// tệ hơn không có nó.
	if !strings.Contains(stderr.String(), "customer checkout failed") ||
		!strings.Contains(stderr.String(), "handy fire: order not found") {
		t.Errorf("stderr mất dòng sau khi bọc handler:\n%s", stderr.String())
	}
}

// ─── 2. `debug` không bao giờ vào đệm ────────────────────────────────────────

func TestLogRecorder_DebugNeverEntersTheBuffer(t *testing.T) {
	rec, db, _ := newLogRecorderForTest(t)
	log := slog.New(rec)

	// Cùng một message ĐÃ KHAI, phát ở hai mức khác nhau. Chỉ mức là biến duy
	// nhất — nếu `debug` lọt thì không thể đổ cho allowlist.
	log.Debug("sync_queue purged", "rows", 3)
	log.Info("sync_queue purged", "rows", 12)

	rec.sink.Flush()

	got := readLogBuffer(t, db)
	if len(got) != 1 {
		t.Fatalf("vòng đệm giữ %d dòng, muốn 1 (chỉ dòng `info`): %+v", len(got), got)
	}
	if got[0].level != "info" {
		t.Errorf("level = %q, muốn \"info\" — `debug` đã lọt vào đệm", got[0].level)
	}
	if got[0].attrs["rows"] != float64(12) {
		t.Errorf("attrs = %+v, muốn dòng `info` (rows=12), không phải dòng `debug`", got[0].attrs)
	}
}

// ─── 3. Attr chưa khai bị bỏ, DÒNG vẫn sống ──────────────────────────────────

func TestLogRecorder_UndeclaredAttrIsDroppedAndTheLineSurvives(t *testing.T) {
	rec, db, _ := newLogRecorderForTest(t)
	log := slog.New(rec)

	// `sync push failed` khai `id`/`entity`/`retryable` và CỐ Ý không khai
	// `error`: giá trị của `error` ở điểm gọi thật là thân phản hồi Cloud nguyên
	// văn cho một `order.create`, tức payload đơn hàng dội ngược kèm tên khách.
	log.Warn("sync push failed",
		"id", 4211, "entity", "payment", "retryable", true,
		"error", `cloud 422: {"customer_name":"Nguyễn Văn A","phone":"090-1234-5678"}`)

	rec.sink.Flush()

	got := readLogBuffer(t, db)
	if len(got) != 1 {
		t.Fatalf("vòng đệm giữ %d dòng, muốn 1 — attr lạ không được làm rơi cả dòng", len(got))
	}
	if _, leaked := got[0].attrs["error"]; leaked {
		t.Fatalf("attr `error` đã lọt vào đệm: %+v — nó mang payload đơn hàng dội ngược", got[0].attrs)
	}
	for _, key := range []string{"id", "entity", "retryable"} {
		if _, ok := got[0].attrs[key]; !ok {
			t.Errorf("attr %q đã khai mà không có trong đệm: %+v", key, got[0].attrs)
		}
	}
}

// Một attr đã khai nhưng mang cả một struct thì vẫn bị bỏ: khai TÊN không phải
// là khai mọi hình dạng nó có thể mang. `slog.Any("order", order)` sẽ tuần tự
// hoá tên khách, ghi chú, số điện thoại nếu ta để `json.Marshal` tự do.
func TestLogRecorder_DeclaredAttrCarryingAStructIsStillDropped(t *testing.T) {
	rec, db, _ := newLogRecorderForTest(t)
	log := slog.New(rec)

	type customerish struct {
		Name  string `json:"name"`
		Phone string `json:"phone"`
	}
	log.Warn("table-paid slip: no printer configured",
		"order", customerish{Name: "Nguyễn Văn A", Phone: "090-1234-5678"})

	rec.sink.Flush()

	got := readLogBuffer(t, db)
	if len(got) != 1 {
		t.Fatalf("muốn 1 dòng, có %d", len(got))
	}
	if v, ok := got[0].attrs["order"]; ok {
		t.Fatalf("attr `order` mang giá trị không vô hướng vẫn được ghi: %#v", v)
	}
}

// Attr tích luỹ qua `slog.With` vẫn đi qua đúng bảng — một logger con là chỗ
// người ta hay gắn định danh, nên bỏ sót nhánh này là bỏ sót đúng nửa dữ liệu.
func TestLogRecorder_WithAttrsGoesThroughTheSameGate(t *testing.T) {
	rec, db, _ := newLogRecorderForTest(t)
	log := slog.New(rec).With("order", "ORD-2026-0018", "customer_name", "Nguyễn Văn A")

	log.Warn("auto-print payment receipt failed", "err", "printer offline")

	rec.sink.Flush()

	got := readLogBuffer(t, db)
	if len(got) != 1 {
		t.Fatalf("muốn 1 dòng, có %d", len(got))
	}
	if got[0].attrs["order"] != "ORD-2026-0018" {
		t.Errorf("attr `order` từ `With` không tới đệm: %+v", got[0].attrs)
	}
	if _, leaked := got[0].attrs["customer_name"]; leaked {
		t.Fatalf("attr chưa khai từ `With` đã lọt: %+v", got[0].attrs)
	}
}

// ─── 4. Trần vòng đệm ────────────────────────────────────────────────────────

// Vòng đệm chạy trên máy của quán. Chạm trần thì nó cắt bản ghi CŨ NHẤT và
// không phình — một sự cố đang diễn ra đáng giá hơn một sự cố đã qua.
func TestLogRecorder_MemoryBufferCapsAndDropsOldest(t *testing.T) {
	rec, _, _ := newLogRecorderForTest(t)
	log := slog.New(rec)

	overflow := logMemoryCap + logMemoryCap/4
	for i := range overflow {
		log.Info("sync_queue purged", "rows", i)
	}

	rec.sink.mu.Lock()
	held := len(rec.sink.pending)
	oldest := rec.sink.pending[0].attrs
	rec.sink.mu.Unlock()

	if held > logMemoryCap {
		t.Fatalf("vòng đệm giữ %d bản ghi, vượt trần %d — nó đang phình trên máy quán", held, logMemoryCap)
	}
	if rec.sink.Dropped() == 0 {
		t.Error("Dropped() = 0 sau khi tràn — số bị bỏ phải nhìn thấy được, không phải giấu đi")
	}
	// Cắt phải rơi vào bản ghi CŨ NHẤT: hàng đầu tiên còn lại không thể là dòng
	// `rows=0`.
	if strings.Contains(oldest, `"rows":0`) {
		t.Errorf("bản ghi cũ nhất (%s) vẫn còn — phép cắt đã bỏ nhầm đầu mới", oldest)
	}
}

// Trần trong SQLite là tầng thứ hai, và nó cũng phải cắt từ đầu CŨ.
func TestLogSink_SqliteTrimKeepsTheNewestRows(t *testing.T) {
	rec, db, _ := newLogRecorderForTest(t)

	// Chèn bằng MỘT câu lệnh: `logRowCap` lượt `Exec` rời nhau là `logRowCap`
	// transaction SQLite, và bài test này sẽ mất hàng chục giây cho một phép
	// khẳng định về phép cắt.
	if _, err := db.Exec(`
		WITH RECURSIVE c(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM c WHERE x < ?)
		INSERT INTO log_records (logged_at, level, message, attrs)
		SELECT '2026-08-16T00:00:00Z', 'info', 'sync_queue purged', '{}' FROM c`,
		logRowCap+25,
	); err != nil {
		t.Fatalf("seed: %v", err)
	}

	rec.sink.trim()

	var count, minID int64
	if err := db.QueryRow(`SELECT COUNT(*), COALESCE(MIN(id), 0) FROM log_records`).Scan(&count, &minID); err != nil {
		t.Fatalf("count: %v", err)
	}
	if count > logRowCap {
		t.Fatalf("SQLite giữ %d hàng, vượt trần %d", count, logRowCap)
	}
	if minID == 1 {
		t.Error("hàng id=1 vẫn còn — phép cắt không chạm bản ghi cũ nhất")
	}
}

// Hạn tuổi là tầng cắt thứ hai: một máy nói rất ít vẫn không được giữ mãi dữ
// liệu của tháng trước. 14 ngày khớp hạn giữ của Cloud (chốt 2026-08-16).
func TestLogSink_TrimDropsRowsPastRetention(t *testing.T) {
	rec, db, _ := newLogRecorderForTest(t)

	if _, err := db.Exec(
		`INSERT INTO log_records (logged_at, level, message, attrs) VALUES ('2020-01-01T00:00:00Z', 'info', 'sync_queue purged', '{}')`,
	); err != nil {
		t.Fatalf("seed: %v", err)
	}

	rec.sink.trim()

	var count int
	if err := db.QueryRow(`SELECT COUNT(*) FROM log_records`).Scan(&count); err != nil {
		t.Fatalf("count: %v", err)
	}
	if count != 0 {
		t.Errorf("còn %d hàng quá hạn 14 ngày — vòng đệm đang tích luỹ rủi ro không ai đọc", count)
	}
}

// ─── 5. Một nguồn, hai đầu ───────────────────────────────────────────────────

// docTableRow khớp một hàng dữ liệu của bảng hợp đồng: **đúng hai cột**, cột
// đầu là message bọc nguyên vẹn trong dấu backtick.
//
// Hai ràng buộc, mỗi cái chặn một kiểu nhận nhầm, và cả hai đều đã bị vi phạm
// thật trong chính file này:
//
//   - cột đầu phải là backtick TRỌN Ô — mục "Luật đọc bảng" có ô kiểu
//     "`message` **có** trong bảng", có backtick nhưng không phải cả ô;
//   - cột sau KHÔNG được chứa `|` (nên là `[^|]+`, đừng nới thành `.+?`) —
//     nếu không, một bảng BA cột sẽ khớp với cột giữa và cột cuối dính làm một.
var docTableRow = regexp.MustCompile("^\\|\\s*`(.+?)`\\s*\\|([^|]+)\\|\\s*$")

// Bảng Go và tài liệu hợp đồng phải khớp HAI CHIỀU.
//
// Một chiều thôi là chưa đủ, và repo này có án lệ: #2860 sống nhiều tháng với
// bảy cách viết cho ba khái niệm vì không có chỗ nào đối chiếu hai tập với
// nhau. Ở đây chiều "doc ⊆ Go" bắt việc khai một dòng cho Cloud mà quên máy
// trạm (Cloud sẽ nhận đúng, máy trạm không bao giờ gửi — hỏng IM LẶNG); chiều
// "Go ⊆ doc" bắt việc mở một dòng ở máy trạm mà không ai xem lại nó có mang PII
// không.
func TestLogAllowlist_MatchesTheSharedContractDocument(t *testing.T) {
	path := filepath.Join("..", "..", "..", "docs", "reference", "workstation-log-allowlist.md")
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("không đọc được nguồn chân lý %s: %v", path, err)
	}

	// CHỈ đọc các bảng nằm trong bốn mục `### Nhóm N`, và dừng ở heading cấp 2
	// kế tiếp.
	//
	// Ràng buộc vị trí này không phải cho gọn: tài liệu còn những bảng văn xuôi
	// khác, và một trong số đó liệt kê **tên các rào** trong ô backtick. Không
	// giới hạn phạm vi thì `TestLogAllowlist_MatchesTheSharedContractDocument`
	// tự đọc CHÍNH TÊN MÌNH thành một thông điệp log phải khai — đã xảy ra
	// đúng một lần, ngay ở lượt viết mục hướng dẫn ấy.
	fromDoc := map[string][]string{}
	inGroup := false

	for _, line := range strings.Split(string(raw), "\n") {
		switch {
		case strings.HasPrefix(line, "### Nhóm"):
			inGroup = true

			continue
		case strings.HasPrefix(line, "## "):
			inGroup = false

			continue
		}

		if !inGroup {
			continue
		}

		m := docTableRow.FindStringSubmatch(line)
		if m == nil {
			continue
		}
		msg := strings.TrimSpace(m[1])
		fromDoc[msg] = parseDocAttrs(m[2])
	}

	if len(fromDoc) == 0 {
		t.Fatalf("không parse được hàng nào từ %s — bảng đã đổi hình dạng, và một rào không đọc được gì là một rào đã TẮT", path)
	}

	fromGo := map[string][]string{}
	for _, r := range logAllowRules {
		attrs := r.Attrs
		if attrs == nil {
			attrs = []string{}
		}
		fromGo[r.Message] = attrs
	}

	for msg, docAttrs := range fromDoc {
		goAttrs, ok := fromGo[msg]
		if !ok {
			t.Errorf("tài liệu khai %q mà `logAllowRules` không có — máy trạm sẽ KHÔNG BAO GIỜ gửi dòng này, im lặng", msg)

			continue
		}
		if strings.Join(goAttrs, ",") != strings.Join(docAttrs, ",") {
			t.Errorf("%q: attr Go = %v, tài liệu = %v", msg, goAttrs, docAttrs)
		}
	}
	for msg := range fromGo {
		if _, ok := fromDoc[msg]; !ok {
			t.Errorf("`logAllowRules` khai %q mà tài liệu không có — dòng này rời khỏi quán mà chưa ai xem lại nó mang gì", msg)
		}
	}
}

func parseDocAttrs(cell string) []string {
	cell = strings.TrimSpace(cell)
	if cell == "" || cell == "—" {
		return []string{}
	}

	// Dấu phân cách của bảng là `·`, KHÔNG phải dấu phẩy. Tách nhầm ký tự thì
	// một ô nhiều attr ra đúng MỘT chuỗi rác, và rào vẫn xanh khi so với một
	// bảng Go cũng sai y hệt — im lặng, đúng kiểu #2860.
	out := []string{}
	for _, part := range strings.Split(cell, "·") {
		if a := strings.Trim(strings.TrimSpace(part), "`"); a != "" {
			out = append(out, a)
		}
	}

	return out
}

// Rào phải biết KÊU và biết IM. `logAllowedAttrs` là cổng duy nhất, nên bài này
// khẳng định cả hai chiều của nó trên dữ liệu thật của bảng.
func TestLogAllowlist_GateAnswersBothWays(t *testing.T) {
	if _, ok := logAllowedAttrs("sync_queue purged"); !ok {
		t.Error("message ĐÃ khai bị từ chối — rào kêu oan thì nó sẽ bị tắt, không bị tranh luận")
	}
	if _, ok := logAllowedAttrs("customer checkout failed for Nguyễn Văn A"); ok {
		t.Error("message CHƯA khai được chấp nhận — fail-closed đã vỡ")
	}
}

// `logRecordWire` là lớp lọc thứ hai, ngay trước khi gửi. Nó lặp lại phép kiểm
// của handler CÓ CHỦ ĐÍCH: giữa lúc ghi và lúc gửi có thể có một bản cũ của
// handler, một lần sửa tay database, hay một công cụ khôi phục.
func TestLogRecordWire_RejectsWhatTheHandlerWouldNeverHaveWritten(t *testing.T) {
	if _, ok := logRecordWire(1, "2026-08-16T03:14:43Z", "debug", "sync_queue purged", `{"rows":1}`); ok {
		t.Error("`debug` lọt qua lớp lọc lúc gửi — Cloud trả 422 và cả lô rơi")
	}
	if _, ok := logRecordWire(2, "2026-08-16T03:14:43Z", "info", "một message chưa khai", `{}`); ok {
		t.Error("message chưa khai lọt qua lớp lọc lúc gửi")
	}
	if _, ok := logRecordWire(3, "2026-08-16T12:14:43+09:00", "info", "sync_queue purged", `{}`); !ok {
		t.Error("dấu thời gian có offset bị từ chối thay vì ép về UTC")
	}
	body, ok := logRecordWire(4, "2026-08-16T12:14:43+09:00", "info", "sync_queue purged", `{"rows":7,"name":"Nguyễn Văn A"}`)
	if !ok {
		t.Fatal("dòng hợp lệ bị từ chối")
	}
	if body["logged_at"] != "2026-08-16T03:14:43Z" {
		t.Errorf("logged_at = %v, muốn RFC3339 UTC — offset địa phương làm HQ xếp sai thứ tự giữa quán VN và JP (#1091)", body["logged_at"])
	}
	attrs, _ := body["attrs"].(map[string]any)
	if _, leaked := attrs["name"]; leaked {
		t.Errorf("attr chưa khai sống sót trong database vẫn được gửi đi: %+v", attrs)
	}
}
