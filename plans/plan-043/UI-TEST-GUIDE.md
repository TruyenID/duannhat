# Plan 043 — Hướng dẫn test THUẾ bằng tay trên UI

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

> Click-by-click cho toàn bộ luồng thuế (plan-043) trên **admin-web · pos-web · customer-web · workstation**.
> Mỗi bước ghi: **màn nào → bấm gì → nhập gì → PHẢI THẤY gì**. Nhãn ghi theo **VI (JA)** — app mặc định locale **ja**, đổi sang vi ở góc chọn ngôn ngữ để thấy nhãn VI.
> ⚠️ = case nhạy cảm pháp lý (hóa đơn) — soi kỹ nhất. Fixture số liệu: bentō ¥1.000 (軽減/8%), bia ¥500 (標準/10%), cola (軽減).

## Bật server + login
```sh
docker compose up -d && docker compose exec app php artisan migrate:fresh --seed --force
pnpm dev:admin   # http://localhost:5430
pnpm dev:pos     # http://localhost:5440
pnpm dev:customer# http://localhost:5450
# workstation: cd workstation-app && make dev  → http://localhost:8080
# email hóa đơn: http://localhost:8025 (Mailpit)
```
Login admin-web bằng tài khoản HQ SSO (dev có SSO-bypass cho account seed). Vào context **HQ** của brand test.

---

# A. ADMIN-WEB — HQ · Loại thuế (Tax Types CRUD)

### A1. Mở danh sách
- Sidebar → nhóm **Catalog** → **Loại thuế** (JA: 税区分) → URL `/hq/{brand}/tax-types`.
- **PHẢI THẤY:** 3 dòng seed — **標準 (店内10%/持ち帰り10%, badge Mặc định)**, **軽減 (10%/8%)**, **非課税 (0%/0%)**. Cột: Mặc định (Default) · Thuế suất "Tại quán 10% / Mang về 8%" · Sản phẩm · Trạng thái. **Không có lỗi console.**

### A2. Tạo loại thuế mới
- Bấm **Mới** (JA: 新規) → dialog "Tạo loại thuế".
- Nhập **Mã** = `TEST8`, tab **Tên** nhập lần lượt ja/en/vi, **Thuế tại quán** = 10, **Thuế mang về** = 8.
- Bấm **Tạo**.
- **PHẢI THẤY:** dòng mới xuất hiện, badge "Tại quán 10% / Mang về 8%". (API `POST .../tax-types` → 201.)

### A3. is_default là duy nhất
- Sửa `TEST8` → tick **Đặt làm loại thuế mặc định** → hiện cảnh báo "Thao tác này sẽ thay thế mặc định hiện tại" → Lưu.
- **PHẢI THẤY:** badge **Mặc định** chuyển sang `TEST8`, **標準 mất badge default** (mỗi brand đúng 1 default).

### A4. Sửa rate (giữ để đối chiếu tính bất biến ở D3)
- Sửa **軽減** → đổi **Thuế mang về** 8 → 9 → Lưu → 200.
- **PHẢI THẤY:** rate mới hiển thị. (Ghi nhớ: order cũ KHÔNG đổi — kiểm ở mục D3.)

### A5. Trùng mã
- Tạo mới mã = `TEST8` (trùng) → **PHẢI THẤY 422**, lỗi inline dưới ô Mã.

### A6. ⚠️ Xóa loại đang dùng → 409
- Trước tiên qua mục B1 gán 軽減 cho 1 sản phẩm. Quay lại đây, mở menu hành động dòng 軽減 → **Xóa**.
- **PHẢI THẤY:** alert **"Loại thuế đang được sử dụng"** (JA: 税区分は使用中です) liệt kê số lượng: **Sản phẩm / Sản phẩm trong menu / Mặc định chi nhánh**, + nút **Vô hiệu hóa thay thế** (JA: 無効化する). Bấm nút đó → loại bị tắt active (không xóa).
- Xóa loại **chưa dùng** (vd `TEST8` sau khi bỏ default) → **204**, biến mất.

### A7. Toggle status ≠ delete
- Tắt active 1 loại → nó **biến khỏi dropdown lookup** (kiểm ở B) nhưng sản phẩm đang tham chiếu **vẫn resolve được**. Restore đưa loại soft-deleted quay lại.

---

# B. ADMIN-WEB — HQ · Gán thuế cho Sản phẩm

### B1. Dropdown thuế ở card 分類
- Sidebar → **Sản phẩm** (Products) → mở 1 sản phẩm đồ ăn (bentō) → panel phải, card **Phân loại** (JA: 分類).
- Trường **Loại thuế** (JA: 税区分): option đầu = **"Dùng mặc định (kế thừa)"** (JA: デフォルトを使用（継承）, = null) → chọn **軽減** → Lưu.
- **PHẢI THẤY:** dưới dropdown có hint "Khi để trống, loại thuế mặc định của thương hiệu sẽ áp dụng"; cột **Loại thuế** ở list sản phẩm phản ánh 軽減.

### B2. Validate cross-brand / inactive
- Không thể gán loại thuế của **brand khác** (API 422/403). Loại **inactive** không xuất hiện trong dropdown; nếu ép gán → 422.

---

# C. ADMIN-WEB — HQ · Override thuế ở Menu item

- Sidebar → **Menu** → mở 1 menu → danh sách item → mở item (drawer).
- Tìm hành động **override** thuế (JA/VI: "override") — chuyển **kế thừa → override** → chọn loại thuế khác với thuế của Product → Lưu.
- **PHẢI THẤY:** chip/nhãn phản ánh override. (API `PATCH /hq/{brand}/menus/{menu}/products/{menuProduct}/tax-type`.)
- **Ưu tiên:** khi lên order, item này ăn thuế **override** (thắng thuế Product). Xác nhận ở mục G (order).

---

# D. ADMIN-WEB — HQ · CSV Sản phẩm (tax_type_code)

- Sản phẩm → **Export** → mở file CSV: **PHẢI THẤY** cột `tax_type_code`.
- Sản phẩm → **Nhập sản phẩm** (JA: 商品をインポート) → **Tải mẫu** (template có cột trên) → **Chọn tệp** → **Nhập**.
  - Row hợp lệ (mã thuế đúng) → gán loại; ô trống = kế thừa (null).
- **PHẢI THẤY:** toast "Đã nhập {n} sản phẩm" hoặc "Nhập được {ok}, thất bại {fail}".

---

# E. ADMIN-WEB — Shop · Settings thuế (⚠️ khóa khi ca mở)

- Đổi context sang **Shop** → **Cài đặt** (Settings) → tab **Đơn hàng** (order) → card **Thuế** (JA: 税).
- **PHẢI THẤY** info alert "Nên thay đổi loại thuế và thuế suất ngoài giờ ca làm…".

### E1. Loại thuế mặc định
- **Loại thuế mặc định** (JA: デフォルト税区分) — Select lookup → chọn 標準 → Lưu → round-trip.

### E2. ⚠️ Giá đã gồm thuế (総額表示) — khóa khi ca mở
- Có **ca thu ngân đang mở** (mở ca ở pos-web): gạt switch **Giá đã gồm thuế** (JA: 税込み価格).
  - **PHẢI THẤY:** alert chặn **"Không thể đổi chế độ gồm/chưa gồm thuế khi còn ca đang mở. Hãy đóng tất cả ca trước"** (409 `TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT`), switch **bật lại trạng thái cũ**, DB không đổi.
- Đóng hết ca → gạt lại → **thành công (200)**.

### E3. Thuế phí dịch vụ + toggle báo cáo
- **Thuế suất phí dịch vụ** (JA: サービス料の税率) = 10 → Lưu.
- Toggle **Hiển thị chi tiết thuế theo mức trên báo cáo đóng ca** (JA: 精算レポートで税区分別の詳細を表示) → Lưu → reload giữ nguyên.
- Lưu ý: **đổi RATE khi ca mở = CHO PHÉP** (200) — chỉ include-mode mới bị khóa.

---

# F. ADMIN-WEB — Order detail per-rate summary
- Mở 1 order mixed-rate (tạo ở pos-web mục G) → component **OrderChargeSummary**.
- **PHẢI THẤY:** thay vì 1 dòng thuế → **nhiều dòng per-rate** "8% (tính thuế: …)" / "10% (…)" + **chip mode**: **Chưa gồm** (amber, excluded) hoặc **Đã gồm** (green, included) đọc từ `is_tax_included`.

---

# G. POS-WEB — Ring order proof-case + Cart per-rate (lõi)

### G1. Tạo order mixed-rate (takeaway)
- Bấm **Tạo đơn** → dialog **Loại đơn** → chọn **Mang đi** (takeaway).
- Thêm món **bentō** (thuế giảm) + **bia** (thuế chuẩn).
- Mở **giỏ hàng** (order-cart, sidebar phải), kéo xuống phần tổng.
- **PHẢI THẤY 2 dòng thuế** riêng:
  ```
  Tạm tính            1.500
  Thuế 8%                80     (bentō)
  Thuế 10%               50     (bia)
  Tổng thu            1.630
  ```
  (Nhãn `Thuế {rate}%` / JA `{rate}%対象`.)

### G2. Chuyển dine-in → re-resolve
- Đổi loại đơn **Tại chỗ** (dine_in).
- **PHẢI THẤY:** cả 2 dòng gộp 10% → **Thuế 10% = 150**, **Tổng = 1.650** (bentō nâng 8%→10%).

### G3. Snapshot bất biến (nối A4)
- Đã sửa 軽減 8→9 ở A4: order tạo TRƯỚC đó vẫn **Thuế 8% = 80**. Order MỚI mới ăn 9%. → **D3 pass.**

### G4. Included mode (総額表示)
- Sau khi bật include-mode (E2, ca đóng): tạo order mới bentō+bia → **PHẢI THẤY** nhãn **"Đã gồm thuế 8% / 10%"** (JA `内消費税`), tổng = Σ gross = **1.630**, thuế hiển thị là phần "trong".

### G5. Void / update
- Void con **bia** trong order G1 → **nhóm 10% biến mất**, nhóm 8% nguyên, tổng tính lại. Update qty bentō 1→3 → giữ rate 8%, thuế dòng tính lại.

---

# H. POS-WEB — Split bill per-rate
- Order ở trạng thái thanh toán → footer giỏ có **Thanh toán** + **Chia đều**.
- Bấm **Chia đều** → dialog, tab **Chia theo món** (by_items).
- Gán món cho từng khách → mỗi **PersonCard** hiển thị breakdown:
  - **PHẢI THẤY** per-person: `Tổng món · Giảm · Thuế (8%) · Thuế (10%) · Phí dịch vụ · Phải thu`. **Σ các khách = tổng order.**

---

# I. POS-WEB — ⚠️ Hóa đơn VAT + toggle báo cáo

### I1. Dialog hóa đơn đỏ
- Order mixed-rate → **Thanh toán** → **Xuất hoá đơn đỏ**.
- Nhập Mã số thuế, Tên công ty → **PHẢI THẤY** box **Chi tiết thuế theo mức**:
  ```
  Chịu thuế 8%    ¥1.000 (thuế trong ¥80)
  Chịu thuế 10%   ¥500 (thuế trong ¥50)
  ```
- Xuất → workstation in slip có số đăng ký khi có.

### I2. Toggle close-report
- Menu → **Cài đặt báo cáo kết ca** → card 5 toggle → dòng **Chi tiết thuế theo mức** (JA: 税率別内訳) → gạt → **Lưu** → toast "Đã lưu cài đặt". Toggle này chỉ gate **in nhiệt** (PDF Z-report luôn có breakdown).

---

# J. CUSTOMER-WEB — Checkout 総額表示 per-rate (⚠️)
- Quét QR bàn hoặc vào `/{locale}/checkout` → thêm bentō (¥1.000) + bia (¥500) → tới **Checkout**.
- **PHẢI THẤY** dưới danh sách món, trên tổng:
  - `Chịu thuế 8% — Thuế đã gồm ₫80` **có dấu ※**
  - `Chịu thuế 10% — Thuế đã gồm ₫50` (không ※)
  - Footer chú thích **`※ Mặt hàng chịu thuế suất giảm`** (chỉ hiện khi có nhóm giảm).
  - Tổng **1.630**.
- Kiểm cả **desktop + mobile** (checkout-page vs checkout-page-mobile).
- **Dine-in split**: `/{locale}/dine-in/...` → payment-view (chia đều) + summary-view → per-rate lines, preview khớp server.
- **Account → Đơn hàng** → mở order mixed cũ → breakdown per-rate render đúng.

---

# K. WORKSTATION — Settings + in ấn (⚠️)

### K1. Settings.tsx (ô taxRate đã bỏ)
- Mở http://localhost:8080 → **Settings**.
- **PHẢI THẤY:** **KHÔNG còn ô "Tax Rate (%)"** (đã gỡ ở T3.7 — thuế nay sync per-branch từ Cloud). Còn Store Name, Address, Port.

### K2. ⚠️ Receipt in (paid slip)
- Thanh toán 1 order mixed-rate → in phiếu.
- **PHẢI THẤY** trên phiếu:
  ```
    8%対象 ¥1,000 (内消費税 ¥80)
    ※
    10%対象 ¥500 (内消費税 ¥50)
  ※は軽減税率対象
  ```
  Dòng bentō có **※**, dòng bia không. Có **登録番号: T…** (T13) khi có số đăng ký. Split-bill thì **ẩn** breakdown.
  **Sửa 2026-08-17 (#2064):** phiếu **split-bill** ẩn breakdown theo mức và dấu ※, nhưng **VẪN in `登録番号: T…`**. Quán 免税事業者 (không có số) thì dòng đó vắng mặt **im lặng** — không nhãn cụt, không dòng trắng (#1152).

### K3. ⚠️ Shift/Close report
- Đóng ca có order mixed → in báo cáo (với `close_report_tax_breakdown = ON`):
  ```
  売上内訳
    8%対象      1,000円
    10%対象       500円
  消費税内訳
    8%対象         80円
    10%対象        50円
  ```
  Tắt toggle → gộp về 1 dòng 課税売上 / 消費税. Nhãn VI fold ASCII cho Shift_JIS.

### K4. Offline parity
- Ngắt mạng workstation → ring lại order proof-case → tổng **giống hệt Cloud** (130 / 1.630). Không panic khi menu cũ thiếu field thuế.

---

# Thứ tự đề xuất & checklist ⚠️
1. **A → B → C** (thiết lập: tax type, gán món, override) — làm trước vì các bước sau cần dữ liệu này.
2. **E** (settings include-mode + khóa ca) trước **G4**.
3. **G** (pos ring order — lõi) → **F** (admin order detail) → **H/I** (split/hóa đơn).
4. **J** (customer-web) → **K** (workstation in ấn + offline).

**Điểm bắt buộc ký xác nhận:** C (override), K2. Cộng hóa đơn per-rate: I1, K3.
