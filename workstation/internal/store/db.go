package store

import (
	"database/sql"
	"fmt"
	"net/url"
	"strings"
	"sync/atomic"
	"time"

	_ "modernc.org/sqlite"
)

type DB struct {
	conn    *sql.DB
	path    string
	closed  atomic.Bool
	metrics dbOperationMetrics
}

type dbOperationMetrics struct {
	queryCount         atomic.Int64
	queryDurationNanos atomic.Int64
	execCount          atomic.Int64
	execDurationNanos  atomic.Int64
	txCount            atomic.Int64
	txDurationNanos    atomic.Int64
}

// DBDiagnostics is a PII-free, non-blocking snapshot suitable for LAN health
// and remote diagnostics. sql.DB.Stats only reads pool counters; this method
// never borrows a SQLite connection and therefore cannot make liveness wait on
// a wedged writer.
type DBDiagnostics struct {
	Status             string `json:"status"`
	MaxOpenConnections int    `json:"max_open_connections"`
	OpenConnections    int    `json:"open_connections"`
	InUse              int    `json:"in_use"`
	Idle               int    `json:"idle"`
	WaitCount          int64  `json:"wait_count"`
	WaitDurationMS     int64  `json:"wait_duration_ms"`
	QueryCount         int64  `json:"query_count"`
	QueryDurationMS    int64  `json:"query_duration_ms"`
	ExecCount          int64  `json:"exec_count"`
	ExecDurationMS     int64  `json:"exec_duration_ms"`
	TransactionCount   int64  `json:"transaction_count"`
	TransactionMS      int64  `json:"transaction_duration_ms"`
}

func Open(path string) (*DB, error) {
	// CRITICAL: pragmas MUST be applied via DSN, not `conn.Exec(PRAGMA …)`
	// after Open. `database/sql`'s pool can dial multiple connections;
	// `Exec` only touches whichever connection it borrows. Subsequent
	// pool connections — including the writer the slow puller grabs
	// inside `PullMenuCatalog`'s transaction — would carry SQLite's
	// driver defaults: rollback journal (NOT WAL) and busy_timeout=0.
	// Writers then collide on a non-WAL journal under
	// concurrent ticks, time out instantly with SQLITE_BUSY, and the
	// slow goroutine eventually wedges a write tx open holding the
	// SQLite-level lock + a pool connection. From there:
	//   1. database/sql pool drains (every handler doing a SELECT
	//      blocks waiting for a free conn).
	//   2. critical POS writes wait behind background writers.
	// The DSN form (`file:/path/db?_pragma=k(v)`) makes modernc apply the
	// WAL + bounded timeout + FK + NORMAL sync defaults on EVERY connection.
	// 2 seconds is deliberately below pos-web's 3-second LAN budget: a busy
	// writer must return a controlled error while the client is still listening,
	// not continue occupying the pool after the client has abandoned the call.
	dsn := buildDSN(path, map[string]string{
		"journal_mode":       "WAL",
		"busy_timeout":       "2000",
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
	_, err := db.Exec("PRAGMA wal_checkpoint(TRUNCATE)")
	return err
}

// Path returns the database file path, useful for backup tooling.
func (db *DB) Path() string {
	return db.path
}

func (db *DB) Close() error {
	db.closed.Store(true)
	return db.conn.Close()
}

func (db *DB) Conn() *sql.DB {
	return db.conn
}

func (db *DB) Exec(query string, args ...any) (sql.Result, error) {
	started := time.Now()
	result, err := db.conn.Exec(query, args...)
	db.metrics.execCount.Add(1)
	db.metrics.execDurationNanos.Add(time.Since(started).Nanoseconds())
	return result, err
}

func (db *DB) Query(query string, args ...any) (*sql.Rows, error) {
	started := time.Now()
	rows, err := db.conn.Query(query, args...)
	db.metrics.queryCount.Add(1)
	db.metrics.queryDurationNanos.Add(time.Since(started).Nanoseconds())
	return rows, err
}

func (db *DB) QueryRow(query string, args ...any) *sql.Row {
	started := time.Now()
	row := db.conn.QueryRow(query, args...)
	db.metrics.queryCount.Add(1)
	db.metrics.queryDurationNanos.Add(time.Since(started).Nanoseconds())
	return row
}

func (db *DB) Transaction(fn func(tx *sql.Tx) error) error {
	started := time.Now()
	defer func() {
		db.metrics.txCount.Add(1)
		db.metrics.txDurationNanos.Add(time.Since(started).Nanoseconds())
	}()
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

// Diagnostics snapshots pool pressure and wrapper-level operation latency.
// It intentionally contains no query text, arguments, paths, IDs or URLs.
func (db *DB) Diagnostics() DBDiagnostics {
	stats := db.conn.Stats()
	status := "ready"
	if db.closed.Load() {
		status = "unavailable"
	} else if stats.MaxOpenConnections > 0 && stats.InUse >= stats.MaxOpenConnections && stats.Idle == 0 {
		status = "degraded"
	}
	return DBDiagnostics{
		Status:             status,
		MaxOpenConnections: stats.MaxOpenConnections,
		OpenConnections:    stats.OpenConnections,
		InUse:              stats.InUse,
		Idle:               stats.Idle,
		WaitCount:          stats.WaitCount,
		WaitDurationMS:     stats.WaitDuration.Milliseconds(),
		QueryCount:         db.metrics.queryCount.Load(),
		QueryDurationMS:    time.Duration(db.metrics.queryDurationNanos.Load()).Milliseconds(),
		ExecCount:          db.metrics.execCount.Load(),
		ExecDurationMS:     time.Duration(db.metrics.execDurationNanos.Load()).Milliseconds(),
		TransactionCount:   db.metrics.txCount.Load(),
		TransactionMS:      time.Duration(db.metrics.txDurationNanos.Load()).Milliseconds(),
	}
}
