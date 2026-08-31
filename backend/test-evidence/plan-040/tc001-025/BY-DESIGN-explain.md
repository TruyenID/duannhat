# Các case "by-design" (KHÔNG phải bug) — giải thích rõ

Trong toàn bộ các batch Material (TC-001 → TC-225), chỉ có **MỘT vấn đề duy nhất** bị xếp "by-design", lặp lại đúng **2 loại test case** ở mỗi batch. Đó là **F2 — phân quyền flat-org**.

## 2 case by-design (mỗi batch)

| Loại case | Mong đợi của test | Thực tế | Vì sao by-design |
|---|---|---|---|
| **Staff CREATE material** (`POST /hq/{brand}/materials` bằng token frank = Shop Staff) | 403 (test ghi 403/404/405/.../411 — typo) | **201** (tạo được) | Policy `create` trả `true` vô điều kiện |
| **Staff IMPORT material** (`POST /hq/{brand}/materials/import` bằng token frank) | 403 | **vào tới importer** (422 do CSV, không phải 403) | Cùng lý do — không có role-check trên import |

### Các TC cụ thể theo từng batch
| Batch | TC Staff-create | TC Staff-import |
|---|---|---|
| TC-001..025 | TC-009 | TC-020 |
| TC-026..050 | TC-034 | TC-045 |
| TC-051..075 | TC-059 | TC-070 |
| TC-076..100 | TC-084 | TC-095 |
| TC-101..125 | TC-109 | TC-120 |
| TC-126..150 | TC-134 | TC-145 |
| TC-151..175 | TC-159 | TC-170 |
| TC-176..200 | TC-184 | TC-195 |
| TC-201..225 | TC-209 | TC-220 |

## TẠI SAO by-design (không phải bug)

### 1. Code cố ý cho phép — không phải "quên check"
`app/Policies/MaterialPolicy.php`:
```php
public function create(User $user): bool
{
    return true;   // ← vô điều kiện: ai đăng nhập + thuộc org đều tạo được
}
```
Đây là viết **chủ ý** `return true`, khác hẳn lỗi do thiếu sót (như F1 quên check brand, F3 quên chặn số âm).

### 2. Hệ thống dùng mô hình "flat-org" (tổ chức phẳng)
Phân quyền đi qua 2 lớp, cả 2 **chỉ chặn ở mức ORG, không xét role**:
- **Middleware `ResolveBrandFromSlug`**: chỉ hỏi *"user có thuộc org này không"* (`role_user_pivots` tồn tại) — KHÔNG hỏi role gì.
- **`MaterialPolicy`**: `create=true`; `view/update/delete=belongsToUserOrg` (chỉ so org).

→ Ranh giới quyền thật của hệ thống là **"thành viên org"**, không phải "cấp bậc role". Có 5 role (Org Admin / Org Manager / Shop Manager / Staff / Shop Staff) nhưng với catalog material thì **không role nào bị phân biệt**. Đã kiểm bằng ma trận: **cả 6 user của cả 5 role đều tạo được (201)**.

### 3. Tài liệu plan-040 ghi rõ đây là chủ ý
NOTES/HANDOFF mục **TJ.1**: *"role gradation là **aspirational** vs current **flat-org** policy"* — tức bảng phân quyền chi tiết theo role mới là **dự kiến tương lai**, code hiện tại **cố ý** flat-org.

### 4. Cột "Expected" trong các test case còn bị typo
Test ghi 403 → 404 → 405 → ... → 411 (tăng dần qua các batch). Các mã 405–412 (Method Not Allowed, Not Acceptable, Proxy Auth, Conflict, Gone, Length Required, Precondition Failed) **vô nghĩa** với tình huống phân quyền. Mã đúng nếu muốn chặn là **403**.

## Kết luận
- **Không có gì hỏng ở code** cho 2 case này — app chạy đúng như được thiết kế (flat-org).
- Đây là **"test expectation mismatch"**: test kỳ vọng một luật phân quyền theo role mà hệ thống **cố ý không áp dụng**.

## Cần quyết (nghiệp vụ, không phải kỹ thuật)
- **(A) Giữ flat-org** → đóng 2 case, đổi Expected thành **201 / allowed**. Không đụng code.
- **(B) Siết quyền** (nếu nghiệp vụ muốn Shop Staff KHÔNG được sửa catalog HQ) → thêm role-check vào `MaterialPolicy@create`/import (vd chỉ Org Admin + Org Manager) + test ma trận role. Đây là **đổi hành vi phân quyền toàn hệ thống**, cần xác nhận role nào được phép.

---

> Lưu ý phân biệt với 2 finding ĐÃ là bug thật và ĐÃ fix:
> - **F1** (cross-brand show/update/delete) — bug isolation, **đã fix** (case "Cross-tenant show" giờ trả 404).
> - **F3** (yield_quantity âm) — bug validation, **đã fix** (case "Validate yield sai" giờ trả 422 "greater than 0").
> Hai cái này KHÁC F2: F1/F3 là thiếu sót → sửa code; F2 là lựa chọn thiết kế → chờ quyết định nghiệp vụ.
