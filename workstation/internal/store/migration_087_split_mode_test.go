package store

import (
	"testing"
)

// #2860 — migration 087 viết lại từ vựng chia bill đã lưu local về canonical.
//
// SQL chạy từ CHÍNH file nhúng mà migration runner dùng, trên các hàng gieo theo
// hình dạng CŨ — nên bài này đo đúng những byte sẽ chạy trên máy ngoài quán, chứ
// không đo một bản chép tay của chúng.
//
// # Vì sao migration này quan trọng hơn vẻ ngoài của nó
//
// Cùng lượt đổi từ vựng gỡ nhánh `case "even", "equal"` khỏi đường in. Sau khi
// gỡ, một khoản thanh toán cũ mang `metadata.split_mode = "equal"` không khớp
// nhánh nào và rơi xuống nhánh suy đoán `splitCount > 1` — mà `total_bills`
// không phải blob nào cũng có. Khi không có, phiếu in ra như hoá đơn thường
// thay vì phiếu chia: mất dòng phần của từng người, đúng thứ khách cầm để đối
// chiếu.
//
// Dữ liệu đó nằm trên hai máy Windows không tự cập nhật, nên nó không tự hết.
func applyMigration087(t *testing.T, db *DB) {
	t.Helper()
	sqlBytes, err := localMigrationsFS.ReadFile("migrations/087_split_mode_canonical_vocabulary.sql")
	if err != nil {
		t.Fatalf("read migration 087: %v", err)
	}
	if _, err := db.Conn().Exec(string(sqlBytes)); err != nil {
		t.Fatalf("apply migration 087: %v", err)
	}
}

func splitModeOf(t *testing.T, db *DB, id, key string) string {
	t.Helper()
	var v *string
	if err := db.QueryRow(
		`SELECT json_extract(metadata, '$.'||?) FROM payments WHERE id = ?`, key, id,
	).Scan(&v); err != nil {
		t.Fatalf("read %s of %s: %v", key, id, err)
	}
	if v == nil {
		return ""
	}
	return *v
}

func TestMigration087_CanonicalisesStoredSplitVocabulary(t *testing.T) {
	db := openTestDB(t)

	// Hình dạng cũ: bốn tên cho ba khái niệm, cộng một hàng đã canonical và
	// hai hàng KHÔNG được đụng tới.
	mustExecStore(t, db, `INSERT INTO payments
		(id, order_id, payment_method, payment_method_id, amount, status, created_at, updated_at, metadata)
		VALUES
		 ('p-equal','o1','cash','pm',1000,'succeeded','2026-08-12T02:00:00Z','2026-08-12T02:00:00Z',
		  '{"split_mode":"equal","bill_index":0,"total_bills":2,"print_history":[1]}'),
		 ('p-bypeople','o1','cash','pm',1000,'succeeded','2026-08-12T02:00:00Z','2026-08-12T02:00:00Z',
		  '{"split_mode":"by_people","split_count":3}'),
		 ('p-custom','o1','cash','pm',1000,'succeeded','2026-08-12T02:00:00Z','2026-08-12T02:00:00Z',
		  '{"split_mode":"custom","label":"giu nguyen toi"}'),
		 ('p-type','o1','cash','pm',1000,'succeeded','2026-08-12T02:00:00Z','2026-08-12T02:00:00Z',
		  '{"split_type":"by_people","amount_per_person":500}'),
		 ('p-already','o1','cash','pm',1000,'succeeded','2026-08-12T02:00:00Z','2026-08-12T02:00:00Z',
		  '{"split_mode":"by_items","item_allocations":[]}'),
		 ('p-null','o1','cash','pm',1000,'succeeded','2026-08-12T02:00:00Z','2026-08-12T02:00:00Z', NULL),
		 ('p-broken','o1','cash','pm',1000,'succeeded','2026-08-12T02:00:00Z','2026-08-12T02:00:00Z',
		  'khong phai JSON')`)

	applyMigration087(t, db)

	for _, c := range []struct{ id, key, want string }{
		{"p-equal", "split_mode", "even"},
		{"p-bypeople", "split_mode", "even"},
		{"p-custom", "split_mode", "by_amount"},
		{"p-type", "split_type", "even"},
		{"p-already", "split_mode", "by_items"},
	} {
		if got := splitModeOf(t, db, c.id, c.key); got != c.want {
			t.Errorf("%s.%s = %q, want %q", c.id, c.key, got, c.want)
		}
	}

	// Các khoá khác của blob phải còn nguyên — đó là lý do dùng `json_set` chứ
	// không ghi đè cả blob. `print_history` đặc biệt: mất nó là mất số bản in đã
	// cấp, và số bản in là thứ chống in lại hoá đơn tiền.
	var hist, label string
	if err := db.QueryRow(
		`SELECT json_extract(metadata,'$.print_history'), (SELECT json_extract(metadata,'$.label') FROM payments WHERE id='p-custom')
		   FROM payments WHERE id='p-equal'`).Scan(&hist, &label); err != nil {
		t.Fatalf("read preserved keys: %v", err)
	}
	if hist != "[1]" {
		t.Errorf("print_history = %q, want [1] — migration đã ăn mất khoá khác của blob", hist)
	}
	if label != "giu nguyen toi" {
		t.Errorf("label = %q — migration đã ăn mất khoá khác của blob", label)
	}

	// Hàng metadata NULL và metadata hỏng phải đi qua nguyên vẹn, không thành
	// NULL và không làm migration chết. Đường kiosk cũ từng ghi chuỗi escape hai
	// lần, nên "metadata không parse được" là chuyện đã xảy ra thật.
	var nullMeta *string
	if err := db.QueryRow(`SELECT metadata FROM payments WHERE id='p-null'`).Scan(&nullMeta); err != nil {
		t.Fatalf("read p-null: %v", err)
	}
	if nullMeta != nil {
		t.Errorf("metadata của p-null = %v, want NULL", *nullMeta)
	}
	var broken string
	if err := db.QueryRow(`SELECT metadata FROM payments WHERE id='p-broken'`).Scan(&broken); err != nil {
		t.Fatalf("read p-broken: %v", err)
	}
	if broken != "khong phai JSON" {
		t.Errorf("metadata của p-broken = %q — migration đã đụng vào hàng JSON hỏng", broken)
	}
}

func TestMigration087_IsIdempotent(t *testing.T) {
	// Runner chạy migration một lần, nhưng một máy được khôi phục từ bản sao lưu
	// có thể chạy lại. Bẫy thật là ánh xạ bắc cầu: nếu MAP có a→b và b→c thì
	// lượt hai đẩy tiếp. Ở đây không có, và bài này giữ cho nó không xuất hiện.
	db := openTestDB(t)
	mustExecStore(t, db, `INSERT INTO payments
		(id, order_id, payment_method, payment_method_id, amount, status, created_at, updated_at, metadata)
		VALUES ('p1','o1','cash','pm',1000,'succeeded','2026-08-12T02:00:00Z','2026-08-12T02:00:00Z',
		        '{"split_mode":"equal","total_bills":2}')`)

	applyMigration087(t, db)
	first := splitModeOf(t, db, "p1", "split_mode")
	applyMigration087(t, db)

	if second := splitModeOf(t, db, "p1", "split_mode"); second != first || second != "even" {
		t.Fatalf("lượt hai đổi kết quả: %q → %q", first, second)
	}
}
