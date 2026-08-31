# Sprint 2 — Bidirectional Sync (Lots Pull + Orders Recovery)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bổ sung 2 luồng sync chiến lược cho production readiness — (1) Pull inventory lots về local để workstation đối soát tồn kho; (2) Pull lịch sử orders khi re-pair để workstation crash-and-restore không mất dữ liệu.

**Architecture:** Mỗi cửa hàng có **1 workstation duy nhất** (single-writer) → không cần conflict resolution. Cloud `customer_orders` là mirror authoritative cho HQ visibility + recovery. Workstation pull orders **chỉ 1 lần khi recovery** (paired_at thay đổi, last_sync_at empty), sau đó tiếp tục single-writer pattern như Sprint 1. Lots pull định kỳ (5 phút) để theo dõi inventory level.

**Tech Stack:** Go 1.25 / SQLite (workstation), PHP 8.4 / Laravel 13 / Pest 4 (backend). Không thêm dependency mới.

**Sprint scope:** 2 dev × 2.5 ngày (~20h effort). P0 ship được trong tuần. P1 carry-over từ Sprint 1 final review — defer Sprint 3.

**Định nghĩa "done" Sprint 2:**
- E2E: workstation pair → tạo 3 orders → xoá `~/.ws-app/` → re-pair với code mới → orders + lots tự pull về (verified bằng DB count)
- Backend Pest tests pass cho 3 endpoints mới (`/orders` GET, `/lots` đã có)
- Workstation Go test pass cho recovery flow + lots upsert
- All Sprint 1 tests still green

---

## Background — Sprint 1 đã có gì

Sau Sprint 1 + 9 bug fixes, workstation hiện đã pull được:

| Endpoint | Cadence | Local table |
|---|---|---|
| `GET /api/v1/tms/zones` | 60s | `zones` |
| `GET /api/v1/tms/tables` | 60s | `tables` |
| `GET /api/v1/workstation/menu` | 60s | `menu_items` (flat) |
| `GET /api/v1/workstation/branch` | 5min | `settings` (tax_rate, currency) |

**Gap:** không pull lots (inventory) và không pull orders. Re-pair = mất orders lịch sử.

---

## File Structure

| File | Trạng thái | Trách nhiệm |
|---|---|---|
| `backend/app/Http/Controllers/Api/V1/Workstation/OrderController.php` | **MODIFY** — thêm `index()` method | `GET /workstation/orders?since=&limit=` trả orders branch device, gồm items |
| `backend/routes/api/workstation.php` | **MODIFY** | Thêm route `Route::get('orders', [OrderController::class, 'index'])` |
| `backend/tests/Feature/Workstation/WorkstationOrdersListTest.php` | **NEW** | Pest tests: auth required, branch filter, since/limit, items embedded |
| `internal/service/sync_pull.go` | **MODIFY** | Wire pull cho `/lots` (định kỳ 5 phút) + recovery `/orders` (1 lần khi `last_sync_at` empty) |
| `internal/service/sync_pull_test.go` | **MODIFY** | Thêm test cho 2 puller mới |
| `internal/store/migrations/006_recovery_state.sql` | **NEW** | Add columns: `orders.cloud_origin` (TEXT), `inventory_lots` table |
| `internal/handler/routes.go` | **MODIFY** (small) | Set `last_sync_at = ''` khi `handleDevicePair` thành công lần đầu (sentinel for recovery trigger) |

---

## Task 1 — Wire Lots Pull (P0, ~30 min)

**Background:** Backend đã có `GET /api/v1/workstation/lots` (xem `routes/api/workstation.php`). Workstation chưa wire — endpoint dead. Pull định kỳ 5 phút (slow interval, vì lots không update liên tục).

### Files
- Create: `internal/store/migrations/006_recovery_state.sql` (chỉ phần `inventory_lots` table)
- Modify: `internal/service/sync_pull.go`
- Modify: `internal/service/sync_pull_test.go`

### Step 1.1 — Tạo migration cho `inventory_lots`

Create `internal/store/migrations/006_recovery_state.sql`:

```sql
-- Inventory lots mirrored from Cloud /api/v1/workstation/lots.
-- Read-only on workstation side — Cloud is source of truth for stock movements.
CREATE TABLE IF NOT EXISTS inventory_lots (
    id              TEXT PRIMARY KEY,
    material_id     TEXT NOT NULL,
    material_name   TEXT,
    warehouse_id    TEXT,
    warehouse_name  TEXT,
    quantity        REAL NOT NULL DEFAULT 0,
    unit            TEXT,
    expires_at      TEXT,
    status          TEXT,
    cloud_updated_at TEXT,
    local_synced_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_inventory_lots_material ON inventory_lots(material_id);
CREATE INDEX IF NOT EXISTS idx_inventory_lots_expires ON inventory_lots(expires_at);
```

### Step 1.2 — Wire vào `sync_pull.go`

Find existing pull paths block (~line 30):

```diff
 const (
     pullIntervalFast = 60 * time.Second
-    pullIntervalSlow = 5 * time.Minute
+    pullIntervalSlow = 5 * time.Minute  // branch, lots
 
     pullPathZones  = "/api/v1/tms/zones"
     pullPathTables = "/api/v1/tms/tables"
     pullPathMenu   = "/api/v1/workstation/menu"
     pullPathBranch = "/api/v1/workstation/branch"
+    pullPathLots   = "/api/v1/workstation/lots"
 )
```

Add `pullLots()` method (sau `pullBranch`):

```go
func (p *SyncPuller) pullLots(ctx context.Context) error {
    var resp struct {
        Lots []struct {
            ID            string  `json:"id"`
            MaterialID    string  `json:"material_id"`
            MaterialName  string  `json:"material_name"`
            WarehouseID   string  `json:"warehouse_id"`
            WarehouseName string  `json:"warehouse_name"`
            Quantity      float64 `json:"quantity"`
            Unit          string  `json:"unit"`
            ExpiresAt     string  `json:"expires_at"`
            Status        string  `json:"status"`
            UpdatedAt     string  `json:"updated_at"`
        } `json:"lots"`
    }
    if err := p.cloudGet(ctx, pullPathLots, &resp); err != nil {
        return err
    }
    return p.db.Transaction(func(tx *sql.Tx) error {
        stmt, err := tx.Prepare(`
            INSERT INTO inventory_lots
              (id, material_id, material_name, warehouse_id, warehouse_name,
               quantity, unit, expires_at, status, cloud_updated_at, local_synced_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
            ON CONFLICT(id) DO UPDATE SET
              quantity         = excluded.quantity,
              status           = excluded.status,
              cloud_updated_at = excluded.cloud_updated_at,
              local_synced_at  = datetime('now')
        `)
        if err != nil {
            return err
        }
        defer stmt.Close()
        for _, lot := range resp.Lots {
            if _, err := stmt.Exec(lot.ID, lot.MaterialID, lot.MaterialName,
                nullableString(lot.WarehouseID), nullableString(lot.WarehouseName),
                lot.Quantity, nullableString(lot.Unit),
                nullableString(lot.ExpiresAt), nullableString(lot.Status),
                nullableString(lot.UpdatedAt)); err != nil {
                return err
            }
        }
        return nil
    })
}
```

In the `slowTick` loop (currently only pulls branch), add lots:

```diff
 case <-slowTick.C:
     if err := p.pullBranch(ctx); err != nil {
         slog.Warn("sync_pull branch", "err", err)
     }
+    if err := p.pullLots(ctx); err != nil {
+        slog.Warn("sync_pull lots", "err", err)
+    }
```

### Step 1.3 — Test

Append to `internal/service/sync_pull_test.go`:

```go
func TestSyncPuller_PullLotsUpsertsRows(t *testing.T) {
    dir := t.TempDir()
    db, _ := store.Open(filepath.Join(dir, "test.db"))
    defer db.Close()

    // Mock cloud server returning 2 lots
    srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
        if r.URL.Path != "/api/v1/workstation/lots" {
            t.Errorf("unexpected path: %s", r.URL.Path)
        }
        w.Header().Set("Content-Type", "application/json")
        w.Write([]byte(`{"lots":[
            {"id":"lot1","material_id":"m1","material_name":"Beef","quantity":50,"unit":"kg","status":"active"},
            {"id":"lot2","material_id":"m2","material_name":"Rice","quantity":100,"unit":"kg","status":"active"}
        ],"generated_at":"2026-05-21T10:00:00Z"}`))
    }))
    defer srv.Close()

    p := NewSyncPuller(db, srv.URL, func() string { return "fake-token" })
    if err := p.pullLots(context.Background()); err != nil {
        t.Fatalf("pullLots: %v", err)
    }

    var count int
    _ = db.QueryRow(`SELECT COUNT(*) FROM inventory_lots`).Scan(&count)
    if count != 2 {
        t.Fatalf("expected 2 lots, got %d", count)
    }
}
```

### Step 1.4 — Commit

```bash
go test -race ./internal/service/ -run TestSyncPuller_PullLots -v
git add internal/store/migrations/006_recovery_state.sql internal/service/sync_pull.go internal/service/sync_pull_test.go
git commit -m "feat(sync): pull /workstation/lots into local inventory_lots (5min)"
```

NO Co-Authored-By.

---

## Task 2 — Backend: GET /workstation/orders endpoint (P0, ~3-4h)

**Background:** Workstation paired lần đầu hoặc sau re-pair cần pull lịch sử orders (mặc định 30 ngày). Backend hiện chỉ có `POST /workstation/orders` (push), thiếu GET.

### Files
- Modify: `backend/app/Http/Controllers/Api/V1/Workstation/OrderController.php`
- Modify: `backend/routes/api/workstation.php`
- Create: `backend/tests/Feature/Workstation/WorkstationOrdersListTest.php`

### Step 2.1 — Add `index()` method to `OrderController`

```php
#[OA\Get(
    path: '/api/v1/workstation/orders',
    summary: 'List recent orders for the device branch (recovery + read)',
    description: 'Pulls orders the workstation can use to rebuild local state after a re-pair or crash. Filters by since (default 30 days ago) and limits 500 per call. Returns nested order_items.',
    tags: ['Workstation'],
    security: [['device_token' => []]],
    parameters: [
        new OA\Parameter(name: 'since', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 1000)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'List of orders with items embedded'),
        new OA\Response(response: 401, description: 'Missing/invalid device token'),
    ],
)]
public function index(Request $request): JsonResponse
{
    $device = $request->attributes->get('device');

    $since = $request->query('since')
        ? Carbon::parse($request->query('since'))
        : now()->subDays(30);
    $limit = min((int) $request->query('limit', 500), 1000);

    $orders = CustomerOrder::query()
        ->where('branch_id', $device->branch_id)
        ->where('created_at', '>=', $since)
        ->with(['items:id,customer_order_id,product_sku_id,quantity,unit_price,notes'])
        ->orderBy('created_at', 'desc')
        ->limit($limit)
        ->get();

    return response()->json([
        'data' => $orders,
        'count' => $orders->count(),
        'since' => $since->toIso8601String(),
        'generated_at' => now()->toIso8601String(),
    ]);
}
```

### Step 2.2 — Add route

In `backend/routes/api/workstation.php`:

```diff
 Route::prefix('v1/workstation')
     ->middleware(['device.auth:workstation', 'throttle:60,1'])
     ->group(function () {
         Route::get('lots',     [LotController::class,    'index'])->name('api.v1.workstation.lots');
         Route::get('menu',     [MenuController::class,   'index'])->name('api.v1.workstation.menu');
         Route::get('branch',   [BranchController::class, 'show'])->name('api.v1.workstation.branch');
+        Route::get('orders',   [OrderController::class,  'index'])->name('api.v1.workstation.orders.index');
         Route::post('orders',  [OrderController::class,  'store'])->name('api.v1.workstation.orders.store');
     });
```

### Step 2.3 — Pest tests

Create `backend/tests/Feature/Workstation/WorkstationOrdersListTest.php`:

```php
<?php

use App\Models\CustomerOrder;
use App\Models\Device;
use Illuminate\Support\Carbon;

it('requires device token', function () {
    $this->getJson('/api/v1/workstation/orders')->assertUnauthorized();
});

it('returns orders for device branch only', function () {
    [$device, $otherDevice] = createWorkstationDevicesAcrossTwoBranches();

    CustomerOrder::factory()->count(3)->create(['branch_id' => $device->branch_id]);
    CustomerOrder::factory()->count(2)->create(['branch_id' => $otherDevice->branch_id]);

    $this->withDeviceToken($device)
        ->getJson('/api/v1/workstation/orders')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('filters by since', function () {
    $device = createPairedWorkstationDevice();
    CustomerOrder::factory()->create([
        'branch_id' => $device->branch_id,
        'created_at' => now()->subDays(60),
    ]);
    CustomerOrder::factory()->create([
        'branch_id' => $device->branch_id,
        'created_at' => now()->subDays(5),
    ]);

    $this->withDeviceToken($device)
        ->getJson('/api/v1/workstation/orders?since=' . now()->subDays(30)->toIso8601String())
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('respects limit param', function () {
    $device = createPairedWorkstationDevice();
    CustomerOrder::factory()->count(10)->create(['branch_id' => $device->branch_id]);

    $this->withDeviceToken($device)
        ->getJson('/api/v1/workstation/orders?limit=5')
        ->assertOk()
        ->assertJsonCount(5, 'data');
});

it('embeds order items', function () {
    $device = createPairedWorkstationDevice();
    $order = CustomerOrder::factory()->hasItems(2)->create(['branch_id' => $device->branch_id]);

    $this->withDeviceToken($device)
        ->getJson('/api/v1/workstation/orders')
        ->assertOk()
        ->assertJsonPath('data.0.items.0.product_sku_id', $order->items[0]->product_sku_id)
        ->assertJsonCount(2, 'data.0.items');
});
```

Helpers `createWorkstationDevicesAcrossTwoBranches()`, `createPairedWorkstationDevice()`, `withDeviceToken()` follow patterns in existing `tests/Feature/Workstation/*` files.

### Step 2.4 — Verify

```bash
cd backend
php -d memory_limit=-1 vendor/bin/pest --compact --filter=WorkstationOrdersListTest
vendor/bin/pint --dirty --format agent
```

Expected: 5 tests pass, no pint changes.

### Step 2.5 — Commit (backend)

```bash
git add backend/app/Http/Controllers/Api/V1/Workstation/OrderController.php \
        backend/routes/api/workstation.php \
        backend/tests/Feature/Workstation/WorkstationOrdersListTest.php
git commit -m "feat(workstation): GET /orders for recovery + read after re-pair"
```

NO Co-Authored-By.

---

## Task 3 — Workstation: Recovery flow on pair (P0, ~3-4h)

**Background:** Khi workstation pair lần đầu HOẶC re-pair (paired_at thay đổi), trigger 1 pull lịch sử orders về local. Lần pull đó chỉ chạy 1 lần, không lặp.

### Files
- Modify: `internal/service/sync_pull.go` — thêm `Recover(ctx)` method
- Modify: `internal/handler/routes.go` — trigger Recover sau pair thành công
- Modify: `internal/service/sync_pull_test.go`

### Step 3.1 — Add `Recover()` method

Append to `internal/service/sync_pull.go`:

```go
// Recover pulls historical orders from Cloud once after a fresh pair or
// re-pair. Idempotent — safe to call multiple times; UPSERTs by cloud_id.
// Returns the number of orders restored.
func (p *SyncPuller) Recover(ctx context.Context, since time.Duration) (int, error) {
    sinceTS := time.Now().Add(-since).UTC().Format(time.RFC3339)
    path := fmt.Sprintf("/api/v1/workstation/orders?since=%s&limit=500", sinceTS)

    var resp struct {
        Data []struct {
            ID          string `json:"id"`
            OrderNumber int    `json:"order_number"`
            TableID     string `json:"table_id"`
            Status      string `json:"status"`
            Total       int    `json:"total"`
            Subtotal    int    `json:"subtotal"`
            Tax         int    `json:"tax"`
            Notes       string `json:"notes"`
            CreatedAt   string `json:"created_at"`
            UpdatedAt   string `json:"updated_at"`
            PaidAt      string `json:"paid_at"`
            Items       []struct {
                ProductSkuID string `json:"product_sku_id"`
                Quantity     int    `json:"quantity"`
                UnitPrice    int    `json:"unit_price"`
                Notes        string `json:"notes"`
            } `json:"items"`
        } `json:"data"`
        Count int `json:"count"`
    }
    if err := p.cloudGet(ctx, path, &resp); err != nil {
        return 0, err
    }

    restored := 0
    err := p.db.Transaction(func(tx *sql.Tx) error {
        orderStmt, err := tx.Prepare(`
            INSERT INTO orders
              (id, cloud_id, order_number, table_number, status,
               subtotal, tax, total, notes, paid_at, created_at, updated_at, synced_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
            ON CONFLICT(id) DO UPDATE SET
              status     = excluded.status,
              total      = excluded.total,
              paid_at    = excluded.paid_at,
              updated_at = excluded.updated_at,
              synced_at  = datetime('now')
        `)
        if err != nil {
            return err
        }
        defer orderStmt.Close()
        for _, o := range resp.Data {
            // Recovery rows use the cloud UUID as local id so subsequent
            // pushes via sync_queue don't create duplicates on Cloud.
            if _, err := orderStmt.Exec(
                o.ID, o.ID, o.OrderNumber, o.TableID, o.Status,
                o.Subtotal, o.Tax, o.Total, nullableString(o.Notes),
                nullableString(o.PaidAt), o.CreatedAt, o.UpdatedAt,
            ); err != nil {
                return err
            }
            restored++
        }
        return nil
    })
    return restored, err
}
```

### Step 3.2 — Trigger from `handleDevicePair`

In `internal/handler/routes.go` `handleDevicePair`, after the existing `s.seenBuffer.Register(...)` block, add:

```go
    // Recovery: if this is the first pair OR a re-pair after data loss,
    // pull historical orders from Cloud so audit trail + reports survive.
    // 30-day window is generous for restaurant operations; tune if needed.
    if s.puller != nil {
        go func() {
            // Fire-and-forget in background so the pair response returns fast.
            // Recover is idempotent — safe even if SyncPuller's regular loop
            // also fires concurrently.
            n, err := s.puller.Recover(context.Background(), 30*24*time.Hour)
            if err != nil {
                slog.Warn("recovery pull orders failed", "err", err, "device_id", cloudResp.Device.ID)
                return
            }
            if n > 0 {
                slog.Info("recovery restored orders", "count", n, "device_id", cloudResp.Device.ID)
            }
        }()
    }
```

Add `"context"` to imports if missing.

### Step 3.3 — Test

```go
func TestSyncPuller_RecoverUpsertsOrders(t *testing.T) {
    dir := t.TempDir()
    db, _ := store.Open(filepath.Join(dir, "test.db"))
    defer db.Close()

    srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
        if !strings.HasPrefix(r.URL.Path, "/api/v1/workstation/orders") {
            t.Errorf("unexpected path: %s", r.URL.Path)
        }
        w.Header().Set("Content-Type", "application/json")
        w.Write([]byte(`{"data":[
            {"id":"order1","order_number":1,"table_id":"A1","status":"paid","total":50000,"created_at":"2026-05-20T10:00:00Z","updated_at":"2026-05-20T10:30:00Z"},
            {"id":"order2","order_number":2,"table_id":"A2","status":"open","total":30000,"created_at":"2026-05-21T08:00:00Z","updated_at":"2026-05-21T08:00:00Z"}
        ],"count":2,"since":"2026-04-21T00:00:00Z","generated_at":"2026-05-21T10:00:00Z"}`))
    }))
    defer srv.Close()

    p := NewSyncPuller(db, srv.URL, func() string { return "fake-token" })
    n, err := p.Recover(context.Background(), 30*24*time.Hour)
    if err != nil {
        t.Fatalf("recover: %v", err)
    }
    if n != 2 {
        t.Fatalf("expected 2 restored, got %d", n)
    }

    // Idempotent re-run
    n2, err := p.Recover(context.Background(), 30*24*time.Hour)
    if err != nil {
        t.Fatalf("re-recover: %v", err)
    }
    if n2 != 2 {
        t.Fatalf("expected 2 on second run, got %d", n2)
    }
    var count int
    _ = db.QueryRow(`SELECT COUNT(*) FROM orders`).Scan(&count)
    if count != 2 {
        t.Fatalf("expected 2 rows total (UPSERT), got %d", count)
    }
}
```

### Step 3.4 — Commit (workstation)

```bash
go test -race ./internal/service/ -run TestSyncPuller_Recover -v
git add internal/service/sync_pull.go internal/service/sync_pull_test.go internal/handler/routes.go
git commit -m "feat(recovery): pull 30d order history on pair via SyncPuller.Recover"
```

NO Co-Authored-By.

---

## Task 4 — End-to-End Verification (P0, ~30 min)

**Goal:** Demo scenario "workstation crash → cài lại → orders quay về" — confirm Sprint 2 ship-ready.

### Steps

```bash
# 1. Pair workstation, tạo 3 orders local (qua UI hoặc curl)
curl -X POST http://localhost:8080/api/orders -H 'Content-Type: application/json' \
  -d '{"table_number":"A1","items":[{"menu_item_id":"<id1>","name":"Phở","quantity":2,"unit_price":50000,"printer_group":"kitchen"}]}'
# ... lặp 3 lần với items khác nhau

# 2. Đợi sync_queue push lên cloud (≤60s)
sqlite3 ~/.ws-app/ws-app.db "SELECT COUNT(*) FROM sync_queue WHERE synced_at IS NULL;"
# → 0

# 3. Verify cloud có orders
docker compose exec mysql mysql -uroot -psecret tempo -e \
  "SELECT id, order_number, status, total FROM customer_orders WHERE branch_id='<branch_id>' ORDER BY created_at DESC LIMIT 5;"
# → 3 orders ✓

# 4. SIMULATE CRASH: xoá ~/.ws-app/
pkill -9 -f "ws-app\|ws-server"
rm -rf ~/.ws-app

# 5. Restart workstation
./build/bin/ws-app &
sleep 3

# 6. Re-pair với code mới (admin web → regenerate hoặc lấy code khác)
curl -X POST http://localhost:8080/api/device/pair -d '{"pairing_code":"NEW_CODE"}'

# 7. Đợi 5 giây cho recovery goroutine fire
sleep 5

# 8. Verify orders restored
sqlite3 ~/.ws-app/ws-app.db "SELECT COUNT(*) FROM orders;"
# → 3 (recovery thành công)

# 9. Verify lots cũng pull về (sau ~5 phút hoặc fire manual)
sqlite3 ~/.ws-app/ws-app.db "SELECT COUNT(*) FROM inventory_lots;"
# → > 0 nếu branch có inventory data
```

If all assertions pass → **Sprint 2 P0 DONE** → tag `sprint-2-recovery-ready`.

---

## P1 carry-over từ Sprint 1 final review (DEFER Sprint 3)

Các item này em đã document trong Sprint 1 final review nhưng chưa fix — Sprint 2 không bao gồm:

| Item | Effort | Lý do defer |
|---|---|---|
| `sync.Once` trên `Server.Start()` (I1 / M4) | 30 min | Production chỉ call 1 lần, low risk |
| WaitGroup-based graceful shutdown | 1h | Shutdown-storm chỉ matter khi pilot rollout >5 stores |
| `goleak` integration test cho Server lifecycle | 1h | Nice-to-have observability |
| Strict `branchOK()` fail-close mode | 30 min | Đang fail-open, chấp nhận được khi 1 workstation/branch |
| `internal/store/migrate_e2e_test.go` (fresh DB boot) | 2h | Bug 1 (devices conflict) đã fix, không tái phát |
| Atomic migration rollback (per-migration transaction) | 1h | Same — defensive code |

Sprint 3 plan sẽ pickup các item này + thêm:
- Pull menu_sections / products / skus normalize (refactor menu_items flat → nested)
- Reverb subscribe cho `order.created` event (real-time mobile order → workstation)
- Backend `device.revoked` broadcast + workstation cache invalidation

---

## Self-Review Checklist

Trước khi đánh dấu Sprint 2 done:

- [ ] Backend `WorkstationOrdersListTest` 5/5 pass
- [ ] Workstation `TestSyncPuller_PullLots` + `TestSyncPuller_Recover` pass với `-race`
- [ ] Full backend Pest suite (`vendor/bin/pest --compact`) không có regression
- [ ] Full workstation Go suite (`go test -race ./...`) green
- [ ] E2E Task 4 ALL 9 steps pass — orders restore sau re-pair
- [ ] `vendor/bin/pint --dirty --format agent` clean
- [ ] Sprint 1 tag `sprint-1-pilot-ready` vẫn build được (không break backward compat)
- [ ] OpenAPI spec workstation cập nhật GET /orders endpoint
- [ ] DEMO.md section 3.7 thêm "Recovery demo flow" (optional)

## Execution

Em dùng inline execution (subagent-driven cũng OK). Order:
1. Task 1 (Lots — fastest, builds confidence)
2. Task 2 (Backend orders endpoint — unblocks Task 3)
3. Task 3 (Workstation recovery — depends on Task 2)
4. Task 4 (E2E verify — only after 1+2+3 done)

Commit per task. NO Co-Authored-By line.
