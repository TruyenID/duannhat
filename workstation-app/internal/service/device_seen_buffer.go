package service

import (
	"context"
	"database/sql"
	"log/slog"
	"sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// DeviceSeenBuffer coalesces high-frequency device heartbeat writes into
// a single UPDATE per flush window. Every authenticated request can call
// Touch without touching the DB; the background Run loop flushes the
// in-RAM map every N seconds (default 30s, see server.go).
type DeviceSeenBuffer struct {
	db  *store.DB
	mu  sync.Mutex
	buf map[string]time.Time
}

func NewDeviceSeenBuffer(db *store.DB) *DeviceSeenBuffer {
	return &DeviceSeenBuffer{db: db, buf: make(map[string]time.Time)}
}

// Register UPSERTs a device row into the local `devices` table so subsequent
// Touch/Flush actually have a target row to UPDATE. Call after Cloud verifies
// a device (pair or LAN auth) — keeps local devices table populated with the
// subset of cloud devices that have interacted with this workstation.
//
// Note: branchID and organizationID are required by the omnify schema
// (NOT NULL). status defaults to 'active' when empty.
func (b *DeviceSeenBuffer) Register(info DeviceInfo) error {
	if info.ID == "" || info.BranchID == "" || info.OrganizationID == "" {
		return nil // skip rows that would violate NOT NULL — caller logs if needed
	}
	status := info.Status
	if status == "" {
		status = "active"
	}
	_, err := b.db.Exec(`
		INSERT INTO devices (id, name, type, status, last_seen_at, organization_id, branch_id, created_at, updated_at)
		VALUES (?, ?, ?, ?, datetime('now'), ?, ?, datetime('now'), datetime('now'))
		ON CONFLICT(id) DO UPDATE SET
			name = excluded.name,
			type = excluded.type,
			status = excluded.status,
			last_seen_at = excluded.last_seen_at,
			updated_at = datetime('now')
	`, info.ID, info.Name, info.Type, status, info.OrganizationID, info.BranchID)
	return err
}

// Touch records that we saw `deviceID` at `at`. Cheap, never blocks on DB.
// If multiple Touch calls arrive for the same device before flush, only
// the most recent timestamp is kept.
func (b *DeviceSeenBuffer) Touch(deviceID string, at time.Time) {
	if deviceID == "" {
		return
	}
	b.mu.Lock()
	defer b.mu.Unlock()
	if prev, ok := b.buf[deviceID]; !ok || at.After(prev) {
		b.buf[deviceID] = at
	}
}

// PendingCount returns the number of distinct devices waiting to flush.
func (b *DeviceSeenBuffer) PendingCount() int {
	b.mu.Lock()
	defer b.mu.Unlock()
	return len(b.buf)
}

// FlushOnce writes all pending timestamps in a single transaction.
func (b *DeviceSeenBuffer) FlushOnce() error {
	b.mu.Lock()
	if len(b.buf) == 0 {
		b.mu.Unlock()
		return nil
	}
	snapshot := b.buf
	b.buf = make(map[string]time.Time)
	b.mu.Unlock()

	return b.db.Transaction(func(tx *sql.Tx) error {
		stmt, err := tx.Prepare(`UPDATE devices SET last_seen_at = ? WHERE id = ?`)
		if err != nil {
			return err
		}
		defer stmt.Close()
		for id, t := range snapshot {
			if _, err := stmt.Exec(t.UTC().Format("2006-01-02 15:04:05.999"), id); err != nil {
				return err
			}
		}
		return nil
	})
}

// Run flushes every `interval` until ctx is done. Does one final best-effort
// flush on ctx.Done so writes don't get lost on shutdown.
func (b *DeviceSeenBuffer) Run(ctx context.Context, interval time.Duration) {
	t := time.NewTicker(interval)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			if err := b.FlushOnce(); err != nil {
				slog.Warn("device seen final flush", "err", err)
			}
			return
		case <-t.C:
			if err := b.FlushOnce(); err != nil {
				slog.Warn("device seen flush", "err", err)
			}
		}
	}
}
