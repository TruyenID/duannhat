# Issue: Cart Timeout Implementation (Customer Web)

**Priority:** 🔴 Critical  
**Status:** Not Started  
**Estimate:** 8-12 hours  
**Related:** `docs/plan-menu-timeout-button.md`, `plans/plan-020/`

---

## Problem Statement

Backend đã có **4-tier cart timeout config** (Brand → HQ Menu → Shop → Shop Menu) hoàn chỉnh với admin UI, nhưng **customer-web không enforce timeout**.

**Current behavior:**
- ✅ Cart items lưu `localStorage` vô thời hạn
- ❌ Không track `created_at` timestamp
- ❌ Không fetch `effective_timeout_minutes` từ backend
- ❌ Không có countdown timer
- ❌ Không validate khi checkout
- ❌ Không auto-clear expired items

**Expected behavior (theo spec):**

```
Schedule end_time                     Cart timeout deadline
      │                                        │
      │◄────── timeout_minutes ────────────────►│
      ▼                                        ▼
Menu kết thúc                         Giỏ hàng expired
Countdown bắt đầu                     Checkout bị block
```

---

## Technical Requirements

### 1. Backend API Endpoint

**New endpoint:**
```
GET /api/v1/customer/branches/{slug}/cart-config
```

**Response:**
```json
{
  "data": {
    "effective_timeout_minutes": 30,
    "current_menu_end_time": "14:00:00",
    "timeout_deadline_iso": "2026-05-11T14:30:00+07:00"
  }
}
```

**Controller:** `backend/app/Http/Controllers/Api/V1/Customer/CustomerBranchController.php`

**Logic:**
```php
// Resolve timeout theo 4-tier chain
$menu = Menu::where('branch_id', $branch->id)
    ->whereActive()
    ->with('schedules')
    ->first();

$effectiveTimeout = $menu->cart_timeout_minutes          // Tier 4
    ?? $branch->cart_timeout_minutes                     // Tier 3
    ?? $menu->masterMenu?->cart_timeout_minutes          // Tier 2
    ?? $branch->brand?->cart_timeout_minutes;            // Tier 1

// Tìm schedule hiện tại (nếu có)
$now = Carbon::now($branch->timezone);
$todaySchedule = $menu->schedules
    ->where('is_active', true)
    ->where('days_of_week', '&', 1 << $now->dayOfWeek)
    ->sortBy('end_time')
    ->first();

$deadline = $todaySchedule 
    ? Carbon::parse($todaySchedule->end_time, $branch->timezone)
        ->addMinutes($effectiveTimeout)
    : null;
```

---

### 2. Frontend — Cart Context Enhancement

**File:** `web/customer/context/cart-context.tsx`

**Add localStorage schema:**
```typescript
const CART_METADATA_KEY = "betoya-cart-metadata";

interface CartMetadata {
  created_at: string;           // ISO timestamp khi add item đầu tiên
  branch_slug: string;          // Branch context để validate
  timeout_minutes: number | null;
  deadline_iso: string | null;  // Computed deadline từ API
}
```

**Add to CartContextValue:**
```typescript
interface CartContextValue {
  // ... existing fields
  cartMetadata: CartMetadata | null;
  timeoutDeadline: Date | null;   // Parsed từ deadline_iso
  isExpired: boolean;             // computed: now > deadline
  secondsRemaining: number;       // for countdown timer
  refreshTimeout: () => Promise<void>; // Re-fetch từ API
}
```

**Implementation:**
```typescript
// State
const [metadata, setMetadata] = useState<CartMetadata | null>(null);

// Computed values
const timeoutDeadline = useMemo(() => 
  metadata?.deadline_iso ? new Date(metadata.deadline_iso) : null,
  [metadata]
);

const isExpired = useMemo(() => 
  timeoutDeadline ? Date.now() > timeoutDeadline.getTime() : false,
  [timeoutDeadline]
);

const secondsRemaining = useMemo(() => {
  if (!timeoutDeadline) return 0;
  return Math.max(0, Math.floor((timeoutDeadline.getTime() - Date.now()) / 1000));
}, [timeoutDeadline]);

// Fetch timeout config
const refreshTimeout = useCallback(async () => {
  if (!branchSlug) return;
  
  const res = await fetch(`${API_BASE}/customer/branches/${branchSlug}/cart-config`);
  const { data } = await res.json();
  
  setMetadata({
    created_at: new Date().toISOString(),
    branch_slug: branchSlug,
    timeout_minutes: data.effective_timeout_minutes,
    deadline_iso: data.timeout_deadline_iso,
  });
  
  localStorage.setItem(CART_METADATA_KEY, JSON.stringify(metadata));
}, [branchSlug]);

// Effect: fetch khi add item đầu tiên
useEffect(() => {
  if (state.items.length === 1 && !metadata) {
    refreshTimeout();
  }
}, [state.items.length, metadata, refreshTimeout]);

// Effect: auto-clear khi expired
useEffect(() => {
  if (isExpired && state.items.length > 0) {
    toast.error(t('cart.expired_clear'));
    clearCart();
  }
}, [isExpired, state.items.length, clearCart]);

// Effect: countdown ticker (update mỗi giây)
useEffect(() => {
  if (!timeoutDeadline || isExpired) return;
  
  const timer = setInterval(() => {
    // Force re-render để update secondsRemaining
    setMetadata(m => m ? { ...m } : null);
  }, 1000);
  
  return () => clearInterval(timer);
}, [timeoutDeadline, isExpired]);
```

---

### 3. Frontend — UI Components

#### 3a. Cart Drawer — Countdown Banner

**File:** `web/customer/components/cart-drawer.tsx`

**Add after cart items list:**
```tsx
import { Clock, AlertTriangle } from "lucide-react";

{!isExpired && timeoutDeadline && secondsRemaining > 0 && (
  <div className={cn(
    "flex items-center gap-2 rounded-lg px-3 py-2 text-sm",
    secondsRemaining < 300 
      ? "bg-red-50 text-red-900" 
      : "bg-amber-50 text-amber-900"
  )}>
    {secondsRemaining < 300 ? (
      <AlertTriangle className="h-4 w-4 text-red-600 animate-pulse" />
    ) : (
      <Clock className="h-4 w-4 text-amber-600" />
    )}
    <span>
      {t('cart.expires_in', { 
        minutes: Math.floor(secondsRemaining / 60),
        seconds: secondsRemaining % 60 
      })}
    </span>
  </div>
)}
```

---

#### 3b. Checkout Page — Pre-submit Validation

**File:** `web/customer/components/checkout-page.tsx`

**Before submit order:**
```tsx
const handleSubmit = async () => {
  // 1. Timeout validation
  if (isExpired) {
    toast.error(t('cart.expired_error'));
    clearCart();
    router.push(`/${locale}/takeaway/${branchSlug}`);
    return;
  }

  // 2. Warning nếu sắp hết hạn (< 2 phút)
  if (secondsRemaining > 0 && secondsRemaining < 120) {
    const confirmed = confirm(t('cart.expiring_soon_confirm', {
      seconds: secondsRemaining
    }));
    if (!confirmed) return;
  }

  // ... existing submit logic
};
```

---

### 4. i18n Messages

**Files:** `web/customer/messages/{ja,en,vi}.json`

**Japanese:**
```json
{
  "cart": {
    "expires_in": "カートの有効期限: {minutes}分{seconds}秒",
    "expired_error": "カートの有効期限が切れました。商品を選び直してください。",
    "expired_clear": "カートがタイムアウトしたため、自動的にクリアされました。",
    "expiring_soon_confirm": "あと{seconds}秒でカートが無効になります。今すぐ注文を確定しますか？",
    "timeout_warning": "メニューは{minutes}分後に終了します。お早めにご注文ください。"
  }
}
```

**English:**
```json
{
  "cart": {
    "expires_in": "Cart expires in {minutes}m {seconds}s",
    "expired_error": "Your cart has expired. Please select items again.",
    "expired_clear": "Cart automatically cleared due to timeout.",
    "expiring_soon_confirm": "Your cart will expire in {seconds} seconds. Confirm order now?",
    "timeout_warning": "Menu ends in {minutes} minutes. Please order soon."
  }
}
```

**Vietnamese:**
```json
{
  "cart": {
    "expires_in": "Giỏ hàng hết hạn sau {minutes} phút {seconds} giây",
    "expired_error": "Giỏ hàng đã hết hạn. Vui lòng chọn lại sản phẩm.",
    "expired_clear": "Giỏ hàng đã tự động xóa do hết hạn.",
    "expiring_soon_confirm": "Giỏ hàng sẽ hết hạn sau {seconds} giây. Xác nhận đặt hàng ngay?",
    "timeout_warning": "Menu kết thúc sau {minutes} phút. Vui lòng đặt hàng sớm."
  }
}
```

---

### 5. Edge Cases & Validation

#### 5a. Menu không có schedule
```typescript
// Nếu menu không có schedule (24/7) → deadline = null → no timeout
if (!metadata?.deadline_iso) {
  // Không hiển thị countdown, không validate
  return null;
}
```

#### 5b. Cross-branch cart
```typescript
// Khi switch branch, clear cart nếu branch khác
useEffect(() => {
  if (metadata?.branch_slug && metadata.branch_slug !== currentBranchSlug) {
    toast.info(t('cart.cleared_branch_switch'));
    clearCart();
  }
}, [currentBranchSlug, metadata]);
```

#### 5c. Page refresh / tab restore
```typescript
// Hydrate metadata từ localStorage
useEffect(() => {
  const raw = localStorage.getItem(CART_METADATA_KEY);
  if (raw) {
    const parsed = JSON.parse(raw) as CartMetadata;

    // Validate còn hạn
    if (parsed.deadline_iso && new Date(parsed.deadline_iso) > new Date()) {
      setMetadata(parsed);
    } else {
      // Expired → clear
      localStorage.removeItem(CART_METADATA_KEY);
      localStorage.removeItem(STORAGE_KEY);
    }
  }
}, []);
```

---

## Testing Checklist

### Unit Tests (Context)
- [ ] `addToCart()` lần đầu trigger `refreshTimeout()`
- [ ] `isExpired` computed đúng khi `now > deadline`
- [ ] `secondsRemaining` update mỗi giây
- [ ] `clearCart()` remove metadata khỏi localStorage
- [ ] Cross-branch switch clear cart

### Integration Tests (API)
- [ ] `GET /customer/branches/{slug}/cart-config` trả về timeout đúng
- [ ] Tier 4 override Tier 3 (shop menu > shop default)
- [ ] Null timeout → no deadline
- [ ] Schedule không có hôm nay → no deadline

### E2E Tests (Playwright)
- [ ] Add item → countdown hiển thị
- [ ] Countdown < 5min → màu đỏ + warning icon
- [ ] Expired → toast + auto-clear cart + redirect
- [ ] Refresh page → metadata persist
- [ ] Switch branch → cart cleared

### Manual QA
1. Set timeout = 2 phút ở admin
2. Add sản phẩm vào cart
3. Verify countdown xuất hiện
4. Đợi 1:50 → màu đỏ + pulse animation
5. Đợi 2:00 → cart auto-clear + toast
6. Refresh page trước khi expired → countdown vẫn đúng
7. Switch sang branch khác → cart cleared

---

## Implementation Order

1. **Backend API** (2h)
   - Create `CustomerBranchController::getCartConfig()`
   - Add route `GET /customer/branches/{slug}/cart-config`
   - Test với Pest

2. **Cart Context** (3h)
   - Add metadata state + localStorage
   - Add computed values (isExpired, secondsRemaining)
   - Add refreshTimeout() + auto-clear effect
   - Add countdown ticker

3. **UI Components** (2h)
   - Cart drawer countdown banner
   - Checkout validation dialog
   - Toast notifications

4. **i18n** (30min)
   - Add messages (ja/en/vi)

5. **Testing** (3h)
   - Unit tests (cart-context)
   - Integration tests (API)
   - E2E tests (Playwright)
   - Manual QA

**Total:** ~10.5 hours

---

## Success Criteria

- ✅ Countdown hiển thị chính xác trong cart drawer
- ✅ Cart auto-clear khi expired + toast notification
- ✅ Checkout block nếu expired
- ✅ Warning dialog nếu < 2min còn lại
- ✅ Metadata persist qua page refresh
- ✅ Cross-branch switch clear cart
- ✅ All tests pass
- ✅ No console errors
- ✅ Accessible (ARIA labels, keyboard navigation)

---

## Related Issues

- #1: Cart timeout (this issue)
- #2: Order status validation → Handle 422 when order closed/voided
- plan-020: Split bill payment → Separate feature

---

## References

- **Spec:** `docs/plan-menu-timeout-button.md`
- **Backend controllers:** `backend/app/Http/Controllers/Api/V1/Shop/ShopMenuItemSettingsController.php`
- **Admin UI:** `web/admin/src/app/shop/[shopSlug]/menus/[menuId]/components/shop-set-timeout-dialog.tsx`
- **4-tier inheritance:** Brand → HQ Menu → Shop → Shop Menu
