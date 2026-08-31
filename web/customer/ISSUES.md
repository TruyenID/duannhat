# Customer Web — Open Issues & Tasks

## 🔴 Critical — Cart Timeout Implementation

### Issue #1: Cart timeout validation chưa được implement

**Current state:**
- ✅ Backend đã có 4-tier timeout config (Brand → HQ Menu → Shop → Shop Menu)
- ✅ Admin UI đã có đầy đủ CRUD cho timeout settings
- ❌ **Customer-web KHÔNG CÓ logic enforce timeout**

**Problem:**
Cart items được lưu vào `localStorage` vô thời hạn. Không có:
- Tracking `cart_created_at` timestamp
- Fetch `effective_timeout_minutes` từ backend
- Countdown timer UI
- Validation khi checkout
- Auto-clear expired items

**Expected behavior (theo `docs/plan-menu-timeout-button.md`):**

```
Schedule end_time                Cart timeout deadline
      │                                   │
      │◄──── timeout_minutes ────────────►│
      ▼                                   ▼
Menu schedule kết thúc          Sản phẩm bị disabled
Countdown bắt đầu               Không thể checkout
```

**Validation rule:**
```
deadline = schedule.end_time (hôm nay) + effective_timeout_minutes
if (now > deadline) → REJECT order
```

**Required changes:**

### 1. Backend API — Cart Timeout Config Endpoint

**New endpoint:**
```
GET /api/v1/customer/branches/{slug}/cart-timeout
```

**Response:**
```json
{
  "data": {
    "effective_timeout_minutes": 30,
    "current_menu_schedules": [
      {
        "menu_id": "...",
        "menu_name": "Lunch Menu",
        "start_time": "11:00",
        "end_time": "14:00",
        "timeout_deadline": "2026-05-11T14:30:00+07:00"
      }
    ]
  }
}
```

**Location:** `backend/app/Http/Controllers/Api/V1/Customer/CustomerBranchController.php`

---

### 2. Frontend — Cart Context Enhancement

**File:** `web/customer/context/cart-context.tsx`

**Changes needed:**

```typescript
// Add to localStorage schema
const CART_METADATA_KEY = "betoya-cart-metadata";

interface CartMetadata {
  created_at: string;          // ISO timestamp khi add item đầu tiên
  branch_slug: string;          // Branch context
  timeout_minutes: number | null; // Cached từ API
}

// Add to CartContextValue
interface CartContextValue {
  // ... existing fields
  cartMetadata: CartMetadata | null;
  timeoutDeadline: Date | null;  // computed
  isExpired: boolean;            // computed
  secondsUntilExpiry: number;    // for countdown
}
```

**Logic:**
1. Khi `addToCart()` lần đầu (empty → has items):
   - Fetch `/api/v1/customer/branches/{slug}/cart-timeout`
   - Store `{ created_at: new Date().toISOString(), timeout_minutes }` vào localStorage
2. Mỗi render:
   - Compute `deadline = created_at + timeout_minutes`
   - Compute `isExpired = now > deadline`
   - Nếu expired → auto-clear cart + show toast warning
3. Khi `clearCart()`:
   - Remove `CART_METADATA_KEY`

---

### 3. Frontend — UI Components

#### 3a. Cart Drawer — Countdown Timer

**File:** `web/customer/components/cart-drawer.tsx`

**Add below cart items list:**
```tsx
{!isExpired && timeoutDeadline && (
  <div className="flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm">
    <Clock className="h-4 w-4 text-amber-600" />
    <span className="text-amber-900">
      {t('cart.expires_in', { minutes: Math.floor(secondsUntilExpiry / 60) })}
    </span>
  </div>
)}
```

#### 3b. Checkout Page — Validation

**File:** `web/customer/components/checkout-page.tsx`

**Before submit:**
```tsx
if (isExpired) {
  toast.error(t('cart.expired_error'));
  clearCart();
  router.push(`/${locale}/takeaway/${branchSlug}`);
  return;
}
```

---

### 4. i18n Messages

**Files:** `web/customer/messages/{ja,en,vi}.json`

```json
{
  "cart": {
    "expires_in": "Giỏ hàng hết hạn sau {minutes} phút",
    "expired_error": "Giỏ hàng đã hết hạn. Vui lòng chọn lại sản phẩm.",
    "timeout_warning": "Menu sẽ kết thúc trong {minutes} phút. Vui lòng đặt hàng sớm."
  }
}
```

---

## 🟡 Medium Priority

### Issue #2: Order status validation

**Fixed in backend:**
- ✅ `CustomerOrderController` đã thêm check `in_array($order->status, ['closed', 'voided'])`

**Customer-web needs:**
- Handle 422 error khi order đã closed/voided
- Show user-friendly message
- Redirect về order history

---

### Issue #3: Stale pickup time handling

**Current:** ✅ Đã có logic clear stale `scheduled_pickup_time`

```tsx
if (parsed.scheduled_pickup_time && new Date(parsed.scheduled_pickup_time).getTime() <= Date.now()) {
  setPickupTimeDataState(DEFAULT_PICKUP_TIME);
  localStorage.removeItem(PICKUP_TIME_KEY);
}
```

**No action needed** — already implemented correctly.

---

## 🟢 Low Priority

### Issue #4: Cart validation schema

**Consider adding Zod schema cho cart items** để validate structure trước khi hydrate từ localStorage:

```tsx
const CartItemSchema = z.object({
  id: z.string(),
  sku: z.object({ /* ... */ }),
  product: z.object({ /* ... */ }),
  quantity: z.number().min(1),
  unitPrice: z.number(),
  toppings: z.array(/* ... */).optional(),
});
```

**Benefit:** Type-safe + runtime validation, prevent corrupted localStorage data crashes.

---

## Priority Order

1. **🔴 Issue #1: Cart timeout** — Critical UX + backend đã sẵn sàng
2. **🟡 Issue #2: Order status validation** — User-facing error handling
3. **🟢 Issue #4: Cart schema validation** — Tech debt, không blocking

---

## Related Files

- `web/customer/context/cart-context.tsx` — Core cart logic
- `web/customer/components/cart-drawer.tsx` — Cart UI
- `web/customer/components/checkout-page.tsx` — Checkout flow
- `docs/plan-menu-timeout-button.md` — Full spec (backend reference)
- `plans/plan-020/` — Split bill payment (separate feature)
