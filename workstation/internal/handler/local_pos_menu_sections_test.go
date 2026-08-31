package handler

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// #3163 — POS phải tải thực đơn THEO SECTION, và máy trạm phải có đúng những
// đường Cloud có.
//
// #3159 chữa "mất section" bằng cách cho POS đi hết các trang, nhưng chi phí
// vẫn tuyến tính theo số món: menu 89 dòng = 638 KB một vòng, `refetchInterval`
// 60 giây, menu ~1000 món ⇒ ~7 MB mỗi phút mỗi tablet.
//
// POS chạy HAI chế độ (Cloud và LAN). Thiếu đường này ở máy trạm thì quán
// offline lặng lẽ rơi về tải cả thực đơn — không gì đỏ, đúng hình dạng lỗi mà
// #3159 vừa phải chữa bằng tay.
func newSectionFixture(t *testing.T) *Server {
	t.Helper()

	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()
	srv.orders = service.NewOrderEngine(db)

	mustExec(t, db, `INSERT INTO pos_menus (id, name, status) VALUES ('m1','Lunch','published')`)
	mustExec(t, db, `INSERT INTO pos_menu_sections (id, menu_id, name, sort_order) VALUES ('sec-drink','m1','Do uong',1)`)
	mustExec(t, db, `INSERT INTO pos_menu_sections (id, menu_id, name, sort_order) VALUES ('sec-empty','m1','Chua co mon',2)`)

	// Hai món trong section, một món CHƯA XẾP.
	for i, spec := range []struct{ id, section string }{
		{"mp1", "sec-drink"},
		{"mp2", "sec-drink"},
		{"mp3", ""},
	} {
		p := fmt.Sprintf("p%d", i)
		mustExec(t, db, `INSERT INTO pos_products (id, name) VALUES (?, ?)`, p, fmt.Sprintf("Mon %d", i))
		mustExec(t, db, `INSERT INTO pos_product_skus (id, product_id, name, selling_price) VALUES (?, ?, 'Bowl', 1000)`,
			fmt.Sprintf("s%d", i), p)
		if spec.section == "" {
			mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, display_order) VALUES (?, 'm1', ?, 0)`,
				spec.id, p)
		} else {
			mustExec(t, db, `INSERT INTO pos_menu_products (id, menu_id, product_id, menu_section_id, display_order) VALUES (?, 'm1', ?, ?, 0)`,
				spec.id, p, spec.section)
		}
		mustExec(t, db, `INSERT INTO pos_menu_product_skus (id, menu_product_id, product_sku_id, is_active) VALUES (?, ?, ?, 1)`,
			fmt.Sprintf("mps%d", i), spec.id, fmt.Sprintf("s%d", i))
	}

	return srv
}

type sectionRow struct {
	ID            *string `json:"id"`
	Name          *string `json:"name"`
	ProductsCount int     `json:"products_count"`
}

func getSections(t *testing.T, srv *Server) []sectionRow {
	t.Helper()

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1/sections", nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuSections(w, req)

	if w.Code != 200 {
		t.Fatalf("sections trả %d: %s", w.Code, w.Body.String())
	}

	var body struct {
		Data []sectionRow `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &body); err != nil {
		t.Fatalf("%v — %s", err, w.Body.String())
	}

	return body.Data
}

func TestLocalPosMenuSections_CountsAndKeepsUnassigned(t *testing.T) {
	srv := newSectionFixture(t)
	rows := getSections(t, srv)

	byID := map[string]sectionRow{}
	for _, r := range rows {
		key := "none"
		if r.ID != nil {
			key = *r.ID
		}
		byID[key] = r
	}

	if got := byID["sec-drink"].ProductsCount; got != 2 {
		t.Errorf("section có món: đếm %d, muốn 2", got)
	}

	// Section RỖNG vẫn phải xuất hiện: quán đã tạo nó, và pill "0 món" nói ra
	// điều đó — giấu đi thì người dựng menu tưởng mình chưa lưu.
	if r, ok := byID["sec-empty"]; !ok {
		t.Error("section rỗng biến mất khỏi thanh pill")
	} else if r.ProductsCount != 0 {
		t.Errorf("section rỗng đếm %d, muốn 0", r.ProductsCount)
	}

	// Nhóm CHƯA XẾP: `id = null` là hợp đồng để client gọi `?section_id=none`.
	// Món chưa xếp vẫn phải bán được — giấu chúng chính là #3159 ở dạng khác.
	if r, ok := byID["none"]; !ok {
		t.Fatal("nhóm chưa xếp bị bỏ — món không thuộc section nào sẽ không bán được")
	} else if r.ProductsCount != 1 || r.ID != nil {
		t.Errorf("nhóm chưa xếp: count=%d id=%v, muốn 1 và null", r.ProductsCount, r.ID)
	}
}

// Đếm ở thanh pill phải áp ĐÚNG bộ lọc availability của đường products. Lệch
// một chút là pill hiện "2 món" rồi mở ra thấy 1 — và lúc đó không con số nào
// trên màn hình còn đáng tin.
func TestLocalPosMenuSections_CountMatchesTheProductsEndpoint(t *testing.T) {
	srv := newSectionFixture(t)

	mustExec(t, srv.db, `UPDATE pos_menu_products SET is_active = 0 WHERE id = 'mp2'`)

	rows := getSections(t, srv)
	for _, r := range rows {
		if r.ID != nil && *r.ID == "sec-drink" && r.ProductsCount != 1 {
			t.Errorf("món tắt vẫn được đếm: %d, muốn 1", r.ProductsCount)
		}
	}

	ids := productIDs(t, srv, "?section_id=sec-drink")
	if len(ids) != 1 || ids[0] != "mp1" {
		t.Errorf("đường products trả %v, muốn [mp1] — hai đường phải cùng một bộ lọc", ids)
	}
}

func productIDs(t *testing.T, srv *Server, query string) []string {
	t.Helper()

	req := httptest.NewRequest("GET", "/api/v1/pos/menus/m1/products"+query, nil)
	req.SetPathValue("menu", "m1")
	w := httptest.NewRecorder()
	srv.handleLocalPosMenuProducts(w, req)

	if w.Code != 200 {
		t.Fatalf("products%s trả %d: %s", query, w.Code, w.Body.String())
	}

	var body struct {
		Data []struct {
			ID string `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &body); err != nil {
		t.Fatalf("%v — %s", err, w.Body.String())
	}

	out := make([]string, 0, len(body.Data))
	for _, r := range body.Data {
		out = append(out, r.ID)
	}

	return out
}

func TestLocalPosMenuProducts_FilterBySectionAndSku(t *testing.T) {
	srv := newSectionFixture(t)

	if got := productIDs(t, srv, "?section_id=sec-drink"); len(got) != 2 {
		t.Errorf("section_id trả %v, muốn 2 món", got)
	}

	// `none` phải TƯỜNG MINH: bỏ trống tham số đã mang nghĩa "mọi section", nên
	// không còn cách nào khác để hỏi riêng nhóm chưa xếp.
	if got := productIDs(t, srv, "?section_id=none"); len(got) != 1 || got[0] != "mp3" {
		t.Errorf("section_id=none trả %v, muốn [mp3]", got)
	}

	// Không truyền gì thì vẫn là CẢ MENU — đường cũ không được đổi nghĩa.
	if got := productIDs(t, srv, ""); len(got) != 3 {
		t.Errorf("không lọc trả %v, muốn cả 3 món", got)
	}

	// Tra theo SKU cho luồng SỬA MÓN: khi lưới thôi tải hết thực đơn, đây là
	// đường duy nhất còn lại để sửa một món đã đặt.
	if got := productIDs(t, srv, "?sku_id=s1"); len(got) != 1 || got[0] != "mp2" {
		t.Errorf("sku_id trả %v, muốn [mp2]", got)
	}
}
