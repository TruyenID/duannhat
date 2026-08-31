package handler

import (
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
)

// plan-053 T3.6 tầng 2 (#1914) — MỌI lời gọi formatter chứng từ tiền phải đi qua
// `renderMoneySlip`.
//
// # Vì sao cần rào này, khi seam đã có test riêng
//
// #1913 chứng minh seam hoạt động đúng. Nó KHÔNG chứng minh được rằng có ai gọi
// seam — một call site gọi thẳng `service.FormatX(...)` vẫn biên dịch, vẫn in
// ra giấy, và vẫn qua mọi test hiện có. Đó đúng là cách #1807 bị đóng nhầm:
// hạ tầng hoàn hảo, không caller.
//
// Rào này quét mã nguồn nên nó bắt được cả trường hợp ai đó THÊM MỚI một lời
// gọi formatter thẳng trong tương lai — thứ mà một test hành vi không thấy.
//
// # Lời gọi hợp lệ duy nhất nằm trong closure `legacy`
//
// `renderMoneySlip(..., func() []byte { return service.FormatX(...) })` — formatter
// cũ là đường lùi khi template hỏng, nên nó PHẢI còn được gọi. Rào phân biệt hai
// trường hợp bằng chỗ đứng: trong closure là đường lùi, ngoài closure là bỏ qua
// seam.
func TestEveryMoneyFormatterGoesThroughTheSeam(t *testing.T) {
	// Chỉ formatter sinh ra GIẤY cho khách hoặc cho sổ. Formatter phụ trợ
	// (dựng chuỗi, canh cột) không thuộc phạm vi này.
	// Danh sách này lấy từ TÊN THẬT trong `internal/service`, không từ trí nhớ.
	// Bản đầu viết "FormatRedInvoice" trong khi hàm thật tên
	// `FormatRedInvoiceTicket` — rào MÙ với đúng một chứng từ tiền, và nó vẫn
	// xanh. Một rào bỏ sót im lặng tệ hơn không có rào.
	//
	//   grep -oE "^func Format[A-Za-z]+" internal/service/print_*.go
	formatters := []string{
		"FormatChainReport", "FormatDebtSlip", "FormatDeltaQRTicket",
		"FormatKitchenTicket", "FormatPaidTicket", "FormatRedInvoiceTicket",
		"FormatRemainingTicket", "FormatRunnerTicket", "FormatShiftOpenReport",
		"FormatShiftReport", "FormatTablePaid", "FormatVatInvoice",
		"FormatVoidNotice",
	}

	// `service.FormatX(` nhưng KHÔNG đứng sau `return ` trong một dòng closure.
	// Không phải parser Go — và không cần: sai số chỉ có thể làm test ĐỎ nhầm
	// (bắt một lời gọi hợp lệ), không thể làm nó xanh nhầm.
	legacyClosure := regexp.MustCompile(`func\(\) \[\]byte \{ return service\.\w+\(`)

	var offenders []string

	entries, err := os.ReadDir(".")
	if err != nil {
		t.Fatalf("doc thu muc: %v", err)
	}

	for _, e := range entries {
		name := e.Name()
		if e.IsDir() || !strings.HasSuffix(name, ".go") || strings.HasSuffix(name, "_test.go") {
			continue
		}

		src, err := os.ReadFile(filepath.Join(".", name))
		if err != nil {
			t.Fatalf("doc %s: %v", name, err)
		}

		for i, line := range strings.Split(string(src), "\n") {
			trimmed := strings.TrimSpace(line)
			if strings.HasPrefix(trimmed, "//") {
				continue
			}
			if legacyClosure.MatchString(line) {
				continue // đường lùi hợp lệ
			}
			for _, f := range formatters {
				if strings.Contains(line, "service."+f+"(") {
					offenders = append(offenders, fmt.Sprintf("%s:%d %s", name, i+1, f))
				}
			}
		}
	}

	if len(offenders) > 0 {
		t.Errorf("còn %d lời gọi formatter KHÔNG qua renderMoneySlip:\n  %s\n\n"+
			"Chuyển sang: s.renderMoneySlip(service.NewXRenderData(...), profile, locale,\n"+
			"    func() []byte { return service.FormatX(...) })",
			len(offenders), strings.Join(offenders, "\n  "))
	}
}
