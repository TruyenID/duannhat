# Handy App — Design & Context Document

> Nguồn chuẩn cho API: `web/pos/src/services/` và `web/pos/src/app/pos/types.ts`  
> Mọi type và endpoint trong file này đều lấy trực tiếp từ pos-web — không tự đặt.

---

## 1. Thiết bị mục tiêu (Target Device)

| Thông số | Giá trị |
|---|---|
| Loại máy | POS Handy / PDA cầm tay |
| Kích thước vật lý | 211mm × 83mm × 54mm |
| Trọng lượng | ~450g |
| Màn hình | 5.5 inch, cảm ứng |
| Hướng màn hình | **Portrait (dọc) — bắt buộc** |
| Hệ điều hành | Android |
| Logical pixels | ~360dp rộng (chuẩn cho mọi 5.5" Android) |
| Đặc biệt | Máy in nhiệt tích hợp ở đỉnh máy |

---

## 2. Mục đích ứng dụng

**Handy App** là ứng dụng nhận order tại bàn (tableside ordering) cho nhân viên phục vụ.  
Nhân viên cầm máy đi đến bàn → chọn bàn → thêm món → gửi order. Thanh toán xử lý ở workstation.

### Luồng chính
```
Xem bàn → Tap bàn → Tạo/mở order → Thêm món từ menu → Gửi bếp
```

### Phân biệt với pos-web
| Tính năng | pos-web | godx-handy |
|---|---|---|
| Xem bàn & order | ✅ | ✅ |
| Tạo order + thêm món | ✅ | ✅ |
| Thanh toán | ✅ | ❌ (ở quầy) |
| Split bill | ✅ | ❌ |
| Void order | ✅ | ❌ |
| In phiếu tạm | - | ✅ (thermal printer) |

---

## 3. API Endpoints (từ pos-web — chuẩn)

Base URL: `NEXT_PUBLIC_API_URL` (env) + `/api/v1`  
Auth header: `Authorization: Bearer {token}` (SSO hoặc device token — TBD)

### 3.1 Shop
```
GET  /api/v1/shops/{shopSlug}
  → { data: ShopDetail }
  Dùng: load tên cửa hàng cho Header
```

### 3.2 Tables (nguồn: table-service.ts)
```
GET  /api/v1/shops/{shopSlug}/tables
  Query params:
    status?:   "free" | "occupied" | "reserved" | "cleaning" | "out_of_service"
    zone_id?:  string
    search?:   string
    per_page?: number  (default 100)
    page?:     number
  → PaginatedResponse<TableResource>
  Dùng: màn hình chính — grid các bàn
```

### 3.3 Orders (nguồn: order-service.ts)
```
GET  /api/v1/shops/{shopSlug}/orders
  Query params:
    status?:    "open,dining,checkout,paying"  ← filter nhiều status, cách nhau dấu phẩy
    per_page?:  number (default 100)
    sort?:      string
  → PaginatedResponse<CustomerOrder>
  Dùng: danh sách order đang mở — hiển thị ở màn hình chính (order list)

GET  /api/v1/shops/{shopSlug}/orders/{id}
  → { data: CustomerOrder }   ← có eager-load items + tables + payments
  Dùng: màn hình detail khi tap vào order/bàn

POST /api/v1/shops/{shopSlug}/orders
  Body: OrderCreateInput
  → { data: CustomerOrder }
  Dùng: tạo order mới khi bàn free

PUT  /api/v1/shops/{shopSlug}/orders/{id}/init
  Body: OrderInitInput  (first-write-wins — chỉ set được 1 lần)
  → { data: CustomerOrder }
  Dùng: gắn bàn + số khách sau khi tạo order

POST /api/v1/shops/{shopSlug}/orders/{id}/items
  Body: { items: OrderItemInput[] }
  → { data: CustomerOrder }   ← trả full order + items đã recompute
  Dùng: gửi món từ giỏ hàng lên bếp

PATCH /api/v1/shops/{shopSlug}/orders/{id}/items/{itemId}
  Body: OrderItemUpdateInput
  → { data: CustomerOrder }
  Dùng: đổi số lượng / ghi chú món

DELETE /api/v1/shops/{shopSlug}/orders/{id}/items/{itemId}
  → { data: CustomerOrder }
  Dùng: xóa món (chỉ khi status = pending)
```

### 3.4 Menu (nguồn: shop-menu-service.ts)
```
GET  /api/v1/shops/{shopSlug}/menus/by-day/{dayOfWeek}
  dayOfWeek: 0=Sun … 6=Sat  (dùng new Date().getDay())
  Query: per_page?, search?
  → PaginatedResponse<ShopMenuByDayResource>
  Dùng: load menu đang active hôm nay (có start_time/end_time schedule)

GET  /api/v1/shops/{shopSlug}/menus/{menuId}
  → { data: ShopMenuResource }  ← có menu_products + skus eager-loaded
  Dùng: load toàn bộ menu khi user chọn menu

GET  /api/v1/shops/{shopSlug}/menus/{menuId}/products
  Query: search?, page?, per_page?
  → PaginatedResponse<ShopMenuProduct>
  Dùng: search và phân trang trong màn menu catalog
```

### 3.5 Shop Settings (nguồn: shop-order-settings-service.ts)
```
GET  /api/v1/shops/{shopSlug}/settings/order
  → { data: ShopOrderSettings }
  Trường quan trọng:
    currency_code:         string  (e.g. "JPY", "VND")
    tax_rate:              string  (decimal string, e.g. "10.00")
    service_charge_rate:   string
    enable_quick_order:    boolean
  Dùng: format tiền tệ, config UX
```

---

## 4. Types (từ web/pos/src/app/pos/types.ts)

### 4.1 Table
```ts
type TableStatusValue =
  | "free"         // Trống — green
  | "occupied"     // Đang có khách — red
  | "reserved"     // Đặt trước — amber
  | "cleaning"     // Đang dọn — slate
  | "out_of_service"; // Hỏng / tắt — destructive

interface TableResource {
  id: string;
  code: string;           // "T01", "B3"...
  name: string | null;    // Tên bàn (nếu có)
  seat_count: number;
  status: TableStatusValue;
  is_active: boolean;
  qr_token: string;
  current_order_id: string | null;  // null = bàn trống
  zone?: { id: string; code: string; name: string };
  created_at: string | null;
  updated_at: string | null;
}
```

### 4.2 CustomerOrder
```ts
type CustomerOrderStatus =
  | "open"      // Mới tạo, chưa có khách
  | "dining"    // Đang ăn / có món
  | "checkout"  // Yêu cầu thanh toán
  | "paying"    // Đang xử lý thanh toán
  | "closed"    // Đã đóng
  | "voided";   // Đã hủy

type CustomerOrderType = "spot" | "dine_in" | "takeaway";

interface CustomerOrder {
  id: string;
  order_code: string;
  order_type: CustomerOrderType;
  status: CustomerOrderStatus;
  subtotal: number | string;
  discount_amount: number | string;
  service_charge: number | string;
  tax_amount: number | string;
  total_amount: number | string;
  paid_amount: number | string;
  remaining_amount: string;   // computed: max(0, total - paid)
  guest_count: number | null;
  note: string | null;
  opened_at: string | null;
  tables?: TableSummary[];    // eager-loaded
  items?: CustomerOrderItem[]; // eager-loaded
}
```

### 4.3 OrderItem
```ts
type OrderItemStatus =
  | "pending"    // Chờ gửi / vừa thêm
  | "preparing"  // Bếp đang làm
  | "ready"      // Sẵn sàng ra bàn
  | "served"     // Đã ra
  | "voided";    // Đã hủy

interface CustomerOrderItem {
  id: string;
  customer_order_id: string;
  product_sku_id: string;
  quantity: number | string;
  unit_price: number | string;
  topping_subtotal: number | string;
  subtotal: number | string;
  status: OrderItemStatus;
  note: string | null;
  product_sku?: {
    id: string;
    name: string | null;    // tên SKU, e.g. "Tô đặc biệt"
    sku: string | null;
    selling_price: number | string;
    product?: { id: string; name: string }; // tên sản phẩm
    image_url?: string | null;
  };
}
```

### 4.4 Menu / Sản phẩm
```ts
interface ShopMenuProduct {
  id: string;
  menu_id: string;
  product_id: string;
  menu_section_id: string | null;
  section?: { id: string; name: string } | null;
  is_active: boolean;
  display_order: number;
  skus?: ShopMenuProductSku[];   // danh sách SKU + giá override
  product?: {
    id: string;
    name: string;
    description?: string | null;
    image_url?: string | null;
    gallery?: { id: string; url: string }[];
    topping_groups?: ShopMenuToppingGroup[]; // plan-015
  } | null;
  active_promotion?: {           // Happy Hour — plan-019
    id: string;
    discount_percent: number;
    discounted_price: number;
    ends_at: string;
  } | null;
}

interface ShopMenuProductSku {
  id: string;                 // menu_product_sku_id (dùng khi addItems)
  product_sku_id: string;
  selling_price: number;      // giá menu (có thể override giá SKU gốc)
  is_active: boolean;
}
```

### 4.5 Input Bodies
```ts
// POST /orders
interface OrderCreateInput {
  order_type?: "spot" | "dine_in" | "takeaway";
  table_ids?: string[];
  guest_count?: number;
  note?: string;
}

// PUT /orders/{id}/init  (first-write-wins)
interface OrderInitInput {
  table_ids?: string[];
  guest_count?: number;
}

// POST /orders/{id}/items → body: { items: OrderItemInput[] }
interface OrderItemInput {
  product_sku_id: string;
  menu_product_sku_id?: string; // bắt buộc nếu add từ menu (để lấy giá đúng)
  quantity: number;
  note?: string;
  toppings?: ToppingSelection[]; // plan-015
}

// PATCH /orders/{id}/items/{itemId}
interface OrderItemUpdateInput {
  quantity?: number;
  note?: string | null;
  status?: Exclude<OrderItemStatus, "voided">;
}
```

---

## 5. Màu sắc (Color Palette)

### Màu trạng thái bàn (từ table-status.ts)
| Status | Màu (light) | Token gợi ý |
|---|---|---|
| `free` | emerald — `#6ee7b7` / bg emerald-50 | `statusFree` |
| `occupied` | red — `#f87171` / bg red-50 | `statusOccupied` |
| `reserved` | amber — `#fbbf24` / bg amber-50 | `statusReserved` |
| `cleaning` | slate — `#94a3b8` / bg slate-100 | `statusCleaning` |
| `out_of_service` | destructive red | `statusOutOfService` |

### Màu chính của app (từ design file SVG)
| Token | Hex | Dùng ở đâu |
|---|---|---|
| `primary` | `#0077c7` | Header, CTA button, cart bar |
| `surface` | `#fafaf9` | Nền app |
| `card` | `#ffffff` | Nền card |
| `border` | `#d6d3d0` | Viền card |
| `divider` | `#ebe9e6` | Đường phân cách list |
| `textPrimary` | `#23221e` | Chữ chính |
| `textSecondary` | `#949495` | Chữ phụ |

---

## 6. Màn hình & Layout

### Screen 1 — Tables Overview (màn hình chính)
```
┌────────────────────────────────────┐
│  [Logo] HANDY       [Tên shop] 🔔 │  ← Header 48dp
├────────────────────────────────────┤
│  ┌──────────┐┌──────────┐┌───────┐│
│  │■ T01     ││■ T02     ││■ T03  ││  ← TableCard × 3 cột
│  │ Trống    ││ Có khách ││ Đặt   ││    stripe màu trái = status
│  │ 0 món    ││ 4 khách  ││ 3 khách││
│  └──────────┘└──────────┘└───────┘│
│  ┌──────────┐┌──────────┐┌───────┐│
│  │■ T04     ││■ T05     ││■ T06  ││
│  │ Dọn dẹp  ││ Có khách ││ Trống ││
│  └──────────┘└──────────┘└───────┘│
├────────────────────────────────────┤  ← divider
│  [order_code] Bàn T02    18:42    │  ← OrderRow
│  4 món · ¥3,500                   │
│ ─────────────────────────────────  │
│  [order_code] Bàn T05    17:58    │
│  2 món · ¥1,200                   │
└────────────────────────────────────┘
│  [🛒 3]   Xem giỏ hàng   ¥4,700→ │  ← CartBar fixed bottom 56dp
└────────────────────────────────────┘
```

**API calls trên màn hình này:**
- `GET /tables?per_page=100` — load grid bàn
- `GET /orders?status=open,dining&per_page=100` — load order list

**Tap vào bàn:**
- Nếu `current_order_id != null` → navigate đến Order Detail với id đó
- Nếu `current_order_id == null && status == "free"` → hiện dialog tạo order mới

---

### Screen 2 — Order Detail
Hiển thị khi tap vào bàn đang có order, hoặc sau khi tạo order mới.

```
┌────────────────────────────────────┐
│  ← Bàn T02          [dining] 4kh │  ← Header
├────────────────────────────────────┤
│  Phở đặc biệt              ×2     │
│  ¥900 × 2                 ¥1,800  │
│ ──────────────────────────────    │
│  Cơm sườn nướng            ×1     │
│  ¥750                      ¥750   │
│ ──────────────────────────────    │
│  [+ Thêm món]                     │  → navigate Menu
├────────────────────────────────────┤
│  Subtotal                 ¥2,550  │
│  Tax (10%)                ¥255    │
│  Total                    ¥2,805  │
└────────────────────────────────────┘
│  [📩 Gửi bếp]   [💬 Ghi chú]     │  ← action bar
└────────────────────────────────────┘
```

**API calls:**
- `GET /orders/{id}` — load order + items
- `PATCH /orders/{id}/items/{itemId}` — đổi số lượng
- `DELETE /orders/{id}/items/{itemId}` — xóa món

---

### Screen 3 — Menu Catalog
Mở khi tap "Thêm món" từ Order Detail.

```
┌────────────────────────────────────┐
│  ← Thêm món · Bàn T02            │
├────────────────────────────────────┤
│  [Đồ ăn]  [Đồ uống]  [Tráng miệng│  ← Section tabs (từ menu_sections)
├────────────────────────────────────┤
│  ┌────────┐ ┌────────┐ ┌────────┐ │
│  │ [img]  │ │ [img]  │ │ [img]  │ │  ← ProductCard 2 cột hoặc list
│  │Phở đặc │ │Cơm sườn│ │Bún bò  │ │
│  │ ¥900   │ │ ¥750   │ │ ¥850   │ │
│  │  [+]   │ │  [+]   │ │  [+]   │ │
│  └────────┘ └────────┘ └────────┘ │
└────────────────────────────────────┘
│  [🛒 3 món]  Xem giỏ (¥2,700) →  │  ← CartBar
└────────────────────────────────────┘
```

**API calls:**
- `GET /menus/by-day/{dayOfWeek}` — menu active hôm nay
- `GET /menus/{menuId}/products?search=...` — sản phẩm trong menu

---

### Screen 4 — Cart (Giỏ hàng tạm)
Local state — chưa gửi API. Khi bấm "Gửi bếp" mới gọi `POST /orders/{id}/items`.

```
┌────────────────────────────────────┐
│  ← Giỏ hàng · Bàn T02            │
├────────────────────────────────────┤
│  Phở đặc biệt           [-] 2 [+] │
│  Cơm sườn nướng         [-] 1 [+] │
├────────────────────────────────────┤
│  Total                    ¥2,550  │
└────────────────────────────────────┘
│       [Gửi lên bếp 🔥]            │  ← POST /orders/{id}/items
└────────────────────────────────────┘
```

---

## 7. Navigation Structure (Expo Router)

```
src/app/
├── _layout.tsx                  ← Stack root, không dùng tab bar
├── index.tsx                    ← Tables Overview (màn hình chính)
├── order/
│   └── [id].tsx                 ← Order Detail
├── menu/
│   └── index.tsx                ← Menu Catalog (nhận orderId qua params)
└── cart/
    └── index.tsx                ← Cart review (local state)
```

> **Không dùng bottom tab bar** — screen nhỏ, flow tuyến tính.  
> Stack navigation: push khi đi sâu, back để quay lại.

---

## 8. State Management

### Local (React state / Context)
- **CartState**: danh sách món chưa gửi (OrderItemInput[]) — xóa sau khi POST thành công
- **ActiveOrderId**: order đang mở theo bàn đang thao tác

### Server State (React Query — TanStack Query)
```
queryKeys:
  ["shop", shopSlug]
  ["tables", shopSlug, "list", filters]
  ["orders", shopSlug, "list", filters]
  ["orders", shopSlug, "detail", orderId]
  ["shop-menus", shopSlug, "by-day", dayOfWeek]
  ["shop-menus", shopSlug, "products", menuId, filters]
  ["shop", shopSlug, "settings", "order"]

staleTime gợi ý:
  tables:        60_000ms  (1 phút)
  orders list:   30_000ms  (30 giây)
  order detail:  30_000ms
  menu products: 300_000ms (5 phút — ít thay đổi)
```

---

## 9. API Client (cho React Native)

Khác với pos-web (dùng cookie/localStorage), React Native cần:
- Lưu token bằng `expo-secure-store`
- Auth header: `Authorization: Bearer {token}`
- Base URL: từ `expo-constants` (env) hoặc hardcode trong `app.json`

```ts
// src/lib/api.ts (RN version)
async function apiFetch<T>(path: string, options?: RequestInit): Promise<T> {
  const token = await SecureStore.getItemAsync('token');
  const locale = await AsyncStorage.getItem('locale') ?? 'ja';
  const baseUrl = Constants.expoConfig?.extra?.apiUrl ?? '';

  const response = await fetch(`${baseUrl}${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'Accept-Language': locale,
      ...(token && { Authorization: `Bearer ${token}` }),
      ...options?.headers,
    },
  });

  if (!response.ok) {
    const body = await response.json().catch(() => ({}));
    throw new ApiError(response.status, body);
  }
  if (response.status === 204) return null as T;
  return response.json();
}
```

---

## 10. Spacing & Layout Grid

| Token | Value (dp) | Ghi chú |
|---|---|---|
| `screenPaddingH` | 12 | Padding trái/phải màn hình |
| `cardGap` | 6 | Khoảng cách giữa card bàn |
| `cardWidth` | ~108 | (360 - 24 - 12) / 3 |
| `cardHeight` | ~80 | 3 dòng nội dung |
| `statusStripeWidth` | 4 | Stripe màu trái của card |
| `cardRadius` | 6 | Border radius card |
| `headerHeight` | 48 | |
| `cartBarHeight` | 56 | Fixed bottom |
| `sectionGap` | 16 | Khoảng cách giữa section |

---

## 11. Câu hỏi còn mở

- [ ] **Auth flow**: nhân viên dùng SSO (user login) hay device token (pairing)?
- [ ] **Model máy cụ thể**: Sunmi? PAX? Urovo? → ảnh hưởng printer SDK
- [ ] **Printer**: gọi SDK native hay forward qua workstation-app?
- [ ] **Offline mode**: cần không? SQLite local?
- [ ] **Handy chỉ add items hay còn xử lý checkout?**
- [ ] **i18n**: ja only hay ja/vi/en?
- [ ] **WebSocket / polling**: tự động refresh bàn khi status thay đổi từ POS khác?
