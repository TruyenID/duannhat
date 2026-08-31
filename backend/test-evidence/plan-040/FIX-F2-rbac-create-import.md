# FIX — F2: Shop-tier roles bypass RBAC để create/import material

**Branch:** `fix/hq-brand-scope-isolation` (chưa commit)

## Lỗi
`MaterialPolicy@create = return true` → bỏ qua RBAC. Shop Staff/Shop Manager (view-only theo matrix seed, không có `material.create`) vẫn tạo/import material được = Broken Access Control. Cả `POST /materials` lẫn `POST /materials/import` đều authorize qua ability `create`.

## Fix
`app/Policies/MaterialPolicy.php@create`:
```php
$orgId = request()->attributes->get('organization_id');
return $user->hasPermission('material.create', $orgId);
```
(Mirror đúng `ProductPolicy@approve` đã dùng `hasPermission`.) Test: 3 file POST material (`MaterialCrudTest`, `ImportExportTest`, `ImportExportHardeningTest`) seed `IamSeeder` để org-admin có quyền; thêm test shop-staff → 403.

## Bằng chứng SAU FIX (curl live :5400)
| Actor | Action | Trước | Sau |
|---|---|---|---|
| frank (Shop Staff) | create | 201 | **403** |
| frank (Shop Staff) | import | 422 (vào importer) | **403** |
| admin (Org Admin) | create | 201 | **201** (control) |

Body 403: `"message":"This action is unauthorized."`

## Phân vai sau fix (đúng matrix seed)
- Tạo/import được: **Org Admin, Org Manager, Staff** (HQ-tier).
- Bị chặn 403: **Shop Manager, Shop Staff** (shop-tier, view-only).

## Test
- Mới: MaterialCrudTest "forbids shop-staff create" + "allows org-admin create (control)"; ImportExportHardeningTest "forbids real shop-staff import".
- Regression: tests/Feature/Product + BrandScopeIsolation + Plan040AuthzClusterJ + ProductPolicyApprove = **410 passed / 0 fail**. `pint` clean.

## Mã test case được fix (để cập nhật sheet → chuyển sang PASS, Expected đúng = 403)
### Staff CREATE (đã 403)
TC-009, TC-034, TC-059, TC-084, TC-109, TC-134, TC-159, TC-184, TC-209, TC-234, TC-259
### Staff IMPORT (đã 403)
TC-020, TC-045, TC-070, TC-095, TC-120, TC-145, TC-170, TC-195, TC-220, TC-245, TC-270

> Lưu ý phạm vi: fix này gate `create` (dùng cho cả create + import). `update`/`delete` material vẫn org-only (Shop-tier vẫn sửa/xoá được) — cùng class lỗ hổng nhưng KHÔNG nằm trong test case F2 bạn theo dõi; nói nếu muốn siết luôn.
