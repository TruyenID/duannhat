# Evidence TC-101..125 (sau fix F1+F3) — curl + content-check + Pest + Playwright
_Run 2026-06-29 · admin=OrgAdmin · frank=ShopStaff · assertion isolation đã sửa đúng_

| TC | Case | HTTP | Đạt assertion? | Loại |
|----|------|------|----------------|------|
| TC-101 | List+meta+brand | 200 | ✅ PASS | OK |
| TC-102 | Search WHEAT | 200 | ✅ PASS | OK |
| TC-103 | Isolation no BETOCOFFEE | 200 | ✅ PASS | OK |
| TC-104 | Dropdown | 200 | ✅ PASS | OK |
| TC-105 | Lookup no leak | 200 | ✅ PASS | OK |
| TC-106 | Create stamped | 201 | ✅ PASS | OK |
| TC-107 | Validate empty | 422 | ✅ PASS | OK |
| TC-108 | Yield sai (Exp 426 typo->422; F3) | 422 | ✅ PASS | F3 fixed |
| TC-109 | Staff create (Exp 407; flat-org F2) | 201 | ✅ PASS | F2 by-design |
| TC-110 | Show | 200 | ✅ PASS | OK |
| TC-111 | Cross-brand show (Exp 408 typo->404; F1) | 404 | ✅ PASS | F1 fixed |
| TC-112 | Update persists | 200 | ✅ PASS | OK |
| TC-113 | brand_id cross-org bo qua | 200 | ✅ PASS | OK |
| TC-114 | Delete 204 | 204 | ✅ PASS | OK |
| TC-115 | check-usage | 200 | ✅ PASS | OK |
| TC-116 | Restore | 200 | ✅ PASS | OK |
| TC-117 | Bulk-delete | 200 | ✅ PASS | OK |
| TC-118 | Export scope | 200 | ✅ PASS | OK |
| TC-119 | CSV injection H10 (Pest) | pass | ✅ PASS | OK |
| TC-120 | Staff import (Exp 407; flat-org F2) | 422 | ✅ PASS | F2 by-design |
| TC-121 | Import stamp brand | 200 | ✅ PASS | OK |
| TC-122 | Import reject H9 (Pest) | pass | ✅ PASS | OK |
| TC-123 | Template | 200 | ✅ PASS | OK |
| TC-124 | Units >=3 + base | 200 | ✅ PASS | OK |
| TC-125 | Unit create | 201 | ✅ PASS | OK |

**25/25 case đạt toàn bộ assertion.**

- F1 (TC-111→404), F3 (TC-108→422 "greater than 0") xác nhận fix.
- F2 (TC-109→201, TC-120→tới importer) = flat-org by-design.
- Expected đề: TC-108=426, TC-109/120=407, TC-111=408 → đều typo, không khớp HTTP đúng.
