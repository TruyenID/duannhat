# Plan 043 — Kịch bản test toàn bộ (Tax Types / Thuế tiêu thụ Nhật)

> ## ⚠️ SUPERSEDED IN PART — read this before trusting anything below
>
> The **two-rate tax type** (`rate_dine_in` / `rate_takeaway` chosen by
> `order_type`) was **removed on 2026-07-26 (#1099)**. A tax type is ONE rate;
> the MENU decides the consumption context. Every mention of it below is a
> record of what was built, **not an instruction**.
>
> Still true and still shipped: immutable per-line snapshots, rounding ONCE per
> rate group (インボイス), 総額表示 mode, service-charge rate, per-rate output,
> the workstation Go engine.
>
> Current truth: [`docs/guide/tax-types.md`](../../docs/guide/tax-types.md).

> Playbook test end-to-end cho toàn bộ plan-043. Dùng để QA thủ công + đối chiếu với 110 scenario tự động trong `TESTS.md`.
> Mọi con số ¥ trong file là **kỳ vọng cứng** — sai một yên là fail. Nguồn số liệu: `SYSTEM-FLOW.md §6.1` + [DESIGN.md §8](DESIGN.md).

**Quy ước:** mỗi case có mã `TC-<nhóm><số>`, ô `[ ]` để tick khi pass. Nhóm I (hóa đơn/報告) là **nhạy cảm pháp lý** — test kỹ nhất.

---

## PHẦN 0 — Chuẩn bị môi trường & dữ liệu mẫu

### 0.1 Dựng stack + seed (canonical = Docker)

```sh
docker compose up -d                                             # backend :5400, mysql :3307, redis, mailpit, minio
docker compose exec app php artisan migrate:fresh --seed --force # seed TRONG container (native artisan trỏ Herd — sai DB)
```

Kiểm tra seed tax type đã chạy:

```sh
docker compose exec app php artisan tinker --execute 'echo App\Models\TaxType::count()." tax types; ".App\Models\Branch::whereNull("default_tax_type_id")->count()." branch thiếu default";'
```
✅ Kỳ vọng: **3 tax type / brand**, **0 branch thiếu default** (mọi branch phải có `default_tax_type_id` — nếu còn NULL là seed hỏng, không được test tiếp).

Chạy web clients:
```sh
pnpm dev:admin      # admin-web  http://localhost:5430   (HQ + Shop settings)
pnpm dev:pos        # pos-web    http://localhost:5440   (cart, split, receipt)
pnpm dev:customer   # customer-web http://localhost:5450 (checkout 総額表示)
```
Workstation (offline parity): `cd workstation-app && make dev` → http://localhost:8080.
Mailpit (xem email hóa đơn): http://localhost:8025.

### 0.2 Fixture chuẩn (dùng xuyên suốt PHẦN 2)

| Thực thể | Cấu hình | Ghi chú |
|----------|----------|---------|
| Brand | 3 tax type | seed sẵn |
| 標準 STANDARD | `rate_dine_in = 10`, `rate_takeaway = 10`, **is_default = true** | 2 cột đều 10 → luôn 10% |
| 軽減 REDUCED | `rate_dine_in = 10`, `rate_takeaway = 8` | thực phẩm mang về = 8% |
| 非課税 EXEMPT | `rate_dine_in = 0`, `rate_takeaway = 0` | miễn thuế |
| SP **bentō** ¥1.000 | tax type = 軽減 (RED) | thực phẩm |
| SP **bia lon** ¥500 | tax type = 標準 (STD) | thuế chuẩn 10% |
| SP **cola** | tax type = 軽減 (RED) | nước ngọt |
| Branch | currency **JPY**, `prices_include_tax = false` (excluded) | JPY làm tròn bước 1 yên |

> Có sẵn state factory: `TaxType::factory()->standard()/reduced()/exempt()`, `->asDefault()`.

### 0.3 Tài khoản test

- **HQ SSO user** (brand admin) — CRUD tax type, gán thuế cho product/menu. Dev có SSO-bypass login cho tài khoản seed (staging/dev only).
- **Shop SSO user** — sửa Shop Settings (default type, sc_tax, include-mode).
- **HQ user org KHÁC** — để test 403 cross-org.
- **Device token** (workstation / pos) — để test 401/403 khi chạm `/hq/*`.

---

## PHẦN 1 — Test tự động (chạy TRƯỚC khi test tay)

Test tự động đã phủ 110 scenario. Chạy hết để có nền xanh, rồi mới soi tay các luồng UI.

### 1.1 Backend (Pest — chạy native, KHÔNG docker)

```sh
cd backend
[ -f .env ] || (cp .env.example .env && php artisan key:generate)
php -d memory_limit=-1 vendor/bin/pest --compact           # toàn bộ suite
```
Chạy riêng cụm plan-043 khi soi lỗi:

| Lệnh filter | File | Phủ scenario |
|-------------|------|--------------|
| `--filter=TaxType` | `tests/Feature/HQ/TaxTypeTest.php` | CRUD, is_default, 409 in-use, authz, validation |
| `--filter=TaxResolver` | `tests/Feature/Customer/TaxResolverTest.php` | chuỗi 4 tầng §7 + rate pick |
| `--filter=OrderPricingCalculator` | `tests/Unit/OrderPricingCalculatorTest.php` | §8: proof case, round-once, coupon pro-rata, sc-tax, included/excluded |
| `--filter=SplitByItems` | `tests/Unit/Services/SplitByItemsCalculatorTest.php` | split per-rate + rounding mode |
| `--filter=MenuProductTaxOverride` | `tests/Feature/HQ/MenuProductTaxOverrideTest.php` | override menu-item |
| `--filter=TaxAudit` | `tests/Feature/HQ/TaxAuditTest.php` | audit_logs (CRUD, settings, gán thuế) |
| `--filter=TaxSeeder` | `tests/Feature/TaxSeederTest.php` | seed 標準/軽減/非課税 idempotent |
| `--filter=BackfillOrderTaxSnapshots` | `tests/Feature/BackfillOrderTaxSnapshotsTest.php` | backfill + `--dry-run` |
| `--filter=InvoiceTaxBreakdown` | `tests/Feature/Pos/InvoiceTaxBreakdownTest.php` | hóa đơn per-rate (bug #1 chết) |
| `--filter=OrderPaidInvoiceMail` | `tests/Feature/Mail/OrderPaidInvoiceMailTest.php` | email render `8%対象`+`10%対象` |
| `--filter=OrderBroadcastTax` | `tests/Feature/Notification/OrderBroadcastTaxTest.php` | broadcast giữ contract |
| `--filter=ShopOrderSettings` | `tests/Feature/Shop/ShopOrderSettingsTest.php` | 4 field mới + 409 open-shift |
| `--filter=ProductTaxCsv` | `tests/Feature/Product/ProductTaxCsvTest.php` | CSV `tax_type_code` round-trip |
| `--filter=Workstation` | `tests/Feature/Workstation/*` | menu/branch/orders payload + AddItems resolve tax tại Cloud |
| `--filter=CustomerBranch` `--filter=CustomerMenu` | `tests/Feature/Customer/*` | payload thuế cho customer-web |

✅ Kỳ vọng: **4001 pass, 3 skipped**. Có **2 fail có sẵn không liên quan** (`KioskPaymentThrottleTest`, `ShopDeviceCrudTest`) — đã xác nhận trên baseline `dev`, KHÔNG phải do plan-043.

### 1.2 Workstation (Go — parity offline, 15 scenario `[Go]`)

```sh
cd workstation-app && go test ./...
```
Phủ: sync DOWN tax_types, `computeOrderTotals`/`NormalizedTotals` khớp PHP đến từng yên (shared fixture), coupon per-group (bug #5), kiosk currency (bug #6), print reads synced settings (bug #2), `repairLegacySchema`, local split mirror. ✅ Kỳ vọng: **green**.

### 1.3 Frontend (vitest — 4 scenario `[TS]`)

```sh
cd pos-web && pnpm test          # split-by-items 52 case port + mixed-rate, totals per-rate, order-payment
# customer-web: nếu có harness → pnpm test; nếu không → phủ bằng Browser PHẦN 2 nhóm L
```
✅ Kỳ vọng: green; `lib/totals.ts` TAX_RATE const đã bị xóa (bug #7).

### 1.4 Tiêu chí PHẦN 1 pass
- [ ] Pest green (trừ 2 fail baseline đã biết)
- [ ] `go test ./...` green
- [ ] pos-web `pnpm test` green
- [ ] `pnpm typecheck` toàn repo 0 lỗi mới

---

## PHẦN 2 — Kịch bản test thủ công (End-to-End)

### Nhóm A — Tax Type CRUD (admin-web HQ · `/hq/{brand}/tax-types`)

- [ ] **TC-A1 — Danh sách:** vào trang → thấy 3 loại seed (標準 10/10 default, 軽減 10/8, 非課税 0/0), badge "店内 10% / 持ち帰り 8%", không có lỗi console JS.
- [ ] **TC-A2 — Tạo mới:** dialog → nhập name theo tab locale (ja/en/vi), 2 ô rate, Save → row mới hiện với badge rate. API: `POST /hq/{brand}/tax-types` → **201**, name lưu cả 3 ngôn ngữ.
- [ ] **TC-A3 — is_default duy nhất:** tạo/sửa 1 loại thành `is_default = true` → loại default cũ **tự bị bỏ** (mỗi brand đúng 1 default). Kiểm tra DB: chỉ 1 row `is_default = 1`.
- [ ] **TC-A4 — Sửa rate:** đổi rate 軽減 takeaway 8→9 → **200**, ghi `audit_logs`. **Quan trọng:** order lịch sử KHÔNG đổi (snapshot bất biến — xem TC-D3).
- [ ] **TC-A5 — Trùng code:** tạo code trùng trong cùng brand → **422** (inline lỗi dưới ô code). Cùng code ở brand khác → **201**.
- [ ] **TC-A6 — Validate rate:** rate < 0, > 100, hoặc > 2 số lẻ → **422**.
- [ ] **TC-A7 — Xóa loại chưa dùng:** delete → **204**, biến khỏi list.
- [ ] **TC-A8 — Xóa loại đang dùng:** gán 軽減 cho 1 product rồi delete → **409 `TAX_TYPE_IN_USE`** kèm số lượng đang dùng (products/menu/branch-default), UI hiện alert + nút "Deactivate instead".
- [ ] **TC-A9 — Toggle status:** tắt active 1 loại → biến khỏi `lookup` (dropdown) NHƯNG product/menu đang tham chiếu vẫn resolve được (deactivate ≠ delete). Restore đưa loại soft-deleted quay lại.
- [ ] **TC-A10 — lookup:** `GET /hq/{brand}/tax-types/lookup` chỉ trả loại **active** của brand `[{id,code,name,rate_dine_in,rate_takeaway,is_default}]`.

### Nhóm B — Gán thuế & chuỗi resolver 4 tầng (§7)

- [ ] **TC-B1 — Gán ở Product:** Products list → sidebar card 分類 → dropdown (option đầu = "デフォルトを使用/Dùng mặc định" = null) → chọn 軽減 → Save. Cột tax-type ở list phản ánh. API `PATCH product` lưu `tax_type_id`.
- [ ] **TC-B2 — Override ở MenuProduct:** menu editor → item → dropdown inherit→override → chọn loại khác product → Save → chip phản ánh override. API `PATCH /hq/{brand}/menus/{menu}/products/{menuProduct}/tax-type`.
- [ ] **TC-B3 — Ưu tiên chuỗi:** món có override MenuProduct=RED, Product=STD, branch default=EXE → order resolve ra **RED** (tầng 1 thắng). Bỏ override → resolve **STD** (Product). Bỏ luôn Product → **branch default EXE**. Bỏ branch default → **brand is_default (標準)**.
- [ ] **TC-B4 — Validate cross-brand:** gán `tax_type_id` của brand khác cho product → **422/403**.
- [ ] **TC-B5 — Validate inactive:** gán loại đang inactive → **422** (chỉ active mới gán được; tham chiếu cũ không bị ảnh hưởng).
- [ ] **TC-B6 — Combo (一体資産):** combo = 1 Product → gán tax type thủ công. UI hiện hint 一体資産 khi thành phần khác loại thuế (chỉ cảnh báo, Q9).

### Nhóm D — Pricing engine §8 (proof case — số cứng)

> Fixture: bentō ¥1.000 (RED), bia ¥500 (STD), mode **excluded**, JPY.

- [ ] **TC-D1 — Proof case §1.2 (takeaway):** order takeaway = 1 bentō + 1 bia.
  ```
  Nhóm 8%  : 1.000 → tax = round(1.000 × 0,08) = 80
  Nhóm 10% :   500 → tax = round(  500 × 0,10) = 50
  order.tax_amount = 130 · total = 1.500 + 130 = 1.630
  ```
  Line bentō stamp 8%, line bia stamp 10%. Cart/summary hiện **2 nhóm** `8%対象` + `10%対象`.
- [ ] **TC-D2 — Cùng order chuyển dine-in:** đổi order_type → **re-resolve toàn bộ** → cả 2 dòng 10% → 1 nhóm ¥1.500, tax = **150**, total = **1.650**.
- [ ] **TC-D3 — Snapshot bất biến:** sau khi tạo order ở TC-D1, vào admin nâng rate 軽減 takeaway 8→9 → order cũ **vẫn tax 130** (không rewrite lịch sử). Order MỚI mới ăn rate 9%.
- [ ] **TC-D4 — Làm tròn 1 lần/nhóm:** order 3 dòng ¥333 @8% → nhóm = round(999 × 0,08 = 79,92) = **80**. Đảm bảo KHÔNG ra 81 (nếu round từng dòng: 27×3=81 = SAI).
- [ ] **TC-D5 — Coupon pro-rata + service charge:** nhóm 8% = ¥3.000, 10% = ¥2.000, coupon ¥500, `service_charge_rate = 10`, `service_charge_tax_rate = 10`:
  ```
  discount:  8%→300 · 10%→200
  tax:       (3.000−300)×8% = 216 · (2.000−200)×10% = 180
  sc:        round(4.500 × 10%) = 450 · sc_tax = round(450 × 10%) = 45
  total:     4.500 + 216 + 180 + 450 + 45 = 5.391
  ```
  Breakdown: **sc_tax (45) gộp vào nhóm 10%対象**, không thành dòng mồ côi.
- [ ] **TC-D6 — Topping theo rate cha:** bentō (RED) + topping tính phí → topping subtotal chịu **8%** (rate snapshot của dòng cha).
- [ ] **TC-D7 — Currency step:** branch USD làm tròn thuế **0,01**; JPY làm tròn **1** (một luật half-up duy nhất).
- [ ] **TC-D8 — All-exempt:** order toàn món 0% → nhóm thuế = 0, không lỗi chia-cho-0 ở mode included.

### Nhóm E — Included / Excluded mode (総額表示)

- [ ] **TC-E1 — Excluded (mặc định):** `total = Σ(subtotal − discount) + Σ tax_nhóm + sc + sc_tax`. Thuế cộng thêm lên giá.
- [ ] **TC-E2 — Included (bật `prices_include_tax`):** bentō gross ¥1.080 @8% → tax nội = 1.080 − round(1.080/1,08) = **80**; bia gross ¥550 @10% → 550 − round(550/1,1) = **50**; total = **1.630** (= Σ gross), thuế 130 chỉ hiển thị "内消費税". Order stamp `is_tax_included = true` lúc tạo.
- [ ] **TC-E3 — Snapshot mode bất biến:** đóng hết ca → bật `prices_include_tax` → order TẠO TRƯỚC khi bật vẫn giữ mode/total cũ (theo `is_tax_included` per-order); chỉ order MỚI ăn mode mới.

### Nhóm F — Mutation order (per-line, không recompute 1 rate)

- [ ] **TC-F1 — voidItem:** order proof-case → void con bia → **nhóm 10% biến mất**, nhóm 8% nguyên, total tính lại từ snapshot còn lại (KHÔNG recompute kiểu 1 rate — gap #4).
- [ ] **TC-F2 — updateItem qty:** bentō qty 1→3 → giữ loại/rate snapshot, tính lại `tax_amount` dòng theo qty mới.
- [ ] **TC-F3 — Coupon release:** áp coupon rồi gỡ → chạy lại phân bổ, khôi phục thuế nhóm về trạng thái trước coupon.
- [ ] **TC-F4 — Lazy re-stamp:** order tạo TRƯỚC deploy (chưa có snapshot dòng) bị addItems/updateItem/coupon sau deploy → **toàn bộ dòng được stamp** ở lần `recalculateTotals()` kế, total ra đúng (đường fallback không cần command).

### Nhóm G — Split bill per-rate

- [ ] **TC-G1 — Split by items (mixed rate):** mỗi hóa đơn con tính đúng nhóm rate của nó; **Σ tổng hóa đơn con = tổng order**; `validateByItemsAllocations` + preview khớp nhau.
- [ ] **TC-G2 — Rounding mode:** đổi `split_bill_rounding_mode` (auto / integer / two_decimals / none) kết hợp nhóm per-rate → drift xử lý đúng theo luật đã ghi.
- [ ] **TC-G3 — Equal split (customer-web/kiosk):** chia đều order mixed-rate → số/người khớp calculator backend (4 mirror: PHP, Go `local_pos_phase3`, pos-web TS, customer-web).

### Nhóm H — Settings guards (Shop settings)

- [ ] **TC-H1 — 4 field mới round-trip:** chọn default tax type, đặt `service_charge_tax_rate`, toggle `close_report_tax_breakdown` → lưu → reload giữ nguyên.
- [ ] **TC-H2 — Chặn đổi include-mode khi ca mở:** có 1 till session `open` → toggle `prices_include_tax` → **409 `TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT`**, switch UI revert, DB không đổi.
- [ ] **TC-H3 — Cho phép khi ca đóng:** mọi session settled/abandoned/expired → toggle → **200**.
- [ ] **TC-H4 — Đổi rate khi ca mở = cho phép:** sửa rate 1 tax type khi có ca mở → **200** (Q6 — dữ liệu đã được snapshot bảo vệ).
- [ ] **TC-H5 — Stamp till open:** mở ca → `prices_include_tax_at_open` được stamp (pattern currency snapshot).
- [ ] **TC-H6 — Validate cross-brand default:** PATCH `default_tax_type_id` của brand khác → **422**; `service_charge_tax_rate` = −1 / 101 → **422**.

### Nhóm I — Bề mặt xuất (hóa đơn / báo cáo / email) — ⚠️ NHẠY CẢM

- [ ] **TC-I1 — Hóa đơn VAT (bug #1 chết):** tạo invoice cho order proof-case → lưu `tax_breakdown` JSON per-rate + thuế per-line trong `items_json`; cột `seller_registration_number` tồn tại (nullable). Rate KHÔNG còn = 0.
- [ ] **TC-I2 — Print hóa đơn (workstation):** in receipt order proof-case → **2 block** `8%対象 … (内消費税 …)` / `10%対象 …`, dấu **※ trên dòng 8%**, slot **T13** (số đăng ký), nhãn included/excluded đúng. Sub-bill vẫn ẩn breakdown (Q13).
  **Sửa 2026-08-17 (#2064):** trên **sub-bill**, `登録番号` **PHẢI có** — nó là trường ① (danh tính người bán), không đi chung cờ với breakdown. Vẫn ẩn trên sub-bill: **khối theo mức (④⑤)** và **dấu ※ + chú thích (③)**. Thấy sub-bill KHÔNG có `登録番号: T…` ⇒ **lỗi**, không phải hành vi mong đợi.
- [ ] **TC-I3 — Email hóa đơn:** trigger `OrderPaidInvoiceMail` → xem ở Mailpit (:8025) → HTML chứa **cả `8%対象` và `10%対象`**.
- [ ] **TC-I4 — Z-report PDF:** session có order mixed-rate → PDF hiện các dòng 課税売上/消費税 **theo từng rate**; `close_report_tax_breakdown` chỉ gate print nhiệt (PDF luôn có breakdown — Decision 8).
- [ ] **TC-I5 — Shift report nhiệt:** in báo cáo ca → section per-rate sau cờ `close_report_tax_breakdown`; nhãn VI fold ASCII cho Shift_JIS.
- [ ] **TC-I6 — Dashboard/Revenue:** `DashboardController` + `PosRevenueService` phơi 税抜/税込 + thuế per-rate, khớp nhau cùng kỳ. By-product report giữ net basis nhất quán.
- [ ] **TC-I7 — Refund:** ghi payment âm → snapshot thuế per-line **không đổi**, breakdown vẫn nhất quán (Q4 — không có slip điều chỉnh thuế riêng).

### Nhóm J — Workstation offline parity

- [ ] **TC-J1 — Sync DOWN:** admin đổi tax type → workstation nhận trong ~5s (pull DOWN upsert `tax_types`); tắt active 1 loại → flip local.
- [ ] **TC-J2 — Offline = Cloud đến từng yên:** ngắt mạng workstation → ring order proof-case offline → totals **giống hệt Cloud** (bentō 8% + bia 10% = 130 / 1.630). Bỏ fallback cứng 10%.
- [ ] **TC-J3 — Old-cloud tolerance:** menu payload thiếu `tax_type_id` (cloud cũ) → zero-value → fallback default type local, **không panic**.
- [ ] **TC-J4 — Round-trip sync UP:** order shell (không gửi field tiền) sync UP → Cloud tính lại → trả snapshot per-line khớp.
- [ ] **TC-J5 — Kiosk LAN:** response kiosk có field thuế per-line; currency lấy từ settings đã sync (KHÔNG hardcode "JPY" — bug #6).
- [ ] **TC-J6 — LAN menu API:** workstation re-expose `tax_types` + per-item `tax_type_id` cho pos-web/kiosk trên LAN; client cũ chịu được build workstation thiếu field.

### Nhóm K — Migration / Backfill / Seed

- [ ] **TC-K1 — Backfill tax types (dry-run trước):**
  ```sh
  docker compose exec app php artisan tax-types:backfill --dry-run   # xem trước, không ghi
  docker compose exec app php artisan tax-types:backfill             # chạy thật
  ```
  Brand có branch 10% và 8% legacy → tạo 2 tax type, mỗi branch `default_tax_type_id` trỏ đúng rate, rate phổ biến nhất = `is_default`. **Idempotent** khi chạy lại. Branch legacy `tax_rate = 0` → tạo/map loại 0/0 — không branch nào để null default.
- [ ] **TC-K2 — Backfill order snapshots:**
  ```sh
  docker compose exec app php artisan orders:backfill-tax-snapshots --dry-run
  docker compose exec app php artisan orders:backfill-tax-snapshots
  ```
  Order mở có `tax_amount` nhưng chưa có snapshot per-line → được stamp từ resolution hiện tại; `--dry-run` không ghi gì.
- [ ] **TC-K3 — Seed nhất quán:** `migrate:fresh --seed` → `CustomerOrderSeeder` 15 order demo có snapshot per-line khớp totals.
- [ ] **TC-K4 — Drop tax_rate (Phase 6) an toàn:** sau khi drop cột `shop_order_settings.tax_rate`, checkout/settings/resource vẫn chạy (regression sweep). `grep tax_rate` backend không còn reader nào của cột branch (còn `customer_order_items.tax_rate` + `customer_invoices.tax_rate` là cột KHÁC — giữ).

### Nhóm L — Hiển thị client (Browser)

- [ ] **TC-L1 — pos-web cart:** order proof-case hiện cả `8%対象` và `10%対象` (cả bản nháp + read-only).
- [ ] **TC-L2 — pos-web split/debt/tab-bar:** split-bill dialog (+ tab by-items), `debt-search-dialog`, `pos-tab-bar` hiện số per-rate cho order mixed.
- [ ] **TC-L3 — pos-web settings editor:** có toggle `close_report_tax_breakdown` cạnh 4 toggle close-report cũ, round-trip giá trị.
- [ ] **TC-L4 — admin OrderChargeSummary:** order detail hiện dòng per-rate + chip mode (included/excluded).
- [ ] **TC-L5 — customer-web checkout:** desktop + mobile hiện dòng per-rate (総額表示 khi include-mode); account order-history detail render breakdown cho order mixed cũ.
- [ ] **TC-L6 — customer-web payment/summary view:** dine-in equal-split (`payment-view`) + `summary-view` hiện per-rate; preview khớp server.
- [ ] **TC-L7 — CSV product:** export chứa `tax_type_code`; import template + dialog nhận (happy round-trip).
- [ ] **TC-L8 — Không lỗi console:** mọi trang trên **không có JS console error**.

---

## PHẦN 3 — Regression sweep & tiêu chí Done

### 3.1 Regression (những chỗ dễ vỡ dây chuyền)
- [ ] ~55 file test cũ (spec Appendix A) đã cập nhật fixture/assertion — nằm trong PHẦN 1.
- [ ] Broadcast `OrderItemAdded` / `OrderPaymentRecorded` giữ nguyên key & value sau refactor (consumer contract §3.19).
- [ ] Stripe nhận 1 tổng amount — hành vi thanh toán không đổi.
- [ ] Không N+1: eager-load `.taxType` (query-count assertion trong Feature test).

### 3.2 Ngoài phạm vi test tự động (verify tay)
- godx-handy `OrderSummary` — không có harness → smoke tay, field optional (chịu payload cũ).
- Wails UI workstation (`Settings.tsx` bỏ ô taxRate) — không có browser-test → build + smoke tay.
- Output máy in nhiệt thật — chỉ assert golden-text string.

### 3.3 Tiêu chí Done
- [ ] PHẦN 1 xanh cả 3 suite (Pest / Go / vitest).
- [ ] Toàn bộ TC-* PHẦN 2 pass (hoặc ghi lý do trong NOTES.md nếu skip).
- [ ] Không case nào trong `TESTS.md` còn `[ ]` không có justification.
- [ ] Case ⚠️ nhạy cảm I1/I2 (hóa đơn per-rate) được người review ký xác nhận.
