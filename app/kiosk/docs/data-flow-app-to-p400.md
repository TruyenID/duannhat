# Luồng dữ liệu: Kiosk App → P400 Terminal (Thanh toán thẻ)

## Tổng quan

```
┌─────────┐    HTTP     ┌─────────┐
│  Kiosk  │────────────►│ Backend │   Bước 1: Tạo payment record
│  App    │◄────────────│ Laravel │
│         │             └─────────┘
│         │
│         │  postMessage  ┌──────────┐  WebSocket  ┌──────────┐
│         │──────────────►│  WebView │────────────►│  P400    │  Bước 2: Gửi lệnh thanh toán
│         │◄──────────────│ (VescaJS)│◄────────────│ Terminal │
│         │   onMessage   └──────────┘             └──────────┘
│         │                                             ▲
│         │    HTTP     ┌─────────┐                     │
│         │────────────►│ Backend │   Bước 3: Confirm   │ Khách chạm thẻ
│         │             │ Laravel │                     │
└─────────┘             └─────────┘
```

---

## Chi tiết từng bước

### Bước 1: Tạo payment record trên backend

```
card.tsx                    use-payment.ts               Backend
   │                            │                           │
   │ submit({                   │                           │
   │   order_id,                │                           │
   │   method: 'card',          │                           │
   │   amount: 3000             │                           │
   │ })                         │                           │
   │───────────────────────────►│                           │
   │                            │  POST /kiosk/payments     │
   │                            │──────────────────────────►│
   │                            │                           │ ① lockForUpdate() trên order
   │                            │                           │ ② Fail pending cũ
   │                            │                           │ ③ Resolve PaymentMethod by code
   │                            │                           │ ④ Chuyển order open → checkout
   │                            │                           │ ⑤ Tạo OrderPayment (pending)
   │                            │  { payment_id,            │
   │                            │    status: 'pending',     │
   │                            │    confirm_type: 'manual',│
   │                            │    expires_at: +15min }   │
   │                            │◄──────────────────────────│
   │  r.status === 'pending'    │                           │
   │◄───────────────────────────│                           │
```

**Files:** `app/payment/card.tsx` → `src/hooks/use-payment.ts` → `src/lib/api.ts` → Backend `KioskController::pay()`

---

### Bước 2: Gửi lệnh thanh toán tới terminal

```
card.tsx              terminal-provider.tsx           WebView              P400
   │                        │                          │                    │
   │ sendToTerminal()       │                          │                    │
   │───────────────────────►│                          │                    │
   │                        │ postToWebView({          │                    │
   │                        │   type: 'REQUEST',       │                    │
   │                        │   host: '192.168.1.11',  │                    │
   │                        │   port: 3647,            │                    │
   │                        │   request: {             │                    │
   │                        │     AuthorizeSales: {    │                    │
   │                        │       Amount: 3000,      │                    │
   │                        │       CurrentService:    │                    │
   │                        │         'Credit',        │                    │
   │                        │       printOption: 0     │                    │
   │                        │     }                    │                    │
   │                        │   }                      │                    │
   │                        │ })                       │                    │
   │                        │─────────────────────────►│                    │
   │                        │                          │ doRequestWorker()  │
   │                        │                          │ Worker xử lý:     │
   │                        │                          │                    │
   │                        │                          │ ws://192.168.1.11  │
   │                        │                          │ :3647              │
   │                        │                          │───────────────────►│
   │                        │                          │   WebSocket open   │
   │                        │                          │                    │
   │                        │                          │ A1{Base64(JSON)}   │
   │                        │                          │───────────────────►│ Gửi lệnh
   │                        │                          │◄───────────────────│ ACK
   │                        │                          │                    │
   │                        │                          │ AP                 │
   │                        │                          │───────────────────►│ Polling
   │                        │                          │◄───────────────────│ S507AP (chờ thẻ)
   │                        │                          │                    │
   │                        │  { type: 'STATUS_EVENT', │                    │
   │                        │    ResponseCode: 'S507'} │                    │
   │                        │◄─────────────────────────│                    │
   │ statusEvent = 'S507'   │                          │                    │
   │ UI: "Chờ thẻ..."      │                          │                    │
   │◄───────────────────────│                          │                    │
```

**Files:** `app/payment/card.tsx` → `src/providers/terminal-provider.tsx` → `assets/vesca-bridge.html` (VescaJS Worker)

**Lưu ý quan trọng:**
- `postToWebView()` có queue mechanism — nếu Worker chưa READY, message được lưu vào `pendingMessage` và flush khi READY
- VescaJS chạy trong Web Worker bên trong WebView ẩn (0x0 pixel)
- WebView phải load qua `file://` URI (không phải inline HTML) để iOS cho phép Web Worker

---

### Bước 3: Khách chạm thẻ → nhận kết quả → confirm

```
P400                  WebView              terminal-provider.tsx     card.tsx          Backend
  │                     │                        │                     │                 │
  │ Khách chạm thẻ      │                        │                     │                 │
  │ Terminal xử lý      │                        │                     │                 │
  │ Liên lạc trung tâm  │                        │                     │                 │
  │                     │                        │                     │                 │
  │ 0000AP{Base64(JSON)}│                        │                     │                 │
  │────────────────────►│                        │                     │                 │
  │                     │ Worker parse kết quả   │                     │                 │
  │◄────────────────────│ ACK                    │                     │                 │
  │                     │ WebSocket close        │                     │                 │
  │                     │                        │                     │                 │
  │                     │ { type: 'RESULT',      │                     │                 │
  │                     │   data: {              │                     │                 │
  │                     │     OutputCompleteEvent:│                     │                 │
  │                     │     { SettledAmount,    │                     │                 │
  │                     │       ApprovalCode,     │                     │                 │
  │                     │       ... }             │                     │                 │
  │                     │   }                    │                     │                 │
  │                     │ }                      │                     │                 │
  │                     │───────────────────────►│                     │                 │
  │                     │                        │ status = 'success'  │                 │
  │                     │                        │ result = data       │                 │
  │                     │                        │────────────────────►│                 │
  │                     │                        │                     │                 │
  │                     │                        │                     │ confirm()       │
  │                     │                        │                     │ POST /payments  │
  │                     │                        │                     │ /{id}/confirm   │
  │                     │                        │                     │────────────────►│
  │                     │                        │                     │                 │ ① payment → succeeded
  │                     │                        │                     │                 │ ② update paid_amount
  │                     │                        │                     │                 │ ③ close order nếu đủ
  │                     │                        │                     │◄────────────────│
  │                     │                        │                     │                 │
  │                     │                        │                     │ paymentStatus   │
  │                     │                        │                     │ = 'paid'        │
  │                     │                        │                     │                 │
  │                     │                        │                     │ router.replace  │
  │                     │                        │                     │ → /success      │
```

**Files:** `assets/vesca-bridge.html` → `src/providers/terminal-provider.tsx` → `app/payment/card.tsx` → `src/hooks/use-payment.ts` → Backend `KioskController::confirmPayment()`

---

## Luồng lỗi

### Terminal lỗi (thẻ bị từ chối, timeout, mất kết nối)

**Ghost-payment protection:** During the 3s wait after `cancel()`, terminal can still complete the transaction (capture occurred just before user tapped Cancel). The kiosk calls `checkStatus()` after the wait to detect this case. If backend reports `paid`, the kiosk navigates to `/success` (printing the receipt the customer is entitled to) instead of calling `fail()` (which would 409 and leave the customer charged without a receipt). See `src/hooks/use-terminal-cancel.ts`.

```
P400 → ErrorEvent → WebView → terminal-provider (status='error') → card.tsx hiển thị lỗi
                                                                          │
                                                              ┌───────────┤
                                                              ▼           ▼
                                                        Thử lại      Huỷ thanh toán
                                                     (handleRetry)  (handleCancel via useTerminalCancel)
                                                          │               │
                                                          │               ├─ cancel() → WebView → AC → terminal
                                                          │               ├─ wait 3s cho terminal ack
                                                          │               ├─ checkStatus() — re-fetch payment status
                                                          │               ├─ Nếu 'paid' (ghost payment) → navigate /success
                                                          │               └─ Nếu khác → fail() → router.back()
                                                          │
                                                          └─ reset() → requestPayment(AuthorizeSales)
```

### Backend lỗi

```
card.tsx → submit() → Backend trả 409/422 → use-payment error → card.tsx hiển thị lỗi
```

| Lỗi backend | HTTP | Nguyên nhân |
|---|---|---|
| Order not in checkout/paying | 409 | Order đã closed/voided |
| Payment exceeds balance | 422 | Amount > remaining (pending cũ đã bị fail tự động) |
| Payment method not available | 422 | Method inactive hoặc không tồn tại |

---

## Dữ liệu truyền qua từng layer

### 1. card.tsx → Backend (HTTP)

```json
POST /api/v1/kiosk/payments
Headers: Authorization: Bearer {device_token}
         Idempotency-Key: {uuid}

Body: {
  "order_id": "019dbda8-...",
  "method": "card",
  "amount": 3000
}
```

### 2. card.tsx → WebView (postMessage)

```json
{
  "type": "REQUEST",
  "host": "192.168.1.11",
  "port": 3647,
  "request": {
    "AuthorizeSales": {
      "SequenceNumber": 100,
      "CurrentService": "Credit",
      "Amount": 3000,
      "TaxOthers": 0,
      "TrainingMode": false,
      "AdditionalSecurityInformation": {
        "lang": "ja",
        "apStatusOption": 1,
        "printOption": 0
      }
    }
  }
}
```

### 3. WebView → Terminal (WebSocket)

```
A1eyJBdXRob3JpemVTYWxlcyI6ey...    (A1 + Base64 encoded JSON)
```

### 4. Terminal → WebView (WebSocket)

```
0000APeyJPdXRwdXRDb21wbGV0ZUV2...   (0000AP + Base64 encoded result JSON)
```

### 5. WebView → card.tsx (onMessage)

```json
{
  "type": "RESULT",
  "data": {
    "OutputCompleteEvent": {
      "SettledAmount": 3000,
      "ApprovalCode": "003993",
      "CardCompanyID": "104",
      "CurrentService": "Credit",
      "TransactionNumber": "031054",
      "CustomerReceipt": [...],
      "MerchantReceipt": [...]
    }
  }
}
```

### 6. card.tsx → Backend confirm (HTTP)

```json
POST /api/v1/kiosk/payments/{payment_id}/confirm
Headers: Authorization: Bearer {device_token}
```

---

## Timeline hoàn chỉnh (happy path)

```
T+0s     Khách bấm "Thanh toán bằng thẻ"
T+0.1s   POST /kiosk/payments → backend tạo payment pending
T+0.2s   postMessage(AuthorizeSales) → WebView
T+0.3s   VescaJS mở WebSocket → terminal
T+0.5s   Terminal hiển thị "Vui lòng chạm thẻ"
T+0.5s   Kiosk hiển thị "Chờ thẻ..." (StatusEvent S507)
T+3-10s  Khách chạm thẻ
T+5-15s  Terminal xử lý với trung tâm thanh toán
T+5-15s  Terminal trả OutputCompleteEvent
T+15.1s  Kiosk nhận kết quả → POST /payments/{id}/confirm
T+15.2s  Backend: payment succeeded, order closed
T+15.3s  Kiosk hiển thị "Cảm ơn!"
```
