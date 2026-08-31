package service

import (
	"errors"
	"log/slog"
)

// AlertEmitter là điểm dồn DUY NHẤT giữa "ghi log" và "dựng alert".
//
// # Vì sao cần một lớp mỏng thay vì gọi thẳng AlertStore
//
// Trước lớp này, `AlertStore` đã có đủ `Raise`/`ResolveAuto`/`Ack` và có test,
// nhưng `git grep AlertStore` ra **0 caller** ngoài chính file của nó — hạ tầng
// đã trả tiền mà chưa thu được gì, còn 145 chỗ `slog.Warn` vẫn kêu vào một file
// log không ai mở. Lý do không phải lười: gọi thẳng store buộc mỗi call site
// phải tự quyết ba việc — log hay không, kind nào, làm gì khi store lỗi — và ba
// quyết định đó lặp lại 145 lần thì sẽ lệch nhau.
//
// Emitter chốt cả ba:
//
//   - **Luôn log, kể cả khi đã dựng alert.** Alert là để người vận hành thấy;
//     log là để người sửa lỗi đọc lại sau. Thay log bằng alert nghe như dọn dẹp
//     nhưng thực ra là xoá vết điều tra.
//   - **Kind chưa đăng ký thì KHÔNG câm.** Registry là default-deny có chủ đích,
//     nhưng "bị từ chối" phải nhìn thấy được — nếu không, thêm một nguồn mới mà
//     quên khai kind sẽ im lặng không bao giờ kêu.
//   - **Store hỏng KHÔNG được làm hỏng đường gọi.** Emitter nằm trên đường in
//     hoá đơn, đường thu tiền. Một lỗi ghi SQLite không được phép chặn việc bán
//     hàng — nó chỉ được làm mất cái alert.
//
// # Emitter không quyết định cái gì đáng báo
//
// Nó không lọc, không gộp, không đặt ngưỡng. Gộp theo `(kind, subject)` là việc
// của `AlertStore` (partial unique index), còn "kind nào tồn tại" là việc của
// `alertRegistry`. Nhét thêm luật vào đây sẽ tạo ra tầng thứ ba quyết định cùng
// một chuyện.
type AlertEmitter struct {
	store *AlertStore
	log   *slog.Logger

	// #1806 S2 — đường phát sự kiện ra LAN.
	//
	// Là một FUNC chứ không phải *Hub: package `service` không được phụ thuộc
	// vào `handler` (chiều phụ thuộc là handler → service). Một hàm thuần cắt
	// đúng chỗ đó, và nó cũng làm test không phải dựng WS hub thật.
	broadcast func(eventType string, payload any)
}

// SetBroadcaster nối đường phát. Nil = không phát (mọi thứ khác vẫn chạy).
func (e *AlertEmitter) SetBroadcaster(fn func(eventType string, payload any)) {
	if e != nil {
		e.broadcast = fn
	}
}

// emit phát sự kiện, nuốt mọi hỏng hóc.
//
// Phát alert ra LAN KHÔNG được là lý do làm hỏng việc dựng alert — thứ tự quan
// trọng là: ghi vào SQLite trước (nó sống qua restart), phát sau (nó chỉ tới
// được ai đang kết nối).
func (e *AlertEmitter) emit(eventType string, payload any) {
	if e == nil || e.broadcast == nil {
		return
	}

	defer func() {
		if r := recover(); r != nil {
			e.log.Error("phát alert ra LAN gây panic", "event", eventType, "panic", r)
		}
	}()

	e.broadcast(eventType, payload)
}

func NewAlertEmitter(store *AlertStore, log *slog.Logger) *AlertEmitter {
	if log == nil {
		log = slog.Default()
	}
	return &AlertEmitter{store: store, log: log}
}

// Raise ghi log rồi dựng/gộp alert.
//
// `subject` là thứ phân biệt hai sự cố CÙNG loại: máy in nào, feed nào, phiên
// nào. Nó đi vào khoá gộp, nên chọn sai subject sẽ hoặc gộp hai sự cố khác nhau
// làm một, hoặc làm một sự cố kéo dài đẻ ra vô số dòng.
func (e *AlertEmitter) Raise(kind AlertKind, subject, title string, detail map[string]any) {
	// Kiểm nil TRƯỚC khi chạm e.log. Bản đầu của hàm này log rồi mới kiểm — tức
	// một emitter nil sẽ panic ngay tại chỗ, trên đúng đường in hoá đơn mà nó
	// được viết ra để bảo vệ.
	if e == nil {
		return
	}

	attrs := []any{"alert_kind", string(kind), "subject", subject}
	for k, v := range detail {
		attrs = append(attrs, k, v)
	}
	e.log.Warn(title, attrs...)

	if e.store == nil {
		return
	}

	id, err := e.store.Raise(kind, subject, title, detail)
	if err != nil {
		if errors.Is(err, ErrAlertKindNotRegistered) {
			// Lập trình sai, không phải sự cố vận hành: ai đó thêm nguồn mới mà
			// quên khai kind. Kêu to ở mức error để nó không lẫn vào chính đống
			// warning mà hệ thống này sinh ra để dọn.
			e.log.Error("alert kind chưa đăng ký — nguồn này sẽ KHÔNG BAO GIỜ hiện lên panel",
				"alert_kind", string(kind), "subject", subject)
			return
		}
		e.log.Error("không ghi được alert", "alert_kind", string(kind), "subject", subject, "error", err)

		return
	}

	severity, audience, _, _ := AlertPolicyFor(kind)

	e.emit("alert.raised", map[string]any{
		"id":       id,
		"kind":     string(kind),
		"subject":  subject,
		"severity": string(severity),
		"audience": string(audience),
		"title":    title,
		"detail":   detail,
	})
}

// Resolve đóng alert đang mở của một (kind, subject) khi điều kiện đã hết.
//
// Chỉ có tác dụng với kind khai `AutoResolvable`. Kind cần `ack` thì im lặng bỏ
// qua — và đó là chủ đích: `cash_retained` (tiền còn kẹt trong máy) không được
// phép tự biến mất chỉ vì lần đếm sau không thấy nữa; phải có người xác nhận đã
// lấy tiền ra.
func (e *AlertEmitter) Resolve(kind AlertKind, subject string) {
	if e == nil || e.store == nil {
		return
	}

	if err := e.store.ResolveAuto(kind, subject); err != nil {
		e.log.Error("không đóng được alert", "alert_kind", string(kind), "subject", subject, "error", err)

		return
	}

	// Phát cả khi đóng: banner ở pos-web/KDS phải TỰ TẮT khi sự cố hết. Bắt
	// người dùng tải lại trang để hết một banner đỏ là cách nhanh nhất khiến
	// họ học được rằng banner đỏ không có nghĩa gì.
	e.emit("alert.resolved", map[string]any{
		"kind":    string(kind),
		"subject": subject,
	})
}

// ListOpen và Ack đi XUYÊN QUA emitter thay vì để handler gọi thẳng store.
//
// Không phải để giấu store, mà để chỉ có MỘT đường tới alert. Hai đường tới
// cùng một dữ liệu là chỗ chúng lệch nhau — và ở đây "lệch" nghĩa là panel hiển
// thị một tập alert, còn thứ được ack lại là tập khác.

func (e *AlertEmitter) ListOpen() ([]Alert, error) {
	if e == nil || e.store == nil {
		return []Alert{}, nil
	}
	return e.store.ListOpen()
}

func (e *AlertEmitter) Ack(id, by string) error {
	if e == nil || e.store == nil {
		return nil
	}
	return e.store.Ack(id, by)
}

// AckKind đóng mọi alert mở của một kind bằng một xác nhận (#2167).
func (e *AlertEmitter) AckKind(kind AlertKind, by string) (int, error) {
	if e == nil || e.store == nil {
		return 0, nil
	}
	return e.store.AckKind(kind, by)
}
