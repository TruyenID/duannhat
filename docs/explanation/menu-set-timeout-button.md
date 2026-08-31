---
title: "Menu Set Timeout button"
category: explanation
tags: [menu, timeout, cart, admin-web]
summary: "Design record for the Set Timeout quick action on menu detail pages — the grace period a cart gets when a menu schedule ends before its products are disabled."
related: []
---

# Set Timeout Button — Menu Detail Quick Actions

> **Phạm vi**: HQ Brand Settings + HQ menu detail + Shop Settings + Shop menu detail
> **Trạng thái**: **ĐÃ SHIP MỘT PHẦN.** Bốn tầng cấu hình ①②③④ + chain resolve
> + UI là **có thật** (`cart_timeout_minutes` trên `Brand` · `Menu` · `Branch`;
> `ShopMenuItemSettingsController` trả về đủ chain; `MenuUpdateRequest`,
> `MenuResource`, `BrandSettingsService`, `ShopBranchSettingsService`).
> **HAI phần trong tài liệu này CHƯA BAO GIỜ ĐƯỢC XÂY** và đã được đánh dấu tại
> chỗ — đừng đọc chúng như hiện trạng:
> - **§2d bảng `branch_menu_settings`** — không migration nào tạo bảng đó. Tầng
>   ④ được cài **khác** (xem §2d).
> - **§6 Checkout Validation + 422 `CART_TIMEOUT_EXPIRED`** — không có nơi nào
>   cưỡng chế deadline. Timeout hôm nay là **dữ liệu hiển thị**, không phải rào.
>
> **Ngày viết**: 2026-05-08 v1.5 · **đối chiếu code**: 2026-08-05 (#1900),
> đo lại 2026-08-07 (#2029)
> **Tính năng cha**: Menu Cart Timeout (khi schedule kết thúc, giỏ hàng được grace period trước khi disable sản phẩm)

---

## Changelog

| Version | Thay đổi |
|---------|---------|
| v1.5 | **Sửa trigger cốt lõi**: timeout trigger theo **schedule kết thúc** (không phải `valid_to`); cập nhật Section 1, điều kiện, Section 6 checkout validation, task #20, i18n dialog description, edge cases |
| v1.4 | Thêm điều kiện `valid_to` là bắt buộc; xóa `menu_transition_grace_minutes` (field thừa); thêm tasks checkout validation backend vào Section 8; thêm E8 (`valid_to = null`) và E9 (`menu_transition_grace_minutes`) vào edge cases |
| v1.3 | Mở rộng scope: thêm tầng ① HQ brand default (Brand Settings page) và tầng ③ Shop default (Shop Settings page) vào plan; thay E4 tooltip → **ẩn nút hoàn toàn** khi menu chưa có schedule; clarify "schedule" = time slots theo thứ (entity riêng, không liên quan `valid_from/valid_to`); thêm data model cho `brands` và `branches`; thêm API endpoints cho brand/shop settings; reorder implementation tasks; cập nhật i18n keys cho settings pages |
| v1.2 | E4: thêm tooltip warning khi không có `valid_to`; fix shop badge i18n key → `common.timeout.*`; thêm DB index note cho `menu_id`; spec HQ brand field source trong `HqMenuResource`; tách `minutes_hint` riêng cho HQ/Shop |
| v1.1 | Fix endpoint shop → `/settings`; đồng nhất API field names với TS types; cập nhật shop dialog thêm tầng HQ per-menu vào chain; bỏ max 1440 chờ confirm nghiệp vụ; tách `disabledAll` cho timeout button; fix i18n namespace `shop.menus.*`; reorder tasks; document edge case `= 0` |
| v1.0 | Tạo mới |

---

## 1. Bối cảnh

**Schedule** = time slots hoạt động của menu theo thứ trong tuần (ví dụ: Thứ 2–Thứ 6, 11:00–14:00). Khi schedule kết thúc (14:00), menu "đóng cửa" cho phiên đó.

Feature **cart timeout** thêm một grace period ngay sau khi schedule kết thúc:

```
schedule.end_time              schedule.end_time + cart_timeout_minutes
      │                                        │
      │◄──────── cart_timeout_minutes ────────►│
      │                                        │
      ▼                                        ▼
Schedule kết thúc                   Sản phẩm bị disable trong giỏ
Countdown bắt đầu                   Khách không thể checkout
```

- Khách đang order thấy countdown khi schedule kết thúc (hiển thị ở customer-web / POS)
- Hết countdown → sản phẩm từ menu đó bị disabled trong giỏ hàng
- Khách phải tự xóa thủ công, không thể đặt hàng với sản phẩm đó

> **`valid_from` / `valid_to`** là ngày hiệu lực tổng thể của menu — **không liên quan** đến trigger của cart timeout.

### Điều kiện để feature hoạt động

| Điều kiện | Bắt buộc | Ghi chú |
|-----------|----------|---------|
| Menu có ≥ 1 schedule | ✅ | Không có schedule thì không có end_time → timeout không bao giờ trigger |

**Điều kiện cốt lõi**: Menu phải có **ít nhất 1 schedule** thì mới được phép cấu hình cart timeout ở tầng per-menu, và timeout mới có thể trigger. Tầng brand default và shop default là setting toàn cục, không yêu cầu điều kiện này.

### Phân cấp kế thừa timeout (ưu tiên từ cao → thấp)

```
① HQ brand default          (brands.cart_timeout_minutes)
      ↓ override
② HQ per-menu               (menus.cart_timeout_minutes)        ← yêu cầu menu có ≥ 1 schedule
      ↓ override
③ Shop default              (branches.cart_timeout_minutes)
      ↓ override
④ Shop per-menu             (menus.cart_timeout_minutes của hàng menu CLONE
                             theo branch — KHÔNG phải bảng branch_menu_settings,
                             bảng đó chưa bao giờ tồn tại; xem §2d)
                                                          ← yêu cầu menu có ≥ 1 schedule
```

### Scope của plan này

| Tầng | UI | Điều kiện hiển thị |
|------|----|--------------------|
| ① HQ brand default | Brand Settings page — section "Cart Timeout" | Luôn hiển thị |
| ② HQ per-menu | HQ menu detail — header quick actions button | Menu có ≥ 1 schedule |
| ③ Shop default | Shop Settings page — section "Cart Timeout" | Luôn hiển thị |
| ④ Shop per-menu | Shop menu detail — header quick actions button | Menu có ≥ 1 schedule |

> **Chưa trong scope**: customer-web countdown UI, cart disable logic tại workstation/POS.

---

## 2. Data Model

### 2a. Bảng `brands` — tầng ① HQ brand default

```sql
ALTER TABLE brands
    ADD COLUMN cart_timeout_minutes SMALLINT UNSIGNED NULL DEFAULT NULL;
-- NULL  = không có default (timeout không được cấu hình ở cấp brand)
-- ≥ 1  = default áp dụng cho toàn bộ menu khi tạo mới
```

### 2b. Bảng `menus` — tầng ② HQ per-menu

```sql
ALTER TABLE menus
    ADD COLUMN cart_timeout_minutes SMALLINT UNSIGNED NULL DEFAULT NULL;
-- NULL  = kế thừa tầng ① (HQ brand default)
-- ≥ 1  = override tại menu cụ thể này
-- 0 là GIÁ TRỊ KHÔNG HỢP LỆ — validation phải reject (xem Section 5)
```

### 2c. Bảng `branches` — tầng ③ Shop default

```sql
ALTER TABLE branches
    ADD COLUMN cart_timeout_minutes SMALLINT UNSIGNED NULL DEFAULT NULL;
-- NULL  = kế thừa tầng ① (HQ brand default)
-- ≥ 1  = shop default, override HQ brand default
```

> ❌ **`menu_transition_grace_minutes`** — **KHÔNG dùng field này cho cart
> timeout**. Nó **không phải cột DB**: `grep -rn menu_transition_grace_minutes
> backend/database/` → zero hit; field chỉ sống trong menu-definition payload
> (`MenuDefinitionPayload`, `MenuUpdateRequest`, 0–120 phút). Đừng dựng cột hay
> migration cho nó — `cart_timeout_minutes` là đường duy nhất.

### 2d. Bảng `branch_menu_settings` — tầng ④ Shop per-menu

> **⚠️ BẢNG NÀY CHƯA BAO GIỜ ĐƯỢC TẠO.** Không phải bị gỡ — đo 2026-08-07:
> `grep -rn branch_menu_settings backend/ schemas/` → **zero hit** (không
> migration, không model, không YAML), và `git log -S` không có commit nào từng
> thêm rồi xoá. `CREATE TABLE` dưới đây là **thiết kế v1.3 chưa thi công**, giữ
> lại làm bản ghi lý do chứ không mô tả DB.
>
> **Tầng ④ được cài theo cách KHÁC, và nó đang chạy.** Menu ở shop là một
> **bản clone** của menu HQ: một hàng `menus` riêng, có `branch_id` và
> `master_menu_id` trỏ về menu master. Override per-menu của shop vì thế nằm
> ngay trên `menus.cart_timeout_minutes` **của hàng clone đó** — không cần bảng
> phụ, và `UNIQUE(branch_id, menu_id)` là thừa vì cặp đó vốn đã là một hàng.
> Xem `ShopMenuItemSettingsController::buildChain()` — nó đọc bốn tầng lần lượt
> từ `$masterMenu->brand`, `$masterMenu`, `$branchMenu->branch`, `$branchMenu`.
>
> Hệ quả cho §10 E5 và E7: không có "query pattern `branch_menu_settings`" và
> không có "row chưa tồn tại" — clone menu về shop là đã có hàng, giá trị `null`
> chính là tín hiệu kế thừa.

```sql
-- KHÔNG TỒN TẠI trong DB — thiết kế chưa thi công, xem cảnh báo trên.
CREATE TABLE branch_menu_settings (
    id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    branch_id     UUID NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    menu_id       UUID NOT NULL REFERENCES menus(id) ON DELETE CASCADE,
    cart_timeout_minutes SMALLINT UNSIGNED NULL DEFAULT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT now(),
    updated_at    TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (branch_id, menu_id)
    -- UNIQUE tạo index ngầm cho (branch_id, menu_id) — đủ cho lookup theo shop.
);
-- Index riêng cho query "tất cả shop override của 1 menu" (WHERE menu_id = ?):
CREATE INDEX idx_branch_menu_settings_menu_id ON branch_menu_settings(menu_id);
```

---

## 3. API Changes

### 3a. HQ Brand Settings — tầng ①

Endpoint settings của brand (đã có hoặc tạo mới):

```
PATCH /api/v1/hq/{brandSlug}/settings
{
  "cart_timeout_minutes": 30   // ≥ 1, hoặc null để xóa default
}
```

**Response**: trả về `BrandSettingsResource` có field `cart_timeout_minutes`.

---

### 3b. HQ — cập nhật per-menu — tầng ②

Endpoint đã có:

```
PATCH /api/v1/hq/{brandSlug}/menus/{menuId}
{
  "cart_timeout_minutes": 45   // ≥ 1, hoặc null để kế thừa brand default
}
```

**`HqMenuResource` — thêm 2 fields**:

```json
{
  "id": "...",
  "name": "...",
  "has_schedules": true,
  "cart_timeout_minutes": 45,
  "hq_brand_timeout_minutes": 30
}
```

| Field | Nguồn | Ghi chú |
|-------|-------|---------|
| `has_schedules` | computed từ schedules count | Dùng để FE quyết định ẩn/hiện nút |
| `cart_timeout_minutes` | `menus.cart_timeout_minutes` | Writable — HQ per-menu override |
| `hq_brand_timeout_minutes` | `brands.cart_timeout_minutes` | Read-only — để dialog hiển thị "Hiện tại: X phút" |

---

### 3c. Shop Settings — tầng ③

```
PATCH /api/v1/shops/{shopSlug}/settings
{
  "cart_timeout_minutes": 45   // ≥ 1, hoặc null để kế thừa HQ brand default
}
```

**Response**: trả về `ShopSettingsResource` có các fields:

```json
{
  "data": {
    "cart_timeout_minutes": 45,
    "hq_brand_timeout_minutes": 30,
    "effective_timeout_minutes": 45
  }
}
```

---

### 3d. Shop — settings per-menu — tầng ④

Endpoint mới:

```
PATCH /api/v1/shops/{shopSlug}/menus/{menuId}/settings
{
  "cart_timeout_minutes": 60   // ≥ 1, hoặc null để kế thừa
}
```

**Response — trả về toàn bộ inheritance chain**:

```json
{
  "data": {
    "hq_brand_timeout_minutes": 30,
    "hq_menu_timeout_minutes": null,
    "shop_default_timeout_minutes": 45,
    "shop_menu_timeout_minutes": 60,
    "effective_timeout_minutes": 60
  }
}
```

**GET `/shops/{slug}/menus/{id}` — embed đủ fields vào `ShopMenuResource`** (để dialog khỏi request thêm):

| Field | Nguồn | Writable |
|-------|-------|---------|
| `has_schedules` | computed | ❌ |
| `hq_brand_timeout_minutes` | `brands` | ❌ |
| `hq_menu_timeout_minutes` | `menus.cart_timeout_minutes` | ❌ (shop read-only) |
| `shop_default_timeout_minutes` | `branches.cart_timeout_minutes` | ❌ (qua Shop Settings page) |
| `shop_menu_timeout_minutes` | `menus.cart_timeout_minutes` của **hàng menu clone theo branch** (`branch_id` + `master_menu_id`) — **không** phải `branch_menu_settings`, xem §2d | ✅ qua PATCH /settings |
| `effective_timeout_minutes` | Backend computed | ❌ |

---

## 4. Nút trên Header Quick Actions

### Điều kiện hiển thị nút (cả HQ lẫn Shop)

```tsx
// Chỉ render nút khi menu có ít nhất 1 schedule
// Không có schedule → không render gì cả (không tooltip, không disabled)
{menu.has_schedules && (
  <Button ...>
    <Clock />
    Timeout
    {/* badge */}
  </Button>
)}
```

---

### 4a. HQ Menu Detail — `items/page.tsx`

**Vị trí**: giữa workflow buttons và Delete:

```
Cancel | Submit | Reject/Approve | Activate | Deactivate | [Set Timeout] | Delete | Save
```

**`disabled` logic**: không dùng chung `disabledAll` — timeout là metadata độc lập với workflow. Chỉ disable khi mutation đang pending.

```tsx
import { Clock } from "lucide-react";

// Chỉ render khi có schedule
{menu.has_schedules && (
  <Button
    type="button"
    variant="outline"
    size="sm"
    className="h-8 gap-1.5 text-xs"
    onClick={() => setTimeoutDialogOpen(true)}
    disabled={updateTimeout.isPending}
  >
    <Clock className="size-3.5" />
    {t("hq.menus.timeout.button_label")}
    {menu.cart_timeout_minutes != null ? (
      <span className="ml-0.5 rounded bg-primary/10 px-1 py-0.5 text-[10px] font-medium text-primary tabular-nums">
        {menu.cart_timeout_minutes}{t("common.timeout.minutes_unit")}
      </span>
    ) : (
      <span className="ml-0.5 rounded bg-muted px-1 py-0.5 text-[10px] text-muted-foreground">
        {t("common.timeout.badge_default")}
      </span>
    )}
  </Button>
)}
```

**State cần thêm**:
```ts
const [timeoutDialogOpen, setTimeoutDialogOpen] = useState(false);
const updateTimeout = useUpdateHqMenuTimeout(brandSlug, menuId); // hook mới
```

---

### 4b. Shop Menu Detail — `shop/[shopSlug]/menus/[menuId]/page.tsx`

**Vị trí**: `[ViewToggle] | [Set Timeout] | [Sync from master]`

```tsx
{menu.has_schedules && (
  <Button
    variant="outline"
    size="sm"
    className="h-8 gap-1.5 text-xs"
    onClick={() => setTimeoutDialogOpen(true)}
    disabled={updateShopTimeout.isPending}
  >
    <Clock className="size-3.5" />
    {t("shop.menus.timeout.button_label")}
    {menu.shop_menu_timeout_minutes != null ? (
      <span className="ml-0.5 rounded bg-primary/10 px-1 py-0.5 text-[10px] font-medium text-primary tabular-nums">
        {menu.shop_menu_timeout_minutes}{t("common.timeout.minutes_unit")}
      </span>
    ) : (
      <span className="ml-0.5 rounded bg-muted px-1 py-0.5 text-[10px] text-muted-foreground">
        {t("common.timeout.badge_default")}
      </span>
    )}
  </Button>
)}
```

---

## 5. Dialog & Settings UI Design

### Validation rules (dùng chung cho tất cả tầng)

- **Min: 1** phút — loại trừ 0 (`= 0` là ambiguous, phải reject)
- **Max**: ⚠️ **Chờ confirm business**. Gợi ý kỹ thuật: 240 phút (4h). Tránh 1440 (24h) vì sai intent F&B.
- **Kiểu**: số nguyên dương, không cho phép thập phân

---

### 5a. HQ Brand Settings page — tầng ①

Section "Cart Timeout" trên trang Brand Settings:

```
┌──────────────────────────────────────────────┐
│  Cart Timeout (Brand Default)                │
│  ──────────────────────────────────────────  │
│  Thời gian grace sau khi menu hết hạn.        │
│  Áp dụng cho toàn bộ menu khi tạo mới,       │
│  trừ khi menu hoặc shop tự override.          │
│                                              │
│  ● Không đặt default                         │
│  ○ Đặt default cho brand:                   │
│    [ 30 ] phút                               │
│    Tối thiểu 1 phút.                         │
│                                              │
│                              [Lưu]           │
└──────────────────────────────────────────────┘
```

- Radio "Không đặt default" → payload `{ cart_timeout_minutes: null }`
- Radio "Đặt default" → show input, payload `{ cart_timeout_minutes: <number ≥ 1> }`
- Mutation: `PATCH /hq/{brandSlug}/settings`

---

### 5b. HQ `HqSetTimeoutDialog` — tầng ②

```
┌──────────────────────────────────────────┐
│  Cài timeout giỏ hàng cho menu này       │
│  ──────────────────────────────────────  │
│  Sau khi menu hết hạn (valid_to),        │
│  khách còn bao nhiêu thời gian trước     │
│  khi sản phẩm bị vô hiệu trong giỏ?     │
│                                          │
│  ● Dùng default HQ (hiện tại: 30 phút)  │
│  ○ Đặt riêng cho menu này:              │
│    [ 45 ] phút                           │
│    Tối thiểu 1 phút.                     │
│                                          │
│                    [Hủy]  [Lưu]          │
└──────────────────────────────────────────┘
```

> Nếu `hqBrandTimeoutMinutes = null`: hint hiển thị "Dùng default HQ (chưa đặt)".

**Component props**:
```tsx
interface HqSetTimeoutDialogProps {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  menuId: string;
  brandSlug: string;
  hqMenuTimeoutMinutes: number | null;    // tầng ② — giá trị hiện tại
  hqBrandTimeoutMinutes: number | null;   // tầng ① — cho hint "Hiện tại: X phút"
}
```

- Radio "Dùng default HQ" → payload `{ cart_timeout_minutes: null }`
- Radio "Đặt riêng" → show input, payload `{ cart_timeout_minutes: <number ≥ 1> }`
- Mutation: `PATCH /hq/{brandSlug}/menus/{menuId}`

---

### 5c. Shop Settings page — tầng ③

Section "Cart Timeout" trên trang Shop Settings:

```
┌──────────────────────────────────────────────┐
│  Cart Timeout (Shop Default)                 │
│  ──────────────────────────────────────────  │
│  HQ brand default: 30 phút                   │
│                                              │
│  ● Dùng HQ brand default (30 phút)          │
│  ○ Đặt default riêng cho shop này:          │
│    [ 45 ] phút                               │
│    Tối thiểu 1 phút.                         │
│                                              │
│                              [Lưu]           │
└──────────────────────────────────────────────┘
```

- Hiển thị HQ brand default để shop manager tham khảo
- Radio "Dùng HQ brand default" → payload `{ cart_timeout_minutes: null }`
- Radio "Đặt riêng" → show input, payload `{ cart_timeout_minutes: <number ≥ 1> }`
- Mutation: `PATCH /shops/{shopSlug}/settings`

---

### 5d. Shop `ShopSetTimeoutDialog` — tầng ④

Hiển thị đủ **4 tầng** — tầng ② HQ per-menu phải xuất hiện kể cả khi null:

```
┌────────────────────────────────────────────────┐
│  Override timeout giỏ hàng cho menu này        │
│  ────────────────────────────────────────      │
│  Kế thừa từ:                                   │
│  ① HQ brand default:   30 phút                 │
│  ② HQ per-menu:        — (chưa đặt)            │  ← luôn hiển thị, dù null
│  ③ Shop default:       45 phút                 │
│  ─────────────────────────────────────         │
│  Giá trị hiệu lực:     45 phút                 │
│                                                │
│  ● Dùng timeout kế thừa (45 phút)             │
│  ○ Override riêng cho menu này tại shop:      │
│    [ 60 ] phút                                 │
│    Tối thiểu 1 phút.                           │
│                                                │
│                       [Hủy]  [Lưu]            │
└────────────────────────────────────────────────┘
```

> Khi HQ per-menu đã đặt (ví dụ 45 phút), chain hiển thị đúng tầng ② = 45 phút, giải thích tại sao effective value ≠ brand default.

> Nếu `effectiveTimeoutMinutes = null` (brand chưa đặt, shop chưa đặt, HQ per-menu chưa đặt): radio label hiển thị "Dùng timeout kế thừa (chưa đặt)".

**Component props**:
```tsx
interface ShopSetTimeoutDialogProps {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  menuId: string;
  shopSlug: string;
  hqBrandTimeoutMinutes: number | null;       // tầng ①
  hqMenuTimeoutMinutes: number | null;        // tầng ② — luôn render, dù null
  shopDefaultTimeoutMinutes: number | null;   // tầng ③
  shopMenuTimeoutMinutes: number | null;      // tầng ④ — giá trị đang override
  effectiveTimeoutMinutes: number | null;     // resolved
}
```

- Radio "Dùng kế thừa" → payload `{ cart_timeout_minutes: null }`
- Radio "Override" → show input
- Mutation: `PATCH /shops/{shopSlug}/menus/{menuId}/settings`

---

## 6. Checkout Validation (Backend) — CHƯA CÀI ĐẶT

> **⚠️ TOÀN BỘ MỤC NÀY CHƯA BAO GIỜ ĐƯỢC XÂY.** Không phải bị gỡ — đo
> 2026-08-07: `grep -rn CART_TIMEOUT_EXPIRED` trên `backend/`, `web/pos/`,
> `web/customer/` → **zero hit**; `git log -S` không có commit nào. Và
> `cart_timeout_minutes` chỉ xuất hiện ở tầng **cấu hình + đọc** (Model,
> Resource, Request, `BrandSettingsService`, `ShopBranchSettingsService`,
> `ShopMenuItemSettingsController`, `CustomerMenuService`) — **không controller
> hay service nào chặn `POST /orders`**.
>
> **Hôm nay timeout là dữ liệu HIỂN THỊ, không phải rào.**
> `CustomerMenuService` + `CustomerBranchController` trả `effective_timeout_minutes`
> ra cho customer-web đếm ngược; server vẫn nhận đơn sau deadline. Ai dựa vào
> mục này để bỏ qua kiểm tra ở client sẽ để lọt đúng thứ họ tưởng đã được chặn.
>
> Đây là **spec còn hiệu lực** cho việc chưa làm (task #20 · #21 ở §9), không
> phải mô tả hiện trạng. Giữ nguyên nội dung dưới đây làm đầu bài.

Validation xảy ra **tại thời điểm submit** (`POST /orders`), không realtime:

```
Với mỗi item trong cart:
  Lấy menu của item
  Resolve effective_timeout_minutes theo chain ① → ④
  Nếu effective_timeout_minutes == null:
    → PASS (feature chưa cấu hình cho menu này)

  Tìm schedule của menu ứng với ngày hôm nay (now.dayOfWeek)
  Nếu không có schedule hôm nay:
    → PASS (menu không hoạt động hôm nay, không có end_time để trigger)

  deadline = schedule.end_time (hôm nay) + effective_timeout_minutes
  Nếu now > deadline:
    → REJECT order
    → Trả lỗi chỉ rõ item nào bị lỗi
  → PASS: tiếp tục xử lý
```

**Error response** phải chỉ rõ từng item bị lỗi để FE hiển thị đúng thông báo:

```json
HTTP 422
{
  "error": "CART_TIMEOUT_EXPIRED",
  "message": "Một hoặc nhiều sản phẩm trong giỏ hàng đã hết thời gian đặt.",
  "expired_items": [
    { "menu_product_id": "uuid-...", "menu_id": "uuid-..." }
  ]
}
```

> **Lưu ý race condition**: Khách có thể checkout hợp lệ (timer chưa hết) nhưng request đến server sau khi deadline. FE phải xử lý lỗi này bằng cách trigger refresh cart và hiển thị thông báo rõ ràng.

---

## 7. TypeScript Types

```ts
// src/types/brand.ts — BrandSettingsResource (thêm field)
interface BrandSettingsResource {
  // ... existing fields ...
  cart_timeout_minutes: number | null; // tầng ① — writable
}

// src/types/hq.ts — HqMenuResource (thêm fields)
interface HqMenuResource {
  // ... existing fields ...
  has_schedules: boolean;              // computed — dùng để ẩn/hiện nút
  cart_timeout_minutes: number | null; // tầng ② — writable
  hq_brand_timeout_minutes: number | null; // tầng ① — read-only, embed từ brand
}

// src/types/shop.ts — ShopSettingsResource (thêm fields)
interface ShopSettingsResource {
  // ... existing fields ...
  cart_timeout_minutes: number | null;      // tầng ③ — writable
  hq_brand_timeout_minutes: number | null;  // tầng ① — read-only
  effective_timeout_minutes: number | null; // resolved ① → ③
}

// src/types/shop.ts — ShopMenuResource (thêm fields)
interface ShopMenuResource {
  // ... existing fields ...
  has_schedules: boolean;                      // computed — dùng để ẩn/hiện nút
  hq_brand_timeout_minutes: number | null;     // tầng ①
  hq_menu_timeout_minutes: number | null;      // tầng ②
  shop_default_timeout_minutes: number | null; // tầng ③
  shop_menu_timeout_minutes: number | null;    // tầng ④ — writable
  effective_timeout_minutes: number | null;    // resolved
}
```

---

## 8. i18n Keys

```jsonc
// --- common.* — dùng chung toàn bộ ---
"common.timeout.minutes_unit": "m",
"common.timeout.badge_default": "Default",
"common.timeout.not_set": "Chưa đặt",

// --- hq.brand.settings.timeout.* — Brand Settings page (tầng ①) ---
"hq.brand.settings.timeout.section_title": "Cart Timeout (Brand Default)",
"hq.brand.settings.timeout.description": "Thời gian grace sau khi schedule của menu kết thúc. Áp dụng cho toàn bộ menu khi tạo mới, trừ khi menu hoặc shop tự override.",
"hq.brand.settings.timeout.no_default_radio": "Không đặt default",
"hq.brand.settings.timeout.custom_radio": "Đặt default cho brand",
"hq.brand.settings.timeout.minutes_hint": "Tối thiểu 1 phút",

// --- hq.menus.timeout.* — HQ menu detail button + dialog (tầng ②) ---
"hq.menus.timeout.button_label": "Timeout",
"hq.menus.timeout.dialog_title": "Cài timeout giỏ hàng",
"hq.menus.timeout.dialog_description": "Sau khi schedule của menu kết thúc, khách còn bao lâu để hoàn tất order trước khi sản phẩm bị vô hiệu trong giỏ hàng.",
"hq.menus.timeout.use_default_radio": "Dùng default HQ",
"hq.menus.timeout.use_default_hint": "Hiện tại: {minutes} phút",
"hq.menus.timeout.use_default_hint_empty": "Chưa đặt",
"hq.menus.timeout.custom_radio": "Đặt riêng cho menu này",
"hq.menus.timeout.minutes_hint": "Tối thiểu 1 phút",

// --- shop.settings.timeout.* — Shop Settings page (tầng ③) ---
"shop.settings.timeout.section_title": "Cart Timeout (Shop Default)",
"shop.settings.timeout.hq_default_label": "HQ brand default: {minutes} phút",
"shop.settings.timeout.hq_default_label_empty": "HQ brand default: Chưa đặt",
"shop.settings.timeout.use_hq_radio": "Dùng HQ brand default",
"shop.settings.timeout.custom_radio": "Đặt default riêng cho shop này",
"shop.settings.timeout.minutes_hint": "Tối thiểu 1 phút",

// --- shop.menus.timeout.* — Shop menu detail button + dialog (tầng ④) ---
"shop.menus.timeout.button_label": "Timeout",
"shop.menus.timeout.dialog_title": "Override timeout giỏ hàng",
"shop.menus.timeout.inherit_chain_title": "Kế thừa từ",
"shop.menus.timeout.hq_brand_row": "① HQ brand default: {minutes} phút",
"shop.menus.timeout.hq_brand_row_empty": "① HQ brand default: — (chưa đặt)",
"shop.menus.timeout.hq_menu_row": "② HQ per-menu: {minutes} phút",
"shop.menus.timeout.hq_menu_row_empty": "② HQ per-menu: — (chưa đặt)",
"shop.menus.timeout.shop_default_row": "③ Shop default: {minutes} phút",
"shop.menus.timeout.shop_default_row_empty": "③ Shop default: — (chưa đặt)",
"shop.menus.timeout.effective_row": "Giá trị hiệu lực: {minutes} phút",
"shop.menus.timeout.effective_row_empty": "Giá trị hiệu lực: — (chưa đặt)",
"shop.menus.timeout.use_inherited_radio": "Dùng timeout kế thừa ({minutes} phút)",
"shop.menus.timeout.use_inherited_radio_empty": "Dùng timeout kế thừa (chưa đặt)",
"shop.menus.timeout.custom_radio": "Override riêng cho menu này tại shop",
"shop.menus.timeout.minutes_hint": "Tối thiểu 1 phút"
```

> **Tại sao `minutes_hint` tách riêng thay vì `common.*`**: max value có thể khác nhau giữa các tầng sau khi confirm business.

---

## 9. Thứ tự Implementation

Types frontend phải đứng **sau** khi backend done — không code blind.

| # | Task | Scope | Dependency |
|---|------|-------|-----------|
| 1 | Migration: `brands.cart_timeout_minutes` | Backend | — |
| 2 | Migration: `menus.cart_timeout_minutes` | Backend | — |
| 3 | Migration: `branches.cart_timeout_minutes` | Backend | — |
| 4 | ~~Migration: `branch_menu_settings` table + index~~ — **BỎ**, tầng ④ dùng `menus.cart_timeout_minutes` trên hàng clone theo branch (§2d) | Backend | — |
| 5 | `BrandSettingsResource` + endpoint `PATCH /hq/{slug}/settings` nhận `cart_timeout_minutes` | Backend | #1 |
| 6 | `HqMenuResource` embed `has_schedules` + `cart_timeout_minutes` + `hq_brand_timeout_minutes` | Backend | #1 #2 |
| 7 | `MenuController@update` nhận field `cart_timeout_minutes` | Backend | #2 |
| 8 | `ShopSettingsResource` + endpoint `PATCH /shops/{slug}/settings` nhận `cart_timeout_minutes` | Backend | #3 |
| 9 | Endpoint `PATCH /shops/{slug}/menus/{id}/settings` | Backend | #4 |
| 10 | GET `/shops/{slug}/menus/{id}` embed `has_schedules` + 5 timeout fields vào `ShopMenuResource` | Backend | #1 #2 #3 #4 |
| 11 | **API contract review** — verify toàn bộ field names + response shape | — | #5–#10 |
| 12 | Update TS types: `BrandSettingsResource`, `HqMenuResource`, `ShopSettingsResource`, `ShopMenuResource` | Frontend | #11 |
| 13 | HQ Brand Settings page — section Cart Timeout + `useUpdateBrandTimeout` hook | Frontend | #12 |
| 14 | `HqSetTimeoutDialog` + `useUpdateHqMenuTimeout` hook (colocated `items/components/`) | Frontend | #12 |
| 15 | Thêm button (ẩn khi `!has_schedules`) + state vào `items/page.tsx` | Frontend | #14 |
| 16 | Shop Settings page — section Cart Timeout + `useUpdateShopDefaultTimeout` hook | Frontend | #12 |
| 17 | `ShopSetTimeoutDialog` + `useUpdateShopMenuTimeout` hook (colocated `[menuId]/components/`) | Frontend | #12 |
| 18 | Thêm button (ẩn khi `!has_schedules`) + state vào shop `[menuId]/page.tsx` | Frontend | #17 |
| 19 | i18n keys — `common.*`, `hq.brand.settings.*`, `hq.menus.*`, `shop.settings.*`, `shop.menus.*` (ja/en/vi) | Frontend | #13–#18 |
| 20 | **CHƯA LÀM** — Backend: checkout validation — resolve `effective_timeout_minutes` tại `POST /orders`, reject nếu `now > schedule.end_time + effective` | Backend | #1–#3 |
| 21 | **CHƯA LÀM** — Backend: error response `CART_TIMEOUT_EXPIRED` với `expired_items` array | Backend | #20 |
| 22 | Xóa migration `menu_transition_grace_minutes` (untracked file, không commit) | Backend | — |

---

## 10. Edge Cases & Open Questions

| # | Vấn đề | Quyết định |
|---|--------|-----------|
| E1 | `cart_timeout_minutes = 0` | **Invalid** — validation reject, min ≥ 1. Document: 0 không có nghĩa "hết hạn ngay" hay "không timeout". |
| E2 | Max timeout value | **Chờ confirm business**. Gợi ý kỹ thuật: 240 phút (4h). Tránh 1440 (24h) vì sai intent F&B. |
| E3 | Shop dialog khi HQ per-menu đã đặt | Chain hiển thị đủ 4 tầng — tầng ② luôn render kể cả null, giải thích effective value. |
| E4 | Menu không có schedule | **Ẩn nút hoàn toàn** tại HQ menu detail và Shop menu detail. Brand Settings và Shop Settings không bị ảnh hưởng (setting toàn cục). |
| E5 | ~~Query pattern `branch_menu_settings`~~ | **KHÔNG CÒN ÁP DỤNG** — bảng không tồn tại (§2d). Tầng ④ đọc thẳng hàng `menus` clone theo branch; "lookup mọi shop override của 1 menu" là `menus WHERE master_menu_id = ?`. |
| E6 | `effective_timeout_minutes = null` ở mọi tầng | Không có timeout nào được cấu hình. Shop dialog và radio label hiển thị "chưa đặt". Cart timeout feature không hoạt động cho menu này. |
| E7 | Menu clone từ HQ về shop | Clone **chính là** hàng lưu tầng ④ (§2d). `menus.cart_timeout_minutes` của clone = `null` → `shop_menu_timeout_minutes = null` → kế thừa theo chain. Shop override qua dialog bình thường. |
| E8 | Menu có nhiều schedules, hôm nay match nhiều slot | Lấy slot có `end_time <= now` lớn nhất (slot đã kết thúc gần nhất). Ví dụ: sáng 06–10, trưa 11–14. Khách order lúc 10:05 → check slot sáng (end_time=10:00), không check slot trưa (chưa kết thúc). |
| E9 | `menu_transition_grace_minutes` | **Không phải đường cart timeout** và không phải cột DB (§2c) — chỉ là field của menu-definition payload. Cart timeout đi qua `cart_timeout_minutes`. |
| E10 | Menu có schedule hôm nay nhưng chưa đến end_time | `now < schedule.end_time` → timeout chưa trigger → PASS bình thường. |
| E11 | Menu không có schedule hôm nay | Không có end_time để trigger → PASS (không validate timeout). |
