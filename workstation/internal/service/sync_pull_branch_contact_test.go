package service

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// #2000 bước 3 — Cloud GỬI `address`/`phone` trong feed branch từ lâu
// (`BranchController` select chúng), máy trạm chỉ chưa bao giờ giải mã. Kết quả:
// hoá đơn in ra không địa chỉ, không điện thoại, dù dữ liệu đã đi hết đường
// xuống đến đây.
//
// Bài này canh đúng khúc đó. Không có nó thì gỡ hẳn hai dòng `setCursor` cũng
// không test nào đỏ — đã đo bằng đột biến và thấy đúng vậy.

func branchContactServer(t *testing.T, address, phone string) *httptest.Server {
	t.Helper()

	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte(`{"data":{
			"id":"br-1","slug":"qpb","name":"Quán Phở Bò",
			"currency":"VND","timezone":"Asia/Tokyo","locale":"vi",
			"address":"` + address + `","phone":"` + phone + `",
			"organization_name":"株式会社ファムジア",
			"settings":{}
		}}`))
	}))
}

func branchContactDB(t *testing.T) *store.DB {
	t.Helper()

	db := newPullerTestDB(t)
	if _, err := db.Exec(`
		CREATE TABLE IF NOT EXISTS branches (
			id TEXT PRIMARY KEY,
			console_branch_id TEXT NOT NULL UNIQUE,
			console_organization_id TEXT NOT NULL,
			slug TEXT NOT NULL,
			name TEXT NOT NULL,
			is_active INTEGER NOT NULL DEFAULT 1,
			timezone TEXT, currency TEXT, locale TEXT,
			updated_at TEXT NOT NULL DEFAULT (datetime('now'))
		)`); err != nil {
		t.Fatal(err)
	}

	return db
}

func settingOf(t *testing.T, db *store.DB, key string) string {
	t.Helper()

	var v string
	if err := db.QueryRow(`SELECT value FROM settings WHERE key = ?`, key).Scan(&v); err != nil {
		return ""
	}

	return v
}

func TestPullBranch_StoresAddressAndPhone(t *testing.T) {
	cloud := branchContactServer(t, "Tokyo, Shinjuku 1-2-3", "03-1234-5678")
	defer cloud.Close()

	db := branchContactDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullBranch(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	if got := settingOf(t, db, "workstation_branch_address"); got != "Tokyo, Shinjuku 1-2-3" {
		t.Errorf("địa chỉ không được lưu: %q", got)
	}
	if got := settingOf(t, db, "workstation_branch_phone"); got != "03-1234-5678" {
		t.Errorf("điện thoại không được lưu: %q", got)
	}
}

// Chi nhánh XOÁ số điện thoại đi thì máy trạm phải quên nó, không giữ số cũ in
// mãi. Khác với tên quán — không có ca "xoá tên quán" hợp lệ nên tên giữ luật cũ
// (chỉ ghi khi khác rỗng).
func TestPullBranch_ClearedContactIsForgotten(t *testing.T) {
	db := branchContactDB(t)

	full := branchContactServer(t, "Tokyo, Shinjuku 1-2-3", "03-1234-5678")
	p := NewSyncPuller(db, full.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullBranch(context.Background()); err != nil {
		t.Fatalf("pull đầu: %v", err)
	}
	full.Close()

	empty := branchContactServer(t, "", "")
	defer empty.Close()
	p2 := NewSyncPuller(db, empty.URL, staticTokenFn("WS-TOKEN"))
	if err := p2.PullBranch(context.Background()); err != nil {
		t.Fatalf("pull sau: %v", err)
	}

	if got := settingOf(t, db, "workstation_branch_phone"); got != "" {
		t.Errorf("số điện thoại đã xoá vẫn còn: %q", got)
	}
	if got := settingOf(t, db, "workstation_branch_address"); got != "" {
		t.Errorf("địa chỉ đã xoá vẫn còn: %q", got)
	}
}

// #2000 bước 4 — 法人名 phải xuống tới settings, nếu không header không in được
// tên pháp nhân dù Cloud đã gửi.
func TestPullBranch_StoresOrganizationName(t *testing.T) {
	cloud := branchContactServer(t, "Tokyo, Shinjuku 1-2-3", "03-1234-5678")
	defer cloud.Close()

	db := branchContactDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	if err := p.PullBranch(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	if got := settingOf(t, db, "workstation_organization_name"); got != "株式会社ファムジア" {
		t.Errorf("法人名 không được lưu: %q", got)
	}
}
