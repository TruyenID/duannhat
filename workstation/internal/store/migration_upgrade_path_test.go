package store

import (
	"database/sql"
	"fmt"
	"io/fs"
	"path/filepath"
	"sort"
	"strings"
	"testing"
)

/*
Đường NÂNG CẤP — nhánh mà máy quán thật đi, và trước bộ test này KHÔNG có gì
chạm tới.

`migrate()` rẽ hai nhánh theo `isFreshDatabase()`. Toàn bộ test cũ trong cây
dựng DB mới tinh, nên chúng đi nhánh `fresh`: một transaction, tất-cả-hoặc-không.
Máy quán đi nhánh còn lại — DB đã có dữ liệu, migration chạy từng file một, mỗi
file một transaction. Hai nhánh có tính chất khác hẳn nhau, và nhánh đắt hơn là
nhánh chưa ai đo.

Vài test trông như đang đo nhánh đó nhưng thật ra không: `TestMigrationsIdempotent`
và `TestMigrationsE2E_ReopenIsNoop` mở DB hai lần bằng CÙNG một binary, nên lượt
hai thấy mọi version đã ghi nhận và **không áp gì cả**. Chúng khẳng định 0 hàng
mới — đúng, nhưng đó là đo một lượt chạy rỗng.

Đo 2026-08-18: fleet ở `v0.6.0` (schema 84) trong khi bản phát hành là `v0.8.26`
(schema 95). Lần đầu tiên đường này được thực thi là một thí nghiệm chạy tay đêm
hôm đó. Bộ test này biến thí nghiệm ấy thành rào thường trực.
*/

// Fleet cũ nhất còn chạy ngoài quán, tính theo version migration.
//
// Con số này KHÔNG suy ra được từ code — nó là phép đo trên
// `devices.device_info->app_version` của production. Cập nhật khi fleet nâng
// lên, và đừng hạ nó xuống để test dễ thở hơn: hạ là thôi đo đúng thứ đang
// chạy ngoài quán.
const oldestDeployedSchemaVersion = 84

// migrationVersionsInOrder trả về mọi version của migration viết tay, tăng dần.
func migrationVersionsInOrder(t *testing.T) []int {
	t.Helper()

	entries, err := fs.ReadDir(localMigrationsFS, "migrations")
	if err != nil {
		t.Fatalf("đọc thư mục migrations: %v", err)
	}

	var versions []int
	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(e.Name(), ".sql") {
			continue
		}
		var v int
		fmt.Sscanf(e.Name(), "%d_", &v)
		if v == 0 {
			continue
		}
		versions = append(versions, v)
	}
	sort.Ints(versions)

	// Mẫu số bằng không có ba nguồn, và một trong số đó là "không hàng nào
	// thuộc diện được hỏi". Không có phép đếm này thì một lần đổi bố cục
	// thư mục sẽ làm cả file test im lặng thay vì đỏ.
	if len(versions) < 90 {
		t.Fatalf("chỉ thấy %d migration — bố cục đã đổi, sửa test chứ đừng xoá", len(versions))
	}
	return versions
}

// buildDatabaseAtVersion dựng một file SQLite đứng ĐÚNG ở schema version
// `maxVersion`, y như máy quán chạy bản cũ.
//
// Áp trong MỘT transaction cho nhanh — ở đây ta chỉ cần trạng thái đầu vào,
// còn thứ đang được đo là lượt chạy incremental do `Open()` thực hiện sau đó.
func buildDatabaseAtVersion(t *testing.T, path string, maxVersion int) {
	t.Helper()

	conn, err := sql.Open("sqlite", path)
	if err != nil {
		t.Fatalf("mở sqlite: %v", err)
	}
	defer conn.Close()

	if _, err := conn.Exec(`
		CREATE TABLE IF NOT EXISTS schema_migrations (
			version    INTEGER PRIMARY KEY,
			name       TEXT NOT NULL,
			applied_at TEXT NOT NULL DEFAULT (datetime('now'))
		)`); err != nil {
		t.Fatalf("tạo schema_migrations: %v", err)
	}

	entries, err := fs.ReadDir(localMigrationsFS, "migrations")
	if err != nil {
		t.Fatalf("đọc migrations: %v", err)
	}
	sort.Slice(entries, func(i, j int) bool { return entries[i].Name() < entries[j].Name() })

	tx, err := conn.Begin()
	if err != nil {
		t.Fatalf("begin: %v", err)
	}
	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(e.Name(), ".sql") {
			continue
		}
		var v int
		fmt.Sscanf(e.Name(), "%d_", &v)
		if v == 0 || v > maxVersion {
			continue
		}
		content, err := fs.ReadFile(localMigrationsFS, "migrations/"+e.Name())
		if err != nil {
			tx.Rollback()
			t.Fatalf("đọc %s: %v", e.Name(), err)
		}
		if err := execMigration(tx, e.Name(), string(content)); err != nil {
			tx.Rollback()
			t.Fatalf("áp %s: %v", e.Name(), err)
		}
		if _, err := tx.Exec("INSERT INTO schema_migrations (version, name) VALUES (?, ?)", v, e.Name()); err != nil {
			tx.Rollback()
			t.Fatalf("ghi nhận %s: %v", e.Name(), err)
		}
	}
	if err := tx.Commit(); err != nil {
		t.Fatalf("commit: %v", err)
	}
}

func recordedVersions(t *testing.T, path string) map[int]bool {
	t.Helper()

	conn, err := sql.Open("sqlite", path)
	if err != nil {
		t.Fatalf("mở sqlite: %v", err)
	}
	defer conn.Close()

	rows, err := conn.Query("SELECT version FROM schema_migrations")
	if err != nil {
		t.Fatalf("đọc schema_migrations: %v", err)
	}
	defer rows.Close()

	out := map[int]bool{}
	for rows.Next() {
		var v int
		if err := rows.Scan(&v); err != nil {
			t.Fatalf("scan: %v", err)
		}
		out[v] = true
	}
	return out
}

// TestUpgradeFromEveryVersionApplies — tính chất TỔNG QUÁT, và nó là lý do file
// này không mục theo thời gian.
//
// Với MỌI điểm cắt trong chuỗi: dựng DB tới đó rồi mở bằng bộ migration đầy đủ.
// Nó bắt được lớp lỗi "migration X chạy được trên DB mới tinh nhưng hỏng khi áp
// incremental" cho mọi X, kể cả những X viết ra sau khi bộ test này ra đời —
// không cần ai nhớ thêm ca mới.
func TestUpgradeFromEveryVersionApplies(t *testing.T) {
	versions := migrationVersionsInOrder(t)
	newest := versions[len(versions)-1]

	for _, from := range versions {
		if from == newest {
			continue // không có gì để áp
		}
		t.Run(fmt.Sprintf("from_%03d", from), func(t *testing.T) {
			path := filepath.Join(t.TempDir(), "up.db")
			buildDatabaseAtVersion(t, path, from)

			db, err := Open(path)
			if err != nil {
				t.Fatalf("nâng cấp từ schema %d thất bại: %v", from, err)
			}
			defer db.Close()

			got := recordedVersions(t, path)
			for _, v := range versions {
				if !got[v] {
					t.Errorf("nâng từ %d: migration %d không được áp", from, v)
				}
			}
		})
	}
}

// TestUpgradeFromDeployedFleetPreservesData — câu hỏi thật của quán: nâng xong
// thì TIỀN có còn nguyên không.
//
// Test trên chỉ khẳng định migration CHẠY. Cái này khẳng định dữ liệu SỐNG SÓT,
// và nó khác nhau: 087 ghi lại `payments.metadata`, nên "chạy xong" và "không
// làm hỏng gì" là hai điều tách biệt.
func TestUpgradeFromDeployedFleetPreservesData(t *testing.T) {
	path := filepath.Join(t.TempDir(), "shop.db")
	buildDatabaseAtVersion(t, path, oldestDeployedSchemaVersion)

	seed, err := sql.Open("sqlite", path)
	if err != nil {
		t.Fatalf("mở để đổ dữ liệu: %v", err)
	}
	if _, err := seed.Exec(`
		INSERT INTO orders (id, order_number, status, created_at, updated_at)
		VALUES ('ord-1','ORD-1','open',datetime('now'),datetime('now'))`); err != nil {
		t.Fatalf("đổ orders: %v", err)
	}

	// Ba hàng đầu mang từ vựng cũ mà 087 phải đổi. Bốn hàng sau là những hình
	// dạng metadata mà 087 KHÔNG hề mong đợi — mảng JSON, giá trị số, chuỗi
	// không phải JSON, và NULL. Chúng ở đây vì `json_set` trên non-object là
	// chỗ một migration ghi-đè-dữ-liệu dễ làm hỏng hàng nhất.
	rows := []struct{ id, metadata string }{
		{"pay-equal", `{"split_mode":"equal"}`},
		{"pay-people", `{"split_mode":"by_people","split_type":"equal"}`},
		{"pay-custom", `{"split_mode":"custom"}`},
		{"pay-array", `[1,2,3]`},
		{"pay-number", `{"split_mode":42}`},
		{"pay-garbage", `khong-phai-json`},
		{"pay-null", ``},
	}
	for i, r := range rows {
		var meta any
		if r.metadata == "" {
			meta = nil
		} else {
			meta = r.metadata
		}
		if _, err := seed.Exec(`
			INSERT INTO payments (id, order_id, payment_method, amount, refunded_amount, metadata, created_at, updated_at)
			VALUES (?, 'ord-1', 'cash', ?, 0, ?, datetime('now'), datetime('now'))`,
			r.id, 100*(i+1), meta); err != nil {
			t.Fatalf("đổ payment %s: %v", r.id, err)
		}
	}
	seed.Close()

	const wantCount, wantSum = 7, 2800

	db, err := Open(path)
	if err != nil {
		t.Fatalf("nâng cấp thất bại: %v", err)
	}
	defer db.Close()

	var n, sum int
	if err := db.QueryRow(`SELECT COUNT(*), COALESCE(SUM(amount),0) FROM payments`).Scan(&n, &sum); err != nil {
		t.Fatalf("đếm payments: %v", err)
	}
	if n != wantCount || sum != wantSum {
		t.Fatalf("TIỀN LỆCH sau nâng cấp: %d hàng / tổng %d, mong đợi %d / %d", n, sum, wantCount, wantSum)
	}

	// 087 phải đổi ĐÚNG những hàng thuộc diện, và không đụng hàng nào khác.
	want := map[string]string{
		"pay-equal":   `{"split_mode":"even"}`,
		"pay-people":  `{"split_mode":"even","split_type":"even"}`,
		"pay-custom":  `{"split_mode":"by_amount"}`,
		"pay-array":   `[1,2,3]`,
		"pay-number":  `{"split_mode":42}`,
		"pay-garbage": `khong-phai-json`,
	}
	for id, expect := range want {
		var got sql.NullString
		if err := db.QueryRow(`SELECT metadata FROM payments WHERE id = ?`, id).Scan(&got); err != nil {
			t.Fatalf("đọc %s: %v", id, err)
		}
		if got.String != expect {
			t.Errorf("%s: metadata = %q, mong đợi %q", id, got.String, expect)
		}
	}

	var nullMeta sql.NullString
	if err := db.QueryRow(`SELECT metadata FROM payments WHERE id = 'pay-null'`).Scan(&nullMeta); err != nil {
		t.Fatalf("đọc pay-null: %v", err)
	}
	if nullMeta.Valid {
		t.Errorf("pay-null: metadata NULL bị ghi thành %q", nullMeta.String)
	}

	// Cột do các migration mới thêm phải tồn tại và đọc được trên hàng CŨ —
	// hàng ra đời trước khi cột có mặt.
	for _, col := range []string{"is_on_hold", "cloud_till_session_id"} {
		var v sql.NullString
		q := fmt.Sprintf("SELECT %s FROM orders WHERE id = 'ord-1'", col)
		if err := db.QueryRow(q).Scan(&v); err != nil {
			t.Errorf("đọc orders.%s trên hàng cũ: %v", col, err)
		}
	}
}

// TestUpgradeIsIdempotentOnDeployedFleet — mở lại lần hai không được đổi gì.
//
// Khác `TestMigrationsIdempotent` ở chỗ lượt ĐẦU thật sự có việc để làm, nên
// lượt hai mới là một phép đo có nội dung.
func TestUpgradeIsIdempotentOnDeployedFleet(t *testing.T) {
	path := filepath.Join(t.TempDir(), "twice.db")
	buildDatabaseAtVersion(t, path, oldestDeployedSchemaVersion)

	db1, err := Open(path)
	if err != nil {
		t.Fatalf("lượt nâng cấp đầu: %v", err)
	}
	first := recordedVersions(t, path)
	db1.Close()

	db2, err := Open(path)
	if err != nil {
		t.Fatalf("mở lại: %v", err)
	}
	defer db2.Close()

	second := recordedVersions(t, path)
	if len(first) != len(second) {
		t.Errorf("mở lại áp thêm migration: %d → %d", len(first), len(second))
	}
}

// TestUpgradeFailureKeepsWhatApplied — hành vi ĐÃ ĐƯỢC VIẾT RA trong comment của
// `migrate.go` mà chưa từng có máy nào kiểm chứng:
//
//	"An EXISTING database keeps the per-migration transaction: an upgrade that
//	 fails on migration 71 must keep the 70 that already applied."
//
// Cách gây lỗi ở đây không phải bịa: 085–095 KHÔNG mang cờ `+guard-add-column`,
// nên một cột đã tồn tại sẵn là `duplicate column name` và boot chết. Đó chính
// là hình dạng hỏng mà `repairLegacySchema` có thể tạo ra nếu nó chạy TRƯỚC
// migration thay vì sau.
func TestUpgradeFailureKeepsWhatApplied(t *testing.T) {
	path := filepath.Join(t.TempDir(), "halt.db")
	buildDatabaseAtVersion(t, path, oldestDeployedSchemaVersion)

	conn, err := sql.Open("sqlite", path)
	if err != nil {
		t.Fatalf("mở sqlite: %v", err)
	}
	// 086 sẽ thêm đúng cột này ⇒ va chạm ⇒ dừng giữa chuỗi.
	if _, err := conn.Exec("ALTER TABLE orders ADD COLUMN is_on_hold INTEGER"); err != nil {
		t.Fatalf("dựng va chạm: %v", err)
	}
	conn.Close()

	db, err := Open(path)
	if err == nil {
		db.Close()
		t.Fatal("Open() phải BÁO LỖI khi migration va chạm — một lượt nâng cấp hỏng mà im lặng sẽ bị đọc thành đã xong")
	}
	if !strings.Contains(strings.ToLower(err.Error()), "duplicate column") {
		t.Errorf("lỗi không nói rõ nguyên nhân: %v", err)
	}

	got := recordedVersions(t, path)
	if !got[85] {
		t.Error("migration 85 áp trước 86 nên phải được GIỮ — mất nó nghĩa là cả chuỗi bị cuộn lại")
	}
	if got[86] {
		t.Error("migration 86 hỏng mà vẫn được ghi nhận là đã áp — lần nâng sau sẽ bỏ qua nó vĩnh viễn")
	}
	for _, v := range []int{87, 88, 90} {
		if got[v] {
			t.Errorf("migration %d nằm SAU chỗ hỏng mà vẫn được áp", v)
		}
	}
}

// TestRepairLegacySchemaIsNoopAfterMigrations — `orders.cloud_till_session_id`
// có HAI nguồn: migration 095 và `repairLegacySchema()`.
//
// Thứ tự trong `migrate()` là migration trước, repair sau — nên 095 thắng và
// repair phải im. Nếu ai đó đảo thứ tự đó, hoặc gỡ `columnExists` khỏi repair,
// máy quán sẽ chết ở `duplicate column name` NGAY LẦN BOOT ĐẦU sau nâng cấp.
// Trước bài này không có gì ghim thứ tự ấy.
func TestRepairLegacySchemaIsNoopAfterMigrations(t *testing.T) {
	path := filepath.Join(t.TempDir(), "repair.db")
	buildDatabaseAtVersion(t, path, oldestDeployedSchemaVersion)

	db, err := Open(path)
	if err != nil {
		t.Fatalf("nâng cấp: %v", err)
	}
	defer db.Close()

	// Gọi lại repair một cách tường minh: nó phải chịu được việc chạy trên một
	// schema đã đầy đủ, bao nhiêu lần cũng được.
	if err := db.repairLegacySchema(); err != nil {
		t.Fatalf("repairLegacySchema sau migration phải là no-op: %v", err)
	}
	if err := db.repairLegacySchema(); err != nil {
		t.Fatalf("repairLegacySchema lần hai: %v", err)
	}

	var n int
	if err := db.QueryRow(
		`SELECT COUNT(*) FROM pragma_table_info('orders') WHERE name = 'cloud_till_session_id'`,
	).Scan(&n); err != nil {
		t.Fatalf("đếm cột: %v", err)
	}
	if n != 1 {
		t.Errorf("orders.cloud_till_session_id xuất hiện %d lần, mong đợi đúng 1", n)
	}
}
