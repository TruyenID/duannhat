package store

import (
	"io/fs"
	"strings"
	"testing"
)

/*
Bẫy của đường `+guard-add-column`, và vì sao rào phải quét CHUỖI chứ không quét
bằng regex thô.

`execMigration` có hai lối. Lối thường: `tx.Exec(cả file)` — SQLite tự hiểu
comment và chuỗi, không có gì để hỏng. Lối mang cờ `guardAddColumnMarker` thì
Go phải TỰ cắt file thành từng câu, và nó làm việc đó bằng hai thao tác đều
không biết gì về chuỗi SQL:

	stripSQLComments  cắt mọi thứ sau `--` TRÊN TỪNG DÒNG
	strings.Split     tách theo `;`

Đo bằng SQLite thật: `INSERT INTO t VALUES ('a--b');` là hợp lệ và lưu đúng
`a--b`. Nhưng qua `stripSQLComments` nó còn lại:

	INSERT INTO t VALUES ('a

— một câu cụt. Với `;` cũng vậy: một chuỗi chứa `;` bị vỡ làm đôi.

Hiện có ĐÚNG MỘT migration mang cờ (`064_plan045_refund_rounding.sql`) và nó
không dính bẫy. Rào này giữ cho câu đó còn đúng.

# Vì sao không dùng regex

Thử `'[^']*(--|;)[^']*'` trên 064 thì nó BÁO NHẦM hai dòng — vì regex thô nhìn

	conditionable_type TEXT NOT NULL DEFAULT '',   -- morph alias: 'order' | 'order_item'

và thấy một "chuỗi" trải từ `''` tới dấu nháy trong phần comment. Một rào kêu
oan trên chính file hợp lệ duy nhất sẽ không bị tranh luận — nó bị TẮT. Nên
phải quét đúng: đi từng ký tự, biết mình đang ở trong chuỗi hay ngoài, và hiểu
`''` là dấu nháy thoát.
*/

// literalHazard trả về đoạn chuỗi SQL đầu tiên chứa `--` hoặc `;`, hoặc "".
//
// Chỉ nhìn NGOÀI comment: `-- ghi chú có dấu ' ở đây` không mở một chuỗi nào cả,
// và bỏ qua điều đó chính là chỗ regex thô sai.
func literalHazard(sql string) string {
	for _, line := range strings.Split(sql, "\n") {
		inString := false
		var literal strings.Builder

		for i := 0; i < len(line); i++ {
			c := line[i]

			if c == '\'' {
				// `''` bên trong chuỗi là một dấu nháy thoát, không phải kết thúc.
				if inString && i+1 < len(line) && line[i+1] == '\'' {
					literal.WriteString("''")
					i++

					continue
				}
				if inString {
					if s := literal.String(); strings.Contains(s, "--") || strings.Contains(s, ";") {
						return "'" + s + "'"
					}
					literal.Reset()
				}
				inString = !inString

				continue
			}

			if inString {
				literal.WriteByte(c)

				continue
			}

			// Ngoài chuỗi, `--` mở comment tới hết dòng — phần còn lại không
			// thể chứa chuỗi nào nữa.
			if c == '-' && i+1 < len(line) && line[i+1] == '-' {
				break
			}
		}
	}

	return ""
}

func TestGuardMarkerMigrationsHaveNoHazardousLiteral(t *testing.T) {
	entries, err := fs.ReadDir(localMigrationsFS, "migrations")
	if err != nil {
		t.Fatalf("đọc migrations: %v", err)
	}

	checked := 0
	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(e.Name(), ".sql") {
			continue
		}
		content, err := fs.ReadFile(localMigrationsFS, "migrations/"+e.Name())
		if err != nil {
			t.Fatalf("đọc %s: %v", e.Name(), err)
		}
		if !strings.Contains(string(content), guardAddColumnMarker) {
			continue
		}
		checked++

		if hazard := literalHazard(string(content)); hazard != "" {
			t.Errorf(
				"%s mang cờ `+guard-add-column` và chứa chuỗi SQL %s.\n"+
					"Đường có cờ tự cắt file bằng `stripSQLComments` (cắt sau `--` theo DÒNG) rồi "+
					"`Split(\";\")` — cả hai đều không biết chuỗi SQL là gì, nên câu lệnh sẽ bị cụt "+
					"hoặc vỡ đôi TRƯỚC khi tới SQLite. Bỏ cờ đi, hoặc viết lại để chuỗi không chứa "+
					"`--`/`;`.",
				e.Name(), hazard,
			)
		}
	}

	// Mẫu số bằng không có ba nguồn, và một trong số đó là "không hàng nào
	// thuộc diện được hỏi". Nếu cờ bị đổi tên hoặc file 064 mất cờ, bài này
	// phải ĐỎ chứ không được im rồi báo sạch.
	if checked == 0 {
		t.Fatal("không migration nào mang cờ `+guard-add-column` — cờ đổi tên, hoặc bố cục đổi; sửa bài test chứ đừng xoá")
	}
}

// TestLiteralHazardScanner — rào của chính rào.
//
// `literalHazard` là thứ duy nhất đứng giữa một migration hỏng và production,
// nên nó phải được chứng minh cả hai chiều: bắt đúng cái nguy, và IM trên
// những hình dạng hợp lệ mà một regex thô sẽ báo nhầm.
func TestLiteralHazardScanner(t *testing.T) {
	cases := []struct {
		name   string
		sql    string
		hazard bool
	}{
		{"chuỗi chứa hai gạch", `INSERT INTO t VALUES ('a--b');`, true},
		{"chuỗi chứa chấm phẩy", `INSERT INTO t VALUES ('a;b');`, true},
		{"chuỗi sạch", `INSERT INTO t VALUES ('order');`, false},

		// Đây là hình dạng làm regex thô báo nhầm trên 064: chuỗi rỗng, rồi
		// một comment có chứa dấu nháy.
		{
			"chuỗi rỗng rồi comment có dấu nháy",
			`conditionable_type TEXT NOT NULL DEFAULT '',   -- morph alias: 'order' | 'order_item'`,
			false,
		},
		{"comment chứa cả -- lẫn dấu nháy", `-- xem 'a--b' và 'c;d'`, false},
		{"dấu nháy thoát bên trong chuỗi", `INSERT INTO t VALUES ('it''s fine');`, false},
		{"dấu nháy thoát ôm một gạch đôi", `INSERT INTO t VALUES ('it''s--bad');`, true},
		{"hai chuỗi trên một dòng, cái sau dính", `VALUES ('ok', 'a;b')`, true},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got := literalHazard(c.sql) != ""
			if got != c.hazard {
				t.Errorf("literalHazard(%q) = %v, mong đợi %v (trả về %q)",
					c.sql, got, c.hazard, literalHazard(c.sql))
			}
		})
	}
}

// TestStripSQLCommentsTruncatesStringLiteral — ghim CÁI BẪY, không phải hành vi
// mong muốn.
//
// Bài này tồn tại để câu chuyện trên không phải truyền miệng. Nếu có người sửa
// `stripSQLComments` cho nó hiểu chuỗi SQL, bài này sẽ đỏ — và đó là lúc gỡ nó
// cùng với rào `...HaveNoHazardousLiteral` ở trên, vì cả hai chỉ tồn tại vì
// giới hạn ấy.
func TestStripSQLCommentsTruncatesStringLiteral(t *testing.T) {
	const stmt = `INSERT INTO t VALUES ('a--b');`

	got := strings.TrimSpace(stripSQLComments(stmt))
	const want = `INSERT INTO t VALUES ('a`

	if got != want {
		t.Errorf(
			"stripSQLComments(%q) = %q, bài test này ghim %q.\n"+
				"Khác đi nghĩa là hàm nay ĐÃ hiểu chuỗi SQL — tin tốt: gỡ bài này và gỡ luôn "+
				"`TestGuardMarkerMigrationsHaveNoHazardousLiteral`, cả hai chỉ tồn tại vì giới hạn đó.",
			stmt, got, want,
		)
	}
}
