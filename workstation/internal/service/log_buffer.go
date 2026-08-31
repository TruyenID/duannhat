package service

import (
	"context"
	"database/sql"
	"encoding/json"
	"log/slog"
	"os"
	stdsync "sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// #2901 — handler `slog` có GHI LẠI, cộng vòng đệm cục bộ của nó.
//
// # Phần dễ bị đánh giá thấp nhất của việc này
//
// Đo 2026-08-15: `slog` mặc định ra stderr và `slog.SetDefault` không xuất hiện
// ở đâu ngoài test ⇒ **chưa có logger dùng chung**. Nên "gửi log lên Cloud"
// không phải là nối một đường ống vào cái đang có — cái đang có là một dòng
// chữ trên stderr của một tiến trình Windows không ai nhìn. Phải dựng chỗ ghi
// trước đã.
//
// # Vì sao ghi vào bộ nhớ trước, KHÔNG ghi thẳng SQLite trong `Handle`
//
// Đây là bất biến cứng, không phải tối ưu hoá. `Handle` chạy trên goroutine của
// người gọi, và trong cây này có **những đường gọi `slog` từ bên trong một
// transaction SQLite** (ví dụ `store` báo lỗi trong lúc `db.Transaction` đang
// giữ khoá ghi). Một `db.Exec` phát ra từ đó lấy một kết nối KHÁC trong pool,
// rồi chặn chờ khoá ghi mà chính transaction gọi nó đang giữ ⇒ **deadlock**,
// và nó deadlock đúng ở đường ghi TIỀN. Vòng đệm trong bộ nhớ + một lượt xả
// riêng cắt đứt vòng đó.
//
// Hệ quả có thật và phải nói thẳng: một lần mất điện giữa hai lượt xả làm mất
// tối đa `logFlushInterval` dữ liệu. Đánh đổi đúng chiều — mất vài giây log
// đứng trước nguy cơ treo máy bán hàng thì không phải một lựa chọn khó.
//
// # Trần, và vì sao trần nằm ở CẢ HAI tầng
//
// Bộ nhớ có trần (`logMemoryCap`) vì một lượt xả có thể hỏng và không được phép
// làm phình RAM của máy quán; SQLite có trần (`logRowCap` + `logRetention`) vì
// đây là vòng đệm điều tra, không phải kho lưu trữ. Cắt luôn bám `id` tự tăng
// nên luôn rơi vào bản ghi CŨ NHẤT.

const (
	// logMemoryCap là số bản ghi giữ trong RAM giữa hai lượt xả. Vượt trần thì
	// bỏ bản ghi CŨ NHẤT: một sự cố đang diễn ra đáng giá hơn một sự cố đã qua,
	// và đây là vòng đệm chứ không phải hàng đợi có bảo đảm.
	logMemoryCap = 2048

	// logRowCap là trần số hàng trong SQLite. Ở mức `info+` (314 điểm gọi) một
	// quán bận sinh vài nghìn dòng mỗi ngày, nên trần này giữ khoảng vài ngày
	// gần nhất — đúng cửa sổ mà một cuộc điều tra hỏi tới.
	logRowCap = 50000

	// logRetention khớp hạn giữ 14 ngày của Cloud (chủ dự án chốt 2026-08-16).
	// Giữ ở máy trạm lâu hơn ở Cloud là tích luỹ rủi ro mà không ai đọc.
	logRetention = 14 * 24 * time.Hour

	// logFlushInterval là nhịp xả bộ nhớ → SQLite.
	logFlushInterval = 5 * time.Second
)

// bufferedLogRecord là một dòng ĐÃ QUA allowlist, chờ xuống SQLite.
type bufferedLogRecord struct {
	loggedAt string
	level    string
	message  string
	attrs    string // JSON đã dựng sẵn — dựng ở đây để lượt xả không phải nghĩ
}

// logSink giữ vòng đệm và biết cách đổ nó xuống SQLite.
type logSink struct {
	db *store.DB

	mu      stdsync.Mutex
	pending []bufferedLogRecord
	dropped int64
}

// LogRecorder là `slog.Handler` bọc: nó chuyển tiếp NGUYÊN VẸN xuống handler
// gốc (stderr không đổi một chữ nào — đó là thứ người ngồi trước máy đang dùng)
// rồi mới thử ghi lại bản đã lọc.
type LogRecorder struct {
	inner slog.Handler
	sink  *logSink

	// attrs là các attr do `WithAttrs` tích luỹ. Giữ lại vì một logger con
	// (`slog.With("order", id)`) mang thông tin định danh của cả nhóm dòng.
	attrs []slog.Attr
	// inGroup: `WithGroup` đổi cách khoá được đặt tên (lồng theo nhóm), mà bảng
	// allowlist khai khoá PHẲNG. Không suy diễn được ⇒ fail-closed: giữ dòng,
	// bỏ hết attr cấp bản ghi.
	inGroup bool
}

// NewLogRecorder bọc `inner`. `db` nil ⇒ chỉ chuyển tiếp, không ghi gì (dùng
// cho các đường khởi động chưa mở database).
func NewLogRecorder(inner slog.Handler, db *store.DB) *LogRecorder {
	return &LogRecorder{inner: inner, sink: &logSink{db: db}}
}

func (h *LogRecorder) Enabled(ctx context.Context, l slog.Level) bool {
	return h.inner.Enabled(ctx, l)
}

func (h *LogRecorder) WithAttrs(attrs []slog.Attr) slog.Handler {
	next := *h
	next.inner = h.inner.WithAttrs(attrs)
	if !h.inGroup {
		// Sao chép chứ không `append` lên mảng dùng chung: hai logger con của
		// cùng một cha sẽ ghi đè lẫn nhau nếu chia chung mảng nền.
		merged := make([]slog.Attr, 0, len(h.attrs)+len(attrs))
		merged = append(merged, h.attrs...)
		merged = append(merged, attrs...)
		next.attrs = merged
	}

	return &next
}

func (h *LogRecorder) WithGroup(name string) slog.Handler {
	next := *h
	next.inner = h.inner.WithGroup(name)
	next.inGroup = true

	return &next
}

// Handle chuyển tiếp TRƯỚC, ghi lại SAU.
//
// Thứ tự đó là hợp đồng: nếu phần ghi lại có gì đó hỏng, stderr vẫn phải nhận
// đủ dòng như trước khi #2901 tồn tại. Một tính năng quan sát làm mất khả năng
// quan sát sẵn có là thứ tệ hơn không có nó.
func (h *LogRecorder) Handle(ctx context.Context, r slog.Record) error {
	err := h.inner.Handle(ctx, r)
	h.capture(r)

	return err
}

// capture lọc rồi đẩy vào vòng đệm. KHÔNG BAO GIỜ ném, KHÔNG BAO GIỜ panic ra
// ngoài: đường gọi của nó là mọi `slog.Warn` trong ứng dụng, kể cả những chỗ
// đang giữ khoá của luồng bán hàng.
func (h *LogRecorder) capture(r slog.Record) {
	defer func() { _ = recover() }()

	if h.sink == nil || h.sink.db == nil {
		return
	}

	// `debug` KHÔNG BAO GIỜ vào đệm. Không phải để tiết kiệm chỗ — mà vì mức log
	// là một quyết định về PII đã được chốt (2026-08-16), và cách duy nhất để
	// nó đúng là ngưỡng nằm ở NGUỒN chứ không ở một cờ nào đó lúc gửi.
	if r.Level < slog.LevelInfo {
		return
	}

	level, ok := logLevelWire(r.Level)
	if !ok {
		return
	}

	allowed, ok := logAllowedAttrs(r.Message)
	if !ok {
		// Fail-closed: message chưa khai ⇒ không ghi gì. Xem `log_allowlist.go`.
		return
	}

	fields := make(map[string]any, len(allowed))
	for _, a := range h.attrs {
		putAllowedLogAttr(fields, allowed, a)
	}
	if !h.inGroup {
		r.Attrs(func(a slog.Attr) bool {
			putAllowedLogAttr(fields, allowed, a)

			return true
		})
	}

	encoded := "{}"
	if len(fields) > 0 {
		if raw, err := json.Marshal(fields); err == nil {
			encoded = string(raw)
		}
	}

	at := r.Time
	if at.IsZero() {
		at = time.Now()
	}

	h.sink.push(bufferedLogRecord{
		loggedAt: at.UTC().Format(time.RFC3339),
		level:    level,
		message:  r.Message,
		attrs:    encoded,
	})
}

// putAllowedLogAttr ghi một attr vào `fields` KHI VÀ CHỈ KHI nó được khai.
//
// Giá trị bị ép về vô hướng có chủ đích. Một `slog.Any("order", order)` mang
// nguyên một struct đơn hàng — tên khách, ghi chú, số điện thoại — và
// `json.Marshal` sẽ vui vẻ tuần tự hoá tất cả. Khai TÊN một attr không phải là
// khai mọi hình dạng nó có thể mang, nên bất cứ thứ gì không phải vô hướng đều
// bị bỏ.
func putAllowedLogAttr(fields map[string]any, allowed map[string]struct{}, a slog.Attr) {
	if _, ok := allowed[a.Key]; !ok {
		return
	}

	v := a.Value.Resolve()
	switch v.Kind() {
	case slog.KindString:
		fields[a.Key] = v.String()
	case slog.KindInt64:
		fields[a.Key] = v.Int64()
	case slog.KindUint64:
		fields[a.Key] = v.Uint64()
	case slog.KindFloat64:
		fields[a.Key] = v.Float64()
	case slog.KindBool:
		fields[a.Key] = v.Bool()
	case slog.KindDuration:
		fields[a.Key] = v.Duration().String()
	case slog.KindTime:
		fields[a.Key] = v.Time().UTC().Format(time.RFC3339)
	default:
		// `KindAny` (gồm mọi `error`) và `KindGroup`. Lỗi là trường hợp thật sự
		// hay gặp và thật sự cần, nên nó được lấy — nhưng chỉ qua `Error()`,
		// tức một chuỗi, không phải struct lỗi có thể ôm cả request.
		if err, ok := v.Any().(error); ok && err != nil {
			fields[a.Key] = err.Error()
		}
	}
}

// logLevelWire ánh xạ mức về đúng ba giá trị hợp đồng wire cho phép.
//
// Mọi mức lạ (custom level giữa các bậc) bị TỪ CHỐI thay vì làm tròn: Cloud trả
// 422 cho một `level` ngoài `info|warn|error` và một dòng làm rơi cả lô là thứ
// hợp đồng #2901 cố ý tránh — nên lô không bao giờ được chứa nó ngay từ đầu.
func logLevelWire(l slog.Level) (string, bool) {
	switch l {
	case slog.LevelInfo:
		return "info", true
	case slog.LevelWarn:
		return "warn", true
	case slog.LevelError:
		return "error", true
	default:
		return "", false
	}
}

// push đưa một bản ghi vào vòng đệm, bỏ bản ghi cũ nhất khi chạm trần.
func (s *logSink) push(rec bufferedLogRecord) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if len(s.pending) >= logMemoryCap {
		// Bỏ nửa đầu chứ không bỏ đúng một hàng: `append` sau một `s.pending[1:]`
		// giữ mảng nền cũ, nên cắt từng hàng một sẽ copy `logMemoryCap` phần tử
		// cho MỖI dòng log tiếp theo — đúng lúc máy đang bận nhất.
		drop := logMemoryCap / 2
		s.pending = append(s.pending[:0], s.pending[drop:]...)
		s.dropped += int64(drop)
	}
	s.pending = append(s.pending, rec)
}

// Flush đổ vòng đệm xuống SQLite rồi cắt phần quá trần. Trả về số hàng đã ghi.
//
// Ghi hỏng thì bản ghi MẤT, không quay lại vòng đệm — và đó là cố ý. Đẩy chúng
// về chỗ cũ sẽ biến một database hỏng thành một vòng lặp phình bộ nhớ trên máy
// của quán, tức một sự cố quan sát leo thang thành một sự cố bán hàng.
func (s *logSink) Flush() int {
	if s == nil || s.db == nil {
		return 0
	}

	s.mu.Lock()
	batch := s.pending
	s.pending = nil
	s.mu.Unlock()

	if len(batch) == 0 {
		return 0
	}

	err := s.db.Transaction(func(tx *sql.Tx) error {
		stmt, err := tx.Prepare(`INSERT INTO log_records (logged_at, level, message, attrs) VALUES (?, ?, ?, ?)`)
		if err != nil {
			return err
		}
		defer stmt.Close()

		for _, rec := range batch {
			if _, err := stmt.Exec(rec.loggedAt, rec.level, rec.message, rec.attrs); err != nil {
				return err
			}
		}

		return nil
	})
	if err != nil {
		return 0
	}

	s.trim()

	return len(batch)
}

// trim cắt theo trần số hàng VÀ theo tuổi.
//
// Hai phép cắt, không phải một: trần số hàng chặn một máy nói nhiều bất thường
// (vòng lặp lỗi), còn hạn tuổi chặn một máy nói rất ít giữ mãi dữ liệu của
// tháng trước. Cái nào chạm trước thì cái đó cắt.
func (s *logSink) trim() {
	_, _ = s.db.Exec(
		`DELETE FROM log_records WHERE id <= (SELECT MAX(id) FROM log_records) - ?`,
		logRowCap,
	)
	_, _ = s.db.Exec(
		`DELETE FROM log_records WHERE logged_at < ?`,
		time.Now().UTC().Add(-logRetention).Format(time.RFC3339),
	)
}

// Dropped là số bản ghi bị bỏ vì vòng đệm chạm trần. Khác 0 nghĩa là lượt xả
// không theo kịp — thứ phải nhìn thấy được, chứ không phải giấu đi.
func (s *logSink) Dropped() int64 {
	s.mu.Lock()
	defer s.mu.Unlock()

	return s.dropped
}

// InstallLogRecorder gắn handler ghi lại vào `slog` mặc định và chạy vòng xả.
//
// Trả về hàm dừng, và hàm đó XẢ MỘT LẦN CUỐI trước khi trả: một lần tắt máy
// bình thường không được nuốt mất mấy giây log cuối cùng, mà mấy giây cuối
// cùng thường là phần thú vị nhất. Gọi nó bằng `defer` NGAY SAU khi mở database
// để nó chạy TRƯỚC `database.Close()` (defer chạy ngược thứ tự đăng ký).
func InstallLogRecorder(db *store.DB) func() {
	base := slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelInfo})
	rec := NewLogRecorder(base, db)
	slog.SetDefault(slog.New(rec))

	stop := make(chan struct{})
	done := make(chan struct{})
	go func() {
		defer close(done)
		ticker := time.NewTicker(logFlushInterval)
		defer ticker.Stop()
		for {
			select {
			case <-stop:
				return
			case <-ticker.C:
				rec.sink.Flush()
			}
		}
	}()

	var once stdsync.Once

	return func() {
		once.Do(func() {
			close(stop)
			<-done
			rec.sink.Flush()
		})
	}
}
