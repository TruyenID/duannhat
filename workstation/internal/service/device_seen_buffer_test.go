package service

import (
	"context"
	"path/filepath"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

func TestDeviceSeenBuffer_FlushUpdatesRow(t *testing.T) {
	dir := t.TempDir()
	db, err := storetest.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	_, _ = db.Exec(`CREATE TABLE IF NOT EXISTS devices (
		id TEXT PRIMARY KEY,
		name TEXT NOT NULL,
		type TEXT NOT NULL DEFAULT 'tms',
		last_seen_at TEXT,
		organization_id TEXT NOT NULL DEFAULT '',
		branch_id TEXT NOT NULL DEFAULT ''
	)`)
	_, _ = db.Exec(`INSERT INTO devices (id, name, type, organization_id, branch_id) VALUES ('d1', 'Kiosk #1', 'kiosk', 'org-1', 'branch-1')`)

	buf := NewDeviceSeenBuffer(db)
	now := time.Now().UTC()
	buf.Touch("d1", now)
	buf.Touch("d1", now.Add(50*time.Millisecond))

	if err := buf.FlushOnce(); err != nil {
		t.Fatalf("flush: %v", err)
	}

	var got string
	if err := db.QueryRow(`SELECT last_seen_at FROM devices WHERE id = 'd1'`).Scan(&got); err != nil {
		t.Fatalf("scan: %v", err)
	}
	if got == "" {
		t.Fatalf("last_seen_at not written")
	}
}

func TestDeviceSeenBuffer_CoalescesUntilFlush(t *testing.T) {
	dir := t.TempDir()
	db, err := storetest.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	_, _ = db.Exec(`CREATE TABLE IF NOT EXISTS devices (
		id TEXT PRIMARY KEY,
		name TEXT NOT NULL,
		type TEXT NOT NULL DEFAULT 'tms',
		last_seen_at TEXT,
		organization_id TEXT NOT NULL DEFAULT '',
		branch_id TEXT NOT NULL DEFAULT ''
	)`)
	_, _ = db.Exec(`INSERT INTO devices (id, name, type, organization_id, branch_id) VALUES ('d1', 'Kiosk #1', 'kiosk', 'org-1', 'branch-1')`)

	buf := NewDeviceSeenBuffer(db)

	for i := 0; i < 1000; i++ {
		buf.Touch("d1", time.Now().UTC())
	}

	if n := buf.PendingCount(); n != 1 {
		t.Fatalf("expected 1 distinct device pending, got %d", n)
	}
	_ = buf.FlushOnce()
	if n := buf.PendingCount(); n != 0 {
		t.Fatalf("expected 0 pending after flush, got %d", n)
	}
}

func TestDeviceSeenBuffer_BackgroundLoop(t *testing.T) {
	dir := t.TempDir()
	db, err := storetest.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	_, _ = db.Exec(`CREATE TABLE IF NOT EXISTS devices (
		id TEXT PRIMARY KEY,
		name TEXT NOT NULL,
		type TEXT NOT NULL DEFAULT 'tms',
		last_seen_at TEXT,
		organization_id TEXT NOT NULL DEFAULT '',
		branch_id TEXT NOT NULL DEFAULT ''
	)`)
	_, _ = db.Exec(`INSERT INTO devices (id, name, type, organization_id, branch_id) VALUES ('d1', 'Kiosk #1', 'kiosk', 'org-1', 'branch-1')`)

	buf := NewDeviceSeenBuffer(db)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go buf.Run(ctx, 50*time.Millisecond)

	buf.Touch("d1", time.Now().UTC())
	time.Sleep(150 * time.Millisecond)

	var got string
	_ = db.QueryRow(`SELECT last_seen_at FROM devices WHERE id = 'd1'`).Scan(&got)
	if got == "" {
		t.Fatalf("background loop did not flush")
	}
}

func TestDeviceSeenBuffer_TimestampComparableWithSQLiteDatetime(t *testing.T) {
	dir := t.TempDir()
	db, err := storetest.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	_, _ = db.Exec(`CREATE TABLE IF NOT EXISTS devices (
		id TEXT PRIMARY KEY,
		name TEXT NOT NULL,
		type TEXT NOT NULL DEFAULT 'tms',
		last_seen_at TEXT,
		organization_id TEXT NOT NULL DEFAULT '',
		branch_id TEXT NOT NULL DEFAULT ''
	)`)
	_, _ = db.Exec(`INSERT INTO devices (id, name, type, organization_id, branch_id) VALUES ('d1', 'Kiosk #1', 'kiosk', 'org-1', 'branch-1')`)

	// Simulate a stale heartbeat: Touch with a time 5 minutes in the past.
	// With RFC3339Nano ('T' 0x54 > ' ' 0x20), a direct lex comparison of
	// last_seen_at against datetime('now', '-3 minutes') produces a false
	// positive — the stale row looks "fresh" and the count comes back 1
	// instead of 0.
	stale := time.Now().Add(-5 * time.Minute).UTC()
	buf := NewDeviceSeenBuffer(db)
	buf.Touch("d1", stale)
	if err := buf.FlushOnce(); err != nil {
		t.Fatalf("flush: %v", err)
	}

	// The stale device must NOT appear as recently-seen.
	// This fails with RFC3339Nano: 'T'(0x54) > ' '(0x20) makes every
	// RFC3339 string sort HIGHER than every datetime() string, so the
	// stale row incorrectly matches "> datetime('now', '-3 minutes')".
	var n int
	if err := db.QueryRow(
		`SELECT COUNT(*) FROM devices WHERE last_seen_at > datetime('now', '-3 minutes')`,
	).Scan(&n); err != nil {
		t.Fatalf("query: %v", err)
	}
	if n != 0 {
		t.Fatalf("expected 0 stale devices, got %d (RFC3339Nano 'T' sorts above SQLite ' ' — false positive)", n)
	}

	// A freshly-touched device MUST appear as recently-seen.
	buf.Touch("d1", time.Now().UTC())
	if err := buf.FlushOnce(); err != nil {
		t.Fatalf("flush: %v", err)
	}
	if err := db.QueryRow(
		`SELECT COUNT(*) FROM devices WHERE last_seen_at > datetime('now', '-1 minute')`,
	).Scan(&n); err != nil {
		t.Fatalf("query: %v", err)
	}
	if n != 1 {
		t.Fatalf("expected 1 fresh device, got %d (timestamp format incompatible with SQLite datetime())", n)
	}
}

func TestDeviceSeenBuffer_RegisterInsertsThenUpdates(t *testing.T) {
	dir := t.TempDir()
	db, err := storetest.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	_, _ = db.Exec(`CREATE TABLE IF NOT EXISTS devices (
		id TEXT PRIMARY KEY,
		name TEXT NOT NULL,
		type TEXT NOT NULL DEFAULT 'tms',
		status TEXT NOT NULL DEFAULT 'pending_activation',
		last_seen_at TEXT,
		organization_id TEXT NOT NULL DEFAULT '',
		branch_id TEXT NOT NULL DEFAULT '',
		created_at TEXT NOT NULL DEFAULT (datetime('now')),
		updated_at TEXT NOT NULL DEFAULT (datetime('now'))
	)`)

	buf := NewDeviceSeenBuffer(db)
	info := DeviceInfo{
		ID:             "d1",
		Name:           "Kiosk #1",
		Type:           "kiosk",
		Status:         "active",
		BranchID:       "branch-1",
		OrganizationID: "org-1",
	}

	// First Register → INSERT
	if err := buf.Register(info); err != nil {
		t.Fatalf("register insert: %v", err)
	}
	var count int
	_ = db.QueryRow(`SELECT COUNT(*) FROM devices WHERE id = 'd1'`).Scan(&count)
	if count != 1 {
		t.Fatalf("expected 1 row after first Register, got %d", count)
	}

	// Second Register with updated name → UPDATE via ON CONFLICT
	info.Name = "Kiosk #1 (Renamed)"
	if err := buf.Register(info); err != nil {
		t.Fatalf("register update: %v", err)
	}
	var name string
	_ = db.QueryRow(`SELECT name FROM devices WHERE id = 'd1'`).Scan(&name)
	if name != "Kiosk #1 (Renamed)" {
		t.Fatalf("expected name updated, got %q", name)
	}
	_ = db.QueryRow(`SELECT COUNT(*) FROM devices`).Scan(&count)
	if count != 1 {
		t.Fatalf("expected still 1 row after UPSERT, got %d", count)
	}
}

func TestDeviceSeenBuffer_RegisterSkipsIncomplete(t *testing.T) {
	dir := t.TempDir()
	db, err := storetest.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	_, _ = db.Exec(`CREATE TABLE IF NOT EXISTS devices (
		id TEXT PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL,
		status TEXT NOT NULL DEFAULT 'active', last_seen_at TEXT,
		organization_id TEXT NOT NULL, branch_id TEXT NOT NULL,
		created_at TEXT, updated_at TEXT
	)`)

	buf := NewDeviceSeenBuffer(db)

	// Missing required fields → no-op (skip), no error.
	for _, info := range []DeviceInfo{
		{ID: "", Name: "x", Type: "kiosk", BranchID: "b", OrganizationID: "o"},
		{ID: "d2", Name: "x", Type: "kiosk", BranchID: "", OrganizationID: "o"},
		{ID: "d3", Name: "x", Type: "kiosk", BranchID: "b", OrganizationID: ""},
	} {
		if err := buf.Register(info); err != nil {
			t.Fatalf("register should not error on incomplete: %v", err)
		}
	}

	var count int
	_ = db.QueryRow(`SELECT COUNT(*) FROM devices`).Scan(&count)
	if count != 0 {
		t.Fatalf("expected 0 rows (all skipped), got %d", count)
	}
}

func TestDeviceSeenBuffer_RegisterThenTouchUpdatesLastSeen(t *testing.T) {
	dir := t.TempDir()
	db, err := storetest.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	_, _ = db.Exec(`CREATE TABLE IF NOT EXISTS devices (
		id TEXT PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL,
		status TEXT NOT NULL DEFAULT 'active', last_seen_at TEXT,
		organization_id TEXT NOT NULL, branch_id TEXT NOT NULL,
		created_at TEXT, updated_at TEXT
	)`)

	buf := NewDeviceSeenBuffer(db)
	if err := buf.Register(DeviceInfo{
		ID: "d1", Name: "k1", Type: "kiosk", Status: "active",
		BranchID: "b1", OrganizationID: "o1",
	}); err != nil {
		t.Fatalf("register: %v", err)
	}

	// Touch after register → Flush should now affect 1 row (production path)
	buf.Touch("d1", time.Now().UTC().Add(1*time.Second))
	if err := buf.FlushOnce(); err != nil {
		t.Fatalf("flush: %v", err)
	}

	var seenAt string
	if err := db.QueryRow(`SELECT last_seen_at FROM devices WHERE id = 'd1'`).Scan(&seenAt); err != nil {
		t.Fatalf("scan: %v", err)
	}
	if seenAt == "" {
		t.Fatalf("last_seen_at empty after Touch+Flush — DeviceSeenBuffer no-op bug regression")
	}
}
