# Re-test 2 findings — ma tran role + cross-brand (data sach)
_Run 2026-06-29 · backend $BASE · material sach moi brand · token 6 role_

## F2 — Authz CREATE material HQ (POST /hq/beto-coffee/materials), body hop le
| Role | HTTP | Ky vong(neu phan tang) | Ket luan |
|------|------|------------------------|----------|
| OrgAdmin | 201 | 403 (tru OrgAdmin/Mgr) | TAO DUOC |
| OrgManager | 201 | 403 (tru OrgAdmin/Mgr) | TAO DUOC |
| ShopManager | 201 | 403 (tru OrgAdmin/Mgr) | TAO DUOC |
| Staff | 201 | 403 (tru OrgAdmin/Mgr) | TAO DUOC |
| ShopStaff | 201 | 403 (tru OrgAdmin/Mgr) | TAO DUOC |
| ShopMgrYkh | 201 | 403 (tru OrgAdmin/Mgr) | TAO DUOC |

## F1 — Cross-brand SHOW/UPDATE (resource brand khac route brand)
| Actor | Action | URL | HTTP | Ky vong | Ket luan |
|-------|--------|-----|------|---------|----------|
| OrgAdmin | show | /hq/betoya/materials/{betoya} | 200 | 200 | control-dung |
| OrgAdmin | show | /hq/beto-coffee/materials/{betoya} | 200 | 404 | LEAK |
| OrgAdmin | show | /hq/beto-coffee/materials/{beto-kitchen} | 200 | 404 | LEAK |
| OrgAdmin | update | /hq/beto-coffee/materials/{betoya} | 200 | 404 | CROSS-WRITE! |
| ShopMgr(ykh) | show | /hq/beto-coffee/materials/{betoya} | 200 | 403/404 | LEAK(shop-scoped doc duoc betoya) |

## Kết luận sau khi test lại đúng chuẩn

### F1 — XÁC NHẬN BUG THẬT (brand isolation hở ở show/update/delete)
- Ma trận chứng minh:
  - `GET /hq/beto-coffee/materials/{betoya}` → **200** (đọc xuyên brand)
  - `GET /hq/beto-coffee/materials/{beto-kitchen}` → **200** (đọc xuyên brand)
  - `PUT /hq/beto-coffee/materials/{betoya} {yield_quantity:777}` → **200**, DB betoya đổi **1000 → 777** (GHI xuyên brand)
  - **ShopMgr(ykh)** — user chỉ thuộc shop ykh/beto-coffee — vẫn `GET {betoya}` → **200**
  - Control: `GET /hq/betoya/materials/{betoya}` → 200 (đúng)
- **Gốc rễ (code):** `MaterialController@show/update/destroy` chỉ gọi `authorizeOrganization()` + `MaterialPolicy@view/update/delete = belongsToUserOrg()` → **chỉ so organization_id, không bao giờ so material.brand_id với {brandSlug} trên route**. Route-model-binding `Material $material` resolve theo id toàn cục.
- **Tác động:** trong cùng 1 org, đứng ở brand A đọc/sửa/xoá được resource brand B. Đề xuất fix: thêm guard `abort_unless($material->brand_id === $routeBrand->id, 404)` (hoặc scopeBindings brand) cho show/update/delete (và rà các resource HQ khác dùng cùng pattern).

### F2 — KHÔNG phải bug per-role; là FLAT-ORG có chủ đích
- Ma trận: **cả 6 role** (OrgAdmin/OrgManager/ShopManager/Staff/ShopStaff/ShopMgr-ykh) đều **201** khi tạo material HQ.
- **Gốc rễ (code):** `MaterialPolicy@create = return true;` (vô điều kiện).
- Khớp ghi chú plan-040 (TJ.1: "role gradation là aspirational vs current flat-org policy"). → Đây là **quyết định nghiệp vụ**: nếu muốn chặn Shop Staff sửa catalog HQ thì cần thêm role-check vào policy; còn theo thiết kế hiện tại là chấp nhận.
