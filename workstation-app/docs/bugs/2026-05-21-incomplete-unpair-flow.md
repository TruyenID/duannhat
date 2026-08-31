# BUG-2026-05-21-02 — Incomplete unpair flow → security gap + data leak

| Field | Value |
|---|---|
| **Status** | 🔴 OPEN — defer Sprint 3 (effort ~5h) |
| **Severity** | 🟡 Important (security gap + data leak; not crash-level but blocks "switch branch" + "decommission" flows) |
| **Discovered** | 2026-05-21 (Sprint 2 review — user asked "có cơ chế logout không") |
| **Class** | "Feature half-done" — pair flow polished trong Sprint 1+2, unpair chưa được nâng cấp tương ứng |
| **Fix target** | Sprint 3 plan: [docs/plan/05-sprint-3-unpair-and-carryovers.md](../plan/05-sprint-3-unpair-and-carryovers.md) |

---

## Tóm tắt

`handleDeviceUnpair` ([internal/handler/routes.go:861-871](../../internal/handler/routes.go#L861-L871)) chỉ clear 4 settings keys cũ, không revoke token với cloud, không xoá local data (orders/menu/zones/devices/auth_cache), không có UI button trong frontend. Hệ quả: unpair vô nghĩa về mặt security (cloud vẫn nghĩ device active), và pair branch khác sau đó → UI hiển thị data lẫn lộn 2 branches.

---

## Triệu chứng

### Test reproducible

```sh
# 1. Pair workstation với branch X
curl -X POST http://localhost:8080/api/device/pair -d '{"pairing_code":"<X-code>"}'

# 2. Unpair
curl -X POST http://localhost:8080/api/device/unpair

# 3. Inspect local — vẫn còn dangling state
sqlite3 ~/.ws-app/ws-app.db <<'SQL'
SELECT key, value FROM settings WHERE value != '';
-- → workstation_branch_id|<X branch UUID>  (LEAK)
-- → organization_id|<X org UUID>             (LEAK)
-- → device_status|active                     (LEAK)

SELECT COUNT(*) FROM orders;       -- vẫn còn N rows
SELECT COUNT(*) FROM menu_items;   -- vẫn còn N rows
SELECT COUNT(*) FROM zones;        -- vẫn còn N rows
SELECT COUNT(*) FROM auth_token_cache;  -- vẫn còn N rows
SQL

# 4. Verify cloud vẫn nghĩ workstation active
docker compose exec mysql mysql -uroot -psecret tempo -e \
  "SELECT name, status, paired_at FROM devices WHERE name='Workstation-POS' AND branch_id='<X-id>';"
# → status='active' (CẦN BIẾN 'revoked' hoặc 'pending_activation')
```

---

## Root cause — 5 vấn đề riêng biệt

### 1. Stale settings sau Sprint 1 schema expansion

Sprint 1 thêm 3 settings keys (`workstation_branch_id`, `organization_id`, `device_status`) khi pair, nhưng [routes.go:862](../../internal/handler/routes.go#L862) `keys` array chỉ list 4 keys cũ:

```go
keys := []string{"device_token", "device_id", "device_name", "device_type"}
// MISSING: workstation_branch_id, organization_id, device_status
```

→ Sau unpair, `Server.workstationBranchID()` vẫn return branch cũ → `AuthMiddleware.branchOK()` vẫn enforce branch cũ → kiosk branch X vẫn auth được vào workstation đã unpair.

### 2. Cloud không biết workstation đã unpair

Workstation chỉ xoá local, **KHÔNG** gọi cloud endpoint nào. Backend's [routes/api.php:21](../../../backend/routes/api.php#L21) có `POST /v1/devices/pair` (public) nhưng không có symmetric `/devices/unpair`. Revoke chỉ có ở admin SSO endpoints:

- `POST /api/v1/hq/{brand}/devices/{id}/revoke` (Sanctum SSO — workstation không gọi được)
- `POST /api/v1/shops/{shop}/devices/{id}/revoke` (Sanctum SSO — same)

Cloud nghĩ device vẫn `status='active'`, `device_token` vẫn valid. Ai có token cũ (vd. backup file của workstation) → vẫn impersonate được.

### 3. Local data của branch cũ vẫn lưu

`handleDeviceUnpair` chỉ touch `settings` table. Các tables sau đây vẫn giữ data branch cũ:

| Table | Số lượng row sau unpair | Hệ quả |
|---|---|---|
| `orders` | N (đã pull từ Recovery) | UI hiển thị orders cũ |
| `menu_items` | M (đã pull từ menu sync) | Menu cũ hiện ra |
| `zones`, `tables` | từ tms sync | Bàn ghế cũ |
| `inventory_lots` | từ Sprint 2 lots pull | Stock cũ |
| `devices` (omnify) | có ít nhất 1 row (workstation tự register) + N rows kiosks đã auth | Identity cũ |
| `auth_token_cache` | N (kiosks đã verify) | LAN device được auth bằng token cache 5 min |
| `sync_queue` | pending rows | Sẽ retry push lên cloud cũ |
| `audit_log` | full history | (acceptable — audit là forever) |

→ Pair branch khác sau đó → UI lẫn lộn data 2 branches → orders branch X mixed orders branch Y.

### 4. Không có UI button trong frontend

```sh
$ grep -rn "unpair\|Unpair" frontend/src/
(empty)
```

Workstation operator **KHÔNG có cách "click logout"** từ UI. Phải:
- Mở terminal
- `curl -X POST http://localhost:8080/api/device/unpair`
- Hoặc xoá thủ công `~/.ws-app/`

Không khả thi cho nhân viên cửa hàng.

### 5. Audit log thiếu context

```go
s.auditLog(r, "device.unpair", "device", "", "")
//                                       ^   ^^
//                                  entity_id, details — empty
```

Khi audit chạy, `settings.device_id` đã được clear → trace không biết unpair device nào. Cần capture device_id TRƯỚC khi clear.

---

## Impact

### Security
- **Token vẫn valid sau unpair** → nếu workstation bị mất/cướp, "unpair" không bảo vệ được. Phải dựa vào admin manual revoke qua admin web.
- **Branch isolation bypass** sau pair branch mới → orders branch cũ có thể bị nhân viên đọc qua UI.

### Operations
- **Switch branch flow broken** — nhà hàng đổi địa điểm + reuse workstation hardware → phải `rm -rf ~/.ws-app/` thủ công, không có "factory reset" sạch.
- **Decommission flow broken** — workstation cũ ngừng dùng → cloud vẫn nghĩ active, gây nhiễu báo cáo "device online".

### Severity rationale
Không phải Critical vì:
- Operator có workaround (rm -rf ~/.ws-app/) — chỉ là ugly
- Cloud admin có thể revoke qua admin web — không hoàn toàn stuck

Nhưng Important vì:
- Sai expectation: dev nghĩ unpair = clean reset, thực tế không phải
- Sprint 1 đã làm pair "production-grade" nhưng unpair "demo-grade"

---

## Fix design (Sprint 3)

### Workstation-side (~2-3h)

`internal/handler/routes.go` rewrite `handleDeviceUnpair`:

```go
func (s *Server) handleDeviceUnpair(w http.ResponseWriter, r *http.Request) {
    // Capture identity BEFORE clearing for audit trail.
    deviceID := s.GetDeviceID()        // helper to add
    branchID := s.workstationBranchID()

    // 1. Best-effort cloud revoke (don't block unpair if cloud down).
    if token := s.GetDeviceToken(); token != "" {
        go s.notifyCloudUnpair(token)  // POST /workstation/self-revoke
    }

    // 2. Clear ALL settings keys set by pair (sync with handleDevicePair).
    keysToClear := []string{
        "device_token", "device_id", "device_name", "device_type",
        "device_status", "workstation_branch_id", "organization_id",
        "last_sync_at",  // Sprint 2 recovery flag
    }
    // (cloud_api_url intentionally kept — operator might re-pair same cloud)

    // 3. Wipe local mirror tables in single transaction.
    err := s.db.Transaction(func(tx *sql.Tx) error {
        for _, key := range keysToClear {
            tx.Exec("UPDATE settings SET value = '' WHERE key = ?", key)
        }
        // Drop mirrored cloud data — operator must re-pair to restore.
        for _, table := range []string{
            "orders", "order_items",
            "menu_items", "zones", "tables",
            "inventory_lots", "devices",
            "auth_token_cache",
            "sync_queue",  // pending pushes to old cloud — moot now
        } {
            tx.Exec("DELETE FROM " + table)
        }
        return nil
    })

    s.auditLog(r, "device.unpair", "device", deviceID, fmt.Sprintf(`{"branch":"%s"}`, branchID))
    writeJSON(w, http.StatusOK, map[string]any{"status": "ok"})
}
```

### Backend-side (~2h)

Add `POST /api/v1/workstation/self-revoke` endpoint with `device.auth:workstation` middleware (uses own device_token to authenticate the revoke):

```php
// routes/api/workstation.php
Route::post('self-revoke', [DeviceController::class, 'selfRevoke'])
    ->name('api.v1.workstation.self-revoke');

// New controller method
public function selfRevoke(Request $request): JsonResponse
{
    $device = $request->attributes->get('device');
    $device->update([
        'status' => DeviceStatusEnum::Revoked->value,
        'device_token' => null,
        'paired_at' => null,
    ]);
    return response()->json(['status' => 'revoked'], 200);
}
```

Plus 2-3 Pest tests (success, missing token, idempotent).

### Frontend-side (~1h)

`frontend/src/pages/Settings.tsx` (or similar) add button:

```tsx
<Button variant="destructive" onClick={async () => {
  if (!confirm("Unpair workstation? Tất cả data local sẽ bị xoá.")) return;
  await unpairDevice();
  navigate("/pair");  // route back to pairing screen
}}>
  Unpair Workstation
</Button>
```

`api.ts` add:

```ts
export const unpairDevice = () => post("/api/device/unpair");
```

---

## Lessons learned

### 1. Pair/unpair asymmetry — rủi ro thường xuyên

Khi schema mở rộng (Sprint 1 thêm branch_id), pair flow được fix nhưng unpair flow thường bị quên. Tương lai cần checklist:

- [ ] Mỗi settings key mới được set trong `handleDevicePair` → có trong `handleDeviceUnpair`'s clear list?
- [ ] Mỗi cloud table được populate sau pair → có DELETE trong unpair?
- [ ] Mỗi cache layer (auth_token_cache, etc.) → có invalidate trong unpair?

→ **Action Sprint 3+**: Thêm test `TestUnpairResetsAllSettings` check `SELECT COUNT(*) FROM settings WHERE value != ''` = 0 sau unpair (trừ cloud_api_url).

### 2. Cloud-local asymmetry — cần symmetric API

Pair là 2-way (workstation ← cloud), nhưng unpair là 1-way (chỉ local). Đối xứng kém → security gap.

→ **Action**: Cloud nên expose self-revoke endpoint (device.auth) song song với pair endpoint.

### 3. Half-done features là technical debt thật

`handleDeviceUnpair` được code Phase 1 lúc Sprint 1 chưa diễn ra. Code "có chạy" → ai cũng nghĩ done. Thực tế chỉ là stub. Phải code-review threshold: "feature done" = full E2E test + UI integration, không chỉ endpoint hoạt động.

---

## Related

- Sprint 3 plan: [docs/plan/05-sprint-3-unpair-and-carryovers.md](../plan/05-sprint-3-unpair-and-carryovers.md) — task breakdown
- Sprint 1 pair flow bug doc: [2026-05-20-device-metadata-discarded-at-pair.md](2026-05-20-device-metadata-discarded-at-pair.md) — class anh em (pair-side)
- Sprint 2 recovery flow: pair triggers Recover → unpair phải invalidate Recovery state too
