# Kế hoạch — API Gọi Nhân Viên (Call Staff)

## Tổng quan

Thêm API cho phép set/clear `call_requested_at` trên bàn, và thêm nút "Đã xử lý" trên TMS app khi bàn đang ở trạng thái `call_staff`. Card bàn sẽ nhấp nháy để thu hút sự chú ý của nhân viên.

---

## Flow

```
Khách bấm gọi nhân viên (scan QR trên bàn)
    ↓
POST /api/v1/tms/tables/{qr_token}/call  ← public, không cần auth
    ↓
Backend: tìm bàn theo qr_token
    → 422 nếu status !== "occupied"
    → Set call_requested_at = NOW() nếu hợp lệ
    ↓
TMS app polling 15s → nhận data mới
    ↓
home.tsx: getDisplayState() → "call_staff"
    → card đỏ + chuông + animation nhấp nháy
    ↓
Nhân viên nhìn thấy → nhấn "Đã xử lý" trên card
    ↓
DELETE /api/v1/tms/tables/{table}/call
    → invalidateQueries ngay (không đợi 15s polling)
    ↓
Backend clear call_requested_at = null
    ↓
TMS app refetch → card về trạng thái bình thường
```

---

## Các file thay đổi

### Backend (`/Users/phamduyanh1910/Documents/famgia/tempo/backend`)

#### 1. `routes/api/tms.php` — Thêm 2 route

2 route có middleware khác nhau:

```php
// Public — khách gọi qua qr_token (không cần auth)
Route::post('tables/{qrToken}/call', [TmsController::class, 'callStaff']);

// Protected — nhân viên xử lý qua device token
Route::middleware('device.auth:tms')->delete('tables/{table}/call', [TmsController::class, 'clearCall']);
```

#### 2. `app/Http/Controllers/Api/V1/Tms/TmsController.php` — Thêm 2 method

**`callStaff(string $qrToken)`**
- Tìm table theo `qr_token` (unique) — không cần device auth
- Kiểm tra `is_active = true`
- Kiểm tra `status === "occupied"` → 422 nếu không phải (khách chỉ gọi khi đang dùng bàn)
- Set `call_requested_at = now()`
- Trả về table data mới

**`clearCall(Request $request, Table $table)`**
- Đã qua middleware `device.auth:tms` — chỉ device đã pair mới gọi được
- Filter `branch_id` của device — không thể tác động bàn của chi nhánh khác
- Clear `call_requested_at = null`
- Trả về table data mới

---

### TMS App (`/Users/phamduyanh1910/Documents/famgia/godx-tempo-tmt`)

#### 3. `src/lib/api.ts` — Không cần sửa
`apiFetch()` đã xử lý Bearer token + timeout.

#### 4. `src/hooks/query-keys.ts` — Không cần sửa
Mutation invalidate dùng `zoneKeys.list` đã có sẵn.

#### 5. `src/hooks/use-call-staff.ts` — Hook mới

**Rule 2**: Dùng `useMutation`, không dùng `useState` + `fetch` thủ công.
**Rule 3**: Dùng `zoneKeys.list` từ `query-keys.ts`, không hardcode string.
**Rule 5**: Gọi API qua `apiFetch()`, không dùng `fetch()` trực tiếp.

```ts
// Expose 2 mutation:
useClearCall()  → DELETE /api/v1/tms/tables/{id}/call
                → onSuccess: invalidateQueries(zoneKeys.list) → refetch ngay
```

> `callStaff` không cần implement trong TMS app vì TMS là màn hình nhân viên,
> không phải màn hình khách. Khách gọi từ app/web riêng.

**Rule 19**: Phải có unit test cho hook này tại `src/hooks/use-call-staff.test.ts`.

#### 6. `app/home.tsx` — Thêm animation + nút "Đã xử lý" vào TableCard

**Rule 4**: Business logic (animation, mutation) extract ra khỏi render inline.
**Rule 11**: Dùng NativeWind `className`, không dùng `StyleSheet`.
**Rule 17**: Không compute inline trong render.
**Rule 18**: `onPress` handler wrap `useCallback`.

**Animation (react-native-reanimated — đã có sẵn trong package.json):**

```ts
// Trong TableCard — chỉ chạy khi ds === "call_staff"
const opacity = useSharedValue(1);

useEffect(() => {
  if (ds === 'call_staff') {
    opacity.value = withRepeat(
      withSequence(
        withTiming(0.3, { duration: 500 }),
        withTiming(1,   { duration: 500 }),
      ),
      -1,  // lặp vô hạn
    );
  } else {
    cancelAnimation(opacity);
    opacity.value = withTiming(1);
  }
}, [ds]);
```

Card `call_staff` wrap trong `Animated.View` với `useAnimatedStyle`.

**UI card khi call_staff:**

```
┌─────────────────┐
│  T-01    🔔     │  ← nền đỏ, nhấp nháy
│  T-01           │
│  4 ghế          │
│  Gọi nhân viên  │
│  [✓ Đã xử lý]  │  ← nút nhỏ, chỉ hiện khi call_staff
└─────────────────┘
```

Nhấn "Đã xử lý" → `clearCall(table.id)` → invalidate → refetch ngay.

#### 7. `src/i18n/ja.json` + `en.json` + `vi.json` — Thêm key

**Rule 13**: Text qua `t()`, không hardcode.
**Rule 14**: Viết `ja` trước.

```json
// ja.json
"action.call_resolved": "対応済み"

// en.json
"action.call_resolved": "Resolved"

// vi.json
"action.call_resolved": "Đã xử lý"
```

---

## Review theo CLAUDE.md Rules

| Rule | Nội dung | Status |
|------|----------|--------|
| 2 | Data fetching qua `useMutation` | ✅ hook dùng `useMutation` |
| 3 | Query keys từ `query-keys.ts` | ✅ dùng `zoneKeys.list` |
| 4 | Business logic trong `src/`, screen trong `app/` | ✅ hook tách riêng |
| 5 | API call qua `apiFetch()` | ✅ |
| 9 | Không dùng `any` | ✅ type rõ ràng |
| 11 | NativeWind only | ✅ không dùng StyleSheet |
| 13 | Text qua `t()` | ✅ |
| 14 | Ja trước | ✅ |
| 17 | `useMemo` cho derived data | ✅ không compute inline |
| 18 | `useCallback` cho event handler | ✅ onPress wrap useCallback |
| 19 | Unit test cho hook mới | ✅ cần tạo `use-call-staff.test.ts` |
| 20 | TypeScript check trước commit | ✅ chạy `npx tsc --noEmit` |

---

## Bảo mật

| Endpoint | Auth | Lý do |
|----------|------|-------|
| `POST .../call` | Public (qr_token) | Khách không có device token, QR token đủ để identify bàn |
| `DELETE .../call` | `device.auth:tms` | Chỉ nhân viên có thiết bị đã pair mới được xử lý |

- `qr_token` là unique, 64 ký tự — không thể đoán
- `callStaff` chỉ cho phép khi `status === "occupied"` — tránh spam gọi bàn trống
- `clearCall` filter theo `branch_id` của device — không tác động bàn chi nhánh khác

---

## Không cần làm

- Migration: `call_requested_at` đã có sẵn trong DB
- Thay đổi polling interval: 15s đủ, sau `clearCall` có `invalidateQueries` ngay
- Thay đổi `src/types/tms.ts`: `call_requested_at` đã có
- Cài thêm package: `react-native-reanimated` đã có trong `package.json`

---

## Thứ tự thực hiện

1. Sửa `routes/api/tms.php` (backend)
2. Thêm `callStaff()` + `clearCall()` vào `TmsController.php` (backend)
3. Thêm i18n keys vào `ja.json`, `en.json`, `vi.json` (app)
4. Tạo `src/hooks/use-call-staff.ts` (app)
5. Tạo `src/hooks/use-call-staff.test.ts` (app)
6. Sửa `app/home.tsx` — thêm animation + nút "Đã xử lý" vào `TableCard` (app)
7. Chạy `npx tsc --noEmit` kiểm tra TS errors
