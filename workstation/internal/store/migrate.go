package store

import (
	"database/sql"
	"embed"
	"fmt"
	"io/fs"
	"log/slog"
	"regexp"
	"sort"
	"strings"
)

// Hand-written migrations (internal/store/migrations/)
//
//go:embed migrations/*.sql
var localMigrationsFS embed.FS

// OmnifyMigrations is set from the root-level embed (migrations.go).
// Passed in via Open() so omnify-generated migrations run after hand-written ones.
var OmnifyMigrations *embed.FS

func (db *DB) migrate() error {
	_, err := db.conn.Exec(`
		CREATE TABLE IF NOT EXISTS schema_migrations (
			version  INTEGER PRIMARY KEY,
			name     TEXT NOT NULL,
			applied_at TEXT NOT NULL DEFAULT (datetime('now'))
		)
	`)
	if err != nil {
		return fmt.Errorf("create migrations table: %w", err)
	}

	// A brand-new database applies the whole set in ONE transaction (#1186).
	// Per-migration commits cost an fsync each, and at 75 migrations that made
	// every fresh Open ~1.8s — which is why the test suite, where each case
	// builds its own SQLite file, ran 560-710s against Go's 600s default
	// timeout. Batching is also the safer shape here: a fresh DB that fails
	// half-way is now discarded whole rather than left partly built.
	//
	// An EXISTING database keeps the per-migration transaction: an upgrade
	// that fails on migration 71 must keep the 70 that already applied, and
	// the fsync cost is paid once per release, not once per test.
	fresh, err := db.isFreshDatabase()
	if err != nil {
		return err
	}

	if fresh {
		tx, err := db.conn.Begin()
		if err != nil {
			return fmt.Errorf("begin fresh migration: %w", err)
		}

		if err := db.applyMigrations(tx, localMigrationsFS, "migrations", 0); err != nil {
			tx.Rollback()

			return fmt.Errorf("local migrations: %w", err)
		}

		if OmnifyMigrations != nil {
			if err := db.applyMigrations(tx, *OmnifyMigrations, "migrations/omnify", 1000); err != nil {
				tx.Rollback()

				return fmt.Errorf("omnify migrations: %w", err)
			}
		}

		if err := tx.Commit(); err != nil {
			return fmt.Errorf("commit fresh migration: %w", err)
		}
	} else {
		// Run hand-written migrations first (001-999)
		if err := db.runMigrations(localMigrationsFS, "migrations"); err != nil {
			return fmt.Errorf("local migrations: %w", err)
		}

		// Run omnify-generated migrations (1000+) — offset version to avoid collision
		if OmnifyMigrations != nil {
			if err := db.runMigrationsWithOffset(*OmnifyMigrations, "migrations/omnify", 1000); err != nil {
				return fmt.Errorf("omnify migrations: %w", err)
			}
		}
	}

	// Idempotent schema repair for DBs that pre-date aa436c3's
	// migration-cleanup commit. See repair.go for the rationale.
	if err := db.repairLegacySchema(); err != nil {
		return fmt.Errorf("repair legacy schema: %w", err)
	}

	return nil
}

// isFreshDatabase reports whether no migration has ever been recorded, i.e.
// this file was created by the Open call currently in flight.
func (db *DB) isFreshDatabase() (bool, error) {
	var count int
	if err := db.conn.QueryRow("SELECT COUNT(*) FROM schema_migrations").Scan(&count); err != nil {
		return false, fmt.Errorf("count applied migrations: %w", err)
	}

	return count == 0, nil
}

// guardAddColumnMarker opts a migration file into statement-by-statement
// execution where every `ALTER TABLE t ADD COLUMN c` is skipped if `c` is
// already on `t`.
//
// SQLite has no `ADD COLUMN IF NOT EXISTS`, which is a problem for exactly one
// situation: a column that repairLegacySchema() may already have added behind
// the migration's back. Re-running such a migration on a repaired database
// would abort the boot with "duplicate column name" (#1196).
//
// It is OPT-IN, per file, and deliberately not the default. Guarding every
// migration would (a) require splitting SQL the runner has no business
// parsing — three files here rebuild tables inside BEGIN blocks where naive
// splitting on `;` is wrong — and (b) silence a genuine mistake, since an
// unexpected pre-existing column is normally worth crashing over.
const guardAddColumnMarker = "-- +guard-add-column"

var addColumnRe = regexp.MustCompile(`(?is)^\s*ALTER\s+TABLE\s+["'` + "`" + `]?(\w+)["'` + "`" + `]?\s+ADD\s+COLUMN\s+["'` + "`" + `]?(\w+)`)

// execMigration runs one migration file's SQL inside tx.
//
// Without the marker this is a single Exec — byte-for-byte the historical
// behaviour, so no existing migration changes meaning.
func execMigration(tx *sql.Tx, name, content string) error {
	if !strings.Contains(content, guardAddColumnMarker) {
		if _, err := tx.Exec(content); err != nil {
			return fmt.Errorf("execute migration %s: %w", name, err)
		}

		return nil
	}

	// Comments are stripped BEFORE splitting, not after: prose runs to a
	// semicolon far more often than SQL does. This very file's header contains
	// "round/ceil/floor; legacy half_up..." — splitting first hands the runner
	// a fragment starting at "legacy" and the migration dies on a syntax error
	// in a COMMENT.
	for _, stmt := range strings.Split(stripSQLComments(content), ";") {
		if strings.TrimSpace(stmt) == "" {
			continue
		}

		if m := addColumnRe.FindStringSubmatch(stmt); m != nil {
			exists, err := columnExistsTx(tx, m[1], m[2])
			if err != nil {
				return fmt.Errorf("inspect %s.%s for migration %s: %w", m[1], m[2], name, err)
			}
			if exists {
				slog.Info("migration column already present — skipped",
					"name", name, "table", m[1], "column", m[2])

				continue
			}
		}

		if _, err := tx.Exec(stmt); err != nil {
			return fmt.Errorf("execute migration %s: %w", name, err)
		}
	}

	return nil
}

// stripSQLComments removes `--` line comments.
//
// It does not understand string literals, so an opted-in migration must not
// contain `--` inside a quoted value. That is a cheap constraint on the few
// files that carry the marker, and far cheaper than teaching the runner to
// tokenise SQL.
func stripSQLComments(stmt string) string {
	var b strings.Builder
	for _, line := range strings.Split(stmt, "\n") {
		if idx := strings.Index(line, "--"); idx >= 0 {
			line = line[:idx]
		}
		b.WriteString(line)
		b.WriteString("\n")
	}

	return b.String()
}

func columnExistsTx(tx *sql.Tx, table, column string) (bool, error) {
	rows, err := tx.Query(fmt.Sprintf("PRAGMA table_info(%q)", table))
	if err != nil {
		return false, err
	}
	defer rows.Close()

	for rows.Next() {
		var (
			cid       int
			name      string
			typ       string
			notNull   int
			dfltValue sql.NullString
			pk        int
		)
		if err := rows.Scan(&cid, &name, &typ, &notNull, &dfltValue, &pk); err != nil {
			return false, err
		}
		if strings.EqualFold(name, column) {
			return true, rows.Err()
		}
	}

	return false, rows.Err()
}

// applyMigrations runs every migration in a directory inside the caller's
// transaction, unconditionally — only a fresh database uses this path, so
// nothing can have been applied already.
func (db *DB) applyMigrations(tx *sql.Tx, fsys embed.FS, dir string, versionOffset int) error {
	entries, err := fs.ReadDir(fsys, dir)
	if err != nil {
		return fmt.Errorf("read migrations dir %s: %w", dir, err)
	}

	sort.Slice(entries, func(i, j int) bool {
		return entries[i].Name() < entries[j].Name()
	})

	// Two files sharing a version number silently loses the second one: the
	// incremental runner sees the version already recorded and skips it, so
	// this path must behave identically rather than let a batch run apply a
	// migration an incremental run would not. The tree had exactly one such
	// pair (both 040) and #1196 renumbered it; TestMigrationVersionsAreUnique
	// now fails the build if another appears, so this is a backstop, not a
	// tolerated state.
	seen := map[int]bool{}

	for _, entry := range entries {
		if entry.IsDir() || !strings.HasSuffix(entry.Name(), ".sql") {
			continue
		}

		var version int
		fmt.Sscanf(entry.Name(), "%d_", &version)
		if version == 0 {
			continue
		}
		version += versionOffset

		if seen[version] {
			slog.Warn("migration version shadowed by an earlier file — skipped", "version", version, "name", entry.Name())

			continue
		}
		seen[version] = true

		content, err := fs.ReadFile(fsys, dir+"/"+entry.Name())
		if err != nil {
			return fmt.Errorf("read migration %s: %w", entry.Name(), err)
		}

		name := entry.Name()
		if versionOffset > 0 {
			name = fmt.Sprintf("omnify/%s", name)
		}

		if err := execMigration(tx, name, string(content)); err != nil {
			return err
		}

		if _, err := tx.Exec(
			"INSERT INTO schema_migrations (version, name) VALUES (?, ?)",
			version, name,
		); err != nil {
			return fmt.Errorf("record migration %d: %w", version, err)
		}
	}

	return nil
}

func (db *DB) runMigrations(fsys embed.FS, dir string) error {
	return db.runMigrationsWithOffset(fsys, dir, 0)
}

func (db *DB) runMigrationsWithOffset(fsys embed.FS, dir string, versionOffset int) error {
	entries, err := fs.ReadDir(fsys, dir)
	if err != nil {
		return fmt.Errorf("read migrations dir %s: %w", dir, err)
	}

	sort.Slice(entries, func(i, j int) bool {
		return entries[i].Name() < entries[j].Name()
	})

	for _, entry := range entries {
		if entry.IsDir() || !strings.HasSuffix(entry.Name(), ".sql") {
			continue
		}

		var version int
		fmt.Sscanf(entry.Name(), "%d_", &version)
		if version == 0 {
			continue
		}
		version += versionOffset

		var count int
		err := db.conn.QueryRow("SELECT COUNT(*) FROM schema_migrations WHERE version = ?", version).Scan(&count)
		if err != nil {
			return fmt.Errorf("check migration %d: %w", version, err)
		}
		if count > 0 {
			continue
		}

		content, err := fs.ReadFile(fsys, dir+"/"+entry.Name())
		if err != nil {
			return fmt.Errorf("read migration %s: %w", entry.Name(), err)
		}

		name := entry.Name()
		if versionOffset > 0 {
			name = fmt.Sprintf("omnify/%s", name)
		}

		slog.Info("applying migration", "version", version, "name", name)

		tx, err := db.conn.Begin()
		if err != nil {
			return fmt.Errorf("begin migration %d: %w", version, err)
		}

		if err := execMigration(tx, name, string(content)); err != nil {
			tx.Rollback()

			return err
		}

		if _, err := tx.Exec(
			"INSERT INTO schema_migrations (version, name) VALUES (?, ?)",
			version, name,
		); err != nil {
			tx.Rollback()
			return fmt.Errorf("record migration %d: %w", version, err)
		}

		if err := tx.Commit(); err != nil {
			return fmt.Errorf("commit migration %d: %w", version, err)
		}

		slog.Info("migration applied", "version", version)
	}

	return nil
}
