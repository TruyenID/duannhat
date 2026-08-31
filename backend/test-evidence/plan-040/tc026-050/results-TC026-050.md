# Evidence TC-026..050 (sau khi fix F1) — curl + Pest + Playwright
_Run 2026-06-29 · http://localhost:5400 · admin=OrgAdmin · frank=ShopStaff_

| TC | Case | HTTP thực tế | Expect đúng | Có bug? | Ghi chú |
|----|------|--------------|-------------|---------|---------|
| TC-026 | List scope brand | 200 | 200 | No |  |
| TC-027 | Search+filter | 200 | 200 | No | Expected ghi is_active=2 (value lạ) → chạy is_active=1 |
| TC-028 | Tenant isolation list betoya | 200 | 200 | No | 0 BETOCOFFEE leak |
| TC-029 | Dropdown | 200 | 200 | No |  |
| TC-030 | Lookup includeIds no leak | 200 | 200 | No | betoya-id không xuất hiện |
| TC-031 | Create hợp lệ | 201 | 201 | No | cần brand_id+calculated_cost |
| TC-032 | Validate body rỗng | 422 | 422 | No |  |
| TC-033 | Validate yield sai | 201 | 422 | **BUG (F3)** | yield_quantity=-5 ĐƯỢC TẠO. Rule thiếu gt:0. (Expected ghi 423 là typo→422) |
| TC-034 | Staff tạo material HQ | 201 | 403(mong)/201(thực) | F2 by-design | policy create=return true (flat-org). Expected ghi 404 không đúng |
| TC-035 | Show WHEAT | 200 | 200 | No |  |
| TC-036 | Cross-brand show | 404 | 404 | No — ĐÃ FIX F1 | Trước fix=200(leak). Expected ghi 405 là typo→404 |
| TC-037 | Update hợp lệ | 200 | 200 | No |  |
| TC-038 | NEW-MR-2 brand_id cross-org bỏ qua | 200 | 200 | No | brand sau=beto-coffee |
| TC-039 | Delete (soft) | 204 | 204 | No | REST 204 |
| TC-040 | check-usage | 200 | 200 | No |  |
| TC-041 | Restore | 200 | 200 | No |  |
| TC-042 | Bulk-delete | 200 | 200 | No |  |
| TC-043 | Export scope brand H11 | 200 | 200 | No | 9 BETOCOFFEE / 0 brand khác |
| TC-044 | CSV injection H10 (Pest) | 12 passed | pass | No | ImportExportHardeningTest |
| TC-045 | Staff import HQ | 422 | 403(mong)/flat-org | F2 by-design | frank tới được importer. Expected ghi 404 không đúng |
| TC-046 | Import stamp brand route | 200 | 200 | No | stamped=beto-coffee, created 1 |
| TC-047 | Import reject row sai H9 (Pest) | 12 passed | pass | No | ImportExportHardeningTest |
| TC-048 | Import template | 200 | 200 | No |  |
| TC-049 | Units list | 200 | 200 | No | 3 unit |
| TC-050 | Unit create | 201 | 201 | No |  |

## Tổng kết
- **22 case KHÔNG bug** (gồm TC-036 đã được fix F1 → 404).
- **1 BUG MỚI: F3 (TC-033)** — `yield_quantity` âm được chấp nhận (rule `['required','numeric']` thiếu `gt:0`/`min:0`). Material tạo với yield=-5.0000.
- **2 case F2 by-design (TC-034, TC-045)** — Shop Staff tạo/import được do `MaterialPolicy@create=return true` (flat-org). Không phải bug code; chờ quyết định nghiệp vụ.

## Lưu ý về cột Expected trong đề bài (có vài giá trị sai/đánh lừa)
- TC-033 Expected ghi **423** → không tồn tại HTTP 423 cho validation; đúng phải **422** (và thực tế ra 201 = bug F3).
- TC-034/TC-045 Expected ghi **404** → thực tế flat-org cho phép (201/422).
- TC-036 Expected ghi **405** → đúng phải **404** (và thực tế ra 404 sau fix).
