# Plan 024 — Hướng dẫn Test UI (Manual)

> Hướng dẫn test thủ công 3 phần UI mà plan-024 đã ship trên `admin-web`:
> 1. **G1** — `ProductSku.inventory_mode` (HQ Brand Admin)
> 2. **G4** — `Warehouse.allow_negative_sales` toggle (Org Admin)
> 3. **G6** — Inline threshold-edit sheet trên stock-alerts page (Shop Manager)
>
> Backend logic (G2/G3/G5) chỉ kích hoạt khi đóng đơn (`OrderClosingService`) — phần verify này nằm ở mục **End-to-end** cuối file.

---

## 0. Chuẩn bị môi trường

### 0.1 Khởi động stack

```sh
# Từ umbrella root
docker compose up -d                                            # backend :5400 + mysql :3307
docker compose exec app php artisan migrate:fresh --seed --force # seed lại DB (chạy trong container)
pnpm install                                                     # nếu chưa cài
pnpm dev:admin                                                   # admin-web :5430
```

Mở [http://localhost:5430](http://localhost:5430). API backend mặc định trỏ về `http://localhost:5400`.

### 0.2 Tài khoản test (cần chuẩn bị từ seed)

| Role | Dùng để test | Ghi chú |
|------|-------------|--------|
| `hq-admin` (Brand Admin) | G1 — inventory_mode | Truy cập `/hq/[brandSlug]/...` |
| `org-admin` | G4 — allow_negative_sales | Truy cập warehouse settings dialog |
| `shop-manager` | G6 — threshold sheet, G4 hiển thị disabled | Truy cập `/shop/[shopSlug]/stock/alerts` |
| `shop-staff` | G6 negative test (không được phép) | Cùng route nhưng không thấy action |

> Nếu seed mặc định không có đủ các role này, tạo user qua tinker:
> ```sh
> docker compose exec app php artisan tinker
> ```

### 0.3 Dữ liệu cần seed

- Ít nhất **1 Brand** + **1 Shop** + **1 Warehouse** trong shop.
- Ít nhất **1 Product + 1 ProductSku** dưới brand (để test G1).
- Ít nhất **1 StockLevel có `quantity < min_stock`** trong warehouse, kèm **1 StockAlert active** (low_stock) (để test G6).
- Ít nhất **1 Recipe** liên kết với SKU `track_stock` + 2 Material có lot (để test E2E G3).

---

## 1. G1 — ProductSku Inventory Mode

### 1.1 Vị trí

[admin-web/src/app/hq/[brandSlug]/products/[id]/skus/[skuId]/page.tsx](admin-web/src/app/hq/%5BbrandSlug%5D/products/%5Bid%5D/skus/%5BskuId%5D/page.tsx)

**Path browser:** `/hq/{brandSlug}/products/{productId}/skus/{skuId}`

### 1.2 Checklist test

| # | Hành động | Kết quả mong đợi |
|---|-----------|------------------|
| 1.1 | Login `hq-admin` → vào trang edit 1 SKU mới (chưa set `inventory_mode`) | Trường **Inventory mode** (在庫モード / Chế độ tồn kho) hiển thị, default = **Made to order** |
| 1.2 | Đọc inline description dưới select | Có 2 dòng: "Made to order — không track kho" / "Track stock — trừ kho khi bán (dùng recipe nếu có)" — bằng ngôn ngữ đang chọn |
| 1.3 | Đổi sang **Track stock** → Save | Toast success xuất hiện; form mark dirty trước khi save, clean sau save |
| 1.4 | Reload trang | Field giữ giá trị **Track stock** |
| 1.5 | Đổi locale (ja → en → vi) | Label + description + 2 option đều có bản dịch, không có key thô (`inventory_mode.label`) |
| 1.6 | Mở DevTools → Network → submit form | Request PATCH `/api/v1/hq/{brandSlug}/product-skus/{id}` có field `inventory_mode: "track_stock"` |
| 1.7 | Submit với giá trị hợp lệ khác → check response | Response 200, body có `inventory_mode` |
| 1.8 | (Negative) Login `shop-manager` (không phải HQ admin) → vào URL HQ trên | Truy cập bị chặn ở routing layer (403/redirect) — KHÔNG được lọt vào trang |
| 1.9 | DevTools Console khi vào trang + thao tác | KHÔNG có lỗi đỏ; không có warning `Missing translation key` |

### 1.3 Visual checklist

- [ ] Select nằm gần cụm "commerce config" (price / SKU code), KHÔNG nằm trong cụm localisation.
- [ ] Font: M PLUS 2.
- [ ] Density: control height 32px (theo design system).
- [ ] Sau khi save không reload, UI phản ánh giá trị mới ngay (TanStack Query invalidate).

---

## 2. G4 — Warehouse Allow Negative Sales Toggle

### 2.1 Vị trí

[admin-web/src/app/shop/[shopSlug]/warehouses/components/warehouse-form-dialog.tsx](admin-web/src/app/shop/%5BshopSlug%5D/warehouses/components/warehouse-form-dialog.tsx)

**Path browser:** `/shop/{shopSlug}/warehouses` → click **Edit** trên 1 row → Dialog mở ra. (Đây là dialog, KHÔNG phải route riêng.)

### 2.2 Checklist test

| # | Hành động | Kết quả mong đợi |
|---|-----------|------------------|
| 2.1 | Login `org-admin` → mở Edit warehouse dialog | Thấy switch **Allow negative stock on sales** (売上時のマイナス在庫を許可 / Cho phép tồn kho âm khi bán hàng) nằm cùng nhóm với `auto_approve_*` |
| 2.2 | Đọc inline description | Mô tả: cho phép bán dưới 0, sẽ fire `out_of_stock` alert, hữu ích cho restaurant cao tải |
| 2.3 | Bật toggle → Save | Toast confirm; dialog đóng |
| 2.4 | Mở lại Edit | Toggle giữ trạng thái ON |
| 2.5 | Tắt toggle → Save | Toggle về OFF, persist sau reload |
| 2.6 | DevTools → Network | PATCH (hoặc PUT) tới `warehouses/{id}/...` có `allow_negative_sales: true/false` |
| 2.7 | (Negative) Login `shop-manager` → mở dialog | Toggle **disabled / read-only**, có tooltip "Org admin only" (hoặc tương đương) |
| 2.8 | (Negative) `shop-staff` vào trang | KHÔNG có nút Edit; nếu chỉ xem được list thì toggle cũng disabled |
| 2.9 | Đổi locale → toggle | Label + description dịch đầy đủ |

### 2.3 Visual checklist

- [ ] Switch component `@godxjp/ui`, KHÔNG dùng checkbox tự chế.
- [ ] Audit log có entry khi flag thay đổi (kiểm trong `audit_logs` table hoặc UI audit page nếu có).

---

## 3. G6 — Inline Threshold Sheet trên Stock-Alerts Page

### 3.1 Vị trí

- Trang: [admin-web/src/app/shop/[shopSlug]/stock/alerts/page.tsx](admin-web/src/app/shop/%5BshopSlug%5D/stock/alerts/page.tsx)
- Table: [admin-web/src/app/shop/[shopSlug]/stock/alerts/components/stock-alert-table.tsx](admin-web/src/app/shop/%5BshopSlug%5D/stock/alerts/components/stock-alert-table.tsx)
- Sheet: [admin-web/src/app/shop/[shopSlug]/stock/alerts/components/stock-level-threshold-sheet.tsx](admin-web/src/app/shop/%5BshopSlug%5D/stock/alerts/components/stock-level-threshold-sheet.tsx)

**Path browser:** `/shop/{shopSlug}/stock/alerts`

### 3.2 Setup dữ liệu trước khi test

Cần có ít nhất:
- 1 StockLevel có `quantity=8, min_stock=10, max_stock=100, alert_enabled=true`.
- 1 StockAlert active (status=`active`, type=`low_stock`) trỏ tới StockLevel trên.
- (Optional) 1 alert đã `resolved` để test filter.

### 3.3 Checklist test — Happy path

| # | Hành động | Kết quả mong đợi |
|---|-----------|------------------|
| 3.1 | Login `shop-manager` → vào `/shop/{shopSlug}/stock/alerts` | Bảng alerts hiển thị, có cột **Actions** (`⋮` rightmost) |
| 3.2 | Click `⋮` trên 1 row | Dropdown mở ra với mục "Configure threshold" |
| 3.3 | Click "Configure threshold" | **Sheet** trượt vào từ bên phải |
| 3.4 | Kiểm tra header sheet | Hiển thị: tên warehouse (read-only), tên item (read-only), current quantity (read-only) |
| 3.5 | Kiểm tra fields | 3 fields theo thứ tự: **alert_enabled** (Switch first), **min_stock** (Input number), **max_stock** (Input number) |
| 3.6 | Kiểm tra giá trị pre-populated | min_stock=10, max_stock=100, alert_enabled=ON |
| 3.7 | Đổi `min_stock` từ 10 → 5 → click **Save** | Spinner trên nút Save; toast "Threshold updated"; sheet đóng |
| 3.8 | Quan sát bảng alerts | Row vừa edit **biến mất** khỏi active filter (đã auto-resolved vì `quantity=8 >= new_min=5`) |
| 3.9 | Reload page → vào tab "Resolved" (nếu có) | Alert hiện ở đó với `resolved_at` set |

### 3.4 Checklist test — Alternate path: auto-resolve khi quantity = 0

| # | Hành động | Kết quả mong đợi |
|---|-----------|------------------|
| 3.10 | Tạo alert mới với `quantity=2, min_stock=10` | Alert active hiện trên trang |
| 3.11 | Mở sheet → set `min_stock=0` → Save | Alert auto-resolve, row biến mất |

### 3.5 Checklist test — Validation

| # | Hành động | Kết quả mong đợi |
|---|-----------|------------------|
| 3.12 | Mở sheet → `min_stock=10`, `max_stock=5` → focus ra hoặc click Save | Inline error dưới min_stock ("min ≤ max"); nút Save **disabled** |
| 3.13 | Sửa lại `min_stock=5, max_stock=10` | Error biến mất; Save enable |
| 3.14 | Nhập `min_stock=-1` | Inline error "phải >= 0"; Save disabled |
| 3.15 | Để trống min_stock (clear field) | Mô tả: "Alert fires when quantity drops below this. Leave empty to disable." — submit được, không error |
| 3.16 | (Edge) Nhập số thập phân `5.5` | Accept (decimal); submit success |
| 3.17 | Form chưa thay đổi gì | Save **disabled** (chỉ enable khi dirty) |

### 3.6 Checklist test — Keyboard / UX

| # | Hành động | Kết quả mong đợi |
|---|-----------|------------------|
| 3.18 | Mở sheet → nhấn **Esc** | Sheet đóng, không có save |
| 3.19 | Trong form, nhấn **Enter** trên input | Submit form (nếu valid) |
| 3.20 | Tab qua các field | Thứ tự: alert_enabled → min_stock → max_stock → Cancel → Save (theo flow tự nhiên) |
| 3.21 | Cancel button | Sheet đóng, không lưu thay đổi |
| 3.22 | Mở sheet → resize browser xuống mobile (375px) | Sheet vẫn đọc/edit được; footer Save/Cancel sticky |

### 3.7 Checklist test — Error handling

| # | Hành động | Kết quả mong đợi |
|---|-----------|------------------|
| 3.23 | Mở DevTools → Network throttle: Offline → Save | Toast "Failed to update threshold"; sheet **giữ nguyên** với value đã nhập (không reset) |
| 3.24 | Trở online → click Save lại | Success bình thường |
| 3.25 | Mở sheet ở 2 tab cùng lúc, save tab A trước → save tab B | Cả 2 đều 200; last-write-wins (chấp nhận được, không cần optimistic lock) |

### 3.8 Checklist test — Authorization

| # | Hành động | Kết quả mong đợi |
|---|-----------|------------------|
| 3.26 | Login `shop-staff` → vào `/shop/{shopSlug}/stock/alerts` | Bảng vẫn hiển thị nhưng **không có "Configure threshold"** trong dropdown (item ẨN, không phải hiện rồi 403) |
| 3.27 | `shop-manager` của shop A → cố ý gọi tay `PUT /api/v1/shops/{shopB}/stock-levels/{id}` qua DevTools | 403 |
| 3.28 | `hq-admin` → mở alerts page của shop bất kỳ | Trang read-only, không có "Configure threshold" |

### 3.9 Side effects (verify bằng cách kiểm DB hoặc backend log)

| # | Hành động | Verify |
|---|-----------|--------|
| 3.29 | Set `min_stock` từ 10 → 5 khi `quantity=8` | DB: `stock_alerts.status='resolved'`, `resolved_at IS NOT NULL` |
| 3.30 | Set `min_stock` từ 5 → 20 khi `quantity=8` (no active alert) | DB: 1 dòng `stock_alerts` mới được tạo (type=`low_stock`, active) |
| 3.31 | Alert auto-resolve do threshold update | **KHÔNG** fire notification (chỉ creation mới fire, resolution silent) |
| 3.32 | Alert được tạo mới do threshold update | Fire notification tới warehouse manager (kiểm `notifications` table hoặc inbox) |

---

## 4. End-to-end — G2 / G3 / G4 / G5 qua Order Close

Mục này test phần logic backend khi đóng đơn — UI không trực tiếp, nhưng cần verify từ admin-web sau khi trigger.

### 4.1 Setup

- Brand X có 2 SKU:
  - **SKU-A** (`inventory_mode=track_stock`) liên kết Recipe = `{rice: 10g, soy_sauce: 5ml per serving, output_quantity=1}`
  - **SKU-B** (`inventory_mode=made_to_order`)
- Warehouse W trong shop S có:
  - `auto_approve_stock_out=true`
  - `allow_negative_sales=false` (sẽ flip để test)
  - StockLevel: rice=100g, soy_sauce=50ml
- 1 CustomerOrder paid với 2× SKU-A + 1× SKU-B, chưa close.

### 4.2 Checklist test

| # | Hành động | Verify trên admin-web |
|---|-----------|----------------------|
| 4.1 | Trigger close order (qua POS/workstation hoặc tinker) | `/shop/{s}/stock/levels` → rice giảm còn 80g, soy_sauce còn 40ml |
| 4.2 | Vào `/shop/{s}/stock/transactions` | Có 2 transactions cho order này: 1× `sales` (SKU-A x2), 1× `sales_material_consumption` (rice + soy_sauce). SKU-B KHÔNG sinh transaction. |
| 4.3 | Detail transaction `sales_material_consumption` | Liên kết về `reference_type=CustomerOrder, reference_id={order.id}`; có 2 items (rice 20g, soy_sauce 10ml) |
| 4.4 | (Allow-negative test) Flip warehouse `allow_negative_sales=true` → đặt thêm đơn consume 200g rice (> 80g còn) → close | Order đóng thành công; `rice` giảm còn `-120g`; tạo `out_of_stock` alert; có notification tới warehouse manager |
| 4.5 | (Strict mode) Flip `allow_negative_sales=false` → close order shortage | Order **không** đóng (vẫn `paid`); transaction rollback; backend log có `InsufficientStockException` |
| 4.6 | (Recipe missing) Tạo SKU-C `track_stock` không có recipe → close order | Order vẫn đóng; backend log có warning "material deduction skipped"; KHÔNG sinh material transaction cho SKU-C |
| 4.7 | (auto_approve=false) Flip `auto_approve_stock_out=false` → close order với SKU-A | `sales_material_consumption` transaction tạo ra ở state `submitted` (chưa completed); StockLevel KHÔNG đổi cho đến khi manager approve; order vẫn `closed` |

### 4.3 Notification verification

| # | Hành động | Verify |
|---|-----------|--------|
| 4.8 | Khi `out_of_stock` alert tạo ra (case 4.4) | Trong inbox của warehouse manager (role=`warehouse_manager` SSO) thấy notification type=`stock.alert.out` |
| 4.9 | Trigger cùng alert 2 lần (cùng alert_id) | Chỉ 1 notification (idempotency key `{type}:{alert->id}`) |
| 4.10 | User KHÔNG phải warehouse manager của warehouse đó | KHÔNG nhận notification |

---

## 5. Cross-cutting / Regression

### 5.1 i18n sanity sweep

Đổi locale qua AppProvider (ja / en / vi), check 3 trang:

- [ ] SKU edit form (G1) — Inventory mode label + description + options đủ 3 ngôn ngữ.
- [ ] Warehouse dialog (G4) — Toggle label + description đủ 3 ngôn ngữ.
- [ ] Threshold sheet (G6) — Header, field labels, validation messages, buttons đủ 3 ngôn ngữ.
- [ ] Không có key thô hiện ra (vd `stock.alert.threshold_sheet.title`).

### 5.2 Theme / Density

- [ ] Light mode + dark mode (nếu có toggle) — text contrast OK, không bị invisible.
- [ ] Tất cả input/button có chiều cao 32px (design system).
- [ ] Card padding 16px.

### 5.3 Console / Network sanity

- [ ] DevTools Console: không có error đỏ.
- [ ] Không có request 404 hoặc CORS error.
- [ ] TanStack Query DevTools (nếu bật): query cache invalidate đúng sau mutation (vd. sau khi update threshold, `stockAlerts` + `stockAlertSummary` được refetch).

### 5.4 Regression — các trang chính KHÔNG đụng vào nhưng cần check

| Trang | Check |
|-------|-------|
| `/shop/{s}/stock/overview` | Hiển thị bình thường, summary đúng |
| `/shop/{s}/stock/levels` | List + detail không break |
| `/shop/{s}/stock/transactions` | Filter `sales_material_consumption` mới hoạt động được (enum thêm value) |
| `/shop/{s}/stock/counts` | Stock count workflow không break |
| `/shop/{s}/stock/disposals` | Không break |

### 5.5 Lint / typecheck (chạy 1 lần trước khi gửi UI test report)

```sh
pnpm typecheck          # Phải green ở admin-web
pnpm lint               # Phải green ở admin-web
```

---

## 6. Báo cáo test

Ghi nhận theo format:

```
## Test report — plan-024 UI

**Người test:** ...
**Date:** ...
**Build:** branch=plan-024-stock, commit=...

### Pass
- [x] 1.1, 1.2, ..., 3.1, ..., 4.1, ...

### Fail
- [ ] 3.13 — validation cross-field min/max chưa fire (BUG-3 đã document)
- [ ] ...

### Notes / Surprises
- ...

### Screenshots
- ...
```

---

## 7. Known issues (đã document trong REVIEW.md)

Các issue dưới đây **đã biết**, không phải bug do test phát hiện:

| Issue | Vị trí | Status |
|-------|--------|--------|
| `MoreHorizontal` thay vì `EllipsisVertical` (convention) | `stock-alert-table.tsx` | W1 — chờ fix |
| `StockLevelThresholdSheetProps` chưa export | `stock-level-threshold-sheet.tsx` | W2 — chờ fix |
| OpenAPI spec thiếu `inventory_mode` ở PUT product-sku | `ProductSkuController.php` | W3 — chờ fix |
| `min_stock > max_stock` không trả 422 (BUG-3 pre-existing) | `StockLevelUpdateRequest` | W4 — pin test, follow-up issue |
| Notification `min_stock=0.0` khi null | Observer | I1 — informational |
| Browser tests scaffold `->skip(...)` | `backend/tests/Browser/` | Cần Playwright runner |

Nếu test phát hiện **issue mới ngoài danh sách trên** → log thành bug.

---

## 8. Tham khảo nhanh

- [README.md](README.md) — scope + success criteria
- [DESIGN.md](DESIGN.md) — chi tiết screens (S1/S2/S3), authorization matrix, user journeys
- [REVIEW.md](REVIEW.md) — code review findings, files changed
- [NOTES.md](NOTES.md) — wrap-up, bugs đã fix
