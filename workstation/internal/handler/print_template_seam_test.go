package handler

import (
	"encoding/json"
	"path/filepath"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

// plan-053 T3.6 tầng 1 (#1913) + #1945.
//
// Seam nối formatter cũ và renderer template. Từ #1945 renderer **bật mặc định**
// (layer 0); chỉ explicit off quay về formatter cũ. Published brand cache opt-in
// qua print_template_use_published_templates.

func newSeamServer(t *testing.T) *Server {
	t.Helper()

	db, err := storetest.Open(filepath.Join(t.TempDir(), "seam.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	return &Server{db: db, printTemplates: service.NewPrintTemplateStore(db)}
}

func (s *Server) setSeamFlag(t *testing.T, value string) {
	t.Helper()
	s.setSetting(t, printTemplateRendererSetting, value)
}

func (s *Server) setSetting(t *testing.T, key, value string) {
	t.Helper()

	if _, err := s.db.Exec(
		`INSERT INTO settings (key, value) VALUES (?, ?)
		 ON CONFLICT(key) DO UPDATE SET value = excluded.value`,
		key, value,
	); err != nil {
		t.Fatalf("set setting %s: %v", key, err)
	}
}

// Không có dòng settings = BẬT (#1945). Chỉ explicit off tắt hẳn.
func TestSeam_AbsentSettingIsOn(t *testing.T) {
	s := newSeamServer(t)

	if !s.templateRendererEnabled() {
		t.Fatal("thiếu setting phải là BẬT — renderer mặc định cho mọi quán")
	}
}

// Explicit off tắt renderer (rollback khẩn).
func TestSeam_ExplicitOffDisablesRenderer(t *testing.T) {
	off := []string{"0", "false", "FALSE", " no ", "No"}

	s := newSeamServer(t)

	for _, v := range off {
		s.setSeamFlag(t, v)
		if s.templateRendererEnabled() {
			t.Errorf("%q phải TẮT renderer", v)
		}
	}
}

// Chỉ explicit off tắt; absent / rác vẫn BẬT renderer (layer 0).
func TestSeam_OnlyExplicitOffDisablesRenderer(t *testing.T) {
	stillOn := []string{"", "1", "true", "ture", "enabled", "null", "garbage"}

	s := newSeamServer(t)

	for _, v := range stillOn {
		s.setSeamFlag(t, v)
		if !s.templateRendererEnabled() {
			t.Errorf("%q phải BẬT renderer (mặc định)", v)
		}
	}
}

func TestSeam_PublishedTemplatesOffByDefault(t *testing.T) {
	s := newSeamServer(t)
	if s.templateUsePublishedTemplates() {
		t.Fatal("published cache phải TẮT mặc định")
	}
	s.setSetting(t, printTemplatePublishedSetting, "true")
	if !s.templateUsePublishedTemplates() {
		t.Fatal("true phải bật published cache")
	}
}

// Cờ BẬT (mặc định), cache có brand publish nhưng published TẮT ⇒ vẫn layer 0.
func TestSeam_IgnoresPublishedCacheByDefault(t *testing.T) {
	s := newSeamServer(t)
	// Không set renderer flag → ON. Không set published → system only.

	if _, err := s.db.Exec(`
		INSERT INTO print_templates (kind, version, scope, definition, effective_from, checksum, is_system_default, fetched_at)
		VALUES ('receipt', 99, 'brand', '{"kind":"receipt","blocks":[]}', '', 'deadbeef', 0, datetime('now'))
	`); err != nil {
		t.Fatalf("seed brand row: %v", err)
	}

	sentinel := []byte("LEGACY-SENTINEL")
	got, _ := s.renderMoneySlip(
		seamShiftOpenData(),
		service.PrintRenderProfile{Columns: 42},
		"ja",
		func() []byte { return sentinel },
	)

	if len(got) == 0 {
		t.Fatal("không ra byte nào")
	}
	if string(got) == string(sentinel) {
		t.Fatal("phải đi renderer layer 0, không legacy")
	}
}

// published=true ⇒ resolve cache brand (opt-in sau #1945).
func TestSeam_UsesPublishedCacheWhenEnabled(t *testing.T) {
	s := newSeamServer(t)
	s.setSetting(t, printTemplatePublishedSetting, "true")

	def := seamBrandTemplate(t, "shift_open", "PUBLISHED-FOOTER-1945")
	if _, err := s.db.Exec(`
		INSERT INTO print_templates (kind, version, scope, definition, effective_from, checksum, is_system_default, fetched_at)
		VALUES ('shift_open', 99, 'brand', ?, '', 'seam-brand-checksum', 0, datetime('now'))`,
		string(def),
	); err != nil {
		t.Fatalf("seed brand row: %v", err)
	}

	_, version := s.renderMoneySlip(
		seamShiftOpenData(),
		service.PrintRenderProfile{Columns: 42},
		"ja",
		func() []byte { return []byte("LEGACY-SENTINEL") },
	)

	if version != "brand:99" {
		t.Fatalf("published=true phải dùng cache brand, nhận %q", version)
	}
}

func seamBrandTemplate(t *testing.T, kind, footerJA string) []byte {
	t.Helper()
	raw, err := service.SystemPrintTemplateRaw(kind)
	if err != nil {
		t.Fatalf("system default: %v", err)
	}
	var doc map[string]any
	if err := json.Unmarshal(raw, &doc); err != nil {
		t.Fatalf("unmarshal default: %v", err)
	}
	blocks, _ := doc["blocks"].([]any)
	for _, b := range blocks {
		blk, _ := b.(map[string]any)
		if blk["id"] == "footer_text" {
			blk["enabled"] = true
			blk["i18n"] = map[string]any{"ja": footerJA, "en": footerJA, "vi": footerJA}
		}
	}
	out, err := json.Marshal(doc)
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}
	return out
}

// Cờ TẮT (explicit) ⇒ byte của formatter cũ, KHÔNG đọc store.
func TestSeam_FlagOffReturnsLegacyBytes(t *testing.T) {
	s := newSeamServer(t)
	s.setSeamFlag(t, "false")

	legacyCalled := false
	want := []byte("LEGACY-BYTES")

	got, _ := s.renderMoneySlip(
		&service.PrintRenderData{Kind: "receipt"},
		service.PrintRenderProfile{Columns: 48},
		"ja",
		func() []byte { legacyCalled = true; return want },
	)

	if !legacyCalled {
		t.Fatal("cờ TẮT thì phải gọi formatter cũ")
	}

	if string(got) != string(want) {
		t.Fatalf("byte không phải của formatter cũ: %q", got)
	}
}

// Cờ BẬT nhưng store nil ⇒ vẫn phải in được. Không có store thì không có gì để
// render từ, và một phiếu không in ra là một khách đứng chờ ở quầy.
func TestSeam_FlagOnWithNilStoreStillPrints(t *testing.T) {
	s := newSeamServer(t)
	s.setSeamFlag(t, "true")
	s.printTemplates = nil

	got, _ := s.renderMoneySlip(
		&service.PrintRenderData{Kind: "receipt"},
		service.PrintRenderProfile{Columns: 48},
		"ja",
		func() []byte { return []byte("LEGACY-BYTES") },
	)

	if string(got) != "LEGACY-BYTES" {
		t.Fatalf("store nil phải rơi về formatter cũ, nhận %q", got)
	}
}

// Cờ BẬT, cache RỖNG ⇒ `RenderSlip` rơi về bản mặc định hệ thống và PHẢI ra
// byte thật, không phải rỗng và không phải byte của `legacy()`.
//
// Đây là ca nối tầng này với cổng TR-40: bản mặc định hệ thống đã được
// `TestPrintRenderer_MigrationGate_ByteIdenticalWithLegacyFormatter` chứng minh
// byte-identical với formatter cũ, nên "bật cờ trên cache rỗng" là an toàn về
// mặt byte. Ca này chỉ ghim rằng đường đó THẬT SỰ được đi, chứ không lặng lẽ rơi
// về `legacy()` và giả vờ như đã render.
func TestSeam_FlagOnEmptyCacheRendersFromSystemDefault(t *testing.T) {
	s := newSeamServer(t)
	s.setSeamFlag(t, "true")

	sentinel := []byte("LEGACY-SENTINEL")

	got, _ := s.renderMoneySlip(
		seamShiftOpenData(),
		service.PrintRenderProfile{Columns: 42},
		"ja",
		func() []byte { return sentinel },
	)

	if len(got) == 0 {
		t.Fatal("không ra byte nào")
	}

	if string(got) == string(sentinel) {
		t.Fatal("rơi về formatter cũ — đường renderer template KHÔNG được đi, " +
			"nên cờ bật cũng vô nghĩa")
	}
}

// Dữ liệu tối thiểu cho một kind mà bản mặc định hệ thống render được mà không
// cần đơn hàng. `shift_open` được chọn vì nó không đọc Order/Items — ca này đo
// SEAM, không đo emitter.
func seamShiftOpenData() *service.PrintRenderData {
	return &service.PrintRenderData{
		Kind: "shift_open",
		Config: service.PrintJobConfig{
			StoreName:  "SEAM TEST",
			PaperWidth: 42,
			Locale:     "ja",
		},
		ShiftOpen: &service.ShiftOpenReportInfo{},
	}
}

// ── TR-28 (#1171): dấu phiên bản phải RA KHỎI seam ────────────────────────
//
// Trước #1171 seam ĐÃ biết phiên bản — `RenderSlip` trả `src`, và `src.Version`
// được đưa vào `slog.Info` — rồi vứt đi ngay dòng sau. Log không phải sổ: sau
// khi tờ giấy ra khỏi máy in, "phiếu này in bằng layout nào" không còn chỗ nào
// trả lời được. Hai ca dưới ghim đúng cái ranh giới đó.

// Đường LEGACY không có phiên bản, và đó là một trạng thái THẬT.
//
// Formatter cũ là mã nguồn, không phải definition đã publish; nó không mang số
// hiệu nào. Trả về `system:0` ở đây sẽ khiến một lần in lại đi tìm bản mặc định
// hệ thống trong khi tờ gốc do formatter vẽ — hai tài liệu khác nhau, không ai
// phát hiện. Rỗng ⇒ NULL trong SQLite, phân biệt được với mọi dấu có thật.
func TestSeam_LegacyPathReportsNoVersion(t *testing.T) {
	s := newSeamServer(t)
	s.setSeamFlag(t, "false")

	_, version := s.renderMoneySlip(
		&service.PrintRenderData{Kind: "receipt"},
		service.PrintRenderProfile{Columns: 48},
		"ja",
		func() []byte { return []byte("LEGACY-BYTES") },
	)

	if version != "" {
		t.Fatalf("đường legacy phải KHÔNG có phiên bản, nhận %q — "+
			"một giá trị bịa ra ở đây là một khẳng định sai về tờ giấy đã in", version)
	}
}

// Store nil (máy chưa pair, TR-05) cũng là đường legacy — cùng lý do.
func TestSeam_NilStoreReportsNoVersion(t *testing.T) {
	s := newSeamServer(t)
	s.setSeamFlag(t, "true")
	s.printTemplates = nil

	_, version := s.renderMoneySlip(
		&service.PrintRenderData{Kind: "receipt"},
		service.PrintRenderProfile{Columns: 48},
		"ja",
		func() []byte { return []byte("LEGACY-BYTES") },
	)

	if version != "" {
		t.Fatalf("không có store thì không có definition nào để khai, nhận %q", version)
	}
}

// Đường TEMPLATE phải khai đúng nguồn đã vẽ ra byte. Cache rỗng ⇒ bản mặc định
// hệ thống ⇒ `system:0`.
//
// Ca này là nửa còn lại của cặp: nếu chỉ kiểm nhánh legacy thì một seam luôn
// trả "" vẫn xanh, và cột `template_version` sẽ vĩnh viễn NULL trên mọi hàng.
func TestSeam_TemplatePathReportsTheVersionThatDrewIt(t *testing.T) {
	s := newSeamServer(t)
	s.setSeamFlag(t, "true")

	slip, version := s.renderMoneySlip(
		seamShiftOpenData(),
		service.PrintRenderProfile{Columns: 42},
		"ja",
		func() []byte { return []byte("LEGACY-SENTINEL") },
	)

	if string(slip) == "LEGACY-SENTINEL" {
		t.Fatal("rơi về formatter cũ — ca này không đo được gì")
	}
	if version != "system:0" {
		t.Fatalf("phiên bản = %q, muốn system:0 (cache rỗng ⇒ bản mặc định hệ thống)", version)
	}
}
