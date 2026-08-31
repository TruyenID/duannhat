package service

import (
	"encoding/json"
	"flag"
	"os"
	"reflect"
	"testing"
)

// plan-053 T5.1d (#1876) — catalog nhãn i18n, ghi ra để PHP ĐỌC chứ không chép.
//
// Mọi emitter trong `print_renderer_{bill,docs,shift}.go` lấy nhãn từ
// `printLabelsFor(locale)`, không lấy từ block definition. Nên bản port PHP phải
// có ĐÚNG 36 chuỗi ấy cho ba locale, và "đúng" ở đây là byte-for-byte: một dấu
// hai chấm thừa trong `NotePrefix` làm lệch mọi dòng ghi chú của mọi kind.
//
// Chép tay hai bên là cách chắc chắn để chúng trôi khỏi nhau — đúng cái T5.2a đã
// tránh cho primitives bằng fixture chung. File này mở rộng cùng khuôn đó.
//
// Sinh lại có chủ đích:
//
//	go test ./internal/service/ -run Labels_Golden -args -update-print-labels
var updateLabels = flag.Bool("update-print-labels", false, "rewrite testdata/print_labels_golden.json")

const labelsGoldenPath = "testdata/print_labels_golden.json"

// Ba locale là toàn bộ tập được hỗ trợ; `printLabelsFor` rơi về ja cho mọi giá
// trị khác, và ca đó được ghim riêng bên dưới thay vì ghi vào fixture — một khoá
// "xx" trong file dùng chung sẽ trông như một locale thật.
func labelLocales() []string { return []string{"ja", "en", "vi"} }

// labelsAsMap dùng reflection thay vì liệt kê 36 trường bằng tay: liệt kê tay
// nghĩa là thêm trường mới vào `printLabels` mà quên thêm ở đây, và fixture im
// lặng thiếu một nhãn mà PHP không có cách nào biết.
func labelsAsMap(l printLabels) map[string]string {
	out := map[string]string{}
	v := reflect.ValueOf(l)
	t := v.Type()

	for i := 0; i < t.NumField(); i++ {
		if t.Field(i).Type.Kind() != reflect.String {
			continue
		}
		out[t.Field(i).Name] = v.Field(i).String()
	}

	return out
}

func TestLabels_Golden(t *testing.T) {
	current := map[string]map[string]string{}
	for _, locale := range labelLocales() {
		current[locale] = labelsAsMap(printLabelsFor(locale))
	}

	if *updateLabels {
		blob, err := json.MarshalIndent(current, "", "  ")
		if err != nil {
			t.Fatalf("marshal: %v", err)
		}
		if err := os.WriteFile(labelsGoldenPath, append(blob, '\n'), 0o644); err != nil {
			t.Fatalf("write %s: %v", labelsGoldenPath, err)
		}
		t.Logf("rewrote %s", labelsGoldenPath)

		return
	}

	raw, err := os.ReadFile(labelsGoldenPath)
	if err != nil {
		t.Fatalf("read %s: %v — rerun with -update-print-labels", labelsGoldenPath, err)
	}

	var recorded map[string]map[string]string
	if err := json.Unmarshal(raw, &recorded); err != nil {
		t.Fatalf("parse %s: %v", labelsGoldenPath, err)
	}

	if !reflect.DeepEqual(recorded, current) {
		t.Fatalf("catalog nhãn lệch khỏi %s — sinh lại có chủ đích, đừng sửa tay file JSON", labelsGoldenPath)
	}
}

// Fixture chỉ chứa ba locale thật. Hành vi fallback vẫn phải được ghim, nếu
// không thì một locale lạ có thể lặng lẽ ra chuỗi rỗng và PHP sẽ sao chép đúng
// cái rỗng đó.
func TestLabels_UnknownLocaleFallsBackToJapanese(t *testing.T) {
	if !reflect.DeepEqual(labelsAsMap(printLabelsFor("xx")), labelsAsMap(printLabelsFor("ja"))) {
		t.Fatal("locale lạ phải rơi về ja")
	}
}

// Không nhãn nào được rỗng ở bất kỳ locale nào: một chuỗi rỗng in ra thành một
// dòng cụt trên phiếu thật, và nó sẽ được fixture ghi lại như thể là chủ đích.
func TestLabels_NoneEmpty(t *testing.T) {
	for _, locale := range labelLocales() {
		for name, value := range labelsAsMap(printLabelsFor(locale)) {
			if value == "" {
				t.Errorf("%s.%s rỗng", locale, name)
			}
		}
	}
}
