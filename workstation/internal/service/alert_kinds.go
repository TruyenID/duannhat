package service

// Alert taxonomy for the workstation alert centre (#1806).
//
// The workstation has 143 `slog.Warn` call sites. This file is the decision
// about which of them are ALERTS, and it is deliberately an ALLOWLIST: a kind
// that is not registered here cannot be raised (RaiseAlert refuses it), so a
// warning added next week does not silently become a notification.
//
// The reason is not tidiness. A log line records that something HAPPENED; an
// alert asserts that a condition EXISTS, that a named person should act on it,
// and that it will end. Promoting all 143 builds a bell that rings all day —
// and a bell that rings all day is muted within a week, after which the one
// alert that mattered rings into a muted bell. Same shape as pos-web's
// `offline-cache-policy.ts`, whose allowlist exists for the same reason.

// AlertSeverity is how urgent — NOT who acts. See AlertAudience.
type AlertSeverity string

const (
	// SeverityCritical — money or service is stopping right now.
	SeverityCritical AlertSeverity = "critical"
	// SeverityWarning — degraded; it will hurt if left alone.
	SeverityWarning AlertSeverity = "warning"
	// SeverityInfo — worth knowing, nothing is broken.
	SeverityInfo AlertSeverity = "info"
)

// AlertAudience is who has to act — NOT how urgent.
//
// The two axes genuinely cross, which is why they are not one field: "this
// machine runs a binary older than dev" is audience=dev, yet severity=critical
// when the old build carries a known money bug. A single "level" field cannot
// express that cell, and that cell is the one that gets people hurt.
type AlertAudience string

const (
	// AudienceShop — staff or the manager standing in the shop can fix it.
	AudienceShop AlertAudience = "shop"
	// AudienceDev — nobody in the shop can fix it; it needs a deploy or a dev.
	AudienceDev AlertAudience = "dev"
)

// AlertResolution records HOW an alert ended. The two are not interchangeable:
// `auto` means the workstation re-measured and the condition was gone; `ack`
// means a human asserted it. Kinds with no probe may only end by `ack`.
type AlertResolution string

const (
	ResolutionAuto AlertResolution = "auto"
	ResolutionAck  AlertResolution = "ack"
)

// AlertKind identifies a condition. Adding one means adding it to alertRegistry
// below — that is the point.
type AlertKind string

const (
	// KindCashRetained — a 釣銭機 collection ended in timeout/abort/failure, so
	// the machine is STILL HOLDING the customer's cash. The heaviest kind here.
	KindCashRetained AlertKind = "cash_retained"
	// KindCashCollectedNotRecorded — máy ĐÃ thu tiền và thối tiền xong, nhưng
	// ghi dòng payment thất bại (#2535 B4). Tiền thật đã vào ngăn kéo mà sổ thì
	// không có gì.
	//
	// KHÔNG dùng lại `cash_retained`: ở đó việc phải làm là mở máy lấy tiền ra;
	// ở đây tiền nằm đúng chỗ và việc phải làm là ghi tay dòng thu rồi đối
	// soát. Gộp hai việc vào một cảnh báo là bảo nhân viên đi tìm thứ không
	// mất.
	KindCashCollectedNotRecorded AlertKind = "cash_collected_not_recorded"
	// KindCashDeviceAmbiguous — quán có NHIỀU HƠN MỘT máy 釣銭機 đang hoạt động
	// trong registry, mà tầng thu tiền chỉ biết tra "máy của quán" chứ không
	// biết "máy vừa chạy lượt này" (#2881).
	//
	// Trước #2881 tình huống này KHÔNG kêu gì cả: phép tra trả về một máy tuỳ
	// ý (`ORDER BY updated_at DESC LIMIT 1`), nên mã giao dịch của máy A đóng
	// lên phiên của máy B im lặng — tiền thu ở máy này ghi thành thu ở máy kia,
	// và đối soát (#2879) báo lệch ở CẢ HAI máy mà không ai hiểu vì sao.
	//
	// Cảnh báo chứ KHÔNG chặn bán: quán đang có khách, và một lượt thu không
	// truy nguyên được vẫn tốt hơn một quầy đứng hình. Cái mất là quy máy —
	// hàng vẫn ghi, chỉ không đẩy lên Cloud được (T1 bỏ qua hàng không có máy).
	KindCashDeviceAmbiguous AlertKind = "cash_device_ambiguous"
	// KindNoPrinter — a print was requested for a role with no printer bound.
	KindNoPrinter AlertKind = "no_printer"
	// KindNotPaired — the workstation has no branch, so every scoped call fails.
	KindNotPaired AlertKind = "not_paired"
	// KindSyncStalled — the sync queue has not drained for long enough that
	// money taken on this device is not in Cloud.
	KindSyncStalled AlertKind = "sync_stalled"
	// KindRealtimeClientDropped — a LAN client was closed because its send
	// buffer overflowed (#1793); it has missed events until it reconnects.
	KindRealtimeClientDropped AlertKind = "realtime_client_dropped"

	// #1806 S4 — máy trạm chạy bản cũ hơn bản HQ mong đợi.
	KindStaleBuild AlertKind = "stale_build"

	// #2635 — bản tự cài lúc 2h sáng CHẾT lúc khởi động; watchdog đã tự quay
	// về `.bak` (hoặc cố và thất bại). Một lần thay binary không người trông
	// mà kết thúc xấu phải để lại vết mà con người nhìn thấy được.
	KindAutoUpdateRollback AlertKind = "auto_update_rollback"

	// KindCloudMoneyOverwrite — Cloud tính lại một đơn ra số tiền KHÁC số máy
	// trạm đang giữ, và đã ghi đè (#2127 A).
	//
	// "Cloud thắng" là đúng — Cloud là sổ kế toán. Cái sai là thắng trong im
	// lặng: trước đây đây chỉ là một dòng `slog`, nên khoảng lệch biến mất khi
	// log xoay vòng và không ai đối soát lại được. Phiếu đã in từ số cũ thì vẫn
	// nằm trong tay khách.
	KindCloudMoneyOverwrite AlertKind = "cloud_money_overwrite"
)

// alertPolicy is the fixed shape of a kind. Severity and audience live here
// rather than at the call site so the same condition cannot be raised as
// critical in one file and info in another.
type alertPolicy struct {
	Severity AlertSeverity
	Audience AlertAudience
	// AutoResolvable is false when NOTHING the workstation can measure proves
	// the condition ended. Cash physically inside a machine is the case: the
	// only thing that settles it is a person opening the machine and counting,
	// so it must be acknowledged, never quietly cleared by a probe that only
	// knows the session is over.
	AutoResolvable bool

	// #1806 S3 — allowlist RIÊNG cho việc đẩy lên HQ, tách khỏi việc hiển thị
	// tại chỗ.
	//
	// Hai câu hỏi khác nhau: "người đứng quầy có cần thấy cái này không" và
	// "HQ ở xa có làm được gì với nó không". Một client realtime bị ngắt vì
	// LAN chập là chuyện của quán — đẩy lên HQ chỉ tạo nhiễu cho người không
	// cầm được sợi dây mạng đó. Tiền kẹt trong máy thì ngược lại.
	//
	// Mặc định `false`: thêm kind mới KHÔNG tự động lên HQ. Đúng chiều an toàn
	// — bỏ sót một alert đáng lên thì có người phàn nàn, còn đẩy nhầm hàng trăm
	// alert vặt lên thì HQ tắt thông báo và từ đó không thấy gì nữa.
	SyncUp bool
}

// AlertSyncsUp cho biết một kind có được đẩy lên HQ không.
func AlertSyncsUp(kind AlertKind) bool {
	p, ok := alertRegistry[kind]

	return ok && p.SyncUp
}

var alertRegistry = map[AlertKind]alertPolicy{
	KindCashRetained: {
		Severity: SeverityCritical,
		Audience: AudienceShop,
		// Deliberately NOT auto-resolvable — see the field comment.
		AutoResolvable: false,
		// Tiền kẹt trong máy là chuyện HQ phải biết: nó ảnh hưởng đối soát cuối
		// ca, và thường cần người từ ngoài quán vào xử lý.
		SyncUp: true,
	},
	KindCashDeviceAmbiguous: {
		Severity: SeverityWarning,
		Audience: AudienceShop,
		// Tự đóng được: quán gỡ bớt máy khỏi registry là hết mập mờ, và lượt
		// tra sau sẽ không dựng lại cảnh báo. Khác hai kind tiền ở trên — ở đó
		// thứ cần là NGƯỜI ghi tay, không phải cấu hình.
		AutoResolvable: true,
		// HQ là nơi cấu hình thiết bị được quản, nên đây đúng là việc của HQ.
		SyncUp: true,
	},
	KindCashCollectedNotRecorded: {
		Severity: SeverityCritical,
		Audience: AudienceShop,
		// Cùng lý do `cash_retained`: sổ chỉ khớp lại khi CÓ NGƯỜI ghi tay dòng
		// thu. Tự đóng nó ở lần đếm sau là xoá dấu vết của một khoản tiền mà
		// Cloud chưa bao giờ thấy.
		AutoResolvable: false,
		// Cloud không có dòng payment nào cho khoản này, nên HQ chỉ biết tới nó
		// qua đúng cảnh báo này.
		SyncUp: true,
	},
	KindNoPrinter: {
		Severity:       SeverityCritical,
		Audience:       AudienceShop,
		AutoResolvable: true, // a successful print on that role clears it
		// Quán không in được hoá đơn là việc HQ cần biết ngay — nó chặn bán
		// hàng và thường phải gửi máy hoặc người tới.
		SyncUp: true,
	},
	KindStaleBuild: {
		// `info`, không phải `warning`/`critical`: một máy trạm chạy bản cũ VẪN
		// BÁN ĐƯỢC HÀNG. Mức nghiêm trọng thật của từng bản vá do HQ gửi kèm và
		// nằm trong `detail.hq_severity` — nó KHÔNG được nâng mức của alert,
		// vì một kind chỉ có một mức và đổi mức theo từng lần dựng sẽ làm khoá
		// gộp `(kind, subject)` mang hai ý nghĩa khác nhau.
		Severity: SeverityInfo,
		// `shop`: assisted update đã làm cảnh báo actionable — Settings có nút
		// tải / cài & khởi động lại (gated khi còn ca mở). Trước đó audience=dev
		// vì quán không có đường cài; giờ họ có.
		Audience:       AudienceShop,
		AutoResolvable: true, // nâng cấp xong là tự đóng
		// KHÔNG đẩy lên HQ: chính HQ vừa gửi con số này xuống. Đẩy ngược lên là
		// báo cho họ một điều họ đã biết, và tạo một vòng dữ liệu đi lòng vòng.
		SyncUp: false,
	},

	KindAutoUpdateRollback: {
		// `warning`, không phải `critical`: máy ĐÃ tự quay về bản cũ và vẫn bán
		// được hàng. Cái hỏng là bản phát hành, không phải quán.
		Severity: SeverityWarning,
		// `dev`: quán không sửa được một binary chết lúc boot — người phải hành
		// động là người cắt bản phát hành kế tiếp.
		Audience: AudienceDev,
		// KHÔNG tự đóng: không phép đo nào của máy trạm chứng minh "đã có người
		// điều tra vì sao bản đó chết". Một lần rollback không người trông phải
		// được một con người nhìn thấy rồi ack.
		AutoResolvable: false,
		// Đẩy lên HQ: chính HQ bật cờ auto_apply cho bản này — họ là người duy
		// nhất dừng được rollout trước khi quán kế tiếp thử lại vào đêm sau.
		SyncUp: true,
	},

	KindNotPaired: {
		Severity:       SeverityCritical,
		Audience:       AudienceShop,
		AutoResolvable: true, // pairing clears it
		// KHÔNG đẩy lên: máy chưa ghép cặp thì cũng chưa có token để đẩy. Bật
		// cờ này sẽ tạo một vòng lặp không bao giờ chạy được — và tệ hơn, một
		// dòng cấu hình trông như đang bảo vệ điều gì đó.
		SyncUp: false,
	},
	KindSyncStalled: {
		Severity:       SeverityWarning,
		Audience:       AudienceShop,
		AutoResolvable: true, // the queue draining clears it
	},
	KindCloudMoneyOverwrite: {
		// `warning`, không phải `critical`: đơn vẫn bán được và Cloud vẫn là số
		// đúng. Cái hỏng là ĐỐI SOÁT, và nó hỏng theo giờ chứ không theo giây.
		Severity: SeverityWarning,
		// `shop`: người đối soát được khoảng lệch này là thu ngân/quản lý đang
		// cầm phiếu đã in và đếm được két, không phải một dev ở xa.
		Audience: AudienceShop,
		// KHÔNG tự đóng được. Máy trạm đo được rằng số đã đổi, nhưng không có
		// phép đo nào chứng minh **khoảng lệch đã được đối soát** — đó là một
		// hành động của người, ngoài tầm nhìn của tiến trình này. Một probe kiểu
		// "giờ hai bên đã bằng nhau" sẽ luôn đúng ngay sau khi Cloud ghi đè, tức
		// nó đóng alert đúng lúc chưa ai làm gì cả.
		AutoResolvable: false,
		// Đẩy lên HQ: đây là chênh lệch giữa GIẤY (phiếu đã in, tiền trong két)
		// và SỔ (Cloud). Nó đi vào 過不足 cuối ca, và HQ là bên duy nhất nhìn
		// được nó xảy ra ở bao nhiêu quán cùng lúc — một lỗi giá/thuế cấu hình
		// sai sẽ hiện ra ở đó trước khi từng quán tự nhận ra.
		SyncUp: true,
	},
	KindRealtimeClientDropped: {
		Severity: SeverityWarning,
		Audience: AudienceDev,
		// The client reconnects on its own; this records that it happened so a
		// "the POS was showing stale orders" report has something to land on.
		AutoResolvable: true,
	},
}

// AlertKindRegistered reports whether a kind may be raised at all.
func AlertKindRegistered(kind AlertKind) bool {
	_, ok := alertRegistry[kind]
	return ok
}

// AlertPolicyFor returns the fixed severity/audience/resolvability of a kind.
func AlertPolicyFor(kind AlertKind) (AlertSeverity, AlertAudience, bool, bool) {
	p, ok := alertRegistry[kind]
	if !ok {
		return "", "", false, false
	}
	return p.Severity, p.Audience, p.AutoResolvable, true
}

// RegisteredAlertKinds lists every allowed kind (stable order is not promised;
// callers that display them should sort).
func RegisteredAlertKinds() []AlertKind {
	kinds := make([]AlertKind, 0, len(alertRegistry))
	for k := range alertRegistry {
		kinds = append(kinds, k)
	}
	return kinds
}
