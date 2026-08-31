# Phase 1.5 — Sync DOWN + Sync UP cho Workstation

> **Discovered:** 2026-05-19, trong quá trình implement Phase 1.
> **Updated:** 2026-05-19 — chốt Option A + bổ sung order sync UP sau khi verify code.
> **Status:** READY TO IMPLEMENT (ops gates landed in Sprint 1 — see [03-sprint-1-ops-hardening.md](03-sprint-1-ops-hardening.md)).

---

## Vấn đề (gốc)

Plan Phase 1 chốt **Option A — dùng endpoint Cloud có sẵn, không động backend**. Khi implement Task 5 (Sync DOWN) thì phát hiện workstation token KHÔNG truy cập được endpoint nào ngoài `/workstation/lots`.

### Verify ngày 2026-05-19 (đọc code thực tế)

| Endpoint | Middleware hiện tại | Workstation gọi được? |
|---|---|---|
| `GET /api/v1/shops/{slug}/menus` | `sso.auth` (Sanctum SSO) | ❌ |
| `GET /api/v1/tms/me` | `device.auth:tms,kiosk` | ❌ thiếu workstation |
| `GET /api/v1/tms/zones` | `device.auth:tms,kiosk` | ❌ |
| `GET /api/v1/tms/tables` | `device.auth:tms,kiosk` | ❌ |
| `GET /api/v1/workstation/lots` | `device.auth:workstation` | ✅ |

**Sync UP status** (verify từ `internal/service/sync_service.go`):
- ✅ `payment.create/confirm/fail` → `POST /api/v1/kiosk/payments/*` đã có handler
- ❌ `order.create` **chưa có handler** trong sync engine. Order chỉ insert local SQLite, không lên Cloud.

---

## Hệ quả với Phase 1

Workstation đã có:
- ✅ Task 1: Bảng SQLite local cho `menu_items`, `tables`, `zones`, `shop_settings`
- ✅ Task 2: Local endpoints `/api/v1/tms/zones`, `/api/v1/tms/tables`, `/api/v1/customer/tables/{qr}`
- ✅ Task 3: Auth middleware
- ✅ Task 4: Sync UP payments
- ❌ Task 5 (Sync DOWN): bị block — kiosk gọi `/tms/zones` qua workstation nhận `[]`
- ❌ Task 6 (Sync UP order): chưa làm — order chết kẹt trong workstation SQLite

**Workaround test trong khi BE chưa xong**: chèn mock data thẳng vào SQLite của workstation (xem section "Mock data" cuối doc).

---

## Phương án đã chốt: Option A (chia nhỏ endpoint, đối xứng pattern hiện có)

### Backend changes (3 file, ~250 dòng + 4 pest test)

#### 1. Sửa middleware cho `/tms/*` read routes (1 dòng)

```php
// backend/routes/api/tms.php:12
->middleware('device.auth:tms,kiosk,workstation')   // thêm 'workstation'
```

`TmsController` đã filter theo `$device->branch_id` rồi nên zero code change ngoài middleware.

Sau bước này workstation pull được:
- `GET /api/v1/tms/me` → device + branch info
- `GET /api/v1/tms/zones` → zones của branch
- `GET /api/v1/tms/tables` → tables + status của branch

#### 2. Thêm route + 3 controller mới trong `/workstation/*`

```php
// backend/routes/api/workstation.php
Route::prefix('v1/workstation')
    ->middleware(['device.auth:workstation', 'throttle:60,1'])
    ->group(function () {
        Route::get('lots',     [LotController::class,    'index']);
        Route::get('menu',     [MenuController::class,   'index']);    // NEW (sync DOWN)
        Route::get('branch',   [BranchController::class, 'show']);     // NEW (sync DOWN)
        Route::post('orders',  [OrderController::class,  'store']);    // NEW (sync UP)
    });
```

**`Workstation\MenuController::index`** — trả về menu của branch:
```json
{
  "data": [
    { "id": "...", "name": "...", "is_active": true,
      "products": [
        { "id": "...", "name": "...", "price": 1200,
          "skus": [...], "category_id": "...", "image_url": "..." }
      ]
    }
  ],
  "generated_at": "2026-05-19T..."
}
```
Resolve `branch` → `shop` từ `$device->branch_id` rồi tận dụng query của `Shop\MenuController`.

**`Workstation\BranchController::show`** — trả về branch info + shop_settings:
```json
{
  "data": {
    "id": "...", "name": "...", "shop_id": "...",
    "settings": {
      "currency": "JPY", "tax_rate": 0.10,
      "cart_timeout_seconds": 300, "open_hours": {...}
    }
  }
}
```

**`Workstation\OrderController::store`** — nhận order từ workstation, lưu vào Cloud:
- Validate payload (table_id, items[], total, idempotency_key)
- Use `Idempotency-Key` header để dedupe (workstation retry an toàn)
- Trả `{ "data": { "id": "<cloud_id>" } }` để workstation lưu `orders.cloud_id`

### Workstation Go changes (2 file, ~80 dòng + go test)

#### 3. `internal/service/sync_service.go`

```go
// Thêm vào e.handlers map
e.handlers = map[string]syncHandler{
    "payment.create":  e.handlePaymentCreate,
    "payment.confirm": e.handlePaymentConfirm,
    "payment.fail":    e.handlePaymentFail,
    "order.create":    e.handleOrderCreate,   // NEW
}

// Hàm mới
func (e *SyncEngine) handleOrderCreate(ctx context.Context, entityID string, payload map[string]any) (map[string]any, bool, error) {
    bearer, _ := payload["bearer"].(string)
    idemKey, _ := payload["idempotency_key"].(string)
    body := payload["order"].(map[string]any)
    return e.cloudPost(ctx, "/api/v1/workstation/orders", bearer, idemKey, body)
}
```

#### 4. Order create handler — thêm Enqueue

Trong handler tạo order ([routes.go](../../internal/handler/routes.go) hoặc local_customer.go):
```go
// Sau khi insert order vào SQLite local
if err := s.sync.Enqueue("order", o.ID, "create", syncPayload, 1); err != nil {
    slog.Warn("enqueue order sync UP", "err", err)
    // KHÔNG fail request — order đã local rồi, sync UP background
}
```

Tương tự pattern payment ở [local_kiosk.go:120](../../internal/handler/local_kiosk.go#L120). Trên `cloud_id` trả về (nếu có), update `orders.cloud_id` để link.

---

## Sync DOWN worker (Phase 1.5 — workstation side)

`internal/service/sync_pull.go` (MỚI):

```go
type SyncPuller struct {
    db       *store.DB
    cloudURL string
    bearer   string
    interval map[string]time.Duration
}

// Polling intervals
// - tables, zones    : 60s
// - menu             : 60s (sau này hook WS event menu.updated để invalidate)
// - branch+settings  : 5 phút
// - me               : 5 phút (check device.revoked)
```

Mỗi tick:
1. `GET cloudURL/api/v1/tms/zones` → upsert vào local `zones`
2. `GET cloudURL/api/v1/tms/tables` → upsert vào local `tables`
3. `GET cloudURL/api/v1/workstation/menu` → upsert vào local `menu_items`
4. `GET cloudURL/api/v1/workstation/branch` → update `shop_settings` + branch info

Wire trong `internal/handler/server.go` cùng chỗ với `SyncEngine`.

---

## Mock data trong workstation (test trong khi BE chưa xong)

Trong dev, không cần đợi BE — chèn data trực tiếp vào SQLite để test kiosk ↔ workstation:

```sh
sqlite3 ~/.workstation-app/workstation-app.db <<EOF
INSERT INTO zones (id, name, branch_id, is_active) VALUES
  ('z1', 'Khu A', 'br-001', 1),
  ('z2', 'Khu B', 'br-001', 1);

INSERT INTO tables (id, code, name, status, zone_id, branch_id, is_active) VALUES
  ('t1', 'A1', 'Bàn A1', 'free',     'z1', 'br-001', 1),
  ('t2', 'A2', 'Bàn A2', 'occupied', 'z1', 'br-001', 1);

INSERT INTO menu_items (id, name, price, is_available, branch_id) VALUES
  ('m1', 'Phở bò',  60000, 1, 'br-001'),
  ('m2', 'Bún chả', 55000, 1, 'br-001');
EOF
```

Khi sync DOWN chạy thật, data này sẽ bị overwrite tự nhiên — zero code change ở handler.

---

## Action items

### Backend (BE team, ~1 ngày)
- [ ] Sửa middleware `routes/api/tms.php` → `device.auth:tms,kiosk,workstation`
- [ ] Tạo `app/Http/Controllers/Api/V1/Workstation/MenuController.php`
- [ ] Tạo `app/Http/Controllers/Api/V1/Workstation/BranchController.php`
- [ ] Tạo `app/Http/Controllers/Api/V1/Workstation/OrderController.php`
- [ ] Thêm 3 route vào `routes/api/workstation.php`
- [ ] Pest test cho từng endpoint (4 file: tms-workstation-access, menu, branch, orders)
- [ ] Chạy `vendor/bin/pint --format agent`

### Workstation Go (~0.5 ngày)
- [ ] Implement `handleOrderCreate` trong `internal/service/sync_service.go`
- [ ] Thêm `Enqueue("order", ...)` trong order create handler
- [ ] Go test cho `handleOrderCreate`
- [ ] Implement `internal/service/sync_pull.go` (polling worker)
- [ ] Wire `sync_pull` trong `internal/handler/server.go`
- [ ] Wails UI dashboard: hiển thị last sync DOWN timestamps (optional)

### Test E2E
- [ ] HQ admin sửa table.name trên Cloud → workstation pull trong 60s → kiosk thấy tên mới
- [ ] Kiosk tạo order qua workstation → 60s sau Cloud có order
- [ ] Kiosk pay qua workstation → Cloud có payment (đã test ở Phase 1)

---

## File code sẽ đụng

```
backend/
├── routes/api/tms.php                                         ← sửa middleware
├── routes/api/workstation.php                                 ← thêm 3 route
├── app/Http/Controllers/Api/V1/Workstation/MenuController.php ← MỚI
├── app/Http/Controllers/Api/V1/Workstation/BranchController.php ← MỚI
├── app/Http/Controllers/Api/V1/Workstation/OrderController.php ← MỚI
└── tests/Feature/Api/V1/Workstation/*.php                     ← MỚI (4 pest test)

workstation/
├── internal/service/sync_service.go    ← thêm handleOrderCreate
├── internal/service/sync_service_test.go ← thêm test
├── internal/service/sync_pull.go       ← MỚI (Phase 1.5 worker)
├── internal/handler/server.go          ← wire sync_pull
└── internal/handler/<order create>.go  ← thêm Enqueue("order", ...)
```
