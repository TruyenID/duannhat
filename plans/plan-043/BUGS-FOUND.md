# Plan 043 — Live test findings (2026-07-10)

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

> Kết quả test sống toàn hệ thống thuế: **curl** trên backend :5400 + **Playwright headless** trên admin-web/pos-web/customer-web
> (branch `feature/plan-043-tax-types`, DB seed sạch + fixture proof-case riêng `plan043-test`). ~150 checks / 3 vòng.
> Harness: `/tmp/t043_battery{,2,3}.py`, `/tmp/t043_ui_test.js`, fixture `backend/plan043_fixture.php` (untracked). Screenshot: `/tmp/t043_ui/*.png`.
>
> **Vòng 2 (Playwright + curl) bổ sung 3 bug mới: BUG-7/8/9 bên dưới. Cả 6 bug vòng 1 đều tái hiện.**
>
> **Vòng 3 (curl mở rộng + Playwright sâu): KHÔNG có bug mới. Cả 9 bug tái hiện; thêm proof sống cho BUG-2e/2f/3.**
>
> **Vòng 5 (2026-07-10 chiều, làm tính năng 総額表示 + test sống trên POS LAN): thêm BUG-10 (khuyến mãi % sai 100 lần). Xem mục ngay dưới.** (Lưu ý: "vòng 4" đã dùng cho ma trận độ phủ ở dưới.)

## VÒNG 5 — 2026-07-10 (làm 総額表示 hiển thị menu + BUG-10)

### BUG-10 🔴 NGHIÊM TRỌNG — Khuyến mãi % giảm sai 100 lần (basis-points thay vì phần trăm)
- **Phát hiện từ câu hỏi sống:** "giá menu ¥2.450 mà dòng đơn lại ¥2.446?" → truy ra KM "Demo Happy Hour 15%" chỉ giảm ¥4 (~0,15%), badge hiện **−0%**.
- **Chứng minh sống:** `menu_promotions.discount_value=15` (=15%). Workstation `applyDiscount`/`applyDiscountForBadge` tính `giá×(10000−15)/10000` = 0,9985 → giảm 0,15%; `activePromotionForProduct` tính `15/100=0` → badge −0%. Đối chiếu: Cloud `CustomerOrderService` = `giá×(100−n)/100`, `n` validate 0,01–100 (plain percent); coupon workstation cũng đã `/100`; sync_pull cũng `/100`. → luồng **promotion** là chỗ duy nhất còn dùng basis-points.
- **Đã fix (`6b24958`):** 3 chỗ → `×(100−value)/100` (guard ≥100): `service.applyDiscount` (tiền thực thu), `handler.applyDiscountForBadge` (badge), `activePromotionForProduct` (percent badge). Fixture test đổi 2000/5000/1000 → 20/50/10 + thêm ca 15%→850, 20%→800. **Kiểm chứng sống sau fix:** LAN trả `Bun Cha 2350 → discount_percent=15, discounted_price=1997`; `Banh Mi 1250 → 15%, 1062`. Go test toàn bộ pass.

### Tính năng mới 総額表示 (hiển thị 税込/税抜 trên menu) — Q10 (không phải bug, xác nhận sống)
- Toggle `prices_include_tax` giờ đổi **giá hiển thị** trên menu pos-web + customer-web: TẮT → giá net + "Chưa gồm thuế/税抜"; BẬT → giá net+thuế + "Đã gồm thuế/税込" (helper `menuDisplayPrice`). Kiểm chứng sống: Bun Cha 2350 (net, dine-in 10%) → BẬT hiện 2585.
- LAN pos menu (workstation) nay trả `tax_rate_dine_in/takeaway` theo product (helper `resolveProductTaxRates`) cho cả `/menus/{menu}` và `/menus/{menu}/products` (endpoint grid pos-web dùng). Kiểm chứng sống: Bun Cha → `dine_in=10, takeaway=8`.
- Đã sửa tiếp các thẻ biến thể/SKU khi có KM (nhánh `StrikethroughPrice` bị bỏ sót transform) để đồng bộ với card. Không đụng vào giỏ hàng/checkout/tổng đơn (backend đã tính sẵn — tránh nhân đôi thuế).

## VÒNG 3 — xác nhận + proof mới (không có bug mới)
- **BUG-2e (coupon replay) — proof sống:** áp coupon fixed ¥500 lên order 8%=3000/10%=2000 → `discount_amount=500` OK nhưng `tax_amount` **giữ nguyên 440** (đúng theo §8 phải pro-rata 300/200 → tax=(2700×8%)+(1800×10%)=**396**, total=**4896**). Kết quả sống: total=**5440** (sai +544). `release-coupon` cũng không tính lại tax.
- **BUG-3 — proof trực quan (screenshot `13-tax-section.png`):** set DB `prices_include_tax=true`+`sc_tax=7`+`default=STANDARD` → GET settings CHỈ trả `close_report_tax_breakdown` → trên UI switch **税込価格（内税）= unchecked**, input **サービス料の税率 = 0** (đáng lẽ 7). Section thuế render đúng, chỉ giá trị không round-trip.
- **Coverage MỚI đều PASS (không bug):** rate-pick `dine_in`/`spot` → 10% (tax 100) ✓; order toàn **EXEMPT** (0/0) → tax 0, total=subtotal, không lỗi chia-0 ✓; **tạo tax type end-to-end qua dialog UI** (điền code/tên/2 rate → 作成 → row hiện) ✓ — BUG-9 chỉ ảnh hưởng SEED, CRUD runtime bình thường; **release-coupon** trả discount về 0 ✓; invoice endpoints no-auth → 401/403 (không 500) ✓; shop-manager PATCH shop khác → 403 ✓.
- **Console noise** vẫn còn (Echo `ws://0.0.0.0:8080`) trên mọi trang admin/customer — không thuộc plan-043.
- **Con số tổng vòng 3:** battery chính 63 checks (PASS 55 / 6 known-bug / 2 harness-fail đã xác minh không phải bug), battery mở rộng 15 checks, UI 12 checks. Tất cả FAIL đều truy về 9 bug đã biết hoặc lỗi harness (đã kiểm chứng).

## BUGS MỚI (vòng 2)

### BUG-7 🔴 HIGH — Cloud addItems BỎ QUA tier 1 (MenuProduct override) → drift cloud/workstation
- **Bằng chứng sống:** override menu-item bentō → STANDARD (10%), ring order takeaway qua workstation addItems → line stamp **8%** (đọc từ Product, không đọc override).
- **Root cause:** `OrderLifecycleController.php:201` — comment thừa nhận *"override isn't sent on this sync path → resolve from product"*. Nhưng menu payload gửi xuống workstation lại là giá trị **đã resolve override** → workstation local tính 10%, Cloud tính 8% → **hai bên lệch nhau cho mọi món có override**.
- **Fix hướng:** tra MenuProduct qua `menu_product_skus` scoped branch-menu — hệt như `resolveAuthoritativeItemPrices` (dòng 655) đã làm cho GIÁ.

### BUG-8 🔴 HIGH — Lazy re-stamp (§11.3) KHÔNG được implement → line thiếu snapshot = 0% vĩnh viễn
- **Bằng chứng sống:** order có line `tax_rate=null` (giả lập pre-deploy) bị mutate qua addItems → line cũ **vẫn null**, đóng góp **0 thuế** (order 1300 nhưng tax chỉ 24 = mỗi line mới) → **thiếu thuế**.
- **Root cause:** `OrderPricingCalculator.php:203` — `$rate = $item->tax_rate !== null ? … : $legacyRate` với `$legacyRate = 0.0` (sau khi T6.2 drop cột legacy). Không code nào re-resolve line null. Kịch bản "Lazy re-stamp" trong TESTS.md chưa từng có implementation lẫn test.
- **Giảm nhẹ:** lệnh `orders:backfill-tax-snapshots` chữa được nếu chạy đúng lúc deploy — nhưng contract "no-command fallback" trong spec là vỡ.

### BUG-9 🔴 HIGH (fresh install) — Seed xong: TÊN loại thuế biến mất (0 translations)
- **Bằng chứng sống (UI + DB):** sau `migrate:fresh --seed`, trang 税区分 render 3 dòng **trống cột tên** (screenshot `1-tax-types.png`); `tax_type_translations` = **0 rows** cho cả 9 types.
- **Root cause (bisect 6 vòng, chốt 100%):** `DatabaseSeeder` dùng trait **`WithoutModelEvents`** → mute model events toàn chuỗi seed; Astrotomic Translatable lưu translations qua listener **`static::saved()`** (`Translatable.php:35`) → translations bị nuốt im lặng. Chạy seeder standalone (`db:seed --class=TaxTypeSeeder`) thì CÓ tên (không trait). PaymentMethodSeeder đã biết bẫy này và dùng raw `flushTranslations()` — TaxTypeSeeder (plan-043) không dùng.
- **Không ảnh hưởng:** `BackfillTaxTypes` command (chạy ngoài trait, events bình thường).
- **Fix hướng:** TaxTypeSeeder ghi translations tường minh (pattern PaymentMethodSeeder), hoặc service create ghi translations không phụ thuộc event.

### Ghi chú UI (Playwright, vòng 2)
- ✅ Trang 税区分 render đủ 3 dòng + badge デフォルト + rate 2 cột; dialog tạo mở đúng (店内/持ち帰り); trang products có cột 税区分; settings/orders/pos-web/customer-web load không crash. Khi DB có translations, tên render 3/3 (screenshot `8-tax-types-named.png`).
- ⚠️ **Console noise mọi trang** (admin + customer-web): Echo/Pusher spam `WebSocket ws://0.0.0.0:8080` handshake fail — host `0.0.0.0` không hợp lệ cho browser (config Reverb dev env, không thuộc plan-043 nhưng nên sửa).
- Claude-in-Chrome extension không kết nối → dùng Playwright headless (chromium 147) thay thế.

## BUGS

### BUG-1 🔴 CRITICAL — `is_tax_included` không bao giờ được stamp → chế độ 総額表示 chết toàn hệ thống
- **Bằng chứng sống:** PATCH `prices_include_tax=true` persist DB OK → tạo order mới qua workstation → `is_tax_included=false`, engine tính excluded (bentō+bia → total 1630 = cộng thuế lên trên, thay vì 1500 gross + thuế nội 119).
- **Root cause:** không nơi nào trong `app/` ghi `is_tax_included` khi tạo order (grep chỉ thấy đọc/serialize). Cột NOT NULL default `false` → mọi fallback `$order->is_tax_included ?? $settings->prices_include_tax` (CustomerOrderService.php:1895, OrderPricingCalculator.php:143) là **dead code** — attribute không bao giờ null sau khi load từ DB.
- **Vì sao Pest vẫn xanh:** các test included-mode set thẳng `is_tax_included => true` vào fixture → test engine (đúng) nhưng không test stamping (thiếu).
- **Fix hướng:** stamp trong `CustomerOrderService::create()` từ `ShopOrderSetting.prices_include_tax` của branch (mọi đường tạo order: customer QR / workstation store / seeder).

### BUG-2 🔴 HIGH — Workstation Cloud replay: 5/6 mutation KHÔNG chạy lại engine §8 → tiền cloud sai
`OrderLifecycleController` (backend): chỉ `addItems` gọi `refreshOrderTotals` (được vá ở T6.2). Còn lại đều ghi thô:
| Endpoint | Thiếu | Chứng minh sống |
|---|---|---|
| `POST orders/{id}/update` (nhận `order_type`) | không re-resolve line + không recompute (§7 vỡ) | takeaway→dine_in: bentō vẫn 8%, tax 130, total 1630 (đúng: 10%/150/1650) |
| `POST items/{item}` (updateItem) | update `subtotal = qty×price` nhưng KHÔNG tính lại line `tax_amount` + order totals | qty 1→3: line subtotal **3000** nhưng line tax **80**, order subtotal **1000**, total **1080** — bất nhất hoàn toàn |
| `POST items/{item}/void` | không recompute | void bia: tax vẫn 130, total vẫn 1630 |
| `POST checkout` | không recompute → drift đóng băng vào tiền chốt | — |
| `POST apply-coupon` | ghi `discount_amount` phẳng, không pro-rata per-group + không tính lại tax trên base đã giảm (§8 step 2) | — |
- **Impact thật:** workstation Go sync_push bắn đúng các endpoint này (`sync_push_regressions_test.go:147/223`) → mọi order LAN có void/sửa qty/đổi loại/coupon SAU lần addItems cuối → tiền order phía cloud sai → dashboard shop/HQ, Z-report cloud, invoice đọc số sai.
- **Fix hướng:** gọi `refreshOrderTotals`/re-resolve (như addItems) ở cả 5 endpoint; update nhận order_type phải re-stamp toàn bộ line; updateItem nhận toppings hoặc tài liệu hóa rằng swap phải replay qua addItems.

### BUG-3 🟠 HIGH (UI) — `GET /shops/{slug}/settings/order` không trả 3 field thuế → admin-web không round-trip
- `ShopOrderSettingsController::show()` build payload tay, **thiếu** `prices_include_tax`, `service_charge_tax_rate`, `default_tax_type_id` (có `close_report_tax_breakdown`).
- admin-web `settings/page.tsx` khai báo interface (dòng 88–90) + hydrate state (329–331) từ CHÍNH endpoint này → sau reload: switch include-tax luôn OFF, sc-tax-rate luôn "0", default type luôn unset — **dù DB đã lưu đúng** (PATCH hoạt động). Dirty-check (455–457) so với undefined → nguy cơ lần save sau gửi `prices_include_tax=false` revert ngầm giá trị thật.
- Kèm **OpenAPI drift**: OA schema của show (dòng 72–77) quảng cáo cả `tax_rate` legacy (đã drop) lẫn 3 field mới — không khớp payload thật.
- **Fix hướng:** thêm 3 field vào show() (+ xóa OA property `tax_rate`).

### BUG-4 🟡 MEDIUM — POST/PUT tax-types với `name` là object → 500 thay vì 422
- `TaxTypeStoreRequest::withValidator()` làm `trim((string) $this->input('name'))` → gửi `name: {ja:...}` (nhầm shape phổ biến) → **ErrorException "Array to string conversion" 500**.
- Contract đúng là top-level locale keys (`{ja:{name},en:{name},vi:{name}}`) — nhưng input sai shape phải trả 422, không được crash. (UpdateRequest cùng idiom — kiểm tra tương tự.)
- **Fix hướng:** thêm rule `'name' => ['sometimes','nullable','string']` hoặc guard `is_string()`.

### BUG-5 🟡 REVIEW (authz, systemic) — shop-manager tạo được tax type HQ (201)
- `shop-manager-sjk` (scope shop SJK) POST `/hq/beto-kitchen/tax-types` → **201**; GET list → 200.
- **Đối chứng:** product-types (idiom gốc) cho phép y hệt (POST → 201) → mô hình policy hiện tại chỉ check **org membership**, không check role/context HQ. Không phải regression của plan-043 (clone đúng idiom), nhưng lệch kỳ vọng TESTS.md ("Shop SSO user cannot create tax types → 403" — test Pest pass vì user test không mang quyền như user seed). Cần chủ dự án quyết: siết cả họ policy catalog hay chấp nhận.

### BUG-6 ⚪ LOW (docs) — OA annotation còn quảng cáo cột `tax_rate` đã drop
- `ShopOrderSettingsController` dòng ~72: property `tax_rate` mô tả "kept until the Phase 6 column drop" — Phase 6 ĐÃ drop. T6.6 sót annotation này. (Cùng chỗ với BUG-3.)

## PASS — phần lõi vững (bằng chứng sống)
- **Proof case §1.2 chính xác từng yên:** takeaway bentō+bia → line 8%/80 + 10%/50, subtotal 1500, tax 130, total 1630 ✓
- **Làm tròn 1 lần/nhóm:** 3×¥333 @8% → 80 (không phải 81) ✓
- **Immutability:** nâng RED 8→9 → order cũ giữ 80, order mới ăn 90 ✓
- **SC tax:** sc 10% + sc_tax 10% → total 1190 ✓
- **Resolver:** override menu > product > branch default > brand default ✓ (set/clear override 200/null)
- **Guards:** 409 `TAX_TYPE_IN_USE` (+counts, bulk per-row) ✓; 409 `TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT` khi ca mở, 200 khi đóng ✓; 422 rates/dup-code/cross-brand/inactive ✓
- **Payloads:** workstation menu (tax_types[] + tax_type_id), branch (4 field, sạch tax_rate), customer branches (sạch tax_rate) ✓
- **Default duy nhất:** is_default dance qua create/update ✓

## MA TRẬN ĐỘ PHỦ (vòng 4 — kiểm kê toàn hệ thống)

| Tầng / App | Phương pháp | Kết quả |
|---|---|---|
| **backend** — resolver, engine §8, CRUD, authz, guards | curl sống ~90 + Pest | ✅ ~90 curl + **325** Pest (tax-scoped) 0 fail |
| **backend** — invoice per-rate, Z-report, revenue, dashboard, refund, broadcast | Pest đích danh | ✅ **108 + 2** pass 0 fail |
| **workstation-app (Go binary/engine offline)** | `go test ./...` | ✅ **7 packages, 0 fail** (computeOrderTotals, receipt ※/T13, shift report, coupon per-group, kiosk currency) |
| **workstation Cloud endpoints** (/workstation/*) | curl sống | ✅ menu/branch/orders payload + addItems resolve |
| **admin-web** — tax-types list/dialog CRUD | Playwright | ✅ list 3 loại + tên; tạo end-to-end qua dialog |
| **admin-web** — OrderChargeSummary per-rate | Playwright + screenshot `16` | ✅ **税別 chip + 10%対象/8%対象 tách nhóm, đúng proof case 1500/130/1630** |
| **admin-web** — settings tax (BUG-3) | Playwright + screenshot `13` | ✅ chứng minh BUG-3 trực quan (switch OFF dù DB=true) |
| **admin-web** — menu override | Playwright | ⚠️ editor mở OK; UI override nằm trong item-drawer (chưa mở sâu; hành vi API đã test 422+resolve) |
| **pos-web** — split-by-items per-rate (T5.2) | vitest đích danh | ✅ **29/29** pass |
| **pos-web** — components tax (order-cart, person-card, vat-invoice) | build + 043-diff | ✅ render trong suite; live cart cần pairing (chưa) |
| **pos-web** — 51 fail khác | phân tích | ✅ PRE-EXISTING (guard pairing #472, non-043; 043 không đụng 6 file này) |
| **customer-web** | Playwright + payload | ✅ load không pageerror; per-rate dựa payload backend (đã test) |

## Chưa test được (khó chạm sống — nhưng đã phủ gián tiếp)
- **pos-web cart / customer-web checkout RENDER sống** per-rate: cần device pairing / QR session. Phủ bởi: split-by-items 29/29, backend payload tests, component do 043 sửa (order-cart `TaxBreakdownRows`, checkout `TaxBreakdownLines`).
- **Workstation Wails UI** (Settings bỏ ô taxRate — T3.7): không có browser harness (spec liệt out-of-scope); phủ bởi Go build.
- **Máy in nhiệt thật / Stripe**: golden-text trong Go tests (pass) + fixture Stripe không đổi.
- **Claude-in-Chrome extension**: không kết nối → toàn bộ UI chạy bằng Playwright headless (chromium 147) thay thế.

## KẾT LUẬN VÒNG 4
Đã phủ **cả 5 app + Go binary**. **KHÔNG phát sinh bug mới**; 9 bug đứng nguyên. Bổ sung 2 lớp phủ authoritative còn thiếu: **workstation Go (0 fail)** + **admin-web UI per-rate (visual, đúng proof case)**. Lõi engine đúng ở cả 3 runtime (PHP/Go/TS). Các bug đều là "mutation không nối vào engine" (BUG-1/2/7/8) + seed/settings/authz/docs (BUG-9/3/5/4/6).
