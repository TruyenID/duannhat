package service

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// plan-053 M3 (#1171) — the local template cache + the fallback chain
// (TASKS T3.3b / T3.4, DESIGN §5, EDGE-CASES TR-05/TR-12/TR-14/TR-24…TR-27).
//
// The single promise of this file: **a template problem can never stop a sale**.
// Cloud down, cache empty, cache corrupt, definition unparseable, clock adrift —
// every one of those paths ends at the system default embedded in the binary,
// with a loud log, and the slip still comes out of the printer.
//
// Resolution order at print time:
//
//	1. newest CACHED version whose effective_from has arrived in BRANCH time
//	2. …if that row will not parse: the next older cached version
//	3. …if nothing cached parses: the embedded system default (TR-05/TR-14)

const (
	printTemplatesCursorKey   = "sync.print_templates.last_pulled"
	printTemplatesTimezoneKey = "sync.print_templates.branch_timezone"
	// branchWallClockKey records the wall clock Cloud reported at the last
	// successful pull, together with the workstation's own clock at that moment.
	// Offline, the two let the workstation keep using BRANCH time even if its own
	// clock is set to another zone (TR-25). Nothing about money depends on this —
	// only the instant a new template version takes over.
	printTemplatesWallClockKey = "sync.print_templates.branch_wall_clock"
	printTemplatesSampledAtKey = "sync.print_templates.sampled_at"

	// branchWallClockLayout is Cloud's `branch_wall_clock` format and the format
	// `effective_from` is stored in. It sorts lexicographically, which is what
	// lets version selection be a plain string comparison.
	branchWallClockLayout = "2006-01-02 15:04:05"
)

// PrintTemplateSource says WHERE the definition that produced a slip came from.
// It is recorded on the print job so a reprint can be honest about which
// version it used (TR-28) and an operator can see "đang dùng mẫu hệ thống".
type PrintTemplateSource struct {
	Kind    string
	Version int    // 0 = the embedded system default
	Scope   string // system | brand | shop
	// Fallback is set when the cache had something but it could not be used —
	// the slip printed, and somebody needs to know why it printed from the
	// default.
	Fallback bool
	Reason   string
}

// IsSystemDefault reports whether the binary's layer-0 definition was used.
func (s PrintTemplateSource) IsSystemDefault() bool { return s.Version == 0 }

// Stamp is this provenance as ONE machine-parseable string — the value that
// goes onto the print job's `template_version` column (TR-28).
//
// Shape: `<scope>:<version>` — `system:0`, `brand:7`, `shop:12`. Two fields
// rather than the bare integer because a version number alone does not identify
// a definition: brand v3 and shop v3 are different documents, and the Cloud
// reprint path (`TemplateResolver::forVersion($kind, $branch, $version, $scope)`)
// needs both to address the right one.
//
// The EMPTY string is never produced here, and that is the point. "No stamp" is
// reserved for one thing only — a slip drawn by the legacy formatter, which has
// no version because it is code, not data (see handler.renderMoneySlip). So a
// NULL `template_version` reads as "printed by the old formatter" and a present
// one reads as "printed from this definition"; nothing has to guess which.
//
// An empty Scope becomes `unknown` rather than `system`: a cache row whose scope
// column was never filled is a row of unknown provenance, and claiming it was
// the binary's default would be the invented value TR-28 exists to prevent.
func (s PrintTemplateSource) Stamp() string {
	scope := strings.TrimSpace(s.Scope)
	if scope == "" {
		scope = "unknown"
	}

	return scope + ":" + strconv.Itoa(s.Version)
}

// PrintTemplateStore is the workstation's view of the registry.
type PrintTemplateStore struct {
	db *store.DB
	// parsed memoises decoded definitions by (kind, version, checksum).
	//
	// Two reasons, and the second is the load-bearing one:
	//  1. re-decoding JSON on every kitchen ticket is pointless allocation;
	//  2. the "warn once per (template, locale)" ledger (TR-19) lives ON the
	//     definition. Re-parsing per print would reset it, and a shop with a
	//     half-translated template would get one warning per slip — which is
	//     exactly the log flood the rule exists to prevent.
	parsed sync.Map // string -> *PrintTemplateDefinition
}

func NewPrintTemplateStore(db *store.DB) *PrintTemplateStore {
	return &PrintTemplateStore{db: db}
}

// definitionFor decodes a cache row, memoised by its content identity.
func (s *PrintTemplateStore) definitionFor(row cachedPrintTemplate) (*PrintTemplateDefinition, error) {
	key := fmt.Sprintf("%s|%d|%s", row.Kind, row.Version, row.Checksum)
	if cached, ok := s.parsed.Load(key); ok {
		if def, ok := cached.(*PrintTemplateDefinition); ok {
			return def, nil
		}
		return nil, fmt.Errorf("print template %s v%d: %v", row.Kind, row.Version, cached)
	}
	def, err := ParsePrintTemplateDefinition(row.Definition)
	if err != nil {
		// Memoise the FAILURE too: a corrupt row is corrupt on every print, and
		// re-parsing it per slip would also re-log it per slip.
		s.parsed.Store(key, err)
		return nil, err
	}
	if def.Kind == "" {
		def.Kind = row.Kind
	}
	s.parsed.Store(key, def)
	return def, nil
}

// setting reads one row of the shared key/value `settings` table — the same
// table the sync cursors live in.
func (s *PrintTemplateStore) setting(key string) string {
	var v string
	_ = s.db.QueryRow(`SELECT value FROM settings WHERE key = ?`, key).Scan(&v)
	return v
}

// cachedPrintTemplate is one row of the local cache.
type cachedPrintTemplate struct {
	Kind          string
	Version       int
	Scope         string
	Definition    []byte
	EffectiveFrom string
	Checksum      string
}

// ─── version selection (TR-12 / TR-25) ────────────────────────────────────

// BranchNow returns the branch's WALL CLOCK — the only clock that may decide
// when a new template version takes over (#1091).
//
// While online, Cloud tells the workstation the branch's wall clock on every
// pull; the workstation records it next to its own clock at that instant and,
// offline, advances it by the elapsed local time. That keeps a machine whose
// own clock is set to a different country (or is simply wrong) switching
// versions at the branch's midnight, not its own.
//
// Falling back to the local clock is safe by construction: an early or late
// switchover changes only WHICH LAYOUT prints, never a single figure on it.
func (s *PrintTemplateStore) BranchNow() time.Time {
	if s == nil || s.db == nil {
		return time.Now()
	}
	// Preferred: the branch's own timezone, applied to the local clock. The
	// workstation runs on the shop PC, so its clock ticks at the right rate even
	// when its zone is wrong.
	if tz := s.setting(printTemplatesTimezoneKey); tz != "" {
		if loc, err := time.LoadLocation(tz); err == nil {
			return time.Now().In(loc)
		}
	}
	// Next best: replay the last sampled offset between Cloud's answer and ours.
	wall := s.setting(printTemplatesWallClockKey)
	sampled := s.setting(printTemplatesSampledAtKey)
	if wall != "" && sampled != "" {
		if cloudAt, err := time.Parse(branchWallClockLayout, wall); err == nil {
			if localAt, err := time.Parse(time.RFC3339, sampled); err == nil {
				return cloudAt.Add(time.Since(localAt))
			}
		}
	}
	return time.Now()
}

// Resolve returns the definition to print a kind with, plus its provenance.
// It NEVER returns an error: the last link of the chain is compiled into the
// binary (TR-14).
func (s *PrintTemplateStore) Resolve(kind string) (*PrintTemplateDefinition, PrintTemplateSource) {
	return s.ResolveAt(kind, s.BranchNow())
}

// ResolveAt is Resolve with the branch clock supplied — the seam the tests use
// to prove a future effective_from switches over on time while offline (W3).
func (s *PrintTemplateStore) ResolveAt(kind string, branchNow time.Time) (*PrintTemplateDefinition, PrintTemplateSource) {
	rows := s.effectiveVersions(kind, branchNow)
	for _, row := range rows {
		def, err := s.definitionFor(row)
		if err != nil {
			// TR-14: bit rot in the cache is not a reason to stop printing. Log
			// LOUDLY (this is data corruption, somebody must look) and keep
			// walking down to an older version, then to the binary's default.
			slog.Error("print template cache row unusable — falling back",
				"kind", kind, "version", row.Version, "error", err)
			continue
		}
		return def, PrintTemplateSource{Kind: kind, Version: row.Version, Scope: row.Scope}
	}

	def, err := SystemPrintTemplate(kind)
	if err != nil {
		// Cannot happen for a kind the renderer knows — the golden gate asserts
		// every renderable kind ships a default — but a nil definition here would
		// turn a template problem into a crash, which is the one outcome this
		// whole file exists to prevent.
		slog.Error("no system print template", "kind", kind, "error", err)
		return nil, PrintTemplateSource{Kind: kind, Fallback: true, Reason: err.Error()}
	}
	src := PrintTemplateSource{Kind: kind, Scope: "system"}
	if len(rows) > 0 {
		src.Fallback = true
		src.Reason = "cached definition unusable"
	}
	return def, src
}

// RenderSlip is the production print path: resolve the template for this kind,
// render it, and — if the resolved definition somehow fails to render — reprint
// from the embedded system default rather than hand the caller an error.
//
// This is TR-14 end to end. A brand can publish a template that the validator
// let through and this renderer still chokes on (a block combination nobody
// tried, a data shape nobody anticipated); the shop finds out from the log, not
// from a customer waiting at the till.
//
// A nil store is legal and means "no cache" — the offline/never-paired path
// (TR-05) — so callers do not need to special-case a machine that has never
// synced.
// imageOption cấp nguồn bitmap cho khối `logo` (#1957 mảnh C).
//
// Dùng lại `BranchNow()` — cùng đồng hồ chi nhánh mà việc chọn phiên bản template
// dùng. Hai đồng hồ cho hai thứ cùng do HQ hẹn giờ sẽ lệch nhau đúng vào lúc
// người ta để ý nhất: sáng ngày đổi mẫu.
func (s *PrintTemplateStore) imageOption() RenderOption {
	if s == nil || s.db == nil {
		return func(*printRenderCtx) {}
	}

	return WithPrintImages(NewPrintImageStore(s.db), s.BranchNow().Format(branchWallClockLayout))
}

func (s *PrintTemplateStore) RenderSlip(data *PrintRenderData, profile PrintRenderProfile, locale string) ([]byte, PrintTemplateSource, error) {
	if data == nil {
		return nil, PrintTemplateSource{}, fmt.Errorf("print: nil render data")
	}
	def, src := s.Resolve(data.Kind)
	if def != nil {
		res, err := RenderPrintTemplate(def, data, profile, locale, s.imageOption())
		if err == nil {
			return res.Bytes(), src, nil
		}
		slog.Error("print template render failed — falling back to system default",
			"kind", data.Kind, "version", src.Version, "error", err)
		src.Fallback = true
		src.Reason = err.Error()
	}

	fallback, err := SystemPrintTemplate(data.Kind)
	if err != nil {
		return nil, src, err
	}
	res, err := RenderPrintTemplate(fallback, data, profile, locale, s.imageOption())
	if err != nil {
		return nil, src, err
	}
	src.Version = 0
	src.Scope = "system"
	src.Fallback = true
	if src.Reason == "" {
		src.Reason = "no usable cached template"
	}
	return res.Bytes(), src, nil
}

// RenderSlipSystemDefault renders chỉ từ bản mặc định nhúng trong binary (layer 0),
// bỏ qua cache brand/shop. Dùng khi renderer bật nhưng chưa cho phép template publish
// (#1945 — mọi quán dùng mẫu hệ thống; tuỳ biến brand là việc sau).
func (s *PrintTemplateStore) RenderSlipSystemDefault(data *PrintRenderData, profile PrintRenderProfile, locale string) ([]byte, PrintTemplateSource, error) {
	if data == nil {
		return nil, PrintTemplateSource{}, fmt.Errorf("print: nil render data")
	}
	def, err := SystemPrintTemplate(data.Kind)
	if err != nil {
		return nil, PrintTemplateSource{Kind: data.Kind, Fallback: true, Reason: err.Error()}, err
	}
	res, err := RenderPrintTemplate(def, data, profile, locale)
	if err != nil {
		return nil, PrintTemplateSource{Kind: data.Kind, Scope: "system", Fallback: true, Reason: err.Error()}, err
	}
	return res.Bytes(), PrintTemplateSource{Kind: data.Kind, Version: 0, Scope: "system"}, nil
}

// effectiveVersions lists this kind's cached versions that are already in force
// at branchNow, newest first. A row with no effective_from is in force from the
// moment it was published (DESIGN §4).
func (s *PrintTemplateStore) effectiveVersions(kind string, branchNow time.Time) []cachedPrintTemplate {
	if s == nil || s.db == nil {
		return nil
	}
	rows, err := s.db.Query(`
		SELECT kind, version, scope, definition, COALESCE(effective_from, ''), checksum
		  FROM print_templates
		 WHERE kind = ?
		 ORDER BY version DESC`, kind)
	if err != nil {
		slog.Warn("print template cache read failed — using system default", "kind", kind, "error", err)
		return nil
	}
	defer rows.Close()

	nowStr := branchNow.Format(branchWallClockLayout)
	var out []cachedPrintTemplate
	for rows.Next() {
		var r cachedPrintTemplate
		var def string
		if err := rows.Scan(&r.Kind, &r.Version, &r.Scope, &def, &r.EffectiveFrom, &r.Checksum); err != nil {
			slog.Warn("print template cache row unreadable", "kind", kind, "error", err)
			continue
		}
		if eff := normalizeWallClock(r.EffectiveFrom); eff != "" && eff > nowStr {
			continue // TR-12 — published, cached, not yet in force
		}
		r.Definition = []byte(def)
		out = append(out, r)
	}
	return out
}

// normalizeWallClock reduces whatever Cloud sent to the sortable
// "YYYY-MM-DD HH:MM:SS" form. It accepts the ISO "T" separator and tolerates a
// trailing zone marker, because `effective_from` is deliberately NOT cast on
// the Cloud side and has historically been written both ways.
func normalizeWallClock(v string) string {
	v = strings.TrimSpace(v)
	if v == "" {
		return ""
	}
	v = strings.Replace(v, "T", " ", 1)
	if i := strings.IndexAny(v, "Z+"); i > 10 {
		v = v[:i]
	}
	if len(v) > 19 {
		v = v[:19]
	}
	if len(v) == 10 { // date only → start of that day
		v += " 00:00:00"
	}
	return strings.TrimSpace(v)
}

// ─── sync DOWN (TR-24) ────────────────────────────────────────────────────

// printTemplatePayload is Cloud's `GET /api/v1/workstation/print-templates`
// response (PrintTemplateReplicaController::entry).
type printTemplatePayload struct {
	Data []struct {
		Kind            string          `json:"kind"`
		Scope           string          `json:"scope"`
		Version         *int            `json:"version"`
		EffectiveFrom   *string         `json:"effective_from"`
		Checksum        string          `json:"checksum"`
		IsSystemDefault bool            `json:"is_system_default"`
		Definition      json.RawMessage `json:"definition"`
		UpdatedAt       *string         `json:"updated_at"`
	} `json:"data"`
	BranchTimezone  string `json:"branch_timezone"`
	BranchWallClock string `json:"branch_wall_clock"`
	GeneratedAt     string `json:"generated_at"`
}

const pullPathPrintTemplates = "/api/v1/workstation/print-templates"

// PullPrintTemplates mirrors the branch's resolved templates into SQLite.
//
// TR-24 is the whole point of the verification below: every entry's definition
// is re-hashed locally and compared with the checksum Cloud published. A single
// mismatch aborts the ENTIRE write and leaves the cursor where it was, so the
// shop keeps printing from the last cache it trusted and the next tick tries
// again. A half-written registry is worse than a stale one — stale prints last
// week's footer, half-written prints an unpredictable slip.
func (p *SyncPuller) PullPrintTemplates(ctx context.Context) error {
	cursor := p.getCursor(printTemplatesCursorKey)
	path := pullPathPrintTemplates
	if cursor != "" {
		// The cursor is an ISO-8601 timestamp and can carry a "+09:00" offset;
		// unescaped, the "+" would reach Cloud as a space and the delta window
		// would silently shift by the branch's UTC offset.
		path += "?since=" + url.QueryEscape(cursor)
	}

	var payload printTemplatePayload
	if err := p.cloudGet(ctx, path, &payload); err != nil {
		// W4 — Cloud dead. The cache already on disk is the answer; nothing is
		// written, nothing is invalidated, the shop never notices.
		return err
	}

	// Branch clock first: it is useful even when the delta is empty, and it is
	// what keeps effective_from honest on a workstation whose own clock drifted.
	if payload.BranchTimezone != "" {
		_ = p.setCursor(printTemplatesTimezoneKey, payload.BranchTimezone)
	}
	if payload.BranchWallClock != "" {
		_ = p.setCursor(printTemplatesWallClockKey, payload.BranchWallClock)
		_ = p.setCursor(printTemplatesSampledAtKey, time.Now().Format(time.RFC3339))
	}

	if len(payload.Data) == 0 {
		return nil
	}

	type verified struct {
		kind          string
		version       int
		scope         string
		definition    []byte
		effectiveFrom any
		checksum      string
		isDefault     int
		updatedAt     any
	}
	rows := make([]verified, 0, len(payload.Data))
	newest := cursor

	for _, entry := range payload.Data {
		if entry.Kind == "" || len(entry.Definition) == 0 {
			return fmt.Errorf("print templates: entry with no kind/definition — keeping cache")
		}
		sum, err := PrintTemplateChecksum(entry.Definition)
		if err != nil {
			return fmt.Errorf("print templates: %s definition is not JSON — keeping cache: %w", entry.Kind, err)
		}
		// A missing checksum means an OLD Cloud that predates the field; we
		// accept it (there is nothing to disagree with) but never accept a
		// checksum that is present AND wrong.
		if entry.Checksum != "" && !strings.EqualFold(entry.Checksum, sum) {
			return fmt.Errorf("print templates: checksum mismatch for %s (cloud %s, local %s) — keeping cache", entry.Kind, entry.Checksum, sum)
		}

		v := verified{
			kind:       entry.Kind,
			scope:      entry.Scope,
			definition: entry.Definition,
			checksum:   sum,
		}
		if entry.Version != nil {
			v.version = *entry.Version
		}
		if entry.IsSystemDefault {
			v.isDefault = 1
		}
		if entry.EffectiveFrom != nil && *entry.EffectiveFrom != "" {
			v.effectiveFrom = normalizeWallClock(*entry.EffectiveFrom)
		}
		if entry.UpdatedAt != nil && *entry.UpdatedAt != "" {
			v.updatedAt = *entry.UpdatedAt
			newest = maxCursorString(newest, *entry.UpdatedAt)
		}
		rows = append(rows, v)
	}

	err := p.db.Transaction(func(tx *sql.Tx) error {
		stmt, err := tx.Prepare(`
			INSERT INTO print_templates
				(kind, version, scope, definition, effective_from, checksum, is_system_default, cloud_updated_at, fetched_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
			ON CONFLICT(kind, version) DO UPDATE SET
				scope             = excluded.scope,
				definition        = excluded.definition,
				effective_from    = excluded.effective_from,
				checksum          = excluded.checksum,
				is_system_default = excluded.is_system_default,
				cloud_updated_at  = excluded.cloud_updated_at,
				fetched_at        = excluded.fetched_at`)
		if err != nil {
			return err
		}
		defer stmt.Close()
		for _, r := range rows {
			if _, err := stmt.Exec(r.kind, r.version, r.scope, string(r.definition),
				r.effectiveFrom, r.checksum, r.isDefault, r.updatedAt); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return err
	}
	newest = boundedCursorString(newest, payload.GeneratedAt)
	if newest != "" && newest != cursor {
		_ = p.setCursor(printTemplatesCursorKey, newest)
	}
	slog.Info("print templates cached", "count", len(rows))
	return nil
}
