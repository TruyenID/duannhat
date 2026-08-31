package store

import (
	"embed"
	"fmt"
	"io/fs"
	"log/slog"
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

	// Idempotent schema repair for DBs that pre-date aa436c3's
	// migration-cleanup commit. See repair.go for the rationale.
	if err := db.repairLegacySchema(); err != nil {
		return fmt.Errorf("repair legacy schema: %w", err)
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

		if _, err := tx.Exec(string(content)); err != nil {
			tx.Rollback()
			return fmt.Errorf("execute migration %s: %w", name, err)
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
