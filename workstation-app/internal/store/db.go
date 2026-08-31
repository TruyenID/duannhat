package store

import (
	"database/sql"
	"fmt"
	"net/url"
	"strings"

	_ "modernc.org/sqlite"
)

type DB struct {
	conn *sql.DB
	path string
}

func Open(path string) (*DB, error) {
	// CRITICAL: pragmas MUST be applied via DSN, not `conn.Exec(PRAGMA …)`
	// after Open. `database/sql`'s pool can dial multiple connections;
	// `Exec` only touches whichever connection it borrows. Subsequent
	// pool connections — including the writer the slow puller grabs
	// inside `PullMenuCatalog`'s transaction — would carry SQLite's
	// driver defaults: rollback journal (NOT WAL) and busy_timeout=0
	// (NOT 15s). Writers then collide on a non-WAL journal under
	// concurrent ticks, time out instantly with SQLITE_BUSY, and the
	// slow goroutine eventually wedges a write tx open holding the
	// SQLite-level lock + a pool connection. From there:
	//   1. database/sql pool drains (every handler doing a SELECT
	//      blocks waiting for a free conn).
	//   2. /api/lan/health blocks on workstationBranchID()'s
	//      `SELECT value FROM settings` — that's what makes pos-web's
	//      30-second health probe time out, surfacing "disconnected".
	// The fix is the DSN form: `file:/path/db?_pragma=k(v)` — modernc's
	// driver applies each `_pragma` param on EVERY connection it dials,
	// so the WAL + 15s timeout + FK + NORMAL sync defaults follow the
	// connection wherever in the pool it lands.
	dsn := buildDSN(path, map[string]string{
		"journal_mode":       "WAL",
		"busy_timeout":       "15000",
		"foreign_keys":       "ON",
		"synchronous":        "NORMAL",
		"wal_autocheckpoint": "1000",
	})
	conn, err := sql.Open("sqlite", dsn)
	if err != nil {
		return nil, fmt.Errorf("open database: %w", err)
	}

	// SQLite handles 1 writer + N readers. With WAL mode N readers don't
	// block the writer, but >1 concurrent writer causes SQLITE_BUSY. We cap
	// the pool so retry happens at app layer, not driver layer.
	conn.SetMaxOpenConns(8)
	conn.SetMaxIdleConns(4)
	conn.SetConnMaxLifetime(0)

	// Sanity probe: round-trip a SELECT so any pragma misconfig surfaces
	// at boot rather than inside the first slow-loop tick.
	if _, err := conn.Exec("SELECT 1"); err != nil {
		conn.Close()
		return nil, fmt.Errorf("sanity probe: %w", err)
	}

	db := &DB{conn: conn, path: path}

	if err := db.migrate(); err != nil {
		conn.Close()
		return nil, fmt.Errorf("run migrations: %w", err)
	}

	return db, nil
}

// buildDSN assembles a modernc.org/sqlite DSN with `_pragma=K(V)` params.
// Sorted by key so test snapshots are stable.
func buildDSN(path string, pragmas map[string]string) string {
	// Path may already be a `file:` URI from callers; preserve as-is.
	prefix := "file:" + path
	if strings.HasPrefix(path, "file:") {
		prefix = path
	}
	parts := []string{}
	for k, v := range pragmas {
		parts = append(parts, "_pragma="+url.QueryEscape(fmt.Sprintf("%s(%s)", k, v)))
	}
	if len(parts) == 0 {
		return prefix
	}
	sep := "?"
	if strings.Contains(prefix, "?") {
		sep = "&"
	}
	return prefix + sep + strings.Join(parts, "&")
}

// Checkpoint forces a WAL → main DB merge and truncates the WAL file.
// Call periodically (eg. hourly) to bound WAL file size.
func (db *DB) Checkpoint() error {
	_, err := db.conn.Exec("PRAGMA wal_checkpoint(TRUNCATE)")
	return err
}

// Path returns the database file path, useful for backup tooling.
func (db *DB) Path() string {
	return db.path
}

func (db *DB) Close() error {
	return db.conn.Close()
}

func (db *DB) Conn() *sql.DB {
	return db.conn
}

func (db *DB) Exec(query string, args ...any) (sql.Result, error) {
	return db.conn.Exec(query, args...)
}

func (db *DB) Query(query string, args ...any) (*sql.Rows, error) {
	return db.conn.Query(query, args...)
}

func (db *DB) QueryRow(query string, args ...any) *sql.Row {
	return db.conn.QueryRow(query, args...)
}

func (db *DB) Transaction(fn func(tx *sql.Tx) error) error {
	tx, err := db.conn.Begin()
	if err != nil {
		return err
	}
	if err := fn(tx); err != nil {
		tx.Rollback()
		return err
	}
	return tx.Commit()
}
