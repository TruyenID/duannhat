<?php

use App\Http\Controllers\Api\V1\Customer\CouponController as CustomerCouponController;
use App\Http\Controllers\Api\V1\Customer\CustomerAuthController;
use App\Http\Controllers\Api\V1\Customer\CustomerBranchController;
use App\Http\Controllers\Api\V1\Customer\CustomerBranchReviewController;
use App\Http\Controllers\Api\V1\Customer\CustomerCouponWalletController;
use App\Http\Controllers\Api\V1\Customer\CustomerMembershipController;
use App\Http\Controllers\Api\V1\Customer\CustomerMenuController;
use App\Http\Controllers\Api\V1\Customer\CustomerOrderController;
use App\Http\Controllers\Api\V1\Customer\CustomerOrderHistoryController;
use App\Http\Controllers\Api\V1\Customer\CustomerOrderSplitStatusController;
use App\Http\Controllers\Api\V1\Customer\CustomerPointController;
use App\Http\Controllers\Api\V1\Customer\CustomerPostController;
use App\Http\Controllers\Api\V1\Customer\CustomerQrController;
use App\Http\Controllers\Api\V1\Customer\CustomerReviewController;
use App\Http\Controllers\Api\V1\Customer\CustomerTableController;
use App\Http\Controllers\Api\V1\Customer\StripeConfigController;
use App\Http\Controllers\Api\V1\Customer\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  Customer-facing API — QR flow, auth, menu, orders
//  Prefix: /api/v1/customer
// =========================================================================

Route::prefix('v1/customer')->group(function () {

    // Auth (public)
    Route::post('auth/register', [CustomerAuthController::class, 'register'])->name('api.v1.customer.auth.register');
    Route::post('auth/login', [CustomerAuthController::class, 'login'])->name('api.v1.customer.auth.login');

    // Email verification bằng MÃ 6 CHỮ SỐ — đường chính kể từ khi thư bỏ link.
    //
    // `throttle:10,1` theo IP, và service còn tự huỷ mã sau 5 lần gõ sai (chặn
    // theo TÀI KHOẢN, nên đổi IP không lách được). Cần cả hai: một mình throttle
    // IP thì một botnet vẫn quét được không gian 10^6; một mình bộ đếm lượt sai
    // thì một kẻ tấn công có thể xin mã mới liên tục để reset nó.
    Route::post('auth/email/verify-code', [CustomerAuthController::class, 'verifyCode'])
        ->middleware(['throttle:10,1'])
        ->name('api.v1.customer.verification.verify-code');

    // Email verification bằng LINK có chữ ký — đường CŨ, giữ lại cho những thư
    // đã nằm sẵn trong hộp thư khách trước lần deploy đổi sang mã. Không thư
    // mới nào trỏ vào đây nữa; gỡ được sau khi mọi link cũ đã hết hạn.
    //
    // Chữ ký được kiểm TRONG controller chứ không bằng middleware `signed`, để
    // phân biệt "hết hạn" với "link hỏng" và trả khách về Customer Web thay vì
    // một 403 trần.
    Route::get('auth/verify/{id}/{hash}', [CustomerAuthController::class, 'verify'])
        ->middleware(['throttle:6,1'])
        ->name('api.v1.customer.verification.verify');

    // Gửi lại mã xác nhận — CÔNG KHAI (#1680): khách chưa xác nhận thì chưa
    // đăng nhập được, nên không có token nào để gửi kèm. Trả lời luôn giống
    // nhau dù địa chỉ có tồn tại hay không.
    Route::post('auth/email/resend', [CustomerAuthController::class, 'resend'])
        ->middleware(['throttle:3,1'])
        ->name('api.v1.customer.verification.resend');

    // #1784 — đăng nhập bằng Google. Hệ RIÊNG của khách, không dùng SSO nhân
    // viên. Throttle như login: mỗi lượt là một phép xác minh chữ ký, không
    // phải một email đi ra.
    Route::post('auth/google', [CustomerAuthController::class, 'loginWithGoogle'])
        ->middleware(['throttle:10,1'])
        ->name('api.v1.customer.auth.google');

    // #1783 — quên / đặt lại mật khẩu. CÔNG KHAI: khách không đăng nhập được
    // thì không có token nào để gửi kèm. Throttle chặt hơn login vì mỗi lượt
    // gọi thành công là một email đi ra — đây là đường khuếch đại thư rác nếu
    // để rộng.
    Route::post('auth/password/forgot', [CustomerAuthController::class, 'forgotPassword'])
        ->middleware(['throttle:3,1'])
        ->name('api.v1.customer.password.forgot');

    Route::post('auth/password/reset', [CustomerAuthController::class, 'resetPassword'])
        ->middleware(['throttle:6,1'])
        ->name('api.v1.customer.password.reset');

    // Auth (guarded)
    Route::middleware('auth:customer')->group(function () {
        Route::post('auth/logout', [CustomerAuthController::class, 'logout'])->name('api.v1.customer.auth.logout');
        Route::get('auth/user', [CustomerAuthController::class, 'user'])->name('api.v1.customer.auth.user');
        Route::patch('auth/user', [CustomerAuthController::class, 'update'])->name('api.v1.customer.auth.update');
        Route::post('auth/password', [CustomerAuthController::class, 'changePassword'])
            ->middleware('throttle:3,1')
            ->name('api.v1.customer.auth.change-password');
    });

    // Me — authenticated customer self-service
    Route::prefix('me')->middleware('auth:customer')->group(function () {
        Route::get('orders', [CustomerOrderHistoryController::class, 'index'])->name('api.v1.customer.me.orders.index');
        Route::get('orders/{id}', [CustomerOrderHistoryController::class, 'show'])->name('api.v1.customer.me.orders.show');
        Route::post('orders/claim', [CustomerOrderHistoryController::class, 'claim'])->name('api.v1.customer.me.orders.claim');

        // #1441 — trang thông tin cá nhân. Không endpoint nào ở đây nhận
        // customer id từ request: khách luôn là `user('customer')` của token,
        // nên không có đường nào đọc ví/điểm của người khác.
        Route::get('points', [CustomerPointController::class, 'index'])->name('api.v1.customer.me.points.index');
        Route::get('points/rewards', [CustomerPointController::class, 'rewards'])->name('api.v1.customer.me.points.rewards');
        Route::post('points/redeem', [CustomerPointController::class, 'redeem'])
            ->middleware('throttle:10,1')
            ->name('api.v1.customer.me.points.redeem');

        Route::get('coupons', [CustomerCouponWalletController::class, 'index'])->name('api.v1.customer.me.coupons.index');
        Route::get('membership', [CustomerMembershipController::class, 'show'])->name('api.v1.customer.me.membership.show');
    });

    // plan-031: Batch fetch orders by IDs for guest users (public, no auth)
    Route::post('orders/batch', [CustomerOrderHistoryController::class, 'batchShow'])
        ->middleware('throttle:customer-order-batch')
        ->name('api.v1.customer.orders.batch');

    // Posts (public) — blog/news/promotion excerpts for the home page.
    // #1441: `?category=faq&with_content=1` là nguồn của trang Câu hỏi
    // thường gặp — FAQ là bài viết, không phải một bảng riêng.
    Route::get('posts', [CustomerPostController::class, 'index'])->name('api.v1.customer.posts.index');
    Route::get('posts/{slug}', [CustomerPostController::class, 'show'])->name('api.v1.customer.posts.show');

    // Branches (public)
    Route::get('branches', [CustomerBranchController::class, 'index'])->name('api.v1.customer.branches.index');
    Route::get('branches/{branchSlug}/menu', [CustomerMenuController::class, 'showByBranch'])->name('api.v1.customer.branches.menu');
    Route::get('branches/{branchSlug}/cart-config', [CustomerBranchController::class, 'getCartConfig'])->name('api.v1.customer.branches.cart-config');
    // plan-048 T2.5 — policy identity for the intent-call drift echo (public, ids only)
    Route::get('branches/{branchSlug}/payment-context', [CustomerBranchController::class, 'paymentContext'])->name('api.v1.customer.branches.payment-context');
    Route::get('branches/{branchSlug}/zones', [CustomerBranchController::class, 'zones'])->name('api.v1.customer.branches.zones');
    Route::post('branches/{branchSlug}/orders', [CustomerOrderController::class, 'storeByBranch'])->name('api.v1.customer.branches.orders.store');

    // Unified QR resolve (public) — kiosk scans one token, backend routes it
    // to a table or an order. Throttled per IP to blunt token enumeration.
    Route::get('qr/{token}', [CustomerQrController::class, 'resolve'])
        ->middleware('throttle:qr-resolve')
        ->name('api.v1.customer.qr.resolve');

    // Table routes (public)
    Route::get('tables/{qrToken}', [CustomerTableController::class, 'show'])->name('api.v1.customer.tables.show');
    Route::get('tables/{qrToken}/menu', [CustomerMenuController::class, 'show'])->name('api.v1.customer.tables.menu');
    Route::post('tables/{qrToken}/orders', [CustomerOrderController::class, 'store'])->name('api.v1.customer.tables.orders.store');
    Route::get('tables/{qrToken}/order', [CustomerOrderController::class, 'currentOrder'])->name('api.v1.customer.tables.orders.current');
    Route::delete('tables/{qrToken}/items/{itemId}', [CustomerOrderController::class, 'destroyItem'])->name('api.v1.customer.tables.items.destroy');
    Route::post('tables/{qrToken}/call-staff', [CustomerTableController::class, 'callStaff'])->name('api.v1.customer.tables.call-staff');
    Route::post('tables/{qrToken}/occupy', [CustomerTableController::class, 'occupy'])->name('api.v1.customer.tables.occupy');
    Route::post('tables/{qrToken}/release', [CustomerTableController::class, 'release'])->name('api.v1.customer.tables.release');
    // plan-034 — multi-device session join. Replaces /occupy for new clients;
    // /occupy stays around so older builds keep working until they refresh.
    Route::post('tables/{qrToken}/join', [CustomerTableController::class, 'join'])->name('api.v1.customer.tables.join');

    // Order by ID (public). Throttled per-order (not per-IP — see
    // `customer-order-read` in AppServiceProvider): customer-web polls this
    // while the guest waits on the payment screen, and every phone in a shop
    // shares one NAT egress IP.
    Route::get('orders/{id}', [CustomerOrderController::class, 'show'])
        ->middleware('throttle:customer-order-read')
        ->name('api.v1.customer.orders.show');
    Route::get('orders/{id}/split-status', [CustomerOrderSplitStatusController::class, 'show'])
        ->middleware('throttle:customer-order-read')
        ->name('api.v1.customer.orders.split-status');
    // Plan 033 — by-items split preview (read-only, public per opaque order id).
    Route::get('orders/{id}/split-by-items/preview', [CustomerOrderController::class, 'splitByItemsPreview'])
        ->middleware('throttle:customer-order-read')
        ->name('api.v1.customer.orders.split-by-items.preview');

    // Coupon (public — for dine-in orders)
    Route::post('orders/{id}/apply-coupon', [CustomerOrderController::class, 'applyCoupon'])
        ->middleware('throttle:customer-order-write')
        ->name('api.v1.customer.orders.apply-coupon');

    // Stripe payment (public — uses order id as opaque token)
    Route::post('orders/{id}/payment-intent', [CustomerOrderController::class, 'createPaymentIntent'])
        ->middleware('throttle:customer-order-write')
        ->name('api.v1.customer.orders.payment-intent');
    Route::post('orders/{id}/full-payment-intent', [CustomerOrderController::class, 'createFullPaymentIntent'])
        ->middleware('throttle:customer-order-write')
        ->name('api.v1.customer.orders.full-payment-intent');
    Route::post('orders/{id}/split-payment-intent', [CustomerOrderController::class, 'createSplitPaymentIntent'])
        ->middleware('throttle:customer-order-write')
        ->name('api.v1.customer.orders.split-payment-intent');
    // Synchronous confirmation — marks the order paid right after Stripe.js
    // confirms, so admin reflects "paid" without depending on `stripe listen`.
    Route::post('orders/{id}/confirm-payment', [CustomerOrderController::class, 'confirmPayment'])
        ->middleware('throttle:customer-order-write')
        ->name('api.v1.customer.orders.confirm-payment');
    // plan-054 — PayPay dynamic QR (public, same opaque-order-id model as the
    // Stripe routes above). Minting is rate-limited per order id rather than per
    // IP for the same reason the read limiter is: every phone in a shop shares
    // one NAT egress address. Tighter than the status limiter because each mint
    // is a write that costs a PayPay round trip and invalidates the live code.
    Route::post('orders/{id}/paypay-qr', [CustomerOrderController::class, 'createPayPayQrCode'])
        ->middleware('throttle:customer-paypay-qr')
        ->name('api.v1.customer.orders.paypay-qr');
    // Polled by the waiting customer as a fallback when the realtime channel is
    // dead; it asks PayPay and records the money if it has moved, so a lost
    // webhook still settles the order.
    // #1737 — huỷ mã đang sống mà không mint mã mới.
    //
    // `customer-order-write`, KHÔNG phải `customer-paypay-qr`. Cái sau khoá theo
    // `'customer-paypay-qr:'.route('id')` — prefix literal, KHÔNG có tên route —
    // nên huỷ sẽ ăn chung đúng một bucket 10/phút với mint. Vòng "mint → đổi ý →
    // mint" tốn 2 token mỗi vòng, tức khách chỉ đổi ý được 5 lần/phút thay vì
    // 10, và một chuỗi huỷ có thể 429 chính đường mint — phản tác dụng.
    // `customerOrderLimiterKey` có tên route trong khoá, nên đây được bucket
    // riêng 30/phút mà không lấn ngân sách của apply-coupon / confirm-payment.
    //
    // Không sợ lạm dụng để spam API PayPay: huỷ return sớm khi không còn mã
    // sống, và khi có `merchant_payment_id` lệch thì cũng no-op — nên trần số
    // lần thật sự chạm PayPay vẫn là trần mint (10/phút), 30 chỉ là headroom
    // cho các lượt no-op.
    Route::delete('orders/{id}/paypay-qr', [CustomerOrderController::class, 'cancelPayPayQrCode'])
        ->middleware('throttle:customer-order-write')
        ->name('api.v1.customer.orders.paypay-qr.cancel');

    Route::get('orders/{id}/paypay-qr/status', [CustomerOrderController::class, 'payPayQrStatus'])
        ->middleware('throttle:customer-order-read')
        ->name('api.v1.customer.orders.paypay-qr.status');

    // Zero-due settlement — close a ¥0 bill (comped / 100%-off) without Stripe,
    // which rejects a 0-amount PaymentIntent. Refuses any order still owing.
    Route::post('orders/{id}/settle-zero', [CustomerOrderController::class, 'settleZero'])
        ->middleware('throttle:customer-order-write')
        ->name('api.v1.customer.orders.settle-zero');

    // plan-037 — takeaway counter-pay confirmation step. `commit` flips
    // awaiting_confirmation → pending so KDS / admin pick it up; `cancel`
    // flips to voided. Reaper command voids anything past
    // confirmation_due_at unattended.
    Route::post('orders/{id}/commit', [CustomerOrderController::class, 'commit'])
        ->middleware('throttle:customer-order-write')
        ->name('api.v1.customer.orders.commit');
    Route::post('orders/{id}/cancel', [CustomerOrderController::class, 'cancel'])
        ->middleware('throttle:customer-order-write')
        ->name('api.v1.customer.orders.cancel');

    // #377 — record the split-bill mode customer picked on dine-in payment
    // view so the kiosk can skip its own chooser on the next read.
    // Public by design (opaque order-id token, same guest pattern as the rest
    // of /customer/orders/*) — auth:customer would break the guest counter-pay
    // flow. Throttled per ORDER, not per IP: the `throttle:30,1` this carried
    // shared one bucket across every phone behind a shop's NAT, so a busy
    // dining room could 429 itself off its own split-bill screen (#1256).
    Route::post('orders/{id}/split-mode', [CustomerOrderController::class, 'setSplitMode'])
        ->middleware('throttle:customer-order-write')
        ->name('api.v1.customer.orders.split-mode');

    Route::get('stripe/config', [StripeConfigController::class, 'show'])
        ->name('api.v1.customer.stripe.config');
    Route::post('stripe/webhook', [StripeWebhookController::class, 'handle'])
        ->name('api.v1.customer.stripe.webhook');

    // Coupon preview (public — read-only, throttled to defeat typo-storms)
    Route::post('coupons/preview', [CustomerCouponController::class, 'preview'])
        ->middleware('throttle:60,1')
        ->name('api.v1.customer.coupons.preview');

    // Product reviews (public — order UUID as opaque token, plan-025)
    Route::get('orders/{orderId}/reviewable', [CustomerReviewController::class, 'reviewable'])
        ->middleware('throttle:customer-order-read')
        ->name('api.v1.customer.orders.reviewable');
    Route::post('orders/{orderId}/reviews', [CustomerReviewController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api.v1.customer.orders.reviews.store');
    // Review photo upload (multipart) — plan-026
    Route::post('orders/{orderId}/review-photos', [CustomerReviewController::class, 'uploadPhotos'])
        ->middleware('throttle:20,1')
        ->name('api.v1.customer.orders.review-photos.store');

    // Branch reviews (public — order UUID as opaque token, plan-026)
    Route::get('orders/{orderId}/branch-reviewable', [CustomerBranchReviewController::class, 'reviewable'])
        ->middleware('throttle:customer-order-read')
        ->name('api.v1.customer.orders.branch-reviewable');
    Route::post('orders/{orderId}/branch-review', [CustomerBranchReviewController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('api.v1.customer.orders.branch-review.store');
});
