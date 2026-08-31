# FIX — F1: HQ brand-scope isolation (cross-brand show/update/delete)

**Branch:** `fix/hq-brand-scope-isolation`

## Vấn đề (đã xác nhận khi QA plan-040, TC-011)
Endpoint single-resource `/hq/{brandSlug}/...` bind model theo **id toàn cục** và chỉ kiểm `organization_id` (`authorizeOrganization` + `MaterialPolicy@view/update/delete = belongsToUserOrg`). **Không bao giờ so `brand_id` với brand trên route** → thành viên org đứng ở brand A đọc/sửa/xoá được resource brand B chỉ bằng đổi slug trên URL. List endpoint thì đã scope brand (qua `ResolveBrandFromSlug` → `brand_id` attribute), nên lỗ hổng chỉ ở show/update/destroy.

## Fix
1. Thêm helper `authorizeBrand(Model $model)` vào `app/Http/Controllers/Traits/HasOrganizationContext.php`:
   - So `model.brand_id` với `request->attributes('brand_id')` (do middleware set).
   - **abort(404)** nếu lệch (không leak sự tồn tại của brand khác). No-op khi route không có brand context.
2. Gọi `$this->authorizeBrand($model)` ngay sau `authorizeOrganization` trong các controller HQ brand-owned:
   - `MaterialController` (show/update/destroy/restore/check-usage)
   - `RecipeController` (show/update/destroy/+approval actions)
   - `MaterialLotController` (show/dispose/quarantine/release/timeline)
   - `MaterialSubstitutionRuleController` (show/update/destroy)
   - `MaterialUnitController` (index/store/update/destroy — qua material cha)

## Bằng chứng SAU FIX (curl, app live :5400)
| Hành động | Trước | Sau |
|---|---|---|
| control: show beto-coffee mat via beto-coffee | 200 | **200** ✓ |
| show betoya mat via **beto-coffee** route | 200 (leak) | **404** ✓ |
| update betoya (yield=777) via **beto-coffee** | 200 (ghi xuyên brand) | **404** ✓ (yield giữ 1000) |
| delete betoya via **beto-coffee** | 200 | **404** ✓ |
| control: show betoya via betoya route | 200 | **200** ✓ |

## Test
- Mới: `tests/Feature/HQ/BrandScopeIsolationTest.php` — 5 passed (show/update/delete cross-brand → 404, control 200; + recipe).
- Regression: MaterialCrud + RecipeCrud + RecipeApproval + MaterialLot + MaterialSubstitutionRule + MaterialUnit = **153 passed / 0 fail**.
- `pint --dirty` clean.

## Còn lại (ngoài phạm vi inventory, nên rà tiếp riêng)
`authorizeOrganization` (org-only) còn dùng ở nhiều controller HQ khác có brand_id: Product, ProductType, Category, Allergen, ToppingGroup*, ProductSku (qua product), Menu*… → khả năng cùng lỗ hổng brand-scope. Đề xuất: rà & áp cùng `authorizeBrand()` trong một pass riêng (đã để TODO).
