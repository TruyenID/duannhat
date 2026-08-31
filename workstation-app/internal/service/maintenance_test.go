package service

import (
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

func TestMaintenance_CheckpointShrinksWAL(t *testing.T) {
	dir := t.TempDir()
	dbPath := filepath.Join(dir, "test.db")

	db, err := store.Open(dbPath)
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	defer db.Close()

	if _, err := db.Exec(`CREATE TABLE t (id INTEGER PRIMARY KEY, blob TEXT)`); err != nil {
		t.Fatalf("create table: %v", err)
	}
	for i := 0; i < 5000; i++ {
		if _, err := db.Exec(`INSERT INTO t (blob) VALUES (?)`, string(make([]byte, 256))); err != nil {
			t.Fatalf("insert: %v", err)
		}
	}

	walBefore := walSize(t, dbPath)
	if walBefore == 0 {
		t.Skip("WAL file not created — driver may differ; skip")
	}

	m := NewMaintenance(db, MaintenanceConfig{})
	if err := m.CheckpointOnce(); err != nil {
		t.Fatalf("checkpoint: %v", err)
	}

	walAfter := walSize(t, dbPath)
	if walAfter >= walBefore {
		t.Fatalf("WAL did not shrink: before=%d after=%d", walBefore, walAfter)
	}
}

func walSize(t *testing.T, dbPath string) int64 {
	t.Helper()
	fi, err := os.Stat(dbPath + "-wal")
	if err != nil {
		if os.IsNotExist(err) {
			return 0
		}
		t.Fatalf("stat wal: %v", err)
	}
	return fi.Size()
}

func TestMaintenance_PurgeSyncQueueKeepsUnsynced(t *testing.T) {
	dir := t.TempDir()
	db, err := store.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	_, _ = db.Exec(`INSERT INTO sync_queue (entity_type, entity_id, operation, payload, idempotency_key, synced_at)
		VALUES ('order', 'a', 'create', '{}', 'k1', datetime('now', '-10 days'))`)
	_, _ = db.Exec(`INSERT INTO sync_queue (entity_type, entity_id, operation, payload, idempotency_key, synced_at)
		VALUES ('order', 'b', 'create', '{}', 'k2', datetime('now', '-1 day'))`)
	_, _ = db.Exec(`INSERT INTO sync_queue (entity_type, entity_id, operation, payload, idempotency_key, synced_at)
		VALUES ('order', 'c', 'create', '{}', 'k3', NULL)`)

	m := NewMaintenance(db, MaintenanceConfig{SyncQueueKeep: 7 * 24 * time.Hour})
	n, err := m.PurgeSyncQueueOnce()
	if err != nil {
		t.Fatalf("purge: %v", err)
	}
	if n != 1 {
		t.Fatalf("expected 1 row purged, got %d", n)
	}

	var remaining int
	_ = db.QueryRow(`SELECT COUNT(*) FROM sync_queue`).Scan(&remaining)
	if remaining != 2 {
		t.Fatalf("expected 2 rows left, got %d", remaining)
	}
}

func TestMaintenance_PurgeAuditOnce(t *testing.T) {
	dir := t.TempDir()
	db, err := store.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	_, _ = db.Exec(`INSERT INTO audit_log (timestamp, actor, action) VALUES (datetime('now', '-100 days'), 'system', 'old.event')`)
	_, _ = db.Exec(`INSERT INTO audit_log (timestamp, actor, action) VALUES (datetime('now', '-30 days'), 'system', 'recent.event')`)

	m := NewMaintenance(db, MaintenanceConfig{AuditKeep: 90 * 24 * time.Hour})
	n, err := m.PurgeAuditOnce()
	if err != nil {
		t.Fatalf("purge: %v", err)
	}
	if n != 1 {
		t.Fatalf("expected 1 row purged, got %d", n)
	}
}

func TestMaintenance_PurgeAuditBoundaryDate(t *testing.T) {
	dir := t.TempDir()
	db, err := store.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	// Two rows with timestamps THAT CROSS THE CUTOFF on the same day:
	// - one row 1 minute BEFORE cutoff → should be deleted
	// - one row 1 minute AFTER  cutoff → should be kept
	// Inserted using SQLite's default datetime() format (space separator).
	_, _ = db.Exec(`INSERT INTO audit_log (timestamp, actor, action)
		VALUES (datetime('now', '-90 days', '-2 minutes'), 'system', 'just_before')`)
	_, _ = db.Exec(`INSERT INTO audit_log (timestamp, actor, action)
		VALUES (datetime('now', '-90 days', '+2 minutes'), 'system', 'just_after')`)

	m := NewMaintenance(db, MaintenanceConfig{AuditKeep: 90 * 24 * time.Hour})
	n, err := m.PurgeAuditOnce()
	if err != nil {
		t.Fatalf("purge: %v", err)
	}
	if n != 1 {
		t.Fatalf("expected exactly 1 row purged, got %d", n)
	}

	var remainingAction string
	if err := db.QueryRow(`SELECT action FROM audit_log`).Scan(&remainingAction); err != nil {
		t.Fatalf("scan: %v", err)
	}
	if remainingAction != "just_after" {
		t.Fatalf("expected 'just_after' to survive, got %q", remainingAction)
	}
}

func TestMaintenance_SnapshotCreatesFile(t *testing.T) {
	dir := t.TempDir()
	db, err := store.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	_, _ = db.Exec(`INSERT INTO settings (key, value) VALUES ('k', 'v')`)

	backupDir := filepath.Join(dir, "backups")
	m := NewMaintenance(db, MaintenanceConfig{BackupDir: backupDir, BackupKeep: 3})

	path, err := m.SnapshotOnce()
	if err != nil {
		t.Fatalf("snapshot: %v", err)
	}
	if fi, err := os.Stat(path); err != nil || fi.Size() == 0 {
		t.Fatalf("snapshot file missing or empty: %v", err)
	}
}

func TestMaintenance_SnapshotRotatesOldFiles(t *testing.T) {
	dir := t.TempDir()
	db, err := store.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	backupDir := filepath.Join(dir, "backups")
	m := NewMaintenance(db, MaintenanceConfig{BackupDir: backupDir, BackupKeep: 2})

	for i := 0; i < 5; i++ {
		if _, err := m.SnapshotOnce(); err != nil {
			t.Fatalf("snapshot %d: %v", i, err)
		}
		// VACUUM INTO refuses to overwrite existing files; ensure distinct
		// filenames AND distinct mtimes for sort-by-mtime in rotateBackups.
		time.Sleep(1100 * time.Millisecond)
	}

	entries, _ := os.ReadDir(backupDir)
	if len(entries) != 2 {
		t.Fatalf("expected 2 backups after rotation, got %d", len(entries))
	}
}

func TestMaintenance_SnapshotNoopWhenDirEmpty(t *testing.T) {
	dir := t.TempDir()
	db, err := store.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	m := NewMaintenance(db, MaintenanceConfig{}) // BackupDir == ""
	path, err := m.SnapshotOnce()
	if err != nil {
		t.Fatalf("expected no error when BackupDir empty, got %v", err)
	}
	if path != "" {
		t.Fatalf("expected empty path, got %q", path)
	}
}
