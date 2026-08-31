# Workstation × POS — Bảng DB, Endpoint, Pull-DOWN

> Tham chiếu nhanh cho mọi thứ workstation cung cấp cho **pos-web** ở chế độ LAN.
> Mọi đường dẫn trỏ tới file source code của workstation (`/Users/alex/Documents/code_godx/tempo/workstation-app/...`).

---

## 1. Toàn bộ bảng SQLite trong workstation

Hai nhóm migration:

| Folder | Phạm vi | Sở hữu |
|---|---|---|
| `internal/store/migrations/001..026` | **Hand-written** — workstation-owned + replica thủ công | Workstation |
| `migrations/omnify/001..011` | **Omnify-generated** — cloud-mirror schema | Omnify codegen |

### 1.1 Workstation-local (workstation là Source-Of-Truth)

| Bảng | Migration | Vai trò |
|---|---|---|
| `settings` | 001 | Device auth + sync cursors (`device_token`, `cloud_api_url`, `sync.customer_orders.last_pulled`...) |
| `printers` | 002, 013 | ESC/POS thermal printers (LAN/USB) + `roles` JSON |
| `audit_log` | 003 | Action audit trail (`actor`, `action`, `entity_type`, `entity_id`, `client_ip`) |
| `menu_items` | 004 | Flat menu cache — kiosk + handy use chung |
| `menu_meta` | 004/018 | Branch-level menu metadata (cart_timeout) |
| `orders` | 005, 016 | **Order header workstation owns** (status, totals, table_id, customer_id, discount, tax, service_charge) |
| `order_items` | 005, 011, 012 | Line items + topping_subtotal + sku_variant_name |
| `order_tables` | 005 | Pivot multi-table merge (PK: order_id+table_id, có sort_order) |
| `order_coupons` | 005 | Coupon đang apply trên order (released_at = NULL ⇒ active) |
| `order_counters` | 005 | Per-day order number sequence |
| `order_item_toppings` | 011 | Topping snapshot trên line item |
| `kitchen_ticket_counters` | 010 | Đếm số lần "fire to kitchen" theo ngày |
| `payments` | 006, 014, 026 | **Payment workstation owns** — full Cloud parity: payment_method_id, tip, tendered, change, reference_no, note, paid_at, expires_at, till_session_id, metadata |
| `payment_refunds` | 006 | Lịch sử partial refund (mirror sau Cloud confirm) |
| `idempotency_keys` | 007 | Replay-safe ledger cho POST mutations |
| `sync_queue` | 007 | Outbound sync UP backlog (entity, action, payload, attempts, last_error) |
| `image_cache`/`pos_image_cache` | 023 | Cache ảnh content-addressable (SHA-256 URL) |
| `tills` | 015 | Cashier till definitions (mỗi terminal vật lý) |
| `till_sessions` | 015 | **Cashier shift workstation owns** — open/closing/settled/abandoned/expired |
| `till_cash_events` | 015 | Paid-in / paid-out / loan / pickup |
| `till_cash_denomination_counts` | 015 | Snapshot tờ tiền tại open + close phase |
| `till_settlement_tender_details` | 015 | Counted-by-tender khi settle |

### 1.2 Cloud-mirror (replica thuần đọc, workstation chỉ INSERT trong SyncPuller tx)

| Bảng | Migration | Pull function | Pull path | Tick |
|---|---|---|---|---|
| `zones` | 008 | `PullZones` | `/api/v1/tms/zones` | 5s |
| `tables` | 008, 025 | `PullTables` | `/api/v1/tms/tables` | 5s |
| `shop_settings` | 008 | `PullBranch` | `/api/v1/workstation/branch` | 5s |
| `branches` | omnify/001 | `PullBranch` | (cùng) | 5s |
| `auth_token_cache` | 008 | (cache local — populated bởi authMW khi `/verify` Cloud) | — | TTL 5 phút |
| `inventory_lots` | 008 | `PullLots` | `/api/v1/workstation/lots` | 5s slow loop |
| `customers` | 009 | `PullCustomers` | `/api/v1/workstation/customers` | 5s slow loop |
| `coupons` | 009, 016, 017 | `PullCoupons` | `/api/v1/workstation/coupons` | 5s slow loop |
| `coupon_redemptions` | 016 | (cùng) | (cùng) | 5s |
| `payment_methods` | 006 | `PullPaymentMethods` | `/api/v1/workstation/payment-methods` | 5s slow loop |
| `menu_promotions` | 016 | `PullPromotions` | `/api/v1/workstation/menu-promotions` | 5s slow loop |
| `menu_promotion_products` | 016 | (cùng) | (cùng) | 5s |
| `menu_promotion_schedules` | 016 | (cùng) | (cùng) | 5s |
| `menu_schedules` | 016, 024 | `PullMenuSchedules` | `/api/v1/workstation/menu-schedules` | 5s slow loop |
| `menus` (eager-load) | 018 | `PullMenuCatalog` | `/api/v1/workstation/menu-catalog` | 5s slow loop |
| `pos_menus` | 018 | (cùng) | (cùng) | 5s |
| `pos_menu_sections` (+ `_new`) | 018, 020 | (cùng) | (cùng) | 5s |
| `pos_menu_products` | 018, 022 | (cùng) | (cùng) | 5s |
| `pos_menu_product_topping_overrides` | 022 | (cùng) | (cùng) | 5s |
| `pos_products` | 021, 022 | (cùng) | (cùng) | 5s |
| `pos_product_skus` | 021, 022 | (cùng) | (cùng) | 5s |
| `pos_product_galleries` | 021 | (cùng) | (cùng) | 5s |
| `pos_product_options` | 022 | (cùng) | (cùng) | 5s |
| `pos_product_option_values` | 022 | (cùng) | (cùng) | 5s |
| `pos_product_topping_groups` | 022 | (cùng) | (cùng) | 5s |
| `pos_product_topping_item_overrides` | 022 | (cùng) | (cùng) | 5s |
| `pos_topping_groups` | 022 | (cùng) | (cùng) | 5s |
| `pos_topping_group_items` | 022 | (cùng) | (cùng) | 5s |
| `pos_topping_group_item_skus` | 022 | (cùng) | (cùng) | 5s |
| `handy_menu_cache` | 004 | `PullHandyMenu` | `/api/v1/workstation/menu/handy` | 5s fast loop |
| `staff` | 019 | `PullStaff` | `/api/v1/workstation/staff` | 5s slow loop |
| `denominations` | 015 | `PullDenominations` | `/api/v1/workstation/till-denominations` | 5s slow loop |
| `till_tender_categories` | 015 | `PullTenderCategories` | `/api/v1/workstation/till-tender-categories` | 5s slow loop |
| `till_tender_types` | 015 | `PullTenderTypes` | `/api/v1/workstation/till-tender-types` | 5s slow loop |
| `customer_orders` (omnify) | omnify/004 | `pullCustomerOrders` | `/api/v1/workstation/orders?since=...` | 5s kitchen loop |
| `customer_order_items` (omnify) | omnify/005 | (cùng) | (cùng) | 5s |
| `brands`, `categories`, `devices`, `menus`, `menu_product_skus`, `organizations`, `products`, `product_skus` | omnify/002,003,006,007,008,009,010,011 | (Omnify schema — chứa replica metadata) | (cùng pull paths) | 5s |

### 1.3 Bảng schema-bridge / legacy

| Bảng | Trạng thái |
|---|---|
| `coupons_new`, `pos_menu_sections_new` | Bridge tables giữ migration cũ (`017`, `020`) — không còn write, an toàn để bỏ qua |

---

## 2. Endpoint phục vụ pos-web

Đăng ký trong `internal/handler/routes.go` (dòng 167–299).
Middleware stack: `lanOnly → corsMiddleware → corsForBrowser → authMW.Wrap → handler`.

### 2.1 Identity & shop context

| Method | Path | Handler | Mô tả |
|---|---|---|---|
| GET | `/api/v1/pos/me` | `handleLocalPosMe` | Cashier identity (SSO user info) |
| GET | `/api/v1/pos/shop` | `handleLocalPosShop` | Shop info (brand, branch, currency) |
| GET | `/api/v1/pos/staff` | `handlePosStaff` | Staff list cho "Người mở ca" dropdown |
| GET | `/api/v1/pos/settings/order` | `handleLocalPosOrderSettings` | `tax_rate`, `service_charge_rate`, `currency_code`, `enable_quick_order`, `default_order_item_status` |

### 2.2 Catalog (menu)

| Method | Path | Handler |
|---|---|---|
| GET | `/api/v1/pos/menus` | `handleLocalPosListMenus` |
| GET | `/api/v1/pos/menus/{menu}` | `handleLocalPosMenuDetailLocal` |
| GET | `/api/v1/pos/menus/{seg1}/{seg2}` | `dispatchMenuTwoSeg` (by-day vs detail) |
| GET | `/api/v1/pos/payment-methods` | `handleLocalPosPaymentMethods` |
| GET | `/api/v1/pos/tables` | `handleLocalPosTables` (current_order_id derived từ `order_tables` pivot) |

### 2.3 Order (CRUD + lifecycle)

| Method | Path | Handler |
|---|---|---|
| GET | `/api/v1/pos/orders` | `handleLocalPosOrders` (paginated, filter status/order_type/customer_id/search/date) |
| GET | `/api/v1/pos/orders/{id}` | `handleLocalPosGetOrder` |
| POST | `/api/v1/pos/orders` | `handleLocalPosCreateOrder` |
| PUT | `/api/v1/pos/orders/{id}/init` | `handleLocalPosInitOrder` (first-write-wins: table_ids + guest_count) |
| PUT | `/api/v1/pos/orders/{id}` | `handleLocalPosUpdateOrder` (guest_count, note, customer_id, order_type) |
| DELETE | `/api/v1/pos/orders/{id}` | `handleLocalPosDeleteOrder` (soft-void, void_reason='deleted_by_pos') |
| POST | `/api/v1/pos/orders/{id}/void` | `handleLocalPosVoidOrder` |
| POST | `/api/v1/pos/orders/{id}/checkout` | `handleLocalPosCheckout` (open → checkout) |

### 2.4 Line items

| Method | Path | Handler |
|---|---|---|
| POST | `/api/v1/pos/orders/{id}/items` | `handleLocalPosAddItems` (BR-OI06 merge by tuple key) |
| PATCH | `/api/v1/pos/orders/{id}/items/{item}` | `handleLocalPosUpdateItem` (quantity/note/status) |
| DELETE | `/api/v1/pos/orders/{id}/items/{item}` | `handleLocalPosDeleteItem` (delegate VoidItem("Removed by staff")) |
| POST | `/api/v1/pos/orders/{id}/items/{item}/void` | `handleLocalPosVoidItem` (BR-OI05) |

### 2.5 Table management

| Method | Path | Handler |
|---|---|---|
| POST | `/api/v1/pos/orders/{id}/merge-table` | `handleLocalPosMergeTable` |
| POST | `/api/v1/pos/orders/{id}/unmerge-table` | `handleLocalPosUnmergeTable` (gate last-on-dine-in) |

### 2.6 Coupons

| Method | Path | Handler |
|---|---|---|
| POST | `/api/v1/pos/orders/{id}/apply-coupon` | `handleLocalPosApplyCoupon` |
| DELETE | `/api/v1/pos/orders/{id}/coupon` | `handleLocalPosReleaseCoupon` |

### 2.7 Customer

| Method | Path | Handler |
|---|---|---|
| POST | `/api/v1/pos/customers/find-or-create` | `handleLocalPosFindOrCreateCustomer` |
| GET | `/api/v1/pos/customers/{customer}/outstanding` | `handleLocalPosCustomerOutstanding` |

### 2.8 Split bill

| Method | Path | Handler |
|---|---|---|
| GET | `/api/v1/pos/orders/{id}/split-bill` | `handleLocalPosSplitBill` (preview equal mode) |
| POST | `/api/v1/pos/orders/{id}/split-bill` | `handleLocalPosSplitBill` (by_items / by_amount body) |

### 2.9 Payment

| Method | Path | Handler |
|---|---|---|
| GET | `/api/v1/pos/orders/{id}/payments` | `handleLocalPosListOrderPayments` |
| POST | `/api/v1/pos/orders/{id}/payments` | `handleLocalPosCreatePayment` — auto-confirm, requires_tendered, change_amount, overpayment guard, drift guard, order lifecycle checkout→paying→closed |
| POST | `/api/v1/pos/orders/{id}/payments/{paymentId}/refund` | `handleLocalPosRefundPayment` |

### 2.10 Revenue / reporting

| Method | Path | Handler |
|---|---|---|
| GET | `/api/v1/pos/revenue/summary` | `handleLocalPosRevenueSummary` |
| GET | `/api/v1/pos/revenue/by-product` | `handleLocalPosRevenueByProduct` |

### 2.11 Cashier shift (plan-030 / 031 / 032)

| Method | Path | Handler |
|---|---|---|
| GET | `/api/v1/pos/till/current` | `handleLocalPosTillCurrent` |
| GET | `/api/v1/pos/till/denominations` | `handleLocalPosTillDenominations` |
| GET | `/api/v1/pos/till/tender-types` | `handleLocalPosTillTenderTypes` |
| GET | `/api/v1/pos/till/tender-categories` | `handleLocalPosTillTenderCategories` |
| POST | `/api/v1/pos/till/sessions` | `handleLocalPosTillOpenSession` |
| GET | `/api/v1/pos/till/sessions/{id}` | `handleLocalPosTillSessionShow` |
| GET | `/api/v1/pos/till/sessions/{id}/reconciliation` | `handleLocalPosTillReconciliation` |
| POST | `/api/v1/pos/till/sessions/{id}/cash-events` | `handleLocalPosTillCashEvent` |
| POST | `/api/v1/pos/till/sessions/{id}/draft` | `handleLocalPosTillDraft` |
| POST | `/api/v1/pos/till/sessions/{id}/close` | `handleLocalPosTillClose` |
| POST | `/api/v1/pos/till/sessions/{id}/abandon` | `handleLocalPosTillAbandon` |
| GET | `/api/v1/pos/till/sessions/stale` | (catch-all proxy → Cloud) |

### 2.12 Catch-all → Cloud proxy

`internal/handler/routes.go:299` — `mux.Handle("/api/v1/pos/", corsForBrowser(s.authMW.Wrap(s.posCloudProxy())))`.
Mọi path `/api/v1/pos/*` không khớp các route ở trên (vd. `/api/v1/pos/till/sessions/{id}/force-abandon`, `/api/v1/pos/till/sessions/{id}/manual-settle`, mọi endpoint mới chưa mirror) **chuyển thẳng request body + Bearer + X-Shop-Slug** cho Cloud. Không transform JSON.

### 2.13 Endpoint phụ trợ (không qua `/api/v1/pos/`)

| Path | Handler | Vai trò cho POS |
|---|---|---|
| `/api/lan/images/{hash}` | (image cache server) | URL được rewrite vào response của mọi handler POS (xem `shapeOrderForResponse`) |
| `/ws` | `handleWebSocket` | Realtime broadcast: `order_updated`, `order_paid`, `order_voided`, `order_item.status_changed`, `order_created` |
| `/api/status`, `/api/lan`, `/api/dashboard/stats` | Wails-only desktop UI | Không phục vụ pos-web |

---

## 3. Sync DOWN — bảng tổng hợp toàn bộ pull-DOWN cho POS

Khởi động trong `cmd/workstation/main.go` qua `SyncPuller.Start()`. Ba goroutine song song:

| Loop | Interval | Start offset | Pull function trong loop |
|---|---|---|---|
| **fast** | 5s | 0ms | `PullZones`, `PullTables`, `PullMenu`, `PullHandyMenu` |
| **settings** | 5s | 250ms | `PullBranch` (branch + shop_settings) |
| **slow** | 5s | 500ms | `PullLots` + `pullSlowPos()` |
| **kitchen** | 5s | (1 interval) | `pullCustomerOrders` (orders ?since=...) |

`pullSlowPos()` (`internal/service/sync_pull_pos.go:36`) chain 12 hàm liên tiếp:

```
PullPaymentMethods → PullCustomers → PullMenuSchedules → PullTill →
PullTillSessions → PullDenominations → PullTenderCategories →
PullTenderTypes → PullCoupons → PullPromotions → PullMenuCatalog → PullStaff
```

### 3.1 Pull function ↔ Cloud endpoint ↔ Bảng đích

| Pull function | Cloud endpoint | Bảng workstation cập nhật | Tần suất |
|---|---|---|---|
| `PullZones` | `GET /api/v1/tms/zones` | `zones` (delete + re-insert) | 5s fast |
| `PullTables` | `GET /api/v1/tms/tables` | `tables` (delete + re-insert) | 5s fast |
| `PullMenu` | `GET /api/v1/workstation/menu` | `menu_items` (kiosk-flat, sku_id=NULL) | 5s fast |
| `PullHandyMenu` | `GET /api/v1/workstation/menu/handy` | `menu_items` (handy SKU rows) + `menu_meta` | 5s fast |
| `PullBranch` | `GET /api/v1/workstation/branch` | `branches`, `shop_settings` (flatten `data.settings.*` → KV; viết cả `currency`, `tax_rate`, `service_charge_rate`, `currency_code`, `enable_quick_order`, `default_order_item_status`, `cart_timeout_minutes`...) | 5s settings |
| `PullLots` | `GET /api/v1/workstation/lots` | `inventory_lots` | 5s slow |
| `PullPaymentMethods` | `GET /api/v1/workstation/payment-methods` | `payment_methods` (id, code, name, is_active, sort_order, **requires_tendered, is_auto_confirm**) | 5s slow |
| `PullCustomers` | `GET /api/v1/workstation/customers` | `customers` (chỉ rows Cloud chưa biết — không chạm `local_pending_sync=1`) | 5s slow |
| `PullMenuSchedules` | `GET /api/v1/workstation/menu-schedules` | `menu_schedules` (day_of_week, start_time, end_time, priority) | 5s slow |
| `PullTill` | `GET /api/v1/workstation/till` | `tills` | 5s slow |
| `PullTillSessions` | `GET /api/v1/workstation/till-sessions/active` | `till_sessions` (sync state machine) | 5s slow |
| `PullDenominations` | `GET /api/v1/workstation/till-denominations` | `denominations` | 5s slow |
| `PullTenderCategories` | `GET /api/v1/workstation/till-tender-categories` | `till_tender_categories` | 5s slow |
| `PullTenderTypes` | `GET /api/v1/workstation/till-tender-types` | `till_tender_types` | 5s slow |
| `PullCoupons` | `GET /api/v1/workstation/coupons` | `coupons` + `coupon_redemptions` | 5s slow |
| `PullPromotions` | `GET /api/v1/workstation/menu-promotions` | `menu_promotions`, `menu_promotion_products`, `menu_promotion_schedules` | 5s slow |
| `PullMenuCatalog` | `GET /api/v1/workstation/menu-catalog` | 14 bảng: `menus`, `pos_menus`, `pos_menu_sections`, `pos_menu_products`, `pos_menu_product_topping_overrides`, `pos_products`, `pos_product_skus`, `pos_product_galleries`, `pos_product_options`, `pos_product_option_values`, `pos_product_topping_groups`, `pos_product_topping_item_overrides`, `pos_topping_groups`, `pos_topping_group_items`, `pos_topping_group_item_skus` (delete + re-insert atomic) | 5s slow |
| `PullStaff` | `GET /api/v1/workstation/staff` | `staff` (delete + re-insert) | 5s slow |
| `pullCustomerOrders` | `GET /api/v1/workstation/orders?since=<cursor>&limit=...` | `orders` + `order_items` (incremental upsert qua `sync.customer_orders.last_pulled` cursor) | 5s kitchen |

### 3.2 Pull cũng phục vụ cross-namespace cho POS

POS-web LAN read `/api/v1/pos/tables` nhưng dữ liệu nguồn lại đến từ **TMS namespace** Cloud (`/api/v1/tms/zones` + `/api/v1/tms/tables`). Workstation mirror local rồi serve cho POS không round-trip Cloud.

### 3.3 Image cache pipeline (async, không tick định kỳ)

Khi `shapeOrderForResponse` / menu handler trả response, tree-walker `rewriteResponseImages` quét mọi `image_url` Cloud URL → hash SHA-256 → trả URL local `/api/lan/images/{hash}`. Nếu hash chưa có trong `pos_image_cache`, background worker tải về và stamp `pos_image_cache(hash, mime, bytes, downloaded_at)`.

---

## 4. Tham chiếu nhanh — luồng dữ liệu chính

```
Cloud REST                      Workstation                       Pos-web LAN
──────────                      ───────────                       ───────────
GET /tms/zones        ──5s──▶   zones                    ◀──read─ GET /pos/tables
GET /tms/tables       ──5s──▶   tables
GET /workstation/branch ─5s─▶   shop_settings ───────────read───▶ GET /pos/settings/order
                                       └ tax_rate, service_charge_rate, currency_code, enable_quick_order, default_order_item_status
GET /workstation/menu-catalog─▶ pos_* (14 bảng) ─────────read───▶ GET /pos/menus, /pos/menus/{id}
GET /workstation/payment-methods─▶ payment_methods ──────read───▶ GET /pos/payment-methods + handleLocalPosCreatePayment (lookup is_auto_confirm / requires_tendered)
GET /workstation/orders ──5s──▶ orders, order_items ────read───▶ GET /pos/orders, /pos/orders/{id}
GET /workstation/till-* ──5s──▶ tills, denominations, ──read───▶ GET /pos/till/*
                                till_tender_*

Pos-web mutation                Workstation                       Cloud
────────────────                ───────────                       ─────
POST /pos/orders              ──▶ orders (Create)        ──queue──▶ /workstation/orders
POST /pos/orders/{id}/items   ──▶ order_items (AddItems) ──queue──▶ (cùng order POST)
PATCH /pos/orders/{id}/items/{item} ─▶ order_items.status ──queue──▶ (item status sync)
POST /pos/orders/{id}/merge-table ─▶ order_tables pivot  ──queue──▶ (table_ids update)
POST /pos/orders/{id}/payments ──▶ payments + orders.status ─queue▶ /workstation/payments
```

---

## 5. Gap đã biết / không có sẵn

| Tính năng | Trạng thái workstation |
|---|---|
| `POST /pos/payments/{id}/confirm` / `/fail` (terminal flow) | Có cho kiosk path. Pos terminal flow fallback qua catch-all proxy → Cloud |
| `POST /pos/till/sessions/{id}/force-abandon` / `manual-settle` (plan-032) | Catch-all proxy → Cloud verbatim (Cloud sở hữu state machine) |
| Stripe webhook hooks | Catch-all → Cloud |
| Inventory write (stock movement, recipe) | Catch-all → Cloud |
| HQ catalog write (product CRUD) | Không phục vụ POS — chỉ admin-web/HQ |

---

## 6. Source pointers

| Topic | File |
|---|---|
| Route registry | `internal/handler/routes.go` |
| POS handlers (orders/items/payment) | `internal/handler/local_pos.go`, `local_pos_phase1.go`, `local_pos_phase2.go`, `local_pos_phase3.go`, `local_pos_phase5.go` |
| POS till handlers | `internal/handler/local_pos_till.go` |
| POS revenue handlers | `internal/handler/local_pos_revenue.go`, `local_pos_revenue_by_product.go` |
| POS menu handlers | `internal/handler/local_pos_menus.go` |
| Customer-order response shape | `internal/handler/customer_order_shape.go` |
| Sync DOWN base loops | `internal/service/sync_pull.go` |
| Sync DOWN POS replicas | `internal/service/sync_pull_pos.go` |
| Order engine (totals, lifecycle, BR-OI) | `internal/service/order_service.go`, `order_service_pos.go`, `order_service_tables.go` |
| Cloud proxy (catch-all) | `internal/handler/cloud_proxy.go` |
| Hand-written migrations | `internal/store/migrations/*.sql` |
| Omnify migrations | `migrations/omnify/*.sql` |
| Legacy-schema repair (idempotent ALTER) | `internal/store/repair.go` |
