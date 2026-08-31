package service_test

import (
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

// #1806 S1 — mỗi alert kind phải có ÍT NHẤT MỘT nguồn gọi thật.
//
// Đây là test tồn tại vì một sự cố đã xảy ra: #1807 được đóng `COMPLETED` với
// bảng, store, registry và test đầy đủ — nhưng `git grep AlertStore` ra **0
// caller**. Toàn bộ hạ tầng là dead code, và 145 chỗ `slog.Warn` vẫn kêu vào
// một file log không ai mở. Không có gì đỏ, vì test cũ chỉ đo store nói chuyện
// với chính nó.
//
// Bài học không phải "viết thêm test cho store", mà là: **test đơn vị của một
// hạ tầng không bao giờ phát hiện được rằng không ai dùng nó**. Chỉ một phép
// quét toàn cây mới thấy.
//
// Test này quét mã nguồn thay vì chạy hệ thống, và đó là chủ đích: nó phải đỏ
// khi ai đó GỠ nguồn cuối cùng của một kind, kể cả khi mọi thứ khác vẫn chạy.
func TestEveryAlertKindHasARealCaller(t *testing.T) {
	root := repoRootForAlerts(t)

	// Tên hằng Go, không phải giá trị chuỗi: giá trị có thể xuất hiện trong
	// migration, fixture hay tài liệu, còn hằng thì chỉ xuất hiện ở nơi có mã
	// gọi thật.
	kinds := []string{
		"KindCashRetained",
		"KindCashCollectedNotRecorded",
		"KindCashDeviceAmbiguous",
		"KindNoPrinter",
		"KindNotPaired",
		"KindSyncStalled",
		"KindRealtimeClientDropped",
		"KindStaleBuild",
		"KindCloudMoneyOverwrite",
		"KindAutoUpdateRollback",
	}

	// Danh sách trên là tên HẰNG, cố ý (xem docblock) — mà tên hằng thì không
	// suy ra được từ registry, nên nó phải chép tay. Chép tay thì trôi: bản đầu
	// thiếu `KindStaleBuild`, tức kind đó **chưa bao giờ được canh** dù nó đã có
	// caller thật từ #1806 S4. Không ai thấy, vì thiếu một dòng trong danh sách
	// làm test chạy ÍT hơn chứ không đỏ.
	//
	// Đếm là cái rào rẻ nhất giữ hai bên khớp nhau: thêm kind vào registry mà
	// quên thêm vào đây thì đỏ ngay, kèm chỉ dẫn.
	if got, want := len(kinds), len(service.RegisteredAlertKinds()); got != want {
		t.Fatalf("danh sách quét có %d kind nhưng registry có %d.\n"+
			"Thêm tên hằng của kind mới vào `kinds` — thiếu nó thì kind ấy KHÔNG được canh, "+
			"và test vẫn xanh (đúng chuyện đã xảy ra với KindStaleBuild).", got, want)
	}

	callers := map[string][]string{}

	err := filepath.Walk(filepath.Join(root, "internal"), func(path string, info os.FileInfo, err error) error {
		if err != nil || info.IsDir() || !strings.HasSuffix(path, ".go") {
			return err
		}
		base := filepath.Base(path)
		// Bỏ qua chính định nghĩa và test — một kind chỉ được khai báo ở
		// `alert_kinds.go` thì vẫn là dead code.
		if strings.HasPrefix(base, "alert_") || strings.HasSuffix(base, "_test.go") {
			return nil
		}

		src, err := os.ReadFile(path)
		if err != nil {
			return err
		}

		// BỎ COMMENT trước khi tìm.
		//
		// Bản đầu của test này quét thẳng nội dung file, và phép thử hai chiều
		// cho thấy nó VÔ DỤNG: comment dòng gọi cuối cùng lại thì test vẫn
		// xanh, vì tên hằng còn nằm trong chính dòng comment đó. Một rào xanh
		// khi mã đã chết là rào tệ hơn không có rào — nó trả lời "có" cho câu
		// hỏi "chỗ này đã được canh chưa".
		code := stripLineComments(string(src))

		for _, k := range kinds {
			if strings.Contains(code, k) {
				rel, _ := filepath.Rel(root, path)
				callers[k] = append(callers[k], rel)
			}
		}

		return nil
	})
	if err != nil {
		t.Fatalf("quét cây nguồn: %v", err)
	}

	for _, k := range kinds {
		if len(callers[k]) == 0 {
			t.Errorf("alert kind %s KHÔNG có nguồn gọi nào — nó sẽ không bao giờ kêu.\n"+
				"Đây đúng là trạng thái mà #1807 bị đóng nhầm khi đang ở: hạ tầng có, "+
				"caller không.", k)
			continue
		}
		t.Logf("%-28s ← %s", k, strings.Join(callers[k], ", "))
	}
}

// #2695 — allowlist sync-UP cũng phải có ĐƯỜNG ĐẨY thật, cùng lý do như trên.
//
// Đây chính xác là lỗi mà `TestEveryAlertKindHasARealCaller` được viết ra để
// bắt, chỉ dịch sang một tầng khác và **không bị nó bắt**: từ #1806 S3 registry
// đã khai `SyncUp: true` cho bốn kind, Cloud đã có endpoint + controller viết
// kỹ, và test hai đầu đều xanh — nhưng không dòng Go nào gọi endpoint đó. Đo
// trên production 2026-08-13: 0 hàng `workstation.alert` trong `notifications`
// trên tổng 363 thông báo.
//
// Bài học lặp lại nguyên văn: **test đơn vị của một cờ không bao giờ phát hiện
// được rằng không ai đọc cờ đó.** Nên rào ở đây cũng phải là một phép quét cây
// nguồn, và nó phải đỏ khi ai đó gỡ lời gọi cuối cùng khỏi vòng đồng bộ.
func TestSyncUpAllowlistHasARealPushPath(t *testing.T) {
	root := repoRootForAlerts(t)

	// Phải có ít nhất một kind khai SyncUp — nếu không, cả bài toán này biến
	// mất mà rào vẫn xanh (mẫu số bằng không).
	syncUp := []string{}
	for _, k := range service.RegisteredAlertKinds() {
		if service.AlertSyncsUp(k) {
			syncUp = append(syncUp, string(k))
		}
	}
	if len(syncUp) == 0 {
		t.Fatal("không kind nào khai SyncUp — hoặc registry sai, hoặc rào này đang canh một tập rỗng")
	}

	const (
		endpoint  = "/api/v1/workstation/alerts"
		tickCall  = "maybePushAlertsUp()"
		pushOwner = "alert_sync_up.go"
	)

	var (
		endpointIn []string
		tickCallIn []string
	)

	err := filepath.Walk(filepath.Join(root, "internal"), func(path string, info os.FileInfo, err error) error {
		if err != nil || info.IsDir() || !strings.HasSuffix(path, ".go") || strings.HasSuffix(path, "_test.go") {
			return err
		}

		src, err := os.ReadFile(path)
		if err != nil {
			return err
		}
		// Bỏ comment trước khi tìm — một lời gọi đã bị comment không phải là
		// một lời gọi (bài học đã trả giá ở test ngay trên).
		code := stripLineComments(string(src))
		rel, _ := filepath.Rel(root, path)

		if strings.Contains(code, endpoint) {
			endpointIn = append(endpointIn, rel)
		}
		// Lời gọi từ NGOÀI file định nghĩa: một hàm chỉ tự gọi chính mình thì
		// vẫn là mã chết.
		if filepath.Base(path) != pushOwner && strings.Contains(code, tickCall) {
			tickCallIn = append(tickCallIn, rel)
		}

		return nil
	})
	if err != nil {
		t.Fatalf("quét cây nguồn: %v", err)
	}

	if len(endpointIn) == 0 {
		t.Errorf("KHÔNG mã Go nào nhắc tới %s — %d kind khai SyncUp sẽ không bao giờ rời khỏi quán (%s)",
			endpoint, len(syncUp), strings.Join(syncUp, ", "))
	}
	if len(tickCallIn) == 0 {
		t.Errorf("KHÔNG chỗ nào gọi %s ngoài %s — đường đẩy tồn tại nhưng không ai chạy nó.\n"+
			"Đúng trạng thái #2695 mở ra để sửa.", tickCall, pushOwner)
	}

	t.Logf("endpoint ← %s", strings.Join(endpointIn, ", "))
	t.Logf("nhịp gọi ← %s", strings.Join(tickCallIn, ", "))
	t.Logf("kind sync-UP: %s", strings.Join(syncUp, ", "))
}

// Emitter phải sống sót khi chưa được nối — nhiều test dựng Server/Hub trực
// tiếp, và một alert không được phép là lý do làm chết đường bán hàng.
func TestAlertEmitterIsNilSafe(t *testing.T) {
	defer func() {
		if r := recover(); r != nil {
			t.Fatalf("emitter nil gây panic: %v", r)
		}
	}()

	var e *service.AlertEmitter
	e.Raise(service.KindNoPrinter, "receipt_printer", "test", nil)
	e.Resolve(service.KindNoPrinter, "receipt_printer")

	if _, err := e.ListOpen(); err != nil {
		t.Fatalf("ListOpen trên emitter nil: %v", err)
	}
	if err := e.Ack("id", "ai đó"); err != nil {
		t.Fatalf("Ack trên emitter nil: %v", err)
	}
}

func repoRootForAlerts(t *testing.T) string {
	t.Helper()

	dir, err := os.Getwd()
	if err != nil {
		t.Fatalf("getwd: %v", err)
	}
	for i := 0; i < 6; i++ {
		if _, err := os.Stat(filepath.Join(dir, "go.mod")); err == nil {
			return dir
		}
		dir = filepath.Dir(dir)
	}
	t.Fatal("không tìm thấy go.mod")

	return ""
}

// stripLineComments bỏ phần `//` cuối dòng và các dòng chỉ có comment.
//
// Không phải parser Go đầy đủ — không cần: mục tiêu là không đếm một lời gọi đã
// bị comment. Chuỗi chứa "//" (hiếm ở đây) bị cắt oan là sai an toàn: nó chỉ có
// thể làm test ĐỎ nhầm, không thể làm test xanh nhầm.
func stripLineComments(src string) string {
	var b strings.Builder

	for _, line := range strings.Split(src, "\n") {
		if i := strings.Index(line, "//"); i >= 0 {
			line = line[:i]
		}
		b.WriteString(line)
		b.WriteByte('\n')
	}

	return b.String()
}

// #1806 S2 — alert phải ĐI RA được LAN, và phải đi ra ở cả hai chiều.
//
// Chỉ phát lúc dựng là chưa đủ: banner đỏ ở pos-web/KDS phải TỰ TẮT khi sự cố
// hết. Bắt người dùng tải lại trang để hết một banner là cách nhanh nhất khiến
// họ học được rằng banner đỏ không có nghĩa gì.
func TestAlertEmitterBroadcastsRaiseAndResolve(t *testing.T) {
	db := newAlertTestDB(t)
	e := service.NewAlertEmitter(service.NewAlertStore(db), nil)

	var events []string
	e.SetBroadcaster(func(eventType string, _ any) {
		events = append(events, eventType)
	})

	e.Raise(service.KindNoPrinter, "receipt_printer", "máy in mất kết nối", nil)
	e.Resolve(service.KindNoPrinter, "receipt_printer")

	if len(events) != 2 || events[0] != "alert.raised" || events[1] != "alert.resolved" {
		t.Fatalf("events = %v, muốn [alert.raised alert.resolved]", events)
	}
}

// Đường phát hỏng KHÔNG được làm hỏng việc dựng alert.
//
// Thứ tự quan trọng: ghi vào SQLite trước (nó sống qua restart), phát sau (nó
// chỉ tới được ai đang kết nối). Một hub đang chết không được phép làm mất bản
// ghi của sự cố.
func TestAlertSurvivesBroadcasterPanic(t *testing.T) {
	db := newAlertTestDB(t)
	store := service.NewAlertStore(db)
	e := service.NewAlertEmitter(store, nil)

	e.SetBroadcaster(func(string, any) { panic("hub chết") })

	e.Raise(service.KindNoPrinter, "receipt_printer", "máy in mất kết nối", nil)

	open, err := store.ListOpen()
	if err != nil {
		t.Fatalf("ListOpen: %v", err)
	}
	if len(open) != 1 {
		t.Fatalf("alert đã mất khi hub panic: có %d, muốn 1", len(open))
	}
}

func newAlertTestDB(t *testing.T) *store.DB {
	t.Helper()

	// storetest.Open chép một template ĐÃ migrate (~15ms) thay vì chạy lại mọi
	// migration — xem #1186.
	db, err := storetest.Open(filepath.Join(t.TempDir(), "alerts.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { _ = db.Close() })

	return db
}

// #1806 S3 — allowlist đẩy-lên-HQ là danh sách RIÊNG, và mặc định phải là KHÔNG.
//
// Bỏ sót một alert đáng lên thì có người phàn nàn; đẩy nhầm hàng trăm alert vặt
// lên thì HQ tắt thông báo và từ đó không thấy gì nữa. Hai sai lầm không đối
// xứng, nên mặc định nghiêng về phía im lặng.
func TestSyncUpAllowlistIsOptIn(t *testing.T) {
	cases := map[service.AlertKind]bool{
		service.KindCashRetained: true,  // ảnh hưởng đối soát cuối ca
		service.KindNoPrinter:    true,  // chặn bán hàng, thường phải gửi người
		service.KindNotPaired:    false, // chưa ghép cặp thì chưa có token để đẩy
	}

	for kind, want := range cases {
		if got := service.AlertSyncsUp(kind); got != want {
			t.Errorf("AlertSyncsUp(%s) = %v, muốn %v", kind, got, want)
		}
	}

	// Kind chưa đăng ký không bao giờ được lên.
	if service.AlertSyncsUp("khong_ton_tai") {
		t.Error("kind chưa đăng ký mà vẫn đẩy lên HQ")
	}
}

// `not_paired` KHÔNG được đẩy lên, và đó không phải sơ suất.
//
// Máy chưa ghép cặp thì chưa có token để gọi Cloud. Bật cờ cho nó sẽ tạo một
// vòng lặp không bao giờ chạy được — và tệ hơn, một dòng cấu hình trông như
// đang bảo vệ điều gì đó.
func TestNotPairedNeverSyncsUp(t *testing.T) {
	if service.AlertSyncsUp(service.KindNotPaired) {
		t.Fatal("not_paired đẩy lên HQ được — nhưng lúc đó chưa có token nào để đẩy")
	}
}

// #1806 S4 — ràng buộc của ruling "HQ đẩy xuống", ghim tại registry.
//
// Dễ bị "sửa cho hợp lý" sai: nâng severity cho nổi bật hơn, hoặc bật SyncUp
// cho HQ biết (HQ vừa gửi số này xuống). Audience = shop sau assisted update —
// quán đã có đường cài trong Settings.
func TestStaleBuildFollowsTheS4Ruling(t *testing.T) {
	severity, audience, autoResolvable, ok := service.AlertPolicyFor(service.KindStaleBuild)
	if !ok {
		t.Fatal("stale_build chưa đăng ký")
	}

	// info: một máy trạm chạy bản cũ VẪN BÁN ĐƯỢC HÀNG. Mức nghiêm trọng thật
	// của từng bản vá do HQ gửi kèm trong detail, không nâng mức của alert —
	// một kind chỉ có một mức, đổi theo từng lần dựng sẽ làm khoá gộp
	// (kind, subject) mang hai ý nghĩa khác nhau.
	if severity != service.SeverityInfo {
		t.Errorf("severity = %s, muốn info", severity)
	}

	// shop: assisted update làm cảnh báo actionable (Settings → tải/cài).
	// Trước đó là dev vì quán không có đường cài giữa ca.
	if audience != service.AudienceShop {
		t.Errorf("audience = %s, muốn shop", audience)
	}

	// nâng cấp xong là tự đóng; không bắt ai bấm xác nhận cho một việc đã xong.
	if !autoResolvable {
		t.Error("stale_build phải tự đóng sau khi nâng cấp")
	}

	// KHÔNG đẩy ngược lên HQ: chính HQ vừa gửi con số này xuống. Đẩy lên là báo
	// cho họ một điều họ đã biết, qua một vòng dữ liệu đi lòng vòng.
	if service.AlertSyncsUp(service.KindStaleBuild) {
		t.Error("stale_build đẩy ngược lên HQ — nhưng HQ chính là nơi gửi nó xuống")
	}
}

// #2127 A — mỗi service có `SetAlerts` phải ĐƯỢC NỐI trong `server.go`.
//
// Test kind-có-caller ở trên không phủ được chuyện này, và khoảng trống ấy đã
// đo được: gỡ dòng `s.sync.SetAlerts(s.alerts)` khỏi `server.go` thì **toàn bộ
// suite vẫn xanh** — kind vẫn có caller (`Raise` còn nguyên trong
// `sync_service.go`), test đơn vị vẫn dựng emitter thủ công, chỉ có production
// là im lặng vì `e.alerts` mãi mãi nil và `Raise` nil-safe nuốt gọn.
//
// Đó đúng hình dạng của #1807: hạ tầng đủ, caller đủ, dây nối thiếu, không gì
// đỏ. Nil-safe làm code khoẻ hơn VÀ làm lỗi này im hơn — nên chỗ nối phải được
// canh riêng.
func TestEveryServiceWithSetAlertsIsWiredInServer(t *testing.T) {
	root := repoRootForAlerts(t)

	// Bên khai: tìm `func (x *T) SetAlerts(` trong internal/service.
	decls := map[string]string{} // tên kiểu → file khai
	err := filepath.Walk(filepath.Join(root, "internal", "service"), func(path string, info os.FileInfo, err error) error {
		if err != nil || info.IsDir() || !strings.HasSuffix(path, ".go") || strings.HasSuffix(path, "_test.go") {
			return err
		}
		src, err := os.ReadFile(path)
		if err != nil {
			return err
		}
		for _, m := range setAlertsDecl.FindAllStringSubmatch(stripLineComments(string(src)), -1) {
			rel, _ := filepath.Rel(root, path)
			decls[m[1]] = rel
		}

		return nil
	})
	if err != nil {
		t.Fatalf("quét internal/service: %v", err)
	}
	if len(decls) == 0 {
		// Bộ đọc hỏng và "không có service nào" trông giống hệt nhau ở đầu ra:
		// vòng lặp dưới chạy 0 lần và bài test XANH.
		t.Fatal("không tìm thấy `SetAlerts` nào — bộ quét hỏng, không phải repo không có")
	}

	wiring, err := os.ReadFile(filepath.Join(root, "internal", "handler", "server.go"))
	if err != nil {
		t.Fatalf("đọc server.go: %v", err)
	}
	code := stripLineComments(string(wiring))

	n := strings.Count(code, ".SetAlerts(")
	if n < len(decls) {
		t.Errorf("có %d service khai `SetAlerts` (%v) nhưng `server.go` chỉ gọi %d lần.\n"+
			"Một service không được nối thì `Raise` của nó nil-safe nuốt mọi alert, "+
			"và KHÔNG có test nào khác đỏ.", len(decls), decls, n)
	}

	for typ, file := range decls {
		t.Logf("%-24s khai ở %s", typ, file)
	}
}

var setAlertsDecl = regexp.MustCompile(`func \(\w+ \*(\w+)\) SetAlerts\(`)
