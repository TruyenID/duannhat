package service

import (
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

// #2083 — hợp đồng CHUNG cho phép tính tiền giảm của coupon, máy trạm ↔ Cloud.
//
// Lệch ở đây nghĩa là TIỀN IN TRÊN PHIẾU khác TIỀN VÀO SỔ, và nó xuất hiện đúng
// lúc OFFLINE — lúc không ai đối chiếu được. Đã lệch thật: bản cũ chia SỐ NGUYÊN
// (cắt cụt) trong khi Cloud `round()`, nên giỏ ¥1.005 với coupon 15% ra 150 ở
// đây và 151 trên Cloud.
//
// `coupon_parity_test.go` có sẵn chỉ soi TRẠNG THÁI VÒNG ĐỜI của coupon (đã
// dùng chưa, hết hạn chưa) — không soi một đồng nào. Cổng này lấp đúng khoảng
// trống đó.

type couponMathCase struct {
	ID    string `json:"id"`
	Type  string `json:"type"`
	Value int    `json:"value"`
	// #2118/#2186 — giá trị CHÍNH XÁC ×100 (12,5% → 1250). nil (khoá vắng
	// hoặc null) = feed cũ ⇒ engine phải rơi về Value; khác nil ⇒ engine
	// phải dùng nó, chia 100 TRƯỚC khi coi là phần trăm.
	ValueX100 *int   `json:"value_x100"`
	Subtotal  int    `json:"subtotal"`
	Cap       *int   `json:"cap"`
	Expected  int    `json:"expected"`
	Why       string `json:"why"`
}

func loadCouponMathGolden(t *testing.T) []couponMathCase {
	t.Helper()

	raw, err := os.ReadFile(filepath.Join("testdata", "coupon_math_golden.json"))
	if err != nil {
		t.Fatalf("đọc fixture: %v", err)
	}
	var doc struct {
		Cases []couponMathCase `json:"cases"`
	}
	if err := json.Unmarshal(raw, &doc); err != nil {
		t.Fatalf("giải mã fixture: %v", err)
	}
	if len(doc.Cases) == 0 {
		t.Fatal("fixture rỗng — bộ đọc hỏng, và fixture rỗng thì test dưới xanh giả")
	}

	return doc.Cases
}

func TestCouponMathGolden(t *testing.T) {
	for _, c := range loadCouponMathGolden(t) {
		row := &couponRow{DiscountType: c.Type, DiscountValue: c.Value}
		if c.ValueX100 != nil {
			row.DiscountValueX100 = sql.NullInt64{Int64: int64(*c.ValueX100), Valid: true}
		}
		if c.Cap != nil {
			row.MaxDiscountCap = sql.NullInt64{Int64: int64(*c.Cap), Valid: true}
		}

		if got := computeDiscount(row, c.Subtotal); got != c.Expected {
			t.Errorf("%s: computeDiscount(%s %d%%, subtotal=%d, cap=%v) = %d, muốn %d — %s",
				c.ID, c.Type, c.Value, c.Subtotal, c.Cap, got, c.Expected, c.Why)
		}
	}
}

// Nửa còn thiếu của cổng: máy trạm cũng phải khẳng định fixture của nó BẰNG bản
// backend. Cổng thuế cũ chỉ PHP so hash, nên sửa một bên rồi copy một chiều vẫn
// xanh ở CI của repo này (nó không checkout backend) — xem #2089.
func TestCouponMathGolden_FixtureMatchesBackend(t *testing.T) {
	ours := filepath.Join("testdata", "coupon_math_golden.json")
	theirs := filepath.Join("..", "..", "..", "backend", "tests", "Fixtures", "coupon_math_golden.json")

	if _, err := os.Stat(theirs); err != nil {
		// Repo này được clone độc lập trong CI của chính nó, nơi `backend/`
		// không tồn tại. Bỏ qua CÓ NHÃN — an toàn vì cổng phía PHP KHÔNG được
		// phép bỏ qua, nên lượt chạy nào có cả hai repo đều bắt buộc so.
		t.Skip("không có backend/ trong lượt chạy này — cổng phía PHP là chỗ bắt buộc so")
	}

	sum := func(p string) string {
		b, err := os.ReadFile(p)
		if err != nil {
			t.Fatalf("đọc %s: %v", p, err)
		}
		h := sha256.Sum256(b)

		return hex.EncodeToString(h[:])
	}

	if sum(ours) != sum(theirs) {
		t.Errorf("fixture LỆCH nhau:\n  máy trạm: %s\n  backend:  %s\n\n"+
			"Hợp đồng chung — sửa công thức thì sửa file này TRƯỚC, copy sang cả hai\n"+
			"repo, rồi mới sửa code hai phía.", ours, theirs)
	}
}
