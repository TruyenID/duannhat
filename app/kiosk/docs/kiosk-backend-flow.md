# Kiosk App ↔ Backend — Luồng tương tác

## Tổng quan

```
┌──────────┐                          ┌──────────┐                    ┌──────────┐
│  Kiosk   │ ◄── HTTP (Bearer) ────► │ Backend  │ ◄── DB ──────────► │  MySQL   │
│  App     │                          │ Laravel  │                    │          │
│ (iPhone) │                          │ (Docker) │                    │          │
└──────────┘                          └──────────┘                    └──────────┘
     │
     │ WebSocket (LAN)
     ▼
┌──────────┐
│  P400    │
│ Terminal │
└──────────┘
```

Kiosk app giao tiếp backend qua **6 HTTP endpoints** + TMS endpoints (read-only). Mọi request gửi kèm `Authorization: Bearer {device_token}`.

---

## Flow theo thứ tự sử dụng

### 1. Pairing (1 lần duy nhất)

```
Kiosk App                          Backend
    │                                 │
    │ POST /api/v1/devices/pair       │
    │ { pairing_code, device_info }   │
    │────────────────────────────────►│
    │                                 │ Validate pairing_code (6 ký tự, 15 phút)
    │                                 │ Generate device_token
    │                                 │ Set device status = active
    │  { device_token, device }       │
    │◄────────────────────────────────│
    │                                 │
    │ Lưu token vào SecureStore       │
```

**Endpoint:** `POST /api/v1/devices/pair` (public, không cần auth)
**Khi nào:** Lần đầu setup kiosk hoặc sau khi logout

---

### 2. Lấy thông tin device (mỗi lần mở app)

```
Kiosk App                          Backend
    │                                 │
    │ GET /api/v1/kiosk/me            │
    │ Authorization: Bearer {token}   │
    │────────────────────────────────►│
    │                                 │ Validate token + type = kiosk
    │                                 │ Load device + branch
    │  { device, branch }             │
    │◄────────────────────────────────│
```

**Endpoint:** `GET /api/v1/kiosk/me`
**Khi nào:** App mở, auth provider kiểm tra token còn valid

---

### 3. Lấy zones + tables (màn hình chọn bàn)

```
Kiosk App                          Backend
    │                                 │
    │ GET /api/v1/tms/zones           │
    │────────────────────────────────►│ Trả zones thuộc branch này
    │◄────────────────────────────────│
    │                                 │
    │ GET /api/v1/tms/tables          │
    │────────────────────────────────►│ Trả tables thuộc branch này
    │◄────────────────────────────────│
    │                                 │
    │ Client filter: status=occupied  │
    │ Hiển thị bàn đang có khách     │
```

**Endpoints:** `GET /api/v1/tms/zones`, `GET /api/v1/tms/tables`
**Middleware:** `device.auth:tms,kiosk` (read-only, cả TMS và kiosk đều truy cập được)
**Polling:** 15 giây

---

### 4. Lấy order của bàn (màn hình checkout)

```
Kiosk App                          Backend
    │                                 │
    │ GET /api/v1/kiosk/orders        │
    │   ?table_id={uuid}              │
    │────────────────────────────────►│
    │                                 │ Validate table thuộc branch
    │                                 │ Tìm current_order_id
    │                                 │ Filter: status in [open,dining,checkout,paying]
    │                                 │ Eager load: items.productSku.product
    │                                 │
    │  { id, table_id, table_name,    │
    │    items: [{name, qty, price}], │
    │    subtotal, discount, total,   │
    │    currency }                   │
    │◄────────────────────────────────│
    │                                 │
    │  null nếu không có order active │
```

**Endpoint:** `GET /api/v1/kiosk/orders`
**Security:** Table + order scoped theo `branch_id` + `organization_id`

---

### 5. Tạo payment (khi khách chọn phương thức)

```
Kiosk App                          Backend
    │                                 │
    │ POST /api/v1/kiosk/payments     │
    │ { order_id, method, amount }    │
    │ Idempotency-Key: {uuid}         │
    │────────────────────────────────►│
    │                                 │ ① lockForUpdate() order row
    │                                 │ ② Idempotency check (key trùng → trả payment cũ)
    │                                 │ ③ Cancel tất cả pending cũ của order
    │                                 │ ④ Validate order status (checkout/paying)
    │                                 │ ⑤ Auto-transition: open/dining → checkout
    │                                 │ ⑥ Resolve PaymentMethod by code
    │                                 │    (branch-scoped ưu tiên, fallback system-wide)
    │                                 │ ⑦ Overpayment guard
    │                                 │ ⑧ Create OrderPayment (pending, expires_at +15min)
    │                                 │
    │  { payment_id, reference_no,    │
    │    status: "pending",           │
    │    expires_at, confirm_type,    │
    │    amount_paid }                │
    │◄────────────────────────────────│
```

**Endpoint:** `POST /api/v1/kiosk/payments` (throttle: 10 req/phút)
**Method mapping (kiosk → backend DB code):**

| Kiosk gửi | Backend code | Ý nghĩa |
|---|---|---|
| `card` | `card` | Thẻ tín dụng |
| `qr` | `transfer` | QR payment |
| `emoney` | `e_wallet` | E-money |
| `cash` | `cash` | Tiền mặt |

**Sau bước này:**
- `confirm_type: "auto"` (cash) → payment succeeded ngay → kiosk navigate success
- `confirm_type: "manual"` (card/qr/emoney) → kiosk gửi request tới P400 terminal

---

### 6. Poll trạng thái payment (khi chờ terminal)

```
Kiosk App                          Backend
    │                                 │
    │ GET /api/v1/kiosk/payments      │
    │   /{id}/status                  │
    │────────────────────────────────►│
    │                                 │ Scope: branch_id + organization_id
    │  { id, status }                 │
    │◄────────────────────────────────│
    │                                 │
    │ status = "pending" → tiếp poll  │
    │ status = "paid" → success       │
    │ status = "failed" → retry/back  │
```

**Endpoint:** `GET /api/v1/kiosk/payments/{id}/status` (throttle: 30 req/phút)
**Status mapping:**

| Backend (PaymentStatusEnum) | Kiosk nhận |
|---|---|
| `pending` | `pending` |
| `succeeded` | `paid` |
| `failed` / `refunded` | `failed` |

**Lưu ý:** Kiosk chủ yếu dùng terminal response thay vì polling — polling là fallback.

---

### 7. Confirm payment (sau khi terminal thành công)

```
Kiosk App                          Backend
    │                                 │
    │ ← P400 trả OutputCompleteEvent  │
    │                                 │
    │ POST /api/v1/kiosk/payments     │
    │   /{id}/confirm                 │
    │────────────────────────────────►│
    │                                 │ ① Validate payment pending + branch scope
    │                                 │ ② Set status = succeeded, paid_at = now
    │                                 │ ③ Update order: paid_amount += amount
    │                                 │ ④ Nếu paid_amount >= total_amount → close order
    │                                 │ ⑤ Audit log
    │  { id, status: "succeeded" }    │
    │◄────────────────────────────────│
    │                                 │
    │ Navigate → /success             │
```

**Endpoint:** `POST /api/v1/kiosk/payments/{payment}/confirm`
**Khi nào:** Terminal P400 trả `OutputCompleteEvent` thành công

---

### 8. Fail payment (khi terminal lỗi hoặc hủy)

```
Kiosk App                          Backend
    │                                 │
    │ ← P400 trả ErrorEvent          │
    │   hoặc khách bấm Cancel        │
    │                                 │
    │ POST /api/v1/kiosk/payments     │
    │   /{id}/fail                    │
    │────────────────────────────────►│
    │                                 │ Set status = failed
    │  { id, status: "failed" }       │
    │◄────────────────────────────────│
    │                                 │
    │ Navigate back                   │
```

**Endpoint:** `POST /api/v1/kiosk/payments/{payment}/fail`
**Khi nào:** Terminal lỗi, khách hủy, hoặc timeout

---

## Flow thanh toán hoàn chỉnh (Card — happy path)

```
 Kiosk App              Backend              P400 Terminal
    │                      │                      │
 1. │ POST /kiosk/payments │                      │
    │─────────────────────►│                      │
    │  { pending }         │                      │
    │◄─────────────────────│                      │
    │                      │                      │
 2. │ AuthorizeSales ──────┼─────────────────────►│
    │                      │                      │ Hiển thị "Chờ thẻ"
    │                      │                      │
 3. │                      │                      │ Khách chạm thẻ
    │                      │                      │ Liên lạc trung tâm
    │                      │                      │
 4. │ OutputCompleteEvent ◄┼──────────────────────│
    │                      │                      │
 5. │ POST /payments/{id}  │                      │
    │   /confirm           │                      │
    │─────────────────────►│                      │
    │                      │ payment → succeeded  │
    │                      │ order → closed        │
    │  { succeeded }       │                      │
    │◄─────────────────────│                      │
    │                      │                      │
 6. │ Navigate /success    │                      │
```

## Flow thanh toán Cash (không dùng terminal)

```
 Kiosk App              Backend
    │                      │
 1. │ POST /kiosk/payments │
    │ { method: "cash" }   │
    │─────────────────────►│
    │                      │ PaymentMethod cash = auto_confirm
    │                      │ payment → succeeded ngay
    │                      │ order → closed (nếu đủ tiền)
    │  { succeeded }       │
    │◄─────────────────────│
    │                      │
 2. │ Navigate /success    │
```

---

## Pending payment lifecycle

```
Tạo payment ──► pending (expires_at = +15 phút)
                   │
         ┌─────────┼──────────┐──────────┐
         ▼         ▼          ▼          ▼
    terminal    terminal    timeout    tạo payment
    success     error/cancel  15 min    mới cho
         │         │          │        cùng order
         ▼         ▼          ▼          │
      confirm    fail     expire      cancel
         │         │      command    pending cũ
         ▼         ▼          │          │
     succeeded   failed    failed     failed
         │
         ▼
    order closed
    (nếu đủ tiền)
```

---

## Security

| Layer | Cơ chế |
|---|---|
| Auth | `device.auth:kiosk` middleware — validate token + type + active |
| Scope | Mọi query filter `branch_id` + `organization_id` từ device |
| Race condition | `lockForUpdate()` trên order row |
| Duplicate | `Idempotency-Key` header |
| Overpayment | SUM(pending + succeeded) guard |
| Spam | Throttle: 10/min payments, 30/min status |
| Pending rác | Auto-cancel pending cũ khi tạo mới + expire 15 phút |
