# BUG-2026-05-20-01 — Schema conflict: 2 bảng `devices` cùng tên, khác schema

| Field | Value |
|---|---|
| **Status** | ✅ FIXED |
| **Severity** | 🔴 Critical (block fresh DB initialization) |
| **Discovered** | 2026-05-20 (Sprint 1 soak verification) |
| **Fixed** | 2026-05-20 |
| **Introduced in** | Phase 1 (commit `e246de8` — `feat: omnify Go codegen output + enum stubs`) |
| **Reporter** | Soak test khi prepare pilot rollout |
| **Fix commit** | `8372355` — `fix(schema): rename printer table to resolve conflict with omnify devices` |
| **Affected versions** | Any binary built after Phase 1 omnify regen + before fix |

---

## Tóm tắt

Codebase có **2 migration cùng tạo bảng tên `devices`** với schema khác nhau hoàn toàn. Khi binary chạy fresh DB, omnify migration thứ 2 fail vì bảng đã tồn tại với schema cũ thiếu cột cần thiết.

---

## Triệu chứng

Server crash ngay sau migration step:

```
2026/05/20 19:52:22 INFO applying migration version=1006 name=omnify/006_devices.sql
2026/05/20 19:52:22 database:run migrations: omnify migrations: execute migration omnify/006_devices.sql: SQL logic error: no such column: organization_id (1)
[1]  + exit 1     ./build/bin/ws-app
```

Process exit code 1. Không tạo được DB, app không khởi động được.

---

## Root cause

### Hai bảng `devices` cùng tên đến từ 2 codebase khác nhau

| Nguồn | File | Schema (rút gọn) | Mục đích |
|---|---|---|---|
| Hand-written (Sprint 0) | `migrations/001_initial_schema.sql:62-73` | `id, type, name, connection_type, address, config, is_active, last_seen_at, ...` | **Phần cứng máy in** (ESC/POS printers USB/TCP) — printer.Manager owns |
| Omnify codegen (Phase 1) | [migrations/omnify/006_devices.sql](../../migrations/omnify/006_devices.sql) | `id, name, type, status, pairing_code, device_token, organization_id, branch_id, ...` | **Thiết bị pair với cloud** (Kiosk/TMS/Workstation pairing) |

Hai concept hoàn toàn khác (máy in vật lý vs thiết bị authenticate cloud), nhưng vô tình trùng tên `devices`. Phía omnify generate từ umbrella YAML schema mà không biết workstation đã có bảng `devices` cho việc khác.

### Chuỗi failure khi binary chạy fresh DB

1. Migration `001` tạo bảng `devices` với schema printer (id, type, name, connection_type, ...)
2. Migration `omnify/006` chạy `CREATE TABLE IF NOT EXISTS devices (...)` → **no-op** vì bảng đã tồn tại (SQLite semantic của `IF NOT EXISTS`)
3. Migration `omnify/006` tiếp tục chạy `CREATE INDEX idx_devices_organization_id ON devices(organization_id)` → **FAIL** vì bảng cũ (từ 001) không có cột `organization_id`
4. Migration runner bubble error → process exit

---

## Tại sao bug ẩn nhiều tháng

Bug đã có sẵn từ Phase 1 (commit `e246de8`) nhưng không lộ ra vì:

1. **Unit tests không trigger omnify migrations** — `store.OmnifyMigrations` chỉ được wire từ `cmd/workstation/main.go:30`, test framework chỉ chạy hand-written migrations (`001-004`). Test suite vẫn xanh hoàn toàn dù bug tồn tại.

2. **Existing dev databases skip 006** — máy dev nào đã có DB từ trước Phase 1 đều có schema_migrations table với version 1006 đã apply (hoặc skipped). Bug chỉ trigger trên **fresh DB**.

3. **CI không boot binary fresh** — CI chỉ chạy `go test`, không spin up binary + fresh DB end-to-end.

4. **Soak test trước Sprint 1 chưa từng chạy fresh** — không ai thử `rm -rf ~/.ws-app && ./ws-app` cho tới Sprint 1.

→ **Bug "ngủ"** cho tới khi pilot prep buộc phải fresh-init một workstation.

---

## Impact

### Trong dev environment
- Mỗi dev mới onboard với `rm -rf ~/.ws-app && ws-app` đều bị block
- Pilot rollout: KHÔNG thể setup workstation mới tại cửa hàng (luôn phải import DB từ máy dev có sẵn — workaround xấu)

### Trong production
- 100% block khả năng deploy lên cửa hàng mới
- Có thể đã làm chậm timeline pilot nếu không catch sớm

### Severity = Critical
- Block một flow chính (fresh install)
- Không có workaround sạch
- Discovery accidentaly through soak test, không phải qua testing chính thức

---

## Reproduction

Trước khi fix:

```sh
cd /Users/phamduyanh1910/Documents/famgia/godx/godx-tempo/workstation-app
rm -rf ~/.ws-app                # ensure fresh DB
./build/bin/ws-app              # built before commit 8372355
# → exit 1, "no such column: organization_id"
```

---

## Fix

### Approach

**Rename bảng hand-written `devices` → `printers`** (smaller blast radius). Lý do chọn rename HAND-WRITTEN side:
- Bảng printer là **local-only** (workstation owns, không sync cloud)
- Naming align với package `internal/printer/`
- Omnify schema là **canonical** từ umbrella, không nên fork
- Đụng ít code Go hơn (chỉ printer.Manager + tests)

### Migration mới — `005_rename_devices_to_printers.sql`

```sql
-- Rename hand-written printer registry table to avoid conflict with
-- omnify-generated `devices` table (cloud-paired devices).
-- The old `devices` table stores ESC/POS printer hardware config;
-- omnify's `devices` represents Kiosk/TMS/Workstation paired against cloud.
ALTER TABLE devices RENAME TO printers;
```

Đặt trong `internal/store/migrations/` (chạy cùng namespace với 001-004, SAU 004 và TRƯỚC omnify `1001+`). SQLite tự follow indexes qua ALTER TABLE RENAME TO.

### Code đụng (commit `8372355`)

| File | Thay đổi |
|---|---|
| `internal/store/migrations/005_rename_devices_to_printers.sql` | MỚI (1 dòng ALTER) |
| `internal/printer/manager.go` | 3 query: `FROM devices` → `FROM printers`, `INTO devices` → `INTO printers`, `UPDATE devices` → `UPDATE printers` |
| `internal/store/queries/devices.sql` | sqlc stub: update table reference |
| `internal/store/migrate_test.go` | Expected table list: `devices` → `printers` trong `TestMigrationsApply` |
| `internal/service/device_seen_buffer_test.go` | Test fixture: insert vào schema omnify mới (`id, name, type, organization_id, branch_id`) thay vì schema printer cũ |

**Không đụng:**
- `internal/service/device_seen_buffer.go` — giữ nguyên `UPDATE devices SET last_seen_at` vì sau rename, "devices" trỏ vào bảng omnify (cloud devices) — chính xác target mà DeviceSeenBuffer cần update.
- `migrations/omnify/*` — không sửa codegen output.
- `internal/handler/routes.go` — endpoints `/api/devices` đi qua `printer.Manager`, SQL đã fix ở manager.go.

---

## Verification

### Trước fix

```
ERROR migration applied version=1006 ... SQL logic error: no such column: organization_id
```

### Sau fix (smoke test 19:54:39 ngày 2026-05-20)

```
INFO applying migration version=4 name=004_local_replica.sql
INFO migration applied version=4
INFO applying migration version=5 name=005_rename_devices_to_printers.sql       ← NEW
INFO migration applied version=5
INFO applying migration version=1001 name=omnify/001_branches.sql
...
INFO applying migration version=1006 name=omnify/006_devices.sql
INFO migration applied version=1006                                              ← PASS
...
INFO migration applied version=1011
INFO database opened path=/Users/phamduyanh1910/.ws-app/ws-app.db
```

DB state sau migration:
```sh
sqlite3 ~/.ws-app/ws-app.db ".tables" | grep -E "devices|printers"
# → devices  (omnify cloud schema)
# → printers (renamed local printer schema)
```

### Soak verification (60 phút, 18,905 orders)

Soak run 15:49-16:49 ngày 2026-05-20 với fix:
- Tất cả 16 migrations apply clean (4 hand-written + 1 rename + 11 omnify)
- 60 phút sustained load, 0 panic
- Cả 2 bảng `devices` và `printers` tồn tại độc lập

---

## Lessons learned

### 1. Codegen tools cần namespace
Omnify generate vào root `migrations/omnify/` nhưng dùng tên bảng generic (`devices`, `menus`, `categories`). Trong umbrella monorepo có nhiều submodule, naming collision là rủi ro thường trực.

**Action item Sprint 2+**: Đề xuất omnify prefix tên bảng theo domain (vd. `cloud_devices`, `cloud_menus`) hoặc workstation-app rename tất cả local-only tables (`local_*` prefix).

### 2. Test coverage gap — fresh DB init không được test
Unit tests xanh hoàn toàn trong khi bug tồn tại nhiều tháng. Cần:
- Integration test boot binary với fresh DB rồi assert `/api/status` 200
- CI step chạy binary 30s + curl healthcheck

**Action item Sprint 2**: Add `internal/store/migrate_e2e_test.go` mà:
1. Spin up `store.Open` với omnify migrations enabled
2. Assert tất cả bảng dự kiến tồn tại
3. Assert schema match expected columns

### 3. Migration ordering convention chưa rõ
Hand-written `001-004` và omnify `1001-1011` chạy theo version number. Hiện chưa có quy tắc rõ về:
- Hand-written được phép ALTER bảng omnify không?
- Omnify có được phép tạo bảng trùng tên hand-written không?
- Migration nào "own" tên bảng nào?

**Action item Sprint 2**: Viết `docs/MIGRATIONS.md` mô tả convention.

### 4. Schema_migrations sao chưa rollback được
Khi migration fail giữa chừng (như case này), schema_migrations table có thể có row "1006 applied" trong khi thực tế chưa apply hết. Migration runner cần atomic per-migration hoặc rollback hỗ trợ.

**Action item Sprint 2+**: Audit `internal/store/migrate.go` — đảm bảo mỗi migration wrap trong transaction để rollback toàn bộ trên error.

---

## Related

- **Pre-existing**: [docs/INTEGRATION_GAPS.md](../INTEGRATION_GAPS.md) — Phase 1 gap analysis (không cover bug này vì tập trung integration layer)
- **Sprint 1 plan**: [docs/plan/03-sprint-1-ops-hardening.md](../plan/03-sprint-1-ops-hardening.md) — sprint mà bug được discover trong quá trình soak verify
- **Tag**: `sprint-1-pilot-ready` (commit `1111ccd`) — bao gồm fix này
