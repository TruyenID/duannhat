package service

import (
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
)

// #2188 — LEGACY KHÔNG TỒN TẠI (ruling chủ dự án 2026-08-08):
//
//	"Không có cái gì gọi là legacy. Mọi thứ chưa được release nên không quan
//	tâm đến legacy. Legacy không tồn tại và cấm tuyệt đối."
//
// Rào này cấm ĐỊNH DANH mang nghĩa legacy trong code mới dưới internal/: biến,
// hàm, hằng, field… khớp (?i)legacy trong phần MÃ. Comment và string literal
// được lột trước khi quét — removal record ("ĐÃ GỠ #1779") và log text kể
// chuyện cũ không phải là nhánh legacy; một cái TÊN trong code thì là.
//
// Allowlist là ảnh chụp NHỮNG GÌ CÒN LẠI sau đợt dọn #2188 đợt 4 (legacyTaxRate
// + fallback stored-subtotal, lazy re-stamp, alias till_name/`type`), mỗi entry
// kèm lý do. CHỈ ĐƯỢC TEO — dọn xong file nào thì gỡ entry đó (chiều ngược lại
// cũng bị cưỡng chế bên dưới). Thêm entry mới là vi phạm ruling.
func TestLegacyIdentifiersStayOnTheShrinkOnlyDebtList(t *testing.T) {
	// đường dẫn tương đối từ internal/ => lý do còn sống (nợ #2188).
	allowed := map[string]string{
		"handler/customer_order_shape.go":            "fallback doc menu_items khi sku khong con trong pos_product_skus (duong handy/kiosk cu) — ung vien don",
		"handler/local_effective_payment_options.go": "cot legacy_payment_method_id/code trong replica payment-options — schema Cloud, chet cung dot payment",
		"handler/local_pos_revenue_by_product.go":    "fallback nhan category tu menu_items khi catalog chua co category that — ung vien don",
		"printer/manager.go":                         "legacyHoldPrinter: alias role hold_printer cu con do trong config JSON tren may — can migration config truoc khi chat",
		"service/sync_pull_pos.go":                   "LegacyMinOrderAmount…: alias key JSON cu cua feed coupon (PullCoupons normalize) — ung vien dot feed",
		"store/migrate.go":                           "goi repairLegacySchema() — chet cung entry ben duoi",
		"store/repair.go":                            "repairLegacySchema: sua schema SQLite cu tren may da cai — can quyet dinh wipe-db truoc khi chat",
	}

	pattern := regexp.MustCompile(`(?i)legacy`)

	violations := map[string]int{}
	root := ".." // internal/ (test chạy trong internal/service)
	err := filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		if info.IsDir() {
			// Code generated — regen ghi đè mọi sửa tay.
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

		if n := len(pattern.FindAllIndex(stripGoCommentsAndStrings(src), -1)); n > 0 {
			rel := filepath.ToSlash(strings.TrimPrefix(path, root+string(filepath.Separator)))
			violations[rel] = n
		}
		return nil
	})
	if err != nil {
		t.Fatalf("quet cay internal/: %v", err)
	}

	for rel, n := range violations {
		if _, ok := allowed[rel]; !ok {
			t.Errorf("%s: %d dinh danh legacy trong MA — ruling #2188 cam tuyet doi. "+
				"Xoa nhanh/doi ten thay vi xin allowlist; danh sach chi ghi NO CU va chi duoc teo.", rel, n)
		}
	}

	// Shrink-only: entry mà file đã sạch (hoặc đã xoá) thì phải gỡ.
	for rel := range allowed {
		if _, ok := violations[rel]; !ok {
			t.Errorf("entry allowlist thua: %s het dinh danh legacy (hoac file da xoa) — go entry", rel)
		}
	}
}

// stripGoCommentsAndStrings lột line/block comment, string ("…", `…`) và rune
// literal khỏi mã nguồn Go, giữ nguyên phần còn lại — đủ chính xác cho một
// lượt quét regex định danh (không cần AST; một identifier không thể vắt
// ngang các vùng bị lột).
func stripGoCommentsAndStrings(src []byte) []byte {
	out := make([]byte, 0, len(src))
	n := len(src)
	for i := 0; i < n; {
		c := src[i]
		switch {
		case c == '/' && i+1 < n && src[i+1] == '/':
			for i < n && src[i] != '\n' {
				i++
			}
		case c == '/' && i+1 < n && src[i+1] == '*':
			i += 2
			for i+1 < n && !(src[i] == '*' && src[i+1] == '/') {
				i++
			}
			i += 2
		case c == '"':
			i++
			for i < n && src[i] != '"' {
				if src[i] == '\\' {
					i++
				}
				i++
			}
			i++
		case c == '`':
			i++
			for i < n && src[i] != '`' {
				i++
			}
			i++
		case c == '\'':
			i++
			for i < n && src[i] != '\'' {
				if src[i] == '\\' {
					i++
				}
				i++
			}
			i++
		default:
			out = append(out, c)
			i++
		}
	}
	return out
}
