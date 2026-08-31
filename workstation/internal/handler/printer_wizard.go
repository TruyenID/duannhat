package handler

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
	"github.com/dxs-platform/workstation-app/internal/printjob"
)

// Printer setup wizard — plan-052 T1.4d (#1166).
//
// The old "test print" printed three lines and said OK. That answered exactly
// one question — is the machine reachable — and left every question that
// actually matters unanswered: does it render 漢字, does Vietnamese come out
// with its diacritics, how many columns fit, does the blade work, does the
// drawer open. Those answers ARE the capability profile (DESIGN §3b), and
// nobody in the office can produce them: only a person standing at that
// machine, holding the paper, can.
//
// So the wizard prints a DIAGNOSTIC SHEET designed to be read by a shop
// employee, and takes their answers back as a profile. No release required to
// support a printer nobody has seen before.
//
// The sheet rides the ledger as kind=diagnostic (P-41): retryable, short TTL,
// never consumes a 「Bản in #N」 and never appears in invoice audit.

// diagnosticSample is one comparison block on the sheet. Keeping them as data
// makes the sheet's structure obvious and lets the answer endpoint refer to
// blocks by name.
type diagnosticSample struct {
	Label string
	Text  string
}

// diagnosticSamples are chosen to fail VISIBLY on the machines we expect
// trouble from — the cheap ESC/POS units with no kanji ROM, which print
// squares or mojibake instead of refusing.
func diagnosticSamples() []diagnosticSample {
	return []diagnosticSample{
		{Label: "1. KANJI / 漢字", Text: "唐揚げ 生ビール 会計 合計"},
		{Label: "2. KANA", Text: "カタカナ ひらがな"},
		{Label: "3. TIENG VIET", Text: "Phở đặc biệt — Cà phê sữa đá"},
		{Label: "4. ASCII + MONEY", Text: "TOTAL 1,980 / 12,345 JPY"},
	}
}

// handlePrinterDiagnostic replaces the old three-line test print.
//
// POST /api/devices/{id}/diagnostic
//
// P-40: it runs against a machine we know nothing about. The profile in force
// is whatever is stored, which for a new printer is `escpos_generic` — the
// sheet prints, the operator reads it, and the answers arrive later. A wizard
// that required a complete profile in order to discover the profile would be
// useless.
func (s *Server) handlePrinterDiagnostic(w http.ResponseWriter, r *http.Request) {
	p, ok := s.devices.GetPrinter(r.PathValue("id"))
	if !ok {
		writeError(w, http.StatusNotFound, "printer not found")
		return
	}

	profile := p.Profile()
	sheet := buildDiagnosticSheet(profile, p.Name(), time.Now())

	err := printDiagnostic(p, sheet)

	// The sheet goes through the ledger like every other print, so a shop can
	// see that a diagnostic failed rather than wondering whether anyone tried.
	s.journalPrint(p, printjob.Entry{
		Kind:         printjob.KindDiagnostic,
		RequestedVia: "workstation",
		Payload: map[string]any{
			"template": "printer_diagnostic",
			"profile":  profile.Preset,
		},
	}, err)

	if err != nil {
		writeServerError(w, r, err)
		return
	}

	s.auditLog(r, "printer.diagnostic", "device", p.ID(), auditDetails(map[string]any{
		"profile": profile.Preset,
	}))

	writeJSON(w, http.StatusOK, map[string]any{
		"status": "ok",
		// The questions the sheet asks, so the UI can render the same list of
		// answers without duplicating the wording.
		"questions": diagnosticQuestions(),
		"profile":   profile,
	})
}

// printDiagnostic performs the actual print. Split out so the sheet builder can
// be tested without hardware.
func printDiagnostic(p *printer.Printer, sheet []byte) error {
	if err := p.Connect(); err != nil {
		return err
	}
	defer p.Disconnect()
	return p.Print(sheet)
}

// buildDiagnosticSheet renders the sheet. It is deliberately printed with the
// CURRENT profile's finishing so the cut question below is asking about what
// the machine will really do in service, not about some other command.
func buildDiagnosticSheet(profile printer.Profile, printerName string, now time.Time) []byte {
	width := profile.ColumnsFor(80)

	e := escpos.New()
	e.Align(escpos.AlignCenter).Bold(true).Line("PRINTER SETUP / 設定診断")
	e.Bold(false).Line(printerName)
	e.Line(now.Format("2006-01-02 15:04:05"))
	e.Align(escpos.AlignLeft).Separator(width)

	// ── Character rendering ────────────────────────────────────────────────
	e.Line("A. CHU / 文字")
	for _, sample := range diagnosticSamples() {
		e.Line(sample.Label)
		e.Line("   " + sample.Text)
	}
	e.Line("")
	e.Line("-> Neu 1/2 ra o vuong hoac rac:")
	e.Line("   tra loi kanji = KHONG.")
	e.Separator(width)

	// ── Column ruler ───────────────────────────────────────────────────────
	// The ruler is the only honest way to learn a machine's real width: the
	// 58/80mm assumption is wrong often enough that a receipt silently wraps
	// and the total ends up on its own line.
	e.Line("B. SO COT / 桁数")
	for _, cols := range []int{32, 42, 48} {
		e.Line(fmt.Sprintf("%2d:%s", cols, strings.Repeat("-", cols-3)+"|"))
	}
	e.Line("-> Dong nao vua khit le giay?")
	e.Separator(width)

	// ── Finishing ──────────────────────────────────────────────────────────
	e.Line("C. CAT GIAY / カット")
	e.Line(fmt.Sprintf("   mode = %s, feed = %d",
		profile.Finishing.Cut.Mode, profile.Finishing.Cut.FeedBeforeCut))
	e.Line("-> Sau day may co cat khong?")
	e.Line("   Co cat mat dong cuoi khong?")
	e.Separator(width)

	e.Line("D. KET TIEN / ドロア")
	if profile.CanKickDrawer() {
		e.Line("   -> Ket vua mo?")
	} else {
		e.Line("   (chua bat — profile: khong ho tro)")
	}
	e.Line("")
	e.Align(escpos.AlignCenter).Line("== HET / 終了 ==")
	e.Align(escpos.AlignLeft)

	// Kick only if the profile says the machine can (P-37) — a blind pulse on
	// an unknown pin is how a till jams.
	e.KickDrawer(profile.FinishingSpec())

	e.Finish(profile.FinishingSpec())

	return e.Bytes()
}

// diagnosticQuestions is the answer schema the UI renders and POSTs back.
func diagnosticQuestions() []map[string]any {
	return []map[string]any{
		{"key": "kanji_ok", "type": "bool", "block": "A", "label": "漢字 / カナ in dung?"},
		{"key": "vietnamese_ok", "type": "bool", "block": "A", "label": "Tieng Viet co dau in dung?"},
		{"key": "columns", "type": "int", "block": "B", "options": []int{32, 42, 48}, "label": "So cot vua khit"},
		{"key": "cut_ok", "type": "bool", "block": "C", "label": "May co cat giay?"},
		{"key": "cut_clipped_last_line", "type": "bool", "block": "C", "label": "Cat mat dong cuoi?"},
		{"key": "drawer_opened", "type": "bool", "block": "D", "label": "Ket tien co mo?"},
	}
}

// diagnosticAnswers is what the operator observed. Every field is a POINTER:
// "not answered" and "answered no" are different things, and a wizard that
// treated them the same would overwrite a known-good setting with a default
// every time someone skipped a question (P-40).
type diagnosticAnswers struct {
	KanjiOK            *bool `json:"kanji_ok"`
	VietnameseOK       *bool `json:"vietnamese_ok"`
	Columns            *int  `json:"columns"`
	CutOK              *bool `json:"cut_ok"`
	CutClippedLastLine *bool `json:"cut_clipped_last_line"`
	DrawerOpened       *bool `json:"drawer_opened"`
}

// handlePrinterProfileAnswers turns what the operator saw into a profile.
//
// POST /api/devices/{id}/profile
//
// P-40: a partial answer set is saved as-is. Someone who answers two questions
// and walks away has still taught the system two true things about that
// machine, and those two must survive.
func (s *Server) handlePrinterProfileAnswers(w http.ResponseWriter, r *http.Request) {
	p, ok := s.devices.GetPrinter(r.PathValue("id"))
	if !ok {
		writeError(w, http.StatusNotFound, "printer not found")
		return
	}

	var answers diagnosticAnswers
	if err := json.NewDecoder(r.Body).Decode(&answers); err != nil {
		writeError(w, http.StatusBadRequest, "invalid body")
		return
	}

	profile := ApplyDiagnosticAnswers(p.Profile(), answers)

	raw, err := profile.JSON()
	if err != nil {
		writeServerError(w, r, err)
		return
	}

	// Persist first, then swap memory: a failed write leaves routing on the
	// old, still-accurate profile rather than one the database does not have
	// (same rule as UpdatePrinter).
	if _, err := s.db.Exec(
		`UPDATE printers SET model_profile = ?, updated_at = ? WHERE id = ?`,
		raw, time.Now().UTC().Format(time.RFC3339), p.ID(),
	); err != nil {
		writeServerError(w, r, err)
		return
	}
	// The row now HAS a stored value, so the in-memory copy must say so too —
	// otherwise the renderer keeps treating this machine as undescribed until
	// the next restart re-reads it through ParseProfile, and a wizard run would
	// appear to do nothing to the cut.
	profile.Configured = true
	p.SetProfile(profile)

	s.auditLog(r, "printer.profile_updated", "device", p.ID(), auditDetails(map[string]any{
		"text_mode": profile.TextMode,
		"cut":       profile.Finishing.Cut.Mode,
		"kanji":     profile.Charset.Kanji,
	}))

	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "profile": profile})
}

// ApplyDiagnosticAnswers folds observed answers into a profile. Exported so
// the mapping — the actual product decision of the wizard — is testable
// without a printer, an HTTP server or a database.
func ApplyDiagnosticAnswers(profile printer.Profile, answers diagnosticAnswers) printer.Profile {
	if answers.KanjiOK != nil {
		profile.Charset.Kanji = *answers.KanjiOK
		if *answers.KanjiOK {
			profile.TextMode = printer.TextModeNative
		} else {
			// The machine cannot render the characters this shop's menu is
			// written in. `auto` then rasterises only the blocks that need it
			// and leaves money/numbers native, which is what keeps a rush
			// moving (P-30).
			profile.TextMode = printer.TextModeAuto
		}
	}

	// Vietnamese failing while kanji works still means "outside the codepage",
	// so the same auto/raster route applies.
	if answers.VietnameseOK != nil && !*answers.VietnameseOK {
		profile.TextMode = printer.TextModeAuto
		profile.Charset.Kanji = false
	}

	if answers.Columns != nil && *answers.Columns > 0 {
		if profile.Columns == nil {
			profile.Columns = map[string]int{}
		}
		profile.Columns["80mm"] = *answers.Columns
		if *answers.Columns <= 32 {
			profile.Columns["58mm"] = *answers.Columns
		}
	}

	if answers.CutOK != nil && !*answers.CutOK {
		// It did not cut. Never keep sending a command this machine ignores —
		// on some firmware the unrecognised bytes print as garbage (P-36).
		profile.Finishing.Cut.Mode = printer.CutNone
	}

	if answers.CutClippedLastLine != nil && *answers.CutClippedLastLine {
		// Physical distance from head to blade. Two more lines of feed is the
		// standard remedy and costs a few millimetres of paper.
		profile.Finishing.Cut.FeedBeforeCut += 2
	}

	if answers.DrawerOpened != nil {
		profile.Finishing.DrawerKick.Supported = *answers.DrawerOpened
	}

	return profile
}
