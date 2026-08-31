# Kiosk App — UI Screen Flow

## Tổng quan

```
                    ┌──────────┐
                    │  index   │ Auth guard
                    └────┬─────┘
                         │
              ┌──────────┴──────────┐
              ▼                     ▼
        ┌──────────┐          ┌──────────┐
        │  login   │          │advertise │ Màn hình chờ
        │(pairing) │          │ (idle)   │
        └────┬─────┘          └────┬─────┘
             │                     │
             │ Pair thành công     │ Tap 1 lần
             └─────────►──────────┘
                                   │
                              ┌────▼─────────┐
                              │ select-table │ Chọn bàn occupied
                              └────┬─────────┘
                                   │ Chọn + Confirm
                                   ▼
                              ┌──────────┐
                              │   bill   │ Order summary + tổng tiền
                              └────┬─────┘
                                   │ Tiếp tục
                                   ▼
                            ┌──────────────┐
                            │split-options │ Full / split / custom
                            └────┬─────────┘
                                 │
                                 ▼
                          ┌──────────────┐
                          │payment-method│ Chọn PTP (card/qr/emoney/cash)
                          └────┬─────────┘
                               │
                   ┌───────────┼───────────┐───────────┐
                   ▼           ▼           ▼           ▼
             ┌──────────┐ ┌────────┐ ┌─────────┐ ┌────────┐
             │  card    │ │   qr   │ │ emoney  │ │  cash  │
             │(terminal)│ │(terminal)│ │(terminal)│ │(manual)│
             └────┬─────┘ └───┬────┘ └────┬────┘ └───┬────┘
                  │           │           │          │
                  └───────────┴───────────┴──────────┘
                                 │
                                 ▼
                          ┌──────────┐
                          │ success  │ Kết quả + Auto redirect
                          └────┬─────┘
                               │ 10s auto / bấm "Về trang chủ"
                               ▼
                          ┌──────────┐
                          │advertise │ Quay lại idle
                          └──────────┘
```

> Canonical payment-surface reference: [`payment-flow.md`](./payment-flow.md)
> (split / custom / payment-method / payment/{method} state machine, idempotency
> key minting, deferred logout rules).

> Note: the camera-based `scan.tsx` and the legacy `checkout.tsx` were removed in
> `fix/payment-flow-critical-trio` (Phase A). The advertise tap now goes directly
> to `select-table` (manual zone/table grid was the production flow — QR scan
> was never wired to real labels). Method picking moved out of `checkout` into
> `payment-method.tsx` so split / custom-amount can branch off `bill` cleanly.

## Màn hình ẩn

```
advertise ──── Tap 5 lần trong 3s ────► settings
                                          │
                                          │ Logout
                                          ▼
                                        login
```

---

## Chi tiết từng màn hình

### 1. index.tsx — Auth Guard

- **Vai trò:** Entry point, kiểm tra đăng nhập
- **Logic:** Nếu có device token → `/advertise`. Nếu không → `/login`
- **UI:** Loading spinner khi kiểm tra auth

---

### 2. login.tsx — Device Pairing

- **Vai trò:** Nhập pairing code 6 ký tự để pair kiosk với backend
- **Flow:** Nhập code → `POST /api/v1/devices/pair` → nhận device token → lưu SecureStore → redirect `/advertise`
- **UI:** Input pairing code + nút Submit

---

### 3. advertise.tsx — Màn hình chờ (Idle)

- **Vai trò:** Màn hình hiển thị khi không có khách sử dụng
- **UI:** 
  - Background tối, headline lớn, text mời thanh toán
  - Footer: language switcher (ja/en/vi) — nằm ngoài vùng tap
- **Interactions:**
  - **Tap 1 lần** bất kỳ đâu trên content → chờ 700ms → navigate `/select-table`
  - **Tap 2 lần trong 700ms** → bắt đầu secret mode → đợi đủ 5 tap trong 3s → navigate `/settings`
  - **Tap language button** → đổi ngôn ngữ, không navigate

---

### 4. select-table.tsx — Chọn bàn thủ công (Full screen)

- **Vai trò:** Hiển thị danh sách bàn đang occupied để khách chọn (entry-point của payment flow)
- **UI:**
  - Header: tiêu đề + nút quay lại advertise
  - Legend: occupied / selected
  - ZoneTableGrid: grid bàn theo zone, chỉ hiện bàn `status = 'occupied'`
  - Bottom bar: hiện khi chọn bàn → nút Confirm
- **Flow:** Chọn bàn → Confirm → navigate `/bill?tableId=`

---

### 5. bill.tsx — Order summary + tổng tiền

- **Vai trò:** Hiển thị danh sách món + tổng tiền cho bàn vừa chọn (read-only)
- **Hook:** `useOrder(tableId)` → `GET /api/v1/kiosk/orders?table_id=`
- **UI:**
  - OrderSummary (danh sách món, đơn giá, tổng)
  - Nút "Thanh toán" ở footer
- **Flow:** Bấm Thanh toán → navigate `/split-options?tableId=&orderId=&amount=`

---

### 6. split-options.tsx — Chọn full / split / custom

- **Vai trò:** Cho khách chọn trả full hóa đơn, split đều theo số người, hoặc nhập số tiền tự do
- **Branches:**
  - **Full:** navigate `/payment-method?...&amount=<total>`
  - **Split (chia đều):** navigate `/split/people` để chọn số người trước
  - **Custom (nhập tay):** navigate `/custom/amount` để nhập số tiền
- **Flow chi tiết:** xem [`payment-flow.md`](./payment-flow.md) (state machine + idempotency key minting).

---

### 7. payment-method.tsx — Chọn phương thức thanh toán

- **Vai trò:** 4 button method picker (sau khi đã quyết được số tiền)
- **Layout:** 2 cột — OrderSummary trái, 4 button (2x2 grid) phải
- **Phương thức:**
  - Card (thẻ tín dụng) → `/payment/card`
  - QR (PayPay, Alipay...) → `/payment/qr`
  - EMoney (Suica, Edy...) → `/payment/emoney`
  - Cash (tiền mặt) → `/payment/cash`
- **Flow:** Chọn method → bấm Pay → navigate `/payment/{method}?tableId=&orderId=&amount=&currency=`
- **Idempotency:** entry-point này mint fresh idempotency key qua `newAttempt()` — xem `payment-flow.md`.

---

### 8. payment/card.tsx — Thanh toán thẻ

- **Vai trò:** Gửi lệnh `AuthorizeSales` tới terminal P400
- **Flow:**
  1. `POST /kiosk/payments` → tạo payment pending trên backend
  2. Gửi `AuthorizeSales` tới P400 qua WebView/VescaJS
  3. P400 hiển thị "Chờ chạm thẻ" → khách chạm thẻ
  4. P400 trả `OutputCompleteEvent` → `POST /kiosk/payments/{id}/confirm`
  5. Navigate `/success`
- **UI:**
  - Icon thẻ + "Chờ thẻ..." status
  - StatusEvent từ terminal (S507 = chờ thẻ, etc.)
  - Nút Cancel (chờ terminal phản hồi trước khi navigate back)
  - Error screen: nút Retry + Cancel

---

### 9. payment/qr.tsx — Thanh toán QR

- **Vai trò:** Gửi lệnh `SubtractValue` (QRPayment) tới terminal P400
- **Flow:** Giống card nhưng:
  - Request: `SubtractValue` với `CurrentService: 'QRPayment'`, `qrCodeMode: 'MPM'`
  - P400 tự tạo QR code trên màn hình terminal
  - Khách quét QR bằng PayPay/Alipay/WeChat
- **Fallback:** Nếu không có terminal → hiển thị QR từ backend (Phase 2)

---

### 10. payment/emoney.tsx — Thanh toán E-Money

- **Vai trò:** Gửi lệnh `SubtractValue` (ElectronicMoney) tới terminal P400
- **Flow:** Giống card nhưng:
  - Request: `SubtractValue` với `CurrentService: 'ElectronicMoney'`
  - P400 hiển thị "Chờ chạm thẻ" → khách chạm Suica/Edy/WAON/nanaco
- **UI:** Giống card

---

### 11. payment/cash.tsx — Thanh toán tiền mặt

- **Vai trò:** Nhập số tiền khách đưa, tính tiền thối
- **Flow:**
  1. Hiển thị tổng tiền cần trả
  2. Khách (hoặc nhân viên) nhập số tiền đưa
  3. Tính tiền thối real-time
  4. Bấm Confirm → `POST /kiosk/payments` (cash = auto-confirm)
  5. Navigate `/success`
- **UI:**
  - Input số tiền (numeric keyboard, tap outside to dismiss)
  - Card: tổng tiền cần trả + tiền thối
  - Nút Confirm (disabled nếu chưa đủ tiền)
- **Không dùng terminal P400**

---

### 12. success.tsx — Kết quả thanh toán

- **Vai trò:** Hiển thị thanh toán thành công
- **Layout:** Full screen (không chia cột)
- **UI:**
  - Checkmark xanh
  - Tiêu đề "Cảm ơn!"
  - Reference number + phương thức thanh toán
  - Nút "In hóa đơn" + "Về trang chủ"
  - Auto redirect về `/advertise` sau 10 giây (countdown hiển thị)

---

### 13. settings.tsx — Cài đặt (ẩn)

- **Truy cập:** Tap 5 lần trong 3s tại advertise screen
- **Vai trò:** Cấu hình thiết bị ngoại vi
- **UI:**
  - **Printer:** IP address + Save + Test connection
  - **Terminal P400:** IP + Port + Save + Test connection (gửi `StartService` qua VescaJS)
  - **Logout:** Xoá device token → quay về login

---

## Provider Tree

```
SafeAreaProvider
  └── ErrorBoundary
       └── AppProvider (theme + locale)
            └── QueryProvider (TanStack Query)
                 └── AuthProvider (device token)
                      └── TerminalProvider (VescaJS WebView — mount 1 lần tại root)
                           └── IdleTimer
                                └── Stack (Expo Router screens)
```

---

## Shared Components

| Component | Dùng ở | Vai trò |
|---|---|---|
| `OrderSummary` | bill, payment-method, payment/_layout | Danh sách món + giá + tổng |
| `ZoneTableGrid` | select-table | Grid bàn theo zone |
| `IdleTimer` | _layout (root) | Auto redirect về advertise khi không tương tác |
| `ErrorBoundary` | _layout (root) | Catch crash, hiển thị fallback |
