package handler

import (
	"os"
	"strings"
	"testing"
)

// #2535 B2 — recorder nào ĐÓNG ĐƠN tại chỗ thì phải tự bắn lượt in.
//
// Đơn đóng cục bộ ở đây (giống kiosk / counter-pay) nên hook sync-down
// `onOrderPaid` không bao giờ chạy cho nó. `card_terminal_recorder.go` đã học bài
// này bằng máu và ghi lại nguyên văn: *"Missing this call was why a P400 capture
// closed the order but never printed anything."* Recorder tiền mặt thì chưa bao
// giờ được sửa — quán bật `auto_print_bill` thu bằng 釣銭機 không ra tờ nào, và
// đường in tay cũng mất (#2535 B1), nên **không tờ giấy nào tồn tại**.
//
// ## Vì sao test này QUÉT MÃ NGUỒN, và nó KHÔNG đo được gì hơn thế
//
// Nó ghim SỰ TỒN TẠI CỦA CHỖ GỌI, không ghim hành vi in. Một bài test hành vi
// cần máy in giả + spool + cấu hình `auto_print_bill`, tức dựng lại gần hết
// tầng print để kết luận đúng một điều mà một dòng gọi đã nói.
//
// Đánh đổi phải nói thẳng: test này ĐỎ nếu ai đó dời lượt gọi sang một file
// khác (đúng cách), và XANH nếu lượt gọi còn đó nhưng in hỏng. Nó là rào chống
// **quên**, không phải bằng chứng in được — cùng vai với
// `alert_wiring_test.go`, nơi lý lẽ "test đơn vị của một hạ tầng không bao giờ
// phát hiện được rằng không ai dùng nó" đã được viết ra.
func TestPeripheralRecordersFireAutoPrintOnLocalClose(t *testing.T) {
	for _, file := range []string{
		"cash_changer_recorder.go",
		"card_terminal_recorder.go",
	} {
		src, err := os.ReadFile(file)
		if err != nil {
			t.Fatalf("read %s: %v", file, err)
		}

		body := string(src)

		// Nhánh đóng đơn: cả hai recorder dùng cùng một câu chuyển trạng thái.
		if !strings.Contains(body, `transitionOrderStatus(p.OrderID, "closed")`) {
			t.Fatalf("%s: không tìm thấy nhánh đóng đơn — test này đã lạc chỗ, đọc lại nó", file)
		}

		if !strings.Contains(body, "handleLocalPaymentAutoPrint(p.OrderID") {
			t.Errorf("%s: đóng đơn tại chỗ mà KHÔNG gọi handleLocalPaymentAutoPrint.\n"+
				"Hook sync-down onOrderPaid không chạy cho đơn đóng cục bộ, nên recorder này "+
				"tự sở hữu lượt in. Thiếu nó = quán bật auto_print_bill vẫn không ra giấy (#2535 B2).", file)
		}
	}
}
