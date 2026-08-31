package printer

import (
	"bytes"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// #3059 — `printers.cut_type` phải QUYẾT ĐỊNH được lệnh cắt.
//
// Trước bản này cột đó được đồng bộ từ Cloud, hiện trên màn cấu hình, người lắp
// máy chọn giá trị cho nó — và không chỗ nào đọc. Đo được: năm chỗ nhắc `CutType`
// trong cây Go đều là khai struct, gán mặc định, nhận từ feed. Lệnh cắt luôn là
// hằng số cứng `ESC d 3`.
//
// Hệ quả thật: `none` (máy có dao tự động, hoặc không có dao) vẫn bị gửi lệnh
// cắt — thừa một tờ trắng mỗi lượt, hoặc firmware rẻ in nguyên chuỗi escape lên
// tờ của khách sau. `partial` (quán muốn giấy còn dính để xé theo thứ tự) vẫn
// bị cắt rời.
//
// Bài này đo BYTE, không đo tên chế độ: tên có thể đúng mà chuỗi phát ra vẫn
// sai, và tờ giấy chỉ biết byte.
func finishBytes(t *testing.T, p Profile) []byte {
	t.Helper()

	e := escpos.New()
	e.Finish(p.FinishingSpec())

	return bytes.TrimPrefix(e.Bytes(), escpos.Init)
}

func TestCutTypeDrivesTheCutCommand(t *testing.T) {
	cases := []struct {
		cutType string
		want    []byte
		why     string
	}{
		{
			cutType: "full",
			want:    escpos.Cut,
			why:     "ESC d 3 — hành vi hôm nay, và cả ba máy production đang ở giá trị này",
		},
		{
			cutType: "partial",
			want:    escpos.PartialCut,
			why: "ESC d 2, KHÔNG phải GS V 1: máy chưa ai mô tả mặc định là phương ngữ " +
				"Star, mà Star lờ GS V (#438) — chọn nhầm sẽ biến cắt-dở thành không-cắt",
		},
	}

	for _, tc := range cases {
		t.Run(tc.cutType, func(t *testing.T) {
			got := finishBytes(t, UndescribedProfileForCut(tc.cutType))
			if !bytes.Contains(got, tc.want) {
				t.Fatalf("cut_type=%q không phát %v (%s); phát ra %v", tc.cutType, tc.want, tc.why, got)
			}
		})
	}
}

// Ca đắt nhất của cả bài: `none` phải KHÔNG có byte cắt nào.
//
// Đây là lý do P-36 tồn tại — một máy dao tự động nhận thêm lệnh cắt sẽ nhả một
// tờ trắng mỗi lượt, và một máy chỉ có thanh xé sẽ in chuỗi escape thành rác.
// Kiểm bằng cách quét TỪNG lệnh cắt đã biết, không chỉ lệnh mà nhánh này định
// tránh: một bản sửa hỏng rất dễ đổi từ ESC d sang GS V thay vì bỏ hẳn.
func TestCutTypeNoneEmitsNoCutCommandAtAll(t *testing.T) {
	got := finishBytes(t, UndescribedProfileForCut("none"))

	for name, seq := range map[string][]byte{
		"ESC d 3 (Cut)":        escpos.Cut,
		"ESC d 2 (PartialCut)": escpos.PartialCut,
		"GS V 0":               {0x1D, 0x56, 0x00},
		"GS V 1":               {0x1D, 0x56, 0x01},
	} {
		if bytes.Contains(got, seq) {
			t.Fatalf("cut_type=none vẫn phát %s — máy có dao tự động sẽ nhả một tờ trắng mỗi lượt", name)
		}
	}

	// Vẫn phải ĐẨY giấy: không feed thì mấy dòng cuối nằm trong máy và người
	// vận hành xé qua chính dòng tổng tiền.
	if len(got) == 0 {
		t.Fatal("cut_type=none không phát gì cả — giấy phải được đẩy ra khỏi đầu in")
	}
}

// Giá trị lạ KHÔNG được làm quán mất lệnh cắt.
//
// Cột này đồng bộ từ Cloud; một giá trị mới thêm ở đầu kia mà máy trạm chưa
// biết là chuyện bình thường của một fleet cài tay. Rơi về hành vi hôm nay là
// câu trả lời an toàn — giấy chưa đứt thì xé được, còn đoán bừa một phương ngữ
// thì không sửa được.
func TestUnknownCutTypeFallsBackToTodaysBehaviour(t *testing.T) {
	for _, cutType := range []string{"", "FULL", "half", "tear_bar", "đầy đủ"} {
		got := finishBytes(t, UndescribedProfileForCut(cutType))
		if !bytes.Contains(got, escpos.Cut) {
			t.Fatalf("cut_type=%q làm mất lệnh cắt — giá trị lạ phải rơi về ESC d 3", cutType)
		}
	}
}

// Profile THẬT thắng cut_type.
//
// `model_profile` biết máy nói tiếng nào; `cut_type` chỉ nói quán muốn gì. Một
// Epson đã khai `gs_v_partial` mà bị `cut_type=full` kéo về ESC d 3 sẽ THÔI CẮT
// — đúng lỗi #1966, chỉ đến từ hướng ngược lại.
func TestConfiguredProfileBeatsCutType(t *testing.T) {
	raw := `{"preset":"epson_tm_i","finishing":{"cut":{"mode":"gs_v_partial","feed_before_cut":4}}}`

	p := ProfileForRow(raw, "full")
	if !p.Configured {
		t.Fatal("profile khai tường minh mà không được đánh dấu Configured")
	}
	if p.Finishing.Cut.Mode != CutGsVPartial {
		t.Fatalf("cut_type ghi đè profile thật: mode=%q", p.Finishing.Cut.Mode)
	}
}

// Và chiều ngược lại: KHÔNG có profile thì cut_type mới được quyết định.
//
// Ba hình dạng `{}`, `null`, JSON hỏng đều là "không ai mô tả máy" — phân biệt
// mà #1965 phải trả giá mới có. Cả ba phải rơi về cut_type, nếu không thì bản
// vá này chỉ chạy cho đúng một trong ba và im lặng ở hai cái kia.
func TestUndescribedProfileDefersToCutType(t *testing.T) {
	for _, raw := range []string{"", "{}", "null", "{khong-phai-json"} {
		p := ProfileForRow(raw, "none")
		if p.Finishing.Cut.Mode != CutNone {
			t.Fatalf("model_profile=%q không nhường cho cut_type: mode=%q", raw, p.Finishing.Cut.Mode)
		}
	}
}
