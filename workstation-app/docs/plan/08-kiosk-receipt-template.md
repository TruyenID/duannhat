# Plan 08 — Kiosk in template hóa đơn (qua workstation)

> **⚠️ SUPERSEDED (phần thuế):** mô hình thuế single-rate + fix bug `config.TaxRate`
> hardcode 10% mô tả ở dưới đã bị **plan-043 (軽減税率 / インボイス)** thay thế —
> fix bug print đọc settings synced = T3.7, receipt per-rate + ※ + T13 = T4.1.
> Xem `docs/guide/tax-types.md` (umbrella) + `plans/plan-043/`. Phần layout/trigger
> vẫn còn giá trị.
>
> **Discovered:** 2026-06-11, sau khi chốt hướng "workstation làm single print authority".
> **Status:** CLARIFIED — đã chốt hướng + thuế + trigger + nguồn data, chờ duyệt layout trước khi implement.
> **Scope:** workstation-app (`print_service.go` + sync_pull + print resolve + endpoint)
> và kiosk (bỏ in trực tiếp). Liên quan plan [07-device-dynamic-setup.md](07-device-dynamic-setup.md)
> (cần printer type cho máy in hóa đơn).

### Quyết định đã chốt
- **Workstation là single print authority** — kiosk bỏ Star SDK, chỉ báo payment success.
- Phiếu "ĐÃ THANH TOÁN" = **clone `FormatRunnerTicket`, bỏ QR**, thêm trạng thái.
- Split 2+ người: in **2 vé** (vé đã trả không QR + vé còn lại còn QR).
- **Thuế tax-included, `tax_rate` per-shop từ backend** (mặc định 10% khi trống) —
  KHÔNG hard-code, KHÔNG cộng ngoài.
- **Trigger in = cả hai**: auto khi confirm payment + endpoint reprint.
- **Nguồn data receipt = đọc từ SQLite ws** (không nhận payload kiosk). `orders` đã
  đủ breakdown; split state suy từ `payments` (thêm cột `metadata` mirror backend).
- **Máy in hóa đơn = role `receipt_printer`** (model multi-role ở plan 07), fallback hold/kitchen.
- Template tiếng Việt **không dấu** theo convention hiện có.
- Hóa đơn đỏ = **BACKLOG** (note lại, chưa làm).

### Thứ tự thực thi đề xuất
1. **Sửa thuế** (section "Chuẩn hóa cách tính thuế") — nền tảng, phải xong trước.
2. Template "ĐÃ THANH TOÁN" + split 2 vé.
3. Trigger in + bỏ in trực tiếp ở kiosk.
4. (Backlog) Hóa đơn đỏ.

---

## Bối cảnh — các loại phiếu hiện có & quy tắc in

Workstation hiện có 2 template trong [print_service.go](../../internal/service/print_service.go):

| Phiếu | Hàm | Khi nào in | Nội dung |
|---|---|---|---|
| **Phiếu bếp** (kitchen) | `FormatKitchenTicket` | Mỗi lần khách order / gọi thêm món | **Theo từng order/batch** — chỉ các món vừa fire |
| **Phiếu runner** (hold/bàn) | `FormatRunnerTicket` | In **cùng** phiếu bếp mỗi lần có order mới / gọi thêm | **Total tích lũy** — toàn bộ món từ lúc tạo order đến hiện tại. **Có QR** = `order.ID` |

➡️ Quy tắc xác nhận: **phiếu bếp = từng batch**, **phiếu runner = tổng dồn sau mỗi lần gọi mới**, in kèm nhau khi khách order hoặc gọi thêm món.

---

## Mục tiêu plan 08

Khi **thanh toán xong ở kiosk**, workstation in ra phiếu hóa đơn **tương tự phiếu
runner nhưng ở trạng thái ĐÃ THANH TOÁN và KHÔNG còn QR**. Kiosk không tự drive máy
in nữa (bỏ Star SDK) — chỉ báo "payment success cho order X", workstation trỏ IP
máy in để in (xem plan in-authority đã review trước đó).

---

## Các template mới cần thêm (clone từ `FormatRunnerTicket`)

### Template 1 — Phiếu "ĐÃ THANH TOÁN" (full payment)
- [ ] **Clone từ `FormatRunnerTicket`**, giữ nguyên layout header / items / totals.
- [ ] **Bỏ block QR** (`e.QRCode(order.ID, 7)` ở [print_service.go:259-263](../../internal/service/print_service.go#L259)).
- [ ] Thêm nhãn trạng thái **"ĐÃ THANH TOÁN"** (vd thay/đi kèm tiêu đề `HOA DON BAN`).
- [ ] Bổ sung dòng **phương thức thanh toán** + (tùy) số tiền nhận / tiền thối.
- [ ] `Con lai` = **¥0** (đã trả đủ).
- [ ] Đặt tên theo họ template hiện có: `Format<...>Ticket` (vd `FormatPaidTicket` —
      **không** dùng `FormatPaymentReceipt`). Tên cuối cùng chốt khi design.
- [ ] Tái dùng toàn bộ helper: `padRight`, `dashedLine`, `formatPrice`,
      `displayWidth`, `printWrappedName`, `printToppingLines`, `footerRow`,
      `OrderCodeSuffix`.

### Template 2 — Split bill 2+ người (trả nhiều lần)
Tình huống: đi từ 2 người trở lên, người A trả trước phần của mình, phần còn lại
để người kia trả. Khi A thanh toán xong → in **2 vé**:

- [ ] **Vé 1 — phần A đã trả:** dùng **Template 1 (ĐÃ THANH TOÁN, không QR)**,
      hiển thị số tiền A đã trả.
- [ ] **Vé 2 — phần còn lại cần thanh toán:** **y chang `FormatRunnerTicket`
      (VẪN CÒN QR)**, chỉ khác **`Total` = số tiền còn lại cần thanh toán**
      (`total - paidSoFar`). Để người kia quét QR thanh toán tiếp.
- [ ] Khi người cuối cùng trả nốt (phần còn lại = 0) → chỉ in Template 1 cho họ,
      không in vé "còn lại" nữa.
- [ ] **Suy split state từ `payments` table (giống backend `CustomerOrderSplitStatusController`)**,
      KHÔNG port `ReceiptSplitInfo` của kiosk:
  - `split_count`, `amount_per_person` ← `payments[0].metadata` (payment đầu, confirmed)
  - `paid_count` (slip index) ← `COUNT(payments WHERE status confirmed)`
  - `remaining` ← `order.total_amount - SUM(payments.amount confirmed)`
  - Vé "còn lại" hiện `Total = remaining`; vé "đã trả" hiện amount của payment vừa xong.
  - Nhãn "Khach n/N" (nếu có `split_count`) — optional, có thì in, không có thì bỏ.

---

## Chuẩn hóa cách tính thuế (BẮT BUỘC — sửa trước)

> Chốt: **thuế tax-included (đã bao gồm trong giá) — chuẩn Nhật. `tax_rate` lấy
> per-shop từ backend (đã đồng bộ về ws), KHÔNG hard-code 10%.** Mặc định 10% khi
> chưa có giá trị.

### Luồng thuế per-shop qua 3 phía (đã đọc code, xác nhận)

| Phía | Hiện trạng | File |
|---|---|---|
| **Backend** (nguồn chân lý) | `shop_order_settings.tax_rate` `decimal(5,2)` per-branch, admin set qua `PATCH /shops/{slug}/settings/order`. Endpoint `/api/v1/workstation/branch` **đã trả** `tax_rate` (+ `service_charge_rate`, `currency_code`) **nested trong `data.settings`** | [BranchController.php:43-52](../../../backend/app/Http/Controllers/Api/V1/Workstation/BranchController.php#L43) |
| **Admin-web** (UI set) | Input `taxRate` per-shop, lưu OK | [shop/[shopSlug]/settings/page.tsx:489](../../../admin-web/src/app/shop/[shopSlug]/settings/page.tsx#L489) |
| **WS** (⚠️ BUG/GAP) | `PullBranch` chỉ flatten `br.Settings` (field branch top-level) → **KHÔNG đọc `data.settings.tax_rate` (ShopOrderSetting)**. Tax per-shop **bị bỏ rơi**, ws luôn dùng `config.TaxRate=10` cố định | [sync_pull.go:1067-1153](../../internal/service/sync_pull.go#L1067) |

### Việc cần sửa

- [ ] **Sửa `PullBranch`**: thêm decode field nested `settings` (ShopOrderSetting)
      từ response — `tax_rate`, `service_charge_rate`, `currency_code`. Hiện struct
      `resp.Data` chưa có field này (chỉ có `Settings map[string]any` từ chỗ khác).
      Lưu `tax_rate` vào bảng `shop_settings` (vd key `tax_rate`) để print đọc.
- [ ] **Print đọc tax_rate per-shop**: `print_service.go` lấy `tax_rate` từ
      `shop_settings` (đã sync) thay vì `config.TaxRate` hard-code. Fallback 10 khi
      trống. Truyền vào `PrintJobConfig.TaxRate`.
- [ ] **Đổi cách tính sang tax-included**: hiện `print_service.go`
      ([:245](../../internal/service/print_service.go#L245)) tính `tax = subtotal/10`
      rồi `total = subtotal + tax` (**cộng ngoài**) → sửa thành tax-included:
      `thue = total - round(total / (1 + rate/100))`.
- [ ] Khi `order.TaxAmount` đã có từ backend → **ưu tiên dùng**, không tự tính lại.
      Chỉ fallback công thức tax-included (theo `tax_rate` per-shop) khi `TaxAmount==0`.
- [ ] Dùng chung 1 helper tính thuế cho runner ticket + `FormatInvoiceTicket` để không lệch.

### Lưu ý 軽減税率 (8% vs 10%)
- Hiện model chỉ có **1 `tax_rate`/shop** (không phân biệt mang-về 8% / tại-chỗ 10%).
  Ảnh mẫu là 8% 軽減対象, nhưng đã chốt dùng `tax_rate` của shop (mặc định 10%).
- Nếu sau này cần phân biệt theo `order_type` (takeaway/dine-in) thì tax_rate phải
  theo từng order/item — **ngoài scope plan này**, ghi để theo dõi.

---

## Trigger & nguồn dữ liệu (ĐÃ CHỐT)

> Chốt: **Trigger = cả hai (auto khi confirm + endpoint reprint).** **Nguồn data =
> đọc từ SQLite của ws.**

- [ ] **Auto-in khi confirm payment**: in trong `handleLocalConfirmPayment` /
      `transitionPayment` ([local_kiosk.go:144-215](../../internal/handler/local_kiosk.go#L144))
      ngay sau khi order chuyển `closed`. In lỗi **không block** confirm
      (giống `printKitchenAndRunner` hiện tại).
- [ ] **Endpoint reprint**: hoàn thiện stub `POST /api/lan/print/payment-receipt`
      ([lan_local.go:52](../../internal/handler/lan_local.go#L52)) → kiosk gọi để **in lại**
      (nút "In lại"). Body chỉ cần `order_id` (ws đọc phần còn lại từ DB).
- [ ] **Idempotency**: auto + reprint dùng chung 1 hàm format; reprint không tạo
      payment/giao dịch mới, chỉ in lại từ data đã có.
- [ ] **Nguồn data = SQLite ws**: format phiếu đọc `orders` + `order_items` +
      breakdown **từ DB local**, KHÔNG nhận payload kiosk.
  - ✅ **Đã verify** ([005_orders.sql](../../internal/store/migrations/005_orders.sql)):
        `orders` đã có `guest_count`, `discount_amount`, `service_charge`,
        `tax_amount`, `total_amount`, `paid_amount` → đủ cho phiếu full + hóa đơn đỏ.
  - [ ] ⚠️ **Split state — thêm cột `metadata` JSON vào ws `payments`** (mirror đúng
        `order_payments.metadata` của backend). Hiện ws `payments`
        ([006_payments.sql](../../internal/store/migrations/006_payments.sql)) **chưa có**
        cột này. Migration thêm `metadata TEXT` (JSON).
  - [ ] **Sync metadata**: (1) kiosk gửi `metadata` (split_count/amount_per_person)
        khi `POST /api/v1/kiosk/payments` → ws lưu vào cột mới (giống cách
        `terminal_response` đang đi qua `handleLocalCreatePayment`
        [local_kiosk.go:91-119](../../internal/handler/local_kiosk.go#L91)). (2) Thêm
        `metadata` vào sync UP payload để backend `order_payments.metadata` nhận đúng
        → backend `/split-status` hoạt động end-to-end.
- [ ] **Máy in hóa đơn**: resolve qua role `receipt_printer` (model multi-role ở
      plan [07](07-device-dynamic-setup.md)). Nếu chưa máy nào nhận role này →
      fallback máy in hold/kitchen (giống runner ticket).

---

## Kiosk — bỏ in trực tiếp
- [ ] Xóa `src/lib/printer.ts`, `src/lib/receipt-escpos.ts`,
      `src/hooks/use-receipt-printer.ts`, gỡ dependency `react-native-star-io10`.
- [ ] `app/success.tsx`: thay "drive máy in Star" bằng "gọi workstation báo
      payment success / in". Nút "In lại" → gọi lại endpoint in của ws.
- [ ] Giữ `buildReceiptData` chỉ khi cần gửi payload lên ws; nếu ws đọc từ DB
      thì bỏ luôn.

---

## NOTE — Tính năng hóa đơn đỏ (CHƯA THỰC HIỆN, ghi để theo dõi)

> **Trạng thái: BACKLOG — chưa cần làm trong sprint này.** Ghi lại theo yêu cầu để
> không quên.

Sau khi thanh toán xong, thêm **option xuất hóa đơn đỏ** (chứng từ chính thức để
khách khấu trừ chi phí — tương đương 領収書 của Nhật, nhưng **template in bằng
tiếng Việt KHÔNG DẤU** theo đúng convention các phiếu hiện có). Có **2 đường xuất**:

1. **In ra từ kiosk** — in hóa đơn đỏ trực tiếp (qua workstation như các phiếu khác).
2. **Gửi qua email** — kiosk cho khách **nhập email**, hệ thống gửi hóa đơn tới email đó.

### Đặc điểm KHÁC HẲN các phiếu kia
Hóa đơn đỏ **không phải** bản liệt kê món như runner/kitchen ticket. Nó là **chứng
từ 1 trang**: chỉ có **tổng tiền + breakdown thuế + thông tin phát hành + ô tên
người nhận**. Vì vậy đây là **template độc lập**, không clone runner — nhưng **vẫn
tái dùng cùng bộ helper** (`displayWidth`, `padRight`, `formatPrice`, `footerRow`,
`dashedLine`) và cùng convention escpos. **Toàn bộ chữ tiếng Việt không dấu**
(giống "HOA DON BAN", "Thanh tien", "Ghi chu"... đang dùng).

### Layout template (tiếng Việt không dấu, khổ 48 cột)

```
┌──────────────────────────────────────────────┐
│ [logo]  ベト屋 / VIET ORIGIN     So: 0001-0001 │  ← store name trái, so phieu phai
│                              Ngay: 2026/06/08  │  ← ngay phat hanh
│                                                │
│            HOA  DON                            │  ← title, CENTER + DoubleSize
│                                                │
│  ____________________________  (Quy khach)    │  ← o ten nguoi nhan, de trong
│                                                │
│              ¥800 —                            │  ← So tien: tong, CENTER + DoubleSize
│                                                │
│  Noi dung: ____________________________        │  ← ly do / mat hang, de trong
│                                                │
│       Da nhan du so tien tren                  │  ← cau xac nhan
│  - - - - - - - - - - - - - - - - - - - - - - - │
│  So tien                               ¥800    │  ← lap lai tong (lam ro)
│  (Thue suat {tax_rate}%)                       │  ← thue suat per-shop (vd 10%), tu shop_settings
│  Trong do thue                          ¥72    │  ← thue tieu thu DA BAO GOM (tax-included)
│  Gia chua thue                         ¥728    │  ← (tuy) gia chua thue = total - thue
│  - - - - - - - - - - - - - - - - - - - - - - - │
│  Ben to Event                                  │  ← phap nhan / cua hang phat hanh (bold)
│  Dia chi:  <store_address>                     │  ← tu config
│  Dien thoai: <store_phone>                     │
│  Nguoi phu trach:                              │  ← de trong / ky
│  So HD: 00012026608144514199                   │  ← so hoa don unique (dai)
│             [O dong tem neu >= ¥50,000]         │  ← (note) o tem thue khi tien lon
└──────────────────────────────────────────────┘
```

> Lưu ý: store name `ベト屋 / VIET ORIGIN` lấy từ `config.StoreName` / `StoreSubName`
> (có thể là tiếng Nhật) — phần này GIỮ NGUYÊN theo config như runner ticket; chỉ
> các **nhãn template** (So tien / Thue / Dia chi...) là tiếng Việt không dấu.

### Map sang escpos encoder (đã verify có sẵn)
| Phần | Lệnh encoder |
|---|---|
| Title "HOA DON" / So tien | `e.Align(AlignCenter)` + `e.Size(DoubleSize)` rồi `NormalSize` |
| Store name / phap nhan | `e.Bold(true)` |
| Dòng label–value (Trong do thue … ¥60) | `footerRow(label, value)` (tái dùng) |
| Phân cách | `e.Line(dashedLine(w))` |
| Số tiền | `"¥" + formatPrice(...)` |
| Cắt giấy | `e.FullCut()` |
| **KHÔNG có QR** | (hóa đơn đỏ không cần QR) |

### Hàm dự kiến
- [ ] `FormatInvoiceTicket(order *Order, info InvoiceInfo, config PrintJobConfig) []byte`
      — đặt tên theo họ `Format<...>Ticket`. **KHÔNG** dùng `FormatPaymentReceipt`.
- [ ] `InvoiceInfo` gồm: `RecipientName` (ten nguoi nhan, optional), `Note`
      (noi dung/mat hang, optional), `InvoiceNo` (so hoa don unique), `IssuedAt`.
- [ ] Breakdown thuế **dùng chung helper tax-included per-shop** ở section
      "Chuẩn hóa cách tính thuế" — KHÔNG tự tính riêng, KHÔNG hard-code 8%/10%.

### Việc cần làm (khi được ưu tiên — HIỆN CHƯA LÀM)
- [ ] Form trên kiosk: nhập **ten nguoi/cong ty nhan** + **noi dung (mat hang)** +
      chọn đường xuất (in / email). Đường email → nhập email.
- [ ] Sinh **so hoa don** unique (theo ngày + sequence), lưu để chống cấp trùng/đối soát.
- [ ] **Tem thuế (収入印紙)**: note rằng giao dịch ≥ ¥50,000 cần ô đóng tem — chỉ
      chừa chỗ, không tự xử lý.
- [ ] Đường **email** cần backend (Cloud) gửi mail + render hóa đơn (PDF/HTML) —
      **không** phải workstation LAN. Tách task backend riêng.
- [ ] Cân nhắc: nếu cửa hàng là đơn vị phát hành hóa đơn đăng ký (インボイス発行
      事業者) thì cần in **số đăng ký (T + 13 số)** ở footer.

---

## File liên quan

- [internal/service/print_service.go](../../internal/service/print_service.go) — `FormatKitchenTicket`, `FormatRunnerTicket`, helpers (clone cho template mới)
- [internal/handler/print_helpers.go](../../internal/handler/print_helpers.go) — resolve printer khi in
- [internal/handler/lan_local.go](../../internal/handler/lan_local.go) — stub `handleLANPrintReceipt`
- [internal/handler/local_kiosk.go](../../internal/handler/local_kiosk.go) — confirm payment (điểm móc in)
- `godx-kiosk/src/lib/receipt-types.ts` — `ReceiptSplitInfo` (port split logic sang Go)
- `godx-kiosk/app/success.tsx`, `godx-kiosk/src/lib/printer.ts` — bỏ in trực tiếp
