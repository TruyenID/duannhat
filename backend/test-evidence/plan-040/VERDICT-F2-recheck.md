# Verdict sau khi điều tra SÂU — F2 (Staff create/import) + TC-078

> **Đính chính:** Trước đây tôi xếp F2 là "by-design, không phải bug". Sau khi bạn yêu cầu kiểm tra kỹ, tôi đã đào sâu hệ thống permission và **kết luận này phải sửa lại**.

## TC-078 (isolation) — KHÔNG phải bug ✅
Đã chứng minh trước đó: app cách ly đúng (list betoya không chứa BETOCOFFEE). "Fail" lần deep-test là do **assertion phụ của tôi sai + fixture test bẩn**, không phải lỗi sản phẩm.

---

## Nhóm Staff create / import (TC-034/045/059/070/109/120/134/159/170/184/195/209/220) — **CÓ LỖI THẬT** ⚠️

### Bằng chứng mới (quyết định)
Hệ thống **CÓ RBAC đầy đủ và đang hoạt động**, không phải "flat-org thuần":

1. **Bảng `permissions` có 33 quyền**, gồm `material.create`, `material.import`, `catalog.create`...
2. **Shop Staff được gán đúng 4 quyền VIEW** (`catalog.view`, `menu.view`, `inventory.view`, `shop.view`) — **không** có `material.create`/`material.import`. Tức hệ thống *cố ý* định nghĩa Shop Staff là view-only.
3. **Cơ chế enforce tồn tại và chạy:** `User::hasPermission()` (trait `HasSsoRoles`, package dxs-sso). Đã test live:
   - `frank->hasPermission('material.create')` = **false**
   - `frank->hasPermission('material.import')` = **false**
   - `alice (OrgAdmin)->hasPermission('material.create')` = **true**
4. **Cơ chế này ĐÃ được dùng ở chỗ khác:** `ProductPolicy@approve` gọi `$user->hasPermission('catalog.approve', $orgId)` để chặn.

### Vấn đề
`MaterialPolicy@create` (và `import` path) viết `return true;` → **bỏ qua hoàn toàn permission**. Hệ thống *biết* frank không có quyền `material.create` (proof ở trên) nhưng policy vẫn cho tạo.

→ Đây là **Broken Access Control (OWASP A01)**: người dùng vượt quyền được gán. Shop Staff (view-only theo thiết kế) vẫn create/import/update/delete được catalog.

### Đây là lỗi THẬT, nhưng là loại "đã biết & hoãn", không phải regression
- Docblock `ProductPolicy` ghi thẳng: *"fine-grained role/permission checks live at a higher layer... **Re-tighten here once the role matrix is finalized**."*
- NOTES plan-040 (TJ.1): "role gradation là *aspirational* vs current flat-org policy."
→ Nhóm dev **biết** và **cố ý hoãn** việc cắm permission check vào các policy `create`. Nhưng "cố ý hoãn" ≠ "không phải lỗi" — về mặt bảo mật, đây vẫn là **lỗ hổng phân quyền thật**.

### Mức độ
- **Medium.** Giảm nhẹ vì: deployment hiện 1 org, user hiện tại đều tin cậy/org-wide. Nhưng nếu có Shop Staff thật trên production → họ sửa được catalog HQ trái quyền.

### Fix đề xuất (rẻ, idiomatic — đúng pattern đã có)
Sửa `MaterialPolicy` (và các policy create/import/update/delete tương ứng) gọi `hasPermission`, y như `ProductPolicy@approve`:
```php
public function create(User $user): bool {
    return $user->hasPermission('material.create', $this->orgId());
}
// import -> 'material.import', update -> 'material.update', delete -> 'material.delete'
```
+ Pest ma trận role (frank→403, alice→201). Vì cơ chế `hasPermission` đã có sẵn nên rủi ro thấp; chỉ cần đảm bảo các user/role seed có quyền đúng để test khác không gãy.

---

## Bảng kết luận cuối

| Nhóm case | Lỗi thật? | Loại |
|---|---|---|
| TC-078 (isolation) | **KHÔNG** | Test false-alarm (assertion tôi sai) |
| Staff create (TC-034/059/109/134/159/184/209…) | **CÓ** | Broken Access Control — RBAC chưa enforce ở MaterialPolicy@create |
| Staff import (TC-045/070/120/170/195/220…) | **CÓ** | Như trên, cho material import |

> Đính chính so với lần trước: F2 KHÔNG nên gọi là "by-design không lỗi". Đúng hơn: **lỗ hổng phân quyền có thật, đã được biết và hoãn lại** — nên fix (cơ chế đã có sẵn). Quyết định cuối thuộc về bạn/PO vì nó đổi hành vi phân quyền, nhưng về kỹ thuật đây là lỗi.
