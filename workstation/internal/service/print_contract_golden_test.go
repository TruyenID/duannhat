package service

import (
	"encoding/json"
	"flag"
	"os"
	"reflect"
	"sort"
	"testing"
)

// plan-053 T5.1d slice 0 (#1897) — HỢP ĐỒNG của renderer, ghi ra để PHP ĐỌC.
//
// `print_labels_golden.json` (#1876) ghim các CHUỖI. File này ghim thứ nằm dưới
// chúng: hình dạng dữ liệu đầu vào và bảng dispatch.
//
// Vì sao cần, và vì sao không thể suy từ fixture nào đang có: bản port PHP dựng
// `PrintRenderData` / `PrintJobConfig` từ model của Cloud, khác hẳn nguồn của
// workstation. Nếu Go thêm một trường (ví dụ `ShiftOpen`) mà PHP không có, thì
// mọi emitter đọc trường đó ở PHP sẽ đọc phải null — và trên phiếu nó hiện ra
// thành một dòng thiếu, không phải một lỗi. `print_golden.json` không bắt được:
// nó render bằng renderer GO, nên nó vẫn xanh khi phía PHP thiếu nguyên một
// trường.
//
// Ghi cả bảng dispatch vì đó là chỗ thứ hai trôi được: một kind có mặt ở Go mà
// vắng ở PHP thì Cloud không in được kind đó, và cách hỏng là "không có gì xảy
// ra" chứ không phải một ngoại lệ.
//
// Fixture này KHÔNG ghim byte của phiếu — đó là T5.2b, và nó cần emitter đã có.
// Ở đây chỉ ghim thứ slice 0 dựng.
//
// Sinh lại có chủ đích:
//
//	go test ./internal/service/ -run Contract_Golden -args -update-print-contract
var updateContract = flag.Bool("update-print-contract", false, "rewrite testdata/print_contract_golden.json")

const contractGoldenPath = "testdata/print_contract_golden.json"

// printContract là hình dạng file. Khoá đặt theo snake_case vì phía đọc là PHP.
type printContract struct {
	DataFields   []string `json:"data_fields"`
	ConfigFields []string `json:"config_fields"`
	// TaxLabels ghim GIÁ TRỊ chứ không chỉ tên trường, cùng lý do với
	// `print_labels_golden.json` (#1876): `RateTarget` là một template
	// `Sprintf` ("%s%%対象"), nên chép tay sai một dấu `%` thì mọi khối thuế
	// theo mức ở locale đó in ra hỏng — và tên trường thì vẫn khớp.
	TaxLabels map[string]map[string]string `json:"tax_labels"`
	// ShiftLabels / ShiftOpenLabels ghim GIÁ TRỊ của 48 + 11 nhãn × 3 locale
	// (#1934). Chúng là NỀN của cả 19 emitter họ shift, và phía PHP không có
	// bảng tương ứng — phải dựng mới. 177 chuỗi chép tay mà không có gì đối
	// chiếu thì một nhãn sai hiện ra dưới dạng MỘT DÒNG SAI CHỮ trên phiếu 精算,
	// và 精算 là chứng từ đối soát tiền mặt.
	ShiftLabels     map[string]map[string]string `json:"shift_labels"`
	ShiftOpenLabels map[string]map[string]string `json:"shift_open_labels"`
	// PayloadFields ghim tên trường của các struct mà `PrintRenderData` mang
	// theo — `slip`, `shift`, `shiftOpen`, `vat`, `debt`… Phần PHP chép chúng
	// bằng tay (`ShiftReportInfo` có 41 trường), và chép tay 41 trường mà không
	// có gì đối chiếu thì sai một trường sẽ hiện ra dưới dạng MỘT DÒNG THIẾU
	// trên phiếu, không phải một lỗi (#1910).
	PayloadFields map[string][]string     `json:"payload_fields"`
	Kinds         map[string]contractKind `json:"kinds"`
}

// contractKind là những gì một `printKindPlan` hứa với người gọi. `blocks` là
// tập id mà plan biết — KHÔNG phải thứ tự in: thứ tự do definition quyết định,
// và ghim nó ở đây sẽ biến một quyết định của brand thành một hằng số của code.
type contractKind struct {
	DefaultWidth int      `json:"default_width"`
	JapaneseDoc  bool     `json:"japanese_doc"`
	Blocks       []string `json:"blocks"`
}

// structFieldNames liệt kê tên trường EXPORTED bằng reflection thay vì gõ tay.
// Gõ tay nghĩa là thêm trường vào struct rồi quên thêm ở đây, và fixture im lặng
// thiếu đúng trường vừa thêm — tức cái rào này sẽ nói "khớp" ở đúng lúc nó phải
// kêu.
func structFieldNames(v any) []string {
	t := reflect.TypeOf(v)
	out := make([]string, 0, t.NumField())

	for i := 0; i < t.NumField(); i++ {
		f := t.Field(i)
		if f.PkgPath != "" { // không exported
			continue
		}
		out = append(out, f.Name)
	}

	sort.Strings(out)

	return out
}

func currentPrintContract() printContract {
	kinds := map[string]contractKind{}
	for kind, plan := range printKindPlans {
		blocks := make([]string, 0, len(plan.emitters))
		for id := range plan.emitters {
			blocks = append(blocks, id)
		}
		sort.Strings(blocks)

		kinds[kind] = contractKind{
			DefaultWidth: plan.defaultWidth,
			JapaneseDoc:  plan.japaneseDoc,
			Blocks:       blocks,
		}
	}

	tax := map[string]map[string]string{}
	for _, locale := range labelLocales() {
		tax[locale] = stringFieldMap(taxLabelsFor(locale))
	}

	shiftL := map[string]map[string]string{}
	shiftOpenL := map[string]map[string]string{}
	for _, locale := range labelLocales() {
		shiftL[locale] = stringFieldMap(labelsFor(locale))
		shiftOpenL[locale] = stringFieldMap(openLabelsFor(locale))
	}

	payload := map[string][]string{
		"PaymentSlipInfo":     structFieldNames(PaymentSlipInfo{}),
		"ShiftReportInfo":     structFieldNames(ShiftReportInfo{}),
		"ShiftOpenReportInfo": structFieldNames(ShiftOpenReportInfo{}),
		"ShiftPaymentLine":    structFieldNames(ShiftPaymentLine{}),
		"ShiftDiscountLine":   structFieldNames(ShiftDiscountLine{}),
		"ShiftTaxRateLine":    structFieldNames(ShiftTaxRateLine{}),
		"ShiftOpenDenomLine":  structFieldNames(ShiftOpenDenomLine{}),
		"ChainShiftLine":      structFieldNames(ChainShiftLine{}),

		// Họ docs (#1909). VatInvoiceInfo có 19 trường và phía PHP chép chúng
		// bằng tay — cùng tình huống đã làm ShiftReportInfo sai hai kiểu ở
		// #1910 (thiếu 3 trường ở một struct, THỪA 2 trường không tồn tại ở
		// struct khác). Kiểu "thừa" không làm gì đỏ; nó chỉ nằm đó cho tới khi
		// có người tin là nó có dữ liệu.
		"VatInvoiceInfo":    structFieldNames(VatInvoiceInfo{}),
		"VatInvoiceLine":    structFieldNames(VatInvoiceLine{}),
		"VatInvoiceTaxLine": structFieldNames(VatInvoiceTaxLine{}),
		"VatInvoiceTopping": structFieldNames(VatInvoiceTopping{}),
		"DebtSlipInfo":      structFieldNames(DebtSlipInfo{}),
		"TablePaidInfo":     structFieldNames(TablePaidInfo{}),
	}

	return printContract{
		DataFields:      structFieldNames(PrintRenderData{}),
		PayloadFields:   payload,
		ConfigFields:    structFieldNames(PrintJobConfig{}),
		TaxLabels:       tax,
		ShiftLabels:     shiftL,
		ShiftOpenLabels: shiftOpenL,
		Kinds:           kinds,
	}
}

// stringFieldMap là `labelsAsMap` tổng quát hoá cho mọi struct toàn chuỗi.
func stringFieldMap(v any) map[string]string {
	out := map[string]string{}
	rv := reflect.ValueOf(v)
	rt := rv.Type()

	for i := 0; i < rt.NumField(); i++ {
		if rt.Field(i).Type.Kind() != reflect.String {
			continue
		}
		out[rt.Field(i).Name] = rv.Field(i).String()
	}

	return out
}

func TestContract_Golden(t *testing.T) {
	current := currentPrintContract()

	if *updateContract {
		blob, err := json.MarshalIndent(current, "", "  ")
		if err != nil {
			t.Fatalf("marshal: %v", err)
		}
		if err := os.WriteFile(contractGoldenPath, append(blob, '\n'), 0o644); err != nil {
			t.Fatalf("write %s: %v", contractGoldenPath, err)
		}
		t.Logf("rewrote %s", contractGoldenPath)

		return
	}

	raw, err := os.ReadFile(contractGoldenPath)
	if err != nil {
		t.Fatalf("read %s: %v — rerun with -update-print-contract", contractGoldenPath, err)
	}

	var recorded printContract
	if err := json.Unmarshal(raw, &recorded); err != nil {
		t.Fatalf("parse %s: %v", contractGoldenPath, err)
	}

	if !reflect.DeepEqual(recorded, current) {
		t.Fatalf("hợp đồng renderer lệch khỏi %s — sinh lại có chủ đích, đừng sửa tay file JSON", contractGoldenPath)
	}
}

// Bảng dispatch được nạp qua `init()` của từng file renderer. Nếu build tag hay
// thứ tự nạp đổi làm bảng rỗng, `TestContract_Golden` vẫn có thể XANH ở lượt
// `-update` (nó ghi ra một bảng rỗng rồi so với chính nó). Ca này chặn đúng chỗ
// đó: fixture rỗng là fixture vô nghĩa, và nó phải kêu trước khi PHP kịp mirror
// một bảng không có gì.
func TestContract_DispatchTableIsNotEmpty(t *testing.T) {
	if len(printKindPlans) == 0 {
		t.Fatal("printKindPlans rỗng — không có kind nào đăng ký, fixture sinh ra sẽ vô nghĩa")
	}

	for kind, plan := range printKindPlans {
		if len(plan.emitters) == 0 {
			t.Errorf("kind %q không có emitter nào", kind)
		}
		if plan.defaultWidth <= 0 {
			t.Errorf("kind %q có defaultWidth %d", kind, plan.defaultWidth)
		}
	}
}
