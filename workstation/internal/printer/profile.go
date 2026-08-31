package printer

import (
	"encoding/json"
	"unicode"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// Capability profile — plan-052 T1.4b / T1.4c, DESIGN §3b (#1166).
//
// A profile describes what a MACHINE can do. It never describes what a slip
// SAYS: the content is one template for every printer in the fleet. The
// renderer reads a profile to pick the way OUT (native text or a bitmap, which
// cut command, whether the drawer can be kicked at all) — which is why this is
// data, and why there is no per-model branch anywhere in the formatters.
//
// This mirrors backend/app/Services/Printing/PrinterCapabilityProfile.php field
// for field. The two sides must agree because Cloud is where a shop edits the
// profile and the workstation is where it is applied.

// TextMode selects how a block of text reaches the paper.
const (
	TextModeAuto   = "auto"
	TextModeNative = "native"
	TextModeRaster = "raster"
)

// Cut modes. `CutNone` is a real answer, not an absence: a tear-bar machine
// must be sent NO cut command at all (P-36) — some cheap firmware prints an
// unrecognised escape sequence as literal garbage onto the next slip.
const (
	CutNone       = "none"
	CutGsVFull    = "gs_v_full"
	CutGsVPartial = "gs_v_partial"
	CutEscD       = "esc_d"
	// #3059 — bản cắt DỞ của phương ngữ Star (ESC d 2). Cần riêng vì máy Star
	// lờ `GS V` (#438), nên `gs_v_partial` trên máy Star = KHÔNG cắt.
	CutEscDPartial = "esc_d_partial"
)

// Error-detect levels (DESIGN §3b, A/B/C).
const (
	ErrorDetectNone       = "none"        // A — we know the socket accepted bytes. Nothing more.
	ErrorDetectStatusBack = "status_back" // B — ASB / DLE EOT status byte.
	ErrorDetectProtocol   = "protocol"    // C — ePOS / WebPRNT / CloudPRNT confirm.
)

// Health probe methods.
const (
	HealthTCPDial      = "tcp_dial"
	HealthDLEEOT       = "dle_eot"
	HealthHTTPPing     = "http_ping"
	HealthPollSilence  = "poll_silence"
	QuirkReconnectJobs = "reconnect_between_jobs"
	QuirkSlowRaster    = "slow_raster"
)

type CharsetProfile struct {
	Kanji     bool     `json:"kanji"`
	Codepages []string `json:"codepages,omitempty"`
}

type CutProfile struct {
	Mode string `json:"mode"`
	// FeedBeforeCut is a physical quirk, not a preference: the distance from
	// the print head to the blade differs per chassis, and too little feed
	// slices the last line off the slip. Data, so a shop fixes it without a
	// release.
	FeedBeforeCut int  `json:"feed_before_cut"`
	AutoCutPerJob bool `json:"auto_cut_per_job"`
}

type DrawerKickProfile struct {
	Supported bool `json:"supported"`
	Pin       int  `json:"pin"`
	OnMs      int  `json:"on_ms"`
	OffMs     int  `json:"off_ms"`
}

type BuzzerProfile struct {
	Supported bool `json:"supported"`
}

type FinishingProfile struct {
	Cut        CutProfile        `json:"cut"`
	DrawerKick DrawerKickProfile `json:"drawer_kick"`
	Buzzer     BuzzerProfile     `json:"buzzer"`
}

type ErrorDetectProfile struct {
	Level         string `json:"level"`
	ASB           bool   `json:"asb"`
	DLEEOT        bool   `json:"dle_eot"`
	PollIntervalS int    `json:"poll_interval_s"`
}

type HealthProfile struct {
	Method             string `json:"method"`
	IntervalS          int    `json:"interval_s"`
	TimeoutMs          int    `json:"timeout_ms"`
	OfflineAfterMisses int    `json:"offline_after_misses"`
}

type Profile struct {
	Preset      string             `json:"preset,omitempty"`
	Transports  []string           `json:"transports,omitempty"`
	Charset     CharsetProfile     `json:"charset"`
	TextMode    string             `json:"text_mode"`
	Finishing   FinishingProfile   `json:"finishing"`
	ErrorDetect ErrorDetectProfile `json:"error_detect"`
	Health      HealthProfile      `json:"health"`
	Columns     map[string]int     `json:"columns,omitempty"`
	Quirks      []string           `json:"quirks,omitempty"`

	// Configured reports that this profile came from a value somebody actually
	// STORED for this machine, rather than from DefaultProfile() standing in
	// for a `printers.model_profile` nobody has ever filled.
	//
	// `Preset` cannot answer that: an un-configured machine and a machine
	// explicitly set to `escpos_generic` both read "escpos_generic", and the
	// difference matters wherever a default would otherwise be mistaken for a
	// declaration — see PrintRenderProfileFor, where treating the two alike
	// silently changed the cut command for every shop that never ran the setup
	// wizard.
	//
	// `json:"-"` on purpose: it describes WHERE the profile came from, not what
	// the machine can do, so it must never round-trip through storage. A stored
	// blob is Configured by definition of having been stored.
	Configured bool `json:"-"`
}

// DefaultProfile is `escpos_generic` (P-29): the fallback for a machine nobody
// has ever described. Chosen so the cheap marketplace ESC/POS box a shop
// actually bought prints something readable on the first try — plain text, a
// full cut, NO drawer pulse (a wrong pin can jam a till), and the honest
// admission that it cannot report its own errors.
func DefaultProfile() Profile {
	return Profile{
		Preset:     "escpos_generic",
		Transports: []string{"ws_lan"},
		Charset:    CharsetProfile{Kanji: false, Codepages: []string{"CP437", "CP858"}},
		TextMode:   TextModeAuto,
		Finishing: FinishingProfile{
			Cut:        CutProfile{Mode: CutGsVFull, FeedBeforeCut: 4},
			DrawerKick: DrawerKickProfile{Supported: false, Pin: 2, OnMs: 120, OffMs: 240},
			Buzzer:     BuzzerProfile{Supported: false},
		},
		ErrorDetect: ErrorDetectProfile{Level: ErrorDetectNone, PollIntervalS: 30},
		Health:      HealthProfile{Method: HealthTCPDial, IntervalS: 60, TimeoutMs: 3000, OfflineAfterMisses: 3},
		Columns:     map[string]int{"58mm": 32, "80mm": 48},
	}
}

// presets are the shapes we have actually seen. Adding a machine is adding an
// entry here (or filling `model_profile` from the setup wizard) — never a
// branch in a formatter.
func presets() map[string]Profile {
	generic := DefaultProfile()

	epson := DefaultProfile()
	epson.Preset = "epson_tm_i"
	epson.Transports = []string{"ws_lan", "epos_http"}
	epson.Charset = CharsetProfile{Kanji: true, Codepages: []string{"CP932", "CP437"}}
	epson.TextMode = TextModeNative
	epson.Finishing.Cut = CutProfile{Mode: CutGsVPartial, FeedBeforeCut: 4}
	epson.Finishing.DrawerKick = DrawerKickProfile{Supported: true, Pin: 2, OnMs: 120, OffMs: 240}
	epson.Finishing.Buzzer = BuzzerProfile{Supported: true}
	epson.ErrorDetect = ErrorDetectProfile{Level: ErrorDetectProtocol, ASB: true, DLEEOT: true, PollIntervalS: 30}
	epson.Health = HealthProfile{Method: HealthHTTPPing, IntervalS: 60, TimeoutMs: 3000, OfflineAfterMisses: 3}

	star := DefaultProfile()
	star.Preset = "star_mcprint"
	star.Transports = []string{"ws_lan", "webprnt", "cloudprnt"}
	star.Charset = CharsetProfile{Kanji: true, Codepages: []string{"CP932", "CP437"}}
	star.TextMode = TextModeNative
	star.Finishing.Cut = CutProfile{Mode: CutEscD, FeedBeforeCut: 3}
	star.Finishing.DrawerKick = DrawerKickProfile{Supported: true, Pin: 1, OnMs: 100, OffMs: 200}
	star.ErrorDetect = ErrorDetectProfile{Level: ErrorDetectStatusBack, ASB: true, DLEEOT: true, PollIntervalS: 30}
	star.Health = HealthProfile{Method: HealthDLEEOT, IntervalS: 60, TimeoutMs: 3000, OfflineAfterMisses: 3}
	// The RAW port takes ONE TCP session at a time, so a held-open connection
	// makes the SECOND job of a burst fail (same single-slot behaviour
	// Manager.probeEach already works around when probing).
	star.Quirks = []string{QuirkReconnectJobs}

	return map[string]Profile{
		"escpos_generic": generic,
		"epson_tm_i":     epson,
		"star_mcprint":   star,
	}
}

// Preset returns a named profile, or the generic fallback for a name we do not
// know. Refusing to print because a config value is unfamiliar would be a
// worse failure than printing with safe defaults (P-29).
func Preset(name string) Profile {
	if p, ok := presets()[name]; ok {
		return p
	}
	return DefaultProfile()
}

// ParseProfile resolves the JSON stored in `printers.model_profile`.
//
// Everything merges over the generic base, so:
//   - an empty/absent/corrupt value yields a working profile (P-29);
//   - a wizard run that stopped after two questions keeps those two answers and
//     defaults the rest (P-40) — partial knowledge beats none.
//
// UndescribedProfile is what a machine NOBODY HAS DESCRIBED gets.
//
// #1965 — it exists because `DefaultProfile()` and an explicit `escpos_generic`
// were indistinguishable: both carry `Preset == "escpos_generic"` and
// `Cut.Mode == gs_v_full`. A DEFAULT was therefore read as a DECLARATION, and
// #1950 acted on it by cutting with `GS V 0` — which Star mC-Print in StarPRNT
// emulation ignores. Every shop that had not run the setup wizard stopped
// cutting paper: no error, no log, the slip simply never separates.
//
// The only difference from `DefaultProfile()` is the cut dialect, and the
// narrowness is deliberate: P-29 depends on the generic columns, charset and
// drawer defaults still applying to an unconfigured machine.
//
// `ESC d 3` is not a claim that ESC d is more correct in general — on a true
// Epson it only feeds. It is a claim about which wrong guess is SAFE. Guessing
// `GS V` on a Star produces a slip that never separates and says nothing;
// guessing `ESC d` on an Epson produces a slip that feeds and can still be
// torn. Silence on the machine most shops actually own is the worse failure,
// and `ESC d 3` is exactly what every print path emitted before #1950.
//
// Kept separate from `DefaultProfile()` rather than changing it, because the
// presets BUILD on the default and a shop that chooses `escpos_generic` on
// purpose has described its machine and must keep `GS V`.
func UndescribedProfile() Profile {
	p := DefaultProfile()
	p.Finishing.Cut.Mode = CutEscD
	p.Finishing.Cut.FeedBeforeCut = 3

	return p
}

// UndescribedProfileForCut — hồ sơ của máy CHƯA AI MÔ TẢ, nhưng có lời khai
// `printers.cut_type`.
//
// #3059: cột đó được đồng bộ từ Cloud, hiện trên màn cấu hình, người lắp máy
// chọn giá trị cho nó — và **không chỗ nào đọc**. Đo được: năm chỗ nhắc tới
// `CutType` trong cây Go đều là khai struct, gán mặc định, nhận từ feed. Lệnh
// cắt thì luôn là hằng số cứng `ESC d 3`.
//
// Nên hôm nay `none` vẫn nhả một lệnh cắt (máy có dao tự động ⇒ thừa một tờ
// trắng mỗi lượt) và `partial` vẫn cắt rời.
//
// ## Vì sao cut_type KHÔNG chọn phương ngữ
//
// `cut_type` nói quán muốn GÌ; `model_profile` nói máy nói TIẾNG NÀO. Máy đã có
// profile thật thì profile thắng — nó là lời khai giàu hơn, và nó biết máy là
// Star hay ESC/POS. Chỉ khi không ai mô tả máy thì mới rơi về đây, và ở đó
// phương ngữ mặc định là ESC d (xem {@link UndescribedProfile}).
//
// Vì vậy `partial` ánh xạ sang `esc_d_partial`, KHÔNG phải `gs_v_partial`: máy
// Star lờ GS V (#438), nên chọn nhầm phương ngữ sẽ biến "cắt dở" thành "không
// cắt gì" — im lặng, và chỉ lộ ra khi quán cầm tờ giấy chưa đứt.
//
// Giá trị lạ rơi về hành vi hôm nay. Một chuỗi không đọc được KHÔNG phải lý do
// để thôi cắt giấy của quán.
// ProfileForRow dựng hồ sơ từ MỘT hàng `printers`: cột `model_profile` và cột
// `cut_type`.
//
// Thứ tự nhường quyền là điều duy nhất cần nhớ ở đây: **profile thật thắng**.
// Nó giàu hơn (phương ngữ cắt, feed theo khung máy, chân ngăn kéo, cách dò lỗi)
// và nó đến từ người đã thật sự mô tả cái máy đó. `cut_type` chỉ nói lên một
// mong muốn, và chỉ được quyết định khi **không ai mô tả máy** — trạng thái của
// cả ba máy ở production lúc viết dòng này (`model_profile` NULL, `cut_type`
// `full`).
//
// Cố ý rẽ theo `Configured` chứ không theo chuỗi rỗng: `{}`, `null` và JSON
// hỏng đều parse ra một hồ sơ KHÔNG được khai, và cả ba đều phải rơi về đây —
// đúng phân biệt mà #1965 phải trả giá mới có.
func ProfileForRow(rawProfile, cutType string) Profile {
	p := ParseProfile(rawProfile)
	if p.Configured {
		return p
	}

	return UndescribedProfileForCut(cutType)
}

func UndescribedProfileForCut(cutType string) Profile {
	p := UndescribedProfile()

	switch cutType {
	case "none":
		p.Finishing.Cut.Mode = CutNone
	case "partial":
		p.Finishing.Cut.Mode = CutEscDPartial
	}

	return p
}

func ParseProfile(raw string) Profile {
	base := DefaultProfile()
	if raw == "" {
		// #1965 — NULL `model_profile`. Nobody has described this machine.
		return UndescribedProfile()
	}

	var declared map[string]json.RawMessage
	if err := json.Unmarshal([]byte(raw), &declared); err != nil {
		// Garbage in the column is not a statement about the machine either.
		return UndescribedProfile()
	}

	// `{}` and `null` parse cleanly and say NOTHING. That is the same state as
	// a NULL column, so it gets the same answer — treating it as a declaration
	// would leave exactly those shops silently uncut again.
	if len(declared) == 0 {
		return UndescribedProfile()
	}

	if presetRaw, ok := declared["preset"]; ok {
		var name string
		if json.Unmarshal(presetRaw, &name) == nil {
			base = Preset(name)
		}
	}

	// Unmarshalling the declared object ON TOP of the resolved base leaves
	// every field the shop did not mention exactly as the base had it — Go's
	// decoder only writes the keys that are present.
	if err := json.Unmarshal([]byte(raw), &base); err != nil {
		return DefaultProfile()
	}

	// Reached only on a value that parsed: somebody described this machine.
	// The corrupt-JSON returns above deliberately stay un-Configured — an
	// unreadable blob is not a description, and callers that fall back on
	// "nobody told us" must fall back for it too.
	base.Configured = true

	return base.normalised()
}

// normalised replaces values we cannot act on with the safest ones we can. An
// unknown cut command or health method must never reach a machine.
func (p Profile) normalised() Profile {
	switch p.TextMode {
	case TextModeNative, TextModeRaster, TextModeAuto:
	default:
		p.TextMode = TextModeAuto
	}

	// Danh sách này phải liệt kê MỌI chế độ mà `escpos.Finish` biết phát, không
	// phải mọi chế độ mà người viết nhớ ra.
	//
	// #3059 thêm `esc_d_partial` vào hằng số và vào `Finish`, nhưng KHÔNG thêm
	// vào đây — nên một hồ sơ mang chế độ đó parse ra `none`, tức THÔI CẮT.
	// Đường đi tới nó có thật và không ai thấy: quán khai `cut_type = partial`
	// (chưa có `model_profile`) ⇒ `UndescribedProfileForCut` cho `esc_d_partial`
	// ⇒ người lắp máy chạy thuật sĩ cài máy in ⇒ `handlePrinterProfileAnswers`
	// LƯU nguyên chuỗi đó vào `printers.model_profile` ⇒ lượt đọc kế tiếp
	// `ParseProfile` chạy qua đây và đổi nó thành `none`. Đo được: byte kết thúc
	// tờ giấy còn đúng `0A 0A 0A` — ba dòng trắng, không một lệnh cắt nào.
	//
	// Chính thuật sĩ ấy vừa hỏi "máy có cắt không?" và được trả lời CÓ, nên
	// không ai đi tìm nguyên nhân ở đó.
	switch p.Finishing.Cut.Mode {
	case CutNone, CutGsVFull, CutGsVPartial, CutEscD, CutEscDPartial:
	default:
		p.Finishing.Cut.Mode = CutNone
	}
	if p.Finishing.Cut.FeedBeforeCut < 0 {
		p.Finishing.Cut.FeedBeforeCut = 0
	}

	switch p.ErrorDetect.Level {
	case ErrorDetectNone, ErrorDetectStatusBack, ErrorDetectProtocol:
	default:
		p.ErrorDetect.Level = ErrorDetectNone
	}

	switch p.Health.Method {
	case HealthTCPDial, HealthDLEEOT, HealthHTTPPing, HealthPollSilence:
	default:
		p.Health.Method = HealthTCPDial
	}

	if p.Columns == nil {
		p.Columns = map[string]int{"58mm": 32, "80mm": 48}
	}

	return p
}

// TextModeFor is the ONE decision the renderer needs per block of text (P-30).
//
// `native` and `raster` are explicit operator choices and are obeyed as given.
// `auto` works it out: a machine with a kanji ROM prints natively (fast); a
// machine without one turns a block CONTAINING characters outside its
// codepages into a bitmap, and leaves the numbers and money native —
// rasterising the whole slip on a slow head is how a queue backs up mid-rush.
func (p Profile) TextModeFor(block string) string {
	switch p.TextMode {
	case TextModeNative, TextModeRaster:
		return p.TextMode
	}

	if p.Charset.Kanji {
		return TextModeNative
	}

	for _, r := range block {
		if r > unicode.MaxASCII {
			return TextModeRaster
		}
	}
	return TextModeNative
}

// CutsPaper — P-36. False means send NOTHING; feed instead and stop.
func (p Profile) CutsPaper() bool { return p.Finishing.Cut.Mode != CutNone }

// CanKickDrawer — P-37. When false the UI must HIDE the open-drawer button and
// warn on a cash tender, rather than swallowing the press in silence while a
// cashier stands there pressing it again.
func (p Profile) CanKickDrawer() bool { return p.Finishing.DrawerKick.Supported }

// PrintConfidence — P-33 [HARD]. A level-A machine can only ever earn
// `sent_only`; nothing may promote that later.
func (p Profile) PrintConfidence() string {
	if p.ErrorDetect.Level == ErrorDetectNone {
		return "sent_only"
	}
	return "confirmed"
}

// SupportsPreflightStatus — P-34. Only a machine that can answer is worth
// asking before a receipt or an invoice goes out.
func (p Profile) SupportsPreflightStatus() bool { return p.ErrorDetect.Level != ErrorDetectNone }

// ReconnectBetweenJobs — P-32. The dialer opens a fresh connection per job
// instead of holding one. If it still fails, the ordinary retry matrix per
// kind applies; this quirk gets no special case.
func (p Profile) ReconnectBetweenJobs() bool { return p.HasQuirk(QuirkReconnectJobs) }

func (p Profile) HasQuirk(quirk string) bool {
	for _, q := range p.Quirks {
		if q == quirk {
			return true
		}
	}
	return false
}

// ColumnsFor resolves the printable character width for a paper size.
func (p Profile) ColumnsFor(paperWidthMm int) int {
	key := "80mm"
	fallback := 48
	if paperWidthMm <= 58 {
		key, fallback = "58mm", 32
	}
	if n, ok := p.Columns[key]; ok && n > 0 {
		return n
	}
	return fallback
}

// SupportsTransport reports whether this machine can speak a transport at all.
func (p Profile) SupportsTransport(transport string) bool {
	for _, t := range p.Transports {
		if t == transport {
			return true
		}
	}
	return false
}

// JSON serialises the profile for storage in `printers.model_profile`. Used by
// the setup wizard when it writes back what the operator observed.
func (p Profile) JSON() (string, error) {
	raw, err := json.Marshal(p)
	return string(raw), err
}

// FinishingSpec translates the capability profile into the leaf-package value
// the encoder consumes. Keeping the translation HERE (rather than letting
// escpos import this package) is what stops the encoder growing a dependency
// on printer configuration — the encoder only ever knows about bytes.
func (p Profile) FinishingSpec() escpos.Finishing {
	return escpos.Finishing{
		CutMode:             p.Finishing.Cut.Mode,
		FeedBeforeCut:       p.Finishing.Cut.FeedBeforeCut,
		AutoCutPerJob:       p.Finishing.Cut.AutoCutPerJob,
		DrawerKickSupported: p.Finishing.DrawerKick.Supported,
		DrawerPin:           p.Finishing.DrawerKick.Pin,
		DrawerOnMs:          p.Finishing.DrawerKick.OnMs,
		DrawerOffMs:         p.Finishing.DrawerKick.OffMs,
	}
}
