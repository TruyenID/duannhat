package service

import (
	"reflect"
	"testing"
)

// #2000 — các NHÁNH BIÊN của `store_info.fields`.
//
// Sáu ca dưới đây đều đã hành xử đúng trước khi có bài này; điều thiếu là không
// gì ghim chúng. Chúng cũng là chỗ PHP và Go phải NÓI CÙNG MỘT THỨ — đã đo tay
// và thấy khớp, nhưng một phép đo tay không sống sót qua lần refactor kế tiếp.
//
// `StoreInfoBlockEdgesTest` bên PHP là bản đối xứng, cùng bảng ca, cùng kỳ vọng.
func TestStoreDetailValues_Edges(t *testing.T) {
	cfg := PrintJobConfig{
		StoreOrganization: "ORG", StoreName: "N",
		StoreSubName: "SUB", StoreAddress: "ADDR", StorePhone: "TEL",
	}

	for _, tc := range []struct {
		name         string
		fields       []string
		above, below []string
	}{
		{
			// Không có mốc thì mọi field coi như đứng DƯỚI. Giữ đúng hành vi
			// trước khi có phép cắt, nên một definition cũ không đổi bố cục.
			name:   "không có store_name → tất cả xuống dưới",
			fields: []string{"store_sub_name", "store_address"},
			above:  nil, below: []string{"SUB", "ADDR"},
		},
		{
			name:   "store_name đứng đầu → tất cả xuống dưới",
			fields: []string{"store_name", "store_sub_name", "store_address"},
			above:  nil, below: []string{"SUB", "ADDR"},
		},
		{
			name:   "store_name đứng cuối → tất cả lên trên",
			fields: []string{"store_sub_name", "store_address", "store_name"},
			above:  []string{"SUB", "ADDR"}, below: nil,
		},
		{
			// Khai hai lần thì in hai lần — CỐ Ý. Definition là ý muốn của người
			// thiết kế phiếu; lọc trùng ở đây sẽ là renderer cãi lại template, và
			// "địa chỉ ở cả đầu lẫn cuối" là một bố cục hợp lệ.
			name:   "field lặp → in ở cả hai nửa",
			fields: []string{"store_address", "store_name", "store_address"},
			above:  []string{"ADDR"}, below: []string{"ADDR"},
		},
		{
			// Không ném: một definition mới hơn code KHÔNG được làm hỏng cả phiếu
			// (cùng luật với block lạ trong `printKindPlan.emitters`).
			name:   "field lạ → bỏ qua, không ném",
			fields: []string{"store_fax", "store_name"},
			above:  nil, below: nil,
		},
		{
			name:   "danh sách rỗng → không in gì",
			fields: nil,
			above:  nil, below: nil,
		},
	} {
		t.Run(tc.name, func(t *testing.T) {
			if got := StoreDetailValues(cfg, tc.fields, true); !reflect.DeepEqual(got, tc.above) {
				t.Errorf("above = %v, muốn %v", got, tc.above)
			}
			if got := StoreDetailValues(cfg, tc.fields, false); !reflect.DeepEqual(got, tc.below) {
				t.Errorf("below = %v, muốn %v", got, tc.below)
			}
		})
	}
}

// Giá trị RỖNG bị bỏ qua ở mọi vị trí — đây là thứ giữ cho một quán chưa nhập
// địa chỉ vẫn ra tờ giấy như cũ sau khi #2000 bước 6 bật header đầy đủ.
func TestStoreDetailValues_EmptyValuesSkipped(t *testing.T) {
	cfg := PrintJobConfig{StoreSubName: "SUB"} // địa chỉ + TEL + 法人名 đều rỗng
	fields := []string{"store_organization", "store_sub_name", "store_name", "store_address", "store_phone"}

	if got := StoreDetailValues(cfg, fields, true); !reflect.DeepEqual(got, []string{"SUB"}) {
		t.Errorf("above = %v, muốn chỉ [SUB]", got)
	}
	if got := StoreDetailValues(cfg, fields, false); got != nil {
		t.Errorf("below = %v, muốn rỗng", got)
	}
}

// `StoreFieldsForKind` là thứ đường DỰ PHÒNG dùng để in đúng bộ dòng mà đường
// template in. Trả nil ở mọi ca không tra được: formatter cũ khi đó in như
// trước bài này, và "in được" quan trọng hơn "in đủ".
func TestStoreFieldsForKind_UnknownAndPlanless(t *testing.T) {
	if got := StoreFieldsForKind("khong-co-that"); got != nil {
		t.Errorf("kind lạ trả %v, muốn nil", got)
	}
	if got := StoreFieldsForKind("receipt"); len(got) != 5 {
		t.Errorf("receipt trả %v, muốn 5 field", got)
	}
	// `kitchen` TỪNG trả nil ở đây: nó không có khối `store_info` nào. Nay phiếu
	// bếp dùng chung template với phiếu hall — khác đúng ở QR — nên nó mang cùng
	// bộ dòng danh tính với `runner`. Đổi khẳng định chứ không nới nó: một dòng
	// danh tính SAI trên phiếu bếp cũng là sai, chỉ là ít ai soi.
	if got, want := StoreFieldsForKind("kitchen"), StoreFieldsForKind("runner"); !reflect.DeepEqual(got, want) {
		t.Errorf("kitchen trả %v, muốn giống runner %v", got, want)
	}
}
