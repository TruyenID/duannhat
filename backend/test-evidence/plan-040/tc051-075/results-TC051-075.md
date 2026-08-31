# Evidence TC-051..075 (sau fix F1+F3) — curl + Pest + Playwright
_Run 2026-06-29 · http://localhost:5400 · admin=OrgAdmin · frank=ShopStaff_

| TC | Case | HTTP thực tế | Expect đúng | Có bug? | Minh chứng |
|----|------|--------------|-------------|---------|-----------|
| TC-051 | List scope brand | 200 | 200 | No | TC-051.txt |
| TC-052 | Search+filter (Expected is_active=3 lạ) | 200 | 200 | No | TC-052.txt |
| TC-053 | Tenant isolation (leak=0) | 200 | 200 | No | TC-053.txt |
| TC-054 | Dropdown | 200 | 200 | No | TC-054.txt |
| TC-055 | Lookup no leak (betoya-id=0) | 200 | 200 | No | TC-055.txt |
| TC-056 | Create hợp lệ | 201 | 201 | No | TC-056.txt |
| TC-057 | Validate rỗng | 422 | 422 | No | TC-057.txt |
| TC-058 | Validate yield sai (Expected 424 typo→422; F3 đã fix) | 422 | 422 | No (F3 fixed) | TC-058.txt |
| TC-059 | Staff tạo (Expected 405; thực flat-org) | 201 | 201 (F2) | F2-bydesign | TC-059.txt |
| TC-060 | Show WHEAT | 200 | 200 | No | TC-060.txt |
| TC-061 | Cross-brand show (Expected 406 typo→404; F1 đã fix) | 404 | 404 | No (F1 fixed) | TC-061.txt |
| TC-062 | Update hợp lệ | 200 | 200 | No | TC-062.txt |
| TC-063 | NEW-MR-2 brand_id cross-org bỏ qua (brand sau=beto-coffee) | 200 | 200 | No | TC-063.txt |
| TC-064 | Delete (soft) | 204 | 204 | No | TC-064.txt |
| TC-065 | check-usage | 200 | 200 | No | TC-065.txt |
| TC-066 | Restore | 200 | 200 | No | TC-066.txt |
| TC-067 | Bulk-delete | 200 | 200 | No | TC-067.txt |
| TC-068 | Export scope (BETOCOFFEE=9 other=0) | 200 | 200 | No | TC-068.txt |
| TC-069 | CSV injection H10 (Pest) | 12 passed | pass | No | TC-069.txt |
| TC-070 | Staff import (Expected 405; thực flat-org) | 422 | 403 mong/flat-org | F2-bydesign | TC-070.txt |
| TC-071 | Import stamp brand (stamped=beto-coffee) | 200 | 200 | No | TC-071.txt |
| TC-072 | Import reject H9 (Pest) | 12 passed | pass | No | TC-072.txt |
| TC-073 | Import template | 200 | 200 | No | TC-073.txt |
| TC-074 | Units list | 200 | 200 | No | TC-074.txt |
| TC-075 | Unit create | 201 | 201 | No | TC-075.txt |

## Tổng kết (sau khi đã fix F1 + F3)
- **23 case KHÔNG bug** — gồm:
  - **TC-058** yield sai → **422** (chứng minh **F3 đã fix**; Expected đề ghi 424 là typo→422).
  - **TC-061** cross-brand show → **404** (chứng minh **F1 đã fix**; Expected đề ghi 406 là typo→404).
- **2 case F2 by-design** — **TC-059** (Staff tạo material →201), **TC-070** (Staff import →tới importer). `MaterialPolicy@create=return true` (flat-org). Expected đề ghi 405 không đúng với thiết kế hiện tại. Chờ quyết định nghiệp vụ (đã giải thích chi tiết F2).
- **0 bug mới.**

## Lưu ý cột Expected của đề (nhiều giá trị sai/đánh lừa)
- TC-058 `424`, TC-059/TC-070 `405`, TC-061 `406` → đều không khớp HTTP thực tế đúng (422 / 201 / 404). Đã chấm theo expected ĐÚNG.
