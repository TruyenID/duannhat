# plan-043 — Tax Audit Fixes (2026-07-14)

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

> Kết quả xử lý toàn bộ phát hiện từ cuộc audit thuế 4 lượt (2026-07-13/14).
> Đánh số theo danh sách audit đã báo cáo: **1.x** = lỗi nghiêm trọng backend,
> **2.x** = tầng sync LAN↔Cloud, **3.x** = mức trung bình, **4.x** = vận hành,
> **5.x** = dữ liệu; **Round-3 A/B** + **Round-4 B-x** = phát hiện khi audit
> ngược chính các fix + các vùng chưa từng soi. Mỗi mục: vấn đề → fix → file → test.

---

## ROUND 4 (vùng chưa soi + audit ngược round-3) — ĐÃ XỬ LÝ

### Kết quả xác minh lớn (tin tốt)
- **FULL backend suite: 4.838 passed / 2 skipped / 0 fail** (lần đầu chạy trọn).
- **Omnify schemas KHÔNG drift** — 10 bảng/enum thuế khớp YAML↔migration↔model↔TS.
  `omnify:gen` an toàn để chạy.
- Ngữ nghĩa `taxable` net mới **khớp kỳ vọng sẵn có của cả 3 frontend** — không
  consumer nào vỡ. tms-app: 0 dính líu thuế (đúng).

### B-I — godx-kiosk (client CHƯA TỪNG audit; tiền vẫn đúng, đây là hiển thị)
- **B-I.1** `is_tax_included` bị kiosk bỏ qua → không nhãn 税込/税抜. Fix: thêm
  field vào kiosk `Order` type + render suffix trên dòng thuế; **bổ sung
  `is_tax_included` vào Cloud `KioskOrderResource`** (workstation đã ship sẵn).
- **B-I.2** kiosk không render breakdown per-rate. Fix: thêm `tax_breakdown`
  vào kiosk type + render 1 dòng/rate (fallback dòng đơn); **bổ sung
  `tax_breakdown` vào workstation kiosk order shape** (`kioskTaxBreakdown`,
  group-once per rate, net taxable trong included-mode — Cloud đã có sẵn).
- File: `godx-kiosk/src/types/kiosk.ts` + `app/bill.tsx` + i18n 3 locale;
  `backend/.../KioskOrderResource.php`; `workstation .../local_kiosk.go`.
- Test: `local_kiosk_tax_breakdown_test.go` (3 case: gom rate excluded, net
  included, empty-fallback).
- **CHƯA fix (nghiệp vụ, cần khách chốt): UI xác nhận 店内/持ち帰り** trên kiosk
  takeaway-capable — order_type hiện suy ngầm từ QR; đây là quyết định luật/UX
  của khách hàng Nhật, không phải bug code.

### B-II — sót từ round-3 (audit ngược)
- **B-II.5** `UpdateMeta`: gộp CẢ meta UPDATE + re-resolve + recalc vào MỘT
  transaction khi đổi order_type (hết cửa sổ crash để order_type mới cạnh rate
  cũ). File: `workstation .../order_service_pos.go`.
- **B-II.6** admin-web `TaxTypeInUseMeta` thêm key `brand_default` + render dòng
  đó trong dialog in-use + i18n 3 locale (409 từ guard deactivate 3.8 hiện đủ
  ngữ cảnh). File: `admin-web tax-type-service.ts` + `tax-types/page.tsx`.
- **B-II.7** pos-web test: `vi.restoreAllMocks()` trong afterEach (spy sonner
  không rò sang test sau). File: `pos-web .../settings/page.test.tsx`.

### B-III — admin-web enhancements (không phải bug chặn, đã làm phần có backend hỗ trợ)
- **B-III.8** hiển thị `service_charge_tax` (residual) dưới dòng service charge
  + type + i18n → đối soát trọn `Σ breakdown.tax + service_charge_tax == tax_amount`.
- **B-III.11** badge `prices_include_tax_at_open` ở till session detail —
  **bổ sung serialize field vào `ShopTillTrackingService`** (trước chỉ stamp lúc
  open, không trả về) + type + UI + i18n.
- Còn lại (gap/feature, chưa làm — ghi backlog): CSV till per-rate tax,
  UI tax-audit-log, UI MenuProduct audit trail, per-line tax trong
  order-line-items, dashboard net/gross/by_rate payload (backend chưa ship).

### Round-4: cáo buộc đã bác bỏ (verify code)
- "orderTypeOf đọc e.db stale" — SAI: truyền `tx`.
- "toFloat rơi tax_rate:0 vì thiếu case int" — SAI: JSON→`any` luôn ra float64.
- "B4 bỏ sót case 0%" — SAI: type resolve (kể cả exempt 0/0) luôn HasSnapshot=true.
- "restore() observer race" — không có observer `restored` cho Brand.

---

## ROUND 3 (audit ngược các fix + consumer) — TẤT CẢ ĐÃ XỬ LÝ

### A1 — pos-web Settings 403 khi đổi toggle thuế (regression của fix 1.5)
- pos-web chỉ giữ device token (không có user bearer) → backend gate 1.5 trả
  403 khi cashier bấm toggle thuế. **Giải quyết:** giữ backend chặt (đúng
  audit-control); pos-web map riêng `403 TAX_BREAKDOWN_TOGGLE_FORBIDDEN` thành
  thông báo "chỉ user đăng nhập (quản lý) — đổi trong admin-web" (i18n
  ja/en/vi), switch tự revert về giá trị server. Bản cài SSO-user (nếu có)
  không bị ảnh hưởng — vẫn đổi được như thường.
- File: `pos-web/src/app/settings/page.tsx`, `src/i18n/{ja,en,vi}.json`.
- Test: `page.test.tsx` +2 case (map message + i18n 3 locale). 316/316 + tsc sạch.

### B1 — clamp `scNet ≥ 0` (included-mode residual > service charge)
- `forOrder` finalized: dữ liệu legacy/lệch có residual > sc → taxable nhóm âm.
  Đã clamp; tiền thuế (residual) không đổi. Test: residual 120 > sc 50 →
  mọi `taxable ≥ 0`, Σ tax vẫn == stored 200.

### B2 — brand RESTORE không được provision types
- `wasRecentlyCreated` bỏ sót brand khôi phục từ soft-delete. Giờ ensure khi
  `wasRecentlyCreated || brand chưa có type nào` (1 EXISTS/brand/sync).
- **🆕 BUG CÓ SẴN bị phơi ra khi viết test:** `deleted_at => null` qua
  `updateOrCreate` bị fillable-guard **âm thầm bỏ qua** ở CẢ 3 model
  (Organization/Brand/Branch) → **restore qua org-sync chưa bao giờ hoạt
  động**. Fix: gọi `->restore()` sau upsert (bypass mass-assignment) cho cả 3.
- Test: GodxOrgSyncServiceTest +1 (provision mới + restore re-provision).

### B3 — `bulkDelete` không catch `ModelNotFoundException`
- `delete()` mới re-fetch bằng findOrFail → race trash-giữa-chừng làm nổ cả
  batch. Giờ catch per-row → error `Not found`. Test: id đã trash → 1 error
  row, phần còn lại vẫn xoá; stale-instance delete → throws đúng loại.

### B4 — Go: không bao giờ UN-stamp dòng đã có snapshot
- `reResolveOrderLines` khi resolution rơi về legacy-no-type (tax_types local
  biến mất) → giữ nguyên snapshot thay vì ghi NULL. Test: xoá hết types +
  menu rồi flip order_type → dòng giữ `tt-red`, rate NOT NULL.

### B5 — Go: dòng có product rời khỏi menu local giữ type của chính nó
- Cloud re-resolve từ `product.taxType` (sống ngoài menu); Go đọc menu mirror
  → khi menu row mất, giờ dùng `tax_type_id` hiện tại của dòng làm input
  tier-1. Helper mới `menuItemTaxFieldsAnyState` (chấp nhận row inactive — reference
  lịch sử hợp lệ). Test: xoá menu row, thêm type default khác → flip dine_in →
  dòng giữ `tt-red` + rate 10 (không rơi về default).

### B6 — brand kết thúc sweep với 0 default
- `ensureStandardTypesForBrand` giờ promote STANDARD (fallback type active
  đầu tiên) khi sau sweep brand không có default nào. Test: STANDARD sẵn có
  không-default → sau ensure có đúng 1 default = STANDARD.

### B7 — Go: guard voided lặp lại trong mệnh đề UPDATE của re-resolve.

### B9 — Go: `UpdateMeta` re-resolve + recalc trong MỘT transaction
- Tách `recalcOrderTotalsTx(tx)`; flip order_type giờ atomic (hết cửa sổ crash
  giữa "rate mới" và "totals cũ").

### Round-3: cáo buộc đã bác bỏ (không sửa)
- "Alarm paid-mismatch không bao giờ nổ" — SAI: schema payments có
  `succeeded` và query chứa nó; `confirmed/paid` chỉ là giá trị chết vô hại.
- "Stale item.subtotal làm lệch pro-rata" — resource dùng cùng công thức cho
  tử/mẫu số → tự nhất quán.
- "abort trong DB::transaction bị nuốt" — Laravel rollback + rethrow đúng.
- Ngữ nghĩa `taxable` mới: **cả 3 frontend đã kỳ vọng net từ trước** (pos-web
  docstring, customer-web `taxable+tax=gross`, admin-web hiển thị net) —
  backend cũ mới là bên sai; không consumer nào vỡ.

### Round-3: còn lại có chủ đích
- OpenAPI chưa mô tả semantics `taxable` mới + `service_charge_tax` (doc-drift,
  regen annotation là follow-up).
- Invoice lịch sử mang snapshot semantics cũ — ghi chú đối soát cho kế toán.

---

## A. ĐÃ FIX — Backend (Laravel)

### 1.2 — Brand mới không có tax types → mọi order 0% âm thầm
- **Vấn đề:** brand tạo sau plan-043 không được seed tax types (seeder chỉ chạy
  tay); `TaxResolver` trả 0% **không log gì**.
- **Fix (3 lớp):**
  1. `TaxTypeService::ensureStandardTypesForBrand()` — nguồn chân lý duy nhất
     cho bộ 標準/軽減/非課税 (idempotent theo `[brand_id, code]`, không cướp
     default nếu admin đã trỏ lại).
  2. Entrypoint prod `GodxOrgSyncService::cacheBrands()` gọi nó khi brand
     `wasRecentlyCreated`. **Chủ ý KHÔNG gắn vào `BrandObserver`** — hàng chục
     test tạo brand qua factory rồi tự seed TaxType fixture sẽ vỡ unique
     `[brand_id, code]` (đã thử: 27 test fail → revert; ghi chú để lại trong
     observer).
  3. `TaxResolver` log **warning** (1 lần/brand/resolver) khi resolve về 0%.
- **File:** `app/Services/Tax/TaxTypeService.php`,
  `app/Services/Godx/GodxOrgSyncService.php`, `app/Observers/BrandObserver.php`
  (chỉ comment), `app/Services/Customer/TaxResolver.php`,
  `database/seeders/TaxTypeSeeder.php` (delegate).
- **Test:** `tests/Feature/Brand/BrandTaxTypeSeedingTest.php` (4 case: seed đủ 3
  + đúng chiều 10/8, idempotent + không cướp default, backfill thiếu code,
  seeder sweep).

### 1.3 — `tax_breakdown` sai ngữ nghĩa (gross/net, pre/post-discount, thiếu SC-tax)
- **Vấn đề:** `CustomerOrderResource.tax_breakdown.taxable` = Σ subtotal thô —
  **trước** giảm giá và là **GROSS** trong included-mode, trong khi `tax` là
  số **sau** giảm giá; Kiosk resource (PricingResult) trả NET → cùng field 2
  nghĩa; thuế phí phục vụ không có mặt.
- **Fix:** `taxable` = net-sau-giảm-giá (trừ thêm phần 内税 trong included
  mode) — đúng định nghĩa 課税売上 của engine; thêm field cộng dồn **additive**
  `service_charge_tax` (residual `order.tax_amount − Σ line tax`, chỉ khi mọi
  dòng đã stamp — order legacy trả `null` để không gán nhãn sai). Contract mới:
  `Σ tax_breakdown[].tax + service_charge_tax == order.tax_amount`.
- **File:** `app/Http/Resources/CustomerOrderResource.php`.
- **Test:** `tests/Feature/Shop/TaxBreakdownSemanticsTest.php` (3 case đầu).

### 1.4 — Breakdown order ĐÃ ĐÓNG recompute bằng setting sống
- **Vấn đề:** `forOrder()` đóng băng scalar nhưng dựng groups bằng
  `service_charge_(tax_)rate` **hiện tại** → sửa setting sau checkout làm
  invoice/email/kiosk hiển thị lệch `tax_amount` đã chốt.
- **Fix:** order finalized dựng groups **chỉ từ line snapshots** (sc rate = 0),
  rồi lấy sc tax = **residual** so với `tax_amount` đã lưu (chỉ gán khi order
  thực sự có `service_charge > 0`; residual của order legacy không bị dán nhãn
  sc tax). Vị trí đặt residual dùng rate hiện tại (chỉ là nhãn), **số tiền
  không bao giờ đổi**: `Σ groups.tax == stored tax_amount` bất biến.
- **File:** `app/Services/Customer/OrderPricingCalculator.php::forOrder`.
- **Test:** `TaxBreakdownSemanticsTest` case 1.4 (đổi sc rate 10→8 sau close,
  Σ vẫn 220).

### 1.5 — Toggle `close_report_tax_breakdown` không authorize
- **Vấn đề:** device token ẩn danh tắt được khối thuế trên báo cáo đóng ca in
  nhiệt (control audit) — không ai chịu trách nhiệm.
- **Fix:** `updateCloseReportToggles` chặn key này khi request không có
  `ssoUser` (middleware chỉ stamp cho người thật; device path resolve user()
  = Device model nên không dùng `user() === null` được) → **403
  `TAX_BREAKDOWN_TOGGLE_FORBIDDEN`**. Các toggle layout khác giữ nguyên cho
  device (pos-web settings không vỡ).
- **File:** `app/Http/Controllers/Api/V1/Shop/ShopOrderSettingsController.php`.
- **Test:** `tests/Feature/Shop/TaxGuardsAuditTest.php` (device 403 + layout
  toggle vẫn OK + user thật OK).

### 3.6 — Race guard mid-shift (currency + tax mode)
- **Fix:** toàn bộ guard-đọc + write của `update()` chạy trong **một
  transaction**, đọc giá trị hiện tại bằng `lockForUpdate()` trên settings row;
  `TillSessionService::open()` cũng `lockForUpdate()` **cùng row đó** khi
  snapshot currency/tax-mode → flip-vs-open serialize thật sự thay vì interleave.
- **File:** `ShopOrderSettingsController.php`, `app/Services/Pos/TillSessionService.php`.
- **Test:** regression `ShopOrderSettingsTest` (guard 409 + partial PATCH) xanh.

### 3.7 — `TaxTypeService::delete/bulkDelete` race
- **Fix:** `delete()` bọc transaction + `lockForUpdate` row trước khi đếm usage
  (bulkDelete đi qua delete() nên hưởng theo). Ghi chú trung thực: cửa sổ
  lý thuyết "INSERT product tham chiếu giữa check và soft-delete" không thể
  fence bằng FK (soft delete) — nhưng resolver bỏ qua type trashed nên đọc an toàn.
- **File:** `TaxTypeService.php`.

### 3.8 — Deactivate type đang là default → config mồ côi
- **Fix:** `toggleStatus()` chặn deactivate khi type là **brand default**
  (`is_default=1`) hoặc **branch default** (`shop_order_settings.default_tax_type_id`)
  → 409 `TAX_TYPE_IN_USE` với meta `brand_default`/`branch_defaults` (exception
  render merge key mới, 3 key cũ luôn hiện diện cho consumer cũ).
- **File:** `TaxTypeService.php`, `app/Exceptions/TaxTypeInUseException.php`.
- **Test:** `TaxGuardsAuditTest` case 3.8 (block cả 2 chiều + type thường vẫn
  toggle được).

### 3.9 — MenuProduct không có audit trail
- **Fix:** gắn `AuditsActivity` trait (Product đã có; override thuế cấp menu
  giờ có log ai/khi nào).
- **File:** `app/Models/MenuProduct.php`.

### 3.4 — Thiếu test pin "client không set được tax"
- **Fix:** test injection: POST items với `tax_rate=0 / tax_amount=0 /
  tax_type_id=null / unit_price=1` → server resolve 8% + giá menu 1000, không
  giá trị client nào persist.
- **Test:** `TaxGuardsAuditTest` case 3.4.

## B. ĐÃ FIX — Workstation Go

### 1.1 — Đổi `order_type` không re-resolve thuế các dòng (thu thiếu tiền thật)
- **Fix:** `reResolveOrderLines(tx, orderID, orderType, onlyUnstamped)` — mirror
  `CustomerOrderService::reResolveOrderLines` (menu tax fields từ dòng đã lưu
  → `resolveLineTax`); `UpdateMeta` gọi nó + `recalcOrderTotals`
  khi order_type đổi.
- **File:** `internal/service/order_service_tax_reresolve.go` (mới),
  `order_service_pos.go` (UpdateMeta hook).
- **Test:** `TestUpdateMeta_OrderTypeFlipReResolvesLineTax` (8%→10%, tax 80→100,
  total 1080→1100).

### 2.6/2.7 — Order offline kẹt legacy rate mãi mãi
- **Fix:** lazy re-stamp (mirror Cloud BUG-8): `recalcOrderTotals` phát hiện
  dòng `tax_rate NULL` + đã có tax types local → re-resolve các dòng đó trước
  khi tính (1 COUNT rẻ khi không có gì để làm). COALESCE-legacy chỉ còn là
  fallback cuối khi chưa sync types.
- **Test:** `TestRecalc_LazyReStampsUnstampedLinesOnceTypesExist`.

### 2.1 — Reconcile không adopt per-line tax từ Cloud
- **Fix:** `reconcileOrderFromCloud` giờ adopt cả `tax_rate / tax_amount /
  tax_type_id` per-line (builder dynamic, best-effort per-field như cũ) → rate
  local không còn stale sau khi Cloud re-resolve, Z-report LAN dùng đúng rate.
- **Test:** `TestReconcileOrderFromCloud_AdoptsPerLineTax` (Cloud re-resolve
  8→10: dòng local nhận 10/100/tt-std).

### 2.2/2.3 — Paid-order mismatch + clamp payment thiếu cảnh báo
- **Fix:** (a) reconcile phát hiện order **đã có payment local** mà Cloud đổi
  total → `slog.Error` đủ field (old/cloud/paid/delta) — điểm lệch két phải
  kêu to; (b) clamp payment phân cấp: lệch ≤ 1 đơn vị (rounding) = Warn như cũ,
  lệch > 1 đơn vị (tiền thật cashier đã thu mà Cloud không ghi) = **Error** kèm
  delta. Ghi chú: audit-table record cho sync engine là follow-up (cần plumbing
  audit logger vào SyncEngine).
- **File:** `internal/service/sync_service.go`.

### 2.4 + BUG MỚI phát hiện khi fix — item_update sync rỗng
- **Phát hiện mới:** call-site POS enqueue `{"item_id","patch":{...}}` nhưng
  handler chỉ đọc key phẳng → **mọi qty/note edit trên LAN sync lên Cloud thành
  null → Cloud không patch gì**. Test regression cũ dùng shape phẳng nên không
  bắt được.
- **Fix:** handler đọc **cả 2 shape** (phẳng cho hàng queue cũ, nested patch
  cho shape production) + forward `toppings` khi có (Cloud updateItem đã accept
  + re-resolve — pipe sẵn sàng cho UI topping-edit tương lai của LAN).
- **Test:** `TestOrderItemUpdate_ForwardsNestedPatchShape` (pin đúng shape
  production); test shape phẳng cũ vẫn giữ.

### 2.5 — Tolerance lệch Go/Cloud (đánh giá lại)
- **Kết luận sau khi đọc sâu:** Go clamp **mọi** overpay về đúng outstanding
  (không phải tolerance 1-unit như audit đoán) và Cloud cũng clamp về
  outstanding trong tolerance → hai bên hội tụ cùng giá trị; không cần đổi
  hành vi. Phần thiếu là **cảnh báo** khi delta lớn — đã xử ở 2.3.

## C. ĐÃ FIX — customer-web

### 3.5 — Thiếu 5 currency zero-fraction
- **Fix:** thêm `GNF, VUV, XAF, XOF, XPF` vào `ZERO_FRACTION`
  (`lib/split-rounding.ts`) — khớp backend `RoundingMode` + pos-web.
- **Test:** `pnpm test` 96/96 pass.

## D. ĐÃ FIX — Dữ liệu (dev docker)

### 5.1 — 34 order đang mở chưa stamp
- `php artisan orders:backfill-tax-snapshots --dry-run` → 34 order/102 dòng →
  chạy thật → **stamped 34/102, verify 0 dòng mở còn NULL**. (4.023 order ĐÃ
  ĐÓNG giữ NULL đúng thiết kế — tiền đã chốt không re-price; xem mục F.)

---

## E. KHÔNG FIX BẰNG CODE — lý do

| Mục | Lý do |
|---|---|
| 4.1 Gate T6.1 evidence | Việc vận hành: chạy query gate + ghi nhận kết quả trước khi deploy prod. Checklist ở mục F. |
| 4.2 Q1 UAT seed direction | Quyết định của khách hàng — code đã theo luật Nhật (店内10/持ち帰り8). |
| 4.3 Q8 min-version handshake | Feature mới (device version negotiation), không phải bug — cần plan riêng. |
| 4.4 UI MenuProduct override | Feature UI admin-web (endpoint + guard backend đã có); thuộc backlog plan-043 T2.3. |
| 4.5 UI seller_registration_number | Deferred có chủ đích (Q5) — cột + template đã sẵn. |
| 4.6 Rule combo 一体資産 | Bản chất là quy trình ops (luật cho phép con người quyết); hệ thống đã warn. |
| 3.1 Split validator thiếu SC | Agent báo nhưng **chưa tái hiện được bằng test độc lập trong lượt này** — cần 1 test shop SC+included trước khi sửa máu me vào validator payment (rủi ro chặn payment thật). Backlog ưu tiên cao. |
| 3.2 Stripe intent stale | Tiền đã an toàn (webhook auto-refund guard); còn lại là UX — backlog. |
| 3.3 Rounding mode `none` | Đang bị cô lập ở preview; sửa đụng contract preview — backlog + đã document. |
| 3.11/3.12/3.13 UX nhỏ frontend | Poll interval / preview reconcile / cảnh báo rate-null: cải thiện UX, không sai tiền (server authoritative). |
| I3 bucket 0% lịch sử | Đúng thiết kế (order đóng không re-price). Cần **ghi chú đối soát** cho kế toán: dữ liệu trước plan-043 nằm ở bucket 0%. |
| I4 Included-mode chưa có data thật | Việc UAT — phải chạy một vòng 総額表示 end-to-end trước khi bật cho khách. |

## F. CHECKLIST VẬN HÀNH (phải làm khi lên môi trường thật)

1. `php artisan tax-types:backfill` → `php artisan orders:backfill-tax-snapshots`
   (cả hai `--dry-run` trước) — **theo đúng thứ tự**.
2. Chạy query gate T6.1 và LƯU kết quả: mọi branch có `default_tax_type_id`;
   fleet workstation đã update (cột `tax_rate` legacy đã drop rồi — gate này
   giờ là hồi cứu bắt buộc).
3. Chốt Q1 với khách tại UAT (chiều 店内10/持ち帰り8).
4. UAT riêng cho included-mode (総額表示) trước khi bật.
5. Kế toán: doanh thu lịch sử pre-plan-043 hiển thị ở bucket 0% trong
   per-rate breakdown — đây là dữ liệu thiếu snapshot, không phải hàng miễn thuế.
6. Giám sát log mới: `TaxResolver: no tax type resolved` (Cloud) và
   `cloud recompute changed the total of a LOCALLY-PAID order` /
   `payment capped by MORE than a rounding unit` (workstation) — cả ba đều là
   chuông báo tiền.

## G. CÁC CÁO BUỘC AUDIT ĐÃ BÁC BỎ (không fix vì không phải lỗi)

1. `is_tax_included` NULL fallback — cột NOT NULL default false.
2. Go createItem truncation nghiêm trọng — giá trị bị stamp ghi đè trong cùng tx.
3. Float pro-rata discount drift — sai số ~1e-12, dưới step.
4. Tip bị nuốt trong overpay guard — `amount`/`tip_amount` là 2 field độc lập.
5. `change_amount` sai — quy ước `tendered ≥ amount + tip` có validation.
6. Clamp payment "im lặng hoàn toàn" — có `slog.Warn` từ trước (giờ phân cấp Error).
7. Go tolerance 1-unit vs Cloud — hai bên đều clamp về outstanding, hội tụ.

## H. KIỂM CHỨNG

| Suite | Kết quả |
|---|---|
| Backend Unit (full) | **301/301** |
| Backend Feature (Brand/HQ/Shop/Workstation/Kiosk/Mail/Godx/Notification + tax) | **1174/1174** |
| Go `internal/service` + `internal/handler` (full) | **xanh** (27s + 16s) |
| customer-web `pnpm test` | **96/96** |
| Backfill dev | 34 order/102 dòng stamped, 0 còn sót |
| Test mới thêm | 15 case (4 seeding + 4 semantics + 4 guards/injection + 2 Go re-resolve + 2 Go sync — 1 case pin bug item_update mới phát hiện) |

Pint sạch (backend) · gofmt + go vet sạch (Go).

## I. FIX HIỂN THỊ GIÁ 税込 TRONG GIỎ POS (2026-07-14 — báo lỗi "bún chả")

**Triệu chứng:** Shop bật `prices_include_tax` (総額表示). Card menu hiển thị giá
gross qua `menuDisplayPrice` (net×(1+r)) = ¥2.151, nhưng khi add vào order thì
dòng giỏ + tạm tính lại hiện net `order.subtotal` = ¥1.955 → hai màn lệch nhau.

**Chẩn đoán:** Dữ liệu + tổng tiền ĐÚNG. `is_tax_included=false` là đúng cho giá
nhập net (engine cộng thuế lên trên: net 1955 + tax 196 ≈ gross menu 2151). Bug
thuần DISPLAY: card menu dùng cờ *display* (`prices_include_tax`), còn giỏ dùng
cờ *engine* (`order.is_tax_included`) nên trình bày net. NOTES.md Q10 đã dự đoán
đúng ca này ("order-cart deliberately NOT transformed").

**Fix (`pos-web/src/app/pos/components/order-cart.tsx`):** khi `prices_include_tax`
BẬT và snapshot lưu net (`!is_tax_included`), giỏ trình bày 税込:
- dòng item → `menuDisplayPrice(subtotal, item.tax_rate, …)` (khớp card menu),
- tạm tính → `order.subtotal + Σ tax_breakdown.tax` (group-once, chuẩn),
- phí phục vụ → `service_charge + (tax_amount − item tax)`,
- các dòng thuế → nhãn 内消費税 (thông tin), TỔNG GIỮ NGUYÊN `total_amount`.
Đối soát khít: gross subtotal − discount + gross service ≡ total. Có guard
`!is_tax_included` ở cả dòng lẫn tổng để không double-count nếu snapshot đã gross.

**LAN parity:** `customer_order_shape.go` giờ emit `tax_breakdown` (trước chỉ
kiosk bill có) để đơn đọc qua workstation cũng đủ dữ liệu group-once.

**Refinement (2026-07-14 bis — báo lỗi "982 + 50 = 1032 sao có dòng thuế 89"):**
Ở chế độ 総額表示, dòng "Đã gồm thuế 10% ¥89" nằm giữa phí phục vụ và tổng, canh
phải y hệt các dòng cộng dồn → đọc như addend thứ 4 (982+50+89?), trong khi 89 đã
NẰM TRONG 982. Sửa trình bày: 内税 chuyển XUỐNG DƯỚI tổng thành dòng chú thích mờ,
đóng ngoặc `(¥89)` (component `IncludedTaxNote`) — đúng quy ước phiếu 税込 Nhật
「（内消費税 ¥X）」. Trong giỏ chỉ còn tạm tính + phí phục vụ (đều gross) + tổng,
nên 982 + 50 = 1032 hiển nhiên. Dòng thuế cộng-dồn (`TaxBreakdownRows`) chỉ render
khi 税抜 (`!showGrossSummary`); 税込 thì ẩn khỏi thân giỏ, đẩy xuống chú thích.

**Fix #2 (2026-07-14 ter — báo lỗi "893 + 45 + 89 = 1027 ≠ 1032"):** Ở chế độ
税抜, các dòng hiển thị KHÔNG cộng khớp tổng. Nguyên nhân: `tax_breakdown` chỉ là
thuế MÓN (89); phí phục vụ là một khoản chịu thuế riêng ở `service_charge_tax_rate`
(10%) → thuế phí = `tax_amount − Σ thuế món` = 94 − 89 = **5** bị THIẾU khỏi màn.
`order.total_amount` (1032) đã gồm cả 5 này (engine `pricing.go::priceGroups` tính
`serviceChargeTax` + gộp vào `taxAmount` — xác nhận bằng DB thật), nhưng màn chỉ
hiện 89 → lệch 5. Bug CÓ SẴN (shape `kioskTaxBreakdown` recompute breakdown từ item
lines, bỏ thuế phí), lộ ra khi user cộng tay.

**Chốt cách trình bày (user chọn "Gộp 1 dòng"):** thuế phí phục vụ luôn NẰM TRONG
dòng "Phí phục vụ" (hiển thị gross = tiền phí + thuế của nó), thống nhất cả hai chế
độ. Dòng thuế còn lại chỉ mang thuế MÓN. Reconcile:
- **税抜**: Tạm tính 893 (net món) + Phí phục vụ **50** (45+5) + Thuế món 89 = 1032 ✅
- **税込**: Tạm tính 982 (893+89) + Phí phục vụ **50** = 1032, chú thích 内税 89 (món) ✅

Cách làm (pos-web `order-cart.tsx`): `serviceChargeGross = service_charge +
serviceTax` (serviceTax = `tax_amount − Σ item tax`, gate `hasBreakdown`), dùng cho
dòng phí phục vụ ở CẢ hai chế độ; dòng/chú thích thuế đọc thẳng item breakdown (89),
KHÔNG gộp thuế phí. Không cần luồng `service_charge_tax_rate` xuống giỏ nữa.

**Trực quan hoá (`ServiceChargeRow`, exported để test):** nhãn "Phí phục vụ" là
NÚT BẤM (chevron xoay) thu gọn/mở rộng 2 dòng con canh cột, có đường kẻ dọc dẫn
hướng: "Trước thuế ¥45" + "Thuế +¥5" → thấy rõ ¥50 = phí + thuế. Mặc định mở.
i18n `pos.cart.service_charge_base` (Trước thuế / 税抜 / Pre-tax) cho ja/en/vi. Test
`service-charge-row.test.tsx` (3 case: mặc định mở, bấm thu gọn/mở lại, không thuế
→ không toggle).

**Helper thuần + test:** `itemTaxTotal` / `serviceTaxTotal` trong
`pos-web/src/app/pos/lib/tax-display.ts`.

| Suite | Kết quả |
|---|---|
| pos-web `vitest run` (full) | **329/329** (14 case tax-display: helper + đối soát ORD-2026-4084 cả 税込 lẫn 税抜) |
| pos-web `tsc -b` + eslint (file sửa) | **sạch** |
| Go `internal/handler` + `internal/service` (full) | **xanh** (+1 case `TestCustomerOrderShape_EmitsTaxBreakdown`) |

> Ghi chú: `tax_breakdown` phía backend (Cloud + kiosk) vẫn là thuế MÓN. Engine
> (`pricing.go` gap #7) + phiếu in đã gộp thuế phí đúng vào nhóm thuế suất; chỉ shape
> JSON `kioskTaxBreakdown` là item-only. pos-web tự xử ở tầng hiển thị. **Phiếu bill
> KIOSK qua LAN** vẫn hiện breakdown thiếu thuế phí (tổng đúng) — tồn đọng, chưa fix.
