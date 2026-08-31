package service

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

// plan-053 M3 (#1171) — version-selection and pull EDGE CASES, on top of the
// W1…W7 scenarios in print_template_cache_test.go.
//
// Version selection is the one place where a wrong answer is invisible: the
// slip still prints, it just prints the wrong LAYOUT, and nobody notices until
// an auditor asks why last Tuesday's receipts look different. So every boundary
// gets pinned rather than left to whoever reads the SQL next.

// ─── the effective_from boundary ──────────────────────────────────────────

// The comparison is INCLUSIVE: a version whose effective_from is exactly now is
// already in force. Publishing "effective 09:00" and having the 09:00:00 slip
// come out on the old template would be indefensible to the person who set it.
func TestPrintTemplateVersion_EffectiveFromBoundaryIsInclusive(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	seedCachedTemplate(t, db, "receipt", 1, "2026-07-01 00:00:00", brandTemplate(t, "receipt", "old"))
	seedCachedTemplate(t, db, "receipt", 2, "2026-08-01 09:00:00", brandTemplate(t, "receipt", "new"))
	s := NewPrintTemplateStore(db)

	cases := []struct {
		name string
		at   time.Time
		want int
	}{
		{"one second before", time.Date(2026, 8, 1, 8, 59, 59, 0, time.UTC), 1},
		{"exactly at", time.Date(2026, 8, 1, 9, 0, 0, 0, time.UTC), 2},
		{"one second after", time.Date(2026, 8, 1, 9, 0, 1, 0, time.UTC), 2},
		// Sub-second precision is truncated by the wall-clock format, so a
		// nanosecond before the boundary still reads as "at" it.
		{"999ms into the boundary second", time.Date(2026, 8, 1, 9, 0, 0, 999_000_000, time.UTC), 2},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if _, src := s.ResolveAt("receipt", tc.at); src.Version != tc.want {
				t.Errorf("want version %d, got %d", tc.want, src.Version)
			}
		})
	}
}

// Two versions published for the same instant is a real race (two admins, one
// minute). The tie-break is the HIGHER version number — the later publish —
// and it must be deterministic, not "whatever SQLite felt like".
func TestPrintTemplateVersion_TieBreakIsHighestVersion(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	seedCachedTemplate(t, db, "receipt", 7, "2026-08-01 09:00:00", brandTemplate(t, "receipt", "seven"))
	seedCachedTemplate(t, db, "receipt", 8, "2026-08-01 09:00:00", brandTemplate(t, "receipt", "eight"))
	seedCachedTemplate(t, db, "receipt", 6, "2026-08-01 09:00:00", brandTemplate(t, "receipt", "six"))
	s := NewPrintTemplateStore(db)

	at := time.Date(2026, 8, 1, 12, 0, 0, 0, time.UTC)
	for i := 0; i < 5; i++ { // repeat: a nondeterministic answer would show up here
		if _, src := s.ResolveAt("receipt", at); src.Version != 8 {
			t.Fatalf("tie-break must pick the highest version, got %d", src.Version)
		}
	}
}

// A version that is cached but not yet in force must NEVER be chosen — not even
// when it is the only row, in which case the binary's default carries the shop.
func TestPrintTemplateVersion_FutureOnlyFallsBackToSystemDefault(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	db := newPrintTemplateTestDB(t)
	seedCachedTemplate(t, db, "receipt", 5, "2027-01-01 00:00:00", brandTemplate(t, "receipt", "next year"))
	s := NewPrintTemplateStore(db)

	at := time.Date(2026, 8, 1, 12, 0, 0, 0, time.UTC)
	def, src := s.ResolveAt("receipt", at)
	if !src.IsSystemDefault() {
		t.Fatalf("a future-only cache must resolve to the system default, got %+v", src)
	}
	res, err := RenderPrintTemplate(def, goldenRenderData("receipt", goldenConfig("ja", 42)), PrintRenderProfile{Columns: 42}, "ja")
	if err != nil {
		t.Fatal(err)
	}
	order, items := goldenOrder()
	if want := FormatPaidTicket(order, items, 7, goldenConfig("ja", 42), goldenSlip()); !bytes.Equal(want, res.Bytes()) {
		t.Errorf("the default slip is not today's receipt:\n%s", diffBytes(want, res.Bytes()))
	}
}

// A NULL effective_from means "in force since publish". It must win over an
// older dated version and must never be skipped.
func TestPrintTemplateVersion_NullEffectiveFromIsAlwaysInForce(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	seedCachedTemplate(t, db, "receipt", 3, "2026-01-01 00:00:00", brandTemplate(t, "receipt", "dated"))
	seedCachedTemplate(t, db, "receipt", 4, "", brandTemplate(t, "receipt", "undated"))
	s := NewPrintTemplateStore(db)

	if _, src := s.ResolveAt("receipt", time.Date(2020, 1, 1, 0, 0, 0, 0, time.UTC)); src.Version != 4 {
		t.Errorf("an undated version is in force even before every dated one, got %d", src.Version)
	}
}

// Cloud has written effective_from both as a plain wall clock and in ISO form.
// Both must select identically — a format change upstream must not silently
// move a switchover.
func TestPrintTemplateVersion_AcceptsBothWallClockAndIsoEffectiveFrom(t *testing.T) {
	cases := map[string]string{
		"wall clock":     "2026-08-01 09:00:00",
		"iso T":          "2026-08-01T09:00:00",
		"iso T with Z":   "2026-08-01T09:00:00Z",
		"iso res offset": "2026-08-01T09:00:00+09:00",
		"date only":      "2026-08-01",
	}
	for name, raw := range cases {
		t.Run(name, func(t *testing.T) {
			db := newPrintTemplateTestDB(t)
			seedCachedTemplate(t, db, "receipt", 1, "2020-01-01 00:00:00", brandTemplate(t, "receipt", "old"))
			seedCachedTemplate(t, db, "receipt", 2, raw, brandTemplate(t, "receipt", "new"))
			s := NewPrintTemplateStore(db)

			before := time.Date(2026, 7, 31, 23, 0, 0, 0, time.UTC)
			after := time.Date(2026, 8, 1, 23, 0, 0, 0, time.UTC)
			if _, src := s.ResolveAt("receipt", before); src.Version != 1 {
				t.Errorf("before: want 1, got %d", src.Version)
			}
			if _, src := s.ResolveAt("receipt", after); src.Version != 2 {
				t.Errorf("after: want 2, got %d", src.Version)
			}
		})
	}
}

// ─── business time across three zones (#1091) ─────────────────────────────

// One frozen instant, three branches. Tokyo has crossed into the new business
// day while Hanoi and UTC have not, so Tokyo alone switches template version.
// This is the #1091 shape that once mis-stamped nine hours of every JST day.
func TestPrintTemplateVersion_ThreeTimezonesAtOneFrozenInstant(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	seedCachedTemplate(t, db, "receipt", 1, "2026-07-01 00:00:00", brandTemplate(t, "receipt", "old"))
	// Takes effect at the branch's own midnight.
	seedCachedTemplate(t, db, "receipt", 2, "2026-08-01 00:00:00", brandTemplate(t, "receipt", "new"))
	s := NewPrintTemplateStore(db)

	// 2026-07-31 16:30 UTC: Tokyo (+9) is already 2026-08-01 01:30; Hanoi (+7)
	// is 2026-07-31 23:30; UTC is 2026-07-31 16:30.
	instant := time.Date(2026, 7, 31, 16, 30, 0, 0, time.UTC)
	zones := []struct {
		name string
		tz   string
		want int
	}{
		{"Asia/Tokyo", "Asia/Tokyo", 2},
		{"Asia/Ho_Chi_Minh", "Asia/Ho_Chi_Minh", 1},
		{"UTC", "UTC", 1},
	}
	for _, z := range zones {
		t.Run(z.name, func(t *testing.T) {
			loc, err := time.LoadLocation(z.tz)
			if err != nil {
				t.Skipf("timezone database unavailable: %v", err)
			}
			if _, src := s.ResolveAt("receipt", instant.In(loc)); src.Version != z.want {
				t.Errorf("%s at the same instant: want version %d, got %d", z.name, z.want, src.Version)
			}
		})
	}
}

// BranchNow prefers the branch's own timezone over the machine's, because the
// machine may be a spare shipped from another country.
func TestPrintTemplateVersion_BranchNowUsesTheBranchTimezone(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	s := NewPrintTemplateStore(db)

	if _, err := db.Exec(`INSERT INTO settings (key, value) VALUES (?, ?)
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`,
		printTemplatesTimezoneKey, "Asia/Tokyo"); err != nil {
		t.Fatal(err)
	}
	tokyo, err := time.LoadLocation("Asia/Tokyo")
	if err != nil {
		t.Skipf("timezone database unavailable: %v", err)
	}
	got := s.BranchNow()
	if got.Format("2006-01-02 15:04") != time.Now().In(tokyo).Format("2006-01-02 15:04") {
		t.Errorf("BranchNow did not follow the branch timezone: %s", got)
	}

	// With no timezone recorded, the sampled Cloud wall clock carries it.
	if _, err := db.Exec(`DELETE FROM settings WHERE key = ?`, printTemplatesTimezoneKey); err != nil {
		t.Fatal(err)
	}
	s2 := NewPrintTemplateStore(db)
	sampled := time.Now().Add(-2 * time.Minute)
	if _, err := db.Exec(`INSERT INTO settings (key, value) VALUES (?, ?), (?, ?)`,
		printTemplatesWallClockKey, "2030-01-01 12:00:00",
		printTemplatesSampledAtKey, sampled.Format(time.RFC3339)); err != nil {
		t.Fatal(err)
	}
	replayed := s2.BranchNow()
	if replayed.Year() != 2030 {
		t.Errorf("BranchNow should replay the sampled Cloud clock, got %s", replayed)
	}
	if d := replayed.Sub(time.Date(2030, 1, 1, 12, 0, 0, 0, time.UTC)); d < time.Minute || d > 5*time.Minute {
		t.Errorf("sampled clock should have advanced by the elapsed local time, advanced %s", d)
	}
}

// ─── pull edge cases (TR-24) ──────────────────────────────────────────────

func servePrintTemplates(t *testing.T, body string) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprint(w, body)
	}))
	t.Cleanup(srv.Close)
	return srv
}

// A payload whose checksum is CORRECT but whose definition is not a usable
// definition still lands in the cache — the checksum only proves the bytes
// arrived intact, and refusing here would strand a shop on a stale template
// forever. The fallback chain is what protects the print, and it does.
func TestPrintTemplatePull_ValidChecksumButUnusableDefinitionStillPrints(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	body := []byte(`{"schema":"tempo.print.v9","blocks":[{"id":"items","type":"line_items"}]}`)
	sum, err := PrintTemplateChecksum(body)
	if err != nil {
		t.Fatal(err)
	}
	srv := servePrintTemplates(t, fmt.Sprintf(`{"data":[{"kind":"receipt","scope":"brand","version":3,
		"effective_from":null,"checksum":%q,"is_system_default":false,"definition":%s,
		"updated_at":"2026-07-21T00:00:00Z"}],"branch_timezone":"Asia/Tokyo",
		"branch_wall_clock":"2026-07-21 09:00:00"}`, sum, body))

	db := newPrintTemplateTestDB(t)
	p := NewSyncPuller(db, srv.URL, func() string { return "tok" })
	if err := p.PullPrintTemplates(context.Background()); err != nil {
		t.Fatalf("a well-formed transport payload must be accepted: %v", err)
	}

	s := NewPrintTemplateStore(db)
	cfg := goldenConfig("ja", 42)
	got, src, err := s.RenderSlip(goldenRenderData("receipt", cfg), PrintRenderProfile{Columns: 42}, "ja")
	if err != nil {
		t.Fatalf("an unusable definition must not stop a sale: %v", err)
	}
	if !src.IsSystemDefault() || !src.Fallback {
		t.Errorf("expected a flagged fallback, got %+v", src)
	}
	order, items := goldenOrder()
	if want := FormatPaidTicket(order, items, 7, cfg, goldenSlip()); !bytes.Equal(want, got) {
		t.Errorf("fallback slip is not today's receipt:\n%s", diffBytes(want, got))
	}
}

// A payload that is not JSON at all, an entry with no kind, and an entry with
// no definition are all transport failures: nothing is written and the cursor
// stays put, so the next tick retries against the same window.
func TestPrintTemplatePull_MalformedPayloadsWriteNothing(t *testing.T) {
	good := brandTemplate(t, "receipt", "good")
	goodSum, _ := PrintTemplateChecksum(good)

	cases := map[string]string{
		"entry with no kind": fmt.Sprintf(`{"data":[{"kind":"","scope":"brand","version":1,"checksum":%q,
			"definition":%s,"updated_at":"2026-07-21T00:00:00Z"}]}`, goodSum, good),
		"entry with no definition": `{"data":[{"kind":"receipt","scope":"brand","version":1,
			"checksum":"x","updated_at":"2026-07-21T00:00:00Z"}]}`,
		"definition is not json": `{"data":[{"kind":"receipt","scope":"brand","version":1,
			"checksum":"x","definition":"not-json-at-all","updated_at":"2026-07-21T00:00:00Z"}]}`,
		"checksum of the wrong document": fmt.Sprintf(`{"data":[{"kind":"receipt","scope":"brand","version":1,
			"checksum":"%s","definition":{"schema":"tempo.print.v1","blocks":[{"id":"items"}]},
			"updated_at":"2026-07-21T00:00:00Z"}]}`, goodSum),
	}

	for name, payload := range cases {
		t.Run(name, func(t *testing.T) {
			srv := servePrintTemplates(t, payload)
			db := newPrintTemplateTestDB(t)
			seedCachedTemplate(t, db, "receipt", 99, "", brandTemplate(t, "receipt", "already trusted"))

			p := NewSyncPuller(db, srv.URL, func() string { return "tok" })
			if err := p.PullPrintTemplates(context.Background()); err == nil {
				t.Error("a malformed payload must fail the pull")
			}
			var count int
			if err := db.QueryRow(`SELECT COUNT(*) FROM print_templates`).Scan(&count); err != nil {
				t.Fatal(err)
			}
			if count != 1 {
				t.Errorf("cache should still hold exactly the trusted row, holds %d", count)
			}
			if cursor := p.getCursor(printTemplatesCursorKey); cursor != "" {
				t.Errorf("cursor advanced past a failed pull: %q", cursor)
			}
			s := NewPrintTemplateStore(db)
			if _, src := s.Resolve("receipt"); src.Version != 99 {
				t.Errorf("resolve must still serve the trusted version, got %d", src.Version)
			}
		})
	}
}

// A batch is ALL-OR-NOTHING: one poisoned entry must not let its healthy
// siblings through, because a registry half a version ahead prints a slip
// nobody designed.
func TestPrintTemplatePull_OnePoisonedEntryRejectsTheWholeBatch(t *testing.T) {
	receipt := brandTemplate(t, "receipt", "receipt footer")
	kitchen := brandTemplate(t, "kitchen", "kitchen footer")
	receiptSum, _ := PrintTemplateChecksum(receipt)

	srv := servePrintTemplates(t, fmt.Sprintf(`{"data":[
		{"kind":"receipt","scope":"brand","version":2,"checksum":%q,"definition":%s,"updated_at":"2026-07-21T00:00:00Z"},
		{"kind":"kitchen","scope":"brand","version":2,"checksum":"deadbeef","definition":%s,"updated_at":"2026-07-22T00:00:00Z"}
	],"branch_timezone":"Asia/Tokyo","branch_wall_clock":"2026-07-22 09:00:00"}`, receiptSum, receipt, kitchen))

	db := newPrintTemplateTestDB(t)
	p := NewSyncPuller(db, srv.URL, func() string { return "tok" })
	if err := p.PullPrintTemplates(context.Background()); err == nil {
		t.Fatal("a poisoned entry must fail the whole pull")
	}
	var count int
	if err := db.QueryRow(`SELECT COUNT(*) FROM print_templates`).Scan(&count); err != nil {
		t.Fatal(err)
	}
	if count != 0 {
		t.Errorf("the healthy sibling must not land either — got %d rows", count)
	}
}

// An old Cloud that predates the checksum field is accepted (there is nothing
// to disagree with) — the workstation must not refuse to sync against a Cloud
// it is deliberately deployed ahead of.
func TestPrintTemplatePull_MissingChecksumIsAccepted(t *testing.T) {
	body := brandTemplate(t, "receipt", "no checksum")
	srv := servePrintTemplates(t, fmt.Sprintf(`{"data":[{"kind":"receipt","scope":"brand","version":2,
		"definition":%s,"updated_at":"2026-07-21T00:00:00Z"}]}`, body))

	db := newPrintTemplateTestDB(t)
	p := NewSyncPuller(db, srv.URL, func() string { return "tok" })
	if err := p.PullPrintTemplates(context.Background()); err != nil {
		t.Fatalf("a checksum-less Cloud must still sync: %v", err)
	}
	s := NewPrintTemplateStore(db)
	if _, src := s.Resolve("receipt"); src.Version != 2 {
		t.Errorf("want version 2, got %d", src.Version)
	}
}

// An empty delta is the steady state — nothing changed since the last pull. It
// must be a clean no-op that still refreshes the branch clock.
func TestPrintTemplatePull_EmptyDeltaKeepsCacheAndRefreshesClock(t *testing.T) {
	srv := servePrintTemplates(t, `{"data":[],"branch_timezone":"Asia/Ho_Chi_Minh",
		"branch_wall_clock":"2026-07-21 09:00:00"}`)

	db := newPrintTemplateTestDB(t)
	seedCachedTemplate(t, db, "receipt", 5, "", brandTemplate(t, "receipt", "unchanged"))
	p := NewSyncPuller(db, srv.URL, func() string { return "tok" })
	if err := p.PullPrintTemplates(context.Background()); err != nil {
		t.Fatalf("an empty delta must be a clean no-op: %v", err)
	}
	s := NewPrintTemplateStore(db)
	if _, src := s.Resolve("receipt"); src.Version != 5 {
		t.Errorf("an empty delta must not disturb the cache, got version %d", src.Version)
	}
	if tz := s.setting(printTemplatesTimezoneKey); tz != "Asia/Ho_Chi_Minh" {
		t.Errorf("an empty delta must still refresh the branch clock, got %q", tz)
	}
}

// Re-pulling the same version updates it in place rather than duplicating —
// (kind, version) is the primary key and a republish of the same number (a
// Cloud-side correction) must not multiply rows.
func TestPrintTemplatePull_SameVersionUpsertsInPlace(t *testing.T) {
	first := brandTemplate(t, "receipt", "first text")
	second := brandTemplate(t, "receipt", "second text")
	firstSum, _ := PrintTemplateChecksum(first)
	secondSum, _ := PrintTemplateChecksum(second)

	db := newPrintTemplateTestDB(t)
	for i, pair := range []struct{ body, sum, updated string }{
		{string(first), firstSum, "2026-07-21T00:00:00Z"},
		{string(second), secondSum, "2026-07-22T00:00:00Z"},
	} {
		srv := servePrintTemplates(t, fmt.Sprintf(`{"data":[{"kind":"receipt","scope":"brand","version":4,
			"checksum":%q,"definition":%s,"updated_at":%q}]}`, pair.sum, pair.body, pair.updated))
		p := NewSyncPuller(db, srv.URL, func() string { return "tok" })
		if err := p.PullPrintTemplates(context.Background()); err != nil {
			t.Fatalf("pull %d: %v", i, err)
		}
	}

	var count int
	if err := db.QueryRow(`SELECT COUNT(*) FROM print_templates WHERE kind='receipt'`).Scan(&count); err != nil {
		t.Fatal(err)
	}
	if count != 1 {
		t.Errorf("republishing version 4 must upsert, not duplicate — got %d rows", count)
	}
	var stored string
	if err := db.QueryRow(`SELECT definition FROM print_templates WHERE kind='receipt' AND version=4`).Scan(&stored); err != nil {
		t.Fatal(err)
	}
	if stored != string(second) {
		t.Error("the second pull's definition did not replace the first")
	}
}

// The cursor advances only on a successful, fully verified pull — and it is URL
// encoded, so an ISO offset ("+09:00") cannot reach Cloud as a space and shift
// the delta window by the branch's UTC offset.
func TestPrintTemplatePull_CursorAdvancesAndIsUrlEncoded(t *testing.T) {
	body := brandTemplate(t, "receipt", "cursor test")
	sum, _ := PrintTemplateChecksum(body)

	var sawSince string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		sawSince = r.URL.Query().Get("since")
		w.Header().Set("Content-Type", "application/json")
		if sawSince != "" {
			fmt.Fprint(w, `{"data":[]}`)
			return
		}
		fmt.Fprintf(w, `{"data":[{"kind":"receipt","scope":"brand","version":6,"checksum":%q,
			"definition":%s,"updated_at":"2026-07-21T09:00:00+09:00"}]}`, sum, body)
	}))
	defer srv.Close()

	db := newPrintTemplateTestDB(t)
	p := NewSyncPuller(db, srv.URL, func() string { return "tok" })
	if err := p.PullPrintTemplates(context.Background()); err != nil {
		t.Fatal(err)
	}
	if got := p.getCursor(printTemplatesCursorKey); got != "2026-07-21T09:00:00+09:00" {
		t.Fatalf("cursor should be the newest updated_at, got %q", got)
	}
	if err := p.PullPrintTemplates(context.Background()); err != nil {
		t.Fatal(err)
	}
	if sawSince != "2026-07-21T09:00:00+09:00" {
		t.Errorf("the ?since= offset was mangled in transit: %q", sawSince)
	}
}

// A cache row flagged is_system_default (Cloud saying "this branch has nothing
// published") is still a CACHE row, and using it is correct — it is Cloud's
// resolved answer, not a guess.
func TestPrintTemplateVersion_CloudSystemDefaultRowIsUsed(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	raw, err := SystemPrintTemplateRaw("receipt")
	if err != nil {
		t.Fatal(err)
	}
	sum, _ := PrintTemplateChecksum(raw)
	db := newPrintTemplateTestDB(t)
	if _, err := db.Exec(`
		INSERT INTO print_templates (kind, version, scope, definition, effective_from, checksum, is_system_default, fetched_at)
		VALUES ('receipt', 1, 'system', ?, NULL, ?, 1, datetime('now'))`, string(raw), sum); err != nil {
		t.Fatal(err)
	}

	s := NewPrintTemplateStore(db)
	cfg := goldenConfig("ja", 42)
	got, src, err := s.RenderSlip(goldenRenderData("receipt", cfg), PrintRenderProfile{Columns: 42}, "ja")
	if err != nil {
		t.Fatal(err)
	}
	if src.Scope != "system" || src.Fallback {
		t.Errorf("Cloud's system-scope row should be used as a normal cache hit, got %+v", src)
	}
	order, items := goldenOrder()
	if want := FormatPaidTicket(order, items, 7, cfg, goldenSlip()); !bytes.Equal(want, got) {
		t.Errorf("Cloud's system default is not today's receipt:\n%s", diffBytes(want, got))
	}
}

// A cached version older than the software still WINS over the binary's
// default. The binary's copy is a floor, not a competitor: if a newer build
// could quietly override a brand's published template, upgrading the
// workstation would silently un-publish HQ's decision.
func TestPrintTemplateVersion_StaleCacheStillBeatsTheEmbeddedDefault(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	db := newPrintTemplateTestDB(t)
	seedCachedTemplate(t, db, "receipt", 1, "2020-01-01 00:00:00",
		brandTemplate(t, "receipt", "published years ago and never touched since"))
	s := NewPrintTemplateStore(db)

	cfg := goldenConfig("ja", 42)
	got, src, err := s.RenderSlip(goldenRenderData("receipt", cfg), PrintRenderProfile{Columns: 42}, "ja")
	if err != nil {
		t.Fatal(err)
	}
	if src.IsSystemDefault() || src.Version != 1 {
		t.Fatalf("a stale cached version must still win, got %+v", src)
	}
	order, items := goldenOrder()
	if bytes.Equal(FormatPaidTicket(order, items, 7, cfg, goldenSlip()), got) {
		t.Error("the brand's published footer was silently dropped in favour of the binary default")
	}
}

// Kinds are independent: a broken receipt template must not disturb the kitchen
// ticket, and a kind with no cache row at all falls back on its own.
func TestPrintTemplateVersion_KindsAreIndependent(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	db := newPrintTemplateTestDB(t)
	if _, err := db.Exec(`
		INSERT INTO print_templates (kind, version, scope, definition, effective_from, checksum, is_system_default, fetched_at)
		VALUES ('receipt', 2, 'brand', 'not a definition', NULL, 'x', 0, datetime('now'))`); err != nil {
		t.Fatal(err)
	}
	seedCachedTemplate(t, db, "kitchen", 2, "", brandTemplate(t, "kitchen", "kitchen ok"))
	s := NewPrintTemplateStore(db)

	if _, src := s.Resolve("receipt"); !src.IsSystemDefault() {
		t.Errorf("the broken receipt row should fall back, got %+v", src)
	}
	if _, src := s.Resolve("kitchen"); src.Version != 2 {
		t.Errorf("the healthy kitchen row must be unaffected, got %+v", src)
	}
	if _, src := s.Resolve("vat_invoice"); !src.IsSystemDefault() {
		t.Errorf("a kind with no row at all falls back on its own, got %+v", src)
	}
}

// A kind the workstation has never heard of must not panic the print path.
func TestPrintTemplateVersion_UnknownKindIsAnErrorNotAPanic(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	s := NewPrintTemplateStore(db)
	def, src := s.Resolve("diagnostic")
	if def != nil {
		t.Error("there is no definition for an unknown kind")
	}
	if !src.Fallback || src.Reason == "" {
		t.Errorf("an unknown kind must be reported, got %+v", src)
	}
	if _, _, err := s.RenderSlip(&PrintRenderData{Kind: "diagnostic"}, PrintRenderProfile{Columns: 42}, "ja"); err == nil {
		t.Error("rendering an unknown kind must be an error the caller can see")
	}
}

var _ = json.Marshal
