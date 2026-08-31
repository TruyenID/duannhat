package service

import (
	"context"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// MaintenanceConfig controls cadence of background DB upkeep jobs.
// Zero values fall back to sensible defaults — see NewMaintenance.
type MaintenanceConfig struct {
	CheckpointInterval  time.Duration // default 1h
	SyncQueueInterval   time.Duration // default 6h
	AuditInterval       time.Duration // default 24h
	IdempotencyInterval time.Duration // default 1h
	SyncQueueKeep       time.Duration // default 7 days
	AuditKeep           time.Duration // default 90 days
	IdempotencyKeep     time.Duration // default 24h (matches migration 010 header)

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
	name := fmt.Sprintf("snapshot-%s.db", time.Now().UTC().Format("20060102-150405"))
	dst := filepath.Join(m.cfg.BackupDir, name)
	// VACUUM INTO is atomic and consistent even with active writers.
	if _, err := m.db.Exec(`VACUUM INTO ?`, dst); err != nil {
		return "", fmt.Errorf("vacuum into: %w", err)
	}
	if err := m.rotateBackups(); err != nil {
		slog.Warn("backup rotation", "err", err)
	}
	return dst, nil
}

func (m *Maintenance) rotateBackups() error {
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
		if !strings.HasPrefix(e.Name(), "snapshot-") {
			continue
		}
		fi, err := e.Info()
		if err != nil {
			continue
		}
		files = append(files, entry{e.Name(), fi.ModTime()})
	}
	if len(files) <= m.cfg.BackupKeep {
		return nil
	}
	sort.Slice(files, func(i, j int) bool { return files[i].mod.Before(files[j].mod) })
	for _, old := range files[:len(files)-m.cfg.BackupKeep] {
		_ = os.Remove(filepath.Join(m.cfg.BackupDir, old.name))
	}
	return nil
}
