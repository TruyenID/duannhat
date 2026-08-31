package service

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

// plan-053 M3 (#1171) — TESTS §4 (W1…W7).
//
// Every scenario here is a way the registry can be missing, stale, corrupt or
// unreachable. The assertion is always the same underneath: THE SLIP STILL
// PRINTS. A shop that cannot hand a customer a receipt is a shop that cannot
// take the customer's money.

// newPrintTemplateTestDB hands back a CLEAN template cache.
//
// It used to share ONE SQLite file across the package and truncate between
// tests, because `store.Open` replayed every migration at ~1s a go and this
// file alone opens ~40 databases. #1186 removed that cost — storetest.Open
// copies a migrated template in ~15ms — so each test now gets a genuinely
// separate database. That is stronger isolation than truncating two tables
// was, not merely faster: nothing else a previous test wrote can survive.
func newPrintTemplateTestDB(t *testing.T) *store.DB {
	t.Helper()

	return openIsolatedPrintTemplateDB(t, "print-templates.db")
}

// openIsolatedPrintTemplateDB is a genuinely separate database — a second
// workstation, not a second test.
func openIsolatedPrintTemplateDB(t *testing.T, name string) *store.DB {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), name))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { _ = db.Close() })
	return db
}

// seedCachedTemplate writes one cache row the way a verified pull would.
func seedCachedTemplate(t *testing.T, db *store.DB, kind string, version int, effectiveFrom string, definition []byte) {
	t.Helper()
	sum, err := PrintTemplateChecksum(definition)
	if err != nil {
		t.Fatalf("checksum: %v", err)
	}
	var eff any
	if effectiveFrom != "" {
		eff = effectiveFrom
	}
	if _, err := db.Exec(`
		INSERT INTO print_templates
			(kind, version, scope, definition, effective_from, checksum, is_system_default, cloud_updated_at, fetched_at)
		VALUES (?, ?, 'brand', ?, ?, ?, 0, ?, datetime('now'))`,
		kind, version, string(definition), eff, sum, fmt.Sprintf("2026-07-%02dT00:00:00Z", version)); err != nil {
		t.Fatalf("seed cache: %v", err)
	}
}

// brandTemplate takes the embedded system default and changes ONE authored
// string, which is exactly the shape of a real brand publish: same blocks, same
// order, different words.
func brandTemplate(t *testing.T, kind, footerJA string) []byte {
	t.Helper()
	raw, err := SystemPrintTemplateRaw(kind)
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
	out, _ := json.Marshal(doc)
	return out
}

// W1 — a machine that has NEVER been online. Cache empty, Cloud unreachable.
// TR-05: the binary's own definition prints, and it prints today's slip.
func TestPrintTemplateCache_W1_ColdCacheOfflinePrintsSystemDefault(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	db := newPrintTemplateTestDB(t)
	s := NewPrintTemplateStore(db)

	cfg := goldenConfig("ja", 42)
	got, src, err := s.RenderSlip(goldenRenderData("receipt", cfg), PrintRenderProfile{Columns: 42}, "ja")
	if err != nil {
		t.Fatalf("cold-cache render must not fail: %v", err)
	}
	if !src.IsSystemDefault() {
		t.Errorf("expected the embedded system default, got version %d scope %q", src.Version, src.Scope)
	}
	order, items := goldenOrder()
	if want := FormatPaidTicket(order, items, 7, cfg, goldenSlip()); !bytes.Equal(want, got) {
		t.Errorf("cold-cache slip differs from today's receipt:\n%s", diffBytes(want, got))
	}
}

// W2 — a pull whose checksum does not verify (truncated body, mangled proxy).
// TR-24: the good cache survives untouched and the cursor does not advance, so
// the next tick tries again.
func TestPrintTemplateCache_W2_ChecksumMismatchKeepsOldCache(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	good := brandTemplate(t, "receipt", "ありがとうございました")
	seedCachedTemplate(t, db, "receipt", 4, "", good)

	poisoned := brandTemplate(t, "receipt", "POISONED")
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprintf(w, `{"data":[{"kind":"receipt","scope":"brand","version":5,"effective_from":null,
			"checksum":"deadbeef","is_system_default":false,"definition":%s,"updated_at":"2026-07-21T00:00:00Z"}],
			"branch_timezone":"Asia/Tokyo","branch_wall_clock":"2026-07-21 09:00:00"}`, poisoned)
	}))
	defer srv.Close()

	p := NewSyncPuller(db, srv.URL, func() string { return "device-token" })
	if err := p.PullPrintTemplates(context.Background()); err == nil {
		t.Fatal("a checksum mismatch must fail the pull, not be swallowed")
	}

	var count int
	if err := db.QueryRow(`SELECT COUNT(*) FROM print_templates`).Scan(&count); err != nil {
		t.Fatal(err)
	}
	if count != 1 {
		t.Errorf("unverified payload must not be written: cache holds %d rows, want 1", count)
	}
	if cursor := p.getCursor(printTemplatesCursorKey); cursor != "" {
		t.Errorf("cursor advanced past an unverified pull: %q", cursor)
	}

	s := NewPrintTemplateStore(db)
	_, src := s.Resolve("receipt")
	if src.Version != 4 {
		t.Errorf("resolve must still serve the last verified version, got %d", src.Version)
	}
}

// W3 — a version published to take effect at a future BRANCH time, on a
// workstation that stays offline right through the switchover. TR-12/TR-25: it
// was cached in advance, so it takes over on time without any network.
func TestPrintTemplateCache_W3_FutureEffectiveFromSwitchesOverOffline(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	seedCachedTemplate(t, db, "receipt", 4, "2026-07-01 00:00:00", brandTemplate(t, "receipt", "current"))
	seedCachedTemplate(t, db, "receipt", 5, "2026-08-01 04:00:00", brandTemplate(t, "receipt", "future"))
	s := NewPrintTemplateStore(db)

	before := time.Date(2026, 8, 1, 3, 59, 0, 0, time.UTC)
	if _, src := s.ResolveAt("receipt", before); src.Version != 4 {
		t.Errorf("before the switchover: want version 4, got %d", src.Version)
	}
	after := time.Date(2026, 8, 1, 4, 0, 1, 0, time.UTC)
	if _, src := s.ResolveAt("receipt", after); src.Version != 5 {
		t.Errorf("after the switchover: want version 5, got %d", src.Version)
	}
}

// W3b — the branch's timezone decides, not the server's and not the machine's.
// The same instant is "before" in Tokyo and "after" in Hanoi (#1091).
func TestPrintTemplateCache_W3b_SwitchoverFollowsBranchTimezone(t *testing.T) {
	db := newPrintTemplateTestDB(t)
	seedCachedTemplate(t, db, "receipt", 4, "2026-07-01 00:00:00", brandTemplate(t, "receipt", "current"))
	seedCachedTemplate(t, db, "receipt", 5, "2026-08-01 00:30:00", brandTemplate(t, "receipt", "future"))
	s := NewPrintTemplateStore(db)

	// 2026-07-31 17:15 UTC = 2026-08-01 02:15 JST (past) but 2026-08-01 00:15
	// ICT (not yet). Same instant, two answers — which is the whole point.
	instant := time.Date(2026, 7, 31, 17, 15, 0, 0, time.UTC)
	tokyo, _ := time.LoadLocation("Asia/Tokyo")
	hanoi, _ := time.LoadLocation("Asia/Ho_Chi_Minh")

	if _, src := s.ResolveAt("receipt", instant.In(tokyo)); src.Version != 5 {
		t.Errorf("Tokyo branch should already be on version 5, got %d", src.Version)
	}
	if _, src := s.ResolveAt("receipt", instant.In(hanoi)); src.Version != 4 {
		t.Errorf("Hanoi branch should still be on version 4, got %d", src.Version)
	}
}

// W4 — Cloud is completely dead. The pull fails; printing does not.
func TestPrintTemplateCache_W4_CloudDownStillPrintsFromCache(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	db := newPrintTemplateTestDB(t)
	seedCachedTemplate(t, db, "receipt", 9, "", brandTemplate(t, "receipt", "またのご来店をお待ちしております"))

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {}))
	srv.Close() // dead on arrival
	p := NewSyncPuller(db, srv.URL, func() string { return "device-token" })
	if err := p.PullPrintTemplates(context.Background()); err == nil {
		t.Fatal("expected the pull to fail against a dead Cloud")
	}

	s := NewPrintTemplateStore(db)
	cfg := goldenConfig("ja", 42)
	out, src, err := s.RenderSlip(goldenRenderData("receipt", cfg), PrintRenderProfile{Columns: 42}, "ja")
	if err != nil {
		t.Fatalf("printing must survive a Cloud outage: %v", err)
	}
	if src.Version != 9 {
		t.Errorf("want the cached brand version 9, got %d", src.Version)
	}
	if !bytes.Contains(out, []byte{0x1B, 0x64, 0x33}) {
		t.Error("slip has no cut command — it is not a complete slip")
	}
}

// W5 — bit rot: a cache row that is no longer valid JSON. TR-14: log loudly,
// fall back, PRINT.
func TestPrintTemplateCache_W5_CorruptDefinitionFallsBackAndStillPrints(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	db := newPrintTemplateTestDB(t)
	if _, err := db.Exec(`
		INSERT INTO print_templates (kind, version, scope, definition, effective_from, checksum, is_system_default, fetched_at)
		VALUES ('receipt', 7, 'brand', '{"schema":"tempo.print.v1","blocks":[', NULL, 'x', 0, datetime('now'))`); err != nil {
		t.Fatal(err)
	}
	s := NewPrintTemplateStore(db)

	cfg := goldenConfig("ja", 42)
	got, src, err := s.RenderSlip(goldenRenderData("receipt", cfg), PrintRenderProfile{Columns: 42}, "ja")
	if err != nil {
		t.Fatalf("a corrupt template must not stop a sale: %v", err)
	}
	if !src.IsSystemDefault() || !src.Fallback {
		t.Errorf("expected a flagged fallback to the system default, got %+v", src)
	}
	order, items := goldenOrder()
	if want := FormatPaidTicket(order, items, 7, cfg, goldenSlip()); !bytes.Equal(want, got) {
		t.Errorf("fallback slip differs from today's receipt:\n%s", diffBytes(want, got))
	}
}

// W6 — an operator wipes the cache table (or a repair drops it). TR-27: the
// default carries the shop until the next pull refills it.
func TestPrintTemplateCache_W6_WipedCacheRefillsFromCloud(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	db := newPrintTemplateTestDB(t)
	seedCachedTemplate(t, db, "receipt", 4, "", brandTemplate(t, "receipt", "before the wipe"))
	if _, err := db.Exec(`DELETE FROM print_templates`); err != nil {
		t.Fatal(err)
	}

	s := NewPrintTemplateStore(db)
	if _, src := s.Resolve("receipt"); !src.IsSystemDefault() {
		t.Errorf("a wiped cache must resolve to the system default, got %+v", src)
	}

	refill := brandTemplate(t, "receipt", "after the refill")
	sum, _ := PrintTemplateChecksum(refill)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprintf(w, `{"data":[{"kind":"receipt","scope":"brand","version":4,"effective_from":null,
			"checksum":%q,"is_system_default":false,"definition":%s,"updated_at":"2026-07-21T00:00:00Z"}],
			"branch_timezone":"Asia/Tokyo","branch_wall_clock":"2026-07-21 09:00:00"}`, sum, refill)
	}))
	defer srv.Close()

	p := NewSyncPuller(db, srv.URL, func() string { return "device-token" })
	if err := p.PullPrintTemplates(context.Background()); err != nil {
		t.Fatalf("refill pull: %v", err)
	}
	if _, src := s.Resolve("receipt"); src.Version != 4 || src.IsSystemDefault() {
		t.Errorf("cache did not refill: %+v", src)
	}
	if tz := s.setting(printTemplatesTimezoneKey); tz != "Asia/Tokyo" {
		t.Errorf("branch timezone not recorded, got %q", tz)
	}
}

// W7 — two workstations on one branch pull the same payload independently.
// TR-26: they resolve the same version and print the same bytes, so a customer
// cannot tell which terminal served them.
func TestPrintTemplateCache_W7_TwoWorkstationsConverge(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	body := brandTemplate(t, "receipt", "共通フッター")
	sum, _ := PrintTemplateChecksum(body)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprintf(w, `{"data":[{"kind":"receipt","scope":"brand","version":11,"effective_from":"2026-07-01 00:00:00",
			"checksum":%q,"is_system_default":false,"definition":%s,"updated_at":"2026-07-21T00:00:00Z"}],
			"branch_timezone":"Asia/Tokyo","branch_wall_clock":"2026-07-21 09:00:00"}`, sum, body)
	}))
	defer srv.Close()

	cfg := goldenConfig("ja", 42)
	var slips [][]byte
	for i := 0; i < 2; i++ {
		db := openIsolatedPrintTemplateDB(t, fmt.Sprintf("ws%d.db", i))
		p := NewSyncPuller(db, srv.URL, func() string { return "device-token" })
		if err := p.PullPrintTemplates(context.Background()); err != nil {
			t.Fatalf("workstation %d pull: %v", i, err)
		}
		s := NewPrintTemplateStore(db)
		out, src, err := s.RenderSlip(goldenRenderData("receipt", cfg), PrintRenderProfile{Columns: 42}, "ja")
		if err != nil {
			t.Fatalf("workstation %d render: %v", i, err)
		}
		if src.Version != 11 {
			t.Errorf("workstation %d resolved version %d, want 11", i, src.Version)
		}
		slips = append(slips, out)
	}
	if !bytes.Equal(slips[0], slips[1]) {
		t.Errorf("two workstations on one branch printed different slips:\n%s", diffBytes(slips[0], slips[1]))
	}
	// And the published template really is what printed — otherwise this test
	// would pass just as happily on two identical system defaults.
	sysDef, _ := SystemPrintTemplate("receipt")
	sysRes, err := RenderPrintTemplate(sysDef, goldenRenderData("receipt", cfg), PrintRenderProfile{Columns: 42}, "ja")
	if err != nil {
		t.Fatal(err)
	}
	if bytes.Equal(sysRes.Bytes(), slips[0]) {
		t.Error("the published footer never reached the slip — both terminals printed the system default")
	}
}

// TestPrintTemplateChecksum_MatchesCloudCanonicalisation locks the TR-24 gate to
// Cloud's `TemplateChecksum::of`: sha256 over JSON with every OBJECT's keys
// sorted recursively, arrays left in place, no HTML escaping, no unicode
// escaping. The expected value was produced by PHP from the identical document.
func TestPrintTemplateChecksum_MatchesCloudCanonicalisation(t *testing.T) {
	// Same document, keys deliberately out of order and nested — if Go sorted
	// only the top level, or escaped the slash or the CJK, the hash would move.
	a := []byte(`{"b":[3,1,2],"a":{"z":1,"y":{"n":"領収書/A&B","m":2}}}`)
	b := []byte(`{"a":{"y":{"m":2,"n":"領収書/A&B"},"z":1},"b":[3,1,2]}`)

	sumA, err := PrintTemplateChecksum(a)
	if err != nil {
		t.Fatal(err)
	}
	sumB, err := PrintTemplateChecksum(b)
	if err != nil {
		t.Fatal(err)
	}
	if sumA != sumB {
		t.Fatalf("key order changed the checksum: %s vs %s", sumA, sumB)
	}
	const wantPHP = "6bc7f2cc9c5cada235e9f72ac51d2f6f71c8ca334ed4d0326831655f0b4c2bbf"
	if sumA != wantPHP {
		t.Errorf("checksum diverged from Cloud's canonicalisation:\n got %s\nwant %s", sumA, wantPHP)
	}

	// Array order IS meaning — block order on a receipt — so it must change it.
	reordered := []byte(`{"b":[1,3,2],"a":{"z":1,"y":{"n":"領収書/A&B","m":2}}}`)
	sumC, _ := PrintTemplateChecksum(reordered)
	if sumC == sumA {
		t.Error("reordering an array must change the checksum — block order is meaning")
	}
}

// ── TR-28 (#1171): the provenance stamp ───────────────────────────────────
//
// `Stamp` is the value that lands on `print_jobs.template_version` and is the
// only record of WHICH layout drew a sheet once the paper leaves the printer.
// Two properties are load-bearing, and both are about what it must NOT do.

func TestPrintTemplateSource_StampCarriesScopeAndVersion(t *testing.T) {
	cases := map[string]struct {
		src  PrintTemplateSource
		want string
	}{
		"brand version":  {PrintTemplateSource{Kind: "receipt", Scope: "brand", Version: 7}, "brand:7"},
		"shop version":   {PrintTemplateSource{Kind: "receipt", Scope: "shop", Version: 12}, "shop:12"},
		"system default": {PrintTemplateSource{Kind: "receipt", Scope: "system"}, "system:0"},
		// A fallback still says WHAT drew the sheet. The reason it fell back is
		// in the log; the stamp answers a different question ("which definition
		// produced these bytes") and must answer it the same way either way.
		"system after fallback": {
			PrintTemplateSource{Kind: "receipt", Scope: "system", Fallback: true, Reason: "cached definition unusable"},
			"system:0",
		},
		// Scope is what distinguishes brand v3 from shop v3, so a row that lost
		// it must say `unknown` — never `system`, which would send a reprint to
		// the binary's default for a sheet a published template drew.
		"scopeless cache row": {PrintTemplateSource{Kind: "receipt", Version: 4}, "unknown:4"},
	}

	for name, tc := range cases {
		if got := tc.src.Stamp(); got != tc.want {
			t.Errorf("%s: Stamp() = %q, want %q", name, got, tc.want)
		}
	}
}

// The empty string is reserved — it is how the seam says "the legacy formatter
// drew this, there is no version". If Stamp could ever produce it, that signal
// would stop being decidable and a reprint of a formatter sheet would go
// looking for a template that never drew it.
func TestPrintTemplateSource_StampIsNeverEmpty(t *testing.T) {
	for _, src := range []PrintTemplateSource{
		{},
		{Kind: "receipt"},
		{Scope: "", Version: 0},
		{Fallback: true},
	} {
		if got := src.Stamp(); got == "" {
			t.Errorf("Stamp() on %#v = \"\" — empty is reserved for the legacy formatter", src)
		}
	}
}

// The renderer's own answer must round-trip through the stamp: whatever
// RenderSlip reports as the source is what the ledger will claim, so the two
// must not be able to drift.
func TestRenderSlip_SourceStampMatchesTheRenderedVersion(t *testing.T) {
	s := NewPrintTemplateStore(newPrintTemplateTestDB(t))

	_, src, err := s.RenderSlip(
		&PrintRenderData{
			Kind:      "shift_open",
			Config:    PrintJobConfig{StoreName: "STAMP", PaperWidth: 42, Locale: "ja"},
			ShiftOpen: &ShiftOpenReportInfo{},
		},
		PrintRenderProfile{Columns: 42},
		"ja",
	)
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	if !src.IsSystemDefault() {
		t.Fatalf("empty cache must resolve to the system default, got %#v", src)
	}
	if got := src.Stamp(); got != "system:0" {
		t.Fatalf("Stamp() = %q, want system:0", got)
	}
}
