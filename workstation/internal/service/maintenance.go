package service

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

const (
	// periodicSnapshotPrefix marks the 6-hourly copies; preUpdateSnapshotPrefix
	// marks the ones taken immediately before an install. They MUST differ:
	// rotation is per-prefix, so sharing one would let the periodic keep-window
	// delete the pre-update copy at the exact moment it is needed (#2659).
	periodicSnapshotPrefix  = "snapshot-"
	preUpdateSnapshotPrefix = "pre-update-"

	// preUpdateSnapshotKeep bounds the pre-update family. Three, because these
	// are written per INSTALL (a handful a month), not per clock tick, and the
	// realistic recovery need is "the copy from before the build that broke" —
	// which may be one or two installs back by the time a shop notices. Each
	// copy costs a whole database, so unbounded growth would eventually fill
	// the very disk the next backup needs.
	preUpdateSnapshotKeep = 3

	miB = 1 << 20
	// minSnapshotHeadroomBytes is the floor for the free-space precondition. A
	// tiny database must not make "enough room" mean a few hundred kilobytes:
	// SQLite still needs journal/temp room while VACUUM runs.
	minSnapshotHeadroomBytes = 16 * miB
)

// MaintenanceConfig controls cadence of background DB upkeep jobs.
// Zero values fall back to sensible defaults — see NewMaintenance.
type MaintenanceConfig struct {
	CheckpointInterval  time.Duration // default 1h
	SyncQueueInterval   time.Duration // default 6h
	AuditInterval       time.Duration // default 24h
	IdempotencyInterval time.Duration // default 1h
	AlertInterval       time.Duration // default 24h
	SyncQueueKeep       time.Duration // default 7 days
	AuditKeep           time.Duration // default 90 days
	IdempotencyKeep     time.Duration // default 24h (matches migration 010 header)
	AlertKeep           time.Duration // default 30 days — ĐÃ ĐÓNG mới bị dọn (#2167)

	BackupInterval time.Duration // default 6h
	BackupDir      string        // empty disables backups
	BackupKeep     int           // default 7
}

// Maintenance runs periodic SQLite housekeeping: WAL checkpoint,
// sync_queue retention, audit_log retention, idempotency cache TTL.
type Maintenance struct {
	db          *store.DB
	cfg         MaintenanceConfig
	idempotency *IdempotencyStore // nil disables the cleanup job

	// diskFreeFn is a test seam over the platform free-space call. Production
	// leaves it nil (diskFreeBytes). It sits BELOW the pre-update precondition
	// so a test can starve the disk without mocking the guard it is measuring.
	diskFreeFn func(dir string) (uint64, error)
}

func NewMaintenance(db *store.DB, cfg MaintenanceConfig) *Maintenance {
	if cfg.CheckpointInterval == 0 {
		cfg.CheckpointInterval = 1 * time.Hour
	}
	if cfg.SyncQueueInterval == 0 {
		cfg.SyncQueueInterval = 6 * time.Hour
	}
	if cfg.AuditInterval == 0 {
		cfg.AuditInterval = 24 * time.Hour
	}
	if cfg.IdempotencyInterval == 0 {
		cfg.IdempotencyInterval = 1 * time.Hour
	}
	if cfg.SyncQueueKeep == 0 {
		cfg.SyncQueueKeep = 7 * 24 * time.Hour
	}
	if cfg.AuditKeep == 0 {
		cfg.AuditKeep = 90 * 24 * time.Hour
	}
	if cfg.IdempotencyKeep == 0 {
		cfg.IdempotencyKeep = 24 * time.Hour
	}
	if cfg.AlertInterval == 0 {
		cfg.AlertInterval = 24 * time.Hour
	}
	if cfg.AlertKeep == 0 {
		cfg.AlertKeep = 30 * 24 * time.Hour
	}
	if cfg.BackupInterval == 0 {
		cfg.BackupInterval = 6 * time.Hour
	}
	if cfg.BackupKeep == 0 {
		cfg.BackupKeep = 7
	}
	return &Maintenance{db: db, cfg: cfg}
}

// SetIdempotencyStore wires the bump-dedup table cleanup into the
// maintenance loop. Idempotent — passing nil disables the job. Called by
// Server.New after the store is constructed; tests can leave it unset.
func (m *Maintenance) SetIdempotencyStore(s *IdempotencyStore) {
	m.idempotency = s
}

// SetDiskFreeFunc overrides the free-space probe. Tests only — production
// never calls it, which is why the default lives in diskFreeBytes.
func (m *Maintenance) SetDiskFreeFunc(fn func(dir string) (uint64, error)) {
	m.diskFreeFn = fn
}

// Start launches all maintenance goroutines. Safe to call once.
func (m *Maintenance) Start(ctx context.Context) {
	go m.loop(ctx, m.cfg.CheckpointInterval, "checkpoint", func() error {
		return m.CheckpointOnce()
	})
	go m.loop(ctx, m.cfg.SyncQueueInterval, "sync_queue", func() error {
		n, err := m.PurgeSyncQueueOnce()
		if err == nil && n > 0 {
			slog.Info("sync_queue purged", "rows", n)
		}
		return err
	})
	go m.loop(ctx, m.cfg.AuditInterval, "audit_log", func() error {
		n, err := m.PurgeAuditOnce()
		if err == nil && n > 0 {
			slog.Info("audit_log purged", "rows", n)
		}
		return err
	})
	go m.loop(ctx, m.cfg.AlertInterval, "alerts", func() error {
		n, err := m.PurgeAlertsOnce()
		if err == nil && n > 0 {
			slog.Info("alerts purged (closed rows past retention)", "rows", n)
		}
		return err
	})
	go m.loop(ctx, m.cfg.IdempotencyInterval, "idempotency", func() error {
		n, err := m.PurgeIdempotencyOnce()
		if err == nil && n > 0 {
			slog.Info("idempotency_keys purged", "rows", n)
		}
		return err
	})
	go m.loop(ctx, m.cfg.BackupInterval, "backup", func() error {
		path, err := m.SnapshotOnce()
		if err == nil && path != "" {
			slog.Info("snapshot written", "path", path)
		}
		return err
	})
}

func (m *Maintenance) loop(ctx context.Context, every time.Duration, name string, fn func() error) {
	t := time.NewTicker(every)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			if err := fn(); err != nil {
				slog.Warn("maintenance job", "name", name, "err", err)
			}
		}
	}
}

// CheckpointOnce runs PRAGMA wal_checkpoint(TRUNCATE).
func (m *Maintenance) CheckpointOnce() error {
	return m.db.Checkpoint()
}

// PurgeSyncQueueOnce removes synced rows older than cfg.SyncQueueKeep.
func (m *Maintenance) PurgeSyncQueueOnce() (int, error) {
	cutoff := time.Now().Add(-m.cfg.SyncQueueKeep).UTC().Format(time.RFC3339)
	res, err := m.db.Exec(
		`DELETE FROM sync_queue WHERE synced_at IS NOT NULL AND datetime(synced_at) < datetime(?)`,
		cutoff,
	)
	if err != nil {
		return 0, err
	}
	n, _ := res.RowsAffected()
	return int(n), nil
}

// PurgeIdempotencyOnce drops bump-dedup rows older than cfg.IdempotencyKeep.
// No-op if SetIdempotencyStore was never called (e.g. tests).
func (m *Maintenance) PurgeIdempotencyOnce() (int, error) {
	if m.idempotency == nil {
		return 0, nil
	}
	n, err := m.idempotency.CleanupOlderThan(m.cfg.IdempotencyKeep)
	return int(n), err
}

// PurgeAlertsOnce removes CLOSED alerts (resolved/acked) whose resolved_at is
// older than cfg.AlertKeep. Alert MỞ không bao giờ bị dọn — một alert chưa ai
// xử lý mà tự biến mất theo tuổi là MẤT DỮ LIỆU (tiền còn kẹt trong máy không
// hết kẹt chỉ vì 30 ngày trôi qua); volume của alert mở được chặn bằng deadband
// + ack-theo-kind, không phải bằng retention (#2167).
func (m *Maintenance) PurgeAlertsOnce() (int, error) {
	cutoff := time.Now().Add(-m.cfg.AlertKeep).UTC().Format(time.RFC3339)
	res, err := m.db.Exec(
		`DELETE FROM alerts WHERE resolved_at IS NOT NULL AND datetime(resolved_at) < datetime(?)`,
		cutoff,
	)
	if err != nil {
		return 0, err
	}
	n, _ := res.RowsAffected()
	return int(n), nil
}

// PurgeAuditOnce removes audit_log rows older than cfg.AuditKeep.
func (m *Maintenance) PurgeAuditOnce() (int, error) {
	cutoff := time.Now().Add(-m.cfg.AuditKeep).UTC().Format(time.RFC3339)
	res, err := m.db.Exec(
		`DELETE FROM audit_log WHERE datetime(timestamp) < datetime(?)`,
		cutoff,
	)
	if err != nil {
		return 0, err
	}
	n, _ := res.RowsAffected()
	return int(n), nil
}

// SnapshotOnce writes a copy of the live DB to BackupDir using `VACUUM INTO`,
// then rotates older files keeping at most BackupKeep. Returns "", nil if
// BackupDir is empty (snapshots disabled).
func (m *Maintenance) SnapshotOnce() (string, error) {
	if m.cfg.BackupDir == "" {
		return "", nil
	}
	if err := os.MkdirAll(m.cfg.BackupDir, 0o755); err != nil {
		return "", err
	}
	name := fmt.Sprintf("%s%s.db", periodicSnapshotPrefix, time.Now().UTC().Format("20060102-150405"))
	dst := filepath.Join(m.cfg.BackupDir, name)
	// VACUUM INTO is atomic and consistent even with active writers.
	if _, err := m.db.Exec(`VACUUM INTO ?`, dst); err != nil {
		return "", fmt.Errorf("vacuum into: %w", err)
	}
	if err := m.rotateBackups(periodicSnapshotPrefix, m.cfg.BackupKeep); err != nil {
		slog.Warn("backup rotation", "err", err)
	}
	return dst, nil
}

// PreUpdateSnapshot writes the copy an install is restored from when the new
// build migrates the DB and then dies (#2659). It is NOT the 6-hourly snapshot
// under another name — three differences are deliberate:
//
//   - The filename prefix is different. rotateBackups only ever considers files
//     carrying the prefix it is given, so a `pre-update-*` copy can never be
//     rotated away by the periodic job at the exact moment it is needed —
//     which is precisely what a 6-hourly keep-7 window would do to it on a busy
//     shop that installs two builds in a day.
//   - Free space is checked BEFORE the copy starts. `VACUUM INTO` needs room
//     for a whole second database; discovering that after the binary has been
//     swapped is discovering it too late.
//   - Backups being disabled is an ERROR here, not a silent no-op. The caller
//     uses the returned error to refuse the install; "", nil would install
//     with no way back.
//
// The caller must treat any error as a hard stop. This is the opposite of the
// fail-open rule the observability layer follows: a missing metric costs a
// graph, a missing pre-update copy costs the shop its night of orders.
func (m *Maintenance) PreUpdateSnapshot(version string) (string, error) {
	if m.cfg.BackupDir == "" {
		return "", errors.New("backups are disabled (no backup directory configured)")
	}
	if err := os.MkdirAll(m.cfg.BackupDir, 0o755); err != nil {
		return "", fmt.Errorf("create backup dir: %w", err)
	}
	if err := m.ensureRoomForSnapshot(); err != nil {
		return "", err
	}

	name := fmt.Sprintf("%s%s-%s.db",
		preUpdateSnapshotPrefix,
		sanitizeVersionForFilename(version),
		time.Now().UTC().Format("20060102-150405.000"),
	)
	dst := filepath.Join(m.cfg.BackupDir, name)
	if _, err := m.db.Exec(`VACUUM INTO ?`, dst); err != nil {
		return "", fmt.Errorf("vacuum into %s: %w", dst, err)
	}
	// Rotation runs AFTER the copy exists and its failure is never fatal: the
	// backup this install depends on is already on disk, and refusing the
	// install because an OLD copy could not be deleted would be the tail
	// wagging the dog. Sorting oldest-first with keep >= 1 cannot delete the
	// file just written.
	if err := m.rotateBackups(preUpdateSnapshotPrefix, preUpdateSnapshotKeep); err != nil {
		slog.Warn("pre-update backup rotation", "err", err)
	}
	return dst, nil
}

// ensureRoomForSnapshot fails when the backup volume cannot hold another copy
// of the database. VACUUM INTO writes a compacted copy, so the live file is an
// upper bound; the headroom covers the WAL frames a concurrent writer appends
// while the copy runs.
func (m *Maintenance) ensureRoomForSnapshot() error {
	need, err := m.snapshotSpaceNeeded()
	if err != nil {
		return err
	}
	free, err := m.diskFree(m.cfg.BackupDir)
	if err != nil {
		// Unknowable free space is not "probably fine" — on the install path
		// an unverified precondition is a failed precondition.
		return fmt.Errorf("cannot determine free space on %s: %w", m.cfg.BackupDir, err)
	}
	if free < need {
		return fmt.Errorf(
			"not enough free disk space for a pre-update database copy in %s: need ~%d MiB, %d MiB free",
			m.cfg.BackupDir, need/miB, free/miB,
		)
	}
	return nil
}

func (m *Maintenance) snapshotSpaceNeeded() (uint64, error) {
	path := m.db.Path()
	if path == "" {
		return 0, errors.New("database path is unknown, cannot size a backup")
	}
	var total uint64
	fi, err := os.Stat(path)
	if err != nil {
		return 0, fmt.Errorf("stat database: %w", err)
	}
	total += uint64(fi.Size())
	// The -wal file may legitimately not exist (checkpointed, or journal_mode
	// != wal); anything else is a real error.
	if wal, err := os.Stat(path + "-wal"); err == nil {
		total += uint64(wal.Size())
	} else if !os.IsNotExist(err) {
		return 0, fmt.Errorf("stat wal: %w", err)
	}
	total += total / 10
	if total < minSnapshotHeadroomBytes {
		total = minSnapshotHeadroomBytes
	}
	return total, nil
}

func (m *Maintenance) diskFree(dir string) (uint64, error) {
	if m.diskFreeFn != nil {
		return m.diskFreeFn(dir)
	}
	return diskFreeBytes(dir)
}

// sanitizeVersionForFilename keeps the version readable in `ls` without letting
// an HQ-supplied string escape the backup directory.
func sanitizeVersionForFilename(version string) string {
	var b strings.Builder
	for _, r := range strings.TrimSpace(version) {
		switch {
		case r >= 'a' && r <= 'z', r >= 'A' && r <= 'Z', r >= '0' && r <= '9',
			r == '.', r == '-', r == '_':
			b.WriteRune(r)
		default:
			b.WriteRune('_')
		}
	}
	if b.Len() == 0 {
		return "unknown"
	}
	return b.String()
}

// rotateBackups deletes the oldest files carrying `prefix` until at most `keep`
// remain. Files with any other prefix are invisible to it — that is what keeps
// the two backup families (periodic vs pre-update) from evicting each other.
func (m *Maintenance) rotateBackups(prefix string, keep int) error {
	if keep < 1 {
		keep = 1
	}
	entries, err := os.ReadDir(m.cfg.BackupDir)
	if err != nil {
		return err
	}
	type entry struct {
		name string
		mod  time.Time
	}
	var files []entry
	for _, e := range entries {
		if e.IsDir() {
			continue
		}
		if !strings.HasPrefix(e.Name(), prefix) {
			continue
		}
		fi, err := e.Info()
		if err != nil {
			continue
		}
		files = append(files, entry{e.Name(), fi.ModTime()})
	}
	if len(files) <= keep {
		return nil
	}
	sort.Slice(files, func(i, j int) bool {
		if files[i].mod.Equal(files[j].mod) {
			// Same-mtime ties would otherwise be resolved by directory order,
			// which is not stable — the timestamped name is.
			return files[i].name < files[j].name
		}
		return files[i].mod.Before(files[j].mod)
	})
	for _, old := range files[:len(files)-keep] {
		_ = os.Remove(filepath.Join(m.cfg.BackupDir, old.name))
	}
	return nil
}
