# Evidence — Plan-040 Material TC-001…025 (executed)

- **Backend:** `http://localhost:5400/api/v1`  · DB `tempo` (docker)
- **Tooling:** curl (API) + Pest (`ImportExportHardeningTest`) + Playwright (UI, see `ui/`)
- **Auth:** admin = `admin@famgia.com` (Org Admin) · frank = `frank@demo.test` (**Shop Staff**, low-priv)
- **Run date:** 2026-06-29
- Per-TC raw request/response in `TC-0xx.evidence.txt` / `TC-0xx.response.json`. Export/template/import payloads saved as `.csv`.

## Kết quả

| TC | Case | HTTP | Expect | Verdict | Ghi chú |
|----|------|------|--------|---------|---------|
| TC-001 | List materials (scope brand) | 200 | 200 | ✅ PASS | trả material beto-coffee + pagination |
| TC-002 | Search + filter (`search=WHEAT&is_active=1`) | 200 | 200 | ✅ PASS | |
| TC-003 | Tenant isolation (list betoya) | 200 | 200 | ✅ PASS | body = **12 BETOYA / 0 BETOCOFFEE** → list scope đúng |
| TC-004 | Dropdown | 200 | 200 | ✅ PASS | (bạn đánh "không cần thiết" — endpoint vẫn hoạt động) |
| TC-005 | Lookup includeIds cross-brand | 200 | 200 | ✅ PASS | betoya id **không** xuất hiện trong kết quả (no leak — NEW-MR-7) |
| TC-006 | Create material hợp lệ | 201 | 201 | ✅ PASS | **bắt buộc** `brand_id` + `calculated_cost` trong body |
| TC-007 | Validate body rỗng | 422 | 422 | ✅ PASS | lỗi từng field required |
| TC-008 | Validate yield sai | 422 | 422 | ✅ PASS | |
| TC-009 | Authz — Staff không tạo được | **201** | 403 | ⚠️ **FINDING** | frank (Shop Staff) **TẠO ĐƯỢC** material HQ (row `TC9-*` đã vào DB). Xem Findings. |
| TC-010 | Show material | 200 | 200 | ✅ PASS | |
| TC-011 | Cross-tenant show → 404 | **200** | 404 | ⚠️ **FINDING** | `GET /hq/beto-coffee/materials/{id-betoya}` trả **material betoya**. Show không scope brand. |
| TC-012 | Update hợp lệ | 200 | 200 | ✅ PASS | |
| TC-013 | NEW-MR-2 chặn brand_id cross-org | 200 | 200 | ✅ PASS | sau PUT brand_id=betoya → material **vẫn beto-coffee** (client brand_id bị bỏ qua) |
| TC-014 | Delete (soft) | 204 | 200→**204** | ✅ PASS | REST chuẩn trả 204 No Content |
| TC-015 | check-usage trước khi xóa | 200 | 200 | ✅ PASS | |
| TC-016 | Restore | 200 | 200 | ✅ PASS | |
| TC-017 | Bulk-delete | 200 | 200 | ✅ PASS | tạo 2 → bulk-delete OK |
| TC-018 | Export scope brand (H11) | 200 | 200 | ✅ PASS | CSV = **9 BETOCOFFEE / 0 brand khác** |
| TC-019 | Export chống CSV injection (H10) | — | pass | ✅ PASS | Pest `ImportExportHardeningTest`: **12 passed** |
| TC-020 | Import 403 khi thiếu quyền (C6) | **422** | 403 | ⚠️ **FINDING** | frank **tới được** importer (chạy xử lý, chỉ lỗi CSV header) → authz import không chặn Shop Staff |
| TC-021 | Import bỏ qua brand_id client | 200 | — | ✅ PASS | material import **stamp beto-coffee** (route brand); template không có cột brand_id |
| TC-022 | Import row sai reject qua service (H9) | — | pass | ✅ PASS | Pest `ImportExportHardeningTest` (chung TC-019) |
| TC-023 | Import template | 200 | 200 | ✅ PASS | header: `id,sku,name,description,yield_quantity,yield_unit,calculated_cost,is_active` |
| TC-024 | Units list | 200 | 200 | ✅ PASS | 3 unit (g/kg/bag_25kg) |
| TC-025 | Unit create | 201 | 201 | ✅ PASS | |

**Tổng: 22 PASS · 3 FINDING (TC-009, TC-011, TC-020).**

## Findings (cần review)

### F1 — TC-011: Show material rò rỉ cross-brand (nên xem trước)
`GET /api/v1/hq/beto-coffee/materials/{material-của-betoya}` → **200** trả đúng material betoya.
- **List** scope brand đúng (TC-003) nhưng **Show** chỉ scope **org**, không scope **brand** ⇒ ở context brand A vẫn xem được resource brand B (cùng org Famgia).
- Bằng chứng: `TC-011.response.json` → `sku=BETOYA-DEMO-PANCAKE-MIX, brand_id=…(betoya)`.

### F2 — TC-009 / TC-020: Authz create/import không chặn Shop Staff
frank@demo.test (Shop Staff) **tạo material** (201) và **gọi import** (chạy importer) ở HQ brand level.
- Khớp ghi chú plan-040 (NOTES/HANDOFF): policy hiện **flat-org** — "role gradation là aspirational vs current flat-org policy" (TJ.1). Mọi thành viên org có quyền này.
- Nếu nghiệp vụ yêu cầu Shop Staff KHÔNG sửa catalog HQ → gap authz cần siết (MaterialPolicy@create/import).

> Chỉ có **1 org "Famgia"** chứa cả 3 brand; admin/frank đều org-wide. Nên F1/F2 là **brand-level isolation/authz**, không phải org-level leak.

## Lưu ý kỹ thuật phát hiện khi chạy
- Validation chạy **trước** authz: request thiếu field trả 422 bất kể role → muốn test 403 phải gửi body hợp lệ.
- Material `create` yêu cầu `brand_id` + `calculated_cost` (dù route đã có brandSlug).
- DELETE trả **204** (không phải 200).

## Cleanup
Material/unit test (`TC6-* TC9-* TC17* TC21-* sack_tc025`) đã xóa; tên WHEAT-FLOUR đã revert. Reservations/fixtures plan-040 giữ nguyên cho các TC sau.
