# BUG-2026-05-21-03 — Scan NULL into bare Go string crashes endpoints

| Field | Value |
|---|---|
| **Status** | ✅ FIXED (Option C "triệt để" — codebase-wide audit + 3 regression tests) |
| **Severity** | 🔴 Critical (HTTP 500, blocks Orders + Menu UI tabs sau Recovery) |
| **Discovered** | 2026-05-21 (sau Sprint 2 Recover ship — frontend báo 500 trên `/api/orders`) |
| **Fixed** | 2026-05-21 |
| **Class** | "Scan NULL pitfall" — bare `var s string` + `rows.Scan(&s, ...)` crashes when SQLite returns NULL |

---

## Tóm tắt

Sprint 2 `SyncPuller.Recover()` insert orders với `table_number = NULL` (vì cloud `customer_orders` không có khái niệm "single table number" — họ dùng `table_ids` array). Workstation's `OrderEngine.ListActive()` Scan vào `var o.TableNumber string` → Go database/sql từ chối convert NULL → HTTP 500 trả về frontend → Orders tab trống vĩnh viễn.

Bug class này tiềm ẩn ở mọi nullable column trong DB. Em audit toàn codebase, phát hiện thêm 2 chỗ tương tự (`menu_items.category`, `printers.address/config`) và fix triệt để.

---

## Triệu chứng

Browser DevTools Console:
```
api.ts:28  GET http://localhost:8080/api/orders 500 (Internal Server Error)
```

Direct curl trả message rõ:
```sh
$ curl -s http://localhost:8080/api/orders
{"error":"sql: Scan error on column index 2, name \"table_number\": converting NULL to string is unsupported"}
```

---

## Root cause

### Cloud `customer_orders` schema vs Workstation `orders` schema

| Field | Cloud (Laravel) | Workstation (SQLite) |
|---|---|---|
| Table identifier | `table_ids` JSON array | `table_number TEXT` (single) |
| Sprint 2 Recover() flow | đọc 1 row, **không có** column tương ứng để map | INSERT bỏ qua `table_number` → SQLite default NULL |

### Go scan code

`OrderEngine.ListActive()` ([internal/service/order_service.go:228-230](../../internal/service/order_service.go#L228-L230)):

```go
var o Order
if err := rows.Scan(
    &o.ID, &o.OrderNumber, &o.TableNumber, ...   // ← &o.TableNumber là *string
); err != nil {
    return nil, err
}
```

`database/sql` package quy tắc: scan vào `*string` (raw, không phải `*sql.NullString`) → nếu DB trả NULL → trả error "converting NULL to string is unsupported".

→ Ngay khi Recovery insert 1 order với NULL table_number, ListActive crash tới error gói trong HTTP 500.

### Vì sao tồn tại

`GetByID()` cùng file đã đúng convention (dùng `sql.NullString` cho `cloud_id`, `payment_method`, `notes`...) — nhưng **quên** `table_number`. `ListActive()` không hề dùng `sql.NullString` cho bất kỳ cột nào (vì query chỉ select required cols + table_number nhầm là required).

Code reviewer trước đó (Sprint 1 audit) không catch vì lúc đó orders chỉ tạo local → table_number luôn = "A1" hoặc "" (empty string) → bug ẩn cho tới khi Recovery insert NULL.

---

## Vì sao bug ẩn

| Layer | Lý do không catch |
|---|---|
| Unit tests | Tests cũ chỉ insert orders với `CreateOrderInput{TableNumber:"A1"}` → string không bao giờ NULL → Scan OK |
| Type system Go | `string` (not `*string`) cho phép NULL không type-safely → compiler không help |
| Convention drift | GetByID dùng NullString cho 4/5 nullable cols, quên 1 cái — code review không catch vì pattern đã được áp dụng "đủ nhiều" |
| Integration tests | Chưa có E2E test "Recovery flow + ListActive" cho tới Sprint 2 |

---

## Fix — Option C triệt để

3 layers:

### 1. Convention type helper (`internal/store/sqltypes.go` — MỚI)

```go
type NullableText struct { sql.NullString }
func (n NullableText) String() string { ... }   // unwrap với NULL → ""
func (n NullableText) MarshalJSON() ([]byte, error) { ... }
func (n NullableText) UnmarshalJSON(data []byte) error { ... }
func Text(s string) NullableText { ... }       // constructor wrap

// Plus NullableInt, NullableTime cho cases tương tự
```

Sẵn sàng cho Sprint 3 sweep (refactor struct fields → `NullableText`), nhưng Sprint 2.5 này KHÔNG đụng struct (giữ JSON contract ổn định).

### 2. Scan fix theo pattern hiện có (`sql.NullString` intermediate)

| File | Sites fixed |
|---|---|
| `internal/service/order_service.go` | 3 (`GetByID`, `ListActive`, `ListByDate`) — add `var tableNumber sql.NullString` |
| `internal/handler/routes.go` `handleListMenu` | 1 — `SELECT COALESCE(category, '')` (SQL-side fix, no Go change) |
| `internal/printer/manager.go` `LoadFromDB` | 2 (`address`, `config`) — sql.NullString intermediate |

→ Tổng 6 fixes across 3 files. Pattern thống nhất với existing convention (eg. `cloud_id`, `payment_method`, `notes` đã dùng từ Phase 1).

### 3. Regression test (`internal/service/order_service_nullable_test.go` — MỚI)

3 tests pin behavior:
- `TestListActive_ScansNullTableNumber` — insert 2 orders không có table_number → assert ListActive trả 2 orders, TableNumber = ""
- `TestGetByID_ScansNullableFields` — insert order với mọi nullable col = NULL → GetByID không crash, mọi field rỗng/nil đúng
- `TestListByDate_ScansNullTableNumber` — same pattern cho ListByDate

→ Nếu dev tương lai thêm column nullable + quên dùng sql.NullString → test fail trong CI.

---

## Verification

```sh
# Build clean
go build ./...
# → no errors

# Tests
go test -race ./internal/service/ -run "TestListActive_ScansNull|TestGetByID_ScansNull|TestListByDate_ScansNull" -v
# → 3 PASS

# Full suite
go test -race ./...
# → all packages green

# Live verify against running workstation
curl -s http://localhost:8080/api/orders | python3 -c "import json,sys; d=json.loads(sys.stdin.read()); print('count:', len(d.get('orders',[])))"
# → count: 132 (was 0/error before fix)
```

---

## Lessons learned

### 1. JSON contract vs Go type stability

Tốt nhất là giữ Order struct field là `string` (JSON output stable cho clients), dùng `sql.NullString` intermediate trong Scan code. KHÔNG đổi struct field type sang `sql.NullString` trực tiếp vì sẽ break JSON contract (frontend nhận `{"table_number": {"String":"...","Valid":true}}` thay vì `"A1"`).

`store.NullableText` (mới tạo) HỖ trợ pattern này nếu sau này muốn refactor: implement `MarshalJSON` để preserve "string out, nullable in" semantics.

### 2. SQL-side COALESCE vs Go-side NullString

Hai cách hợp lệ:
- **`COALESCE(col, '')` trong SELECT** — đơn giản, không cần đổi Go code. Phù hợp khi luôn muốn empty-string fallback (như `menu_items.category`).
- **`sql.NullString` intermediate trong Go** — distinguish NULL vs "" nếu logic cần. Phù hợp khi NULL có nghĩa khác empty string (vd. `payment_method = NULL` = chưa thanh toán, `= ""` = unknown).

Em mix cả 2: COALESCE cho display-only fields, NullString cho fields có business semantics.

### 3. Schema mismatch giữa Cloud và Workstation

Cloud `customer_orders.table_ids` (array) ≠ Workstation `orders.table_number` (single). Recovery flow đối mặt mismatch này im lặng → bug.

→ **Sprint 3 action**: refactor `orders` schema chấp nhận `table_ids` JSON column (deferred decision); HOẶC SyncPuller map first `table_ids[0]` → `table_number` để giữ semantic consistent.

### 4. Pre-existing patterns không đảm bảo convention được áp dụng triệt để

GetByID dùng NullString đúng 4/5 nullable cols. Nhân viên thêm Recovery flow (Sprint 2) thấy ListActive scan bare string không lỗi → nhân bản pattern bare string → bug.

→ **Sprint 3 action**: thêm linter rule hoặc code review checklist:
- Mỗi column nullable trong schema → scan target phải là `sql.NullString` (hoặc COALESCE-wrapped)
- Schema nào "ambiguous" (chấp nhận NULL hoặc empty) → comment rõ trong schema file

---

## Related

- [BUG-2026-05-20-02 — device metadata discarded at pair](2026-05-20-device-metadata-discarded-at-pair.md) — cùng class "silent data omission"
- [Sprint 2 plan](../plan/04-sprint-2-bidirectional-sync.md) — Sprint 2 Recover() là root cause
- [Sprint 3 plan Task 6f] (đã thêm vào): codebase-wide refactor sang `store.NullableText` (deferred)
