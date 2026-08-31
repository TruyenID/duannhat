---
title: "POS-web direct-to-cloud auth"
category: explanation
tags: [pos-web, auth, sanctum, route-group]
summary: "Design record for authenticating pos-web straight against Cloud on the /api/v1/pos/* route group instead of only through the workstation LAN."
related: []
---

# POS-Web Direct-to-Cloud Auth

> **Phạm vi**: Backend — `/api/v1/pos/*` route group
> **Trạng thái**: **ĐÃ SHIP** — `backend/routes/api/pos.php` tồn tại và mang 81 route.
> Đây là **bản ghi thiết kế**, giữ lại để giải thích *vì sao* đường auth có hình dạng
> hiện tại; nó không còn là việc phải làm.
> **Ngày viết**: 2026-07-08 · **đối chiếu code**: 2026-08-05 (#1900)

---

## 1. Bối cảnh

POS-Web authenticates via device pairing (device token). Cloud `/api/v1/pos/*` routes yêu cầu SSO Sanctum token (middleware `sso.auth`). Khi workstation offline, auto-mode fallback lên cloud trả về 401 → `apiFetch` clear session → redirect về `/pairing`.

```
User nhập pairing code
→ POST /api/v1/devices/pair (cloud, public) ✅
→ Lưu device_token vào localStorage
→ ShiftGate render → gọi useTillCurrent()
→ apiFetch("/api/v1/pos/till/current")
→ Auto mode: thử workstation → NETWORK ERROR
→ Fallback cloud với device token
→ Cloud sso.auth reject → 401
→ clearSession() → redirect /pairing 🔄
```

**Nguyên nhân gốc:** POS routes mounted trong group `Route::prefix('v1')->middleware(['sso.auth'])` (file `routes/api.php:118`). `sso.auth` chỉ chấp nhận Sanctum user token — device token bị reject.

### Tại sao workstation work?

Workstation là LAN gateway: POS-Web gửi device token tới workstation → workstation validate token local → forward request lên cloud với internal auth → cloud nhận request từ internal nên pass `sso.auth`.

Không có workstation → POS-Web device token không thể pass `sso.auth` trên cloud.

---

## 2. Yêu cầu

- POS-Web có thể login và sử dụng cloud API khi workstation offline
- Zero impact lên device auth của Kiosk, KDS, Handy, Workstation, TMS
- Device (type=pos) chỉ có thể truy cập branch của nó
- SSO users (admin-web) vẫn dùng được POS routes qua `sso.auth`

---

## 3. Giải pháp

Compound middleware `AuthenticateSsoOrDevice` chấp nhận cả SSO Sanctum token và POS device token. POS routes được tách ra khỏi group `sso.auth` và đặt trong group riêng với middleware mới này.

Khi device auth success:
- Set `$request->attributes->set('device', $device)` — cho controller biết đang device auth
- Set `$request->setUserResolver(fn() => $device)` — `$request->user()` trả về Device model (có `->id`, `->name`)
- Set `$request->attributes->set('_device_bypass_gate', true)` — scoped flag cho `Gate::before()`
- NOT set `Auth::setUser()` — `Auth::user()` vẫn null (tránh conflict Sanctum guard)

### Auth flow mới

```
User nhập pairing code
→ POST /api/v1/devices/pair (cloud, public) ✅
→ Lưu device_token
→ ShiftGate: apiFetch("/api/v1/pos/till/current")
→ Auto mode: thử workstation → NETWORK ERROR
→ Fallback cloud với device token
→ AuthenticateSsoOrDevice: device token valid ✅
→ ResolvePosShop: device branch match shop slug ✅
→ TillController::current(): Gate::before() bypass ✅
→ 200 OK
→ Login success 🎉
```

---

## 4. Zero-Impact Guarantee — Các Device Khác

Tất cả device routes khác đều hoàn toàn độc lập:

| Routes | Middleware | Dùng `$this->authorize()`? | File |
|--------|-----------|---------------------------|------|
| Kiosk | `device.auth:kiosk` | ❌ | `routes/api/kiosk.php` |
| KDS | `device.auth:kds` | ❌ | `routes/api/kds.php` |
| Handy | `device.auth:handy,pos` | ❌ | `routes/api/handy.php` |
| Workstation | `device.auth:workstation` | ❌ | `routes/api/workstation.php` |
| TMS | `device.auth:tms` | ❌ | `routes/api/tms.php` |
| **POS** (đang sửa) | `auth.sso_or_device` (mới) | ✅ | `routes/api.php:pos` |

Key safeguard: `Gate::before()` check scoped attribute `_device_bypass_gate` — chỉ set bởi `AuthenticateSsoOrDevice`, KHÔNG set bởi `device.auth` cũ. Zero cross-contamination.

---

## 5. Files cần tạo (2 files)

### 5a. `app/Http/Middleware/AuthenticateSsoOrDevice.php`

```php
<?php

namespace App\Http\Middleware;

use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use App\Services\Device\DeviceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSsoOrDevice
{
    public function __construct(
        private readonly DeviceService $service,
    ) {}

    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Token required.'], 401);
        }

        // Try device auth first (faster — single query, no Sanctum overhead)
        $device = $this->service->findByToken($token);

        if ($device && $device->status === DeviceStatusEnum::Active) {
            $allowedTypes = ! empty($types) ? $types : ['pos'];
            $allowedTypes = array_map(
                fn (string $t) => DeviceTypeEnum::tryFrom($t),
                $allowedTypes,
            );

            if (! in_array($device->type, $allowedTypes, true)) {
                return response()->json(['message' => 'Device type not allowed for this endpoint.'], 403);
            }

            $request->attributes->set('device', $device);
            $request->setUserResolver(fn () => $device);
            $request->attributes->set('_device_bypass_gate', true);
            $this->service->heartbeat($device);
            return $next($request);
        }

        // Fall back to SSO auth
        if (Auth::guard('sanctum')->check()) {
            $user = Auth::guard('sanctum')->user();
            $request->setUserResolver(fn () => $user);
            $request->attributes->set('ssoUser', $user);
            Auth::setUser($user);
            return $next($request);
        }

        return response()->json(['message' => 'Invalid token or unauthenticated.'], 401);
    }
}
```

### 5b. `tests/Feature/Middleware/AuthenticateSsoOrDeviceTest.php`

| Test case | Expected |
|-----------|----------|
| Returns 401 when no token | 401 |
| Returns 401 when invalid token | 401 |
| Returns 401 when device inactive | 401 |
| Returns 403 when device type is not pos (e.g. kds, tms) | 403 |
| Returns 200 with valid POS device token | 200 |
| Returns 200 with valid SSO token (backward compat) | 200 |
| `_device_bypass_gate` attribute set on device auth | Assert attribute |
| `ssoUser` attribute set on SSO auth | Assert attribute |

---

## 6. Files cần sửa (6 files)

### 6a. `routes/api.php` — Tách POS routes khỏi `sso.auth`

**Trước (POS bên trong `sso.auth` group):**

```php
Route::prefix('v1')
    ->middleware(['sso.auth'])
    ->group(function () {
        // me, hq, shops, pos, files
    });
```

**Sau**: SSO group giữ nguyên (`me`, `hq`, `shops`, `files`), POS tách thành một
group riêng mang `['auth.sso_or_device', 'throttle:pos']` + `ResolvePosShop`.

Luật viết route là của backend và **chỉ có một bản** — code group POS đầy đủ nằm
ở [`backend/docs/contributing/route.md` § Entry Point](../../backend/docs/contributing/route.md#entry-point),
bản chạy thật ở `backend/routes/api.php`. Đừng chép lại vào đây: bản chép sẽ trôi
khỏi cả hai.

### 6b. `bootstrap/app.php` — Register middleware

```php
$middleware->alias([
    'device.auth' => AuthenticateDevice::class,
    'auth.sso_or_device' => AuthenticateSsoOrDevice::class,  // ADD
]);

$middleware->prependToPriorityList(
    ThrottleRequests::class,
    AuthenticateSsoOrDevice::class,  // ADD
);
```

Note: `device.auth` đã tồn tại — chỉ thêm alias mới, không duplicate.

### 6c. `app/Http/Middleware/Concerns/ResolvesShopContext.php` — Device branch validation + skip IAM

Trong `bindShopContext()`, sau khi shop lookup thành công và org resolved:

```php
// Device auth: validate device branch matches shop
$device = $request->attributes->get('device');
if ($device) {
    if ((string) $device->branch_id !== (string) $shop->id) {
        abort(403, 'Device not authorized for this shop.');
    }
}

$user = $request->user();

// Skip IAM check for device requests (device ID ≠ SSO user ID)
if ($user && ! $device) {
    if (! $organization) {
        abort(403, 'Shop does not belong to a known organization.');
    }

    $hasAccess = DB::table('role_user_pivots')
        ->where('user_id', $user->id)
        ->where('organization_id', $organization->id)
        ->exists();

    if (! $hasAccess) {
        abort(403, 'You do not have access to this shop.');
    }
}
```

**Critical:** Dùng `(string)` cast, KHÔNG `(int)`. Cả `branch_id` và `shop->id` là UUID — `(int)` trên UUID ra 0 → comparison luôn pass, bypass security.

### 6d. `app/Providers/AppServiceProvider.php` — Gate::before + rate limiter

Trong `boot()`:

```php
// Device auth bypasses policy checks for POS routes.
// Device authorization is handled by ResolvesShopContext branch validation.
// This callback must have ZERO params (user is null for device auth) to
// pass Gate::callbackAllowsGuests() reflection check.
Gate::before(function () {
    if (request()?->attributes->get('_device_bypass_gate')) {
        return true;
    }
});
```

Trong `configureRateLimiters()`:

```php
RateLimiter::for('pos', fn (Request $request) => Limit::perMinute(120)
    ->by($request->attributes->get('device')?->id ?? $request->ip()));
```

### 6e. `app/Http/Controllers/Traits/HasOrganizationContext.php` — getOrganizationId fallback

```php
protected function getOrganizationId(): string
{
    // Device-authenticated requests: org already resolved by
    // ResolvesShopContext (from X-Shop-Slug header lookup).
    $orgId = request()->attributes->get('organization_id');
    if ($orgId) {
        return $orgId;
    }

    $user = request()->user();

    if (! $user) {
        abort(401, 'Unauthenticated');
    }

    $consoleOrgId = $user->console_organization_id;

    if (! $consoleOrgId) {
        abort(400, 'No organization assigned');
    }

    $cacheKey = 'organization_id:'.$consoleOrgId;
    $cached = request()->attributes->get($cacheKey);

    if ($cached) {
        return $cached;
    }

    $localId = Organization::where('console_organization_id', $consoleOrgId)
        ->value('id');

    if (! $localId) {
        abort(403, 'Organization not found in this service.');
    }

    request()->attributes->set($cacheKey, $localId);

    return $localId;
}
```

### 6f. `app/Http/Controllers/Api/V1/Pos/PosMeController.php` — Device-aware response

```php
public function show(Request $request): JsonResponse
{
    $user = $request->user();
    $device = $request->attributes->get('device');
    /** @var Branch $shop */
    $shop = $request->attributes->get('shop');

    return response()->json([
        'user' => $device ? null : [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale ?? null,
            'timezone' => $user->timezone ?? null,
        ],
        'device' => $device ? [
            'id' => $device->id,
            'name' => $device->name,
            'type' => $device->type->value,
            'branch_id' => $device->branch_id,
        ] : null,
        'shop' => [
            'id' => $shop->id,
            'slug' => $shop->slug,
            'name' => $shop->name,
            'console_brand_id' => $shop->console_brand_id,
        ],
    ]);
}
```

### 6g. `tests/Feature/Pos/ResolvePosShopTest.php` — Add device test cases

Add new tests:

- `returns 403 when device branch does not match X-Shop-Slug`
- `returns 200 when device belongs to the correct branch`
- Existing `returns 401 when no SSO token is present` vẫn pass (no bearer token → compound middleware 401)

### 6h. `routes/api/pos.php` — Update docblock comment

Dòng 26-27 sửa từ "sso.auth prefix" thành "auth.sso_or_device prefix".

---

## 7. Downstream Controller Behavior Under Device Auth

### 7a. `$request->user()` trả về Device model

- `$request->user()->id` → device UUID (stored in `created_by_id`, `received_by_id`)
- `$request->user()->name` → device name (e.g. "POS-Terminal-3")
- `$request->user()->email` → null (Device không có email column)
- Pattern đã có precedent: Workstation (`PaymentController:120`) và Kiosk (`KioskController:323`) cũng ghi device UUID vào audit columns

### 7b. `$this->authorize()` — Gate::before bypass

Tất cả policy checks trong POS controllers được bypass khi `_device_bypass_gate` active. Authorization được đảm bảo bởi device branch validation ở middleware level.

### 7c. `Auth::user()` và `Auth::id()` — null

- `TillSessionService::open()` dùng `Auth::id()` fallback cho `opened_by_id` — POS-Web gửi staff UUID từ dropdown nên không rely trên Auth
- `TillSessionService::close()`, `abandon()` dùng `Auth::id()` cho `closed_by_id` — tương tự, POS-Web gửi staff UUID
- `TillSessionController::forceAbandon()`, `manualSettle()` gọi `Auth::user()` — là manager-only endpoints, device auth SHOULD NOT gọi

### 7d. Audit trail: created_by_id / received_by_id

`customer_orders.created_by_id` và `order_payments.received_by_id` là UUID columns không có FK constraint. Device UUID được ghi vào — giống pattern workstation/kiosk hiện tại. Không break downstream queries (không có JOIN `orders.created_by_id → users.id` trong app code).

---

## 8. Implementation Tasks

| # | Task | File | Dependency |
|---|------|------|-----------|
| 1 | Create `AuthenticateSsoOrDevice` middleware | `app/Http/Middleware/AuthenticateSsoOrDevice.php` | — |
| 2 | Register alias `auth.sso_or_device` + priority list | `bootstrap/app.php` | #1 |
| 3 | Add `Gate::before()` và rate limiter `pos` | `app/Providers/AppServiceProvider.php` | #1 |
| 4 | Add device branch validation trong `ResolvesShopContext` | `app/Http/Middleware/Concerns/ResolvesShopContext.php` | — |
| 5 | Fix `getOrganizationId()` fallback trong `HasOrganizationContext` | `app/Http/Controllers/Traits/HasOrganizationContext.php` | — |
| 6 | Adapt `PosMeController` cho device response | `app/Http/Controllers/Api/V1/Pos/PosMeController.php` | #1 |
| 7 | Split POS routes trong `routes/api.php` | `routes/api.php` | #2 |
| 8 | Update docblock trong `routes/api/pos.php` | `routes/api/pos.php` | #7 |
| 9 | Create `AuthenticateSsoOrDeviceTest` | `tests/Feature/Middleware/AuthenticateSsoOrDeviceTest.php` | #1 |
| 10 | Add device test cases trong `ResolvePosShopTest` | `tests/Feature/Pos/ResolvePosShopTest.php` | #4 |
| 11 | Run full POS test suite | `php artisan test --compact --filter=Pos` | #9, #10 |
| 12 | Verify zero impact: run other device tests | `php artisan test --compact --filter=Kds\|Kiosk\|Handy\|Workstation\|Tms` | #11 |

---

## 9. Edge Cases & Risks

| # | Issue | Decision |
|---|-------|----------|
| E1 | KDS/Kiosk token gửi tới POS routes | Middleware `device.type === 'pos'` check → 403 (trước SSO fallback) |
| E2 | Revoked device token gửi tới POS | `status !== Active` → exit device path → SSO check → token không phải Sanctum → 401 |
| E3 | Device auth gọi manager endpoints (forceAbandon) | `Auth::user()` null → controller abort hoặc null audit. Acceptable: manager actions cần SSO login thật |
| E4 | `$request->user()->email` trả về null trên Device | `PosMeController` return `user: null` khi device auth — POS-Web không dùng email field |
| E5 | Device `created_by_id` không JOIN được với `users` table | Không có FK constraint. Audit trace được qua `devices.id` |
| E6 | Performance: device token query mỗi request | Single query `WHERE device_token = ?` + index. Có thể cache sau này nếu cần |
