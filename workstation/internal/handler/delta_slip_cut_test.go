package handler

import (
	"bytes"
	"io"
	"net"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// #3046 — hai phiếu 追加商品 của HAI ĐƠN ra liền một dải giấy, không có nhát cắt
// giữa chúng (ảnh từ 人形町店, 2026-08-16).
//
// # Vì sao bài này bắt BYTE chứ không đọc mã
//
// Issue đã loại trừ hai giả thuyết bằng cách đọc mã — "thiếu lệnh cắt" (sai:
// `formatBillTicket` có `FullCut()` ngay sau QR) và "máy Star bỏ qua GS V" (sai:
// ba máy của quán đều `model_profile = NULL` nên renderer phát `ESC d 3`, đúng
// lệnh StarPRNT hiểu). Giả thuyết thứ ba, "feed trước khi cắt quá ít", cũng
// không giải thích được vì sao RIÊNG phiếu này lỗi.
//
// Đọc thêm một vòng nữa là dựng giả thuyết trên giả thuyết. Tờ giấy chỉ biết
// byte, nên bài này dựng đúng cảnh của quán — máy hall RIÊNG máy bếp — bắn hai
// đơn liên tiếp, rồi đếm.
//
// # Cảnh phải đúng, nếu không phép đo vô nghĩa
//
// `printKitchenAndRunnerOn` gửi phiếu delta sang máy HALL, và chỉ rơi về máy bếp
// khi quán không có máy hall (`rp == nil || cùng địa chỉ`). 人形町店 có cả ba máy
// (Hall · Kitchen · Casher), nên dải giấy trong ảnh là của MÁY HALL. Dựng chung
// một máy cho cả hai vai sẽ trộn phiếu bếp vào luồng và làm phép đếm sai.
func captureHallStream(t *testing.T, fires int) []byte {
	t.Helper()

	s := newFireTestServer(t)

	hall, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen hall: %v", err)
	}
	defer hall.Close()

	kitchen, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen kitchen: %v", err)
	}
	defer kitchen.Close()

	drain := func(ln net.Listener, out chan<- []byte) {
		var all []byte
		deadline := time.Now().Add(4 * time.Second)
		for time.Now().Before(deadline) {
			_ = ln.(*net.TCPListener).SetDeadline(time.Now().Add(400 * time.Millisecond))
			conn, err := ln.Accept()
			if err != nil {
				continue
			}
			b, _ := io.ReadAll(conn)
			conn.Close()
			all = append(all, b...)
		}
		out <- all
	}

	hallBytes := make(chan []byte, 1)
	kitchenBytes := make(chan []byte, 1)
	go drain(hall, hallBytes)
	go drain(kitchen, kitchenBytes)

	if _, err := s.devices.AddPrinter("kitchen", []printer.DeviceType{printer.TypeKitchenPrinter},
		printer.ConnNetwork, kitchen.Addr().String(), printer.PrinterConfig{PaperWidth: 80}); err != nil {
		t.Fatalf("add kitchen printer: %v", err)
	}
	if _, err := s.devices.AddPrinter("hall", []printer.DeviceType{printer.TypeHallPrinter},
		printer.ConnNetwork, hall.Addr().String(), printer.PrinterConfig{PaperWidth: 80}); err != nil {
		t.Fatalf("add hall printer: %v", err)
	}

	// Hai ĐƠN KHÁC NHAU, bắn liên tiếp — đúng thứ trong ảnh (伝票 0651 rồi 0653),
	// không phải hai lượt bắn của cùng một đơn.
	for i := 0; i < fires; i++ {
		o := seedFireOrder(t, s, "dine_in", []string{"kitchen"})
		if fired, _, ferrs := s.fireKitchenForOrder(o, "ja"); fired < 1 {
			t.Fatalf("lượt bắn %d không bắn được món nào: %+v", i+1, ferrs)
		}
	}

	raw := <-hallBytes
	<-kitchenBytes

	return raw
}

// ESC d 3 — lệnh cắt mà renderer phát cho máy chưa ai mô tả (`model_profile`
// NULL), tức đúng thứ ba máy của 人形町店 nhận.
//
// Byte thứ ba là **0x33**, ký tự ASCII '3', KHÔNG phải 0x03. Đó là lựa chọn có
// chủ đích của #438 cho Star mC-Print3 và được `escpos.TestCutCommandsAreStarPRNT`
// ghim. Bản đầu của bài này viết 0x03 và đếm ra **0 nhát cắt** — tức nó suýt
// "chứng minh" một lỗi không tồn tại. Dùng thẳng hằng số của encoder thay vì
// chép lại byte, để mốc đo không thể lệch khỏi thứ đang được phát.
var escD3 = escpos.Cut

func TestDeltaSlips_EachOrderGetsItsOwnCut(t *testing.T) {
	raw := captureHallStream(t, 2)

	if len(raw) == 0 {
		t.Fatal("máy hall không nhận được byte nào — bài này không đo được gì")
	}

	// Câu hỏi 1 của issue: mỗi phiếu có đúng MỘT lệnh cắt, hay hai phiếu bị nối
	// vào một job?
	cuts := bytes.Count(raw, escD3)
	if cuts != 2 {
		t.Errorf("hai đơn ⇒ phải có 2 nhát cắt trên máy hall, đo được %d.\n"+
			"Thiếu một nhát nghĩa là hai phiếu của HAI BÀN dính nhau — nhân viên "+
			"phải tự xé và rất dễ đưa nhầm bàn (#3046).", cuts)
	}

	// Câu hỏi 3: nhát cắt phải nằm SAU nội dung của phiếu, không nằm giữa.
	// Đếm không đủ trả lời điều đó — một job phát hai lệnh cắt liền nhau ở cuối
	// vẫn cho ra `cuts == 2` mà giấy thì dính.
	if idx := bytes.Index(raw, escD3); idx >= 0 {
		if bytes.HasPrefix(raw[idx+len(escD3):], escD3) {
			t.Error("hai lệnh cắt phát liền nhau — nhát thứ hai không có nội dung nào ở giữa")
		}
	}
}

// Bài này ĐO, không khẳng định: in ra hình dạng luồng byte để lần điều tra sau
// không phải dựng lại harness.
//
// Chạy: go test ./internal/handler/ -run TestDeltaSlips_DumpShape -v
func TestDeltaSlips_DumpShape(t *testing.T) {
	raw := captureHallStream(t, 2)

	t.Logf("tổng %d byte · %d lệnh cắt ESC d 3 · %d QR (GS ( k)",
		len(raw), bytes.Count(raw, escD3), bytes.Count(raw, []byte{0x1D, 0x28, 0x6B}))

	for i, seg := range bytes.SplitAfter(raw, escD3) {
		if len(bytes.TrimSpace(seg)) == 0 {
			continue
		}
		t.Logf("  đoạn %d: %d byte, kết thúc bằng cắt=%v", i+1, len(seg), bytes.HasSuffix(seg, escD3))
	}

}
