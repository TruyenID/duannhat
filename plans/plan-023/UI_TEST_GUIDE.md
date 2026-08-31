# Notification Platform — Hướng dẫn test flow từ UI

> Mục tiêu: test toàn bộ luồng notification (HQ + Shop + cá nhân) qua **admin-web** mà không cần đụng vào Pest/Playwright. Chạy tay được từng bước, kiểm tra realtime + email + digest + rule engine.
>
> Áp dụng cho plan-023 M1–M8. Backend phải chạy ở `http://localhost:5400` (docker) hoặc `https://dxs-product.test` (Herd), admin-web ở `http://localhost:5430`.

---

## 0. Chuẩn bị môi trường

### 0.1 Bật stack
```sh
# Backend (chọn 1 trong 2)
docker compose up -d                       # canonical → :5400 + mysql + redis + mailpit + minio
# hoặc Herd: backend chạy ở https://dxs-product.test

# Reverb (realtime) — phải chạy riêng nếu test bell update / realtime channel
docker compose exec app php artisan reverb:start

# Queue worker — bắt buộc cho email + digest + scheduler tick
docker compose exec app php artisan queue:work --queue=notifications,default

# Scheduler — bắt buộc cho recurring broadcast + daily digest + offline detector
docker compose exec app php artisan schedule:work

# Admin web
cd admin-web && pnpm dev                   # → http://localhost:5430
```

### 0.2 Seed dữ liệu cơ bản
```sh
docker compose exec app php artisan migrate:fresh --seed --force
```

Sau seed sẽ có:
- 1 brand HQ + ít nhất 2 shop
- 1 SSO user `admin@example.test` / password `password` (kiểm tra `DatabaseSeeder` nếu cần)
- 25 system templates M8 (`stock.transaction.*`, `device.*`, `coupon.*`, `brand.status.*`, `product.approval.*`, ...)
- Audience defaults (`brand.admins`, `shop.managers`, ...)

### 0.3 Tài khoản test
Tối thiểu cần 3 user để verify fan-out:
1. **HQ admin** — `role=brand.admin`, dùng để vào `/hq/[brandSlug]/notifications/*`
2. **Shop manager** — `role=shop.manager` của shop A, vào `/shop/[shopSlug]/notifications/*`
3. **Shop staff** — `role=shop.staff` của shop A, chỉ vào `/inbox` để xem fan-out

Tạo thêm user bằng tinker nếu seed không có:
```sh
docker compose exec app php artisan tinker
>>> User::factory()->create(['email' => 'staff@example.test', 'password' => bcrypt('password')]);
```

### 0.4 Mở Mailpit để xem email
- Mailpit UI: http://localhost:8025
- Mọi email Postmark/SMTP đều bắn về đây trong env local.

---

## 1. Smoke test — inbox + bell realtime (5 phút)

**Mục tiêu:** Confirm pipeline `emit → DB → broadcast → Echo client → bell badge → inbox row` chạy đầu-đuôi.

### Bước 1 — Login + mở 2 tab
1. Tab A: login `admin@example.test`, vào `/inbox`.
2. Tab B: cùng user, vào `/hq/[brandSlug]/notifications` (audit page).

### Bước 2 — Trigger notification thủ công
Mở terminal thứ 3, dùng tinker:
```sh
docker compose exec app php artisan tinker
>>> app(\App\Services\Notification\NotificationService::class)->emit(
...   type: 'stock.alert.low',
...   templateKey: 'stock.alert.low',
...   audience: \App\Services\Notification\Audience\Audience::byRole('brand.admin')->scopedTo($brandId),
...   payload: ['warehouse' => 'Kho A', 'material' => 'Bột mì', 'stock' => 5],
...   priority: 'high',
... );
```

### Bước 3 — Kỳ vọng UI
- **Tab A (/inbox)** — trong vòng <2s:
  - Toast bell-icon update (xem topbar bell).
  - Tab `Unread` xuất hiện row mới với title từ template, badge `high` màu amber, dot màu amber.
  - Click row → mark-read, row chuyển sang tab `Read`.
- **Tab B (/hq/.../notifications)** — refresh hoặc realtime:
  - Row mới xuất hiện ở table audit với `recipients_summary.total > 0`.
  - Click row → Sheet detail mở, phần `Recipients` liệt kê từng user + trạng thái (`unread/seen/read/dismissed`).

### Failure modes thường gặp
| Triệu chứng | Nguyên nhân | Fix |
|---|---|---|
| Bell không update realtime | Reverb chưa chạy hoặc Echo client mismatch | `php artisan reverb:start`, kiểm tra `NEXT_PUBLIC_REVERB_*` trong `.env.local` |
| Inbox không có row nhưng audit table có | Recipient không match user đang login | Verify `Audience::byRole` resolve đúng — check `notification_recipients` table |
| Email không thấy ở mailpit | Queue worker chưa chạy hoặc kênh `email` chưa bật cho user | `php artisan queue:work`, vào `/me/settings/notifications` bật email |

---

## 2. Test M1 — Audience-resolved fan-out (10 phút)

**Mục tiêu:** Đảm bảo emitter trong code (stock alert, recipe approval, customer order) thực sự đi qua Audience engine, không còn cap-50 fallback.

### Bước 1 — Trigger stock alert thật
1. Login HQ admin → `/hq/[brandSlug]/materials/[id]` chọn 1 material.
2. Vào shop có material đó, sửa stock-level xuống dưới threshold (vd. còn 5 trong khi `low_threshold=10`).
3. Save.

### Bước 2 — Verify
- `/inbox` của HQ admin + shop manager đều có row `stock.alert.low`.
- `/hq/[brandSlug]/notifications` audit row mới — mở Sheet detail:
  - Số `recipients.total` = đúng số người có role `brand.admin` + `shop.manager` của shop đó.
  - Không có cap 50 — nếu org > 50 user, fan-out vẫn đầy đủ.

### Bước 3 — Test recipe approval
1. Shop staff submit recipe approval request (`/shop/.../recipes` → đề xuất công thức).
2. HQ admin xem `/inbox` → row `recipe.pending_approval`.
3. HQ admin click row → action approve/reject.
4. Shop staff (tab khác) → `/inbox` thấy `recipe.approved` hoặc `recipe.rejected`.

---

## 3. Test M3 — Recurring broadcasts (15 phút)

**Mục tiêu:** Compose broadcast lặp lại theo RRULE, scheduler tick job phải emit đúng giờ.

### Bước 1 — Tạo broadcast lặp lại
1. HQ admin → `/hq/[brandSlug]/notifications/compose`.
2. Wizard:
   - **Step 1 — Audience**: chọn `brand.admins`.
   - **Step 2 — Template**: chọn 1 template active (vd. `brand.weekly_summary`).
   - **Step 3 — Channels**: tick `in_app` + `email`.
   - **Step 4 — Schedule**: chọn `Repeats` → cấu hình RRULE:
     - Frequency: `Daily`
     - Time: `[now + 2 phút]`
     - End: `After 3 occurrences`
3. Click `Schedule broadcast`.

### Bước 2 — Verify schedule record
1. Vào `/hq/[brandSlug]/notifications/schedules` (hoặc `/shop/.../schedules`).
2. Thấy row mới với `rrule`, `next_occurrence_at`, `occurrences_remaining=3`.

### Bước 3 — Verify tick job
Mở terminal:
```sh
docker compose exec app php artisan tinker
>>> \App\Models\NotificationSchedule::latest()->first()
```
- `next_occurrence_at` phải đúng `now + 2 phút`.
- Đợi scheduler tick (`schedule:work` đã chạy ở §0.1).
- Sau 2 phút: `/inbox` có row mới. `next_occurrence_at` advance lên ngày tiếp theo, `occurrences_remaining=2`.

### Bước 4 — Cancel
1. Mở schedule row → click `Cancel`.
2. Confirm dialog → `status='cancelled'`. Tick job tiếp theo không emit nữa.

---

## 4. Test M4 — Email delivery + bounce (10 phút)

**Mục tiêu:** Email đi qua Postmark provider abstraction, bounce/complaint webhook update `notification_deliveries.status`.

### Bước 1 — Trigger email
1. `/me/settings/notifications` → bật `email` cho type `stock.alert.low`.
2. Lặp lại §1 bước 2 (tinker emit).
3. Mailpit (http://localhost:8025) — email phải xuất hiện.

### Bước 2 — Verify delivery row
```sh
docker compose exec app php artisan tinker
>>> \App\Models\NotificationDelivery::latest()->first()
```
- `channel='email'`, `status='sent'`, `provider_message_id` không null.

### Bước 3 — Simulate bounce (Postmark webhook)
```sh
curl -X POST http://localhost:5400/api/v1/webhooks/postmark/bounce \
  -H "Content-Type: application/json" \
  -H "X-Postmark-Signature: <signature>" \
  -d '{"RecordType":"Bounce","MessageID":"<provider_message_id>","Email":"admin@example.test","Type":"HardBounce"}'
```
- Status row chuyển `bounced`.
- Vào `/hq/[brandSlug]/notifications/email-health` — bounce list có row mới.
- User vào suppression list, email type đó không gửi nữa.

### Bước 4 — Retry dead-letter
- `/hq/[brandSlug]/notifications/email-health` có tab `Dead-letter`.
- Failed delivery có button `Retry` — click → status quay về `pending`, queue worker pick up.

---

## 5. Test M5 — Aggregation + daily digest (15 phút)

### 5.1 Aggregation collapse
1. Trigger 5 stock alert cho cùng 1 warehouse:
```sh
for i in 1 2 3 4 5; do
  docker compose exec app php artisan tinker --execute="app(\App\Services\Notification\NotificationService::class)->emit(type:'stock.alert.low', templateKey:'stock.alert.low', audience:\App\Services\Notification\Audience\Audience::byRole('shop.manager')->scopedTo('$shopId'), payload:['warehouse_id'=>'WH-A','material'=>'M$i','stock'=>$i], aggregationKey:'stock.alert.low:warehouse:WH-A');"
done
```
2. `/inbox` của shop manager — chỉ thấy **1 row collapsed**: `"5 stock alerts in Kho A"` + button `+N more`.
3. Click `+N more` → expand list 5 row.
4. Mark-read row aggregate → cả 5 underlying notification cùng mark-read.

### 5.2 Daily digest
1. `/me/settings/notifications` → section `Digest`:
   - Cadence: `Daily`
   - Time: `08:00`
   - Priority filter: `Normal trở lên`
2. Tinker — set `last_digest_at` về hôm qua để force run:
```sh
docker compose exec app php artisan tinker
>>> auth()->loginUsingId($userId); \App\Models\NotificationDigestPreference::where('user_id', $userId)->first()->update(['last_digest_at' => now()->subDay()]);
```
3. Trigger 3 notification các type khác nhau cho user.
4. Chạy job thủ công:
```sh
docker compose exec app php artisan notifications:run-digest --now
```
5. Mailpit — email digest title `Daily notification summary` body Markdown list 3 events theo type.

---

## 6. Test M6 — Shop-level admin surface (10 phút)

**Mục tiêu:** Shop manager CRUD audience/template/routing/broadcast trong scope shop, không leak qua brand hoặc shop khác.

### Bước 1 — Shop audience
1. Login shop manager → `/shop/[shopSlug]/notifications/audiences`.
2. Click `New audience` → name `shop-staff-only`, type `role`, role `shop.staff`, scope `shop:[shopId]`.
3. Save → row mới chỉ có trong shop này, vào shop khác KHÔNG thấy.

### Bước 2 — Template override
1. `/shop/[shopSlug]/notifications/templates`.
2. Pick template `stock.alert.low` (kế thừa từ brand).
3. Click `Override for this shop` → sửa body, save.
4. Trigger stock alert ở shop này → template override được dùng. Shop khác vẫn dùng brand template.

### Bước 3 — Shop broadcast
1. `/shop/[shopSlug]/notifications/compose`.
2. Audience dropdown chỉ có shop-scoped + brand-default.
3. Pick audience + template + channels → send.
4. Recipients sau khi resolve **bị intersect** với shop members (`ShopScopedAudienceResolver`). Verify ở `/inbox` của user shop khác — KHÔNG có row.

### Bước 4 — Routing override
1. `/shop/[shopSlug]/notifications/routing` → set type `coupon.expired` → channels `in_app` only (bỏ email).
2. Trigger coupon-expired → user shop này không nhận email, brand-level default email vẫn áp cho user khác.

---

## 7. Test M7 — Workflow rule-builder (15 phút)

**Mục tiêu:** Author rule không cần code, dry-run preview, evaluator emit thật khi điều kiện match.

### Bước 1 — Tạo rule
1. HQ admin → `/hq/[brandSlug]/notifications/rules` → `New rule`.
2. Sheet editor:
   - Name: `Notify when product price > 1M`
   - Trigger event: `model.updated`
   - Trigger model type: `App\Models\Product`
   - Conditions JSON (textarea):
     ```json
     {
       "combinator": "and",
       "children": [
         { "field": "price", "op": ">", "value": 1000000 },
         { "field": "status", "op": "==", "value": "active" }
       ]
     }
     ```
   - Action JSON:
     ```json
     {
       "template_key": "product.price_alert",
       "audience": "brand.admins",
       "priority": "high",
       "channels": ["in_app", "email"]
     }
     ```
   - Cooldown: `60` minutes.
   - Active: `true`.
3. Save → RuleDslValidator chạy, nếu JSON sai → error inline per leaf.

### Bước 2 — Dry-run
1. Row vừa tạo có button `Dry run` (FlaskConical icon).
2. Click → drawer load các product gần đây + simulate match.
3. UI list ra mỗi entity với `would_emit: true/false` + reason.

### Bước 3 — Trigger thật
1. Vào `/hq/[brandSlug]/products/[id]` → sửa price từ 500k lên 1.5M, save.
2. ObservedDomainEvent bridge bắt `ProductUpdated` → rule eval → emit.
3. `/inbox` HQ admin có row `product.price_alert`.
4. Sửa lại 1.5M → 2M trong vòng 60 phút → KHÔNG emit lần 2 (cooldown).

### Bước 4 — Audit
1. `/hq/[brandSlug]/notifications/rules` → row có cột `Last fired`.
2. Click history → xem từng lần evaluate + emit.

---

## 8. Test M8 — System-seeded coverage (10 phút)

**Mục tiêu:** Verify 25 system templates + rules seed đúng và emit đúng khi domain event xảy ra.

### Cheatsheet — trigger từng emitter
| Emitter | Cách trigger từ UI |
|---|---|
| `product.approval.requested` | `/hq/[brandSlug]/products/new` → save với `requires_approval=true` |
| `product.approval.approved` | HQ admin click `Approve` trên row pending |
| `stock.transaction.pending` | `/shop/.../inventory/transactions/new` → submit |
| `stock.transfer.in_transit` | `/shop/.../inventory/transfers/[id]` → mark `In transit` |
| `stock.transfer.received` | Shop nhận → mark `Received` |
| `device.paired` | `/hq/[brandSlug]/devices` tạo pairing code, app nhập code |
| `device.offline` | Tắt device 5+ phút, đợi scheduled detector chạy (`php artisan notifications:detect-offline-devices`) |
| `coupon.redeemed` | Customer dùng coupon ở POS / workstation |
| `coupon.expired` | Set `valid_until` về quá khứ, đợi detector (`notifications:detect-expired-coupons`) |
| `brand.status.suspended` | `/hq/[brandSlug]/settings` → suspend brand |
| `menu.approval.*` | `/hq/[brandSlug]/menus/[id]` → request/approve menu |

### Coverage catalogue
`/hq/[brandSlug]/notifications/coverage` — page list 25 templates + status seed + last-emit timestamp. Dùng để confirm seeder đã chạy đầy đủ.

---

## 9. Test cá nhân — preferences + quiet hours (5 phút)

### 9.1 Master mute
1. `/me/settings/notifications` → bật `Master mute`.
2. Trigger bất kỳ notification → DB vẫn có row, nhưng KHÔNG fan-out qua `email/realtime/push`. `in_app` vẫn ghi (để digest pick up).

### 9.2 Quiet hours
1. Set quiet hours `22:00–07:00`, timezone `Asia/Ho_Chi_Minh`.
2. Trigger notification priority `normal` trong giờ quiet → defer email + push.
3. Trigger priority `urgent` → vẫn bắn (urgent bypass quiet hours).

### 9.3 Per-type override
1. Section `Notification types` → row `stock.alert.low`.
2. Toggle off `email`, giữ `in_app` + `realtime`.
3. Trigger stock alert → mailpit KHÔNG có email, bell vẫn ping.

---

## 10. Realtime + multi-tab edge cases

| Case | Cách test | Kỳ vọng |
|---|---|---|
| 2 tab cùng user | Mở `/inbox` ở 2 browser tab | Mark-read tab A → tab B row update trong <2s qua Echo |
| Disconnect Reverb giữa chừng | `docker compose stop reverb` rồi trigger emit | Bell không update realtime, NHƯNG refetch khi reconnect lấy đủ |
| Cross-device pairing | Login mobile (TMS app device token) + web cùng user | Notification fan-out cả 2, mark-read 1 nơi → đồng bộ |
| Aggregation race | Trigger 2 emit cùng `aggregation_key` cách 100ms | Inbox vẫn collapse thành 1 row (DB unique idx hoặc resolver merge) |

---

## 11. Checklist trước khi báo bug

Trước khi mở issue "notification không hoạt động", kiểm tra:

- [ ] Backend up (`curl localhost:5400/api/v1/me` trả 200/401)
- [ ] Reverb chạy (`lsof -i:8080` thấy process)
- [ ] Queue worker chạy (`ps aux | grep queue:work`)
- [ ] Scheduler chạy nếu test recurring/digest (`ps aux | grep schedule:work`)
- [ ] User đang login đúng — token chưa expire (DevTools → cookies `app_token`)
- [ ] `NEXT_PUBLIC_REVERB_HOST` / `NEXT_PUBLIC_REVERB_APP_KEY` trong `admin-web/.env.local` match backend `.env`
- [ ] Audience resolve ra đúng user — query `notification_recipients` để verify
- [ ] Routing không bị user preference mute (check `/me/settings/notifications`)
- [ ] DevTools Network tab — `/api/v1/notifications/inbox` trả 200 + data
- [ ] DevTools Console — Echo subscribe success (`Pusher : State changed : connected`)

---

## 12. Tham chiếu

- **Backend service**: `backend/app/Services/Notification/NotificationService.php`
- **Audience resolver**: `backend/app/Services/Notification/Audience/AudienceResolver.php`
- **Routing**: `backend/app/Services/Notification/Routing/ChannelRouter.php`
- **Rule evaluator**: `backend/app/Services/Notification/Rules/RuleEngine.php`
- **FE inbox page**: `admin-web/src/app/inbox/page.tsx`
- **FE HQ audit**: `admin-web/src/app/hq/[brandSlug]/notifications/page.tsx`
- **FE shop compose**: `admin-web/src/app/shop/[shopSlug]/notifications/compose/page.tsx`
- **FE rules**: `admin-web/src/app/hq/[brandSlug]/notifications/rules/page.tsx`
- **FE preferences**: `admin-web/src/app/me/settings/notifications/page.tsx`
- **API endpoints**: `backend/routes/api/v1/notifications.php`
- **Realtime mount**: `admin-web/src/components/notifications/realtime-mount.tsx`

Plan tổng: `plans/plan-023/README.md` — kèm `DESIGN.md`, `TESTS.md`, `TASKS.md` cho deep-dive từng milestone.
