# BUG-2026-05-20-02 — Device metadata bị bỏ khi pair → branch isolation broken + DeviceSeenBuffer no-op

| Field | Value |
|---|---|
| **Status** | ✅ FIXED |
| **Severity** | 🔴 Critical (security gap: cross-branch LAN auth) + 🟡 Important (DeviceSeenBuffer dead code) |
| **Discovered** | 2026-05-20 (debate khi explain bug-01 cho stakeholder) |
| **Fixed** | 2026-05-20 |
| **Introduced in** | Phase 1 — commit gốc của `handleDevicePair` parsing logic |
| **Fix commit** | (sẽ cập nhật sau khi commit) |
| **Related** | [BUG-2026-05-20-01](2026-05-20-devices-table-name-conflict.md) — schema conflict bị fix cùng đợt; cả 2 bug đều thuộc class "device data dropped on the floor" |

---

## Tóm tắt

Workstation gọi `POST /api/v1/workstation/pair` thì cloud trả về **DeviceResource đầy đủ** (id, name, type, status, branch_id, organization_id, ...), nhưng workstation **chỉ parse 3 fields** (id, name, type) và throw phần còn lại. Hệ quả:

1. **Bug 3 — Branch isolation broken**: Workstation không biết `branch_id` của chính mình → `AuthMiddleware.branchOK()` luôn bypass check → kiosk branch X có thể auth vào workstation branch Y.

2. **Bug 2 — DeviceSeenBuffer no-op**: Local `devices` table (omnify schema) không có row nào của workstation, nên Sprint 1's DeviceSeenBuffer `UPDATE devices SET last_seen_at WHERE id=?` luôn affect 0 rows. Heartbeat tracking dead code im lặng.

---

## Triệu chứng

### Bug 3 — Branch bypass

Trong [internal/handler/auth_middleware.go:117-123](../../internal/handler/auth_middleware.go#L117-L123):
```go
func (m *AuthMiddleware) branchOK(deviceBranch string) bool {
    wsBranch := m.branchIDFn()                     // Server.workstationBranchID()
    if wsBranch == "" || deviceBranch == "" {
        return true                                // ← BYPASS
    }
    return wsBranch == deviceBranch
}
```

`m.branchIDFn()` đọc từ settings key `workstation_branch_id`. Trước fix, key này **chưa bao giờ được write** → luôn empty → check luôn bypass. Branch isolation Sprint 1 đã code nhưng không enforce trong production.

### Bug 2 — Silent no-op

Trong `DeviceSeenBuffer.FlushOnce()`:
```go
stmt, _ := tx.Prepare(`UPDATE devices SET last_seen_at = ? WHERE id = ?`)
stmt.Exec(timestamp, deviceID)   // ← 0 rows affected vì devices table rỗng
```

`go test ./internal/service/` PASS — tests tự `INSERT` row vào trước khi Touch. Production thì không có ai INSERT. Bug ẩn dưới green tests.

---

## Root cause — Code "kiệm parse"

Trước fix, [internal/handler/routes.go:765-785](../../internal/handler/routes.go#L765-L785):

```go
var cloudResp struct {
    DeviceToken string `json:"device_token"`
    Device      struct {
        ID   string `json:"id"`
        Name string `json:"name"`
        Type string `json:"type"`
        // ↓ branch_id, organization_id, status — bị bỏ khi unmarshal
    } `json:"device"`
}

settings := map[string]string{
    "device_token":  cloudResp.DeviceToken,
    "device_id":     cloudResp.Device.ID,
    "device_name":   cloudResp.Device.Name,
    "device_type":   cloudResp.Device.Type,
    "cloud_api_url": cloudURL,
    // ↓ workstation_branch_id, organization_id — không có
}
```

Server-side response (cloud) qua [DeviceResourceBase.php](../../../backend/app/Omnify/Modules/Device/Resources/DeviceResourceBase.php) trả đầy đủ schemaArray. JSON struct của Go **chỉ tag 3 fields** → các fields khác bị silently dropped khi unmarshal (Go JSON default behavior).

---

## Vì sao test không catch

| Layer | Test có catch không? |
|---|---|
| Unit test `cloud_verifier_test.go` | Test parse mock JSON → có verify ID, BranchID, Type, Status nhưng không OrganizationID. Cloud response có thì test có. Test PASS. |
| Unit test `device_seen_buffer_test.go` | Test self-INSERT row rồi Touch → PASS vì row do test tạo, không kiểm production flow |
| Unit test `auth_middleware_test.go` | Test branchOK with mocked branchIDFn return value — production "always empty" case không được test |
| Integration test pair → auth | **Không có** — chỉ có unit test isolated |
| Soak test | Cloud_api_url fake (`127.0.0.1:1`) → không thực sự pair → bug 3 không trigger; bug 2 silently 0 rows updated nhưng không lỗi |

→ Cả 3 layer test đều xanh trong khi bug runtime impact đáng kể.

---

## Fix

### Phía workstation only — không đụng backend, không đụng docker

#### 1. `internal/service/cloud_verifier.go` — thêm field

```diff
 type DeviceInfo struct {
-    ID       string `json:"id"`
-    Name     string `json:"name"`
-    Type     string `json:"type"`
-    Status   string `json:"status"`
-    BranchID string `json:"branch_id"`
+    ID             string `json:"id"`
+    Name           string `json:"name"`
+    Type           string `json:"type"`
+    Status         string `json:"status"`
+    BranchID       string `json:"branch_id"`
+    OrganizationID string `json:"organization_id"`
 }
```

#### 2. `internal/service/device_seen_buffer.go` — thêm `Register()` UPSERT method

```go
// Register UPSERTs a device row into the local `devices` table so subsequent
// Touch/Flush actually have a target row to UPDATE. Call after Cloud verifies
// a device (pair or LAN auth) — keeps local devices table populated with the
// subset of cloud devices that have interacted with this workstation.
func (b *DeviceSeenBuffer) Register(info DeviceInfo) error {
    if info.ID == "" || info.BranchID == "" || info.OrganizationID == "" {
        return nil // skip rows that would violate NOT NULL
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
```

3 test mới:
- `TestDeviceSeenBuffer_RegisterInsertsThenUpdates` — INSERT-then-UPDATE via ON CONFLICT
- `TestDeviceSeenBuffer_RegisterSkipsIncomplete` — empty id/branch/org → no-op
- `TestDeviceSeenBuffer_RegisterThenTouchUpdatesLastSeen` — regression test cho bug 2

#### 3. `internal/handler/auth_middleware.go` — call Register sau Cloud verify success

```go
if putErr := m.cache.Put(hash, device.ID, device.Type, device.BranchID, ""); putErr != nil {
    slog.Error("auth cache put", "err", putErr)
}
if m.seen != nil {
    // Populate local devices table so Touch has a row to UPDATE.
    if regErr := m.seen.Register(*device); regErr != nil {
        slog.Warn("auth device register", "err", regErr, "device_id", device.ID)
    }
    m.seen.Touch(device.ID, time.Now().UTC())
}
```

#### 4. `internal/handler/routes.go handleDevicePair` — parse + save + UPSERT

```diff
 var cloudResp struct {
     DeviceToken string `json:"device_token"`
     Device      struct {
-        ID   string `json:"id"`
-        Name string `json:"name"`
-        Type string `json:"type"`
+        ID             string `json:"id"`
+        Name           string `json:"name"`
+        Type           string `json:"type"`
+        Status         string `json:"status"`
+        BranchID       string `json:"branch_id"`
+        OrganizationID string `json:"organization_id"`
     } `json:"device"`
 }

 settings := map[string]string{
     "device_token":          cloudResp.DeviceToken,
     "device_id":             cloudResp.Device.ID,
     "device_name":           cloudResp.Device.Name,
     "device_type":           cloudResp.Device.Type,
+    "device_status":         cloudResp.Device.Status,
+    "workstation_branch_id": cloudResp.Device.BranchID,
+    "organization_id":       cloudResp.Device.OrganizationID,
     "cloud_api_url":         cloudURL,
 }

+if s.seenBuffer != nil {
+    if regErr := s.seenBuffer.Register(service.DeviceInfo{...}); regErr != nil {
+        slog.Warn("pair device register", "err", regErr)
+    }
+}
```

---

## Verification

### End-to-end smoke với mock cloud

```sh
# Mock cloud trả DeviceResource đầy đủ
python3 -c "...HTTPServer port 9999 returning full /pair response..." &

# Workstation pair
curl -X POST http://localhost:28080/api/device/pair -d '{"pairing_code":"ABC123"}'

# Verify settings có đủ
sqlite3 /tmp/ws-app-pair-smoke/ws-app.db "SELECT key, value FROM settings WHERE key LIKE '%branch%' OR key LIKE 'org%';"
# → workstation_branch_id|branch-aaa
# → organization_id|org-aaa

# Verify local devices row tồn tại
sqlite3 /tmp/ws-app-pair-smoke/ws-app.db "SELECT * FROM devices;"
# → dev-uuid-aaaa|Smoke Workstation|workstation|active|...|branch-aaa|org-aaa|<timestamp>
```

Cả 2 metric ✅ PASS.

### Unit tests
- `internal/service/`: 7 tests trong DeviceSeenBuffer (4 cũ + 3 mới Register tests) PASS
- `internal/handler/`: PASS
- Full suite `-race`: PASS

---

## Lessons learned

### 1. JSON parse "kiệm field" rất dễ silently drop data
Go's default JSON unmarshal **không cảnh báo** khi response có fields mà struct không khai báo. Phải proactively map all fields cần dùng.

**Action**: Khi tích hợp với cloud API mà cloud có khả năng thêm field, dùng `*DeviceInfo` import từ cloud_verifier để đồng bộ schema thay vì redefine inline struct.

### 2. Test "self-insert" che giấu integration gap
DeviceSeenBuffer test tự INSERT row rồi gọi Touch → test PASS dù production flow không bao giờ INSERT. Class of bug "test setup ≠ production setup".

**Action**: Thêm test integration: pair → verify devices table populated → Touch → verify last_seen_at updated.

### 3. Defense-in-depth: branchOK bypass khi config thiếu
`branchOK()` return true khi `wsBranch == ""` là design "fail open" — nguy hiểm khi config bug. Nên đổi sang "fail close" (deny if branch unknown) với log Warn.

**Action Sprint 2**: Đổi `branchOK()` thành strict mode: empty branch → 503 với log "device pairing incomplete", force operator re-pair.

### 4. Branch isolation cần test E2E
Đơn giản test: pair workstation branch A, gửi Bearer của kiosk branch B → expect 403. Hiện không có test này.

**Action Sprint 2**: Add `internal/handler/auth_middleware_e2e_test.go` với mock cloud trả devices của branch khác nhau, verify reject.

---

## Impact

### Trước fix
- **Security**: Branch isolation hoàn toàn không enforce. Một kiosk lậu paired ở branch X có thể gọi vào workstation branch Y, đọc menu/zones/tables của branch Y, tạo order giả.
- **Observability**: DeviceSeenBuffer được Sprint 1 ship như feature nhưng không ghi gì xuống DB → dashboard tương lai sẽ luôn show "no devices seen".
- **Audit**: `audit_log` của pair event chỉ ghi `{"name":"..."}`, không có branch info → khó debug cross-branch issues.

### Sau fix
- Branch isolation enforce ngay khi workstation paired thành công (bước đầu sau install)
- DeviceSeenBuffer ghi thật → last_seen_at hợp lệ → Sprint 2 có thể build "active devices" dashboard
- Audit log pair event include branch_id → trace đúng

---

## Related work — Sprint 2 carryover

1. **Strict branch enforcement** (lesson 3) — đổi `branchOK()` fail close
2. **E2E auth middleware test** (lesson 4) — cross-branch reject
3. **Backend endpoint** `GET /v1/workstation/branch-devices` — workstation pull metadata list của devices cùng branch (KHÔNG bao gồm token) — để UI show "3 kiosks online"
4. **device.revoked event qua Reverb** — cloud push event invalidate cache + xóa local devices row
