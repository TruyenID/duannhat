# Handy App — Prompt cho từng Phase

> Copy nguyên đoạn prompt tương ứng vào conversation mới.  
> Mỗi prompt đã self-contained — không cần giải thích thêm.

---

## Phase 0 — Foundation

```
Tôi đang build app React Native (Expo 56, expo-router) tên godx-handy.
Đây là app nhận order tại bàn cho nhân viên nhà hàng, chạy trên thiết bị POS Handy 5.5 inch Android, portrait only.

Project path: /Users/shu/Documents/Project/godx-tempo/godx-handy
Đọc 2 file này trước khi làm bất cứ thứ gì:
- docs/DESIGN.md   → context, types, endpoints, màu sắc, layout
- docs/PHASES.md   → toàn bộ roadmap

Nhiệm vụ của conversation này: **Phase 0 — Foundation**

Làm theo đúng checklist trong PHASES.md §Phase 0:
1. Xóa toàn bộ boilerplate Expo template (giữ nguyên cấu trúc thư mục src/)
2. Cài thêm: @tanstack/react-query, expo-secure-store (kiểm tra package.json trước, chỉ cài cái chưa có)
3. Tạo src/lib/api.ts — apiFetch wrapper (Bearer token từ SecureStore, Accept-Language, throw ApiError khi !response.ok)
4. Tạo src/lib/auth.ts — getToken / setToken / clearToken / getStoredDevice dùng expo-secure-store
5. Tạo src/constants/colors.ts — đầy đủ màu từ DESIGN.md §5 (status bàn + màu app)
6. Tạo src/constants/theme.ts — spacing, radius, layout constants từ DESIGN.md §10
7. Cập nhật src/app/_layout.tsx — Stack navigator + QueryClientProvider, chưa có tab bar, chưa có auth guard (phase 6 làm)
8. Cập nhật app.json — thêm extra.apiUrl (placeholder), orientation: portrait
9. Tạo màn hình placeholder cho: index.tsx, order/[id].tsx, menu/index.tsx, cart/index.tsx, pair/index.tsx

Yêu cầu kỹ thuật:
- TypeScript strict
- Không dùng StyleSheet.create cho màu — dùng constants/colors.ts
- apiFetch đọc BASE_URL từ Constants.expoConfig?.extra?.apiUrl
- Không viết comment giải thích "what" — chỉ comment khi có điều bất ngờ
- Kiểm tra app chạy được (npx expo start) trước khi báo xong
```

---

## Phase 1 — Tables Overview

```
Tôi đang build app React Native (Expo 56, expo-router) tên godx-handy.
Đây là app nhận order tại bàn cho nhân viên nhà hàng, chạy trên thiết bị POS Handy 5.5 inch Android, portrait only.

Project path: /Users/shu/Documents/Project/godx-tempo/godx-handy
Đọc 2 file này trước khi làm bất cứ thứ gì:
- docs/DESIGN.md   → context, types, endpoints, màu sắc, layout
- docs/PHASES.md   → toàn bộ roadmap

Phase 0 đã xong: apiFetch, auth helpers, colors, theme, Stack navigator, QueryClientProvider đều đã có.

Nhiệm vụ của conversation này: **Phase 1 — Tables Overview (màn hình chính)**

Làm theo đúng checklist trong PHASES.md §Phase 1:
1. src/services/table-service.ts — tableService.list(shopSlug, filters) → GET /api/v1/shops/{shopSlug}/tables
2. src/services/order-service.ts — orderService.list(shopSlug, filters) → GET /api/v1/shops/{shopSlug}/orders
3. src/services/shop-service.ts — shopService.getBySlug(shopSlug) → GET /api/v1/shops/{shopSlug}
4. src/hooks/use-tables.ts — useQuery, staleTime 60_000
5. src/hooks/use-open-orders.ts — filter status="open,dining", staleTime 30_000
6. src/components/TableCard.tsx
   - Props: table: TableResource, order?: CustomerOrder (order tương ứng nếu có)
   - Stripe màu 4dp bên trái theo TableStatusValue (màu từ colors.ts)
   - Hiện: code/name bàn, trạng thái (text), số khách, số món
   - Kích thước: width ~108dp, height ~80dp (xem DESIGN.md §10 card grid)
   - Touchable — onPress truyền từ ngoài
7. src/components/OrderRow.tsx
   - order_code, tên bàn (từ order.tables), giờ mở (opened_at), số món, total_amount
   - Format tiền: tạm dùng JPY (currency từ settings sẽ làm phase 5)
8. src/components/AppHeader.tsx — logo text "HANDY" + tên shop
9. src/app/index.tsx
   - AppHeader
   - FlatList numColumns={3} render TableCard, gap 6dp, paddingH 12dp
   - Divider
   - FlatList orders render OrderRow
   - Pull-to-refresh refetch cả 2 query
   - Loading: ActivityIndicator khi lần đầu load
   - Error: Text đỏ + retry button
   - Logic tap bàn:
     * current_order_id != null → router.push('/order/' + current_order_id)
     * status == 'free' → gọi POST /orders tạo mới → router.push('/order/' + newId)
     * status khác → không làm gì (hoặc toast nhẹ)
10. src/services/order-service.ts — bổ sung orderService.create() → POST /api/v1/shops/{shopSlug}/orders

Hardcode tạm shopSlug = 'demo-shop' trong index.tsx (Phase 6 sẽ đọc từ stored device info).

Yêu cầu kỹ thuật:
- Types lấy từ DESIGN.md §4 (TableResource, CustomerOrder...) — tạo src/types/pos.ts
- Không dùng any
- Service functions return Promise, không try/catch bên trong — để hook xử lý error
- Sau khi xong chạy thử trên simulator/device, confirm màn hình render đúng grid 3 cột
```

---

## Phase 2 — Order Detail

```
Tôi đang build app React Native (Expo 56, expo-router) tên godx-handy.
Đây là app nhận order tại bàn cho nhân viên nhà hàng, chạy trên thiết bị POS Handy 5.5 inch Android, portrait only.

Project path: /Users/shu/Documents/Project/godx-tempo/godx-handy
Đọc 2 file này trước khi làm bất cứ thứ gì:
- docs/DESIGN.md   → context, types, endpoints, màu sắc, layout
- docs/PHASES.md   → toàn bộ roadmap

Phase 0 + 1 đã xong: apiFetch, services, hooks tables/orders, TableCard, màn hình chính hoạt động.

Nhiệm vụ của conversation này: **Phase 2 — Order Detail**

Làm theo đúng checklist trong PHASES.md §Phase 2:
1. Bổ sung src/services/order-service.ts:
   - orderService.get(shopSlug, orderId) → GET /api/v1/shops/{shopSlug}/orders/{id}
   - orderService.updateItem(shopSlug, orderId, itemId, body) → PATCH .../items/{itemId}
   - orderService.removeItem(shopSlug, orderId, itemId) → DELETE .../items/{itemId}
   - orderService.init(shopSlug, orderId, body) → PUT .../init
2. src/hooks/use-order.ts — query detail, staleTime 30_000
3. src/hooks/use-update-item.ts — mutation PATCH, onSuccess: setQueryData(detail key)
4. src/hooks/use-remove-item.ts — mutation DELETE, onSuccess: setQueryData(detail key)
5. src/hooks/use-init-order.ts — mutation PUT /init (gắn table_ids + guest_count)
6. src/components/OrderItemRow.tsx
   - Tên: product.name + (sku.name nếu có)
   - Stepper [-] qty [+] — PATCH debounce 500ms
   - Nút xóa — chỉ enable khi item.status == 'pending'
   - Badge status: pending/preparing/ready/served (màu khác nhau)
   - unit_price × qty = subtotal hiển thị phải
7. src/components/OrderSummary.tsx
   - subtotal, tax_amount, service_charge, total_amount
   - Tất cả field là number | string từ BE → parse bằng Number()
8. src/app/order/[id].tsx
   - useLocalSearchParams để lấy id
   - Header: "Bàn {code}" + badge status order + số khách
   - ScrollView chứa list OrderItemRow
   - Nút "+ Thêm món" → router.push('/menu?orderId=' + id)
   - OrderSummary cố định cuối màn hình (trên cart bar)
   - Pull-to-refresh
   - Loading + error state

Yêu cầu kỹ thuật:
- setQueryData dùng key: ['orders', shopSlug, 'detail', orderId] sau mỗi mutation thành công
- Invalidate ['orders', shopSlug, 'list', ...] sau khi mutation để list ở màn hình chính refresh
- Không block UI khi đang gọi PATCH — optimistic update qty local, rollback nếu error
- Số tiền: Number(item.unit_price), Number(item.subtotal) — không string concat
```

---

## Phase 3 — Menu Catalog

```
Tôi đang build app React Native (Expo 56, expo-router) tên godx-handy.
Đây là app nhận order tại bàn cho nhân viên nhà hàng, chạy trên thiết bị POS Handy 5.5 inch Android, portrait only.

Project path: /Users/shu/Documents/Project/godx-tempo/godx-handy
Đọc 2 file này trước khi làm bất cứ thứ gì:
- docs/DESIGN.md   → context, types, endpoints, màu sắc, layout
- docs/PHASES.md   → toàn bộ roadmap

Phase 0 + 1 + 2 đã xong.

Nhiệm vụ của conversation này: **Phase 3 — Menu Catalog**

Làm theo đúng checklist trong PHASES.md §Phase 3:
1. src/services/shop-menu-service.ts
   - shopMenuService.listByDay(shopSlug, dayOfWeek) → GET .../menus/by-day/{day}
     dayOfWeek = new Date().getDay() (0=Sun…6=Sat)
   - shopMenuService.listProducts(shopSlug, menuId, filters) → GET .../menus/{id}/products
     filters: { search?, page?, per_page? }
2. src/hooks/use-menu-by-day.ts — staleTime 5 phút
3. src/hooks/use-menu-products.ts — có thể dùng useInfiniteQuery nếu cần phân trang
4. src/store/cart-store.ts — Zustand store (cài zustand nếu chưa có)
   State: { orderId: string, items: CartItem[], }
   CartItem: { product_sku_id, menu_product_sku_id, name, selling_price, quantity, note? }
   Actions: setOrderId, addItem, removeItem, updateQty, clear
   Rule: gọi clear() + setOrderId() khi orderId thay đổi
5. src/components/ProductCard.tsx
   - image_url (dùng expo-image), tên sản phẩm, tên SKU (nếu nhiều SKU)
   - Giá selling_price, strikethrough nếu active_promotion
   - Nút [+] → addItem vào cart store
   - Badge số lượng đang có trong cart (lấy từ store)
   - Width: (screenWidth - 12*2 - 8) / 2 ≈ 166dp (2 cột)
6. src/components/MenuSectionTabs.tsx
   - Tab ngang scroll được (ScrollView horizontal)
   - Mỗi tab = 1 section từ menu.menu_sections
   - Tab "Tất cả" ở đầu
7. src/components/SearchBar.tsx
   - TextInput debounce 300ms
   - Clear button khi có text
8. src/components/CartBar.tsx
   - Fixed bottom, height 56dp, background primary (#0077c7)
   - Trái: circle badge số lượng items
   - Phải: "Xem giỏ" + tổng tạm tính
   - onPress → router.push('/cart?orderId=' + orderId)
   - Ẩn khi cart rỗng
9. src/app/menu/index.tsx
   - Nhận orderId từ useLocalSearchParams
   - Load menu hôm nay → nếu có nhiều menu hiện picker, nếu 1 menu load thẳng
   - SearchBar + MenuSectionTabs
   - FlatList numColumns={2} render ProductCard, filter theo section đang chọn
   - CartBar cố định đáy

Yêu cầu kỹ thuật:
- cart-store chỉ là local state, không gọi API (Phase 4 mới gửi)
- expo-image thay cho Image (đã có trong deps)
- Khi search: reset về section "Tất cả", clear filter section
```

---

## Phase 4 — Cart & Gửi bếp

```
Tôi đang build app React Native (Expo 56, expo-router) tên godx-handy.
Đây là app nhận order tại bàn cho nhân viên nhà hàng, chạy trên thiết bị POS Handy 5.5 inch Android, portrait only.

Project path: /Users/shu/Documents/Project/godx-tempo/godx-handy
Đọc 2 file này trước khi làm bất cứ thứ gì:
- docs/DESIGN.md   → context, types, endpoints, màu sắc, layout
- docs/PHASES.md   → toàn bộ roadmap

Phase 0 + 1 + 2 + 3 đã xong. cart-store (Zustand) đã có với CartItem và các actions.

Nhiệm vụ của conversation này: **Phase 4 — Cart & Gửi bếp**

Làm theo đúng checklist trong PHASES.md §Phase 4:
1. Bổ sung src/services/order-service.ts:
   - orderService.addItems(shopSlug, orderId, items: OrderItemInput[])
     → POST /api/v1/shops/{shopSlug}/orders/{id}/items
     → Body: { items: [ { product_sku_id, menu_product_sku_id?, quantity, note? } ] }
     → Response: { data: CustomerOrder } (full order với items đã recompute)
2. src/hooks/use-add-items.ts
   - mutation addItems
   - onSuccess: setQueryData(['orders', shopSlug, 'detail', orderId], response)
   - onSuccess: invalidate ['orders', shopSlug, 'list', ...]
   - onSuccess: cartStore.clear()
3. src/components/CartItemRow.tsx
   - Tên sản phẩm, stepper [-] qty [+] (local state trong store)
   - Subtotal tạm: selling_price × qty
   - Nút xóa item khỏi cart (không gọi API — chỉ remove khỏi store)
4. src/app/cart/index.tsx
   - Nhận orderId từ useLocalSearchParams
   - Header: "Giỏ hàng · Bàn {code}" (load order để lấy tên bàn)
   - List CartItemRow từ cart-store
   - Tổng tạm tính (subtotal local, không có tax — chỉ để review trước khi gửi)
   - Nút "Gửi lên bếp 🔥"
     * isLoading → disable + spinner
     * onPress → addItems mutation → nếu thành công → router.back() về order detail
     * onError → hiện Alert hoặc toast với error message từ BE
   - Empty state nếu cart rỗng (nút "Thêm món")

Yêu cầu kỹ thuật:
- Sau khi gửi thành công: clear cart → router.back() — không navigate sang screen khác
- order detail tự refresh nhờ setQueryData + invalidate
- Nếu addItems thất bại: KHÔNG clear cart (để user retry)
- Mapping CartItem → OrderItemInput: { product_sku_id, menu_product_sku_id, quantity, note }
```

---

## Phase 5 — Polish & UX

```
Tôi đang build app React Native (Expo 56, expo-router) tên godx-handy.
Đây là app nhận order tại bàn cho nhân viên nhà hàng, chạy trên thiết bị POS Handy 5.5 inch Android, portrait only.

Project path: /Users/shu/Documents/Project/godx-tempo/godx-handy
Đọc 2 file này trước khi làm bất cứ thứ gì:
- docs/DESIGN.md   → context, types, endpoints, màu sắc, layout
- docs/PHASES.md   → toàn bộ roadmap

Phase 0–4 đã xong: full flow tạo order, thêm món, gửi bếp hoạt động.

Nhiệm vụ của conversation này: **Phase 5 — Polish & UX**

Làm theo checklist trong PHASES.md §Phase 5. Ưu tiên theo thứ tự:

1. **Auto-refresh**: tables + orders list refetchInterval 30_000 (chỉ khi app ở foreground — dùng useAppState hoặc AppState từ RN)
2. **Skeleton loading**: thay ActivityIndicator bằng skeleton placeholder
   - TableCard skeleton: 6 rectangles grey animated
   - OrderRow skeleton: 2-3 dòng grey
   - Dùng Animated.loop + opacity hoặc react-native-reanimated (đã có trong deps)
3. **Format tiền**:
   - Gọi GET /api/v1/shops/{shopSlug}/settings/order lấy currency_code
   - src/lib/format-money.ts: formatMoney(amount: number, currencyCode: string)
   - Dùng Intl.NumberFormat nếu RN hỗ trợ, fallback thủ công
   - Apply vào tất cả chỗ hiện tổng tiền
4. **Guest count dialog**: khi tạo order mới (tap bàn free) → modal hỏi số khách trước khi POST
5. **Item note**: khi long-press OrderItemRow trong order detail → modal TextInput ghi chú
   → PATCH item với { note: string }
6. **Empty states**:
   - Màn hình chính: không có bàn nào
   - Order detail: chưa có món nào (mới tạo)
   - Menu: không tìm thấy sản phẩm
7. **Haptic feedback**: expo-haptics
   - Light: tap [+] thêm món
   - Medium: "Gửi bếp" thành công
8. **i18n cơ bản**: tạo src/i18n/ja.ts với tất cả string UI, dùng qua hook useT()
   (đơn giản — không cần i18next, chỉ cần object + hook)
9. **Error boundary**: wrap màn hình chính, hiện "Có lỗi xảy ra" + nút Retry

Chỉ làm những gì trong checklist. Không thêm feature ngoài danh sách.
```

---

## Phase 6 — Auth: Device Pairing

```
Tôi đang build app React Native (Expo 56, expo-router) tên godx-handy.
Đây là app nhận order tại bàn cho nhân viên nhà hàng, chạy trên thiết bị POS Handy 5.5 inch Android, portrait only.

Project path: /Users/shu/Documents/Project/godx-tempo/godx-handy
Đọc 2 file này trước khi làm bất cứ thứ gì:
- docs/DESIGN.md   → context, types, endpoints, màu sắc, layout (§3.1 Auth endpoint)
- docs/PHASES.md   → toàn bộ roadmap (§Phase 6 có đầy đủ endpoint + response shape)

Nhiệm vụ của conversation này: **Phase 6 — Auth: Device Pairing via Verify Code**

Endpoint đã xác nhận từ backend:
  POST /api/v1/devices/pair  (public)
  Body: { pairing_code: string (6 ký tự), device_info?: { user_agent?, app_version? } }
  Response: { device_token: string (64 ký tự), device: { id, name, type, branch_id, branch: {id, name} } }
  Error 422: { message, errors: { pairing_code: string[] } }
  Token không expire — chỉ mất khi admin revoke → BE trả 401

Làm theo checklist trong PHASES.md §Phase 6:
1. Cập nhật src/lib/auth.ts:
   - getToken() → SecureStore.getItemAsync('device_token')
   - setToken(token: string) → SecureStore.setItemAsync('device_token', token)
   - setStoredDevice(device: DeviceInfo) → SecureStore.setItemAsync('device_info', JSON.stringify(device))
   - getStoredDevice() → parse JSON từ SecureStore
   - clearToken() → SecureStore.deleteItemAsync cho cả token + device_info
   DeviceInfo type: { id, name, type, branch_id, branchName, shopSlug? }

2. src/services/pairing-service.ts:
   - pair(code: string) → POST /api/v1/devices/pair
   - Không dùng apiFetch (chưa có token) — dùng fetch thuần với BASE_URL từ Constants

3. src/app/pair/index.tsx — màn hình nhập code:
   - 6 ô TextInput tách biệt (OTP style), mỗi ô 1 ký tự
   - Tự động focus ô tiếp theo khi nhập, focus ô trước khi xóa
   - Uppercase tự động (autoCapitalize='characters')
   - Auto-submit khi ô cuối được nhập
   - isLoading: disable input + hiện spinner
   - Error: hiện message từ response.errors.pairing_code[0] bên dưới input
   - Text hướng dẫn: "Nhờ admin tạo device trên web → nhập code 6 ký tự (hết hạn sau 15 phút)"
   - Sau pair thành công: lưu token + device info → router.replace('/')

4. Cập nhật src/app/_layout.tsx — Auth Guard:
   - useEffect: đọc token từ SecureStore khi app mount
   - Có token → render Stack bình thường (route '/')
   - Không có token → router.replace('/pair')
   - Intercept 401 trong apiFetch → clearToken() → router.replace('/pair')
   - Loading state khi đang check token (tránh flash màn hình)

5. Cập nhật src/app/index.tsx (và các screen khác):
   - Thay hardcode 'demo-shop' bằng getStoredDevice().shopSlug
   - Nếu shopSlug chưa có trong device_info → cần thêm trường này hoặc map từ branch_id

Lưu ý: sau khi pair, device.branch_id có thể cần 1 call thêm để lấy shopSlug.
Kiểm tra backend response xem có trả shopSlug không — nếu không thì cần gọi thêm
GET /api/v1/shops/{shopSlug} hoặc dùng branch_id làm identifier tạm.
```

---

## Phase 7 — Printer Integration

```
Tôi đang build app React Native (Expo 56, expo-router) tên godx-handy.
Đây là app nhận order tại bàn cho nhân viên nhà hàng, chạy trên thiết bị POS Handy 5.5 inch Android, portrait only.

Project path: /Users/shu/Documents/Project/godx-tempo/godx-handy
Đọc 2 file này trước khi làm bất cứ thứ gì:
- docs/DESIGN.md   → context, types, endpoints
- docs/PHASES.md   → toàn bộ roadmap (§Phase 7)

Phase 0–6 đã xong. App hoạt động đầy đủ, auth bằng device token.

Nhiệm vụ của conversation này: **Phase 7 — Printer Integration (thermal printer tích hợp)**

⚠️ TRƯỚC KHI LÀM: xác nhận model máy handy với tôi để biết SDK phù hợp.
Nếu là Sunmi → dùng react-native-sunmi-printer hoặc Sunmi SDK
Nếu là ESC/POS thuần → dùng raw bytes qua TCP/USB

Sau khi xác nhận model, làm:
1. src/lib/printer.ts
   - printKitchenTicket(params: { tableCode, guestCount, items: CartItem[], orderedAt: Date })
   - Format ticket ESC/POS: tên bàn, số khách, danh sách món + qty, giờ gửi
   - Return Promise<void> — throw nếu lỗi
2. Trigger trong src/hooks/use-add-items.ts:
   - Sau khi POST /items thành công → gọi printKitchenTicket
   - Lỗi print: log + toast warning "In thất bại" — KHÔNG block flow (order đã gửi rồi)
3. Cài native module phù hợp với model máy
4. Test trên thiết bị thực — simulator không test được printer

Cung cấp model máy trước khi bắt đầu.
```
