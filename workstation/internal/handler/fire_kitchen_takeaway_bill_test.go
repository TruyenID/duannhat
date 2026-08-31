package handler

import (
	"bytes"
	"io"
	"net"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// Tờ phục vụ của đơn MANG VỀ là hoá đơn TOÀN ĐƠN, và hai bài dưới đây ghim hai
// điều kiện mà nó phải thoả — cả hai đều từng sai cùng lúc trong một bản vá.
//
// Vì sao chúng không tự lộ ra: `printKitchenAndRunnerOn` chạy một lần cho MỖI
// `printer_group`, còn mọi bài test takeaway trước đó chỉ dựng đơn MỘT nhóm.
// Một đơn một nhóm thì "in một lần mỗi nhóm" và "in một lần mỗi đơn" cho ra kết
// quả giống hệt nhau — nên lỗi chỉ hiện ở đơn có từ hai nhóm trở lên, tức đơn
// có món bếp lẫn đồ uống quầy bar. Đó là đơn bình thường ở quán.

// captureFireOnGroups dựng MỘT máy in TCP giả rồi bắn một đơn có nhiều
// printer_group qua đó, trả về mọi byte máy in nhận được.
//
// Một máy in cho mọi nhóm là CỐ Ý: khi quán chưa cấu hình máy riêng cho bar,
// dispatcher trả về cùng một máy, nên mọi tờ giấy của lượt bắn đổ vào một luồng
// và đếm được. Đó cũng đúng là cấu hình mà lỗi hai-tờ gây thiệt hại rõ nhất —
// khách ở quầy nhận cả hai tờ từ cùng một máy.
func captureFireOnGroups(t *testing.T, orderType string, groups []string, voidIndex int) (*service.Order, []byte) {
	t.Helper()

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

	// Cấp máy cho CẢ HAI nhóm, cùng trỏ vào một sink. Thiếu máy của nhóm `bar`
	// thì nhóm đó đi nhánh "KDS-only" và không in gì — lúc ấy bài test chỉ còn
	// một nhóm thật sự in, tức nó đo một cảnh KHÔNG phải cảnh cần đo, và sẽ
	// xanh kể cả khi lỗi hai-tờ còn nguyên.
	if _, err := s.devices.AddPrinter("kitchen", []printer.DeviceType{printer.TypeKitchenPrinter},
		printer.ConnNetwork, ln.Addr().String(), printer.PrinterConfig{PaperWidth: 80}); err != nil {
		t.Fatalf("add kitchen printer: %v", err)
	}
	if _, err := s.devices.AddPrinter("bar", []printer.DeviceType{printer.TypeBarPrinter},
		printer.ConnNetwork, ln.Addr().String(), printer.PrinterConfig{PaperWidth: 80}); err != nil {
		t.Fatalf("add bar printer: %v", err)
	}

	o := seedFireOrder(t, s, orderType, groups)

	if voidIndex >= 0 && voidIndex < len(o.Items) {
		if _, err := s.db.Exec(
			`UPDATE order_items SET status = ? WHERE id = ?`,
			string(service.ItemStatusVoided), o.Items[voidIndex].ID,
		); err != nil {
			t.Fatal(err)
		}
		o, _ = s.orders.GetByID(o.ID)
	}

	if fired, _, ferrs := s.fireKitchenForOrder(o, "vi"); fired < 1 {
		t.Fatalf("không bắn được món nào: %+v", ferrs)
	}

	return o, <-received
}

// countBills đếm số tờ hoá đơn toàn đơn trong luồng byte.
//
// Đếm bằng lệnh CẮT là phép đo sai — phiếu bếp cũng có lệnh cắt. Đếm bằng mã
// QR: chỉ tờ phục vụ mới mang QR chứa `orderId`.
func countBills(raw []byte, marker []byte) int {
	return bytes.Count(raw, marker)
}

func TestTakeawayFire_PrintsExactlyOneOrderBill_AcrossPrinterGroups(t *testing.T) {
	o, raw := captureFireOnGroups(t, "takeaway", []string{"kitchen", "bar"}, -1)

	if len(raw) == 0 {
		t.Fatal("máy in không nhận được byte nào")
	}

	// Mỗi tờ phục vụ mang ĐÚNG MỘT mã QR chứa `orderId` — phiếu bếp không có QR.
	// Đây là dấu hiệu chỉ tờ phục vụ mới có. Hai nhóm ⇒ hai lượt gọi `printKitchenAndRunnerOn`; nếu tờ hoá đơn
	// nằm trong lượt gọi đó mà không có cờ, nó ra HAI lần.
	n := countBills(raw, []byte("\"orderId\""))
	if n != 1 {
		t.Fatalf("đơn mang về 2 nhóm in ra %d tờ hoá đơn, phải đúng 1.\n"+
			"Mỗi tờ khai đủ tiền CẢ ĐƠN và mang một mã QR trả tiền còn dùng được, "+
			"nên khách ở quầy nhận nhiều tờ cùng đòi một khoản. (order %s)", n, o.ID)
	}
}

// KHÔNG có rào cho vế "tờ hoá đơn không in dòng đã HUỶ".
//
// Nói thẳng vì im lặng ở đây tệ hơn: tôi đã thử dựng ca đó và KHÔNG dựng được.
// `seedFireOrder` gộp mọi dòng cùng SKU, và đánh dấu huỷ bằng
// `UPDATE order_items SET status` (SQL thô) không tạo ra một dòng mà đường in
// coi là đã huỷ — đo được: cả phiếu BẾP cũng vẫn in đủ số lượng, mà phiếu bếp
// thì lọc voided qua `needsFire` từ trước bản vá này. Tức harness sai, không
// phải bản vá sai.
//
// Bản vá (`bill.Items = nonVoidedItems(o.Items)`) vẫn đúng và vẫn cần: đường
// cũ dùng `items` đã qua `needsFire` nên tự loại voided, đường mới lấy thẳng
// `o.Items` nên phải lọc tường minh. Nhưng nó đang KHÔNG có gì canh.
//
// Muốn dựng rào thật thì phải huỷ dòng qua đúng đường service (`VoidItem`),
// và dùng hai SKU khác nhau để hai dòng không gộp trên giấy.
