# Sprint 3 — Unpair Flow + Polish & Carry-overs

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement task-by-task.

**Goal:** Khoá các "feature half-done" còn lại trước GA (general availability) — primary: complete unpair flow (security + UI); secondary: Sprint 1/2 carry-overs (timezone seeder, strict branch check, sync.Once, etc.). Sau sprint này workstation chuẩn production-ready.

**Architecture:** Không thay đổi kiến trúc — chỉ hoàn thiện các flow đã tồn tại. Sprint 1 đã xây nền (auth + cache + ops), Sprint 2 đã xây 2-way sync (lots + recovery). Sprint 3 lấp các gap polish + security.

**Tech Stack:** Go 1.25 / SQLite / Wails (workstation), PHP 8.4 / Laravel 13 / Pest 4 (backend). Không thêm dependency.

**Sprint scope:** 2 dev × 3 ngày (~24h effort). P0 ship được trong tuần. P1 nice-to-have.

**Định nghĩa "done":**
- E2E: pair → tạo orders → unpair (qua UI button) → verify local sạch + cloud thấy `status=revoked`
- Backend Pest tests pass cho `/workstation/self-revoke` (3 tests)
- Migration e2e test phát hiện schema conflict trước khi merge
- `make ci-local` xanh, full `go test -race ./...` xanh

---

## Background — Sprint 1 + 2 đã có gì

| Sprint 1 | Sprint 2 |
|---|---|
| Auth middleware + cache + cloud verifier | Pull lots (5min) |
| WAL checkpoint + retention | Pull orders recovery (on pair) |
| Device seen buffer | Decimal handling (Laravel) |
| CI workflow + soak test | |
| 9 bug fixes | 1 bug fix |

→ Pair flow: production-grade ✅
→ Unpair flow: stub ⚠️ (bug-2026-05-21-02)
→ Sprint 1 carry-overs: pending

---

## File Structure

| File | Trạng thái | Trách nhiệm |
|---|---|---|
| `backend/app/Http/Controllers/Api/V1/Workstation/DeviceController.php` | **NEW** | `selfRevoke()` method — workstation calls with own token to revoke itself |
| `backend/routes/api/workstation.php` | **MODIFY** | Add `POST /workstation/self-revoke` route |
| `backend/tests/Feature/Workstation/WorkstationSelfRevokeTest.php` | **NEW** | Pest tests for self-revoke |
| `backend/database/seeders/MockDataSeeder.php` | **MODIFY** | Apply Cách 3 cho bug-2026-05-21-01 (timezone NULL fix) |
| `internal/handler/routes.go` | **MODIFY** | Rewrite `handleDeviceUnpair` — clear all keys, wipe tables, notify cloud |
| `internal/handler/server.go` | **MODIFY** | Make `Start()` idempotent với `sync.Once` (Sprint 1 carry-over) |
| `internal/handler/auth_middleware.go` | **MODIFY** | `branchOK()` strict mode flag — fail-close when branch unset |
| `internal/store/migrate_e2e_test.go` | **NEW** | Boot full migration chain (hand-written + omnify) on fresh DB, assert no SQL errors |
| `frontend/src/pages/Settings.tsx` | **MODIFY** (find existing or create) | Add "Unpair Workstation" destructive button |
| `frontend/src/lib/api.ts` | **MODIFY** | Add `unpairDevice()` API client |
| `docs/DEMO.md` | **MODIFY** | Add section 4 "Unpair flow" + screenshot |

---

## Task 1 — Backend self-revoke endpoint (P0, ~2h)

**Files:** 
- Create: `backend/app/Http/Controllers/Api/V1/Workstation/DeviceController.php`
- Modify: `backend/routes/api/workstation.php`
- Create: `backend/tests/Feature/Workstation/WorkstationSelfRevokeTest.php`

### Step 1.1 — DeviceController

```php
<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Omnify\Enums\DeviceStatusEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DeviceController extends Controller
{
    #[OA\Post(
        path: '/api/v1/workstation/self-revoke',
        summary: 'Workstation revokes its own pairing (logout)',
        description: 'Called by workstation when user clicks Unpair. Marks device status=revoked and clears device_token + paired_at. Idempotent — re-call returns 200. After this, the device token no longer authenticates against any /workstation/* or /kiosk/* endpoint.',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Device revoked.'),
            new OA\Response(response: 401, description: 'Missing/invalid device token'),
        ],
    )]
    public function selfRevoke(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');

        $device->update([
            'status' => DeviceStatusEnum::Revoked->value,
            'device_token' => null,
            'paired_at' => null,
            'last_seen_at' => now(),
        ]);

        return response()->json([
            'status' => 'revoked',
            'device_id' => $device->id,
        ], 200);
    }
}
```

### Step 1.2 — Route

```diff
 Route::prefix('v1/workstation')
     ->middleware(['device.auth:workstation', 'throttle:60,1'])
     ->group(function () {
         Route::get('lots', [LotController::class, 'index'])->name('api.v1.workstation.lots');
         Route::get('menu', [MenuController::class, 'index'])->name('api.v1.workstation.menu');
         Route::get('branch', [BranchController::class, 'show'])->name('api.v1.workstation.branch');
         Route::get('orders', [OrderController::class, 'index'])->name('api.v1.workstation.orders.index');
         Route::post('orders', [OrderController::class, 'store'])->name('api.v1.workstation.orders.store');
+        Route::post('self-revoke', [DeviceController::class, 'selfRevoke'])->name('api.v1.workstation.self-revoke');
     });
```

### Step 1.3 — Pest tests

```php
<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Omnify\Enums\DeviceStatusEnum;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->wsToken = Str::random(64);
    $this->wsDevice = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

it('requires a device token', function () {
    $this->postJson('/api/v1/workstation/self-revoke')->assertUnauthorized();
});

it('revokes the calling device and clears token', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
    ])->postJson('/api/v1/workstation/self-revoke');

    $response->assertOk()->assertJsonPath('status', 'revoked');

    $this->wsDevice->refresh();
    expect($this->wsDevice->status->value)->toBe(DeviceStatusEnum::Revoked->value);
    expect($this->wsDevice->device_token)->toBeNull();
    expect($this->wsDevice->paired_at)->toBeNull();
});

it('rejects calls with the revoked token', function () {
    // First revoke
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/self-revoke')
        ->assertOk();

    // Second call with same (now-revoked) token → 401
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu')
        ->assertUnauthorized();
});
```

### Step 1.4 — Verify

```bash
cd backend
vendor/bin/pint --dirty --format agent
php -d memory_limit=-1 vendor/bin/pest --compact --filter=WorkstationSelfRevoke
# expected: 3 pass
```

### Step 1.5 — Commit

```bash
git add backend/app/Http/Controllers/Api/V1/Workstation/DeviceController.php \
        backend/routes/api/workstation.php \
        backend/tests/Feature/Workstation/WorkstationSelfRevokeTest.php
git commit -m "feat(workstation): POST /self-revoke endpoint for unpair flow"
```

NO Co-Authored-By.

---

## Task 2 — Workstation unpair rewrite (P0, ~3h)

**Files:** Modify `internal/handler/routes.go`, add helper in `internal/store/db.go` (optional table-wipe transaction)

### Step 2.1 — Capture identity before clearing

Find `handleDeviceUnpair` and rewrite per [bug-2026-05-21-02.md Fix Design](../bugs/2026-05-21-incomplete-unpair-flow.md).

Key points:
- Capture `device_id`, `branch_id`, `device_token` BEFORE clearing
- Cloud notify in goroutine (don't block unpair if cloud down)
- Single transaction: clear 8 settings keys + DELETE FROM 10 tables
- Audit log with non-empty entity_id + branch context

### Step 2.2 — Add cloud notify helper

```go
func (s *Server) notifyCloudUnpair(token string) {
    cloudURL := s.cloudAPIURL()
    if cloudURL == "" {
        return
    }
    req, _ := http.NewRequest("POST", cloudURL+"/api/v1/workstation/self-revoke", nil)
    req.Header.Set("Authorization", "Bearer "+token)
    req.Header.Set("Accept", "application/json")
    
    client := &http.Client{Timeout: 5 * time.Second}
    resp, err := client.Do(req)
    if err != nil {
        slog.Warn("cloud self-revoke failed", "err", err)
        return
    }
    defer resp.Body.Close()
    if resp.StatusCode != http.StatusOK {
        slog.Warn("cloud self-revoke non-200", "status", resp.StatusCode)
    } else {
        slog.Info("cloud self-revoke success")
    }
}
```

### Step 2.3 — Tests

Add to `internal/handler/auth_middleware_test.go` (or new file):

```go
func TestUnpairClearsAllSettingsKeys(t *testing.T) {
    // ... seed all 8 settings keys with non-empty values
    // ... call handleDeviceUnpair via httptest
    // ... assert SELECT COUNT(*) FROM settings WHERE value != '' AND key != 'cloud_api_url' = 0
}

func TestUnpairWipesLocalMirrorTables(t *testing.T) {
    // ... INSERT 5 orders, 10 menu_items, 3 zones, etc.
    // ... call unpair
    // ... assert COUNT = 0 for each table
}

func TestUnpairAuditLogIncludesDeviceID(t *testing.T) {
    // ... assert audit_log row has non-empty entity_id
}
```

### Step 2.4 — Commit

```bash
git add internal/handler/routes.go internal/handler/routes_test.go
git commit -m "fix(unpair): complete cleanup — settings + tables + cloud notify (bug-02)"
```

---

## Task 3 — Frontend Unpair UI (P0, ~1h)

**Files:** Modify `frontend/src/lib/api.ts`, find/create Settings page

### Step 3.1 — API client

```ts
// frontend/src/lib/api.ts
export async function unpairDevice(): Promise<void> {
  await post("/api/device/unpair");
}
```

### Step 3.2 — Settings page button

Find/create the settings page (likely `frontend/src/pages/Settings.tsx`):

```tsx
import { unpairDevice } from "../lib/api";
import { useNavigate } from "react-router";
import { Button } from "@godxjp/ui";

// Inside Settings component
const navigate = useNavigate();
const [unpairing, setUnpairing] = useState(false);

async function handleUnpair() {
  if (!confirm("Unpair workstation? Tất cả data local (orders, menu, devices) sẽ bị xoá.")) {
    return;
  }
  setUnpairing(true);
  try {
    await unpairDevice();
    navigate("/pair");  // route back to pairing UI
  } catch (err) {
    alert("Unpair failed: " + (err as Error).message);
  } finally {
    setUnpairing(false);
  }
}

// JSX
<Button variant="destructive" disabled={unpairing} onClick={handleUnpair}>
  {unpairing ? "Unpairing..." : "Unpair Workstation"}
</Button>
```

### Step 3.3 — Commit

```bash
git add frontend/src/lib/api.ts frontend/src/pages/Settings.tsx
git commit -m "feat(ui): Unpair Workstation button in Settings"
```

---

## Task 4 — E2E unpair verification (P0, ~30 min)

### Steps

```bash
# 1. Pair workstation, seed data
curl -X POST http://localhost:8080/api/device/pair -d '{"pairing_code":"X"}'
curl -X POST http://localhost:8080/api/menu/seed
# (optionally tạo orders)

# 2. Snapshot before
sqlite3 ~/.ws-app/ws-app.db "SELECT key,value FROM settings WHERE value != ''; SELECT COUNT(*) FROM orders, menu_items, zones, tables, devices, auth_token_cache;"

# 3. Unpair
curl -X POST http://localhost:8080/api/device/unpair

# 4. Verify local sạch (chỉ giữ cloud_api_url)
sqlite3 ~/.ws-app/ws-app.db <<'SQL'
SELECT 'settings non-empty:'||COUNT(*) FROM settings WHERE value != '' AND key != 'cloud_api_url';
-- → 0
SELECT 'orders:'||COUNT(*) FROM orders;             -- 0
SELECT 'menu_items:'||COUNT(*) FROM menu_items;     -- 0
SELECT 'devices:'||COUNT(*) FROM devices;           -- 0
SELECT 'auth_token_cache:'||COUNT(*) FROM auth_token_cache;  -- 0
SQL

# 5. Verify cloud status flipped
docker compose exec mysql mysql -uroot -psecret tempo -e \
  "SELECT name, status, IFNULL(device_token,'<null>') AS token FROM devices WHERE name='Workstation-POS' AND branch_id='<X-id>';"
# → status='revoked', token='<null>'
```

If all pass → Sprint 3 Task 1-4 DONE.

---

## Task 5 — Apply Cách 3 cho timezone bug (P0, ~30 min)

**Reference:** [docs/bugs/2026-05-21-branch-timezone-null.md](../bugs/2026-05-21-branch-timezone-null.md) — Cách 2 đã apply live, Cách 3 (seeder fix) chưa land.

### Step 5.1 — Apply diff

`backend/database/seeders/MockDataSeeder.php`, line ~226-240:

```diff
 Branch::updateOrCreate(
     ['console_branch_id' => $branch['console_branch_id']],
     [
         'console_organization_id' => self::ORG_CONSOLE_ID,
         'console_brand_id' => $brand?->console_brand_id,
         'slug' => $branch['slug'],
         'name' => $branch['name'],
+        'timezone' => 'Asia/Tokyo',
+        'currency' => 'JPY',
+        'locale' => 'ja',
         'is_headquarters' => $branch['is_headquarters'],
         ...
     ]
 );
```

### Step 5.2 — Verify

```bash
cd backend
docker compose exec app php artisan db:seed --class=MockDataSeeder --force
docker compose exec mysql mysql -uroot -psecret tempo -e \
  "SELECT name, timezone FROM branches;"
# → all branches Asia/Tokyo
vendor/bin/pint --dirty --format agent
```

### Step 5.3 — Update bug doc

Edit `docs/bugs/2026-05-21-branch-timezone-null.md`:
- Header: `Status: 🟡 LIVE PATCHED` → `Status: ✅ FIXED`
- Add `Fix commit:` line với SHA của commit này

### Step 5.4 — Commit

```bash
git add backend/database/seeders/MockDataSeeder.php workstation/docs/bugs/2026-05-21-branch-timezone-null.md
git commit -m "fix(seeders): set timezone/currency/locale on Japanese branches (bug-01 permanent fix)"
```

---

## Task 6 — Sprint 1 carry-overs (P1, ~3-4h tổng)

### 6a. `sync.Once` on `Server.Start()` — 30 phút

Sprint 1 final review flagged: nếu `Start()` gọi nhiều lần, spawn duplicate goroutines.

```diff
 type Server struct {
     ...
+    startOnce sync.Once
 }

 func (s *Server) Start() error {
+    var startErr error
+    s.startOnce.Do(func() {
         go s.hub.Run()
         if s.puller != nil { s.puller.Start() }
         go s.authCache.RunCleanupLoop(s.bgCtx, 10*time.Minute, 1*time.Hour)
         if s.maintenance != nil { s.maintenance.Start(s.bgCtx) }
         if s.seenBuffer != nil { go s.seenBuffer.Run(s.bgCtx, 30*time.Second) }
+        if err := s.httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
+            startErr = fmt.Errorf("server: %w", err)
+        }
+    })
+    return startErr
 }
```

Test: gọi `Start()` 2 lần → assert chỉ 1 set goroutines, no panic.

### 6b. Strict `branchOK()` fail-close — 30 phút

```diff
 func (m *AuthMiddleware) branchOK(deviceBranch string) bool {
     wsBranch := m.branchIDFn()
-    if wsBranch == "" || deviceBranch == "" {
-        return true
+    if wsBranch == "" {
+        // Workstation chưa pair hoặc unpair → reject all LAN auth.
+        slog.Warn("branch check fail-close: workstation not paired")
+        return false
+    }
+    if deviceBranch == "" {
+        slog.Warn("branch check fail-close: device has no branch", "device_branch", deviceBranch)
+        return false
     }
     return wsBranch == deviceBranch
 }
```

Test: gọi `/api/v1/kiosk/me` khi workstation unpaired → 403 (not 200).

### 6c. Migration e2e test — 1h

`internal/store/migrate_e2e_test.go`:

```go
package store_test

import (
    "path/filepath"
    "testing"
    workstation "github.com/dxs-platform/workstation-app"
    "github.com/dxs-platform/workstation-app/internal/store"
)

func TestMigrationsApplyFreshDB(t *testing.T) {
    store.OmnifyMigrations = &workstation.OmnifyMigrations
    db, err := store.Open(filepath.Join(t.TempDir(), "test.db"))
    if err != nil {
        t.Fatalf("Open: %v", err)
    }
    defer db.Close()

    // Smoke: every expected table exists post-migration.
    expectedTables := []string{
        "orders", "order_items", "menu_items", "settings",
        "audit_log", "auth_token_cache",
        "zones", "tables", "shop_settings",
        "printers",       // renamed from devices in 005
        "devices",        // omnify cloud devices
        "inventory_lots", // Sprint 2
    }
    for _, tbl := range expectedTables {
        var name string
        err := db.QueryRow(
            `SELECT name FROM sqlite_master WHERE type='table' AND name=?`, tbl,
        ).Scan(&name)
        if err != nil {
            t.Errorf("table missing: %s (%v)", tbl, err)
        }
    }
}
```

→ Catch bug-01 class trước khi merge.

### 6d. `goleak` integration test — 1h

Add `go.uber.org/goleak` (skip nếu user feedback memory không cho thêm deps — check first).

```go
// internal/handler/server_test.go
func TestServerStartStopNoGoroutineLeak(t *testing.T) {
    defer goleak.VerifyNone(t)
    
    deps := buildTestDeps(t)
    s := New(deps)
    go func() { _ = s.Start() }()
    time.Sleep(100 * time.Millisecond)
    if err := s.Stop(); err != nil {
        t.Fatalf("Stop: %v", err)
    }
}
```

### 6e. Server port safety + env override — 15 phút

**Background:** Sprint 1 fix `eef0bc2` thiết lập `cloud_api_url` default + `WS_APP_CLOUD_URL` env override. Nhưng `server_port` không có safety tương ứng — một khi config.json bị ghi sai port (vd. E2E test ghi 18080), không bao giờ tự về 8080. Frontend hardcode gọi `localhost:8080` → ERR_CONNECTION_REFUSED.

Đây là **bug-7 (cloud_api_url) class recurrence** — pattern "config drift after test ghi sai" tái diễn.

**Fix:** Mirror `defaultCloudURL()` pattern cho `server_port`.

#### Step 6e.1 — Update `internal/config/config.go`

```diff
 const (
     appDirName  = ".ws-app"
     configFile  = "config.json"
     credFile    = "credentials.json"
     defaultPort = 8080
     // defaultCloudAPIURL points at the umbrella's docker-compose backend
     defaultCloudAPIURL = "http://localhost:5400"
+    // Acceptable port range for ServerPort. Outside this → reset to default.
+    minValidPort = 1024
+    maxValidPort = 65535
 )

 func NewManager() (*Manager, error) {
     ...
     m := &Manager{
         dir: dir,
         config: Config{
-            ServerPort:  defaultPort,
+            ServerPort:  defaultServerPort(),
             TaxRate:     10,
             CloudAPIURL: defaultCloudURL(),
         },
     }

     if err := m.load(); err != nil && !os.IsNotExist(err) {
         return nil, err
     }

+    // Safety: if config.json has an invalid or non-default ServerPort
+    // (eg. left behind by an E2E test that wrote 18080), reset unless the
+    // operator explicitly set WS_APP_SERVER_PORT for this run.
+    if envPort := os.Getenv("WS_APP_SERVER_PORT"); envPort != "" {
+        if p, err := strconv.Atoi(envPort); err == nil && p >= minValidPort && p <= maxValidPort {
+            m.config.ServerPort = p
+        } else {
+            slog.Warn("invalid WS_APP_SERVER_PORT, falling back",
+                "value", envPort, "fallback", defaultPort)
+            m.config.ServerPort = defaultPort
+        }
+    } else if m.config.ServerPort < minValidPort || m.config.ServerPort > maxValidPort {
+        slog.Warn("ServerPort out of valid range, resetting to default",
+            "found", m.config.ServerPort, "default", defaultPort)
+        m.config.ServerPort = defaultPort
+    }
+
     if m.config.DatabasePath == "" {
         m.config.DatabasePath = filepath.Join(dir, "ws-app.db")
     }

     if m.config.CloudAPIURL == "" {
         m.config.CloudAPIURL = defaultCloudURL()
     }
     if err := m.save(); err != nil {
         return nil, err
     }

     return m, nil
 }

+// defaultServerPort resolves the HTTP server port:
+//   1. WS_APP_SERVER_PORT env (for E2E / multi-instance dev)
+//   2. defaultPort constant (8080 — matches frontend hardcoded URL)
+func defaultServerPort() int {
+    if v := os.Getenv("WS_APP_SERVER_PORT"); v != "" {
+        if p, err := strconv.Atoi(v); err == nil && p >= minValidPort && p <= maxValidPort {
+            return p
+        }
+    }
+    return defaultPort
+}
```

Add `"strconv"` to imports if missing.

#### Step 6e.2 — Test

Add to `internal/config/config_test.go` (create if not exists):

```go
package config

import (
    "os"
    "path/filepath"
    "testing"
)

func TestNewManagerResetsInvalidServerPort(t *testing.T) {
    dir := t.TempDir()
    t.Setenv("WS_APP_CONFIG_DIR", dir)

    // Seed config.json with bogus port (mimics leftover from E2E test).
    bad := `{"server_port": 18080, "cloud_api_url": "http://localhost:5400"}`
    if err := os.WriteFile(filepath.Join(dir, "config.json"), []byte(bad), 0o600); err != nil {
        t.Fatalf("seed config: %v", err)
    }

    m, err := NewManager()
    if err != nil {
        t.Fatalf("NewManager: %v", err)
    }
    if got := m.Get().ServerPort; got != 8080 {
        t.Fatalf("expected port reset to 8080, got %d", got)
    }
}

func TestNewManagerHonorsEnvOverride(t *testing.T) {
    dir := t.TempDir()
    t.Setenv("WS_APP_CONFIG_DIR", dir)
    t.Setenv("WS_APP_SERVER_PORT", "9090")

    m, err := NewManager()
    if err != nil {
        t.Fatalf("NewManager: %v", err)
    }
    if got := m.Get().ServerPort; got != 9090 {
        t.Fatalf("expected env override 9090, got %d", got)
    }
}

func TestNewManagerRejectsOutOfRangeEnv(t *testing.T) {
    dir := t.TempDir()
    t.Setenv("WS_APP_CONFIG_DIR", dir)
    t.Setenv("WS_APP_SERVER_PORT", "99999")  // > max

    m, err := NewManager()
    if err != nil {
        t.Fatalf("NewManager: %v", err)
    }
    if got := m.Get().ServerPort; got != 8080 {
        t.Fatalf("expected fallback to 8080 for invalid env, got %d", got)
    }
}
```

#### Step 6e.3 — Verify

```bash
go test ./internal/config/... -v
# expected: 3 pass
```

Also test reproducing original symptom:

```bash
# 1. Force bad port into existing config
sed -i '' 's/"server_port": 8080/"server_port": 18080/' ~/.ws-app/config.json

# 2. Restart workstation
pkill -9 -f ws-app && ./build/bin/ws-app &
sleep 3

# 3. Verify port was reset
cat ~/.ws-app/config.json | grep server_port
# → "server_port": 8080  (auto-reset)
lsof -ti :8080
# → ws-app PID  (bound to 8080)
```

#### Step 6e.4 — Commit

```bash
git add internal/config/config.go internal/config/config_test.go
git commit -m "fix(config): safety-reset invalid ServerPort + WS_APP_SERVER_PORT env override

Mirror the WS_APP_CLOUD_URL pattern from eef0bc2 for server_port: if
config.json has a port outside [1024-65535] (eg. left behind by an E2E
test that wrote 18080 then never reset), NewManager resets to
defaultPort=8080 on load and logs a Warn. Operators wanting a different
port set WS_APP_SERVER_PORT env explicitly.

Prevents the 'frontend ERR_CONNECTION_REFUSED after E2E test' class of
bugs (see docs/bugs/2026-05-21-incomplete-unpair-flow.md lessons)."
```

### Commit each subtask

```bash
git commit -m "fix(server): sync.Once on Start to prevent duplicate goroutines"
git commit -m "fix(auth): strict branchOK fail-close mode (Sprint 1 carry-over)"
git commit -m "test(migrate): e2e fresh-DB boot test catches schema conflicts"
git commit -m "test(server): goleak verification on Start/Stop lifecycle"
git commit -m "fix(config): safety-reset invalid ServerPort + WS_APP_SERVER_PORT env override"
```

---

## Task 7 — DEMO.md polish (P1, ~30 min)

Add new section to `docs/DEMO.md`:

```markdown
## 4. Kịch bản Unpair / Switch Branch

**Story**: "Workstation chuyển sang branch khác, hoặc decommission máy cũ"

### 4.1 Click "Unpair Workstation" trong Settings

→ Confirm dialog: "Tất cả data local sẽ bị xoá"
→ Sau 1-2 giây: UI navigate về Pairing screen

### 4.2 Verify local sạch

```sh
sqlite3 ~/.ws-app/ws-app.db "SELECT key, value FROM settings WHERE value != '';"
# → chỉ còn cloud_api_url
```

### 4.3 Verify cloud thấy device revoked

Admin web → Devices → status `revoked`, không còn token.

### 4.4 Pair branch mới (nếu muốn switch)

Settings → Pair Device → nhập code branch mới. Workstation sẽ rebuild fresh state.
```

---

## Self-Review Checklist

Trước khi đánh dấu Sprint 3 done:

- [ ] Backend Pest `WorkstationSelfRevokeTest` 3/3 pass
- [ ] Workstation Go test `TestUnpair*` 3/3 pass
- [ ] E2E Task 4 — unpair clears all local + cloud flips revoked
- [ ] Cách 3 timezone applied vào MockDataSeeder + bug doc updated
- [ ] Frontend Unpair button hiển thị, click work
- [ ] Sprint 1 carry-overs (sync.Once, strict branchOK, migrate e2e, goleak) all green
- [ ] DEMO.md section 4 thêm
- [ ] `vendor/bin/pint --dirty` clean
- [ ] `make ci-local` xanh
- [ ] Full `go test -race ./...` xanh

## Effort Summary

| Task | Owner | Hours |
|---|---|---|
| 1. Backend self-revoke | BE | 2 |
| 2. Workstation unpair rewrite | Go | 3 |
| 3. Frontend unpair UI | FE | 1 |
| 4. E2E verify | QA / FullStack | 0.5 |
| 5. Cách 3 timezone | BE | 0.5 |
| 6a. sync.Once on Start | Go | 0.5 |
| 6b. Strict branchOK | Go | 0.5 |
| 6c. Migration e2e test | Go | 1 |
| 6d. goleak verification | Go | 1 |
| 6e. ServerPort safety + env override | Go | 0.25 |
| 7. DEMO polish | Anyone | 0.5 |
| **Total** | | **~10.75h** |

Realistic: 2 dev × 1 ngày + buffer = 2 ngày Sprint 3.

## Execution

Em recommend subagent-driven execution với order:
1. Task 5 (smallest, unblocks future fresh-seed) → commit standalone
2. Task 1 (backend) → Task 2 (Go) → Task 3 (UI) → Task 4 (E2E)
3. Task 6a-d (carry-overs, can parallelize)
4. Task 7 (docs last)

NO Co-Authored-By in any commit.
