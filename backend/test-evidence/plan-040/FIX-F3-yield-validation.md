# FIX — F3: material `yield_quantity` chấp nhận giá trị âm

**Branch:** `fix/hq-brand-scope-isolation` (cùng branch fix QA plan-040)

## Vấn đề (QA TC-033)
`POST /hq/{brand}/materials {yield_quantity:-5}` → **201**; DB lưu `yield_quantity=-5.0000`.
Rule schema chỉ `['required','numeric']` (store) / `['sometimes','numeric']` (update) → **thiếu chặn âm/0**. Yield âm làm sai cost & tính toán sản xuất.

## Fix
Override rule trong request KHÔNG-generated (an toàn, không đụng file Omnify):
- `app/Http/Requests/MaterialStoreRequest.php`: `yield_quantity => ['required','numeric','gt:0']`
- `app/Http/Requests/MaterialUpdateRequest.php`: `yield_quantity => ['sometimes','numeric','gt:0']`

## Bằng chứng SAU FIX (curl live :5400)
| Input | Trước | Sau |
|---|---|---|
| yield_quantity = -5 | 201 (lưu -5) | **422** "must be greater than 0" |
| yield_quantity = 0  | 201 | **422** |
| yield_quantity = 10 (control) | 201 | **201** |

## Test
- `tests/Feature/Product/MaterialCrudTest.php` +3 case (store âm/0 → 422, update âm → 422 + DB không đổi).
- Regression: MaterialCrud + MaterialYieldValidation + BrandScopeIsolation + ImportExportHardening — all green.
- `pint --dirty` clean.
