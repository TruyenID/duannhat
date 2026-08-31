package handler

import (
	"path/filepath"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

// printLabelLocale is the single source every printed slip of an order reads,
// so that the kitchen ticket, the hold slip and the receipt always name the same
// dish the same way. It resolves entirely from LOCAL SQLite — printing must keep
// working in the configured language while the Cloud link is down.
func TestPrintLabelLocale_FallbackChain(t *testing.T) {
	db, err := storetest.Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	s := &Server{db: db}

	setShop := func(v string) {
		t.Helper()
		if _, err := db.Exec(`INSERT INTO shop_settings (key, value) VALUES ('print_label_locale', ?)
			ON CONFLICT(key) DO UPDATE SET value = excluded.value`, v); err != nil {
			t.Fatalf("set shop setting: %v", err)
		}
	}
	// The handler test DB carries only the hand-written migrations — `branches`
	// is omnify-managed and absent, mirroring a workstation that has not synced
	// yet. Create the minimal shape on demand (same approach as
	// pos_shop_guard_test.go).
	setBranch := func(v string) {
		t.Helper()
		if _, err := db.Exec(`CREATE TABLE IF NOT EXISTS branches (id TEXT PRIMARY KEY, locale TEXT)`); err != nil {
			t.Fatalf("create branches: %v", err)
		}
		if _, err := db.Exec(`DELETE FROM branches`); err != nil {
			t.Fatalf("clear branches: %v", err)
		}
		if _, err := db.Exec(`INSERT INTO branches (id, locale) VALUES ('b1', ?)`, v); err != nil {
			t.Fatalf("seed branch: %v", err)
		}
	}

	// 4. Nothing configured anywhere → "" so the print layer keeps its own default.
	if got := s.printLabelLocale(); got != "" {
		t.Errorf("nothing configured → %q, want \"\"", got)
	}

	// 3. Legacy operator locale is the last resort before the built-in default.
	s.rememberPrintLocale("vi")
	if got := s.printLabelLocale(); got != "vi" {
		t.Errorf("legacy operator locale → %q, want vi", got)
	}

	// 2. Branch default (synced from Cloud) beats the legacy value.
	setBranch("ja")
	if got := s.printLabelLocale(); got != "ja" {
		t.Errorf("branch locale must beat legacy → %q, want ja", got)
	}

	// 1. The manager's explicit choice beats everything.
	setShop("en")
	if got := s.printLabelLocale(); got != "en" {
		t.Errorf("shop setting must win → %q, want en", got)
	}

	// A garbage/unsupported value must not stop resolution — it falls through to
	// the branch default rather than printing in a language nobody chose.
	setShop("fr")
	if got := s.printLabelLocale(); got != "ja" {
		t.Errorf("unsupported shop setting must fall through to branch → %q, want ja", got)
	}
	setShop("")
	if got := s.printLabelLocale(); got != "ja" {
		t.Errorf("empty shop setting must fall through to branch → %q, want ja", got)
	}
}

// Every slip of one order must render in the SAME language regardless of which
// terminal triggered it. Before this, each print path read the calling
// terminal's Accept-Language, so a ja terminal firing the kitchen and a vi
// terminal printing the receipt produced two slips naming the same dish
// differently — staff could not match them when handing food over.
func TestOrderPrintLocale_IgnoresCustomerLocale(t *testing.T) {
	db, err := storetest.Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	s := &Server{db: db}

	if _, err := db.Exec(`INSERT INTO shop_settings (key, value) VALUES ('print_label_locale','ja')`); err != nil {
		t.Fatalf("seed setting: %v", err)
	}
	// A guest who ordered in Vietnamese.
	if _, err := db.Exec(`
		INSERT INTO orders (id, order_code, order_type, status, opened_at, customer_locale,
			branch_id, brand_id, organization_id, created_at, updated_at)
		VALUES ('o1','ORD-1','dine_in','open', datetime('now'), 'vi', 'b','br','o', datetime('now'), datetime('now'))
	`); err != nil {
		t.Fatalf("seed order: %v", err)
	}

	// The shop's language governs — the guest's does not leak onto the paper.
	if got := s.orderPrintLocale("o1"); got != "ja" {
		t.Errorf("customer_locale must not override the shop language: got %q, want ja", got)
	}
	// An order that does not exist resolves the same way (no panic, no divergence).
	if got := s.orderPrintLocale("does-not-exist"); got != "ja" {
		t.Errorf("unknown order → %q, want ja", got)
	}
}

// The explicit local override (print_locale_override, set in the WS App) is the
// TOP of the chain: HQ default → shop override → workstation override, each
// layer free to overrule the one above it.
//
// It used to rank below the Cloud-synced shop language. Because Cloud always
// resolves `shop ?? brand default` before shipping the branch feed, that made
// the override unreachable the moment either layer was filled in — the WS App
// picker became a dead control, and a station stuck on the wrong language had
// no way back.
func TestPrintLabelLocale_LocalOverrideRank(t *testing.T) {
	db, err := storetest.Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	s := &Server{db: db}

	setKey := func(key, v string) {
		t.Helper()
		if _, err := db.Exec(`INSERT INTO settings (key, value) VALUES (?, ?)
			ON CONFLICT(key) DO UPDATE SET value = excluded.value`, key, v); err != nil {
			t.Fatalf("set %s: %v", key, err)
		}
	}
	setShop := func(v string) {
		t.Helper()
		if _, err := db.Exec(`INSERT INTO shop_settings (key, value) VALUES ('print_label_locale', ?)
			ON CONFLICT(key) DO UPDATE SET value = excluded.value`, v); err != nil {
			t.Fatalf("set shop: %v", err)
		}
	}
	setBranch := func(v string) {
		t.Helper()
		db.Exec(`CREATE TABLE IF NOT EXISTS branches (id TEXT PRIMARY KEY, locale TEXT)`)
		db.Exec(`DELETE FROM branches`)
		if _, err := db.Exec(`INSERT INTO branches (id, locale) VALUES ('b1', ?)`, v); err != nil {
			t.Fatalf("seed branch: %v", err)
		}
	}

	// Branch says ja, but the operator explicitly picked vi in the WS App.
	setBranch("ja")
	setKey(printLocaleOverrideKey, "vi")
	if got := s.printLabelLocale(); got != "vi" {
		t.Errorf("local override must beat branch → %q, want vi", got)
	}

	// The legacy auto-captured operator locale must NOT beat the explicit
	// override (they are separate keys now).
	s.rememberPrintLocale("en")
	if got := s.printLabelLocale(); got != "vi" {
		t.Errorf("explicit override must beat legacy operator locale → %q, want vi", got)
	}

	// Cloud sets a shop-wide language, but this station was deliberately pinned
	// to vi — the local pick stands. This is the whole point of the control:
	// without it, one odd station can never deviate and a wrongly-synced value
	// can never be worked around on the floor.
	setShop("en")
	if got := s.printLabelLocale(); got != "vi" {
		t.Errorf("local override must beat the cloud shop setting → %q, want vi", got)
	}

	// Clearing the override (the "theo Cloud" button) hands control back to the
	// Cloud-resolved value — the default, and the way to keep every station in
	// step.
	setKey(printLocaleOverrideKey, "")
	if got := s.printLabelLocale(); got != "en" {
		t.Errorf("cleared override must fall back to the cloud setting → %q, want en", got)
	}

	// An unsupported override value is ignored and resolution falls through to
	// the next configured source — here the cloud shop setting, not the branch.
	setKey(printLocaleOverrideKey, "fr")
	if got := s.printLabelLocale(); got != "en" {
		t.Errorf("unsupported override must fall through to cloud → %q, want en", got)
	}

	// With Cloud silent too, an unsupported override still falls through rather
	// than printing in a language nobody chose.
	setShop("")
	if got := s.printLabelLocale(); got != "ja" {
		t.Errorf("unsupported override must fall through to branch → %q, want ja", got)
	}
}

// storeName is what every printed slip's header line shows. It must survive
// the exact failure this test reproduces: Cloud's branches.name serializing
// as null (Astrotomic Translatable returns null when a branch has zero
// branch_translations rows, even though the base column holds a real name —
// see PreservesTranslatableColumns / Branch::attributesToArray on the Cloud
// side). PullBranch's `if br.Name != ""` guard then never writes
// workstation_branch_name, so without this fallback the slip printed the
// hardcoded literal "Store" in the middle of an otherwise-Japanese receipt.
func TestStoreName_FallsBackToLocalWhenCloudNameMissing(t *testing.T) {
	db, err := storetest.Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	s := &Server{db: db}

	setKey := func(key, v string) {
		t.Helper()
		if _, err := db.Exec(`INSERT INTO settings (key, value) VALUES (?, ?)
			ON CONFLICT(key) DO UPDATE SET value = excluded.value`, key, v); err != nil {
			t.Fatalf("set %s: %v", key, err)
		}
	}

	// Nothing configured — a fresh install before pairing/PullBranch has ever
	// run, and before the operator has typed a store name either.
	if got := s.storeName(); got != "" {
		t.Errorf("nothing configured → %q, want \"\"", got)
	}

	// Operator fills in Settings → Store → "Tên cửa hàng" — the offline
	// escape hatch, independent of Cloud ever syncing anything.
	setKey("store_name", "Betoya Shinjuku")
	if got := s.storeName(); got != "Betoya Shinjuku" {
		t.Errorf("local store_name fallback → %q, want Betoya Shinjuku", got)
	}

	// Cloud syncs successfully (PullBranch wrote workstation_branch_name) —
	// it wins over the local fallback so a manager rename on admin-web
	// reaches every station without anyone touching this machine.
	setKey("workstation_branch_name", "新宿店")
	if got := s.storeName(); got != "新宿店" {
		t.Errorf("cloud-synced branch name must win → %q, want 新宿店", got)
	}
}
