package store

import (
	"fmt"
	"io/fs"
	"regexp"
	"sort"
	"strings"
	"testing"
)

/*
Migration GHI ĐÈ DỮ LIỆU phải được KHAI BÁO — lỗ mà rào forward-compat không
nhìn thấy.

`migration_forward_compat_test.go` quét DDL bằng văn bản để giữ bất biến "binary
bản N phải đọc được schema bản N+1". Nó không bao giờ mở database, nên nó **mù
hoàn toàn với DML**: một migration ghi hỏng mọi hàng trong bảng `payments` vẫn
đi qua cổng đó sạch sẽ, vì nó không đụng schema.

Mà DML đe doạ đúng bất biến ấy, chỉ theo đường NGỮ NGHĨA thay vì cấu trúc. 087
viết `split_mode = 'even'`; nếu nó đã viết một giá trị mà binary cũ không có
nhánh xử lý, thì một lượt rollback lúc 2 giờ sáng sẽ in sai — schema vẫn hợp lệ
từng byte.

## Rào này chứng minh được gì, và KHÔNG chứng minh được gì

Nó **không** chứng minh binary cũ hiểu giá trị mới. Điều đó không kiểm được bằng
văn bản, và giả vờ ngược lại còn tệ hơn không có rào.

Nó làm một việc hẹp hơn nhưng có thật: **một lượt ghi đè dữ liệu không được phép
đi vào cây trong im lặng.** Người viết phải khai ra migration ghi giá trị GÌ và
vì sao binary đời trước đọc được — nghĩa là người review có câu để đối chiếu,
thay vì phải tự phát hiện ra rằng file này có DML.

Cùng khuôn với `forwardCompatExceptions`: danh sách không làm cho thao tác trở
nên an toàn, nó làm cho thao tác trở nên NHÌN THẤY ĐƯỢC.
*/

// Bắt câu DML ở đầu dòng. Cố ý KHÔNG bắt `INSERT`: mọi migration tạo bảng đều
// có thể chèn hàng hạt giống, và gộp chúng vào đây sẽ biến danh sách thành thứ
// dài tới mức không ai đọc — đúng cách một rào tự làm mình bị bỏ qua.
var dataRewriteStatement = regexp.MustCompile(`(?im)^[[:space:]]*(UPDATE|DELETE[[:space:]]+FROM)[[:space:]]`)

// Mỗi entry phải nói HAI điều: migration ghi giá trị gì, và vì sao binary đời
// trước đọc được giá trị đó.
var dataRewriteDeclarations = map[string]string{
	"013_printer_roles.sql": "đổ `printers.roles = json_array(type)` cho hàng roles rỗng, và thêm `:9100` vào IP máy in cũ. Giá trị viết ra DẪN XUẤT từ `type` đang có, nên binary cũ đọc `roles` thấy đúng từ vựng nó vẫn tự ghi.",

	"025_tables_status_normalize.sql": "`tables.status` 'available' → 'free'. Thu hẹp về một từ vựng duy nhất; 'free' là giá trị phần còn lại của cây vẫn dùng, 'available' là biến thể lẻ.",

	"034_order_items_printed_quantity.sql": "đổ `printed_quantity = quantity` cho dòng đã gửi bếp. Backfill một cột VỪA thêm từ dữ liệu sẵn có — binary cũ không biết cột này nên không đọc nó.",

	"051_hall_printer_role_rename.sql": "`hold_printer` → `hall_printer` trong `printers.roles` (JSON) và `printers.type`. Đây là đổi TỪ VỰNG, không phải backfill: binary chỉ biết tên cũ sẽ thôi khớp vai in. Đường đọc alias tên cũ vẫn còn trên máy trạm và được theo dõi ở #2412.",

	"054_tax_types_single_rate.sql": "`tax_types.rate = rate_takeaway` trước khi drop hai cột rate cũ (#1099, một type = một rate). Đi kèm entry DDL cùng tên trong `forwardCompatExceptions` — đọc cả hai chỗ.",

	"058_payment_status_succeeded.sql": "`payments.status` 'confirmed' → 'succeeded'. Từ vựng trạng thái tiền; cặp cũ/mới được giữ ở lớp tương thích `payment_status_compatibility`.",

	"065_effective_payment_options_client.sql": "XOÁ hai khoá `settings` (`sync.payment_policy.revision`, `…snapshot_hash`). Đây là DẤU ĐỒNG BỘ, không phải dữ liệu nghiệp vụ: xoá đi buộc máy kéo lại policy từ Cloud, và Cloud là nguồn chân lý của nó.",

	"073_tender_display_locales.sql": "cùng hai khoá `settings` như 065, cùng lý do — buộc kéo lại sau khi hình dạng tender đổi. Không hàng nghiệp vụ nào bị đụng.",

	"083_payments_signed_refund_rows.sql": "đặt `payments.refunded_amount = 0`, `refunded_at = NULL`, và 'refunded' → 'succeeded' khi chuyển hoàn tiền sang hàng KÝ HIỆU ÂM (#2656). Đây là ghi đè CHẠM TIỀN nặng nhất trong danh sách: số tiền hoàn không mất mà đổi chỗ ở, từ một cột sang một hàng riêng. Binary cũ vẫn cộng cột ấy — nên nó đọc ra 0 và KHÔNG đếm trùng. Tám biểu thức tổng còn đọc cột này là lý do #2666 chưa gỡ được nó.",

	"087_split_mode_canonical_vocabulary.sql": "`payments.metadata.split_mode` / `.split_type`: 'equal'·'by_people'·'split_even' → 'even', 'custom' → 'by_amount' (#2860). Binary đời trước có sẵn nhánh `case \"even\", \"equal\"` và `case \"by_amount\"` ở `print_service.go`, nên mọi giá trị migration này ghi ra đều in đúng sau một lượt rollback. Hàng metadata escape hai lần CỐ Ý không được migrate — xem comment trong chính file .sql.",
}

func migrationsWithDataRewrites(t *testing.T) map[string]int {
	t.Helper()

	entries, err := fs.ReadDir(localMigrationsFS, "migrations")
	if err != nil {
		t.Fatalf("đọc thư mục migrations: %v", err)
	}

	found := map[string]int{}
	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(e.Name(), ".sql") {
			continue
		}
		content, err := fs.ReadFile(localMigrationsFS, "migrations/"+e.Name())
		if err != nil {
			t.Fatalf("đọc %s: %v", e.Name(), err)
		}
		if n := len(dataRewriteStatement.FindAll(content, -1)); n > 0 {
			found[e.Name()] = n
		}
	}
	return found
}

// TestDataRewritingMigrationsAreDeclared — cổng chính.
func TestDataRewritingMigrationsAreDeclared(t *testing.T) {
	found := migrationsWithDataRewrites(t)

	// Mẫu số bằng không có ba nguồn, và một trong số đó là "không hàng nào
	// thuộc diện được hỏi". Không có phép đếm này thì một regex hỏng sẽ làm cả
	// bài test IM LẶNG thay vì đỏ — và im lặng ở đây đọc lên y hệt "sạch".
	if len(found) < len(dataRewriteDeclarations) {
		t.Fatalf(
			"chỉ quét ra %d migration có DML nhưng đã khai %d — regex hoặc bố cục thư mục hỏng, sửa bài test chứ đừng xoá",
			len(found), len(dataRewriteDeclarations),
		)
	}

	var undeclared []string
	for name, n := range found {
		if _, ok := dataRewriteDeclarations[name]; !ok {
			undeclared = append(undeclared, fmt.Sprintf("  %s (%d câu DML)", name, n))
		}
	}
	sort.Strings(undeclared)

	if len(undeclared) > 0 {
		t.Fatalf(
			"Migration GHI ĐÈ DỮ LIỆU mà không khai báo:\n%s\n\n"+
				"Rào forward-compat chỉ quét DDL nên nó KHÔNG thấy những câu này: một lượt ghi\n"+
				"hỏng mọi hàng vẫn đi qua nó sạch sẽ. Thêm entry vào `dataRewriteDeclarations`\n"+
				"nói rõ migration ghi giá trị GÌ và vì sao binary đời trước đọc được — vì sau một\n"+
				"lượt rollback lúc 2 giờ sáng, chính binary đó là thứ đọc dữ liệu bạn vừa viết.",
			strings.Join(undeclared, "\n"),
		)
	}
}

// TestDataRewriteDeclarationsDoNotRot — entry phải còn ứng với một file có DML thật.
//
// Cùng khuôn `TestForwardCompatExceptionListOnlyShrinks`: một danh sách miễn trừ
// giữ lại những dòng không còn ứng với gì là cách nó âm thầm mở rộng — lần sau
// có người thấy tên quen trong danh sách và cho rằng đã được duyệt.
func TestDataRewriteDeclarationsDoNotRot(t *testing.T) {
	found := migrationsWithDataRewrites(t)

	for name, reason := range dataRewriteDeclarations {
		if strings.TrimSpace(reason) == "" {
			t.Errorf("%s: lý do rỗng — một entry không giải thích gì thì không phải khai báo", name)
		}
		if _, ok := found[name]; !ok {
			t.Errorf("%s: đã khai nhưng file không còn câu DML nào (hoặc đã bị xoá) — gỡ entry đi", name)
		}
	}
}

// TestMigration087LeavesDoubleEscapedMetadataAlone — ghim hành vi mà comment cũ
// của 087 mô tả SAI.
//
// Comment cũ nói `json_valid` chặn chuỗi escape hai lần. Đo được:
// `json_valid('"{\"split_mode\":\"equal\"}"')` trả **1** — với SQLite đó là một
// chuỗi JSON hợp lệ. Thứ lọc nó khỏi mệnh đề WHERE là `json_extract` trả NULL.
//
// # Bài test này ghim CÁI GÌ — và phép đo đã sửa lại câu trả lời
//
// Bản đầu của docblock này viết: "ai bỏ `json_extract` sẽ mở đúng cái lỗ".
// Đột biến bác bỏ. Gỡ hẳn vế `IN (...)` khỏi 087 thì bài test VẪN XANH, vì
// hàng escape hai lần có một tầng bảo vệ thứ hai, độc lập với WHERE:
//
//	sqlite> SELECT json_set('"{\"split_mode\":\"equal\"}"', '$.split_mode', 'even');
//	"{\"split_mode\":\"equal\"}"
//
// `json_set` trên một giá trị KHÔNG phải object trả về đầu vào nguyên xi. Nên
// hàng ấy an toàn nhờ ngữ nghĩa của `json_set`, không nhờ mệnh đề lọc.
//
// Vậy bài này canh gì? Đo bằng bốn lượt đột biến:
//
//	gỡ vế `IN (...)`                        → XANH (json_set cứu)
//	đổi `json_set` → `json_object`          → XANH ở bài này (WHERE cứu; bài
//	                                          087 có sẵn mới là cái đỏ)
//	gỡ CẢ HAI                               → **ĐỎ**
//
// Nói cho đúng: nó ghim rằng hàng escape hai lần sống sót được **miễn là còn
// ít nhất MỘT trong hai tầng**. Đó là một khẳng định hẹp hơn "canh mệnh đề
// WHERE", và hẹp hơn cả điều tôi tưởng lúc mới viết — nhưng nó là điều đúng,
// và nó chính là thứ giữ cho blob không bị nuốt.
//
// Ghi lại cả đoạn suy luận sai này thay vì lặng lẽ sửa, vì nó chính là hình
// dạng "rào xanh vì lý do KHÁC" mà repo đã trả giá nhiều lần — và lần này thứ
// bắt được nó là một lượt đột biến, không phải một lượt đọc lại.
func TestMigration087LeavesDoubleEscapedMetadataAlone(t *testing.T) {
	db := openTestDB(t)

	if _, err := db.Exec(`
		INSERT INTO orders (id, order_number, status, created_at, updated_at)
		VALUES ('ord-esc','ORD-ESC','open',datetime('now'),datetime('now'))`); err != nil {
		t.Fatalf("đổ order: %v", err)
	}

	// Chuỗi JSON mang một object đã escape — hình dạng đường kiosk cũ từng ghi.
	const doubleEscaped = `"{\"split_mode\":\"equal\"}"`

	if _, err := db.Exec(`
		INSERT INTO payments (id, order_id, payment_method, amount, refunded_amount, metadata, created_at, updated_at)
		VALUES ('pay-esc','ord-esc','cash',100,0,?,datetime('now'),datetime('now'))`,
		doubleEscaped); err != nil {
		t.Fatalf("đổ payment: %v", err)
	}

	// Tiền đề của cả bài: SQLite THẬT SỰ coi đây là JSON hợp lệ. Không có phép
	// khẳng định này thì bài test vẫn xanh cả khi tiền đề sai, và nó sẽ ghim
	// một kết luận đúng vì lý do khác.
	var valid int
	if err := db.QueryRow(`SELECT json_valid(?)`, doubleEscaped).Scan(&valid); err != nil {
		t.Fatalf("json_valid: %v", err)
	}
	if valid != 1 {
		t.Fatalf("tiền đề sai: json_valid trả %d, comment của 087 dựa trên việc nó trả 1", valid)
	}

	content, err := fs.ReadFile(localMigrationsFS, "migrations/087_split_mode_canonical_vocabulary.sql")
	if err != nil {
		t.Fatalf("đọc 087: %v", err)
	}
	if _, err := db.Exec(string(content)); err != nil {
		t.Fatalf("chạy lại 087: %v", err)
	}

	var got string
	if err := db.QueryRow(`SELECT metadata FROM payments WHERE id = 'pay-esc'`).Scan(&got); err != nil {
		t.Fatalf("đọc lại: %v", err)
	}
	if got != doubleEscaped {
		t.Errorf("metadata escape hai lần bị đụng: %q → %q", doubleEscaped, got)
	}
}

// TestMigration087MetadataShapeMatrix — mọi hình dạng `metadata` mà 087 có thể
// gặp, kể cả những hình dạng nó không hề mong đợi.
//
// Bộ test 087 sẵn có phủ các giá trị HỢP LỆ (equal · by_people · custom ·
// split_type · đã canonical · NULL · JSON hỏng). Bảng này phủ phần còn lại —
// những blob mà `json_extract` trả về thứ KHÔNG phải chuỗi từ vựng, và là chỗ
// một câu `UPDATE … json_set(…)` dễ nuốt mất blob nhất.
//
// Cả bảng ghim CÙNG MỘT tính chất: hàng không thuộc diện thì ra sao vào đúng
// như thế. Đó là điều kiện để 087 an toàn — nó chạy trên bảng TIỀN.
func TestMigration087MetadataShapeMatrix(t *testing.T) {
	cases := []struct {
		name string
		in   string // metadata trước
		want string // metadata sau; giống `in` nghĩa là không được đụng
	}{
		{"mảng JSON", `[1,2,3]`, `[1,2,3]`},
		{"mảng chứa object mang từ vựng cũ", `[{"split_mode":"equal"}]`, `[{"split_mode":"equal"}]`},
		{"JSON null literal", `null`, `null`},
		{"chuỗi JSON trần", `"equal"`, `"equal"`},
		{"số", `42`, `42`},
		{"split_mode là số", `{"split_mode":42}`, `{"split_mode":42}`},
		{"split_mode là boolean", `{"split_mode":true}`, `{"split_mode":true}`},
		{"split_mode là object", `{"split_mode":{"k":1}}`, `{"split_mode":{"k":1}}`},
		{"split_mode chuỗi rỗng", `{"split_mode":""}`, `{"split_mode":""}`},
		{"từ vựng lạ không nằm trong tập đổi", `{"split_mode":"khong_biet"}`, `{"split_mode":"khong_biet"}`},

		// Hàng THUỘC diện: đổi đúng, và mọi khoá anh em còn nguyên.
		{
			"thuộc diện, giữ khoá anh em",
			`{"split_mode":"split_even","bill_index":1,"print_history":[7]}`,
			`{"split_mode":"even","bill_index":1,"print_history":[7]}`,
		},

		// `json_set` viết lại blob theo dạng chuẩn của SQLite: khoảng trắng bị
		// nén. Vô hại với mọi bên đọc JSON, nhưng BYTE trên đĩa đổi nhiều hơn
		// một khoá — ghim lại để không ai coi đó là dữ liệu bị hỏng.
		{
			"blob thuộc diện bị nén khoảng trắng",
			`{ "split_mode" : "equal" ,  "a" : 1 }`,
			`{"split_mode":"even","a":1}`,
		},
	}

	for i, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			db := openTestDB(t)
			id := fmt.Sprintf("p-shape-%d", i)

			mustExecStore(t, db, `INSERT INTO payments
				(id, order_id, payment_method, payment_method_id, amount, status, created_at, updated_at, metadata)
				VALUES (?, 'o1','cash','pm',1000,'succeeded','2026-08-12T02:00:00Z','2026-08-12T02:00:00Z', ?)`,
				id, c.in)

			applyMigration087(t, db)

			var got string
			if err := db.QueryRow(`SELECT metadata FROM payments WHERE id = ?`, id).Scan(&got); err != nil {
				t.Fatalf("đọc lại: %v", err)
			}
			if got != c.want {
				t.Errorf("metadata %q → %q, mong đợi %q", c.in, got, c.want)
			}
		})
	}
}
