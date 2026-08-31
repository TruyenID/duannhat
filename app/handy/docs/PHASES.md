# Handy App — Roadmap theo Phase

> Đọc `DESIGN.md` trước để hiểu context, types, và endpoints.  
> Mỗi phase là một deliverable chạy được độc lập trên thiết bị.

---

## Phase 0 — Foundation (Nền tảng)
> Mục tiêu: app khởi động được, gọi API được, navigate được.

### Việc cần làm
- [ ] Xóa boilerplate Expo template (index, explore, components mặc định)
- [ ] Cài thêm deps: `@tanstack/react-query`, `expo-secure-store`, `expo-constants`
- [ ] `src/lib/api.ts` — `apiFetch` wrapper (Bearer token, Accept-Language, error handling)
- [ ] `src/lib/auth.ts` — `getToken` / `setToken` / `clearToken` dùng SecureStore
- [ ] `src/app/_layout.tsx` — Stack navigator, QueryClientProvider, không có tab bar
- [ ] `src/constants/colors.ts` — toàn bộ màu từ DESIGN.md
- [ ] `src/constants/theme.ts` — spacing, radius, layout constants
- [ ] `app.json` — thêm `extra.apiUrl`, lock orientation portrait
- [ ] Màn hình placeholder cho index / order / menu / cart

### Xong khi
App mở được, không crash, navigate qua lại giữa các screen placeholder.

---

## Phase 1 — Tables Overview (Màn hình chính)
> Mục tiêu: nhân viên nhìn thấy toàn bộ bàn + order đang mở.

### Việc cần làm
- [ ] `src/services/table-service.ts` — `tableService.list(shopSlug, filters)`
- [ ] `src/services/order-service.ts` — `orderService.list(shopSlug, filters)`
- [ ] `src/services/shop-service.ts` — `shopService.getBySlug(shopSlug)`
- [ ] `src/hooks/use-tables.ts` — React Query, staleTime 60s
- [ ] `src/hooks/use-open-orders.ts` — filter `status=open,dining`, staleTime 30s
- [ ] `src/components/TableCard.tsx`
  - Stripe màu trái theo `TableStatusValue`
  - Hiển thị: mã bàn, trạng thái, số khách, số món (từ order tương ứng)
  - Tap → navigate
- [ ] `src/components/OrderRow.tsx`
  - order_code, tên bàn, giờ mở, số món, tổng tiền
- [ ] `src/components/AppHeader.tsx` — logo + tên shop
- [ ] `src/app/index.tsx`
  - Grid 3 cột `TableCard` (FlatList `numColumns={3}`)
  - `OrderRow` list phía dưới (active orders)
  - Pull-to-refresh cả 2 query
  - Loading skeleton / error state
- [ ] Logic khi tap bàn:
  - `current_order_id != null` → push `/order/[id]`
  - `status == "free"` → dialog tạo order mới → `POST /orders` → push `/order/[id]`
  - Các status khác → toast "Bàn đang bận"

### Xong khi
Màn chính hiện đúng bàn, đúng màu status, tap vào bàn free/occupied hoạt động.

---

## Phase 2 — Order Detail
> Mục tiêu: xem và chỉnh sửa order đang mở tại bàn.

### Việc cần làm
- [ ] `src/services/order-service.ts` — bổ sung `orderService.get()`, `updateItem()`, `removeItem()`
- [ ] `src/hooks/use-order.ts` — query `/orders/{id}`, staleTime 30s
- [ ] `src/hooks/use-update-item.ts` — mutation PATCH item, setQueryData sau khi thành công
- [ ] `src/hooks/use-remove-item.ts` — mutation DELETE item
- [ ] `src/components/OrderItemRow.tsx`
  - Tên sản phẩm (product.name + sku.name)
  - Stepper `[-] qty [+]` → debounce PATCH
  - Swipe-to-delete hoặc nút xóa (chỉ khi status = `pending`)
  - Badge status: pending / preparing / ready / served
- [ ] `src/components/OrderSummary.tsx` — subtotal, tax, service, total
- [ ] `src/app/order/[id].tsx`
  - Header: mã bàn + order status badge + số khách
  - Danh sách `OrderItemRow`
  - Nút "Thêm món" → push `/menu?orderId={id}`
  - `OrderSummary` cố định ở cuối
  - Pull-to-refresh
- [ ] `src/hooks/use-create-order.ts` — dùng từ Phase 1 (tạo order khi bàn free)
- [ ] `src/hooks/use-init-order.ts` — PUT /init để gắn bàn + guest count

### Xong khi
Mở order thấy đúng items, đổi số lượng lưu được, xóa item được, navigate đến Menu.

---

## Phase 3 — Menu Catalog
> Mục tiêu: duyệt menu, thêm món vào giỏ local.

### Việc cần làm
- [ ] `src/services/shop-menu-service.ts` — `listByDay()`, `listProducts()`
- [ ] `src/hooks/use-menu-by-day.ts` — query by-day/{dayOfWeek}, staleTime 5 phút
- [ ] `src/hooks/use-menu-products.ts` — query products với search + infinite scroll
- [ ] `src/store/cart-store.ts` — local state (Zustand hoặc Context)
  - `items: OrderItemInput[]`
  - `addItem`, `removeItem`, `updateQty`, `clear`
  - Scope theo `orderId` — clear khi chuyển order
- [ ] `src/components/ProductCard.tsx`
  - Ảnh (image_url), tên sản phẩm, tên SKU
  - Giá (selling_price, có strikethrough nếu có `active_promotion`)
  - Nút `[+]` — thêm vào cart local
  - Badge số lượng nếu đã có trong cart
- [ ] `src/components/MenuSectionTabs.tsx` — tab ngang theo `menu_sections`
- [ ] `src/components/SearchBar.tsx` — debounce 300ms, clear button
- [ ] `src/components/CartBar.tsx` — fixed bottom, hiện số món + tổng tạm tính
- [ ] `src/app/menu/index.tsx`
  - Nhận `orderId` từ params
  - Header: "Thêm món · Bàn {code}"
  - `SearchBar` + `MenuSectionTabs`
  - Grid 2 cột `ProductCard` (FlatList numColumns={2})
  - `CartBar` cố định đáy → tap navigate `/cart?orderId={id}`

### Xong khi
Browse menu được, search được, thêm món vào cart local, CartBar hiện đúng số lượng.

---

## Phase 4 — Cart & Gửi bếp
> Mục tiêu: review giỏ hàng và gửi `POST /orders/{id}/items` lên server.

### Việc cần làm
- [ ] `src/services/order-service.ts` — bổ sung `orderService.addItems()`
- [ ] `src/hooks/use-add-items.ts` — mutation POST items, setQueryData sau thành công
- [ ] `src/components/CartItemRow.tsx` — tên, qty stepper, subtotal tạm tính
- [ ] `src/app/cart/index.tsx`
  - Nhận `orderId` từ params
  - Danh sách `CartItemRow` (từ cart-store)
  - Tổng tạm tính (chưa có tax — chỉ để nhân viên review)
  - Nút "Gửi lên bếp 🔥" → `POST /orders/{id}/items` → success → clear cart → pop về Order Detail
  - Loading state khi đang gửi
  - Error toast nếu thất bại
- [ ] Sau khi gửi thành công: invalidate `orders/{id}` query → Order Detail tự refresh

### Xong khi
Gửi món được lên bếp, order detail refresh ngay sau đó, cart được clear.

---

## Phase 5 — Polish & UX
> Mục tiêu: app dùng được thoải mái trong ca làm việc thực tế.

### Việc cần làm
- [ ] **Auto-refresh**: polling tables + orders list mỗi 30s (hoặc refetchInterval)
- [ ] **Skeleton loading**: thay spinner bằng skeleton placeholder cho card + list
- [ ] **Error boundary**: màn hình lỗi với nút retry
- [ ] **Guest count dialog**: khi tạo order mới → hỏi số khách
- [ ] **Note cho order item**: textarea nhỏ khi thêm món hoặc tap vào item
- [ ] **Haptic feedback**: `expo-haptics` khi tap add/remove item
- [ ] **Orientation lock**: confirm portrait-only hoạt động trên thiết bị thực
- [ ] **Empty states**: bàn trống, order không có món, menu không có sản phẩm
- [ ] **i18n cơ bản**: tách string ra file `src/i18n/ja.ts` (ja trước, en/vi sau)
- [ ] **Format tiền**: dùng `currency_code` từ `/settings/order`, format đúng locale

### Xong khi
QA trên thiết bị thực, không crash sau 1 ca làm việc.

---

## Phase 6 — Auth: Device Pairing via Verify Code
> Mục tiêu: thiết bị nhập verify code do admin tạo → nhận token → lưu vĩnh viễn.

> ✅ Đã xác nhận flow: **Device Token** (giống workstation-app, không dùng SSO)  
> Nguồn: `backend/app/Http/Controllers/Api/V1/Device/PairingController.php`  
>         `backend/app/Services/Device/DeviceService.php`

### Luồng xác thực
```
Admin tạo device trên web
  → BE tự sinh pairing_code (6 ký tự, expire 15 phút)
  → Nhân viên nhìn code trên web, nhập vào máy handy
  → Máy POST /api/v1/devices/pair
  → BE trả device_token (64 ký tự)
  → Lưu vào SecureStore → dùng mãi cho đến khi bị revoke
```

### Endpoint
```
POST /api/v1/devices/pair   ← Public, không cần auth
Body:
  pairing_code: string   // Bắt buộc, đúng 6 ký tự (BE sinh chữ hoa + số)
  device_info?: {
    user_agent?: string
    app_version?: string
  }

Response (200):
  {
    device_token: string,   // 64 ký tự — lưu SecureStore, dùng làm Bearer token
    device: {
      id: string,
      name: string,
      type: string,         // "handy" | "pos" | "tms" | "workstation"
      status: "active",
      branch_id: string,
      branch: { id, name },
      paired_at: string,
    }
  }

Error (422): pairing_code sai / hết hạn
  { message: string, errors: { pairing_code: string[] } }
```

### Sau khi pair
- Mọi request dùng header: `Authorization: Bearer {device_token}`
- Token không expire tự động — chỉ mất khi admin revoke trên web
- Khi nhận 401 → xóa token khỏi SecureStore → redirect về màn hình nhập code

### Việc cần làm
- [ ] `src/lib/auth.ts`
  - `getToken()` → `SecureStore.getItemAsync('device_token')`
  - `setToken(token, deviceInfo)` → lưu token + branch_id + device name
  - `clearToken()` → xóa SecureStore
  - `getStoredDevice()` → đọc device info đã lưu
- [ ] `src/services/pairing-service.ts`
  - `pair(pairingCode: string, deviceInfo?: {...})` → `POST /api/v1/devices/pair`
  - Response type: `{ device_token: string, device: DeviceResource }`
- [ ] `src/app/pair/index.tsx` — màn hình nhập code
  - 6 ô input tách biệt (OTP style) hoặc 1 TextInput uppercase
  - Auto-submit khi đủ 6 ký tự
  - Loading state khi đang gọi API
  - Error message từ response (code sai / hết hạn)
  - Hướng dẫn: "Nhờ admin tạo code trên web — code hết hạn sau 15 phút"
- [ ] `src/app/_layout.tsx` — Auth Guard
  - Kiểm tra token khi app khởi động
  - Có token → stack chính (index)
  - Không có token → redirect `/pair`
  - Intercept 401 trong `apiFetch` → `clearToken()` → navigate `/pair`

---

## Phase 7 — Printer Integration
> Mục tiêu: in phiếu tạm (kitchen ticket) sau khi gửi bếp.

> ⚠️ Phụ thuộc vào model máy cụ thể và SDK printer (xem DESIGN.md §11)

- [ ] Xác nhận model máy → chọn SDK (Sunmi SDK / Star SDK / ESC/POS raw)
- [ ] `src/lib/printer.ts` — `printKitchenTicket(order, newItems)`
- [ ] Trigger print sau khi `POST /orders/{id}/items` thành công
- [ ] Format ticket: tên bàn, số khách, danh sách món + qty, giờ in
- [ ] Error handling: in thất bại không block flow chính

---

## Thứ tự ưu tiên

```
Phase 0  →  Phase 1  →  Phase 2  →  Phase 3  →  Phase 4
(nền tảng)  (bàn)       (order)     (menu)       (gửi bếp)
    ↓
Phase 5  (polish — song song hoặc sau Phase 4)
    ↓
Phase 6  (auth — sớm nhất có thể nếu dùng device token)
    ↓
Phase 7  (printer — cuối cùng)
```

> Phase 6 (Auth) nên làm **sớm** nếu dùng device token — nếu không thì dev tạm thời hardcode token vào env để unblock các phase khác.
