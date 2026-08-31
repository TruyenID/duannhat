package service

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"math"
	"os"
	"path/filepath"
	"testing"
)

// #2082 — hợp đồng CHUNG cho tầng đáy làm tròn, máy trạm ↔ Cloud.
//
// Tầng này nạp vào MỌI con số phía trên: priceGroups, stampLineTaxAmounts,
// writeOrderConditionsTx, và công thức trích thuế ngược của 総額表示.
//
// Nó đã lệch thật, hai lần, theo cùng một cách: Cloud sửa (e8275ad97 #821 E1,
// 790e007e4 #821 E1f), bản port này KHÔNG NGHE THẤY, và không có cơ chế nào để
// nghe. Hậu quả đo được: `tax_rounding_decimals = 0` là mặc định DB, nên ở tiền
// tệ có xu thì Cloud dùng bước 0,01 còn máy trạm dùng bước 1 — dòng $1.45 @10%
// thu $0.15 trên Cloud và $0.00 ở đây, trên MỌI đơn.
//
// File này là nửa còn thiếu của cổng: trước #2082 chỉ PHP so hash, nên sửa một
// bên rồi copy một chiều vẫn xanh cả hai phía (#2089).

type roundingCase struct {
	Kind         string   `json:"kind"`
	Currency     *string  `json:"currency"`
	Decimals     *int     `json:"decimals"`
	CurrencyStep *float64 `json:"currency_step"`
	Value        *float64 `json:"value"`
	Step         *float64 `json:"step"`
	Expected     float64  `json:"expected"`
	Why          string   `json:"why"`
}

func loadRoundingGolden(t *testing.T) []roundingCase {
	t.Helper()

	raw, err := os.ReadFile(filepath.Join("testdata", "rounding_golden.json"))
	if err != nil {
		t.Fatalf("đọc fixture: %v", err)
	}
	var doc struct {
		Cases []roundingCase `json:"cases"`
	}
	if err := json.Unmarshal(raw, &doc); err != nil {
		t.Fatalf("giải mã fixture: %v", err)
	}
	if len(doc.Cases) == 0 {
		t.Fatal("fixture rỗng — bộ đọc hỏng, và một fixture rỗng thì mọi test dưới đây đều xanh giả")
	}

	return doc.Cases
}

func TestRoundingGolden_CurrencyStep(t *testing.T) {
	checked := 0
	for _, c := range loadRoundingGolden(t) {
		if c.Kind != "currency_step" || c.Currency == nil {
			continue
		}
		checked++
		if got := currencyStep(*c.Currency); got != c.Expected {
			t.Errorf("currencyStep(%q) = %v, muốn %v — %s", *c.Currency, got, c.Expected, c.Why)
		}
	}
	if checked == 0 {
		t.Fatal("không ca currency_step nào chạy")
	}
}

func TestRoundingGolden_TaxStep(t *testing.T) {
	checked := 0
	for _, c := range loadRoundingGolden(t) {
		if c.Kind != "tax_step" || c.CurrencyStep == nil {
			continue
		}
		checked++
		if got := taxStepFrom(c.Decimals, *c.CurrencyStep); got != c.Expected {
			d := "nil"
			if c.Decimals != nil {
				d = string(rune('0' + *c.Decimals))
			}
			t.Errorf("taxStepFrom(%s, %v) = %v, muốn %v — %s", d, *c.CurrencyStep, got, c.Expected, c.Why)
		}
	}
	if checked == 0 {
		t.Fatal("không ca tax_step nào chạy")
	}
}

func TestRoundingGolden_RoundHalfUp(t *testing.T) {
	checked := 0
	for _, c := range loadRoundingGolden(t) {
		if c.Kind != "round_half_up" || c.Value == nil || c.Step == nil {
			continue
		}
		checked++
		got := roundHalfUpToStep(*c.Value, *c.Step)
		// So với dung sai rất hẹp: đây là tiền, và mọi ca trong fixture đều
		// biểu diễn được chính xác ở bước của nó.
		if math.Abs(got-c.Expected) > 1e-9 {
			t.Errorf("roundHalfUpToStep(%v, %v) = %v, muốn %v — %s",
				*c.Value, *c.Step, got, c.Expected, c.Why)
		}
	}
	if checked == 0 {
		t.Fatal("không ca round_half_up nào chạy")
	}
}

// Nửa còn thiếu của cổng: máy trạm cũng phải khẳng định fixture của nó BẰNG
// bản backend. Trước #2082 chỉ PHP so hash, nên sửa bản Go rồi để yên bản PHP
// vẫn xanh ở CI của repo này (nó không checkout backend) — xem #2089.
func TestRoundingGolden_FixtureMatchesBackend(t *testing.T) {
	ours := filepath.Join("testdata", "rounding_golden.json")
	theirs := filepath.Join("..", "..", "..", "backend", "tests", "Fixtures", "rounding_golden.json")

	if _, err := os.Stat(theirs); err != nil {
		// Repo `workstation-app` được clone độc lập trong CI của chính nó, nơi
		// `backend/` không tồn tại. Bỏ qua CÓ NHÃN — khác hẳn cổng cũ bên PHP
		// vốn `expect(true)->toBeTrue()` rồi đi tiếp, tức xanh mà không đo gì.
		//
		// Bỏ qua ở đây an toàn vì cổng phía PHP KHÔNG được phép bỏ qua: lượt
		// chạy nào có cả hai repo thì bắt buộc phải so.
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
			"Đây là hợp đồng chung — sửa công thức thì sửa file này TRƯỚC, copy sang\n"+
			"cả hai repo, rồi mới sửa code hai phía. Sửa một bên rồi copy một chiều\n"+
			"là đúng cách #2082 xảy ra.", ours, theirs)
	}
}
