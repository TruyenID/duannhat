# Tính năng đang tạm ẩn (feature-flagged)

> Issue gốc: [godx-tempo-customer-web#47](https://github.com/godx-jp/godx-tempo-customer-web/issues/47)
> — thu gọn Customer Web về **chỉ Dine-in + Takeaway**.

Toàn bộ code của Booking và Login/Register/Account **vẫn còn nguyên trong repo**, chỉ bị
ẩn sau feature flag. File này là danh sách đầy đủ những gì đã ẩn, để sau này bật lại /
implement tiếp không phải đi dò khắp codebase.

> **`auth` đã BẬT lại từ #1710** (godx-tempo#1710). Mọi mô tả "khi `FEATURES.auth`
> off" bên dưới giờ là **lịch sử** — giữ nguyên để lần nào cần tắt lại thì biết
> đúng những chỗ nào chịu ảnh hưởng. Chỉ còn `booking` là đang ẩn thật.
>
> Lý do bật: #1505 đã đưa `/login` · `/register` · `/account` vào trong cửa hàng
> (`/login/{shop}`…) và bắt đăng ký gắn `branch_id`, nhưng guard đó **gác sau
> chính flag này** (`middleware.ts`: `FEATURES.auth ? missingShopSegment(bare) : null`),
> nên flag off là cả tính năng vừa ship trở nên vô hình.

## Bật lại

Sửa [`lib/feature-flags.ts`](../lib/feature-flags.ts) → `true` → rebuild. Hết. Không cần
revert commit nào.

```ts
export const FEATURES = {
  booking: false,          // → true để bật lại luồng đặt bàn
  auth: true,              // đã bật lại (#1710)
  authEntryPoints: false,  // → true để hiện lại nút Đăng nhập/Đăng ký (mục 4b)
} as const;
```

⚠️ `auth` và `authEntryPoints` là HAI việc khác nhau — bảng so sánh ở mục 4b.
Tắt `auth` là tắt cả tính năng (chặn route, ép guest); tắt `authEntryPoints`
chỉ là thôi mời khách đăng nhập.

Tìm mọi điểm đã guard:

```bash
grep -rn "FEATURES\." app components lib context middleware.ts
```

---

## 1. Chặn route — [`middleware.ts`](../middleware.ts)

`DISABLED_ROUTE_PREFIXES` được tính từ `FEATURES`; so khớp theo **prefix** trên pathname
đã bỏ locale, match thì `307` về `/{locale}` (không 404, không loop).

| Flag off | Prefix bị chặn | Phủ luôn |
|---|---|---|
| `booking` | `/booking` | `/booking?shop=…` |
| `auth` | `/login` | `/login/[shop]` |
| `auth` | `/register` | `/register/[shop]` |
| `auth` | `/account` | `/account/[shop]` và mọi trang con |

### Guard thứ hai: cửa hàng bắt buộc trong URL (#1505)

Độc lập với `FEATURES.auth` (chỉ chạy khi auth ĐANG BẬT — auth off thì bảng
trên đã chặn hết rồi). Khu tài khoản chỉ tồn tại bên trong một cửa hàng, nên
`/login`, `/register`, `/account` **trần** không render mà `307` về
`/select-branch?next={login|register|account}`; chọn cửa hàng xong quay lại
đúng khu vừa muốn vào.

Middleware chỉ trả lời được "có segment cửa hàng hay không" (edge runtime,
không gọi API). Slug có thật hay không do [`components/require-shop.tsx`](../components/require-shop.tsx)
đối chiếu với danh sách chi nhánh — đó là chỗ bắt URL cũ kiểu `/account/orders`.
Logic thuần + test: [`lib/shop-routes.ts`](../lib/shop-routes.ts).

### Guard thứ ba: entry-point khu tài khoản trong header (#1717 → #1747)

Chặn được *route* vẫn chưa đủ — mọi link/nút dẫn vào khu tài khoản đều dựng href
từ `currentBranch`, tức **localStorage**, nên trên URL không mang cửa hàng chúng
quy khách về chi nhánh của phiên trước: đúng cái sai #1505 sinh ra để chặn.

`authEntryPointsAllowed(pathname)` là ranh giới, và nó KHÔNG phải "URL có slug
hay không" mà là **"cửa hàng có được xác định chắc chắn hay không"** — URL mang
slug (`/stores/{shop}`, `/takeaway/{shop}`, `/dine-in/{shop}/…`, `/account/{shop}/…`),
hoặc trang thuộc luồng mua nơi giỏ/đơn đã ghim branch (`/checkout`,
`/order-confirm`, `/order-success`). Trang cấp thương hiệu
(`/`, `/menus`, `/orders`, `/select-branch`…) không có cửa hàng nào ⇒ không có
entry-point.

- #1717 áp cho **khách vãng lai** (nút Đăng nhập / Đăng ký).
- #1747 áp nốt cho **người đã đăng nhập**: chip avatar + tên trong header cũng là
  một đường VÀO, trước đó nó hiện ở mọi trang. Quyết định gom về một hàm thuần
  `headerAuthSlot()` (`chip` | `guest-cta` | `none`) trong
  [`lib/shop-routes.ts`](../lib/shop-routes.ts) để hai call-site của
  [`components/Header.tsx`](../components/Header.tsx) không diễn giải khác nhau.
  [`components/user-menu.tsx`](../components/user-menu.tsx) lấy cửa hàng từ **URL
  trước**, `currentBranch` chỉ là dự phòng cho luồng mua có URL trần.

**CỐ Ý không siết**: mục "Đăng xuất" trong `MobileNavMenu` (`showAuthItem`) vẫn
gate theo `isLoggedIn` — chip là đường vào, Đăng xuất là lối RA duy nhất còn lại
trên mobile; ẩn nó là nhốt khách trong phiên.

## 2. Ép guest-only

| File | Thay đổi khi `FEATURES.auth === false` |
|---|---|
| [`context/auth-context.tsx`](../context/auth-context.tsx) | Effect restore session bỏ qua hoàn toàn (không gọi `/api/v1/customer/auth/user`), set `sessionChecked = true` ngay → `isLoggedIn` luôn `false`, `isLoading` không treo |
| [`context/auth-context.tsx`](../context/auth-context.tsx) | `login()` / `register()` gọi `assertAuthEnabled()` → throw (backstop, các trang gọi chúng đã bị middleware chặn) |
| [`lib/api.ts`](../lib/api.ts) | `getToken()` trả `null` → không gắn header `Authorization`, kể cả khi localStorage còn token cũ |
| [`lib/api.ts`](../lib/api.ts) | 401 redirect `/login/{shop}` → `/` |

**Đây là đòn bẩy chính**: hơn 20 chỗ trong app viết theo dạng
`isLoggedIn ? <account UI> : <guest UI>` (checkout, `/orders`, `cart-context`, `paid-view`,
`Header`…). Ép `isLoggedIn === false` làm tất cả tự rơi về nhánh
guest — **không cần sửa từng file**, và bật flag lại là khôi phục nguyên trạng.

## 3. Entry point đã ẩn — Booking (`FEATURES.booking`)

| File | Đã ẩn gì | Khi bật lại |
|---|---|---|
| [`components/order-type-landing.tsx`](../components/order-type-landing.tsx) | Card "Đặt bàn" → `/select-branch?next=booking`. Landing `/order` hiện chỉ còn card Takeaway | Card quay lại, i18n `orderType.bookTable*` vẫn còn nguyên |
| [`app/[locale]/select-branch/page.tsx`](../app/[locale]/select-branch/page.tsx) | `isNextFlow()` từ chối `?next=booking` → URL cũ rơi về flow mặc định `/stores/[slug]`. Nhánh `handlePick` → `/booking` và `flowLabel` booking giữ nguyên, chỉ không đạt tới được | Chấp nhận lại `?next=booking` |
| [`app/[locale]/stores/[slug]/page.tsx`](../app/[locale]/stores/[slug]/page.tsx) | Nút "Menu tại chỗ" → `/booking?shop=…` trong `orderButtons()`. Trang chi nhánh chỉ còn nút Takeaway | Nút quay lại |
| [`components/checkout-page.tsx`](../components/checkout-page.tsx) | Empty-table state của dine-in: nút back đi `/` thay vì `/booking` | Về lại `/booking` |

## 4. Entry point đã ẩn — Auth (`FEATURES.auth`)

| File | Đã ẩn gì |
|---|---|
| [`components/Header.tsx`](../components/Header.tsx) | **Menu mode** (takeaway/dine-in): `UserMenu` + nút "Đăng nhập" → chỉ còn `LanguageSwitcher` |
| [`components/Header.tsx`](../components/Header.tsx) | **Desktop**: cả cụm "Đăng nhập / Đăng ký / `UserMenu`" |
| [`components/Header.tsx`](../components/Header.tsx) | **`MobileNavMenu`**: mục Đăng nhập / Đăng xuất (`showAuthItem`), kèm `border-b` thừa của mục "Ngôn ngữ" |
| [`components/checkout-page.tsx`](../components/checkout-page.tsx) · [`checkout-page-mobile.tsx`](../components/checkout-page-mobile.tsx) | Dòng "Đã có tài khoản? **Đăng nhập**" trong block thông tin khách |
| [`components/checkout-page.tsx`](../components/checkout-page.tsx) · [`checkout-page-mobile.tsx`](../components/checkout-page-mobile.tsx) | `CouponLoginPrompt` khi coupon trả `error_code: 'customer_required'` → rơi về block lỗi coupon thường |
| [`app/[locale]/orders/page.tsx`](../app/[locale]/orders/page.tsx) · [`orders/[id]/page.tsx`](../app/[locale]/orders/[id]/page.tsx) | `GuestStorageHint` dùng message biến thể `guestStorageHintNoAuth` / `guestStorageHintDetailNoAuth` (bỏ CTA Đăng nhập / Đăng ký) |
| [`app/[locale]/dine-in/[shop]/table/[qrToken]/components/paid-view.tsx`](../app/[locale]/dine-in/[shop]/table/[qrToken]/components/paid-view.tsx) | Redirect `/login?redirect=…` trước khi đánh giá → đi thẳng `/review/[id]` |
| [`components/home-hero.tsx`](../components/home-hero.tsx) | 3 quick link `/account` (Hướng dẫn / Thẻ / Coupon) — dùng ở `/menuorder` |
| [`app/[locale]/order-success/page.tsx`](../app/[locale]/order-success/page.tsx) | Login prompt `Dialog` (vốn đã là dead code — `setLoginPromptOpen(true)` không được gọi ở đâu) |

## 4b. Lời mời đăng nhập đã ẩn — `FEATURES.authEntryPoints` (ĐANG TẮT)

Khác `auth` ở **phạm vi**, và đây là chỗ dễ nhầm nhất trong tệp này:

| | `auth: false` | `authEntryPoints: false` |
|---|---|---|
| Nút/link mời đăng nhập | ẩn | **ẩn** |
| Route `/login` `/register` `/account` | 307 về `/{locale}` | **vẫn vào được bằng link trực tiếp** |
| Khách đang đăng nhập | rơi về guest (`api.ts` thôi gửi token) | **giữ nguyên phiên** |
| Chip tài khoản ở header | ẩn | **vẫn hiện** |
| Mục "Đăng xuất" ở menu mobile | ẩn | **vẫn hiện** |

Nghĩa là `authEntryPoints` chỉ tắt phần **chào mời**, không tắt tính năng. Ai đã
đăng nhập vẫn dùng khu tài khoản bình thường; chỉ là app không còn rủ khách vãng
lai đăng nhập ở bất cứ đâu.

Cổng duy nhất cho header là [`headerAuthSlot`](../lib/shop-routes.ts) —
`guest-cta` → `none`, nhánh `chip` không đụng tới. Các điểm còn lại gate trực
tiếp bằng `FEATURES.authEntryPoints`:

| File | Đã ẩn gì |
|---|---|
| [`components/Header.tsx`](../components/Header.tsx) | Nút "Đăng nhập" (menu mode + desktop) và nút "Đăng ký" (desktop), qua `headerAuthSlot` |
| [`components/Header.tsx`](../components/Header.tsx) | Mục "Đăng nhập" trong `MobileNavMenu`. **"Đăng xuất" giữ nguyên** — `isLoggedIn` đi thẳng qua cổng, vì ẩn nó là nhốt người ta trong phiên |
| [`components/checkout-page.tsx`](../components/checkout-page.tsx) · [`checkout-page-mobile.tsx`](../components/checkout-page-mobile.tsx) | Dòng "Đã có tài khoản? **Đăng nhập**" |
| [`components/checkout-page.tsx`](../components/checkout-page.tsx) · [`checkout-page-mobile.tsx`](../components/checkout-page-mobile.tsx) | `CouponLoginPrompt` (`customer_required`) → rơi về block lỗi coupon thường. Khách vẫn đọc được lý do coupon không dùng được |
| [`app/[locale]/orders/page.tsx`](../app/[locale]/orders/page.tsx) · [`orders/[id]/page.tsx`](../app/[locale]/orders/[id]/page.tsx) | Biến thể message `*NoAuth` (bỏ CTA Đăng nhập / Đăng ký) |
| [`components/home-hero.tsx`](../components/home-hero.tsx) | 3 quick link `/account` — **chỉ với khách vãng lai**. Chúng không mang chữ "Đăng nhập" nhưng với người chưa đăng nhập là dẫn thẳng vào tường đăng nhập. Người đã đăng nhập vẫn thấy |
| [`app/[locale]/order-success/page.tsx`](../app/[locale]/order-success/page.tsx) | Login prompt `Dialog` (vốn đã là dead code) |

**Một chỗ CỐ Ý không đụng**:
[`paid-view.tsx`](../app/[locale]/dine-in/[shop]/table/[qrToken]/components/paid-view.tsx)
vẫn `router.push('/login?redirect=…')` khi khách vãng lai bấm "Đánh giá món ăn".
Đó là một redirect trong luồng, không phải một nút mời đăng nhập — và đổi nó
thành đi thẳng `/review/[id]` là thay đổi hành vi của trang đánh giá, việc khác.
Hệ quả: đây là đường DUY NHẤT còn lại mà khách vãng lai gặp màn đăng nhập mà
không hề bấm vào nút đăng nhập nào.

## 5. Code còn nguyên nhưng không tiếp cận được

**Đừng xoá** — chỉ là unreachable, không phải dead code:

- Route: `app/[locale]/booking/**`, `app/[locale]/login/**`, `app/[locale]/register/**`,
  `app/[locale]/account/**`
- Component: `components/booking-page.tsx`, `components/login-form.tsx`,
  `components/user-menu.tsx`, `components/coupon-login-prompt.tsx`,
  `components/account-view.tsx`, `account-edit-view.tsx`, `account-orders-view.tsx`,
  `account-order-detail-view.tsx`, `account-password-view.tsx`
- i18n: mọi key `booking.*`, `header.login/register/logout`, `orderType.bookTable*`,
  `guestOrders.guestStorageHint(Detail)`, `checkout.haveAccount/loginLink/loginCta/couponLoginCta`,
  `orderSuccess.loginToReview/loginPromptDesc/loginNow` — giữ nguyên trong `messages/*.json`
- Prop `hideRegister` của `Header` (hiện vô hiệu vì cả cụm auth đã ẩn)

## 6. Nợ kỹ thuật khi bật lại

1. **Landing `/order`** — bật `booking` là card "Đặt bàn" xuất hiện lại, nhưng landing vẫn
   **không có card Dine-in** (dine-in chỉ vào được qua QR bàn). Nếu muốn có, phải thiết kế
   mới + thêm i18n key.
2. **`paid-view` / `order-success`** — flow "đăng nhập rồi quay lại đánh giá" đang bị bypass,
   cần test lại `?redirect=` sau khi bật.
3. **`CouponLoginPrompt`** — phụ thuộc BE trả `error_code: 'customer_required'`; xác nhận
   endpoint preview coupon còn trả code này trước khi bật.
4. **`Header.hideAuth`** — prop này gate cả hamburger và nút "Lịch sử đơn hàng" (guest
   `/orders`), **không phải** chỉ auth. Đừng dùng nó để ẩn auth toàn cục — đó là lý do các
   guard ở đây dùng `FEATURES.auth` riêng thay vì bật `hideAuth`.
5. **Token cũ** — thiết bị từng đăng nhập trước khi tắt flag vẫn còn `cw_auth_token` trong
   localStorage. Hiện bị `getToken()` bỏ qua; bật `auth` lại thì session cũ tự sống lại.
