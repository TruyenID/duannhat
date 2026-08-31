# Issue #001: Cart Timeout Implementation (Customer Web)

**Created:** 2026-05-11  
**Priority:** 🔴 Critical  
**Status:** 📝 Not Started  
**Estimate:** 8-12 hours  
**Assignee:** TBD  
**Related:** `docs/plan-menu-timeout-button.md`, `plans/plan-020/`, `backend/.env`

---

## 📋 Problem Statement

Backend đã có **4-tier cart timeout config** (Brand → HQ Menu → Shop → Shop Menu) hoàn chỉnh với admin UI, nhưng **customer-web không enforce timeout**.

### Current Behavior ❌

- ✅ Cart items lưu `localStorage` vô thời hạn
- ❌ Không track `created_at` timestamp
- ❌ Không fetch `effective_timeout_minutes` từ backend
- ❌ Không có countdown timer
- ❌ Không validate khi checkout
- ❌ Không auto-clear expired items

### Expected Behavior ✅

```
Schedule end_time                     Cart timeout deadline
      │                                        │
      │◄────── timeout_minutes ────────────────►│
      ▼                                        ▼
Menu kết thúc                         Giỏ hàng expired
Countdown bắt đầu                     Checkout bị block
```

**User Story:**
> Là khách hàng đặt món takeaway, tôi cần biết giỏ hàng của tôi sẽ hết hạn khi nào (dựa trên thời gian kết thúc menu + timeout buffer), để tôi có thể hoàn tất đơn hàng trước deadline.

---

## 🎯 Acceptance Criteria

- [ ] **AC1:** Khi add item đầu tiên vào cart → fetch cart config từ backend → lưu `deadline_iso` vào localStorage
- [ ] **AC2:** Countdown timer hiển thị trong cart drawer (VD: "Giỏ hàng sẽ hết hạn sau 28:45")
- [ ] **AC3:** Khi còn < 2 phút → countdown chuyển màu đỏ + pulse animation
- [ ] **AC4:** Khi hết hạn → cart auto-clear + toast "Giỏ hàng đã hết hạn"
- [ ] **AC5:** Khi checkout → validate deadline phía backend → reject nếu expired
- [ ] **AC6:** Refresh page → countdown vẫn đúng (load từ localStorage)
- [ ] **AC7:** Switch sang branch khác → cart cleared (branch context mismatch)

---

## 🛠 Technical Implementation Plan

### Phase 1: Backend API (2h)

**1.1. Create new endpoint**

File: `backend/app/Http/Controllers/Api/V1/Customer/CustomerBranchController.php`

```php
/**
 * GET /api/v1/customer/branches/{slug}/cart-config
 */
public function getCartConfig(string $slug): JsonResponse
{
    $branch = Branch::where('slug', $slug)->firstOrFail();
    $menu = Menu::where('branch_id', $branch->id)
        ->where('status', 'Active')
        ->with(['schedules', 'masterMenu.brand'])
        ->first();

    // 4-tier cascade: ④ → ③ → ② → ①
    $effectiveTimeout = $menu?->cart_timeout_minutes          // Tier 4
        ?? $branch->cart_timeout_minutes                      // Tier 3
        ?? $menu?->masterMenu?->cart_timeout_minutes          // Tier 2
        ?? $branch->brand?->cart_timeout_minutes              // Tier 1
        ?? 30; // Fallback default

    $now = Carbon::now($branch->timezone);
    $todaySchedule = $menu?->schedules
        ->where('is_active', true)
        ->filter(fn($s) => ($s->days_of_week & (1 << $now->dayOfWeek)) > 0)
        ->sortBy('end_time')
        ->first();

    $deadline = $todaySchedule 
        ? Carbon::parse($todaySchedule->end_time, $branch->timezone)
            ->addMinutes($effectiveTimeout)
        : null;

    return response()->json([
        'data' => [
            'effective_timeout_minutes' => $effectiveTimeout,
            'current_menu_end_time' => $todaySchedule?->end_time,
            'timeout_deadline_iso' => $deadline?->toIso8601String(),
        ],
    ]);
}
```

**1.2. Add route**

File: `backend/routes/api/v1/customer.php`

```php
Route::get('branches/{slug}/cart-config', [CustomerBranchController::class, 'getCartConfig']);
```

**1.3. Test**

File: `backend/tests/Feature/Api/V1/Customer/CartConfigTest.php`

```php
it('returns cart config with 4-tier cascade', function () {
    $branch = Branch::factory()->create(['cart_timeout_minutes' => 45]);
    
    $response = $this->getJson("/api/v1/customer/branches/{$branch->slug}/cart-config");
    
    $response->assertOk()
        ->assertJsonStructure(['data' => [
            'effective_timeout_minutes',
            'current_menu_end_time',
            'timeout_deadline_iso',
        ]]);
});
```

---

### Phase 2: Frontend Cart Context (3h)

**2.1. Add localStorage schema**

File: `web/customer/context/cart-context.tsx`

```typescript
const CART_METADATA_KEY = "betoya-cart-metadata";

interface CartMetadata {
  created_at: string;           // ISO timestamp
  branch_slug: string;
  timeout_minutes: number | null;
  deadline_iso: string | null;
}
```

**2.2. Add state & computed values**

```typescript
const [metadata, setMetadata] = useState<CartMetadata | null>(null);

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
```

**2.3. Fetch cart config khi add item đầu tiên**

```typescript
async function fetchCartConfig(branchSlug: string) {
  const res = await fetch(`${API_URL}/api/v1/customer/branches/${branchSlug}/cart-config`);
  const json = await res.json();

  const newMetadata: CartMetadata = {
    created_at: new Date().toISOString(),
    branch_slug: branchSlug,
    timeout_minutes: json.data.effective_timeout_minutes,
    deadline_iso: json.data.timeout_deadline_iso,
  };

  setMetadata(newMetadata);
  localStorage.setItem(CART_METADATA_KEY, JSON.stringify(newMetadata));
}

function addToCart(item: CartItem) {
  // ... existing logic

  // Fetch config nếu đây là item đầu tiên
  if (cartItems.length === 0 && !metadata) {
    fetchCartConfig(currentBranchSlug);
  }
}
```

**2.4. Auto-clear expired cart**

```typescript
useEffect(() => {
  if (isExpired && cartItems.length > 0) {
    clearCart();
    toast.error(t('cart.expired'));
  }
}, [isExpired, cartItems.length]);
```

**2.5. Countdown ticker**

```typescript
useEffect(() => {
  if (!timeoutDeadline) return;

  const interval = setInterval(() => {
    // Force re-render every second to update countdown
    setMetadata(prev => ({ ...prev! }));
  }, 1000);

  return () => clearInterval(interval);
}, [timeoutDeadline]);
```

---

### Phase 3: UI Components (2h)

**3.1. Cart Countdown Banner**

File: `web/customer/components/cart/cart-countdown-banner.tsx`

```tsx
export function CartCountdownBanner({ secondsRemaining }: { secondsRemaining: number }) {
  const minutes = Math.floor(secondsRemaining / 60);
  const seconds = secondsRemaining % 60;
  const isUrgent = secondsRemaining < 120; // < 2 minutes

  return (
    <div className={cn(
      "px-4 py-2 text-sm font-medium",
      isUrgent ? "bg-red-50 text-red-700 animate-pulse" : "bg-yellow-50 text-yellow-800"
    )}>
      ⏰ Giỏ hàng sẽ hết hạn sau {minutes}:{seconds.toString().padStart(2, '0')}
    </div>
  );
}
```

**3.2. Checkout Validation Dialog**

```tsx
if (isExpired) {
  return (
    <Dialog open>
      <DialogContent>
        <DialogHeader>Giỏ hàng đã hết hạn</DialogHeader>
        <p>Menu đã đóng cửa. Vui lòng xóa giỏ hàng và đặt món mới.</p>
        <DialogFooter>
          <Button onClick={clearCart}>Xóa giỏ hàng</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
```

---

### Phase 4: Testing (3h)

**4.1. Unit tests**

- Cart context state management
- Timeout calculation logic
- LocalStorage persistence

**4.2. Feature tests (Playwright)**

- Add item → countdown xuất hiện
- Đợi đến < 2 phút → màu đỏ + pulse
- Đợi đến 0:00 → cart cleared
- Refresh → countdown vẫn đúng
- Checkout khi expired → validation dialog

**4.3. Manual QA**

1. Set timeout = 2 phút ở admin
2. Add sản phẩm vào cart
3. Verify countdown xuất hiện
4. Đợi 1:50 → màu đỏ + pulse animation
5. Đợi 2:00 → cart auto-clear + toast
6. Refresh page trước khi expired → countdown vẫn đúng
7. Switch sang branch khác → cart cleared

---

## 📊 Success Metrics

- [ ] 0 failed checkouts do expired cart (validated phía backend)
- [ ] < 5% user complaints về cart bị clear bất ngờ (có countdown warning)
- [ ] Countdown accuracy ±3 seconds (timezone + schedule logic đúng)

---

## 🚧 Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Timezone mismatch (server vs client) | High | Dùng ISO8601 deadline từ backend, không tính client-side |
| LocalStorage cleared | Medium | Re-fetch config khi load cart từ storage |
| Schedule thay đổi mid-session | Low | Refresh cart config mỗi 5 phút |
| Race condition (countdown vs checkout) | High | Backend validate deadline final time |

---

## 📝 Related Issues

- Backend: `docs/plan-menu-timeout-button.md` (✅ Completed)
- Admin UI: Cart timeout settings (✅ Completed)
- Customer Web: **This issue** (❌ Not started)
- Split Payment: `plans/plan-020/` (🟡 In progress)

---

## 🔗 References

- Design spec: `docs/plan-menu-timeout-button.md`
- Backend models: `backend/app/Models/{Brand,Branch,Menu}.php`
- Admin settings: `web/admin/src/app/shop/[shopSlug]/settings/page.tsx`
- Existing cart context: `web/customer/context/cart-context.tsx`


