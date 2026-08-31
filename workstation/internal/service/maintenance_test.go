package service

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

func TestMaintenance_CheckpointShrinksWAL(t *testing.T) {
	dir := t.TempDir()
	dbPath := filepath.Join(dir, "test.db")

	db, err := storetest.Open(dbPath)
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
	db, err := storetest.Open(filepath.Join(dir, "test.db"))
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
	db, err := storetest.Open(filepath.Join(dir, "test.db"))
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
	db, err := storetest.Open(filepath.Join(dir, "test.db"))
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
	db, err := storetest.Open(filepath.Join(dir, "test.db"))
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
	db, err := storetest.Open(filepath.Join(dir, "test.db"))
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
	db, err := storetest.Open(filepath.Join(dir, "test.db"))
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

// --- pre-update snapshots (#2659) -------------------------------------------

func openBackupTestDB(t *testing.T) (*store.DB, string) {
	t.Helper()
	dir := t.TempDir()
	db, err := storetest.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return db, filepath.Join(dir, "backups")
}

func namesWithPrefix(t *testing.T, dir, prefix string) []string {
	t.Helper()
	entries, err := os.ReadDir(dir)
	if err != nil {
		t.Fatalf("read backup dir: %v", err)
	}
	var out []string
	for _, e := range entries {
		if strings.HasPrefix(e.Name(), prefix) {
			out = append(out, e.Name())
		}
	}
	sort.Strings(out)
	return out
}

func TestMaintenance_PreUpdateSnapshotWritesItsOwnPrefix(t *testing.T) {
	db, backupDir := openBackupTestDB(t)
	m := NewMaintenance(db, MaintenanceConfig{BackupDir: backupDir, BackupKeep: 7})

	path, err := m.PreUpdateSnapshot("v0.3.0")
	if err != nil {
		t.Fatalf("pre-update snapshot: %v", err)
	}
	if fi, err := os.Stat(path); err != nil || fi.Size() == 0 {
		t.Fatalf("pre-update copy missing or empty: %v", err)
	}
	name := filepath.Base(path)
	if !strings.HasPrefix(name, "pre-update-") {
		t.Fatalf("filename %q must carry the pre-update- prefix", name)
	}
	if strings.HasPrefix(name, "snapshot-") {
		t.Fatalf("filename %q shares the rotated periodic prefix", name)
	}
	if !strings.Contains(name, "v0.3.0") {
		t.Fatalf("filename %q must name the version being installed", name)
	}
}

// The whole point of the separate prefix: the 6-hourly job must not be able to
// delete the copy an install depends on. Sharing one prefix with BackupKeep=1
// would evict it on the very next tick.
func TestMaintenance_PeriodicRotationNeverEvictsPreUpdateCopies(t *testing.T) {
	db, backupDir := openBackupTestDB(t)
	m := NewMaintenance(db, MaintenanceConfig{BackupDir: backupDir, BackupKeep: 1})

	pre, err := m.PreUpdateSnapshot("v0.3.0")
	if err != nil {
		t.Fatalf("pre-update snapshot: %v", err)
	}

	for i := 0; i < 3; i++ {
		if _, err := m.SnapshotOnce(); err != nil {
			t.Fatalf("periodic snapshot %d: %v", i, err)
		}
		// VACUUM INTO refuses to overwrite; the periodic name is second-grained.
		time.Sleep(1100 * time.Millisecond)
	}

	if _, err := os.Stat(pre); err != nil {
		t.Fatalf("pre-update copy was rotated away by the periodic job: %v", err)
	}
	if got := namesWithPrefix(t, backupDir, "snapshot-"); len(got) != 1 {
		t.Fatalf("periodic family should be rotated to 1, got %d: %v", len(got), got)
	}
}

// Bounded, not unlimited: each copy is a whole database.
func TestMaintenance_PreUpdateSnapshotsAreRotatedToThree(t *testing.T) {
	db, backupDir := openBackupTestDB(t)
	m := NewMaintenance(db, MaintenanceConfig{BackupDir: backupDir, BackupKeep: 7})

	for i := 0; i < 5; i++ {
		if _, err := m.PreUpdateSnapshot(fmt.Sprintf("v0.3.%d", i)); err != nil {
			t.Fatalf("pre-update snapshot %d: %v", i, err)
		}
		time.Sleep(5 * time.Millisecond) // distinct mtimes for the sort
	}

	got := namesWithPrefix(t, backupDir, "pre-update-")
	if len(got) != 3 {
		t.Fatalf("expected 3 pre-update copies after rotation, got %d: %v", len(got), got)
	}
	// The three that survive must be the NEWEST three — keeping the oldest
	// would be worse than keeping none, since it is the one that no longer
	// matches any schema anybody is rolling back to.
	for _, want := range []string{"v0.3.2", "v0.3.3", "v0.3.4"} {
		found := false
		for _, n := range got {
			if strings.Contains(n, want) {
				found = true
			}
		}
		if !found {
			t.Fatalf("%s missing from surviving copies %v", want, got)
		}
	}
}

// VACUUM INTO needs room for a second copy of the database. Out of disk must be
// reported BEFORE the install, and no half-written file may be left behind.
func TestMaintenance_PreUpdateSnapshotRefusesWithoutFreeSpace(t *testing.T) {
	db, backupDir := openBackupTestDB(t)
	m := NewMaintenance(db, MaintenanceConfig{BackupDir: backupDir})
	m.SetDiskFreeFunc(func(string) (uint64, error) { return 4096, nil })

	path, err := m.PreUpdateSnapshot("v0.3.0")
	if err == nil {
		t.Fatalf("expected a disk-space error, got path %q", path)
	}
	if !strings.Contains(err.Error(), "disk space") {
		t.Fatalf("error must name the cause plainly, got: %v", err)
	}
	if got := namesWithPrefix(t, backupDir, "pre-update-"); len(got) != 0 {
		t.Fatalf("no copy may be left behind after a space failure, got %v", got)
	}
}

// An unanswerable free-space probe is a failed precondition, not "probably ok".
func TestMaintenance_PreUpdateSnapshotRefusesWhenFreeSpaceUnknown(t *testing.T) {
	db, backupDir := openBackupTestDB(t)
	m := NewMaintenance(db, MaintenanceConfig{BackupDir: backupDir})
	m.SetDiskFreeFunc(func(string) (uint64, error) { return 0, errors.New("statfs exploded") })

	if _, err := m.PreUpdateSnapshot("v0.3.0"); err == nil {
		t.Fatal("an unknowable free-space figure must block the copy")
	}
}

// SnapshotOnce answers "", nil when backups are off. PreUpdateSnapshot must NOT
// — an install path reading that as success would swap the binary with nothing
// to restore from.
func TestMaintenance_PreUpdateSnapshotErrorsWhenBackupsDisabled(t *testing.T) {
	db, _ := openBackupTestDB(t)
	m := NewMaintenance(db, MaintenanceConfig{}) // BackupDir == ""

	path, err := m.PreUpdateSnapshot("v0.3.0")
	if err == nil {
		t.Fatalf("disabled backups must be an error here, got path %q", path)
	}
	if path != "" {
		t.Fatalf("no path may be returned with an error, got %q", path)
	}
}
