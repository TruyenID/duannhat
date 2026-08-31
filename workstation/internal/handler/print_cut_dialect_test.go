package handler

import (
	"bytes"
	"io"
	"net"
	"sync"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// #3046 — PHƯƠNG NGỮ lệnh cắt phải đi theo `printers.model_profile`, đo trên
// BYTE thật đã ra khỏi socket, qua đúng đường in của phiếu 追加商品.
//
// Vì sao bài này tồn tại, và vì sao nó đo ở tầng này:
//
// Ở 人形町店 hai phiếu 追加商品 của hai đơn khác nhau ra liền một dải giấy. Byte
// của phiếu thì đúng — `FormatDeltaQRTicket` kết thúc bằng đúng MỘT `ESC d 3`,
// không byte thừa. Nhưng `ESC d 3` chỉ là lệnh CẮT trong phương ngữ StarPRNT;
// trên một máy ESC/POS (Epson) nó là "in và đẩy 3 dòng" và **không cắt gì**.
// Cả ba máy in của quán có `model_profile` NULL ⇒ chưa ai mô tả ⇒ renderer phát
// `ESC d 3` cho mọi máy.
//
// Cơ chế chọn phương ngữ theo hồ sơ máy đã có từ #1950. Thứ CHƯA có là một phép
// đo chứng minh nó còn sống ở đường in thật — `PrintRenderProfileFor` chỉ gắn
// `Finishing` khi `Configured`, `finishBuiltinSlip` cắt đuôi rồi nối lại, và cả
// hai nhánh (formatter cũ / renderer template) phải ra cùng một kết luận. Bài
// này ghim cả chuỗi đó, để lần sau ai đó đổi một mắt xích thì đỏ ở đây chứ
// không đỏ trên giấy của quán.
//
// Bài này KHÔNG khẳng định máy ở 人形町店 là Epson — đó là phép đo của người,
// đọc nhãn máy. Nó khẳng định: khai đúng hồ sơ thì byte đúng đi ra.

var (
	escDFullCut   = []byte{0x1B, 0x64, 0x33} // ESC d 3 — StarPRNT
	escDPartCut   = []byte{0x1B, 0x64, 0x32} // ESC d 2 — StarPRNT, cắt dở
	gsVFullCutSeq = []byte{0x1D, 0x56, 0x00} // GS V 0  — ESC/POS
	gsVPartCutSeq = []byte{0x1D, 0x56, 0x01} // GS V 1  — ESC/POS, cắt dở
)

// cutDialectSink là máy in TCP giả: nhận nhiều kết nối liên tiếp, nối mọi byte.
type cutDialectSink struct {
	ln  net.Listener
	mu  sync.Mutex
	all []byte
	wg  sync.WaitGroup
}

func newCutDialectSink(t *testing.T) *cutDialectSink {
	t.Helper()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	s := &cutDialectSink{ln: ln}
	s.wg.Add(1)
	go func() {
		defer s.wg.Done()
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			b, _ := io.ReadAll(c)
			c.Close()
			s.mu.Lock()
			s.all = append(s.all, b...)
			s.mu.Unlock()
		}
	}()
	t.Cleanup(func() { ln.Close(); s.wg.Wait() })
	return s
}

func (s *cutDialectSink) bytesReceived() []byte {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]byte(nil), s.all...)
}

// fireDeltaSlipWithProfile bắn MỘT đơn qua đúng đường fire, với `model_profile`
// và `cut_type` khai sẵn trên hàng `printers`, rồi trả về byte máy in HALL nhận
// được (phiếu 追加商品 in ra đó khi quán có máy in hall riêng).
func fireDeltaSlipWithProfile(t *testing.T, modelProfile, cutType string) []byte {
	t.Helper()

	s := newFireTestServer(t)
	kitchen := newCutDialectSink(t)
	hall := newCutDialectSink(t)

	if _, err := s.devices.AddPrinter("kitchen", []printer.DeviceType{printer.TypeKitchenPrinter},
		printer.ConnNetwork, kitchen.ln.Addr().String(), printer.PrinterConfig{PaperWidth: 80}); err != nil {
		t.Fatalf("add kitchen printer: %v", err)
	}
	hallPrinter, err := s.devices.AddPrinter("hall", []printer.DeviceType{printer.TypeHallPrinter},
		printer.ConnNetwork, hall.ln.Addr().String(), printer.PrinterConfig{PaperWidth: 80, CutType: cutType})
	if err != nil {
		t.Fatalf("add hall printer: %v", err)
	}

	// Khai hồ sơ máy ĐÚNG CHỖ Cloud/thuật sĩ ghi: cột `printers.model_profile`.
	// Rồi Reload để đi qua `readDevices → ProfileForRow`, tức đúng đường mà một
	// máy trạm khởi động lại sẽ đi — không phải SetProfile trong bộ nhớ.
	if _, err := s.db.Exec(
		`UPDATE printers SET model_profile = ? WHERE id = ?`,
		nullOrString(modelProfile), hallPrinter.ID(),
	); err != nil {
		t.Fatalf("khai model_profile: %v", err)
	}
	if err := s.devices.Reload(); err != nil {
		t.Fatalf("reload devices: %v", err)
	}

	o := seedFireOrder(t, s, "dine_in", []string{"kitchen"})
	if fired, _, ferrs := s.fireKitchenForOrder(o, "ja"); fired < 1 {
		t.Fatalf("fire: %+v", ferrs)
	}

	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		if b := hall.bytesReceived(); len(b) > 0 {
			time.Sleep(100 * time.Millisecond)
			return hall.bytesReceived()
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatal("máy in hall không nhận được byte nào")
	return nil
}

// nullOrString giữ NULL là NULL — "" và NULL là hai trạng thái khác nhau đối với
// `ParseProfile` chỉ ở chỗ chúng phải cho CÙNG một câu trả lời, và bài này phải
// đo được cái NULL thật.
func nullOrString(s string) any {
	if s == "" {
		return nil
	}
	return s
}

func TestDeltaSlipCutDialectFollowsModelProfile(t *testing.T) {
	cases := []struct {
		name         string
		modelProfile string
		cutType      string
		want         []byte
		wantName     string
		forbid       map[string][]byte
		why          string
	}{
		{
			name:         "model_profile NULL — trạng thái của cả ba máy ở 人形町店",
			modelProfile: "",
			cutType:      "full",
			want:         escDFullCut,
			wantName:     "ESC d 3",
			forbid:       map[string][]byte{"GS V 0": gsVFullCutSeq, "GS V 1": gsVPartCutSeq},
			why: "chưa ai mô tả máy ⇒ giữ nguyên byte của hôm qua (#1965). " +
				"Đoán GS V trên một máy Star là phiếu KHÔNG BAO GIỜ đứt và không một lời báo nào",
		},
		{
			name:         "khai preset epson_tm_i",
			modelProfile: `{"preset":"epson_tm_i"}`,
			cutType:      "full",
			want:         gsVPartCutSeq,
			wantName:     "GS V 1",
			forbid:       map[string][]byte{"ESC d 3": escDFullCut, "ESC d 2": escDPartCut},
			why: "đây là bản vá cho một quán dùng máy ESC/POS: khai máy thì phiếu " +
				"nhận GS V, không còn ESC d — thứ mà Epson chỉ hiểu là đẩy giấy",
		},
		{
			name:         "khai thẳng gs_v_full",
			modelProfile: `{"finishing":{"cut":{"mode":"gs_v_full","feed_before_cut":4}}}`,
			cutType:      "full",
			want:         gsVFullCutSeq,
			wantName:     "GS V 0",
			forbid:       map[string][]byte{"ESC d 3": escDFullCut, "ESC d 2": escDPartCut},
			why:          "khai từng trường, không qua preset — cùng một kết quả",
		},
		{
			name:         "khai esc_d_partial — chế độ mà normalised() từng NUỐT",
			modelProfile: `{"finishing":{"cut":{"mode":"esc_d_partial","feed_before_cut":3}}}`,
			cutType:      "partial",
			want:         escDPartCut,
			wantName:     "ESC d 2",
			forbid:       map[string][]byte{"ESC d 3": escDFullCut, "GS V 1": gsVPartCutSeq},
			why: "#3059 thêm `esc_d_partial` vào hằng số và vào Finish nhưng KHÔNG thêm vào " +
				"danh sách trắng của `normalised()`, nên hồ sơ mang chế độ đó parse ra `none` " +
				"= THÔI CẮT. Ca này là ca duy nhất đi qua đúng lỗ đó: khai chế độ vào " +
				"`printers.model_profile` rồi đọc lại — gỡ bản vá ra thì phiếu kết thúc bằng " +
				"ba dòng trắng và bài này đỏ",
		},
		{
			name:         "khai star_mcprint",
			modelProfile: `{"preset":"star_mcprint"}`,
			cutType:      "full",
			want:         escDFullCut,
			wantName:     "ESC d 3",
			forbid:       map[string][]byte{"GS V 0": gsVFullCutSeq, "GS V 1": gsVPartCutSeq},
			why: "chiều ngược lại, và nó là lý do KHÔNG được đổi mặc định sang GS V: " +
				"fleet đang có máy Star thật, đổi mù sẽ làm hỏng chỗ đang chạy đúng",
		},
	}

	for _, tc := range cases {
		tc := tc
		t.Run(tc.name, func(t *testing.T) {
			got := fireDeltaSlipWithProfile(t, tc.modelProfile, tc.cutType)

			if !bytes.Contains(got, tc.want) {
				t.Fatalf("phiếu 追加商品 không mang %s (%s) — %d byte đã ra socket",
					tc.wantName, tc.why, len(got))
			}
			for name, seq := range tc.forbid {
				if bytes.Contains(got, seq) {
					t.Fatalf("phiếu 追加商品 vẫn mang %s cùng với %s — hai phương ngữ trên một tờ giấy",
						name, tc.wantName)
				}
			}
			// Lệnh cắt phải là thứ CUỐI CÙNG. Một byte in nào phía sau nó sẽ rơi
			// sang tờ của khách kế tiếp — đúng hình dạng mà #3046 mô tả.
			if !bytes.HasSuffix(got, tc.want) {
				t.Fatalf("%s không nằm cuối luồng byte: %d byte phía sau nó",
					tc.wantName, len(got)-bytes.LastIndex(got, tc.want)-len(tc.want))
			}
		})
	}
}

// Phiếu 追加商品 phải mang ĐÚNG MỘT lệnh cắt.
//
// Không thừa (một lệnh nữa là một tờ trắng mỗi lượt), không thiếu (phiếu của
// hai bàn dính nhau — chính hiện trường #3046).
func TestDeltaSlipCarriesExactlyOneCut(t *testing.T) {
	got := fireDeltaSlipWithProfile(t, "", "full")

	if n := bytes.Count(got, escDFullCut); n != 1 {
		t.Fatalf("phiếu 追加商品 mang %d lệnh cắt, phải đúng 1", n)
	}
}

var _ = service.Item{}
