package service

import (
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
)

// #2108 — rào kiến trúc cho ruling 税込/税抜 (#2102, docs/guide/tax-types.md):
//
//	Cờ prices_include_tax chỉ được sống ở tầng menu-display và ở THỜI ĐIỂM tạo
//	đơn (order service chốt hướng tính thuế). Mọi tầng sau đó đọc snapshot đã
//	đóng băng trên đơn (orders.is_tax_included) — không được diễn giải lại cờ
//	chi nhánh đang sống trong shop_settings.
//
// Phía Go cờ sống ở shop_settings (mirror từ Cloud qua PullBranch), và lối đọc
// duy nhất là accessor `pricesIncludeTax()` của OrderEngine. Trước #2108 có hai
// call site đọc accessor ấy SAU khi đơn đã tạo (createItem lúc AddItems, và
// refund) — một cú flip 総額表示 giữa chừng làm dòng thêm sau/tiền hoàn negate
// theo mode khác với chính tổng đã chốt của đơn. Rào này giữ cho lỗi đó không
// mọc lại: nó quét MÃ NGUỒN nên bắt được cả call site thêm mới trong tương lai
// — thứ test hành vi không thấy (cùng triết lý print_seam_coverage_test.go).
//
// Cách quét: đi toàn bộ cây internal/ (trừ *_test.go và domain/generated/),
// tìm (a) literal "prices_include_tax" — key đọc thẳng settings, và (b) lời gọi
// `pricesIncludeTax()`. File chứa chúng phải nằm trong allowlist kèm lý do.
// Muốn thêm entry là câu hỏi về ruling, không phải thủ tục — đọc #2102 trước.
func TestBranchTaxModeFlagStaysInSanctionedTiers(t *testing.T) {
	// file (đường dẫn tương đối từ internal/) => lý do một dòng.
	allowed := map[string]string{
		// Accessor định nghĩa + đúng MỘT lời gọi lúc tạo đơn (snapshot
		// is_tax_included). Mọi consumer sau đó phải đi qua
		// orderIsTaxIncluded(q, orderID) — đọc snapshot của đơn.
		"service/order_service.go": "dinh nghia accessor + snapshot mot lan luc tao don",
		// Feed cờ settings xuống pos-web (tầng menu-display của client).
		"handler/local_pos_phase1.go": "feed settings cho pos-web hien thi gia menu",
	}

	literal := regexp.MustCompile(`"prices_include_tax"`)
	call := regexp.MustCompile(`\.pricesIncludeTax\(\)`)

	var offenders []string

	root := ".." // internal/ (test chạy trong internal/service)
	err := filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		if info.IsDir() {
			// Code generated — regen sẽ ghi đè mọi sửa tay, và nó không đọc cờ.
			if strings.Contains(filepath.ToSlash(path), "domain/generated") {
				return filepath.SkipDir
			}
			return nil
		}
		if !strings.HasSuffix(path, ".go") || strings.HasSuffix(path, "_test.go") {
			return nil
		}

		src, err := os.ReadFile(path)
		if err != nil {
			return err
		}

		rel := filepath.ToSlash(strings.TrimPrefix(path, root+string(filepath.Separator)))
		if _, ok := allowed[rel]; ok {
			return nil
		}

		if literal.Match(src) {
			offenders = append(offenders, rel+`: doc thang key "prices_include_tax" tu settings`)
		}
		if call.Match(src) {
			offenders = append(offenders, rel+": goi pricesIncludeTax() (co chi nhanh SONG) — dung orderIsTaxIncluded(q, orderID) doc snapshot cua don")
		}
		return nil
	})
	if err != nil {
		t.Fatalf("quet cay internal/: %v", err)
	}

	if len(offenders) > 0 {
		t.Errorf("co %d cho doc co 税込/税抜 chi nhanh ngoai tang duoc phep (#2108 — ruling #2102):\n  %s",
			len(offenders), strings.Join(offenders, "\n  "))
	}

	// Allowlist chỉ được teo, không được phình mà không ai nhìn: entry mà file
	// không còn tham chiếu cờ nữa thì phải gỡ đi cho danh sách khớp thực tế.
	for rel := range allowed {
		src, err := os.ReadFile(filepath.Join(root, filepath.FromSlash(rel)))
		if err != nil {
			t.Errorf("allowlist tro vao file khong doc duoc %s: %v", rel, err)
			continue
		}
		if !literal.Match(src) && !call.Match(src) {
			t.Errorf("entry allowlist thua: %s khong con tham chieu co — go entry", rel)
		}
	}
}

// #2108 — đúng MỘT lời gọi accessor cờ chi nhánh trong order_service.go: lần
// snapshot lúc tạo đơn. Con số này là chủ đích, không phải hiện trạng: thêm lời
// gọi thứ hai (dù trong file allowlist) là mở lại đúng lỗ createItem/refund vừa
// vá. Nếu bạn thật sự cần đọc cờ SỐNG lần nữa, đó là câu hỏi ruling (#2102).
func TestOrderServiceReadsLiveTaxModeFlagExactlyOnce(t *testing.T) {
	src, err := os.ReadFile("order_service.go")
	if err != nil {
		t.Fatalf("doc order_service.go: %v", err)
	}

	calls := regexp.MustCompile(`e\.pricesIncludeTax\(\)`).FindAll(src, -1)
	if len(calls) != 1 {
		t.Errorf("order_service.go co %d loi goi e.pricesIncludeTax(), muon dung 1 (snapshot luc tao don). "+
			"Moi cho doc SAU khi tao don phai dung orderIsTaxIncluded(q, orderID) (#2108)", len(calls))
	}
}
