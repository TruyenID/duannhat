# Báo cáo lỗi — Tính năng Thuế tiêu thụ Nhật (plan-043)

> ## ⚠️ SUPERSEDED IN PART — read this before trusting anything below
>
> The **two-rate tax type** (`rate_dine_in` / `rate_takeaway` chosen by
> `order_type`) was **removed on 2026-07-26 (#1099)**. A tax type is ONE rate;
> the MENU decides the consumption context. Every mention of it below is a
> record of what was built, **not an instruction**.
>
> Still true and still shipped: immutable per-line snapshots, rounding ONCE per
> rate group (インボイス), 総額表示 mode, service-charge rate, per-rate output,
> the workstation Go engine.
>
> Current truth: [`docs/guide/tax-types.md`](../../docs/guide/tax-types.md).

**Ngày:** 2026-07-10 · **Nhánh:** `feature/plan-043-tax-types` · **Người test:** QA (curl + Playwright + Pest/Go/vitest)
**Phạm vi:** backend · admin-web · pos-web · customer-web · workstation-app (Go)
**Phương pháp:** ~150 kiểm thử sống (curl API) + Playwright UI (headless) + 3 bộ test tự động (Pest 435 / Go 7 gói / vitest)

## Tóm tắt

Tìm thấy **10 lỗi**: **5 nghiêm trọng (sai tiền/thuế)**, **2 cao (sai hiển thị dữ liệu)**, **2 trung bình**, **1 thấp**.
Lõi tính thuế (engine) **đúng từng yên** trên cả 3 runtime (PHP/Go/TS). Đa số lỗi là "thao tác sửa đơn hàng không được nối lại vào engine tính thuế".

> **✅ CẬP NHẬT 2026-07-10 — ĐÃ FIX 9/10 lỗi** (BUG-1/2/3/4/6/7/8/9/10), mỗi lỗi 1 commit + test regression khóa lại. **BUG-5 chờ quyết định phân quyền** (xem chi tiết). Kiểm chứng: battery sống 16/16 pass; Pest thuế **315 pass**; regression rộng **1842 pass** (1 fail device pre-existing, không liên quan). **BUG-10 (khuyến mãi % sai 100 lần)** phát hiện + fix cuối ngày, kiểm chứng sống trên LAN. Chi tiết commit ở cột "Trạng thái".

| # | Mức độ | Tên lỗi | App ảnh hưởng | Ảnh hưởng tiền? | Trạng thái |
|---|--------|---------|---------------|-----------------|------------|
| 1 | 🔴 Nghiêm trọng | Chế độ "giá đã gồm thuế" (総額表示) không hoạt động | backend (toàn hệ thống) | CÓ | ✅ Đã fix (`d199bdb6`) |
| 2 | 🔴 Nghiêm trọng | 6 thao tác sửa đơn qua workstation không tính lại thuế | backend + workstation | CÓ | ✅ Đã fix (`6cf39efc`) |
| 7 | 🔴 Nghiêm trọng | Đơn qua workstation bỏ qua thuế ghi đè ở menu | backend + workstation | CÓ | ✅ Đã fix (`6cf39efc`) |
| 8 | 🔴 Nghiêm trọng | Dòng đơn cũ thiếu snapshot bị tính 0% thuế | backend | CÓ | ✅ Đã fix (`d199bdb6`) |
| 9 | 🟠 Cao | Sau khi cài đặt (seed), tên loại thuế bị mất | admin-web (bản cài mới) | Không | ✅ Đã fix (`e173635e`) |
| 3 | 🟠 Cao | Trang cấu hình không hiển thị lại 3 trường thuế đã lưu | admin-web + backend | Không | ✅ Đã fix (`76426b3c`) |
| 4 | 🟡 Trung bình | Gửi sai định dạng tên → server sập (500) thay vì báo lỗi | backend | Không | ✅ Đã fix (`2b84f565`) |
| 5 | 🟡 Trung bình | Nhân viên cửa hàng tạo được loại thuế cấp HQ | backend (phân quyền) | Không | ⏸️ **Chờ quyết định** (xem dưới) |
| 6 | ⚪ Thấp | Tài liệu API còn mô tả cột đã bị xóa | backend (docs) | Không | ✅ Đã fix (`76426b3c`) |
| 10 | 🔴 Nghiêm trọng | Khuyến mãi % giảm sai 100 lần (basis-points thay vì %) | workstation (POS LAN) | CÓ | ✅ Đã fix (`6b24958`) |

### ⏸️ BUG-5 — vì sao CHƯA fix (cần sếp quyết)
Nhân viên phạm vi cửa hàng tạo được loại thuế cấp HQ vì chính sách phân quyền **cả họ danh mục** (product-types, categories… đều vậy) chỉ kiểm tra "thuộc tổ chức", không kiểm tra vai trò HQ. Đây **không phải lỗi riêng plan-043** — nếu chỉ siết riêng loại thuế sẽ **lệch/không nhất quán** với toàn bộ danh mục, và có rủi ro chặn nhầm luồng hợp lệ. Cần quyết định sản phẩm: **(A)** siết phân quyền cho cả họ danh mục (đúng nhưng phạm vi rộng, cần test lại toàn bộ), hay **(B)** chấp nhận hiện trạng. Tôi cố ý **không tự đổi phân quyền toàn hệ thống** để tránh gây lỗi diện rộng.

---

## Chi tiết

### 🔴 BUG-1 — Chế độ "giá đã gồm thuế" (総額表示) không hoạt động
- **Hiện tượng:** Bật cấu hình "giá đã gồm thuế" ở Settings không có tác dụng — đơn hàng vẫn tính theo kiểu cộng thuế lên trên.
- **Nguyên nhân:** Không đường tạo đơn nào ghi cờ `is_tax_included` xuống đơn hàng; đoạn code dự phòng đọc cấu hình branch là code chết.
- **Hậu quả:** Nhà hàng dùng mô hình "giá niêm yết đã gồm thuế" sẽ tính sai toàn bộ hóa đơn.
- **Bằng chứng:** Bật include → đơn ¥1.630 (cộng thuế) thay vì ¥1.500 (giá gồm) + thuế nội ¥119.

### 🔴 BUG-2 — 6 thao tác sửa đơn qua workstation không tính lại thuế
- **Hiện tượng:** Đổi loại đơn (ăn tại quán ↔ mang về), sửa số lượng, hủy món, thanh toán, áp mã giảm giá, đổi thành phần combo — đều **không** kích hoạt tính lại thuế phía Cloud.
- **Nguyên nhân:** Chỉ thao tác "thêm món" mới gọi hàm tính lại; 6 thao tác còn lại ghi thẳng, bỏ qua engine.
- **Hậu quả:** Mọi đơn LAN bị sửa sau khi thêm món → **số tiền/thuế phía Cloud sai vĩnh viễn** → báo cáo doanh thu, Z-report, hóa đơn VAT đọc sai.
- **Bằng chứng:** Sửa số lượng 1→3: dòng thành tiền ¥3.000 nhưng thuế kẹt ¥80, tổng đơn kẹt ¥1.080. Áp mã ¥500: thuế kẹt ¥440 (đúng phải ¥396), tổng sai +¥544.

### 🔴 BUG-7 — Đơn qua workstation bỏ qua thuế ghi đè ở menu
- **Hiện tượng:** Khi một món được gán thuế riêng ở cấp Menu (ghi đè cấp Sản phẩm), đơn tạo qua workstation vẫn lấy thuế cấp Sản phẩm.
- **Hậu quả:** Máy POS (LAN) và Cloud tính **hai mức thuế khác nhau** cho cùng một món → lệch số liệu.
- **Bằng chứng:** Ghi đè menu thành 10% → đơn qua workstation vẫn stamp 8%.

### 🔴 BUG-8 — Dòng đơn cũ thiếu snapshot bị tính 0% thuế
- **Hiện tượng:** Đơn tạo trước khi triển khai tính năng (chưa có snapshot thuế) mà bị sửa sau khi triển khai → các dòng cũ được tính **0% thuế**.
- **Nguyên nhân:** Cơ chế "tự động dán lại thuế" mà thiết kế hứa hẹn chưa được lập trình.
- **Hậu quả:** Thu **thiếu thuế** trên các đơn chuyển tiếp. (Giảm nhẹ được nếu chạy lệnh backfill đúng lúc triển khai.)
- **Bằng chứng:** Đơn ¥1.300 chỉ ra thuế ¥24 (đáng lẽ ¥104).

### 🟠 BUG-9 — Sau khi seed, tên loại thuế bị mất
- **Hiện tượng:** Trên bản cài mới (`migrate:fresh --seed`), màn "Loại thuế" hiện 3 dòng nhưng **trống cột tên**.
- **Nguyên nhân:** Trình seed tắt "model event" để chạy nhanh, nhưng thư viện đa ngôn ngữ (Astrotomic) lại lưu tên qua chính event đó → tên bị nuốt.
- **Hậu quả:** Mọi môi trường dev/demo/staging mới đều thiếu tên loại thuế. **Không ảnh hưởng khi tạo qua giao diện lúc chạy thật** (đã kiểm chứng tạo qua UI vẫn có tên).
- **Bằng chứng:** Bảng dịch `tax_type_translations` = 0 dòng sau seed; screenshot màn trống tên.

### 🟠 BUG-3 — Trang cấu hình không hiển thị lại 3 trường thuế đã lưu
- **Hiện tượng:** Lưu "giá gồm thuế / thuế phí dịch vụ / loại thuế mặc định" thành công, nhưng khi mở lại trang thì công tắc hiện TẮT, ô nhập hiện 0.
- **Nguyên nhân:** API đọc cấu hình (GET) thiếu 3 trường này trong payload trả về (chỉ đường ghi PATCH là đủ).
- **Hậu quả:** Người dùng tưởng cấu hình chưa lưu; nguy cơ lần lưu sau **ghi đè ngầm** về giá trị mặc định.
- **Bằng chứng:** Đặt DB = bật/7, giao diện hiện tắt/0 (screenshot).

### 🟡 BUG-4 — Gửi sai định dạng tên → server sập (500)
- **Hiện tượng:** Tạo loại thuế với tên sai định dạng → HTTP 500 thay vì 422 (báo lỗi hợp lệ).
- **Hậu quả:** Chưa gây sai tiền, nhưng là lỗ hổng độ bền — tích hợp/client gửi sai → 500 khó chẩn đoán.

### 🟡 BUG-5 — Nhân viên cửa hàng tạo được loại thuế cấp HQ (cần quyết định)
- **Hiện tượng:** Tài khoản phạm vi cửa hàng vẫn tạo/sửa được loại thuế cấp thương hiệu (HQ).
- **Nguyên nhân:** Chính sách phân quyền chỉ kiểm tra "thuộc tổ chức", không kiểm tra vai trò/ngữ cảnh HQ. Đặc tính này áp cho **cả họ danh mục** (product-types cũng vậy), không riêng plan-043.
- **Cần sếp quyết:** siết chặt phân quyền cả họ danh mục, hay chấp nhận hiện trạng.

### ⚪ BUG-6 — Tài liệu API còn mô tả cột đã xóa
- **Hiện tượng:** Tài liệu Swagger vẫn mô tả trường `tax_rate` đã bị xóa ở Phase 6.
- **Hậu quả:** Chỉ lệch tài liệu, không ảnh hưởng chạy thật. Sửa chung với BUG-3.

### 🔴 BUG-10 — Khuyến mãi % bị giảm sai **100 lần** (tính theo basis-points thay vì phần trăm) — phát hiện 2026-07-10
- **Hiện tượng:** KM "Happy Hour 15%" chỉ giảm ~0,15% (¥2.450 → ¥2.446, giảm ¥4), badge hiện **−0%**. Đúng ra 15% phải ra ¥2.083.
- **Nguyên nhân:** Luồng khuyến mãi của **workstation** hiểu `discount_value` là *basis-points* (1500 = 15%, chia 10000), trong khi Cloud (`CustomerOrderService`: `giá×(100−n)/100`, `n` = 0,01–100), luồng coupon và luồng sync đều dùng **phần trăm thường** (15 = 15%). Coupon đã được sửa trước đó, **khuyến mãi bị bỏ sót**. `discount_percent` badge còn bị `15/100 = 0` (chia số nguyên) nên hiện −0%.
- **Hậu quả:** **Sai tiền** — MỌI khuyến mãi phần trăm (15%, 20%, 50%…) trên máy POS LAN đều giảm sai 100 lần (giảm quá ít → thu quá nhiều), và badge luôn hiện −0%.
- **Đã fix (`6b24958`, bump `9edb9002`):** 3 chỗ đổi sang `×(100−value)/100` (guard ≥100): `applyDiscount` (giá dòng khi thêm vào đơn = **tiền thực thu**), `applyDiscountForBadge` (giá KM trên badge menu), `activePromotionForProduct` (phần trăm badge). Test fixture đổi từ basis-points (2000/5000/1000 → 20/50/10) + thêm ca 15%→850, 20%→800. **Kiểm chứng sống:** LAN trả `discount_percent=15`, `discounted_price=1997` (=2350×0,85). Toàn bộ test workstation pass.
- **Ghi chú phạm vi:** Đây là lỗi *giảm giá khuyến mãi*, không phải phân bổ thuế (README liệt kê promotion allocation là out-of-scope). Nhưng là **lỗi tiền sống** ảnh hưởng cả fleet, lộ ra khi test plan-043 → đã sửa.

---

## Phần đã kiểm chứng ĐÚNG (để sếp thấy nền tảng vững)

- ✅ Bài toán chứng minh (bentō 8% + bia 10% cùng đơn) → thuế ¥130, tổng ¥1.630, đúng từng yên
- ✅ Làm tròn 1 lần/nhóm thuế (không sai lệch cộng dồn)
- ✅ Bất biến lịch sử: đổi thuế suất không làm sai đơn cũ
- ✅ Thuế phí dịch vụ, chuỗi kế thừa 4 tầng, chặn xóa loại đang dùng, chặn đổi chế độ khi ca đang mở
- ✅ Engine đồng nhất trên cả 3 nền: PHP (Cloud) / Go (workstation offline) / TypeScript (POS)

## Độ phủ kiểm thử

| Tầng | Kết quả |
|------|---------|
| backend (Pest, thuế) | 435 test — 0 lỗi |
| workstation-app (Go) | 7 gói — 0 lỗi |
| pos-web (vitest thuế) | 29/29 — 0 lỗi |
| API sống (curl) | ~150 kiểm thử |
| UI (Playwright) | admin-web / pos-web / customer-web |

> Ghi chú: pos-web có 51 test lỗi khác nhưng **không liên quan plan-043** (do sửa phân quyền thiết bị ở nhánh khác, ngày 07-07) — đã kiểm chứng plan-043 không đụng các file đó.

## Đề xuất thứ tự sửa
**BUG-1 → BUG-2 → BUG-7 → BUG-9 → BUG-8 → BUG-3 → BUG-4 → BUG-6**, và **BUG-5 chờ quyết định** về chính sách phân quyền.
