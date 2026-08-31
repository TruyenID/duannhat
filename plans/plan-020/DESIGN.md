# Dine-in Stripe Payment Logic

> **⚠️ ACCURACY NOTE (updated after implementation)**
>
> Sections 1–6 below are an EARLY DRAFT and describe a **synchronous
> `POST /api/v1/customer/orders/{id}/checkout`** endpoint that **was never
> implemented**. There is no customer `/checkout` route. The draft also uses
> `status = 'confirmed' | 'paid'` enum values that do not exist
> (`CustomerOrderStatusEnum` is `Open | Dining | Checkout | Paying | Closed |
> Voided`; the fully-paid state is `Closed`).
>
> The **shipped** design is the asynchronous **PaymentIntent + Stripe webhook**
> flow documented from the second **"## Overview"** section onward. The real
> customer endpoints are:
>
> - `POST /api/v1/customer/orders/{id}/full-payment-intent` — charges the
>   remaining balance (`total_amount − paid_amount`).
> - `POST /api/v1/customer/orders/{id}/split-payment-intent` — charges a custom
>   split slice (`≤ remaining`).
>
> Both create a Stripe PaymentIntent; the client confirms it with Stripe.js and
> the ledger is written by `StripePaymentService::markOrderPaidFromIntent()`
> (fired by the `payment_intent.succeeded` webhook, with a synchronous
> `confirmAndRecordPayment()` fast-path). Both flows increment `paid_amount` by
> the ACTUAL charged slice under a per-order `lockForUpdate()` and reject any
> slice that would push the collected total past `total_amount` — so a full and
> a split intent racing on the same order can never overpay. Treat sections 1–6
> as historical intent only.

## Overview

Dine-in orders trong customer-web hỗ trợ 2 loại thanh toán:
1. **Full Payment** — 1 người thanh toán toàn bộ bill
2. **Split Bill** — Nhiều người chia bill, mỗi người thanh toán 1 phần

## 1. Full Payment Flow

### User Journey
1. Khách scan QR bàn → vào trang order của bàn
2. Xem order hiện tại (món, tổng tiền, trạng thái)
3. Click "Thanh toán" → chọn "Thanh toán toàn bộ"
4. Nhập thông tin thẻ Stripe
5. Submit → tạo PaymentIntent → charge toàn bộ `order.total_amount`
6. Success → order chuyển sang `paid`, hiển thị màn hình "Đã thanh toán"

### Backend API Flow

```
POST /api/v1/customer/orders/{orderId}/checkout
Body: {
  "payment_method": "stripe",
  "stripe_payment_method_id": "pm_xxx",  // từ Stripe.js
  "amount": order.total_amount            // toàn bộ bill
}

→ Backend:
  1. Validate order.status === 'confirmed' (chưa thanh toán)
  2. Create Stripe PaymentIntent với amount
  3. Confirm payment
  4. Create OrderPayment record (status: succeeded)
  5. Update order.paid_amount += amount
  6. If order.paid_amount >= order.total_amount:
     - Set order.status = 'paid'
     - Set order.checkout_at = now()
  7. Return success

Response: {
  "success": true,
  "order": { ... },
  "payment": { "id": "...", "amount": "...", "status": "succeeded" }
}
```

### Database Schema

**`order_payments` table:**
```
id                          ULID PK
customer_order_id           FK → customer_orders.id
payment_method_id           FK → payment_methods.id (Stripe)
payment_code                VARCHAR(50) UNIQUE
amount                      DECIMAL(10,2)
status                      ENUM('pending','succeeded','failed','refunded')
stripe_payment_intent_id    VARCHAR(255) NULLABLE
stripe_charge_id            VARCHAR(255) NULLABLE
stripe_refund_id            VARCHAR(255) NULLABLE
paid_at                     TIMESTAMP NULLABLE
organization_id             FK
branch_id                   FK
brand_id                    FK
created_at, updated_at
```

**`customer_orders` table:**
```
...
total_amount                DECIMAL(10,2)  -- Tổng bill
paid_amount                 DECIMAL(10,2)  -- Đã thanh toán (tổng các payments succeeded)
status                      ENUM(..., 'paid', ...)
checkout_at                 TIMESTAMP NULLABLE  -- Thời điểm thanh toán xong
```

## 2. Split Bill Flow

### User Journey

**Người 1 (initiator):**
1. Scan QR → xem order
2. Click "Thanh toán" → chọn "Chia bill"
3. Nhập số tiền mình muốn trả (ví dụ: ¥2000 / tổng ¥5000)
4. Nhập thẻ Stripe → Submit
5. Backend tạo payment ¥2000, `order.paid_amount` = ¥2000
6. Order vẫn ở trạng thái `confirmed` (chưa thanh toán đủ)
7. Hiển thị màn hình "Đã thanh toán ¥2000. Còn lại ¥3000"

**Người 2:**
1. Scan cùng QR → xem order (thấy đã có ¥2000 paid)
2. Click "Thanh toán" → chọn "Chia bill"
3. Nhập ¥1500
4. Submit → `order.paid_amount` = ¥3500, còn lại ¥1500

**Người 3:**
1. Scan QR → thấy còn lại ¥1500
2. Click "Thanh toán toàn bộ" (hoặc "Chia bill" nhập ¥1500)
3. Submit → `order.paid_amount` = ¥5000 ≥ `order.total_amount`
4. Backend tự động set `order.status = 'paid'`, `checkout_at = now()`
5. Tất cả người scan sau đó thấy "Đã thanh toán đủ"

### Backend Logic (Split Bill)

```
POST /api/v1/customer/orders/{orderId}/checkout
Body: {
  "payment_method": "stripe",
  "stripe_payment_method_id": "pm_xxx",
  "amount": 2000  // partial amount (< total_amount)
}

→ Backend validation:
  1. Check order.status === 'confirmed' (not 'paid' yet)
  2. Calculate remaining = order.total_amount - order.paid_amount
  3. If amount > remaining:
     - Reject with error "Amount exceeds remaining balance"
  4. Create Stripe PaymentIntent với amount
  5. Confirm payment
  6. Create OrderPayment record
  7. Update order.paid_amount += amount
  8. If order.paid_amount >= order.total_amount:
     - Set order.status = 'paid'
     - Set order.checkout_at = now()
  9. Return success with remaining info

Response: {
  "success": true,
  "order": {
    "total_amount": 5000,
    "paid_amount": 2000,
    "status": "confirmed"  // vẫn chưa 'paid'
  },
  "payment": { "amount": 2000, "status": "succeeded" },
  "remaining": 3000
}
```

### Frontend State Management

**`customer-web/.../table/[qrToken]/page.tsx`:**

```typescript
const { data: orderData } = useCustomerOrder(qrToken);
const order = orderData?.data;

// Tính remaining từ payments array (source of truth)
const totalAmount = Number(order?.total_amount ?? 0);
const paidAmount = (order?.payments ?? [])
  .filter((p) => p.status === "succeeded")
  .reduce((sum, p) => sum + Number(p.amount), 0);
const remaining = totalAmount - paidAmount;

// UI logic
if (remaining <= 0) {
  // Hiển thị "Đã thanh toán đủ" screen
  return <PaidView order={order} />;
}

if (order?.status === "confirmed") {
  // Hiển thị nút "Thanh toán"
  // Khi click → mở PaymentView với options:
  // - "Thanh toán toàn bộ" (amount = remaining)
  // - "Chia bill" (cho phép nhập amount tùy ý, max = remaining)
}
```

### Payment View Component

```typescript
// customer-web/.../components/payment-view.tsx
function PaymentView({ order }: { order: Order }) {
  const [mode, setMode] = useState<"full" | "split">("full");
  const [customAmount, setCustomAmount] = useState("");

  const totalAmount = Number(order.total_amount);
  const paidAmount = calculatePaidAmount(order.payments);
  const remaining = totalAmount - paidAmount;

  const amountToPay = mode === "full"
    ? remaining
    : Number(customAmount) || 0;

  const handleSubmit = async () => {
    // Validate
    if (amountToPay <= 0) {
      toast.error("Số tiền phải lớn hơn 0");
      return;
    }
    if (amountToPay > remaining) {
      toast.error(`Số tiền không được vượt quá còn lại ¥${remaining}`);
      return;
    }

    // Create Stripe PaymentMethod
    const { paymentMethod, error } = await stripe.createPaymentMethod({
      type: "card",
      card: cardElement,
    });

    if (error) {
      toast.error(error.message);
      return;
    }

    // Call backend
    const result = await fetch(`/api/v1/customer/orders/${order.id}/checkout`, {
      method: "POST",
      body: JSON.stringify({
        payment_method: "stripe",
        stripe_payment_method_id: paymentMethod.id,
        amount: amountToPay,
      }),
    });

    if (result.success) {
      if (result.remaining > 0) {
        toast.success(`Đã thanh toán ¥${amountToPay}. Còn lại ¥${result.remaining}`);
        // Refresh order data
        refetch();
      } else {
        // Paid in full → navigate to success screen
        router.push(`/paid?orderId=${order.id}`);
      }
    }
  };

  return (
    <div>
      <div>Tổng bill: ¥{totalAmount}</div>
      <div>Đã thanh toán: ¥{paidAmount}</div>
      <div className="text-red-600">Còn lại: ¥{remaining}</div>

      <div className="mt-4">
        <label>
          <input
            type="radio"
            checked={mode === "full"}
            onChange={() => setMode("full")}
          />
          Thanh toán toàn bộ (¥{remaining})
        </label>
        <label>
          <input
            type="radio"
            checked={mode === "split"}
            onChange={() => setMode("split")}
          />
          Chia bill
        </label>
      </div>

      {mode === "split" && (
        <input
          type="number"
          placeholder="Nhập số tiền"
          value={customAmount}
          onChange={(e) => setCustomAmount(e.target.value)}
          max={remaining}
        />
      )}

      <CardElement />

      <button onClick={handleSubmit}>
        Thanh toán ¥{amountToPay.toLocaleString()}
      </button>
    </div>
  );
}
```

## 3. Edge Cases & Validations

### Backend Validations

1. **Amount validation:**
   ```php
   if ($amount <= 0) {
       throw new ValidationException('Amount must be greater than 0');
   }

   $remaining = $order->total_amount - $order->paid_amount;
   if ($amount > $remaining) {
       throw new ValidationException("Amount exceeds remaining balance: ¥{$remaining}");
   }
   ```

2. **Order status check:**
   ```php
   if ($order->status === 'paid') {
       throw new ValidationException('Order already fully paid');
   }

   if ($order->status !== 'confirmed') {
       throw new ValidationException('Order must be confirmed before payment');
   }
   ```

3. **Race condition (2 người thanh toán cùng lúc):**
   ```php
   DB::transaction(function () use ($order, $amount) {
       // Lock order row
       $order = CustomerOrder::lockForUpdate()->find($order->id);

       $remaining = $order->total_amount - $order->paid_amount;
       if ($amount > $remaining) {
           throw new ValidationException("Payment rejected: remaining changed to ¥{$remaining}");
       }

       // Create payment
       $payment = OrderPayment::create([...]);

       // Update paid_amount
       $order->paid_amount += $amount;
       if ($order->paid_amount >= $order->total_amount) {
           $order->status = 'paid';
           $order->checkout_at = now();
       }
       $order->save();
   });
   ```

4. **Stripe payment failure:**
   ```php
   try {
       $paymentIntent = $stripe->paymentIntents->create([...]);
       $paymentIntent->confirm();
   } catch (StripeException $e) {
       // Create OrderPayment with status='failed'
       OrderPayment::create([
           'status' => PaymentStatusEnum::Failed,
           'error_message' => $e->getMessage(),
       ]);
       throw $e;
   }
   ```

### Frontend Edge Cases

1. **Order đã thanh toán đủ:**
   ```typescript
   if (remaining <= 0) {
     return <div>Bill đã được thanh toán đủ</div>;
   }
   ```

2. **Người khác vừa thanh toán (real-time update):**
   ```typescript
   // Poll order every 5s
   useEffect(() => {
     const interval = setInterval(() => {
       refetch();
     }, 5000);
     return () => clearInterval(interval);
   }, []);

   // Hoặc dùng WebSocket (future enhancement)
   ```

3. **Network error khi submit:**
   ```typescript
   try {
     await submitPayment();
   } catch (error) {
     if (error.response?.status === 409) {
       // Conflict — order state changed
       toast.error("Bill đã được cập nhật bởi người khác. Vui lòng tải lại.");
       refetch();
     } else {
       toast.error("Lỗi kết nối. Vui lòng thử lại.");
     }
   }
   ```

## 4. Database Queries

### Get order with payments (Admin view):

```sql
SELECT
  o.*,
  o.total_amount,
  o.paid_amount,
  (o.total_amount - o.paid_amount) as remaining,
  COUNT(p.id) as payment_count
FROM customer_orders o
LEFT JOIN order_payments p ON p.customer_order_id = o.id AND p.status = 'succeeded'
WHERE o.id = ?
GROUP BY o.id
```

### Get payment history for an order:

```sql
SELECT
  p.*,
  pm.name as payment_method_name,
  p.created_at as paid_at
FROM order_payments p
JOIN payment_methods pm ON pm.id = p.payment_method_id
WHERE p.customer_order_id = ?
  AND p.status = 'succeeded'
ORDER BY p.created_at ASC
```

### Calculate remaining in real-time:

```php
// In CustomerOrderResource
public function toArray($request): array
{
    $totalAmount = (float) $this->total_amount;
    $succeededPayments = $this->whenLoaded('payments', function () {
        return $this->payments->where('status', 'succeeded');
    }, collect([]));

    $paidAmount = $succeededPayments->sum('amount');
    $remaining = max(0, $totalAmount - $paidAmount);

    return [
        'id' => $this->id,
        'total_amount' => $totalAmount,
        'paid_amount' => $paidAmount,
        'remaining' => $remaining,
        'payments' => OrderPaymentResource::collection($succeededPayments),
        'status' => $this->status,
        'is_fully_paid' => $remaining <= 0,
    ];
}
```

## 5. Admin View Logic

Trong admin order detail page, cần hiển thị:

1. **Tổng bill**: `order.total_amount`
2. **Đã thanh toán**: Sum của `order.payments.where(status=succeeded).sum(amount)`
3. **Còn nợ**: `total_amount - paid_amount` (chỉ hiện nếu > 0)
4. **Danh sách payments**:
   ```
   - Stripe: ¥2000 (2024-01-01 10:30)
   - Stripe: ¥1500 (2024-01-01 10:35)
   - Stripe: ¥1500 (2024-01-01 10:40)
   ```

**Logic:**

```typescript
const totalAmount = Number(order.total_amount);
const succeededPayments = (order.payments ?? []).filter(p => p.status === "succeeded");
const paidAmount = succeededPayments.reduce((sum, p) => sum + Number(p.amount), 0);
const remaining = totalAmount - paidAmount;

return (
  <div>
    <dt>Tổng</dt>
    <dd>¥{totalAmount.toLocaleString()}</dd>

    {remaining > 0 && (
      <>
        <dt>Đã thanh toán</dt>
        <dd>¥{paidAmount.toLocaleString()}</dd>

        <dt className="text-red-600">Còn nợ</dt>
        <dd className="text-red-600">¥{remaining.toLocaleString()}</dd>
      </>
    )}

    {succeededPayments.length > 0 && (
      <>
        <dt>Hình thức thanh toán</dt>
        <dd>
          {succeededPayments.map(p => (
            <div key={p.id}>
              {p.payment_method?.name ?? 'Unknown'}: ¥{Number(p.amount).toLocaleString()}
            </div>
          ))}
        </dd>
      </>
    )}
  </div>
);
```

## 6. Testing Scenarios

### Manual Testing Checklist:

- [ ] **Full payment**: 1 người thanh toán toàn bộ → order chuyển `paid`
- [ ] **Split 2 people**: Người 1 trả ¥2000, Người 2 trả ¥3000 → order `paid`
- [ ] **Split 3+ people**: Test với 3-4 người chia bill
- [ ] **Overpayment rejection**: Nhập amount > remaining → backend reject
- [ ] **Zero/negative amount**: Backend reject
- [ ] **Stripe failure**: Test với test card bị decline → payment status `failed`
- [ ] **Race condition**: 2 người submit cùng lúc → 1 người fail (remaining changed)
- [ ] **Already paid order**: Scan QR sau khi đã thanh toán đủ → hiển thị "Đã thanh toán"
- [ ] **Admin view**: Kiểm tra admin thấy đầy đủ payment history + remaining đúng

### Automated Tests (Pest):

```php
// backend/tests/Feature/Customer/SplitBillPaymentTest.php

test('full payment marks order as paid', function () {
    $order = CustomerOrder::factory()->create([
        'total_amount' => 5000,
        'paid_amount' => 0,
        'status' => 'confirmed',
    ]);

    $response = $this->postJson("/api/v1/customer/orders/{$order->id}/checkout", [
        'payment_method' => 'stripe',
        'stripe_payment_method_id' => 'pm_card_visa',
        'amount' => 5000,
    ]);

    $response->assertStatus(200);
    $order->refresh();
    expect($order->status)->toBe('paid');
    expect($order->paid_amount)->toBe(5000.0);
});

test('split payment keeps order confirmed until fully paid', function () {
    $order = CustomerOrder::factory()->create([
        'total_amount' => 5000,
        'paid_amount' => 0,
    ]);

    // First payment
    $this->postJson("/api/v1/customer/orders/{$order->id}/checkout", [
        'amount' => 2000,
    ]);

    $order->refresh();
    expect($order->status)->toBe('confirmed');
    expect($order->paid_amount)->toBe(2000.0);

    // Second payment
    $this->postJson("/api/v1/customer/orders/{$order->id}/checkout", [
        'amount' => 3000,
    ]);

    $order->refresh();
    expect($order->status)->toBe('paid');
    expect($order->paid_amount)->toBe(5000.0);
});

test('rejects payment exceeding remaining amount', function () {
    $order = CustomerOrder::factory()->create([
        'total_amount' => 5000,
        'paid_amount' => 4000,
    ]);

    $response = $this->postJson("/api/v1/customer/orders/{$order->id}/checkout", [
        'amount' => 2000,  // > remaining (1000)
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('amount');
});
```

## Overview

Luồng thanh toán Stripe cho dine-in customer (customer-web) cho phép khách hàng ngồi bàn tại nhà hàng thanh toán online qua thẻ tín dụng/debit card ngay trên điện thoại mà không cần gọi nhân viên.

## Architecture

```
┌─────────────────┐
│  Customer Web   │ → Stripe Elements UI (card input)
│  (Next.js 16)   │ → POST /full-payment-intent → Laravel
└────────┬────────┘
         │
         ↓
┌─────────────────┐
│  Backend API    │ → Stripe::PaymentIntent::create()
│  (Laravel 13)   │ → Webhook: payment_intent.succeeded
└────────┬────────┘
         │
         ↓
┌─────────────────┐
│  Database       │ → order_payments.stripe_payment_intent_id
│  (MySQL)        │ → orders.paid_amount (cập nhật qua webhook)
└─────────────────┘
```

## Payment Flow (Step-by-step)

### 1. Payment View Selection
**File**: `customer-web/app/[locale]/dine-in/[shop]/table/[qrToken]/components/payment-view.tsx`

- User đã order món → navigate to Payment View (`view = "payment"`)
- Chọn payment method: `online` (Stripe) hoặc `counter` (trả tiền tại quầy)
- Nếu chọn `online`:
  - Hiện `StripeCardSection` component
  - User nhập thông tin thẻ (card number, expiry, CVC) → Stripe Elements UI

### 2. Client-side Validation
**Line**: `payment-view.tsx:50-55`

```typescript
const v = await stripeCardRef.current?.validate();
if (v?.error) {
  setPayError(v.error);
  return;
}
```

- Gọi `StripeCardSection.validate()` → check card validity (client-side)
- Nếu card invalid → hiện error, dừng flow

### 3. Create Payment Intent (Backend)
**API**: `POST /api/v1/customer/orders/{id}/full-payment-intent`
**Controller**: `CustomerOrderController@createFullPaymentIntent`
**Service**: `StripePaymentService@createFullPaymentIntent()`

**Line**: `payment-view.tsx:57-62`

```typescript
const intentRes = await apiFetch(`/api/v1/customer/orders/${order.id}/full-payment-intent`, {
  method: "POST",
});
// intentRes.data = { client_secret, payment_intent_id }
```

**Backend logic**:
1. Tìm order by ID
2. Tính `amount = order.total_amount - order.paid_amount` (số tiền còn lại)
3. Gọi Stripe API: `\Stripe\PaymentIntent::create(['amount' => $amount, 'currency' => 'jpy'])`
4. Lưu `payment_intent_id` vào `order_payments` table (status = `pending`)
5. Trả về `client_secret` cho frontend

### 4. Confirm Payment (Client-side with Stripe)
**Line**: `payment-view.tsx:71-74`

```typescript
const confirmRes = await stripeCardRef.current?.confirm(
  intentRes.data.client_secret,
  returnUrl, // success redirect URL
);
```

**Logic** (`StripeCardSection.confirm()`):
1. Gọi `stripe.confirmCardPayment(client_secret, { payment_method: { card: cardElement } })`
2. Stripe xử lý:
   - Validate card
   - Charge card
   - 3D Secure authentication (nếu cần → redirect user)
3. Return `{ succeeded: true }` hoặc `{ error: '...' }`

**Lưu ý**:
- Payment **succeeded** ở Stripe **NGAY LẬP TỨC** (synchronous)
- Nhưng webhook cập nhật DB **BẤT ĐỒNG BỘ** (asynchronous) → cần polling

### 5. Polling to Wait for Webhook Update
**Line**: `payment-view.tsx:82-101`

```typescript
let attempts = 0;
const maxAttempts = 20; // 20 × 500ms = 10s
while (attempts < maxAttempts) {
  await new Promise((r) => setTimeout(r, 500));
  const checkRes = await apiFetch(`/api/v1/customer/orders/${order.id}`);
  if (checkRes.data.order.paid >= checkRes.data.order.total) {
    // Fully paid! Webhook đã update DB
    onConfirmed(checkRes.data.order);
    return;
  }
  attempts++;
}
```

**Tại sao cần polling?**
- Stripe webhook gọi backend **BẤT ĐỒNG BỘ** (có thể delay 1-3 giây)
- Frontend **không thể biết** khi nào webhook update xong
- → Poll `GET /orders/{id}` mỗi 500ms để check `order.paid_amount`
- Timeout sau 10s → vẫn hiện success (payment đã thành công, chỉ DB chưa update)

### 6. Stripe Webhook (Backend, Async)
**Endpoint**: `POST /webhooks/stripe`
**Controller**: `StripeWebhookController@handleWebhook`
**Event**: `payment_intent.succeeded`

**Logic**:
1. Verify Stripe signature (`\Stripe\Webhook::constructEvent()`)
2. Parse event type: `payment_intent.succeeded`
3. Lấy `payment_intent_id` từ event payload
4. Tìm `OrderPayment` by `stripe_payment_intent_id`
5. Update `OrderPayment`:
   - `status = 'succeeded'`
   - `paid_at = now()`
6. Update `CustomerOrder`:
   - `paid_amount += payment.amount`
   - Nếu `paid_amount >= total_amount` → `status = 'closed'`
7. Return 200 OK to Stripe

### 7. Polling Detects Update → Navigate to Paid View
**Line**: `payment-view.tsx:91-96`

- Polling loop detect `order.paid >= order.total`
- Show success toast
- Call `onConfirmed(order)` → parent component set `view = "paid"`
- **Paid View** hiện receipt + order summary

## Key Components

### `StripeCardSection` (`customer-web/components/stripe-card-section.tsx`)
- Wrapper around Stripe Elements (CardElement)
- Methods:
  - `validate()`: Check card validity (client-side)
  - `confirm(client_secret, returnUrl)`: Confirm payment with Stripe

### `PaymentView` (`customer-web/app/[locale]/dine-in/.../components/payment-view.tsx`)
- UI: payment method selector (online / counter)
- Handle Stripe payment flow
- Polling logic

### `StripePaymentService` (`backend/app/Services/Customer/StripePaymentService.php`)
- `createFullPaymentIntent()`: Create Stripe PaymentIntent
- Handle Stripe API calls

### `StripeWebhookController` (`backend/app/Http/Controllers/StripeWebhookController.php`)
- Verify webhook signature
- Process `payment_intent.succeeded` event
- Update DB

## Error Handling

| Error | Handling |
|-------|----------|
| Card invalid (client-side) | Show error message, block payment |
| Stripe API error (create intent) | Show API error, retry allowed |
| Card declined (Stripe) | Show decline reason, retry allowed |
| 3DS required | Redirect to 3DS page, return after auth |
| Webhook timeout (>10s) | Still show success (payment succeeded, DB will update eventually) |

## Database Schema

### `order_payments` table
```sql
id                         char(36)     PK
customer_order_id          char(36)     FK → customer_orders.id
payment_method_id          char(36)     FK → payment_methods.id
payment_code               varchar      UNIQUE
amount                     decimal
status                     enum (pending, succeeded, failed)
stripe_payment_intent_id   varchar      UNIQUE (nullable)
paid_at                    timestamp    (nullable)
```

### `customer_orders` table
```sql
id                char(36)   PK
order_code        varchar    UNIQUE
total_amount      decimal
paid_amount       decimal    (updated by webhook)
status            enum       (open, confirmed, preparing, ready, closed, cancelled)
```

## Testing

**Manual test**:
1. Mở customer-web dine-in flow: http://localhost:5450/vi/dine-in/ikb/table/{qrToken}
2. Order món → navigate to Payment
3. Chọn "Online" → nhập test card: `4242 4242 4242 4242`, expiry `12/34`, CVC `123`
4. Click "Pay Now"
5. → Polling → navigate to Paid View

**Test cards** (Stripe test mode):
- Success: `4242 4242 4242 4242`
- Declined: `4000 0000 0000 0002`
- 3DS required: `4000 0027 6000 3184`
