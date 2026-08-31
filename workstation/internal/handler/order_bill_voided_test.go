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
	"github.com/dxs-platform/workstation-app/internal/service"
)

// #3044 lỗ thứ TƯ và thứ NĂM — phiếu order và tờ 「PHAN CON LAI」.
//
// Bản vá đầu của #3044 lọc dòng đã huỷ ở `paidSlipInputs`, chỗ dựng danh sách
// món cho biên lai và hoá đơn đỏ. Nó đúng và đủ cho hai loại phiếu ấy. Nhưng
// issue liệt kê phạm vi là **ba** loại — biên lai · hoá đơn đỏ · phiếu order —
// và phiếu order đi đường riêng: `handleLANPrintOrderBill` đọc đơn rồi đẩy
// thẳng `o.Items` vào `FormatRunnerTicket`, không hề đi qua `paidSlipInputs`
// (nó cũng bỏ qua `normalizeOrderForPrint`, xem chú thích #2170 trong handler).
//
// Nên sau bản vá đầu, tờ giấy KHÁCH cầm đã sạch, còn tờ mà thu ngân in ra để
// khách xem trước khi trả tiền thì vẫn liệt kê món khách không mua — cùng một
// mâu thuẫn "dòng cộng lại ¥2.500, tổng in ¥1.250", chỉ đổi tờ.
//
// Bài này đo BYTE thật sự đi ra máy in, không đo hàm trung gian: đường này
// không có hàm nào để gọi riêng, và một bài chỉ gọi `nonVoidedItems` sẽ xanh
// ngay cả khi handler quên gọi nó.
func TestLANPrintOrderBill_DropsVoidedItems(t *testing.T) {
	t.Run("template", func(t *testing.T) { runOrderBillVoidedCase(t, true) })
	t.Run("formatter", func(t *testing.T) { runOrderBillVoidedCase(t, false) })
}

// Handler dựng phiếu qua HAI đường và cả hai đều nhận danh sách món riêng:
// `NewRunnerRenderData` (tầng template, #1914) và `FormatRunnerTicket`
// (formatter cũ, dùng khi seam tắt hoặc render lỗi). Sửa một đường mà quên
// đường kia thì quán nào bật template vẫn in dòng huỷ.
//
// Đã đo bằng đột biến: một bài chỉ chạy với `printTemplates == nil` KHÔNG kêu
// khi tôi trả `NewRunnerRenderData` về `o.Items` — nó rơi vào nhánh formatter
// nên không bao giờ chạm dòng bị hỏng. Đó là lý do bài này chạy hai lượt.
func runOrderBillVoidedCase(t *testing.T, withTemplates bool) {
	t.Helper()
	s := newLANPrintTestServer(t)
	if withTemplates {
		s.printTemplates = service.NewPrintTemplateStore(s.db)
	} else {
		s.printTemplates = nil
	}

	// Hai dòng, đúng hình dạng ca 人形町店 `ORD-2026-0651`: khách gọi một tô,
	// không thích, quán huỷ rồi làm lại tô khác.
	//
	// Hai SKU KHÁC nhau chứ không phải hai dòng cùng SKU: `AddItems` gộp dòng
	// trùng SKU thành một dòng số lượng 2, nên ca "hai dòng" không dựng được theo
	// cách kia. Tên khác nhau còn cho phép đo CẢ HAI chiều trong một lượt — bỏ
	// đúng thứ phải bỏ, và không đụng vào thứ không được bỏ.
	o := seedOrderWithVoidedLine(t, s)

	bill := string(captureOrderBillBytes(t, s, o.ID))

	if strings.Contains(bill, "VOIDED-LINE") {
		t.Fatalf("dòng đã HUỶ vẫn nằm trên phiếu order — đúng lỗi #3044, chỉ khác tờ\n---\n%s", bill)
	}
	if !strings.Contains(bill, "LIVE-LINE") {
		t.Fatalf("phép lọc quá tay: món khách THẬT SỰ mua biến mất khỏi phiếu\n---\n%s", bill)
	}
}

// captureOrderBillBytes gắn một "máy in" TCP loopback vào vai máy in biên lai,
// gọi handler phiếu order, và trả về mọi byte máy in nhận được.
//
// Loopback qua được bộ kiểm địa chỉ máy in (127.0.0.1 nằm trong dải riêng được
// phép), nên đường Connect → Print → Disconnect chạy thật chứ không bị giả lập.
func captureOrderBillBytes(t *testing.T, s *Server, orderID string) []byte {
	t.Helper()

	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	defer ln.Close()

	done := make(chan []byte, 1)
	go func() {
		conn, err := ln.Accept()
		if err != nil {
			done <- nil
			return
		}
		b, _ := io.ReadAll(conn)
		conn.Close()
		done <- b
	}()

	if _, err := s.devices.AddPrinter(
		"receipt",
		[]printer.DeviceType{printer.TypeReceiptPrinter},
		printer.ConnNetwork,
		ln.Addr().String(),
		printer.PrinterConfig{PaperWidth: 80},
	); err != nil {
		t.Fatalf("add printer: %v", err)
	}

	w := httptest.NewRecorder()
	req := stubAuth(httptest.NewRequest(http.MethodPost, "/api/lan/print/order-bill",
		bytes.NewBufferString(`{"order_id":"`+orderID+`"}`)))
	s.handleLANPrintOrderBill(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d (%s)", w.Code, w.Body.String())
	}

	select {
	case b := <-done:
		return b
	case <-time.After(3 * time.Second):
		t.Fatalf("hết giờ chờ byte từ máy in")
		return nil
	}
}

// seedOrderWithVoidedLine dựng đúng hình dạng ca 人形町店 `ORD-2026-0651`: một
// đơn hai dòng, dòng đầu bị huỷ 「Khách không thích」, dòng sau là món khách
// thật sự trả tiền.
//
// Hai SKU KHÁC nhau chứ không phải hai dòng cùng SKU: `AddItems` gộp dòng trùng
// SKU thành một dòng số lượng 2, nên ca "hai dòng" không dựng được theo cách kia.
//
// Tên đặt bằng ASCII (`VOIDED-LINE` / `LIVE-LINE`) vì byte đi tới máy in đã mã
// hoá Shift_JIS: so chuỗi UTF-8 trên byte thô KHÔNG BAO GIỜ khớp, và nó không
// khớp theo chiều IM LẶNG — bài test sẽ "chứng minh" mọi dòng đều vắng mặt, kể
// cả dòng đang có mặt. Đã dính đúng bẫy đó một lượt khi viết bài này.
func seedOrderWithVoidedLine(t *testing.T, s *Server) *service.Order {
	t.Helper()

	// `seedSpotOrder` mới là chỗ tạo sản phẩm `p1`, nên SKU thứ hai phải chèn
	// SAU nó — chèn trước là vỡ khoá ngoại.
	o := seedSpotOrder(t, s, []string{"kitchen"})
	if _, err := s.db.Exec(`
		INSERT OR IGNORE INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('sku-void', 'p1', 'Ban khach tra lai', 'SKU-VOID', 110000, 1)`); err != nil {
		t.Fatal(err)
	}
	if _, err := s.orders.AddItems(o.ID, []service.CreateItemInput{
		{ProductSkuID: "sku-void", Quantity: 1},
	}); err != nil {
		t.Fatalf("add second item: %v", err)
	}
	o, _ = s.orders.GetByID(o.ID)
	if len(o.Items) != 2 {
		t.Fatalf("cần đúng 2 dòng để dựng ca, có %d", len(o.Items))
	}

	var voidedID string
	for _, it := range o.Items {
		if it.ProductSkuID == "sku-void" {
			voidedID = it.ID
		}
	}
	if voidedID == "" {
		t.Fatal("không tìm được dòng để huỷ")
	}
	if _, err := s.db.Exec(
		`UPDATE order_items
		 SET status = CASE WHEN id = ? THEN 'voided' ELSE status END,
		     void_reason = CASE WHEN id = ? THEN 'Khách không thích' ELSE void_reason END,
		     menu_item_name = CASE WHEN id = ? THEN 'VOIDED-LINE' ELSE 'LIVE-LINE' END
		 WHERE customer_order_id = ?`,
		voidedID, voidedID, voidedID, o.ID,
	); err != nil {
		t.Fatalf("void item: %v", err)
	}
	o, _ = s.orders.GetByID(o.ID)

	return o
}
