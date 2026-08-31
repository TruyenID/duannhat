# Sprint 1 — Operational Hardening (Pre-Pilot)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Khoá các lỗ hổng ops & ổn định SQLite/CI trước khi rollout pilot cửa hàng đầu tiên — tập trung vào 5 rủi ro production: cleanup loop chưa wire, WAL bloat, queue/audit unbounded, write-amplification trên `last_seen_at`, CI không tồn tại.

**Architecture:** Code đã có sẵn (auth middleware, cache, verifier, WAL pragma) — Sprint này wire các loop còn thiếu, thêm `internal/service/maintenance.go` để gom các retention/checkpoint job, thêm buffer cho `devices.last_seen_at`, và scaffold `.github/workflows/ci.yml`. Mọi job đều chạy như goroutine background, start cùng server, stop khi server stop.

**Tech Stack:** Go 1.25, SQLite (modernc), `log/slog`, GitHub Actions, không thêm dependency mới.

**Sprint kế hoạch:** 1 dev, 3 ngày (~24h effort). Mỗi task self-contained, commit riêng để dễ revert.

**Định nghĩa "done" cả Sprint:**
- Build sạch trên 5 platform (`make build-all`).
- `go test ./...` pass.
- Chạy workstation 4 giờ với 1000 req/phút giả lập: WAL file < 50 MB, không có panic, audit/sync_queue row count ổn định.
- CI xanh trên PR mới.

---

## File Structure

| File | Trạng thái | Trách nhiệm |
|---|---|---|
| `internal/service/maintenance.go` | **MỚI** | Goroutine định kỳ: WAL checkpoint, sync_queue cleanup, audit_log retention. Start/Stop API giống các service khác. |
| `internal/service/maintenance_test.go` | **MỚI** | Test từng job độc lập với in-memory SQLite. |
| `internal/service/device_seen_buffer.go` | **MỚI** | RAM map `device_id → last_seen_at`, flush 30s vào bảng `devices`. |
| `internal/service/device_seen_buffer_test.go` | **MỚI** | Unit test: enqueue → flush → row updated. |
| `internal/store/db.go` | **SỬA** | Tune connection pool (1 writer / N readers); export `Checkpoint()` helper. |
| `internal/handler/server.go` | **SỬA** | Wire `authCache.RunCleanupLoop`, khởi tạo `MaintenanceService` + `DeviceSeenBuffer`, hook vào `Start()/Stop()`. |
| `internal/handler/auth_middleware.go` | **SỬA NHỎ** | Sau Verify thành công, thay vì update `devices.last_seen_at` trực tiếp → push vào `DeviceSeenBuffer`. |
| `.github/workflows/ci.yml` | **MỚI** | Job `test` (go test + race), `build` (matrix 5 target), `lint` (`go vet` + `gofmt -l`). |
| `Makefile` | **SỬA NHỎ** | Thêm target `make ci-local` mô phỏng CI. |

**Tách file theo trách nhiệm**: `maintenance.go` lo retention/checkpoint (DB-wide), `device_seen_buffer.go` lo write-coalescing (chỉ 1 cột). Không gộp vì lý do test isolation + tránh file > 300 dòng.

---

## Task 1: Wire Auth Cache Cleanup Loop

**Vấn đề thực tế đã verify:** `AuthCacheStore.RunCleanupLoop` đã được implement tại [internal/service/auth_cache.go:124](../../internal/service/auth_cache.go#L124) nhưng KHÔNG được gọi từ [server.go](../../internal/handler/server.go) hay [main.go](../../cmd/workstation/main.go) — cache table sẽ phình vô hạn khi nhiều device pair/unpair.

**Files:**
- Modify: `internal/handler/server.go:50-110` (thêm context cho cleanup goroutine)
- Modify: `internal/handler/server.go:112-132` (Start/Stop)

- [ ] **Step 1.1: Đọc lại `AuthCacheStore.RunCleanupLoop` signature**

Verify hiện trạng:

```bash
grep -n "RunCleanupLoop" internal/service/auth_cache.go
```

Expected: signature `func (s *AuthCacheStore) RunCleanupLoop(ctx context.Context, interval, staleThreshold time.Duration)`.

- [ ] **Step 1.2: Thêm field & context vào `Server`**

Sửa [internal/handler/server.go:20-36](../../internal/handler/server.go#L20-L36) struct `Server`, thêm 2 field cuối:

```go
type Server struct {
	httpServer *http.Server
	hub        *Hub
	config     *config.Manager
	db         *store.DB
	orders     *service.OrderEngine
	devices    *printer.Manager
	sync       *service.SyncEngine
	audit      *audit.Logger
	monitor    *monitor.Monitor
	port       int

	// Local replica (Phase 1)
	authCache *service.AuthCacheStore
	authMW    *AuthMiddleware
	puller    *service.SyncPuller

	// Background loops
	bgCtx    context.Context
	bgCancel context.CancelFunc
}
```

- [ ] **Step 1.3: Khởi tạo context trong `New`**

Sửa [internal/handler/server.go:57-67](../../internal/handler/server.go#L57-L67), thêm dòng `bgCtx`:

```go
	bgCtx, bgCancel := context.WithCancel(context.Background())
	s := &Server{
		hub:      hub,
		config:   deps.Config,
		db:       deps.DB,
		orders:   deps.Orders,
		devices:  deps.Devices,
		sync:     deps.Sync,
		audit:    deps.Audit,
		monitor:  deps.Monitor,
		port:     deps.Port,
		bgCtx:    bgCtx,
		bgCancel: bgCancel,
	}
```

- [ ] **Step 1.4: Start cleanup loop trong `Start()`**

Sửa [internal/handler/server.go:112-123](../../internal/handler/server.go#L112-L123):

```go
func (s *Server) Start() error {
	go s.hub.Run()
	if s.puller != nil {
		s.puller.Start()
	}

	// Auth cache cleanup: chạy mỗi 10 phút, xoá entries quá hạn > 1 giờ.
	go s.authCache.RunCleanupLoop(s.bgCtx, 10*time.Minute, 1*time.Hour)

	slog.Info("local server starting", "port", s.port)
	if err := s.httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		return fmt.Errorf("server: %w", err)
	}
	return nil
}
```

- [ ] **Step 1.5: Cancel context trong `Stop()`**

Sửa [internal/handler/server.go:125-132](../../internal/handler/server.go#L125-L132):

```go
func (s *Server) Stop() error {
	if s.bgCancel != nil {
		s.bgCancel()
	}
	if s.puller != nil {
		s.puller.Stop()
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	return s.httpServer.Shutdown(ctx)
}
```

- [ ] **Step 1.6: Test bằng tay**

```bash
go build ./...
./bin/ws-app &
sleep 12m
sqlite3 ~/.workstation-app/workstation-app.db \
  "SELECT COUNT(*) FROM auth_token_cache WHERE expires_at < datetime('now', '-1 hour');"
```

Expected: 0 rows (tất cả entries quá hạn 1h đã bị xoá).

- [ ] **Step 1.7: Commit**

```bash
git add internal/handler/server.go
git commit -m "feat(server): wire auth cache cleanup loop on Start"
```

---

## Task 2: SQLite Maintenance Service (WAL Checkpoint + Pool Tuning)

**Vấn đề thực tế đã verify:** [internal/store/db.go:22-27](../../internal/store/db.go#L22-L27) chỉ set `journal_mode = WAL` nhưng không có periodic checkpoint → file `*.db-wal` sẽ phình vô hạn dưới load. Connection pool dùng default Go (max open = 0 = unlimited) gây contention vì SQLite chỉ cho phép 1 writer cùng lúc.

**Files:**
- Modify: `internal/store/db.go`
- Create: `internal/service/maintenance.go`
- Create: `internal/service/maintenance_test.go`
- Modify: `internal/handler/server.go`

- [ ] **Step 2.1: Thêm Checkpoint helper + pool tuning vào store**

Sửa [internal/store/db.go:14-43](../../internal/store/db.go#L14-L43):

```go
func Open(path string) (*DB, error) {
	conn, err := sql.Open("sqlite", path)
	if err != nil {
		return nil, fmt.Errorf("open database: %w", err)
	}

	// SQLite handles 1 writer + N readers. With WAL mode N readers don't
	// block the writer, but >1 concurrent writer causes SQLITE_BUSY. We cap
	// the pool so retry happens at app layer, not driver layer.
	conn.SetMaxOpenConns(8)
	conn.SetMaxIdleConns(4)
	conn.SetConnMaxLifetime(0) // SQLite connections are cheap; no rotation

	pragmas := []string{
		"PRAGMA journal_mode = WAL",
		"PRAGMA busy_timeout = 5000",
		"PRAGMA foreign_keys = ON",
		"PRAGMA synchronous = NORMAL",
		"PRAGMA wal_autocheckpoint = 1000", // ~4 MB before auto-checkpoint
	}
	for _, p := range pragmas {
		if _, err := conn.Exec(p); err != nil {
			conn.Close()
			return nil, fmt.Errorf("set pragma %q: %w", p, err)
		}
	}

	db := &DB{conn: conn, path: path}

	if err := db.migrate(); err != nil {
		conn.Close()
		return nil, fmt.Errorf("run migrations: %w", err)
	}

	return db, nil
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
```

- [ ] **Step 2.2: Write failing test cho MaintenanceService.checkpointOnce**

Create `internal/service/maintenance_test.go`:

```go
package service

import (
	"os"
	"path/filepath"
	"testing"

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
	// Generate WAL traffic (large enough to spill).
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
```

- [ ] **Step 2.3: Run test — expect FAIL (file doesn't exist yet)**

```bash
go test ./internal/service/ -run TestMaintenance_CheckpointShrinksWAL -v
```

Expected: `undefined: NewMaintenance`.

- [ ] **Step 2.4: Tạo `internal/service/maintenance.go`**

```go
package service

import (
	"context"
	"log/slog"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// MaintenanceConfig controls cadence of background DB upkeep jobs.
// Zero values fall back to sensible defaults — see NewMaintenance.
type MaintenanceConfig struct {
	CheckpointInterval time.Duration // default 1h
	SyncQueueInterval  time.Duration // default 6h
	AuditInterval      time.Duration // default 24h
	SyncQueueKeep      time.Duration // keep synced rows for this long; default 7 days
	AuditKeep          time.Duration // keep audit rows for this long; default 90 days
}

// Maintenance runs periodic SQLite housekeeping: WAL checkpoint,
// sync_queue retention, audit_log retention.
type Maintenance struct {
	db     *store.DB
	cfg    MaintenanceConfig
	stopCh chan struct{}
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
	if cfg.SyncQueueKeep == 0 {
		cfg.SyncQueueKeep = 7 * 24 * time.Hour
	}
	if cfg.AuditKeep == 0 {
		cfg.AuditKeep = 90 * 24 * time.Hour
	}
	return &Maintenance{db: db, cfg: cfg, stopCh: make(chan struct{})}
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
}

func (m *Maintenance) loop(ctx context.Context, every time.Duration, name string, fn func() error) {
	t := time.NewTicker(every)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-m.stopCh:
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
	res, err := m.db.Exec(`DELETE FROM sync_queue WHERE synced_at IS NOT NULL AND synced_at < ?`, cutoff)
	if err != nil {
		return 0, err
	}
	n, _ := res.RowsAffected()
	return int(n), nil
}

// PurgeAuditOnce removes audit_log rows older than cfg.AuditKeep.
func (m *Maintenance) PurgeAuditOnce() (int, error) {
	cutoff := time.Now().Add(-m.cfg.AuditKeep).UTC().Format(time.RFC3339)
	res, err := m.db.Exec(`DELETE FROM audit_log WHERE timestamp < ?`, cutoff)
	if err != nil {
		return 0, err
	}
	n, _ := res.RowsAffected()
	return int(n), nil
}
```

- [ ] **Step 2.5: Run test — expect PASS**

```bash
go test ./internal/service/ -run TestMaintenance_CheckpointShrinksWAL -v
```

Expected: PASS.

- [ ] **Step 2.6: Thêm test cho PurgeSyncQueueOnce + PurgeAuditOnce**

Append vào `internal/service/maintenance_test.go`:

```go
func TestMaintenance_PurgeSyncQueueKeepsUnsynced(t *testing.T) {
	dir := t.TempDir()
	db, err := store.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	// Migrations already create sync_queue. Insert 3 rows: 2 synced (old + recent), 1 unsynced.
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
```

- [ ] **Step 2.7: Run all maintenance tests**

```bash
go test ./internal/service/ -run TestMaintenance -v
```

Expected: 3 PASS.

- [ ] **Step 2.8: Wire MaintenanceService trong server.go**

Sửa [internal/handler/server.go:32-36](../../internal/handler/server.go#L32-L36) — thêm field:

```go
	// Local replica (Phase 1)
	authCache   *service.AuthCacheStore
	authMW      *AuthMiddleware
	puller      *service.SyncPuller
	maintenance *service.Maintenance

	// Background loops
	bgCtx    context.Context
	bgCancel context.CancelFunc
```

Sau dòng [server.go:84](../../internal/handler/server.go#L84) (sau khi tạo puller), thêm:

```go
	s.maintenance = service.NewMaintenance(s.db, service.MaintenanceConfig{})
```

Trong `Start()` sau `puller.Start()`, thêm:

```go
	if s.maintenance != nil {
		s.maintenance.Start(s.bgCtx)
	}
```

- [ ] **Step 2.9: Smoke test full server**

```bash
go build ./...
./bin/ws-app &
PID=$!
sleep 5
kill -INT $PID
```

Expected: log có `local server starting`, exit clean, không có goroutine leak warning.

- [ ] **Step 2.10: Commit**

```bash
git add internal/store/db.go internal/service/maintenance.go internal/service/maintenance_test.go internal/handler/server.go
git commit -m "feat(maintenance): wire WAL checkpoint + retention loops"
```

---

## Task 3: Device `last_seen_at` Write Buffer

**Vấn đề thực tế đã verify:** [internal/handler/auth_middleware.go:50-108](../../internal/handler/auth_middleware.go#L50-L108) hiện chưa update `devices.last_seen_at` trên mỗi request — nhưng trong INTEGRATION_GAPS.md mục G.1 đã flag rằng khi thêm sẽ gây write storm. Sprint 1 thêm buffer SẴN để khi devs khác wire vào sẽ không gây vấn đề.

**Files:**
- Create: `internal/service/device_seen_buffer.go`
- Create: `internal/service/device_seen_buffer_test.go`
- Modify: `internal/handler/server.go`
- Modify: `internal/handler/auth_middleware.go` (small)

- [ ] **Step 3.1: Write failing test**

Create `internal/service/device_seen_buffer_test.go`:

```go
package service

import (
	"context"
	"path/filepath"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

func TestDeviceSeenBuffer_FlushUpdatesRow(t *testing.T) {
	dir := t.TempDir()
	db, err := store.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	_, _ = db.Exec(`INSERT INTO devices (id, type, name, connection_type) VALUES ('d1', 'kiosk', 'Kiosk #1', 'tcp')`)

	buf := NewDeviceSeenBuffer(db)
	now := time.Now().UTC()
	buf.Touch("d1", now)
	buf.Touch("d1", now.Add(50*time.Millisecond))  // newer should win

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
	db, err := store.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	_, _ = db.Exec(`INSERT INTO devices (id, type, name, connection_type) VALUES ('d1', 'kiosk', 'Kiosk #1', 'tcp')`)

	buf := NewDeviceSeenBuffer(db)

	// 1000 touches → at most 1 UPDATE per device at flush time
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
	db, err := store.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	defer db.Close()

	_, _ = db.Exec(`INSERT INTO devices (id, type, name, connection_type) VALUES ('d1', 'kiosk', 'Kiosk #1', 'tcp')`)

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
```

- [ ] **Step 3.2: Run tests — expect FAIL**

```bash
go test ./internal/service/ -run TestDeviceSeenBuffer -v
```

Expected: `undefined: NewDeviceSeenBuffer`.

- [ ] **Step 3.3: Implement `internal/service/device_seen_buffer.go`**

```go
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
// Exposed for tests and `/api/monitor` observability.
func (b *DeviceSeenBuffer) PendingCount() int {
	b.mu.Lock()
	defer b.mu.Unlock()
	return len(b.buf)
}

// FlushOnce writes all pending timestamps in a single transaction and
// clears the buffer. Uses store.DB.Transaction so SQLite locking is
// honoured.
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
			if _, err := stmt.Exec(t.UTC().Format(time.RFC3339Nano), id); err != nil {
				return err
			}
		}
		return nil
	})
}

// Run flushes every `interval` until ctx is done. On ctx.Done it does
// one final best-effort flush so writes don't get lost on shutdown.
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
```

- [ ] **Step 3.4: Run tests — expect PASS**

```bash
go test ./internal/service/ -run TestDeviceSeenBuffer -v
```

Expected: 3 PASS.

- [ ] **Step 3.5: Wire buffer trong server.go**

Sửa [internal/handler/server.go:32-36](../../internal/handler/server.go#L32-L36) — thêm field:

```go
	seenBuffer  *service.DeviceSeenBuffer
```

Sau khi tạo `maintenance` (Task 2), thêm:

```go
	s.seenBuffer = service.NewDeviceSeenBuffer(s.db)
```

Trong `Start()`:

```go
	if s.seenBuffer != nil {
		go s.seenBuffer.Run(s.bgCtx, 30*time.Second)
	}
```

- [ ] **Step 3.6: Hook auth middleware vào buffer (1 dòng)**

Sửa [internal/handler/auth_middleware.go:36-48](../../internal/handler/auth_middleware.go#L36-L48), thêm field `seen`:

```go
type AuthMiddleware struct {
	cache      *service.AuthCacheStore
	verifier   *service.CloudVerifier
	branchIDFn func() string
	seen       *service.DeviceSeenBuffer // optional
}

func NewAuthMiddleware(
	cache *service.AuthCacheStore,
	verifier *service.CloudVerifier,
	branchIDFn func() string,
	seen *service.DeviceSeenBuffer,
) *AuthMiddleware {
	return &AuthMiddleware{
		cache:      cache,
		verifier:   verifier,
		branchIDFn: branchIDFn,
		seen:       seen,
	}
}
```

Trong `Wrap` after cache fresh OR after Cloud verify success, thêm 1 dòng trước `next.ServeHTTP`:

```go
		// (cache fresh path)
		if fresh {
			if !m.branchOK(entry.BranchID) {
				writeError(w, http.StatusForbidden, "device branch mismatch")
				return
			}
			if m.seen != nil {
				m.seen.Touch(entry.DeviceID, time.Now().UTC())
			}
			next.ServeHTTP(w, m.withDevice(r, entry.DeviceID, entry.DeviceType, entry.BranchID))
			return
		}

		// (cloud verify success path) — same Touch call after branchOK check.
```

Cần thêm `"time"` vào imports nếu chưa có.

- [ ] **Step 3.7: Update caller trong server.go**

Sửa [internal/handler/server.go:72](../../internal/handler/server.go#L72):

```go
	s.authMW = NewAuthMiddleware(s.authCache, verifier, s.workstationBranchID, s.seenBuffer)
```

(thứ tự khởi tạo phải là: `seenBuffer` trước `authMW`)

- [ ] **Step 3.8: Update test fixture nếu break**

```bash
go test ./internal/handler/ -v -run TestAuth
```

Nếu fail vì `NewAuthMiddleware` signature đổi, sửa [internal/handler/auth_middleware_test.go:29](../../internal/handler/auth_middleware_test.go#L29) thêm `nil` làm tham số cuối:

```go
mw := NewAuthMiddleware(cache, verifier, func() string { return wsBranch }, nil)
```

Tương tự cho [internal/handler/local_kiosk_test.go:37](../../internal/handler/local_kiosk_test.go#L37) và các test khác dùng `NewAuthMiddleware`.

- [ ] **Step 3.9: Run all handler tests**

```bash
go test ./internal/handler/ -v
```

Expected: all PASS.

- [ ] **Step 3.10: Commit**

```bash
git add internal/service/device_seen_buffer.go internal/service/device_seen_buffer_test.go \
        internal/handler/server.go internal/handler/auth_middleware.go \
        internal/handler/auth_middleware_test.go internal/handler/local_kiosk_test.go
git commit -m "feat(seen-buffer): coalesce device heartbeat writes in 30s windows"
```

---

## Task 4: SQLite Snapshot Backup Job

**Vấn đề:** Không có backup. Nếu file `.db` hỏng (đĩa lỗi, kill -9 giữa lúc WAL chưa flush), mất tất cả orders chưa sync.

**Approach:** Dùng `VACUUM INTO 'path'` — SQLite-native, an toàn với writer đang chạy, atomic. Giữ 7 snapshot (rotate).

**Files:**
- Modify: `internal/service/maintenance.go` (thêm method SnapshotOnce)
- Modify: `internal/service/maintenance_test.go`
- Modify: `internal/handler/server.go` (config)

- [ ] **Step 4.1: Write failing test**

Append vào `internal/service/maintenance_test.go`:

```go
import (
	// ... existing imports
	"os"
)

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
		time.Sleep(10 * time.Millisecond) // ensure distinct mtimes
	}

	entries, _ := os.ReadDir(backupDir)
	if len(entries) != 2 {
		t.Fatalf("expected 2 backups after rotation, got %d", len(entries))
	}
}
```

- [ ] **Step 4.2: Run tests — expect FAIL**

```bash
go test ./internal/service/ -run TestMaintenance_Snapshot -v
```

Expected: `unknown field BackupDir`.

- [ ] **Step 4.3: Extend `MaintenanceConfig` + add SnapshotOnce + Run loop**

Sửa `internal/service/maintenance.go`:

```go
type MaintenanceConfig struct {
	CheckpointInterval time.Duration
	SyncQueueInterval  time.Duration
	AuditInterval      time.Duration
	SyncQueueKeep      time.Duration
	AuditKeep          time.Duration

	BackupInterval     time.Duration // default 6h
	BackupDir          string        // empty disables backups
	BackupKeep         int           // default 7
}
```

Trong `NewMaintenance` thêm defaults:

```go
	if cfg.BackupInterval == 0 {
		cfg.BackupInterval = 6 * time.Hour
	}
	if cfg.BackupKeep == 0 {
		cfg.BackupKeep = 7
	}
```

Thêm method `SnapshotOnce`:

```go
// SnapshotOnce writes a copy of the live DB to BackupDir using
// `VACUUM INTO`, then rotates older files keeping at most BackupKeep.
// No-op (returns "", nil) if BackupDir is empty.
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
	type f struct {
		name string
		mod  time.Time
	}
	var files []f
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
		files = append(files, f{e.Name(), fi.ModTime()})
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
```

Thêm imports: `"fmt"`, `"os"`, `"path/filepath"`, `"sort"`, `"strings"`.

Trong `Start`, thêm job thứ 4:

```go
	go m.loop(ctx, m.cfg.BackupInterval, "backup", func() error {
		path, err := m.SnapshotOnce()
		if err == nil && path != "" {
			slog.Info("snapshot written", "path", path)
		}
		return err
	})
```

- [ ] **Step 4.4: Run tests**

```bash
go test ./internal/service/ -run TestMaintenance_Snapshot -v
```

Expected: 2 PASS.

- [ ] **Step 4.5: Wire BackupDir trong server.go**

Sửa [internal/handler/server.go](../../internal/handler/server.go) chỗ khởi tạo maintenance:

```go
	backupDir := filepath.Join(filepath.Dir(s.db.Path()), "backups")
	s.maintenance = service.NewMaintenance(s.db, service.MaintenanceConfig{
		BackupDir: backupDir,
	})
```

Thêm import `"path/filepath"` nếu chưa có.

- [ ] **Step 4.6: Smoke test**

```bash
go build ./...
./bin/ws-app &
sleep 2
# Trigger manual snapshot via Go test:
go test ./internal/service/ -run TestMaintenance_SnapshotCreatesFile -v
kill %1
ls -la ~/.workstation-app/backups/
```

Expected: ít nhất 1 file `snapshot-YYYYMMDD-HHMMSS.db`.

- [ ] **Step 4.7: Commit**

```bash
git add internal/service/maintenance.go internal/service/maintenance_test.go internal/handler/server.go
git commit -m "feat(backup): rotating SQLite snapshots via VACUUM INTO"
```

---

## Task 5: GitHub Actions CI Workflow

**Vấn đề:** `.github/workflows/` không tồn tại — mọi release dựa vào `make build-all` thủ công, không có test gate.

**Files:**
- Create: `.github/workflows/ci.yml`
- Modify: `Makefile` (thêm `ci-local`)

- [ ] **Step 5.1: Tạo `.github/workflows/ci.yml`**

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  lint:
    name: Lint
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-go@v5
        with:
          go-version: "1.25"
          cache: true
      - name: go vet
        run: go vet ./...
      - name: gofmt
        run: |
          unformatted=$(gofmt -l .)
          if [ -n "$unformatted" ]; then
            echo "Files need gofmt:"
            echo "$unformatted"
            exit 1
          fi

  test:
    name: Test
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-go@v5
        with:
          go-version: "1.25"
          cache: true
      - name: go test
        run: go test -race -timeout=5m ./...

  build:
    name: Build
    needs: [lint, test]
    runs-on: ubuntu-latest
    strategy:
      matrix:
        include:
          - goos: linux
            goarch: amd64
          - goos: linux
            goarch: arm64
          - goos: darwin
            goarch: amd64
          - goos: darwin
            goarch: arm64
          - goos: windows
            goarch: amd64
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-go@v5
        with:
          go-version: "1.25"
          cache: true
      - name: Setup pnpm
        uses: pnpm/action-setup@v4
        with:
          version: 9
      - uses: actions/setup-node@v4
        with:
          node-version: "20"
          cache: pnpm
          cache-dependency-path: frontend/pnpm-lock.yaml
      - name: Build frontend
        working-directory: frontend
        run: |
          pnpm install --frozen-lockfile
          pnpm build
      - name: Build binary
        env:
          GOOS: ${{ matrix.goos }}
          GOARCH: ${{ matrix.goarch }}
          CGO_ENABLED: "0"
        run: |
          mkdir -p build/bin
          ext=""
          if [ "${{ matrix.goos }}" = "windows" ]; then ext=".exe"; fi
          go build -o build/bin/ws-app-${{ matrix.goos }}-${{ matrix.goarch }}${ext} ./cmd/workstation
      - uses: actions/upload-artifact@v4
        with:
          name: ws-app-${{ matrix.goos }}-${{ matrix.goarch }}
          path: build/bin/
          retention-days: 7
```

- [ ] **Step 5.2: Thêm `make ci-local` target**

Sửa `Makefile`, thêm vào cuối:

```makefile
.PHONY: ci-local
ci-local:
	@echo "==> go vet"
	@go vet ./...
	@echo "==> gofmt check"
	@unformatted=$$(gofmt -l .); \
	if [ -n "$$unformatted" ]; then \
		echo "Files need gofmt:"; echo "$$unformatted"; exit 1; \
	fi
	@echo "==> go test -race"
	@go test -race -timeout=5m ./...
	@echo "==> build current platform"
	@$(MAKE) build
```

- [ ] **Step 5.3: Chạy `make ci-local` local**

```bash
make ci-local
```

Expected: tất cả 4 step pass.

- [ ] **Step 5.4: Push branch + verify CI**

```bash
git add .github/workflows/ci.yml Makefile
git commit -m "ci: scaffold GitHub Actions for lint/test/build matrix"
git push origin HEAD
```

Mở GitHub Actions tab, verify:
- lint job xanh
- test job xanh (kể cả `-race`)
- build matrix 5 platforms xanh
- artifacts uploaded

Nếu fail: fix root cause, không thêm `continue-on-error`.

---

## Task 6: End-to-End Sprint Verification

**Mục đích:** chạy full server 4 giờ với load giả lập, verify các metric pass acceptance criteria.

**Files:**
- Create: `scripts/sprint1-soak.sh` (test script tạm thời, không commit dài hạn)

- [ ] **Step 6.1: Viết soak test script**

Create `scripts/sprint1-soak.sh`:

```bash
#!/usr/bin/env bash
# Sprint 1 soak test: 4-hour run with light traffic, verify ops metrics.
set -euo pipefail

DB="${WS_APP_DB:-$HOME/.workstation-app/workstation-app.db}"
LOG=/tmp/ws-app-soak.log

echo "==> Starting ws-app..."
./bin/ws-app > "$LOG" 2>&1 &
WS_PID=$!
trap "kill $WS_PID 2>/dev/null || true" EXIT
sleep 3

echo "==> Generating 1000 req/min for 4h..."
END=$(($(date +%s) + 14400))
while [ "$(date +%s)" -lt "$END" ]; do
  for _ in $(seq 1 16); do
    curl -fsS http://localhost:8080/api/status > /dev/null &
  done
  wait
  sleep 1
done

echo "==> Checking metrics..."
WAL_SIZE=$(stat -f%z "${DB}-wal" 2>/dev/null || stat -c%s "${DB}-wal" 2>/dev/null || echo 0)
echo "WAL size: $WAL_SIZE bytes"
[ "$WAL_SIZE" -lt 52428800 ] || { echo "FAIL: WAL > 50 MB"; exit 1; }

AUDIT_COUNT=$(sqlite3 "$DB" "SELECT COUNT(*) FROM audit_log;")
echo "audit_log rows: $AUDIT_COUNT"

SYNC_COUNT=$(sqlite3 "$DB" "SELECT COUNT(*) FROM sync_queue;")
echo "sync_queue rows: $SYNC_COUNT"

BACKUPS=$(ls "$HOME/.workstation-app/backups/" 2>/dev/null | wc -l)
echo "backups: $BACKUPS"
[ "$BACKUPS" -ge 1 ] || { echo "FAIL: no backups"; exit 1; }

if grep -q "panic\|fatal" "$LOG"; then
  echo "FAIL: panic/fatal in log"
  grep -E "panic|fatal" "$LOG"
  exit 1
fi
echo "==> PASS"
```

`chmod +x scripts/sprint1-soak.sh`.

- [ ] **Step 6.2: Chạy soak test**

```bash
make build
./scripts/sprint1-soak.sh
```

Expected: kết thúc với `==> PASS`.

- [ ] **Step 6.3: Commit & close sprint**

```bash
git add scripts/sprint1-soak.sh
git commit -m "test: add Sprint 1 4-hour soak verification script"

# Tag the sprint completion
git tag sprint-1-complete
```

- [ ] **Step 6.4: Update `docs/plan/SYNC_DOWN_BLOCKED.md`**

Sửa header `Status:` thành `Status: READY TO IMPLEMENT (ops gates landed in Sprint 1, see 03-sprint-1-ops-hardening.md)`.

- [ ] **Step 6.5: Sprint retrospective (verbal, not code)**

Notes cho PM:
- Tasks done vs estimate (ước lượng 3 ngày)
- Surprises (eg. test fixture cascade từ Task 3)
- Items push sang Sprint 2 (Prometheus metrics, Reverb subscribe, pairing UI)

---

## Tổng kết deliverables Sprint 1

| Task | File mới | File sửa | Test mới |
|---|---|---|---|
| 1. Auth cache cleanup wire | 0 | 1 | 0 |
| 2. SQLite maintenance + pool | 2 | 2 | 3 |
| 3. Device seen buffer | 2 | 2 | 3 |
| 4. SQLite snapshot backup | 0 | 3 | 2 |
| 5. CI workflow | 1 | 1 | 0 |
| 6. Soak verification | 1 | 1 | 0 |
| **Tổng** | **6** | **10** | **8** |

**Risk còn lại sau Sprint 1 (chuyển sang Sprint 2):**
- Prometheus `/metrics` endpoint
- Reverb subscribe để invalidate menu cache real-time
- Pairing UI cho LAN child devices
- Handler test coverage > 50% (Sprint 1 chỉ tăng từ ~13% lên ~25%)
- Delta menu sync `GET /workstation/menu/changes?since=`

---

## Verification commands (chạy bất cứ lúc nào trong sprint)

```bash
# Build + test
go build ./... && go test -race ./...

# Lint
go vet ./... && gofmt -l .

# Manual sanity (sau Task 2 trở đi)
./bin/ws-app &
sleep 5
sqlite3 ~/.workstation-app/workstation-app.db <<'SQL'
.headers on
SELECT name FROM sqlite_master WHERE type='table' AND name IN ('auth_token_cache','sync_queue','audit_log','devices');
SELECT COUNT(*) AS pending_sync FROM sync_queue WHERE synced_at IS NULL;
SELECT COUNT(*) AS cached_tokens FROM auth_token_cache;
SQL
kill %1
```
