# BUG-2026-05-21-01 — Branch timezone NULL → menu pull empty

| Field | Value |
|---|---|
| **Status** | ✅ FIXED (Cách 3 landed — MockDataSeeder sets timezone/currency/locale for all branches) |
| **Severity** | 🟡 Important (block menu sync flow, không crash, có workaround) |
| **Discovered** | 2026-05-21 (E2E demo prep — paired workstation thấy menu empty) |
| **Live-patched** | 2026-05-21 (Cách 2 — UPDATE branches SET timezone) |
| **Affected layer** | Backend cloud — `database/seeders/MockDataSeeder.php` |
| **Permanent fix commit** | TODO (apply Cách 3 trước khi push) |

---

## Tóm tắt

`MockDataSeeder` tạo 5 branches Nhật nhưng KHÔNG set `timezone`/`currency`/`locale`. Khi workstation paired và pull menu, cloud's `CustomerMenuService::getMenuForBranch` fallback timezone về app default = UTC → menu schedules (intended JST) không match giờ hiện tại → trả `{"data": null}` → workstation local menu rỗng.

---

## Triệu chứng

User-facing:
- Pair workstation thành công, status `paired: true`, token valid
- UI Menu tab hiện "Không có dữ liệu" sau nhiều phút chờ
- Indicator Online (góc dưới-trái) vẫn xanh

API-facing:
```sh
curl -H "Authorization: Bearer <token>" http://localhost:5400/api/v1/workstation/menu
# → {"data":null,"generated_at":"2026-05-21T02:01:40+00:00"}
```

Local SQLite:
```sh
sqlite3 ~/.ws-app/ws-app.db "SELECT COUNT(*) FROM menu_items;"
# → 0
```

---

## Root cause

### Filter timezone-aware trong `CustomerMenuService::getMenuForBranch`

[backend/app/Services/Customer/CustomerMenuService.php](../../../backend/app/Services/Customer/CustomerMenuService.php) line ~30-50:

```php
$branch = Branch::find($branchId);
$timezone = $this->resolveBranchTimezone($branch);  // NULL → fallback config('app.timezone')

$now = Carbon::now($timezone);
$currentTime = $now->format('H:i:s');

$query = Menu::where('branch_id', $branchId)
    ->where('status', 'active')
    ->where(function ($q) use (...) {
        $q->whereDoesntHave('schedules')   // OR always-on menu
            ->orWhereHas('activeSchedules', function ($s) use ($currentTime) {
                $s->where('start_time', '<=', $currentTime)
                  ->where('end_time', '>=', $currentTime);
            });
    });
```

### Chuỗi tính giờ sai

| Layer | Giá trị |
|---|---|
| `branch.timezone` | **NULL** ← root cause |
| `resolveBranchTimezone()` fallback | `config('app.timezone')` = `UTC` |
| `Carbon::now('UTC')` lúc demo | `02:14` |
| `MenuSchedule` của 渋谷店 (intended JST) | `lunch: 11:00-14:30`, `dinner: 17:00-22:00` |
| 02:14 UTC nằm trong schedule nào? | KHÔNG → return `null` |
| Đúng ra: 02:14 UTC = **11:14 JST** | match lunch → return menu |

### Vì sao seeder thiếu timezone

[backend/database/seeders/MockDataSeeder.php:221-241](../../../backend/database/seeders/MockDataSeeder.php#L221-L241) — `Branch::updateOrCreate` truyền 13 fields (slug, name, is_headquarters, address, phone, weekly_hours, etc.) nhưng **không có** `timezone`, `currency`, `locale`. DB columns nullable nên row tạo thành công với NULL.

Bug ẩn vì:
1. Customer-web/Kiosk có thể test ở hours khác (vd. 11h-14h JST = 02h-05h UTC) — vô tình match
2. Demo trước nay không test fresh-pair flow → menu cache cũ có sẵn
3. Workstation là client **mới** lần này thử pair fresh → expose bug

---

## Cách fix

### Cách 1 (đã skip) — Fix 1 branch

```sql
UPDATE branches SET timezone='Asia/Tokyo' WHERE id='<specific>';
```

Chỉ fix 1 branch đang demo, các branch khác vẫn NULL.

### Cách 2 (đã áp dụng — live patch hôm nay) ✅

```sh
docker compose exec mysql mysql -uroot -psecret tempo -e \
"UPDATE branches SET timezone='Asia/Tokyo', currency='JPY', locale='ja' WHERE timezone IS NULL;"
```

**Effect**: Tất cả 5 branches có timezone/currency/locale. Workstation pull menu trong ≤60s sau (verified — 36 menu_items xuất hiện local). Tạm thời cho demo hôm nay (2026-05-21).

**Hạn chế**: Patch chỉ tồn tại trong live DB. Bất kỳ `migrate:fresh --seed` nào cũng wipe lại NULL.

### Cách 3 (permanent, cần apply trước khi push) ⏳

Sửa `backend/database/seeders/MockDataSeeder.php` để mỗi `Branch::updateOrCreate` luôn set 3 field này.

**Diff cần apply** ([line ~226-240](../../../backend/database/seeders/MockDataSeeder.php#L226-L240)):

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
         'is_active' => true,
         'is_standalone' => false,
         'address' => $branch['address'],
         'phone' => $branch['phone'],
         'img_branches' => $branch['img_branches'] ?? null,
         'seat_capacity' => $branch['seat_capacity'],
         'business_hours' => $branch['business_hours'],
         'weekly_hours' => $branch['weekly_hours'],
     ]
 );
```

Sau khi apply:
1. `vendor/bin/pint --dirty --format agent` (Laravel Boost convention)
2. `docker compose exec app php artisan db:seed --class=MockDataSeeder --force` (verify locally)
3. Commit với message `fix(seeders): set timezone/currency/locale on Japanese branches`

**Effect**: Mọi `migrate:fresh --seed` (CI, dev mới onboard, tests) đều có branches valid timezone từ đầu. Bug không tái phát.

---

## Verification sau Cách 2

```sh
# 1. UPDATE applied (5 rows touched)
docker compose exec mysql mysql -uroot -psecret tempo -e \
  "SELECT name, timezone, currency, locale FROM branches;"
# → tất cả 5 branches Asia/Tokyo, JPY, ja

# 2. Cloud trả menu (không phải null)
TOKEN=$(sqlite3 ~/.ws-app/ws-app.db "SELECT value FROM settings WHERE key='device_token';")
curl -s -H "Authorization: Bearer $TOKEN" http://localhost:5400/api/v1/workstation/menu | head -c 200
# → {"data":{"menu_id":"...","menu_name":"ベトキッチン — 渋谷店","schedule_start_time":"11:00:00",...

# 3. SyncPuller upsert vào local (đợi ≤60s)
sqlite3 ~/.ws-app/ws-app.db "SELECT COUNT(*) FROM menu_items;"
# → 36 (lunch menu 渋谷店)
```

---

## Lessons learned

### 1. Seeder thiếu mandatory business config

Branches Nhật thiếu timezone/currency/locale là vi phạm convention "seed phải tạo data hợp lệ cho mọi flow downstream". `MockDataSeeder` tập trung vào fields visible (name, address, hours) mà bỏ qua fields invisible (timezone) → flows phụ thuộc time silently fail.

**Action Sprint 2+**: Add pest test cho `CustomerMenuService::getMenuForBranch` mà gọi sau `php artisan migrate:fresh --seed --class=MockDataSeeder` rồi assert non-null khi branch có active menu trong giờ hiện tại.

### 2. Fallback default UTC dễ misleading

`Carbon::now($timezone='UTC')` khi config branch missing → quan điểm "fail-open" (luôn tính được giờ) nhưng "fail-silent" (kết quả sai). Should:
- Log Warning khi branch.timezone NULL: `slog.Warn("branch missing timezone", "branch_id", X)`
- Hoặc fail-close: return null + error code rõ ràng cho client

### 3. E2E test fresh-pair là crucial

Bug này không lộ ra trong unit/feature test vì test DB tự seed với defaults, nhưng end-to-end:
1. `migrate:fresh --seed`
2. Workstation pair fresh
3. Wait 60s
4. Assert menu_items > 0

Lần đầu chạy flow này là lúc bug lộ ra. Workstation team được "blessed" tìm ra trong pre-pilot prep.

**Action**: Thêm E2E pair test vào CI (workstation-app `.github/workflows/ci.yml` Sprint 2).

---

## Related

- [BUG-2026-05-20-01](2026-05-20-devices-table-name-conflict.md) — devices table conflict (cùng class "data layer bug ẩn cho tới khi fresh-pair")
- [BUG-2026-05-20-02](2026-05-20-device-metadata-discarded-at-pair.md) — device metadata discarded (cùng class "silent data omission")
- Sprint 1 plan: [docs/plan/03-sprint-1-ops-hardening.md](../plan/03-sprint-1-ops-hardening.md)
