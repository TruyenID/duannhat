package service

import (
	"os"
	"strings"
	"testing"
)

// #2480 — hướng làm tròn phải áp lên SỐ THUẾ, ở cả hai chế độ.
//
// Bản trước tính 内税 là `gross - round(gross/(1+r))`: hướng áp lên phần NỀN,
// thuế là phần dư, nên `floor` ("làm tròn xuống") cho ra thuế CAO hơn — ngược
// hẳn nhãn cài đặt, trong đúng chế độ mà mọi chi nhánh production đang dùng.
//
// Bản Go này PHẢI khớp từng bit với OrderPricingCalculator::groupTaxFor. Cloud
// re-price đơn offline từ ảnh chụp bất biến trước khi tin tiền (#1092), nên một
// công thức lệch một yên ở đây nổi lên thành lỗi xác minh trên một lần bán vốn
// đúng — và nó hỏng ở phía TIỀN, không phải ở phía hiển thị.
func TestGroupTaxFor2480_RoundingDirectionAppliesToTax(t *testing.T) {
	cases := []struct {
		name        string
		gross, rate float64
		mode        string
		want        float64
	}{
		// 1005 @10% 内税: thuế chính xác = 1005 × 10/110 = 91,36
		{"floor cho thuế THẤP hơn", 1005, 10, "floor", 91},
		{"ceil cho thuế CAO hơn", 1005, 10, "ceil", 92},
		{"half_up bất động", 1005, 10, "round", 91},

		// Ca biên: công thức cũ cho `1 - floor(1/1,08) = 1`, tức khai TOÀN BỘ
		// giá của món ¥1 là tiền thuế.
		{"¥1 @8% floor không còn khai cả giá là thuế", 1, 8, "floor", 0},
		{"¥2 @8% floor", 2, 8, "floor", 0},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			if got := GroupTaxFor(c.gross, c.rate, true, 1.0, c.mode); got != c.want {
				t.Fatalf("GroupTaxFor(%v, %v%%, 内税, %q) = %v, muốn %v", c.gross, c.rate, c.mode, got, c.want)
			}
		})
	}
}

// Bất biến độc lập với con số: xuống ≤ nửa ≤ lên. Nếu chỉ ghim ba giá trị cụ
// thể thì một bản vá đảo dấu vẫn có thể lách qua bằng cách đổi cả ba.
func TestGroupTaxFor2480_MonotonicByMode(t *testing.T) {
	for gross := 1.0; gross <= 3000; gross++ {
		for _, rate := range []float64{8, 10} {
			down := GroupTaxFor(gross, rate, true, 1.0, "floor")
			half := GroupTaxFor(gross, rate, true, 1.0, "round")
			up := GroupTaxFor(gross, rate, true, 1.0, "ceil")
			if !(down <= half && half <= up) {
				t.Fatalf("gross=%v rate=%v%%: floor=%v round=%v ceil=%v — sai thứ tự", gross, rate, down, half, up)
			}
			if down > gross || up > gross {
				t.Fatalf("gross=%v rate=%v%%: thuế không thể lớn hơn cả giá", gross, rate)
			}
		}
	}
}

// Chế độ mặc định phải BẤT ĐỘNG — đây là điều khiến bản vá chỉ chạm một chi
// nhánh thay vì cả 17.
func TestGroupTaxFor2480_HalfUpUnchanged(t *testing.T) {
	for gross := 1.0; gross <= 3000; gross++ {
		for _, rate := range []float64{8, 10} {
			old := gross - roundToStep(gross/(1+rate/100.0), 1.0, "round")
			if got := GroupTaxFor(gross, rate, true, 1.0, "round"); got != old {
				t.Fatalf("gross=%v rate=%v%%: half_up xê dịch %v → %v", gross, rate, old, got)
			}
		}
	}
}

// Đọc thẳng nguồn PHP: đổi một bên mà quên bên kia là đỏ, ở CẢ HAI repo.
func TestGroupTaxFor2480_MatchesCloudFormula(t *testing.T) {
	b, err := os.ReadFile("../../../backend/app/Services/Customer/OrderPricingCalculator.php")
	if err != nil {
		t.Skipf("không đọc được nguồn Cloud: %v", err)
	}
	src := string(b)
	i := strings.Index(src, "public function groupTaxFor")
	if i < 0 {
		t.Fatal("không tìm thấy groupTaxFor bên Cloud — bài này mất tác dụng, sửa selector")
	}
	body := src[i:]
	if j := strings.Index(body, "\n    }"); j > 0 {
		body = body[:j]
	}
	if !strings.Contains(body, "$netGroup * $rate / (100.0 + $rate)") {
		t.Fatalf("Cloud không còn dùng công thức làm-tròn-thuế:\n%s", body)
	}
	if strings.Contains(body, "$netGroup - RoundingMode::roundToStep") {
		t.Fatal("Cloud quay lại công thức làm-tròn-NỀN — hai đầu sẽ lệch tiền")
	}
}
