package handler

import (
	"bytes"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printer"
)

// #3141 — NGÔN NGỮ của quán phải tới được phiếu BẾP, và điều đó phải được CANH.
//
// # Vì sao bài này tồn tại
//
// Bản vá của #3141 đặt `Locale` MỘT LẦN lên `printConfig` dùng chung trong
// `handlePrintOrder`, thay cho hình dạng cũ: một dòng bên trong `case "hall"`,
// một tham số bên trong `case "receipt"`, và `case "kitchen"` **không có gì** —
// nên phiếu bếp render với locale rỗng, mà `printLabelsFor("")` phân giải thành
// `ja`. Quán để English nhận hall + hoá đơn tiếng Anh và phiếu bếp tiếng Nhật.
//
// Bản vá đúng và đã lên `main`. Nhưng đo lại ngày 2026-08-17: **xoá dòng
// `Locale:` khỏi config chung thì KHÔNG bài nào đỏ** — cả gói `internal/handler`
// vẫn xanh. Các bài locale sẵn có canh BỘ PHÂN GIẢI (`printLabelLocale`,
// `orderPrintLocale`), không canh việc giá trị nó trả về có được GẮN vào config
// hay không. Tức bản vá đang sống nhờ trí nhớ, đúng thứ mà chính comment của nó
// nói là không được phép: *"hai trong ba nhánh nhớ không phải vá nhánh thứ ba,
// đó là hình dạng mời gọi bỏ sót"*.
//
// # Bài này đo BYTE, không đo cấu hình
//
// Đọc `printConfig.Locale` bằng phản chiếu sẽ xanh kể cả khi nhánh kitchen
// không truyền config đó xuống formatter. Tờ giấy chỉ biết byte, nên bài đi qua
// chính `handlePrintOrder` rồi đọc thứ máy in nhận được.
func TestPrintOrderKitchen_UsesShopLocale(t *testing.T) {
	s := newFireTestServer(t)

	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	defer ln.Close()

	received := make(chan []byte, 1)
	go func() {
		var all []byte
		deadline := time.Now().Add(3 * time.Second)
		for time.Now().Before(deadline) {
			_ = ln.(*net.TCPListener).SetDeadline(time.Now().Add(500 * time.Millisecond))
			conn, err := ln.Accept()
			if err != nil {
				continue
			}
			b, _ := io.ReadAll(conn)
			conn.Close()
			all = append(all, b...)
		}
		received <- all
	}()

	if _, err := s.devices.AddPrinter("kitchen", []printer.DeviceType{printer.TypeKitchenPrinter},
		printer.ConnNetwork, ln.Addr().String(), printer.PrinterConfig{PaperWidth: 80}); err != nil {
		t.Fatalf("add kitchen printer: %v", err)
	}

	// Quán để tiếng Việt. Nhãn Việt được gấp về ASCII cho Shift_JIS (xem
	// `print_kitchen_bill_i18n.go`), nên chúng hiện nguyên văn trong luồng byte
	// — thứ làm bài này đọc được mà không phải giải mã.
	if _, err := s.db.Exec(`INSERT INTO shop_settings (key, value) VALUES ('print_label_locale','vi')`); err != nil {
		t.Fatalf("seed locale: %v", err)
	}

	o := seedFireOrder(t, s, "dine_in", []string{"kitchen"})

	req := httptest.NewRequest(http.MethodPost, "/api/orders/"+o.ID+"/print",
		strings.NewReader(`{"type":"kitchen"}`))
	req.SetPathValue("id", o.ID)
	req.Header.Set("Content-Type", "application/json")
	rr := httptest.NewRecorder()

	s.handlePrintOrder(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("in phiếu bếp trả %d: %s", rr.Code, rr.Body.String())
	}

	raw := <-received
	if len(raw) == 0 {
		t.Fatal("máy in không nhận được byte nào — bài này không đo được gì")
	}

	// Mốc đo là NHÃN CỘT, không phải tiêu đề.
	//
	// Bản đầu của bài này tra `TitleKitchen` ("PHIEU BEP") và đỏ oan: phiếu bếp
	// KHÔNG in tiêu đề đó — nó đi thẳng vào bảng món. Ghi lại vì một mốc đo
	// không bao giờ xuất hiện sẽ làm bài đỏ mãi và bị người sau xoá, còn mốc
	// ngược lại (luôn xuất hiện) thì làm bài xanh mãi và vô dụng.
	for _, want := range []string{"Cach dat", "So phieu", "San pham", "Tong (da VAT)"} {
		if !bytes.Contains(raw, []byte(want)) {
			t.Errorf("phiếu bếp thiếu nhãn tiếng Việt %q của quán.\n"+
				"`Locale` có còn nằm trên `printConfig` dùng chung trong handlePrintOrder không?", want)
		}
	}

	// Chiều PHỦ ĐỊNH, và nó là vế bắt được lỗi thật: locale rỗng phân giải
	// thành `ja`, nên nhãn tiếng Nhật xuất hiện đúng khi bản vá bị gỡ. Thiếu vế
	// này thì một tờ mang CẢ HAI bộ nhãn vẫn đi qua.
	for _, unwanted := range []string{"提供", "伝票番号", "店内"} {
		if bytes.Contains(raw, []byte(unwanted)) {
			t.Errorf("phiếu bếp in nhãn tiếng Nhật %q trong khi quán cấu hình `vi` — "+
				"đúng triệu chứng #3141: locale rỗng rơi về `ja`", unwanted)
		}
	}
}
