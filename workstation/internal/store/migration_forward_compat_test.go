package store

import (
	"fmt"
	"io/fs"
	"os"
	"regexp"
	"sort"
	"strings"
	"testing"
)

// One invariant, stated in words:
//
//	A binary at version N must be able to read the schema at version N+1.
//
// Workstation migrations are embedded in the binary and run inside store.Open()
// on EVERY boot. There is no schema sync from Cloud and there is no down step.
// So an install goes: swap binary → new binary boots → MIGRATIONS RUN →
// health-check. internal/update/supervise.go handles "the new binary died at
// boot" by restoring the .bak — but the migration has already committed, so the
// OLD binary comes back up against a NEWER schema.
//
// That is survivable today only because these migrations are almost entirely
// add-column / add-table: a binary that does not know a column simply never
// selects it. The day one of them drops or renames a column, the 2 AM
// unattended auto-update (#2635) rolls a shop back to a binary that cannot read
// its own database, with nobody watching (#2659, hole 1).
//
// This test is not a downgrade mechanism and must not grow into one. It is the
// place where breaking the invariant has to be said out loud, by hand, in the
// exception list below.

// forwardCompatExceptions is SHRINK-ONLY.
//
// A key is a migration that contains a banned statement; the value says why a
// shop survived it anyway. Adding a row is a product decision, not paperwork:
// you are asserting that if an update rolls back right after this migration,
// the previous binary still opens the database.
//
// An entry that no longer matches anything is a FAILURE, not dead weight — see
// TestForwardCompatExceptionListOnlyShrinks. The list may only get shorter.
var forwardCompatExceptions = map[string]string{
	// Rebuild-table + rename. The rebuilt table keeps every column the previous
	// binary read, so the invariant actually holds here; the RENAME TO is the
	// SQLite idiom for "drop a CHECK" / "change a PK" / "drop a NOT NULL",
	// which the rule cannot tell apart from a real rename. Each of these also
	// trips DROP TABLE — `CREATE t_new; DROP t; RENAME t_new TO t` drops a table
	// that predates the file — and the same reasoning covers both hits: the name
	// and the column set are back, byte for byte, by the end of the file.
	"017_coupons_drop_check.sql":             "table rebuild to drop a CHECK on coupons.discount_type; column set unchanged, so an older binary still reads every column it knew.",
	"020_pos_menu_sections_composite_pk.sql": "table rebuild to move pos_menu_sections to a composite PK (id, menu_id); column set unchanged.",
	"031_orders_guest_count_nullable.sql":    "table rebuild to drop NOT NULL on orders.guest_count; column set unchanged, and a relaxed constraint is readable by an older binary.",

	// These genuinely break the invariant. They shipped before there was an
	// unattended install path at all — every one of them reached a shop through
	// an attended, manual upgrade. Kept as a record, not as a precedent.
	// Drop-and-recreate of a replace-all Cloud mirror. Nothing local is authored
	// into it, the recreated table is a strict COLUMN SUPERSET of the old one,
	// and the pre-055 writer (checked in the archived repo at
	// godx-tempo-workstation-app@06f946c, the parent of 5ce5fa3 which added this
	// file) is `DELETE FROM …` + a plain 18-column INSERT — no ON CONFLICT and no
	// INSERT OR REPLACE, so losing `id`'s PRIMARY KEY cannot break it. A
	// rolled-back binary refills the mirror on its next pull tick.
	"055_effective_payment_options_device_scope.sql": "drops and recreates effective_payment_options to add (device_id, channel) scoping (#1080). Replace-all Cloud mirror, no locally-authored data, and every column the pre-055 binary read or wrote still exists — verified against that binary's DELETE-then-INSERT writer. Recreating a mirror is the only shape of DROP TABLE that keeps the invariant; a table holding local writes is not.",

	"027_coupons_parity.sql":             "renames coupon columns to backend-native names via table rebuild (min_order_amount→min_order_subtotal, …). Pre-dates the auto-update path (#2635) — an attended manual install was the only way to get it. Do not copy the pattern.",
	"028_promotions_parity.sql":          "rebuilds the menu_promotion_products pivot keyed on product_id instead of product_sku_id. Pre-dates the auto-update path (#2635). Do not copy the pattern.",
	"054_tax_types_single_rate.sql":      "drops tax_types.rate_dine_in / rate_takeaway (#1099 single-rate tax type). Pre-dates the auto-update path (#2635). Do not copy the pattern.",
	"059_drop_tax_alcohol_escalated.sql": "drops the dead tax_alcohol_escalated / is_alcohol columns (#1099 follow-up). Pre-dates the auto-update path (#2635). Do not copy the pattern.",
}

// The omnify set is embedded by the ROOT package (workstation/migrations.go)
// and runs through this same runner at a +1000 version offset, so it is in
// scope for the ban. It cannot be imported from here: the root package's
// `go:embed all:frontend/dist` does not compile in a clean checkout (the bundle
// is gitignored and filled by the build pipeline), so the directory is read
// from disk. A missing directory fails the test rather than skipping — a gate
// that quietly stops looking has rotted.
const omnifyMigrationDir = "../../migrations/omnify"

type destructiveRule struct {
	name  string
	why   string
	fires func(stmt string) bool
}

var (
	// Covers both SQLite spellings: `DROP COLUMN c` and bare `DROP c`.
	reAlterDrop      = regexp.MustCompile(`(?is)^\s*ALTER\s+TABLE\s+\S+\s+DROP\b`)
	reAlterRenameTo  = regexp.MustCompile(`(?is)^\s*ALTER\s+TABLE\s+.*\bRENAME\s+TO\b`)
	reAlterRenameCol = regexp.MustCompile(`(?is)^\s*ALTER\s+TABLE\s+.*\bRENAME\s+COLUMN\b`)
	// Anchored on ALTER TABLE on purpose: `CREATE TABLE t (c TEXT NOT NULL)` on
	// a brand-new table is fine and must never fire, or the gate lights up on
	// nearly every migration and somebody switches it off.
	reAlterAddColumn = regexp.MustCompile(`(?is)^\s*ALTER\s+TABLE\s+\S+\s+ADD\s+(?:COLUMN\s+)?\S`)
	reNotNull        = regexp.MustCompile(`(?is)\bNOT\s+NULL\b`)
	reDefault        = regexp.MustCompile(`(?is)\bDEFAULT\b`)

	// DROP TABLE / CREATE TABLE, captured with their target name so the drop can
	// be judged against what the SAME FILE created earlier — see dropTableRule.
	reDropTable   = regexp.MustCompile(`(?is)^\s*DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?([^\s;(]+)`)
	reCreateTable = regexp.MustCompile(`(?is)^\s*CREATE\s+(?:TEMP\s+|TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([^\s;(]+)`)
)

var destructiveRules = []destructiveRule{
	{
		name:  "DROP COLUMN",
		why:   "the rolled-back binary still SELECTs that column and every query touching the table fails",
		fires: reAlterDrop.MatchString,
	},
	{
		name:  "RENAME TO",
		why:   "the rolled-back binary looks for the old table name and finds nothing",
		fires: reAlterRenameTo.MatchString,
	},
	{
		name:  "RENAME COLUMN",
		why:   "the rolled-back binary looks for the old column name and every query touching it fails",
		fires: reAlterRenameCol.MatchString,
	},
	{
		name: "ADD COLUMN ... NOT NULL without DEFAULT",
		why:  "the rolled-back binary INSERTs without that column and every insert fails the constraint",
		fires: func(stmt string) bool {
			return reAlterAddColumn.MatchString(stmt) &&
				reNotNull.MatchString(stmt) &&
				!reDefault.MatchString(stmt)
		},
	},
}

// dropTableRule is deliberately NOT in destructiveRules: it is the only rule
// here that cannot be decided from one statement. `DROP TABLE x` breaks the
// invariant exactly as hard as DROP COLUMN — the rolled-back binary queries a
// table that is gone — but a migration is also allowed to build a scratch table
// and clean it up, which is what 013_printer_roles.sql does with the TEMP table
// it stages the legacy printer IPs in.
//
// A ban that fired on that gets switched off, which is why #2659 shipped the
// gap in the open rather than a half-right rule. So the drop is judged against
// what the SAME FILE created before it: a table this file CREATEd is this
// file's to drop; anything else predates the binary that is about to come back.
//
// Deliberately NOT counted as a creation: `ALTER TABLE a RENAME TO b`. A rename
// moves a table that already held data under a new name, so dropping `b`
// afterwards still destroys rows an older binary reads. It costs nothing to be
// strict here — every file using that idiom already trips the RENAME TO rule
// and has to be reasoned about by hand anyway.
//
// One legitimate exception shape this rule cannot see: a table CREATEd by one
// migration and dropped by a LATER migration that ships in the SAME release. No
// binary ever knew that table, so the invariant holds — but nothing in the files
// says which release they shipped in. That is a fine reason to add to
// forwardCompatExceptions; it is not a reason to weaken the rule.
var dropTableRule = destructiveRule{
	name: "DROP TABLE",
	why:  "the rolled-back binary still queries that table and every statement touching it fails with `no such table`",
}

// fileTables tracks which tables a single migration created, in statement order.
type fileTables map[string]bool

// note records a CREATE TABLE. Drops are not un-recorded: once a file has
// created a table, that name belongs to the file for the rest of it, and a
// double drop of a scratch table is a nonsense statement, not a rollback hazard.
func (f fileTables) note(stmt string) {
	if m := reCreateTable.FindStringSubmatch(stmt); m != nil {
		f[normalizeTableName(m[1])] = true
	}
}

func (f fileTables) has(name string) bool { return f[name] }

// droppedTableName returns the normalized target of a DROP TABLE statement.
func droppedTableName(stmt string) (string, bool) {
	m := reDropTable.FindStringSubmatch(stmt)
	if m == nil {
		return "", false
	}

	return normalizeTableName(m[1]), true
}

// normalizeTableName strips a schema qualifier (`temp.t`, `main.t` — SQLite
// resolves an unqualified name across attached schemas, so the bare name is the
// identity that matters), unwraps quoting, and lowercases. A quoted identifier
// containing a dot is kept whole; one containing a doubled quote is not
// unescaped, because no migration in this tree names a table that way and a
// half-right unescaper reads worse than none.
func normalizeTableName(raw string) string {
	name := strings.TrimSpace(raw)
	name = strings.TrimSuffix(name, ";")

	const quotes = "\"`[]'"
	if name == "" || strings.ContainsRune(quotes, rune(name[0])) {
		return strings.ToLower(strings.Trim(name, quotes))
	}

	if i := strings.LastIndex(name, "."); i >= 0 {
		name = name[i+1:]
	}

	return strings.ToLower(strings.Trim(name, quotes))
}

type destructiveFinding struct {
	file string
	line int
	rule destructiveRule
	stmt string
}

// TestMigrationsAreForwardCompatibleForAnOlderBinary scans the migration set
// that actually ships — not a hand-maintained list — so a migration added
// tomorrow is covered without anyone remembering to come back here.
func TestMigrationsAreForwardCompatibleForAnOlderBinary(t *testing.T) {
	for _, f := range scanMigrationsForDestructiveSQL(t) {
		if _, allowed := forwardCompatExceptions[f.file]; allowed {
			continue
		}

		t.Errorf("%s:%d — %s is banned in workstation migrations.\n"+
			"  statement: %s\n"+
			"  on rollback: %s\n"+
			"  A binary at version N must be able to read the schema at version N+1.\n"+
			"  Migrations run at boot BEFORE the health-check, and supervise.go's rollback\n"+
			"  restores the .bak binary but NOT the schema (#2659). If this is deliberate,\n"+
			"  say so out loud: add %q to forwardCompatExceptions with a reason.",
			f.file, f.line, f.rule.name, condense(f.stmt), f.rule.why, f.file)
	}
}

// The exception list is a ratchet. An entry that matches nothing means the
// migration changed or vanished, and a stale allowance is how a ban quietly
// becomes optional again.
func TestForwardCompatExceptionListOnlyShrinks(t *testing.T) {
	violating := map[string]bool{}
	for _, f := range scanMigrationsForDestructiveSQL(t) {
		violating[f.file] = true
	}

	names := make([]string, 0, len(forwardCompatExceptions))
	for name := range forwardCompatExceptions {
		names = append(names, name)
	}
	sort.Strings(names)

	for _, name := range names {
		if strings.TrimSpace(forwardCompatExceptions[name]) == "" {
			t.Errorf("exception %s carries no reason — an allowance nobody can audit is not an allowance", name)
		}
		if !violating[name] {
			t.Errorf("%s is on the exception list but contains no banned statement any more — delete the entry. This list only shrinks.", name)
		}
	}
}

// The scanner itself, exercised on synthetic SQL. Without this the ban is only
// as good as an unverified regex: the shapes below are exactly the ones that
// would either let a destructive statement through or fire on a harmless one.
func TestDestructiveRulesFireOnlyOnDestructiveStatements(t *testing.T) {
	cases := []struct {
		name string
		sql  string
		want string // "" = no finding expected
	}{
		{"drop column", `ALTER TABLE payments DROP COLUMN refund_of_id;`, "DROP COLUMN"},
		{"drop column, bare spelling", `ALTER TABLE payments DROP refund_of_id;`, "DROP COLUMN"},
		{"rename table", `ALTER TABLE payments_v2 RENAME TO payments;`, "RENAME TO"},
		{"rename column", `ALTER TABLE payments RENAME COLUMN old_id TO new_id;`, "RENAME COLUMN"},
		{"add not null column with no default", `ALTER TABLE payments ADD COLUMN refund_of_id TEXT NOT NULL;`, "ADD COLUMN ... NOT NULL without DEFAULT"},

		// #2658's migration 082 — nullable ADD COLUMN. The rule must pass it.
		{"add nullable column (#2658)", `ALTER TABLE payments ADD COLUMN refund_of_id TEXT;`, ""},
		{"add not null column WITH default", `ALTER TABLE payments ADD COLUMN tip_amount INTEGER NOT NULL DEFAULT 0;`, ""},
		{"add column without the COLUMN keyword", `ALTER TABLE payments ADD refund_of_id TEXT;`, ""},

		// A brand-new table may declare NOT NULL freely — the previous binary
		// never inserts into a table it does not know exists.
		{"create table with not null columns", `CREATE TABLE refunds (id TEXT PRIMARY KEY, amount INTEGER NOT NULL);`, ""},
		{"create index", `CREATE INDEX idx_payments_refund ON payments(refund_of_id);`, ""},

		// Comments must not produce findings. A migration whose PROSE explains
		// what it deliberately did not do is the common case here — migration
		// 027's header says "SQLite has no ... RENAME COLUMN-in-place workflow".
		{"line comment mentioning the constructs", "-- we did NOT use DROP COLUMN or RENAME COLUMN here\nCREATE TABLE t (id TEXT);", ""},
		{"block comment mentioning the constructs", "/* ALTER TABLE t DROP COLUMN c;\n   ALTER TABLE t RENAME TO u; */\nCREATE TABLE t (id TEXT);", ""},
		{"trailing comment after a safe statement", `ALTER TABLE payments ADD COLUMN note TEXT; -- not a DROP COLUMN`, ""},

		// String literals must not produce findings either.
		{"string literal mentioning the constructs", `INSERT INTO audit_log (detail) VALUES ('ALTER TABLE t DROP COLUMN c');`, ""},
		{"string literal with an escaped quote", `INSERT INTO audit_log (detail) VALUES ('it''s not a DROP COLUMN');`, ""},

		// ...but a real statement following a comment that mentions it still fires.
		{"comment then the real thing", "-- this one really does drop it\nALTER TABLE payments DROP COLUMN refund_of_id;", "DROP COLUMN"},

		// DROP TABLE — the table has to predate the file for it to fire.
		{"drop a table the file did not create", `DROP TABLE payments;`, "DROP TABLE"},
		{"drop if exists, still a pre-existing table", `DROP TABLE IF EXISTS payments;`, "DROP TABLE"},
		{"drop a schema-qualified pre-existing table", `DROP TABLE main.payments;`, "DROP TABLE"},
		{"drop a quoted pre-existing table", "DROP TABLE `payments`;", "DROP TABLE"},

		// ...and the shape 013_printer_roles.sql actually uses: stage a scratch
		// table, use it, drop it. Firing here is what would get the rule switched
		// off, so it is pinned as hard as the positive cases.
		{
			"create then drop a scratch table in the same file",
			"CREATE TEMP TABLE _legacy_printer_ips AS SELECT 1 AS role;\nUPDATE _legacy_printer_ips SET role = 2;\nDROP TABLE _legacy_printer_ips;",
			"",
		},
		{"create then drop, non-temp", "CREATE TABLE _scratch (id TEXT);\nDROP TABLE _scratch;", ""},
		{"create then drop, IF NOT EXISTS / IF EXISTS spellings", "CREATE TABLE IF NOT EXISTS _scratch (id TEXT);\nDROP TABLE IF EXISTS _scratch;", ""},
		{"create then drop, no space before the paren", "CREATE TABLE _scratch(id TEXT);\nDROP TABLE _scratch;", ""},
		{"create then drop, case and quoting differ", "CREATE TEMPORARY TABLE \"_Scratch\" (id TEXT);\ndrop table temp._scratch;", ""},

		// Order matters: dropping first and creating the same name afterwards is
		// the 055 shape, and it is NOT covered by the scratch-table allowance.
		{"drop then recreate the same name", "DROP TABLE IF EXISTS mirror;\nCREATE TABLE mirror (id TEXT);", "DROP TABLE"},

		// A rename does not make the target this file's to drop — it moved a
		// table that already held rows.
		{"rename then drop the renamed table", "ALTER TABLE payments RENAME TO payments_old;\nDROP TABLE payments_old;", "RENAME TO"},

		// Prose and literals, same as every other rule.
		{"comment mentioning DROP TABLE", "-- we do not DROP TABLE payments here\nCREATE TABLE t (id TEXT);", ""},
		{"string literal mentioning DROP TABLE", `INSERT INTO audit_log (detail) VALUES ('DROP TABLE payments');`, ""},

		// Neighbours that merely start with DROP/CREATE must stay quiet.
		{"drop index", `DROP INDEX idx_payments_refund;`, ""},
		{"drop trigger", `DROP TRIGGER trg_payments;`, ""},
		{"drop view", `DROP VIEW v_payments;`, ""},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			found := findDestructiveSQL("case.sql", tc.sql)

			switch {
			case tc.want == "" && len(found) > 0:
				t.Fatalf("no finding expected, got %s on %q", found[0].rule.name, condense(found[0].stmt))
			case tc.want != "" && len(found) == 0:
				t.Fatalf("expected a %s finding, got none", tc.want)
			case tc.want != "" && found[0].rule.name != tc.want:
				t.Fatalf("expected %s, got %s", tc.want, found[0].rule.name)
			}
		})
	}

	// dropTableRule lives outside destructiveRules because it needs file
	// context, and it carries no `fires`. Anyone who moves it into the list
	// would nil-panic the scanner instead of getting a review comment.
	for _, rule := range destructiveRules {
		if rule.fires == nil {
			t.Errorf("rule %q has no fires func — a stateful rule belongs next to dropTableRule, not in destructiveRules", rule.name)
		}
	}
}

// 013_printer_roles.sql is the reason DROP TABLE went unbanned in #2659: it
// stages the legacy printer IPs in a TEMP table and drops it eight statements
// later. Pinning the REAL file, not a paraphrase of it, is the point — a rule
// that cries wolf on a shipped migration gets switched off, and then none of
// the other rules are watching either.
func TestDropTableRuleStaysQuietOnTheScratchTableInMigration013(t *testing.T) {
	const name = "013_printer_roles.sql"

	content, err := localMigrationsFS.ReadFile("migrations/" + name)
	if err != nil {
		t.Fatalf("read %s: %v", name, err)
	}
	if !strings.Contains(string(content), "DROP TABLE _legacy_printer_ips") {
		t.Fatalf("%s no longer drops its scratch table — this test is pinning something that moved, re-point it or delete it", name)
	}

	for _, f := range findDestructiveSQL(name, string(content)) {
		t.Errorf("%s:%d — %s fired on a migration that only drops the scratch table it created itself: %s",
			f.file, f.line, f.rule.name, condense(f.stmt))
	}
}

// Both findings, in order, on the rebuild idiom the exception list covers — a
// single-finding assertion would hide the DROP TABLE behind the RENAME TO.
func TestDropTableAndRenameBothFireOnARebuildThatDoesNotOwnTheTable(t *testing.T) {
	sql := "ALTER TABLE payments RENAME TO payments_old;\nCREATE TABLE payments (id TEXT);\nDROP TABLE payments_old;\n"

	found := findDestructiveSQL("case.sql", sql)
	if len(found) != 2 {
		t.Fatalf("expected 2 findings, got %d: %+v", len(found), found)
	}
	if found[0].rule.name != "RENAME TO" || found[0].line != 1 {
		t.Errorf("expected RENAME TO at line 1, got %s at line %d", found[0].rule.name, found[0].line)
	}
	if found[1].rule.name != "DROP TABLE" || found[1].line != 3 {
		t.Errorf("expected DROP TABLE at line 3, got %s at line %d", found[1].rule.name, found[1].line)
	}
}

// The failure message has to name the table and the line, or the next person
// reads "DROP TABLE is banned" and has to go find which one.
func TestDropTableFindingReportsTheTableAndTheLine(t *testing.T) {
	sql := "-- header\n\nCREATE TABLE _scratch (id TEXT);\n\nDROP TABLE _scratch;\n\nDROP TABLE payments;\n"

	found := findDestructiveSQL("case.sql", sql)
	if len(found) != 1 {
		t.Fatalf("expected exactly one finding (only `payments` predates the file), got %d: %+v", len(found), found)
	}
	if found[0].line != 7 {
		t.Errorf("expected the DROP TABLE to be reported at line 7, got %d", found[0].line)
	}
	if !strings.Contains(condense(found[0].stmt), "payments") {
		t.Errorf("finding does not name the dropped table: %s", condense(found[0].stmt))
	}
}

// Line numbers are reported to the reader, so they have to be right — a gate
// that points at the wrong line sends the next person hunting.
func TestDestructiveFindingReportsTheOriginalLine(t *testing.T) {
	sql := "-- header\n-- header\n\nCREATE TABLE t (id TEXT);\n\nALTER TABLE t DROP COLUMN id;\n"

	found := findDestructiveSQL("case.sql", sql)
	if len(found) != 1 {
		t.Fatalf("expected exactly one finding, got %d", len(found))
	}
	if found[0].line != 6 {
		t.Errorf("expected the DROP COLUMN to be reported at line 6, got %d", found[0].line)
	}
}

// ---- scanning ---------------------------------------------------------------

type migrationSource struct {
	name    string
	content string
}

func scanMigrationsForDestructiveSQL(t *testing.T) []destructiveFinding {
	t.Helper()

	var findings []destructiveFinding
	for _, src := range migrationSources(t) {
		findings = append(findings, findDestructiveSQL(src.name, src.content)...)
	}

	return findings
}

func migrationSources(t *testing.T) []migrationSource {
	t.Helper()

	local := readSQLDir(t, localMigrationsFS, "migrations", "")
	if len(local) == 0 {
		t.Fatal("read zero hand-written migrations — the scan would pass vacuously, which is worse than no gate")
	}

	omnify := readSQLDir(t, os.DirFS(omnifyMigrationDir), ".", "omnify/")
	if len(omnify) == 0 {
		t.Fatalf("read zero omnify migrations from %s — they run through this same runner, so a scan that skips them is a hole, not a shortcut", omnifyMigrationDir)
	}

	return append(local, omnify...)
}

func readSQLDir(t *testing.T, fsys fs.FS, dir, namePrefix string) []migrationSource {
	t.Helper()

	entries, err := fs.ReadDir(fsys, dir)
	if err != nil {
		t.Fatalf("read migrations dir %s: %v", dir, err)
	}

	sort.Slice(entries, func(i, j int) bool { return entries[i].Name() < entries[j].Name() })

	var out []migrationSource
	for _, entry := range entries {
		if entry.IsDir() || !strings.HasSuffix(entry.Name(), ".sql") {
			continue
		}

		path := entry.Name()
		if dir != "." {
			path = dir + "/" + entry.Name()
		}

		content, err := fs.ReadFile(fsys, path)
		if err != nil {
			t.Fatalf("read migration %s: %v", path, err)
		}

		out = append(out, migrationSource{name: namePrefix + entry.Name(), content: string(content)})
	}

	return out
}

func findDestructiveSQL(name, content string) []destructiveFinding {
	var out []destructiveFinding

	// Reset per file: "created earlier" means earlier in THIS migration, not in
	// some migration that ran years ago on the same database.
	created := fileTables{}

	for _, stmt := range splitSQLStatements(content) {
		for _, rule := range destructiveRules {
			if rule.fires(stmt.text) {
				out = append(out, destructiveFinding{file: name, line: stmt.line, rule: rule, stmt: stmt.text})
			}
		}

		if dropped, ok := droppedTableName(stmt.text); ok && !created.has(dropped) {
			out = append(out, destructiveFinding{file: name, line: stmt.line, rule: dropTableRule, stmt: stmt.text})
		}

		created.note(stmt.text)
	}

	return out
}

type sqlStatement struct {
	text string
	line int // 1-based, in the ORIGINAL file
}

// splitSQLStatements blanks comments first, then splits on `;`.
//
// Order matters and the reason is paid for in migrate.go's execMigration: prose
// runs to a semicolon far more often than SQL does, so splitting first hands
// the caller fragments of English. Blanking (rather than deleting) keeps every
// byte offset identical to the original file, which is what lets the reported
// line numbers point at the real source line.
//
// No migration in this tree defines a trigger, so `;` is an unambiguous
// statement terminator here. A CREATE TRIGGER ... BEGIN ... END; block would
// need real tokenising; if one ever lands, this splitter reports its fragments
// separately, which errs toward noise rather than silence.
func splitSQLStatements(content string) []sqlStatement {
	blanked := blankSQLComments(content)

	var out []sqlStatement
	start := 0
	for i := 0; i <= len(blanked); i++ {
		if i != len(blanked) && blanked[i] != ';' {
			continue
		}

		raw := blanked[start:i]
		if trimmed := strings.TrimSpace(raw); trimmed != "" {
			offset := start + (len(raw) - len(strings.TrimLeft(raw, " \t\r\n")))
			out = append(out, sqlStatement{
				text: raw,
				line: 1 + strings.Count(content[:offset], "\n"),
			})
		}
		start = i + 1
	}

	return out
}

// blankSQLComments overwrites `--` line comments and `/* */` block comments
// with spaces, leaving newlines and total length intact. String literals and
// quoted identifiers are tracked so a `--` or `/*` inside one is not mistaken
// for a comment, and — the case this gate exists to survive — a comment that
// merely NAMES a banned construct produces no finding.
//
// This is deliberately stricter than migrate.go's stripSQLComments, which is
// line-based and does not understand quoting: that one only has to serve the
// handful of files carrying the +guard-add-column marker, whereas this reads
// every migration in the tree, prose and all.
func blankSQLComments(s string) string {
	const (
		normal = iota
		inString
		inQuotedIdent
		inLineComment
		inBlockComment
	)

	out := []byte(s)
	blank := func(i int) {
		if out[i] != '\n' {
			out[i] = ' '
		}
	}

	state := normal
	var identQuote byte

	for i := 0; i < len(out); i++ {
		c := out[i]

		switch state {
		case normal:
			switch {
			case c == '\'':
				state = inString
			case c == '"' || c == '`':
				state, identQuote = inQuotedIdent, c
			case c == '-' && i+1 < len(out) && out[i+1] == '-':
				state = inLineComment
				blank(i)
				blank(i + 1)
				i++
			case c == '/' && i+1 < len(out) && out[i+1] == '*':
				state = inBlockComment
				blank(i)
				blank(i + 1)
				i++
			}
		case inString:
			// A doubled '' closes and immediately reopens the literal, which
			// lands in the same place as treating it as an escape.
			if c == '\'' {
				state = normal
			}
		case inQuotedIdent:
			if c == identQuote {
				state = normal
			}
		case inLineComment:
			if c == '\n' {
				state = normal
			} else {
				blank(i)
			}
		case inBlockComment:
			if c == '*' && i+1 < len(out) && out[i+1] == '/' {
				blank(i)
				blank(i + 1)
				i++
				state = normal
			} else {
				blank(i)
			}
		}
	}

	return string(out)
}

// condense flattens a statement onto one line so the failure message stays
// readable when the offender is a 40-line table rebuild.
func condense(stmt string) string {
	flat := strings.Join(strings.Fields(stmt), " ")
	if len(flat) > 160 {
		flat = flat[:160] + " …"
	}

	return fmt.Sprintf("%q", flat)
}
