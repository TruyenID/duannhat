# Evidence TC-076..100 (sau fix F1+F3) — curl + Pest + Playwright
_Run 2026-06-29 · http://localhost:5400 · admin=OrgAdmin · frank=ShopStaff_

| TC | Case | HTTP thực tế | Expect đúng | Có bug? |
|----|------|------|------|------|
| TC-076 | List scope brand | 200 | 200 | No |
| TC-077 | Search+filter | 200 | 200 | No |
| TC-078 | Tenant isolation (leak=0) | 200 | 200 | No |
| TC-079 | Dropdown | 200 | 200 | No |
| TC-080 | Lookup no leak (0) | 200 | 200 | No |
| TC-081 | Create | 201 | 201 | No |
| TC-082 | Validate rỗng | 422 | 422 | No |
| TC-083 | Yield sai (Exp 425 typo→422; F3 fixed) | 422 | 422 | No (F3) |
| TC-084 | Staff tạo (Exp 406; flat-org) | 201 | 201(F2) | F2-bydesign |
| TC-085 | Show | 200 | 200 | No |
| TC-086 | Cross-brand show (Exp 407 typo→404; F1 fixed) | 404 | 404 | No (F1) |
| TC-087 | Update | 200 | 200 | No |
| TC-088 | brand_id cross-org bỏ qua (sau=beto-coffee) | 200 | 200 | No |
| TC-089 | Delete | 204 | 204 | No |
| TC-090 | check-usage | 200 | 200 | No |
| TC-091 | Restore | 200 | 200 | No |
| TC-092 | Bulk-delete | 200 | 200 | No |
| TC-093 | Export (BETOCOFFEE=9 other=0) | 200 | 200 | No |
| TC-094 | CSV injection H10 (Pest) | 12 passed | pass | No |
| TC-095 | Staff import (Exp 406; flat-org) | 422 | 403mong/flat-org | F2-bydesign |
| TC-096 | Import stamp (stamped=beto-coffee) | 200 | 200 | No |
| TC-097 | Import reject H9 (Pest) | 12 passed | pass | No |
| TC-098 | Template | 200 | 200 | No |
| TC-099 | Units list | 200 | 200 | No |
| TC-100 | Unit create | 201 | 201 | No |

## Tổng kết
- **23 không bug** — gồm **TC-083** (yield sai→422, F3 đã fix) và **TC-086** (cross-brand→404, F1 đã fix).
- **2 F2 by-design** — TC-084 (Staff tạo→201), TC-095 (Staff import→tới importer).
- **0 bug mới.** Batch này **trùng hoàn toàn** TC-051..075 (cùng endpoint, cùng kết quả).
- Expected đề có typo: TC-083=425, TC-084/095=406, TC-086=407 → không khớp HTTP đúng (422/201/404).
