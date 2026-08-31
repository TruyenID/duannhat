# Test Evidence — Plan-040 (Material domain QA)

Bằng chứng test thực thi (curl + Pest + Playwright) cho domain Material, qua 11 batch TC-001 → TC-273.

## File tổng hợp (đọc 2 file này trước)

| File | Nội dung |
|---|---|
| **`SUMMARY-all-testcases.csv`** | **273 test case**, 1 dòng/TC: endpoint, expected đúng, HTTP khi test, finding, có-bug, đã-fix, HTTP-sau-fix, đường dẫn minh chứng. |
| **`SUMMARY-findings.csv`** | **5 finding** (1 dòng/finding): mô tả, gốc rễ, cách fix, HTTP trước/sau, test tự động, danh sách TC liên quan. |

## Kết quả tổng

- **273 test case** · **44 có bug** (F1/F3/F2-create/F2-import, mỗi loại 11 TC) · **229 không bug**.
- **Tất cả 44 bug đã FIX + verify live + test tự động** (branch `fix/hq-brand-scope-isolation`, **chưa commit**).

## 5 finding

| Finding | Loại | Bug thật? | Đã fix? | HTTP trước→sau |
|---|---|---|---|---|
| **F1** | Cross-brand isolation (show/update/delete xuyên brand) | Có | ✅ | 200 → 404 |
| **F3** | Yield validation (nhận yield âm/0) | Có | ✅ | 201 → 422 |
| **F2-create** | Broken Access Control — Shop-tier tạo material | Có | ✅ | 201 → 403 |
| **F2-import** | Broken Access Control — Shop-tier import material | Có | ✅ | 422 → 403 |
| **TC-078** | Isolation false-alarm (lỗi assertion test, không phải app) | Không | NA | 200 (đúng) |

## File fix (chi tiết kỹ thuật + bằng chứng)

- `FIX-F1-brand-isolation.md`
- `FIX-F3-yield-validation.md`
- `FIX-F2-rbac-create-import.md`
- `VERDICT-F2-recheck.md` — phân tích lật lại F2 từ "by-design" sang "bug thật" (có RBAC, Shop Staff thiếu quyền).

## Cấu trúc thư mục batch

```
tc001-025/  … tc251-273/   (11 batch)
  results-TC0xx-0yy.md       bảng verdict batch
  TC-0xx.json | TC-0xx.txt   request/response thô từng case
  TC-0xx.export.csv          payload export thật
  ui/materials-route.png     Playwright (admin-web redirect SSO login)
tc001-025/results-findings-retest.md   ma trận role + cross-brand (chứng minh F1/F2)
tc076-100/deep/                deep content-check (verify nội dung, không chỉ HTTP)
```

## Lưu ý đọc kết quả

- Cột **Expected** trong các sheet gốc của bạn có giá trị typo (403→404→…→414 tăng dần qua batch; 422→423→…→432). CSV này dùng **Expected đúng** (403/404/422) + **HTTP thực tế** đo được.
- UI admin-web đăng nhập **chỉ qua SSO** → Playwright dừng ở trang login; tầng API mà UI gọi đã verify đầy đủ bằng curl.
- HTTP "khi test" phản ánh đúng thời điểm chạy batch đó: ví dụ F1 batch đầu = 200 (lúc chưa fix), các batch sau = 404 (sau fix); F3 batch tc026-050 = 201 (lúc phát hiện), sau đó = 422.
