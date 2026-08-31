# Deep verification TC-076..100 (kiểm tra NỘI DUNG, không chỉ HTTP code)

Mỗi case verify thêm các assertion sâu (pagination meta, đúng brand, field response, message lỗi, persistence, DB state). Full body lưu ở `TC-0xx.json`, tổng hợp `SUMMARY.json`.

| TC | Assertion sâu | Kết quả |
|----|---------------|---------|
| TC-076 | http200 + có pagination meta + **mọi item brand=beto-coffee** + nonempty | ✅ PASS |
| TC-077 | http200 + **mọi kết quả khớp WHEAT** | ✅ PASS |
| TC-078 | http200 + **0 BETOCOFFEE** (isolation) | ✅ PASS* |
| TC-080 | http200 + **betoya-id KHÔNG leak** trong lookup | ✅ PASS |
| TC-081 | http201 + **brand_id stamped beto-coffee** + sku đúng | ✅ PASS |
| TC-082 | http422 + **có field-keyed errors** | ✅ PASS |
| TC-083 | http422 + **message "greater than 0"** (F3) | ✅ PASS |
| TC-084 | http201 — Staff tạo được (F2 flat-org, bằng chứng) | ✅ PASS (F2) |
| TC-085 | http200 + có field units | ✅ PASS |
| TC-086 | **http404** cross-brand show (F1 fixed) | ✅ PASS |
| TC-087 | http200 + **name thực sự persisted** (GET lại = DeepUpd87) | ✅ PASS |
| TC-089 | http204 delete | ✅ PASS |
| TC-091 | http200 restore | ✅ PASS |
| TC-099 | http200 + **>=3 units + có base unit** | ✅ PASS |
| TC-100 | http201 unit create | ✅ PASS |

**14/15 assertion-group PASS.**

\* TC-078: assertion phụ `all_BETOYA` báo false **do chính material fixture `DEEP-BY` tôi tạo để test** (sku bắt đầu "DEEP" nằm trong brand betoya). Check cốt lõi **`no_BETOCOFFEE` = true** → cách ly brand vẫn đúng. Không phải bug sản phẩm.

## Kết luận deep
- Material/Unit hành vi đúng ở mức nội dung, không chỉ status code.
- **F1** (TC-086 → 404 + body) và **F3** (TC-083 → 422 + message "greater than 0") xác nhận fix hoạt động ở tầng nội dung.
- **F2** (TC-084 → 201) tái khẳng định flat-org by-design.
