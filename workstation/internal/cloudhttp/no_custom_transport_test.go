package cloudhttp

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// #2123 — bánh cóc giữ cho InstallVersionHeader còn với tới được.
//
// `InstallVersionHeader` bọc `http.DefaultTransport`, nên nó chỉ phủ những client
// KHÔNG có Transport riêng. Hôm nay cả 10 client production đều như vậy — đó
// không phải may, nhưng cũng **không có gì phát biểu ra**, và một dòng
// `Transport: &http.Transport{...}` thêm vào ngày mai sẽ lặng lẽ cắt client đó
// khỏi header phiên bản.
//
// Chế độ hỏng đúng bằng cái #2123 sinh ra để chữa: chỉ báo **đếm thiếu** đúng
// phần lưu lượng đi đường không được phủ, và không gì kêu lên. Cloud sẽ thấy một
// con số nhỏ hơn sự thật, và #2041 bước 3 xoá cột dựa trên con số ấy.
//
// Bài này ĐỌC MÃ NGUỒN — một hình thức thường yếu, vì nó ghim vị trí chứ không
// ghim hành vi. Ở đây nó là lựa chọn đúng vì tính chất cần canh là **toàn cục**:
// không quan sát được từ trong một package, và một bài hành vi chỉ chứng minh
// được cho client mà chính nó dựng.
//
// Cách nới đúng khi thật sự cần Transport riêng: gọi `InstallVersionHeader` xong
// thì `Transport: versionHeaderTransport{base: yourTransport}`, và thêm file vào
// danh sách miễn trừ NGAY TRONG bài test này kèm lý do — để lần sau còn đọc được.
func TestNoCustomTransportOutsideCloudHTTP(t *testing.T) {
	root := filepath.Join("..", "..")

	// Hai mẫu này đã được đo là ZERO hit hôm nay (ngoài package này). Chúng cố ý
	// KHÔNG khớp `Transports:` (tên khả năng máy in, `internal/printer/profile.go`)
	// vì ký tự ngay sau `Transport` là `s`, cũng không khớp field JSON `transport`
	// chữ thường ở `sync_pull_pos.go`.
	needles := []string{"Transport:", ".Transport ="}

	var offenders []string

	err := filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		if info.IsDir() {
			switch info.Name() {
			case ".git", "vendor", "node_modules", "frontend", "cloudhttp":
				return filepath.SkipDir
			}

			return nil
		}
		if !strings.HasSuffix(path, ".go") || strings.HasSuffix(path, "_test.go") {
			return nil
		}

		src, readErr := os.ReadFile(path)
		if readErr != nil {
			return readErr
		}

		for _, needle := range needles {
			// `toContain` kiểu biến thiên không tồn tại ở Go, nhưng nguyên tắc thì
			// giống: so sánh phải là một biểu thức boolean thật, thông điệp để riêng.
			if strings.Contains(string(src), needle) {
				offenders = append(offenders, path+" ("+needle+")")
			}
		}

		return nil
	})
	if err != nil {
		t.Fatalf("walk: %v", err)
	}

	if len(offenders) > 0 {
		t.Errorf(
			"Có client đặt Transport riêng, nên nó KHÔNG đi qua http.DefaultTransport\n"+
				"và mất header X-App-Version (#2123) — chỉ báo phiên bản sẽ đếm thiếu\n"+
				"đúng phần lưu lượng đi đường đó, im lặng:\n  %s",
			strings.Join(offenders, "\n  "),
		)
	}
}

// #2145 vòng 1 (điểm non-blocking) — bánh cóc giữ cho InstallVersionHeader được
// GỌI, không chỉ giữ cho nó với tới được.
//
// Bốn bài trong `version_transport_test.go` đều tự gọi `InstallVersionHeader`
// qua `installForTest`, nên xoá dòng gọi trong `cmd/*/main.go` để lại **toàn bộ
// test xanh** — reviewer đã kiểm và đúng vậy. Bài `TestNoCustomTransport...`
// bên trên cũng không phủ: nó chỉ quét `Transport:`.
//
// Hậu quả trùng đúng thứ #2123 chống: chỉ báo im lặng về 0, không gì kêu.
//
// Bài này cũng ĐỌC MÃ NGUỒN, cùng lý do như bài trên: "mọi entrypoint đều cài
// wrapper" là tính chất toàn cục, không quan sát được từ trong một package.
func TestInstallVersionHeaderCalledFromEveryEntrypoint(t *testing.T) {
	cmdDir := filepath.Join("..", "..", "cmd")

	entries, err := os.ReadDir(cmdDir)
	if err != nil {
		t.Fatalf("đọc cmd/: %v", err)
	}

	found := 0

	for _, e := range entries {
		if !e.IsDir() {
			continue
		}

		mainPath := filepath.Join(cmdDir, e.Name(), "main.go")

		src, readErr := os.ReadFile(mainPath)
		if readErr != nil {
			// Entrypoint không có main.go thì không phải chỗ cài wrapper.
			continue
		}

		found++

		if !strings.Contains(string(src), "cloudhttp.InstallVersionHeader(") {
			t.Errorf("%s KHÔNG gọi cloudhttp.InstallVersionHeader — binary này gửi request "+
				"đi Cloud mà không mang X-App-Version, và không có bài test nào khác kêu", mainPath)
		}
	}

	if found == 0 {
		t.Fatal("không đọc được main.go nào trong cmd/ — bài test này đang không canh gì")
	}
}
