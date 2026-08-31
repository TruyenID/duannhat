---
title: Kiosk customer payment test plan
category: guide
tags: [qa, test-plan, kiosk, payment]
summary: "Test plan for the customer self-payment flow at the kiosk, across both the workstation LAN path and the Cloud fallback path."
related: [api-kiosk]
---

# Test plan — the customer self-payment flow at the kiosk

**Scope**: a customer paying for themselves at a kiosk (godx-kiosk) → the Laravel
backend (`/api/v1/kiosk/*`), either through the workstation LAN or through the
Cloud fallback.
**Date**: 2026-07-06
**Status**: Draft

---

## 1. Goals

Test the customer self-payment flow at the kiosk end to end, covering:
- Creating a payment (cash / card / QR-transfer / e-money).
- The state machine: `pending → succeeded / failed`, and the 15-minute hold
  expiry.
- Manual confirm / fail (cash, e-money, terminal).
- Split bills (equal / by_people / by_items / custom).
- Idempotency, the overpayment guard, throttling and rate limits.
- LAN-first / Cloud-fallback routing, offline tolerance.
- The audit log and PCI safety.

## 2. Architecture and the components involved

| Component | Role |
|---|---|
| godx-kiosk (Expo/RN) | The customer UI: pick a method, enter cash, poll the status |
| Backend `/api/v1/kiosk/*` | The device-token API (`device.auth:kiosk`) |
| Workstation (Go/Wails, LAN) | The LAN gateway; receives metadata as a string |
| Cloud (Laravel) | The fallback when the LAN is lost (30s backoff) |

**The main endpoints** (`backend/routes/api/kiosk.php`):

| Method | Path | Current throttle |
|---|---|---|
| GET | `/kiosk/me` | — |
| GET | `/kiosk/orders` | — |
| POST | `/kiosk/payments` | `throttle:10,1` |
| GET | `/kiosk/payments/{id}/status` | `throttle:30,1` |
| POST | `/kiosk/payments/{payment}/confirm` | — |
| POST | `/kiosk/payments/{payment}/fail` | — |
| GET | `/kiosk/orders/{order}/split-by-items/preview` | — |
| POST | `/kiosk/audit-logs` | 60/min/device |

**Payment states** (`PaymentStatusEnum`): `pending`, `succeeded`, `failed`,
`refunded`. Mapped for the kiosk as: `succeeded → paid`,
`failed|refunded → failed`, everything else `pending`.

## 3. Prerequisites and test data

- A paired kiosk device with a valid Bearer token, attached to an **active
  branch** within the org.
- An order in status `confirmed / open / dining` (so it can move to `checkout`).
- PaymentMethods configured for the org: `cash` (requires_tendered, manual),
  `card` (auto), `transfer/QR` (auto), `e_money` (manual). Both an active and an
  inactive method are needed to test the 422.
- An environment where the LAN can be taken down (workstation stopped) to test the
  Cloud fallback.

## 4. Risks and important notes for QA

> ⚠️ **THROTTLE DISCREPANCY** — `backend/tests/Feature/Kiosk/KioskPaymentThrottleTest.php`
> describes an intended fix: replace `throttle:10,1` (a hard number, keyed by
> **IP**) with a named limiter **`kiosk-payments` = 60/min keyed by device_id**.
> The reasons: (a) 10/min is far too tight when an offline kiosk flushes a batch
> on reconnect; (b) `device.auth` never calls `Auth::shouldUse()`, so the throttle
> keys on the **client IP** and every kiosk behind a branch's NAT shares one
> bucket.
> **The fix is NOT implemented**: the route is still `throttle:10,1`, and there is
> no `kiosk-payments` limiter in `AppServiceProvider`. This test currently
> **FAILS** (40 IP-keyed requests → 429 after the tenth). QA needs to confirm
> whether this is a red test awaiting implementation, or whether the
> route/limiter will be patched.

---

## 5. Test cases

### TC-A — Create a payment (POST /kiosk/payments)

| ID | Title | Steps / input | Expected result |
|---|---|---|---|
| A01 | Valid cash payment | Order in `checkout`, method=cash, amount=10000, tendered=20000 | 201, status per the method (manual → `pending`), returns `payment_id, reference_no, confirm_type='manual'`, change computed correctly |
| A02 | Card payment (auto-confirm) | method=card, amount=10000 | 201, status `paid` (succeeded immediately), `expires_at=null`, `confirm_type='auto'` |
| A03 | QR/transfer payment | method=transfer, amount=10000 | 201, `qr_url=null` (per the current code), status per the auto configuration |
| A04 | Order moves to checkout | Order `confirmed` → POST payment | The order moves to `checkout` and stamps `checkout_at`; the first payment moves the order to `paying` |
| A05 | Missing `order_id` | Omit order_id | 422 validation |
| A06 | Malformed `order_id` | order_id is not a uuid | 422 |
| A07 | Both `method` and `payment_method` missing | Send neither field | 422 (required_without) |
| A08 | `amount` = 0 or negative | amount=0 | 422 (min 1) |
| A09 | `amount` above the maximum | amount=100000000 | 422 (max 99999999) |
| A10 | Device with no branch | The device has no active branch | 422 "Device is not associated with an active branch." |
| A11 | Order outside the branch/org | An order_id from another org | 404 |
| A12 | PaymentMethod missing or inactive | method=cash but inactive | 422 "Payment method not available" |
| A13 | Cash with no tendered amount | method=cash, tendered omitted | 422 "Tendered amount must be provided and must be >= payment amount." |
| A14 | Cash tendered < amount | tendered=5000, amount=10000 | 422 (tendered >= amount) |
| A15 | Branch method beats the system-wide one | Both a branch-scoped and a system method share a code | The branch-scoped one is chosen |
| A16 | Order in the wrong state for a payment | The order is neither `checkout` nor `paying` | 409 (from OrderPaymentService) |

### TC-B — Idempotency and overpayment

| ID | Title | Steps / input | Expected result |
|---|---|---|---|
| B01 | Repeated Idempotency-Key | Two POSTs with the same `(order_id, Idempotency-Key)` | The second returns the **existing payment**; nothing new is created |
| B02 | Same key, different order | The same key but a different order_id | A new payment is created (the key is scoped per order) |
| B03 | Offline flush retry | The kiosk resends a batch after reconnecting with the same keys | No duplicated payments |
| B04 | Overpayment | A succeeded payment plus a pending one that has not expired, and a new payment exceeding the outstanding balance | 422 "Payment amount exceeds the outstanding order balance." |
| B05 | Paying exactly the outstanding amount | The sum equals the order total | 201, success |

### TC-C — Confirm a payment (POST /kiosk/payments/{id}/confirm)

| ID | Title | Steps / input | Expected result |
|---|---|---|---|
| C01 | Confirm a pending cash payment | pending → confirm | Status `succeeded`, `paid_at` stamped |
| C02 | Confirm with terminal_ref/data | Valid terminal_ref and terminal_data | 200, the terminal metadata is merged |
| C03 | terminal_ref too long | >255 characters | 422 |
| C04 | terminal_data of the wrong type | Not an array | 422 |
| C05 | Confirm a payment that does not exist or belongs to another branch | An unknown id | 404 |

### TC-D — Fail a payment (POST /kiosk/payments/{id}/fail)

| ID | Title | Steps / input | Expected result |
|---|---|---|---|
| D01 | Fail a pending payment | Valid reason and error_code | Status `failed`, the error metadata is stored |
| D02 | Fail a non-pending payment | A succeeded payment → fail | 409 "Payment must be 'pending' to fail. Current: X" |
| D03 | reason/error_code too long | reason>255, error_code>50 | 422 |
| D04 | Fail a payment that does not exist | An unknown id | 404 |

### TC-E — Payment status polling (GET /kiosk/payments/{id}/status)

| ID | Title | Steps / input | Expected result |
|---|---|---|---|
| E01 | Poll while pending | A pending payment | `status=pending` |
| E02 | Poll after confirm | A succeeded payment | `status=paid`, the kiosk stops polling |
| E03 | Poll after fail | A failed payment | `status=failed`, the kiosk stops polling |
| E04 | Poll an unknown id or another branch's | An unknown id | 404 |
| E05 | Poll interval | The kiosk polls every 3000ms | It stops on paid/failed and cleans up on unmount |

### TC-F — Split bill / custom

| ID | Title | Steps / input | Expected result |
|---|---|---|---|
| F01 | Equal split | metadata.split_mode=equal, bill_index, total_bills | Each bill is created correctly |
| F02 | Split by_people | split_mode=by_people | Divided correctly by headcount |
| F03 | by_items preview | GET `/orders/{order}/split-by-items/preview` | Returns an accurate per-item preview |
| F04 | Pay a by_items split | split_mode=by_items | Each part is paid correctly |
| F05 | Custom split | split_mode=custom | Arbitrary amounts are allowed |
| F06 | Invalid split_mode | split_mode='foo' | 422 (in equal,by_people,by_items,custom) |
| F07 | Bad bill_index/total_bills | bill_index<0 or total_bills<1 | 422 |
| F08 | Expected-total drift | metadata.expected_total_amount ≠ the real total | 422, code `split_bill_total_drift` (carrying expected/actual) |
| F09 | Label too long | metadata.label>50 | 422 |
| F10 | Close the bill once every part is paid | All bills succeeded | The order closes (a valid prepay/close) |

### TC-G — The 15-minute hold expiry (payments:expire-stale)

| ID | Title | Steps / input | Expected result |
|---|---|---|---|
| G01 | Pending past its expiry | A pending payment past `expires_at` (15m) → run the command | Moves to `failed` |
| G02 | Pending within the window | Within 15m → run the command | Stays `pending` |
| G03 | Succeeded is untouched | A succeeded payment → run the command | Stays `succeeded` |
| G04 | Poll after expiry | The kiosk polls an expired payment | `status=failed`, the UI offers a retry |

### TC-H — Throttling / rate limits

| ID | Title | Steps / input | Expected result |
|---|---|---|---|
| H01 | Payments over the limit | More POSTs per minute than allowed | 429 (⚠️ confirm the limit: currently 10/min by IP, expected 60/min by device) |
| H02 | Several kiosks behind one NAT | Two kiosks sharing the branch IP | ⚠️ They currently share a bucket (a bug) → after the fix they must be keyed per device |
| H03 | Status polling over the limit | >30/min GET status | 429 |
| H04 | Audit log over the limit | >60/min/device | 429 |
| H05 | Batch flush on reconnect | An offline kiosk sends a burst of payments | No 429 (once the 60/min per-device fix lands) |

### TC-I — LAN vs Cloud routing (offline tolerance)

| ID | Title | Steps / input | Expected result |
|---|---|---|---|
| I01 | LAN first | The workstation is reachable over mDNS | Calls go through the workstation proxyUrl |
| I02 | Cloud fallback | The workstation is down | After `markWorkstationUnreachable`, Cloud is used for 30s |
| I03 | Manual URL | An admin manual URL exists in AsyncStorage | The precedence order is respected (mDNS > manual > cloud) |
| I04 | mDNS branch filtering | Several services, TXT.branch_id | The right branch is chosen, ties broken by the highest version |
| I05 | String metadata for the workstation | The kiosk JSON-encodes the metadata | The workstation receives a string; the Cloud controller normalizes it back to an array |
| I06 | Losing the LAN mid-payment | The connection drops while pending | Polling continues through Cloud with no lost payment (idempotency) |

### TC-J — Auth / session guard

| ID | Title | Steps / input | Expected result |
|---|---|---|---|
| J01 | Invalid token | A bad Bearer | 401 |
| J02 | A 401 mid-payment | A 401 while on `/payment/`, `/split/`, `/custom/`, `/success` | Auto-logout is deferred until the flow is left (the transaction is not interrupted) |
| J03 | A 401 outside the payment flow | A 401 on another screen | Normal auto-logout |

### TC-K — Audit log and PCI (POST /kiosk/audit-logs)

| ID | Title | Steps / input | Expected result |
|---|---|---|---|
| K01 | Write a valid audit entry | event, auditable_type (Payment/Order/Terminal), metadata | 201; device_id and branch_id are forced server-side |
| K02 | Metadata too large | >16KB | 422 |
| K03 | Metadata containing a PAN | A run of 13-19 consecutive digits | 422 (PCI block) |
| K04 | auditable_type outside the morph map | An unknown type | 422 |
| K05 | Client forging device_id/branch_id | Sends a different device_id | Overridden; forging is impossible |

### TC-L — End to end (happy path and UX)

| ID | Title | Scenario | Expected result |
|---|---|---|---|
| L01 | E2E cash | Pick cash → enter the money → confirm → print the receipt at `/success` | Payment succeeded, correct change displayed, receipt printed |
| L02 | E2E card auto | Pick card → submit → paid immediately | Goes to success with no confirm step |
| L03 | E2E QR | Pick QR → poll pending → succeeded | The UI updates to paid and stops polling |
| L04 | E2E split success | Split the bill → pay each part → success | All bills paid, the order closes |
| L05 | Retry after a failure | A payment fails → the customer tries again | A new payment is created successfully |
| L06 | Bill lookup | Look up on the `bill.tsx` screen | The bill information is displayed correctly |

---

## 6. Pass/fail criteria

- **Pass**: every High-priority case (A, B, C, D, E, G, L) passes; no payment is
  duplicated; no money is lost when the LAN drops.
- **Blocker**: duplicated payments (idempotency failure), an unblocked
  overpayment, a PAN reaching the audit log, or a throttle key so wrong that
  kiosks block each other.

## 7. References

- `backend/routes/api/kiosk.php`
- `backend/app/Http/Controllers/Api/V1/Kiosk/KioskController.php` (`pay`, `paymentStatus`, `confirmPayment`, `failPayment`, `auditLog`)
- `backend/app/Services/Customer/OrderPaymentService.php` (`create`, `confirm`)
- `backend/app/Omnify/Enums/PaymentStatusEnum.php`
- `backend/app/Providers/AppServiceProvider.php` (limiters)
- `backend/tests/Feature/Kiosk/*` (KioskPaymentThrottleTest, KioskPaymentsTest, KioskPaymentConfirmFailTest, KioskPaymentStatusTest, KioskPaymentMetadataTest, ExpireStalePendingPaymentsTest, KioskAuditLogTest, KioskPrepayCloseTest)
- `app/kiosk/src/hooks/use-payment.ts`, `app/kiosk/app/payment/*.tsx`, `app/kiosk/src/services/workstation/*`, `app/kiosk/src/lib/payment-flow-routes.ts`
