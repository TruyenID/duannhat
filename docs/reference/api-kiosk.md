---
title: Kiosk API
category: reference
tags: [kiosk, api, device, pairing, orders, payments]
summary: API reference for the Kiosk device — a self-service terminal that lets customers view their order and pay at the table. Auth via device token (device.auth:kiosk middleware).
related: [device-management, api-orders]
---

# Kiosk API

Reference doc for Kiosk device endpoints. All routes are mounted at `/api/v1/kiosk/*` and require a **device token** with type `kiosk`.

---

## Overview

The Kiosk is a self-service terminal placed at the table that lets customers:
1. View the open order at the table
2. Choose a payment method
3. Initiate a payment and poll its status

The Kiosk **shares the pairing endpoint** with TMS and Workstation (`POST /api/v1/devices/pair`), but after pairing it may only call the `/api/v1/kiosk/*` endpoints.

---

## Device Pairing (shared)

```
POST /api/v1/devices/pair
```

No auth required. Exchanges a pairing code (6 characters, expires after 15 minutes) for a `device_token`.

**Request body:**
```json
{
  "pairing_code": "A3B9KZ",
  "device_info": {
    "device_uuid": "...",
    "os": "iOS 18.0",
    "model": "iPad Pro 13",
    "brand": "Apple",
    "app_version": "1.0.0"
  }
}
```

**Response `200`:**
```json
{
  "device_token": "64-char-token...",
  "device": {
    "id": "uuid",
    "name": "Kiosk-Table-A1",
    "type": "kiosk",
    "status": "active",
    "branch": { "id": "uuid", "name": "Shibuya" }
  }
}
```

**Token storage:** The Kiosk app stores the `device_token` in `SecureStore` and uses it for every subsequent API call via the `Authorization: Bearer {token}` header.

---

## Kiosk Endpoints

All endpoints below require the header:
```
Authorization: Bearer {device_token}
```

The `device.auth:kiosk` middleware:
- Validates that the token is valid
- Checks `device.status = active`
- Checks `device.type = kiosk` — rejects with `403` for any other type
- Updates `last_seen_at` (heartbeat)

---

### GET /api/v1/kiosk/me

Current device info + branch.

**Response `200`:**
```json
{
  "data": {
    "id": "uuid",
    "name": "Kiosk-Table-A1",
    "type": "kiosk",
    "status": "active",
    "branch": {
      "id": "uuid",
      "name": "Shibuya"
    }
  }
}
```

---

### GET /api/v1/kiosk/orders

Get the open order at a table.

**Query params:**

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `table_id` | UUID | ✅ | ID of the table whose order to view |

**Response `200`:**
```json
{
  "data": {
    "id": "uuid",
    "table_id": "uuid",
    "table_name": "A1",
    "items": [
      {
        "id": "uuid",
        "name": "Ramen",
        "quantity": 2,
        "unit_price": 1200,
        "image_url": "https://..."
      }
    ],
    "subtotal": 2400,
    "discount": 0,
    "total": 2400,
    "currency": "JPY"
  }
}
```

> **Note:** Returns `data: []` if the table has no open order. Full implementation lands when the `CustomerOrder` model is ready.

---

### POST /api/v1/kiosk/payments

Create a payment request for an order.

**Request body:**
```json
{
  "order_id": "uuid",
  "method": "qr",
  "amount": 2400
}
```

**Payment methods:**

| Value | Description |
|-------|-------|
| `cash` | Cash |
| `qr` | QR code (PayPay, LINE Pay, etc.) |
| `card` | Credit/debit card |
| `emoney` | E-money (Suica, Pasmo, etc.) |

**Headers (optional):**

| Header | Type | Description |
|--------|------|-------|
| `Idempotency-Key` | string (max 64) | Prevent duplicate payments on retry. Same key = same payment returned. |

**Response `201`:**
```json
{
  "data": {
    "payment_id": "uuid",
    "reference_no": "PAY-2026-0001",
    "status": "pending",
    "qr_url": null,
    "amount_paid": 2400,
    "expires_at": "2026-04-22T12:15:00+09:00",
    "confirm_type": "manual"
  }
}
```

| Field | Description |
|-------|-------|
| `qr_url` | Always `null` in Phase 1. QR integration (PayPay, LINE Pay) is deferred to Phase 2. |
| `expires_at` | ISO 8601. Pending payments auto-expire after 15 minutes. `null` if auto-confirm. |
| `confirm_type` | `"auto"` (succeeded immediately) or `"manual"` (waits for staff/webhook confirmation). |

---

### GET /api/v1/kiosk/payments/{id}/status

Poll payment status. The Kiosk app calls this endpoint every **3 seconds** until the status is `paid` or `failed`.

**Path params:**

| Param | Description |
|-------|-------|
| `id` | UUID of the payment (from the POST /payments response) |

**Response `200`:**
```json
{
  "data": {
    "id": "uuid",
    "status": "pending"
  }
}
```

**Payment statuses:**

| Status | Description |
|--------|-------|
| `idle` | Not yet initiated |
| `pending` | Awaiting processing |
| `paid` | Payment succeeded → stop polling |
| `failed` | Failed or expired → stop polling, allow retry |

---

## Error Responses

| HTTP | Case |
|------|-----------|
| `401` | Missing or invalid token |
| `401` | Device is not in `active` status |
| `403` | Device type is not `kiosk` |
| `422` | Validation failed (missing field, wrong format) |

---

## Related Files

| File | Role |
|------|---------|
| `routes/api/kiosk.php` | Route definitions (with throttle) |
| `app/Http/Controllers/Api/V1/Kiosk/KioskController.php` | Controller |
| `app/Http/Middleware/AuthenticateDevice.php` | device.auth middleware |
| `app/Services/Customer/OrderPaymentService.php` | Payment business logic (lockForUpdate, expires_at, idempotency) |
| `app/Models/CustomerOrder.php` | `scopeActive()` |
| `app/Models/OrderPayment.php` | `expires_at`, `idempotency_key` |
| `app/Console/Commands/ExpireStalePendingPayments.php` | Scheduled: expire pending payments |
| `app/Omnify/Enums/DeviceTypeEnum.php` | Enum — includes `Kiosk = 'kiosk'` |
| `schemas/Shared/Enum/DeviceType.yaml` | Source of truth for the enum |

---

## Hardening (implemented)

- [x] `lockForUpdate()` on the order row — prevents double-payment race condition
- [x] `expires_at` — pending payments auto-expire after 15 minutes (scheduled command `payments:expire-stale`)
- [x] `Idempotency-Key` header — retry-safe, returns the existing payment on duplicate key
- [x] `throttle:10,1` on POST payments, `throttle:30,1` on GET status
- [x] Double scope `branch_id` + `organization_id` on every query
- [x] Unique constraint `(organization_id, branch_id, code)` on `payment_methods`
- [x] `scopeActive()` on the CustomerOrder model

## TODO — Phase 2

### Confirm path for manual payment methods

The kiosk API is currently complete for **auto-confirm methods** (`is_auto_confirm = true` — e-wallet tap, cash, contactless card). The flow is self-contained: create payment → succeeded immediately → kiosk shows "paid".

For **manual methods** (`is_auto_confirm = false` — QR, bank transfer), the payment is created in `pending` status and a third party must call `OrderPaymentService::confirm()`. If nobody calls it, the payment expires after 15 minutes.

**Implement one of the two (or both):**

| Confirm path | Description | When needed |
|---|---|---|
| **PSP Webhook** | PayPay/LINE Pay calls a webhook when the customer scans the QR successfully → backend calls `confirm()` | When a real QR integration exists |
| **Staff confirm endpoint** | Staff confirms on workstation/admin → calls `POST /payments/{id}/confirm` | When using bank transfer or QR without a webhook |

**End-to-end flow once a confirm path exists:**
```
Customer taps "QR" → payment pending → kiosk shows "Awaiting confirmation" + countdown
                                  → customer scans QR / staff verifies
                                  → webhook/staff calls confirm()
                                  → status = succeeded
                                  → kiosk polls → "paid" → "Thank you!"
```

**`OrderPaymentService::confirm()` already exists** — only the endpoint/webhook caller needs to be created.

### QR payment integration (PayPay, LINE Pay)

- [ ] Integrate PayPay API / LINE Pay API
- [ ] Generate a real `qr_url` in the `POST /kiosk/payments` response (currently returns `null`)
- [ ] Webhook endpoint receiving the PSP callback → calls `OrderPaymentService::confirm()`

### WebSocket instead of polling (Laravel Reverb)

- [ ] Replace `GET /payments/{id}/status` polling with Reverb broadcast
- [ ] `OrderPaymentService::confirm()` emits a `PaymentConfirmed` event → kiosk receives it immediately (~100ms)
      — ⚠️ `PaymentConfirmed` **chưa tồn tại**, đây là tên đề xuất. `backend/app/Events/`
      hôm nay có `OrderPaymentRecorded` (`ShouldBroadcastNow`, dispatch từ
      `OrderPaymentService`); cân nhắc dùng lại thay vì thêm class mới.
      (`OrderPaymentService::confirm()` thì CÓ THẬT —
      `backend/app/Services/Customer/OrderPaymentService.php`, đo 2026-08-07.)
- [ ] WS auth middleware for device tokens
- [ ] Client reconnect + fallback-to-poll logic
