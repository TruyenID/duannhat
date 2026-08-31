# Tenant provisioning — baseline của brand và branch

Một brand mới, một chi nhánh mới: cái gì phải có sẵn để nó bán được hàng, ai
dựng, và dựng lúc nào. Ra đời ở #2320.

## Luật một câu

**Baseline là reconcile, không phải create-once.** Mỗi mục tự trả lời được "đã
đúng chưa", nên chạy lại bao nhiêu lần cũng hội tụ — và **vá được chủ thể đã tồn
tại**, thứ mà một hook `created` không bao giờ làm được.

## Baseline gồm gì

| Chủ thể | Mục | Ai dựng |
|---|---|---|
| Brand | 3 loại thuế chuẩn (標準 10 · 軽減 8 · 非課税 0), đúng **một** mặc định | `TaxTypeService::ensureStandardTypesForBrand` |
| Brand | Reverb app credentials | `BrandReverbAppService::provision` |
| Brand | `ProductType` mã `combo` | `BrandCoreCatalogService::ensureCombo` |
| Brand | đóng dấu loại thuế mặc định lên product **chưa gắn gì** | `BrandBaselineProvisioner` |
| Branch | `shop_order_settings` (tiền tệ của chi nhánh · loại thuế mặc định · `prices_include_tax` theo quốc gia) | `BranchBaselineProvisioner` + `ShopOrderSettingsService::creationDefaults` |
| Branch | sơ đồ zone/table từ mẫu của brand — **chỉ khi chi nhánh chưa có zone nào** | `BrandTableDefaultsService::apply` |

`App\Services\Provisioning\BrandBaselineProvisioner` và `BranchBaselineProvisioner`
là hai cửa duy nhất. Chúng không tự cài đặt gì — mỗi mục vẫn thuộc service chủ
sở hữu miền, provisioner chỉ quyết định **khi nào** gọi.

## Bốn đường vào, cùng một provisioner

| Đường | Khi nào | Ghi chú |
|---|---|---|
| `UserProvisioner::syncBrands` / `syncBranches` | mỗi lượt đăng nhập SSO | Brand/branch xuất hiện ở tempo qua đây. **Đây là "Platform provisioning entrypoint"** mà tài liệu cũ viện dẫn suốt nhưng chưa từng tồn tại. |
| `BranchProvisioningService::create` | HQ tạo shop | Cùng transaction với hàng `branches` — baseline hỏng thì không có chi nhánh nào ra đời. |
| `BaselineProvisioningSeeder` | `db:seed` (cả dev lẫn production) | Quét mọi brand/branch đang có. |
| `php artisan provisioning:reconcile` | tay, trên production | Đường vá chủ thể cũ. |

**Hook `Brand::created` CỐ Ý là tập con** (chỉ Reverb + combo, không tax type):
hàng trăm test tạo brand qua factory rồi tự seed `TaxType`, và một hook đầy đủ sẽ
đụng unique `[brand_id, code]`. Xem `AppServiceProvider` quanh dòng 498.

## Lazy hay baseline?

Ranh giới không được xoá nhoà:

- **Lazy (`firstOrCreate` lúc dùng lần đầu)** — `Till`, `Warehouse`. Chúng là hạ
  tầng, không mang quyết định nghiệp vụ nào trước lần dùng đầu, nên tạo sẵn chỉ
  đẻ hàng rác cho shop chưa mở.
- **Baseline (phải có trước giao dịch đầu tiên)** — loại thuế, `shop_order_settings`.
  Chúng là **tiền**. Một thứ quyết định tiền không được sinh ra bởi chính giao
  dịch đầu tiên dùng nó.

## Vận hành

```sh
php artisan provisioning:reconcile --dry-run            # báo cáo, không ghi gì
php artisan provisioning:reconcile --brand=betoya       # vá một brand
php artisan provisioning:reconcile                      # toàn bộ
```

`--dry-run` **chính là** báo cáo readiness, và `GET /api/v1/hq/{brandSlug}/readiness`
(#2344) phơi đúng phép đo đó ra HTTP cho admin-web — cùng hai provisioner, cùng
ba trạng thái, **chỉ gọi `plan()`**. Cố ý không có lệnh `provisioning:readiness`
thứ hai để hai bên trả lời khác nhau. Bảng in ra chỉ
liệt kê mục chưa đạt; `satisfied` bị lược.

Ba trạng thái cần phân biệt trong báo cáo:

| Trạng thái | Nghĩa |
|---|---|
| `missing` | thiếu, và lượt này không sửa (`--dry-run`) |
| `applied` | thiếu, và lượt này đã sửa |
| `skipped` | **chưa kiểm được** — thiếu tiền đề, ví dụ brand chưa gắn organization |

`skipped` không bao giờ được gộp vào "đã đúng": gộp lại thì một brand chưa đồng
bộ org sẽ báo sẵn sàng.

## Thêm một mục baseline mới

1. Thêm cặp `<mục>Status()` / `apply<Mục>()` vào provisioner tương ứng. Vế status
   **chỉ đọc** — `plan()` chạy nó trên production.
2. Thêm khoá vào `statuses()` và nhánh `match` trong `ensure()`.
3. Viết test: dựng đủ · idempotent (lượt hai `changed() === false`) · **không ghi
   đè lựa chọn đã có của người vận hành**.
4. Nếu mục đó đụng thuế/tiền, thêm luật vào
   `tests/Feature/Architecture/BaselineProvisioningIsSingleSourceTest.php`.

**Đừng viết seeder quét bù cho mục mới.** Đó chính là cách repo này từng có ba
bản cài đặt cùng cấp bộ loại thuế chuẩn, và chúng bắt đầu đánh nhau.

## Vì sao có tài liệu này — bài học #2320

`Brand::created` không bắn khi seed (`DatabaseSeeder` dùng `WithoutModelEvents`).
Mỗi lần thêm một mục baseline, người ta lại viết một seeder quét bù riêng. Đến
lúc có ba bản cho riêng loại thuế thì:

- `JapaneseTaxSeeder` **xoá** hàng do `TaxTypeService` tạo để chiếm chỗ cho UUIDv5
  tất định của nó;
- vì ghi thẳng `DB::table()->upsert()`, nó bỏ qua `ensureOpenRatePeriod()` —
  loại thuế sinh ra không có kỳ hiệu lực nào (#2318);
- `assignProductTaxTypes` `update()` **toàn bộ** product của brand về 標準税率,
  không có `whereNull`.

Hậu quả đo được: ảnh chụp production mang 13 hàng product gán 軽減税率 8% (5 hàng
còn sống) và 1 chi nhánh lấy 軽減 làm mặc định — mọi lượt seed san phẳng hết về
10%. **Khách bị thu vượt 2% trên đúng những món luật cho hưởng 8%.**

Hồi quy ghim ở `tests/Feature/Provisioning/CatalogSnapshotTaxRemapTest.php`.

## Ảnh chụp catalog và ánh xạ loại thuế

`CatalogSnapshotSeeder` mang `products.tax_type_id` của DB **nguồn**. Nó ánh xạ
theo **mã**, không theo id:

```
id nguồn → (tax_types.json) → mã → (DB brand đích) → id đích
```

Loại thuế của brand đích được dựng **trước** vòng upsert bằng
`BrandBaselineProvisioner::ensureTaxTypes()` — cố ý chỉ mục tax, vì baseline đầy
đủ sẽ tạo `product_types` mã `combo` với id mới rồi đụng unique với hàng `combo`
của chính ảnh chụp.

Một id không ánh xạ được là **lỗi**, không phải `null`. Ghi `null` chính là bản
cũ, và nó im lặng đánh mất thuế suất. Dump mới có loại thuế riêng ⇒ cập nhật
`database/seeders/fixtures/catalog/tax_types.json` (đường dump đã chụp sẵn bảng
này).

### Kiểm ngay sau khi restore

```sh
php artisan db:seed --class=CatalogSnapshotSeeder --force
php artisan deploy:verify-production-seed --after-restore
```

Cờ `--after-restore` bật bốn **sàn đếm** (`branches` · `products` · `files` ·
`menu_products` của menu 人形町) — chúng bắt một lượt restore "thành công" mà để
catalog nửa vời.

**Chỉ chạy chúng ở đây**, đừng đưa lại vào đường deploy. Deploy gọi lệnh trần vì
`BetoyaSeeder` **bỏ qua** `CatalogSnapshotSeeder` trên mọi DB đã có catalog, nên
ở đó bốn sàn này đo những bảng lượt deploy không hề đụng tới: ngày 2026-08-12
chúng làm hỏng 3 lượt deploy production và bắt được 0 lỗi thật (#2574).

## Dòng đơn đang mang thuế suất sai thì làm gì

**Reseed.** Không đếm thiệt hại, không lệnh backfill, không chờ ai quyết.

Chưa release ⇒ không có đơn khách thật; mọi `customer_order_items` hiện có đều
là dữ liệu seed/demo. Dựng một quy trình đối soát tiền quanh chúng là tự đẻ ra
công việc và chặn hệ thống — đúng thứ ruling LEGACY KHÔNG TỒN TẠI (#2188) cấm.

`migrate:fresh --seed` sinh lại catalog với thuế suất ĐÚNG (5 món 軽減税率 giữ
nguyên 8%), và `OrderSnapshotSeeder` map lại loại thuế **theo rate** tra từ DB
nên dòng đơn đi theo.

`customer_order_items.tax_rate` vẫn là snapshot bất biến **kể từ lúc có đơn
thật** — bất biến đó bảo vệ chứng từ đã phát hành, không phải bảo vệ dữ liệu
demo.

## Việc còn treo

- **Màn hình readiness trên admin-web** — API đã có (#2344), giao diện thì chưa.
