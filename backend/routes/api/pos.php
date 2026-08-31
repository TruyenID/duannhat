<?php

use App\Http\Controllers\Api\V1\Pos\PosEffectivePaymentOptionsController;
use App\Http\Controllers\Api\V1\Pos\PosMeController;
use App\Http\Controllers\Api\V1\Pos\PosRevenueController;
use App\Http\Controllers\Api\V1\Pos\PosStaffController;
use App\Http\Controllers\Api\V1\Pos\PrintReprintAdviceController;
use App\Http\Controllers\Api\V1\Pos\StripeTerminalController;
use App\Http\Controllers\Api\V1\Pos\TillController;
use App\Http\Controllers\Api\V1\Pos\TillSessionController;
use App\Http\Controllers\Api\V1\Shop\CustomerController;
use App\Http\Controllers\Api\V1\Shop\CustomerOrderController;
use App\Http\Controllers\Api\V1\Shop\DebtController;
use App\Http\Controllers\Api\V1\Shop\MenuAvailabilityController;
use App\Http\Controllers\Api\V1\Shop\MenuController;
use App\Http\Controllers\Api\V1\Shop\OrderPaymentController;
use App\Http\Controllers\Api\V1\Shop\ShopInfoController;
use App\Http\Controllers\Api\V1\Shop\ShopOrderSettingsController;
use App\Http\Controllers\Api\V1\Shop\TableController;
use App\Http\Controllers\Api\V1\Shop\VoidReasonController;
use App\Http\Middleware\ResolveOpenTillSession;
use Illuminate\Support\Facades\Route;

/*
 * POS-domain routes — endpoints consumed by pos-web (browser) and proxied
 * by the workstation app (LAN). Shop context is resolved from the
 * X-Shop-Slug header by ResolvePosShop, so the URL shape is identical
 * whether the call lands on Cloud or on the workstation's local server.
 *
 * Mounted from routes/api.php inside the v1 / auth.sso_or_device prefix
 * (compound auth: SSO Sanctum token from admin-web OR the POS device
 * token that pos-web received from /api/v1/devices/pair). The device
 * path lets pos-web fall back from a downed workstation to cloud with
 * the token it already holds — see docs/explanation/pos-web-cloud-auth.md.
 *
 *   Route::prefix('pos')
 *       ->middleware([ResolvePosShop::class])
 *       ->name('api.v1.pos.')
 *       ->group(fn () => require __DIR__.'/api/pos.php');
 *
 * Endpoints below intentionally REUSE the App\Http\Controllers\Api\V1\Shop
 * controllers. Those controllers read shop context from request attributes
 * (set by either ResolveShopFromSlug or ResolvePosShop) and therefore work
 * unchanged here. This is the contract that makes the two namespaces share
 * a single implementation; do not introduce POS-specific controller code
 * unless the behavior genuinely needs to diverge.
 */

// =========================================================================
//  Session — verify token + return current shop
// =========================================================================

Route::get('me', [PosMeController::class, 'show'])->name('me');

// Plan 030 — staff list for the "Người mở ca" dropdown on /shift/open.
Route::get('staff', [PosStaffController::class, 'index'])->name('staff.index');

// =========================================================================
//  Shop info / settings / tables / payment methods
// =========================================================================

Route::get('shop', [ShopInfoController::class, 'show'])->name('shop.show');

Route::prefix('settings/order')->name('settings.order.')->group(function () {
    Route::get('/', [ShopOrderSettingsController::class, 'show'])->name('show');
    // POS may edit ONLY the 精算 close-report section toggles (scoped method —
    // never currency/tax/policy).
    Route::patch('/', [ShopOrderSettingsController::class, 'updateCloseReportToggles'])->name('update');
});

Route::prefix('tables')->name('tables.')->group(function () {
    Route::get('/', [TableController::class, 'index'])->name('index');
    // #491 — POS staff can clear a table's runtime status (e.g. cleaning → free
    // after the "Dọn xong" action). Reuses the Shop TableController; shop scope
    // comes from ResolvePosShop, actor from the SSO user.
    Route::post('{table}/status', [TableController::class, 'changeStatus'])->name('changeStatus');
});

// plan-051 — void-reason master for the POS void dialog. Same path exists on
// the workstation LAN mirror, so pos-web resolves LAN-first with this Cloud
// fallback. Reuses the Shop controller — ResolvePosShop binds the same
// brand_id attribute via ResolvesShopContext.
Route::get('void-reasons', [VoidReasonController::class, 'index'])
    ->name('void-reasons.index');

// plan-052 §4 (#1166) — reprint ADVICE for money documents. It warns; it never
// refuses. Asked BEFORE paper moves so the operator sees the warning and the
// reason gets typed, but the POS keeps its "In luôn" button enabled whatever
// comes back. Kitchen/bar tickets are never even asked about.
Route::post('print-jobs/reprint-advice', [PrintReprintAdviceController::class, 'store'])
    ->name('print-jobs.reprint-advice');
// Legacy alias — the endpoint was called `reprint-authorization` while it could
// still answer 422. Kept so an older pos-web build keeps working; same
// controller, same 200 advisory body.
Route::post('print-jobs/reprint-authorization', [PrintReprintAdviceController::class, 'store'])
    ->name('print-jobs.reprint-authorization');

Route::get('effective-payment-options', [PosEffectivePaymentOptionsController::class, 'index'])
    ->name('effective-payment-options.index');

// Debt lookup for the POS "Tra cứu nợ" dialog.
//
// The same controller is already mounted at /shops/{shopSlug}/debts, but that
// lives behind Platform SSO and receives the JWKS-verified local user
// after a user SSO login. pos-web authenticates with a DEVICE token and can
// never produce one, so calling the shops route from POS answers
// `401 missing principal`. plan-038 named pos-web as a consumer of this
// endpoint but only ever exposed it in a namespace POS cannot reach.
//
// Re-expose it here instead. No controller change is needed: ResolvePosShop and
// ResolveShopFromSlug share the ResolvesShopContext trait and set the same
// `shop` request attribute, which is all DebtController reads. The shop comes
// from the X-Shop-Slug header rather than the URL, so no slug segment.
Route::get('debts', [DebtController::class, 'index'])->name('debts.index');

// Orders left part-paid: money the shop is owed that no debt report can see,
// because nothing was charged to the customer's account. Kept OUT of the grouped
// /debts total on purpose — an on-account debt granted deliberately is not the
// same obligation as an order nobody finished, and summing them would report a
// single figure that answers neither question.
//
// The original note here justified that split by saying the grouped total "is
// what admin-web's 『Công nợ khách hàng』 panel sums". **There is no such panel**
// — measured 2026-08-06: admin-web has no debt surface at all, nothing in it
// calls `/shops/{slug}/debts`, and the string does not appear in any of its
// locale files. The ruling stands on its own merits; the evidence cited for it
// did not exist, which is worse than citing nothing, because it reads as
// settled practice.
//
// So this money is currently visible ONLY to a cashier at the POS. A manager has
// nowhere to see it. That gap is real and tracked separately — it needs a
// manager-facing surface, not another route.
//
// MUST stay above `debts/{customer}`: the wildcard would otherwise swallow the
// literal segment and "part-paid" would be parsed as a customer uuid.
Route::get('debts/part-paid', [DebtController::class, 'partPaid'])->name('debts.part-paid');

// One customer's individual debts. The grouped list answers "who owes and how
// much" — enough for a manager, and precisely NOT enough to collect: a
// settlement posts `metadata.settles_payment_id`, and that id appears nowhere in
// an aggregate. Without this route the POS dialog can list debtors and then do
// nothing about them, which is the state it shipped in.
Route::get('debts/{customer}', [DebtController::class, 'show'])->name('debts.show');

// =========================================================================
//  Revenue report — daily / monthly aggregates for pos-web reports screen
// =========================================================================

Route::prefix('revenue')->name('revenue.')->group(function () {
    Route::get('summary', [PosRevenueController::class, 'summary'])->name('summary');
    Route::get('by-product', [PosRevenueController::class, 'byProduct'])->name('byProduct');
    Route::get('voids', [PosRevenueController::class, 'voids'])->name('voids');
    Route::get('void-events', [PosRevenueController::class, 'voidEvents'])->name('void-events');
});

// =========================================================================
//  Menus (read-only on POS)
// =========================================================================

Route::prefix('menus')->name('menus.')->group(function () {
    Route::get('/', [MenuController::class, 'index'])->name('index');
    Route::get('by-day/{dayOfWeek}', [MenuController::class, 'indexByDay'])
        ->where('dayOfWeek', '[0-6]')
        ->name('byDay');
    Route::get('{menu}', [MenuController::class, 'show'])->name('show');
    // #3163 — phải đứng TRƯỚC `{menu}/products`? Không: hai path khác hẳn segment
    // cuối nên không đụng nhau. Đặt cạnh nhau vì chúng là một cặp — pill lấy từ
    // `sections`, món lấy từ `products?section_id=`.
    Route::get('{menu}/sections', [MenuController::class, 'listSections'])->name('sections.index');
    Route::get('{menu}/products', [MenuController::class, 'listProducts'])->name('products.index');
});

// =========================================================================
//  Menu availability (plan-056) — the POS "Tồn món" screen
// =========================================================================
//
// A namespace of its OWN, not extra verbs on `menus` above. Those four routes
// feed the ordering screen and must keep answering exactly what they answer
// today; these six are the stock switchboard and deliberately serve
// turned-off rows, which the ordering screen must never see. Sharing a path
// would put both behind one default, and a wrong default there puts a sold-out
// dish back in the cart picker.
//
// This is the CLOUD-DIRECT door, used when pos-web runs in cloud mode or the
// shop has no workstation. The LAN door is the workstation's mirror of these
// same URLs; the sync-UP door is `/api/v1/workstation/menu-*`, which carries a
// device token instead of an SSO session.
//
// Writes are PUT-with-a-value, never toggle: a workstation replays queued ops
// at least once, and a flip that lands twice puts the dish back on sale.
Route::prefix('menu-availability')->name('menu-availability.')->group(function () {
    Route::get('menus', [MenuAvailabilityController::class, 'index'])->name('menus.index');
    Route::get('menus/{menu}', [MenuAvailabilityController::class, 'show'])->name('menus.show');

    Route::put('products/{menuProduct}', [MenuAvailabilityController::class, 'setProductAvailability'])
        ->name('products.set');
    Route::put('skus/{menuProductSku}', [MenuAvailabilityController::class, 'setSkuAvailability'])
        ->name('skus.set');
    // One topping on one dish. A SINGLE-row write — deliberately not the admin
    // sync endpoint, which replaces every override of the group and would wipe
    // the shop's topping prices as a side effect of hiding one item.
    Route::put('products/{menuProduct}/toppings/{toppingItem}', [MenuAvailabilityController::class, 'setToppingAvailability'])
        ->name('toppings.set');
    Route::post('menus/{menu}/sections/{menuSection}/bulk', [MenuAvailabilityController::class, 'bulkSetSectionAvailability'])
        ->name('sections.bulk');
    // "Turn off size Lớn for this dish" — an EXPLICIT list of menu_product_sku
    // ids, because an option value is a name for a SET of variant rows, not a
    // thing with a shop-scoped row of its own (`product_option_values` has no
    // branch column; writing there would turn the size off at every branch).
    Route::post('menus/{menu}/skus/bulk', [MenuAvailabilityController::class, 'bulkSetSkuAvailability'])
        ->name('skus.bulk');
});

// =========================================================================
//  Customers (POS-facing subset)
// =========================================================================

Route::prefix('customers')->name('customers.')->group(function () {
    Route::post('find-or-create', [CustomerController::class, 'findOrCreate'])->name('findOrCreate');
    Route::get('{customer}/outstanding', [CustomerController::class, 'outstanding'])->name('outstanding');
});

// =========================================================================
//  Orders — full lifecycle managed from POS
// =========================================================================

Route::prefix('orders')->name('orders.')->group(function () {
    // Init + Update
    Route::put('{customerOrder}/init', [CustomerOrderController::class, 'init'])->name('init');
    Route::put('{customerOrder}', [CustomerOrderController::class, 'update'])->name('update');

    // Workflow actions
    // Accept a customer-submitted takeaway (pending|confirmed → open) — the
    // POS "Tiếp nhận đơn" button. Cloud-fallback twin of the workstation's
    // local POST /pos/orders/{id}/confirm.
    Route::post('{customerOrder}/confirm', [CustomerOrderController::class, 'confirm'])->name('confirm');
    Route::post('{customerOrder}/checkout', [CustomerOrderController::class, 'checkout'])->name('checkout');
    // #2479 — đường NGƯỢC lại của checkout. Thiếu nó, một cú chạm nhầm "Tính
    // tiền" chỉ còn cách huỷ cả đơn rồi gõ lại trước mặt khách.
    Route::post('{customerOrder}/reopen', [CustomerOrderController::class, 'reopen'])->name('reopen');
    Route::post('{customerOrder}/void', [CustomerOrderController::class, 'voidOrder'])->name('void');

    // plan-034 — POS soft-lock so dine-in customer devices stop adding
    // items while staff is fixing the order. Customer-web reacts to the
    // Reverb broadcast OrderEditingStarted / OrderEditingEnded.
    Route::patch('{customerOrder}/start-edit', [CustomerOrderController::class, 'startEdit'])->name('start-edit');
    Route::patch('{customerOrder}/end-edit', [CustomerOrderController::class, 'endEdit'])->name('end-edit');

    // Items
    Route::post('{customerOrder}/items', [CustomerOrderController::class, 'addItem'])->name('items.store');
    Route::patch('{customerOrder}/items/{item}', [CustomerOrderController::class, 'updateItem'])->name('items.update');
    Route::post('{customerOrder}/items/{item}/void', [CustomerOrderController::class, 'voidItem'])->name('items.void');
    // plan-045 — refund N units of a line (appends a negative-value line).
    Route::post('{customerOrder}/items/{item}/refund', [CustomerOrderController::class, 'refundItem'])->name('items.refund');
    Route::delete('{customerOrder}/items/{item}', [CustomerOrderController::class, 'removeItem'])->name('items.destroy');

    // Split bill
    Route::get('{customerOrder}/split-bill', [CustomerOrderController::class, 'splitBill'])->name('split-bill');
    // Plan 033 — by-items preview (read-only).
    Route::get('{customerOrder}/split-by-items/preview', [CustomerOrderController::class, 'splitByItemsPreview'])->name('split-by-items.preview');

    // Table management
    Route::post('{customerOrder}/merge-table', [CustomerOrderController::class, 'mergeTable'])->name('merge-table');
    Route::post('{customerOrder}/unmerge-table', [CustomerOrderController::class, 'unmergeTable'])->name('unmerge-table');
    // BR-O11 — gộp hai đơn ĐANG CÓ để thu tiền chung. Khác `merge-table`, cái
    // đó gắn thêm một bàn TRỐNG (bàn đang có đơn là 409 của chính nó).

    // Coupon
    Route::post('{customerOrder}/apply-coupon', [CustomerOrderController::class, 'applyCoupon'])->name('apply-coupon');
    Route::delete('{customerOrder}/coupon', [CustomerOrderController::class, 'releaseCoupon'])->name('release-coupon');

    // Payments
    Route::get('{customerOrder}/payments', [OrderPaymentController::class, 'index'])->name('payments.index');
    // Plan 030 — payment creation is hard-locked behind an open cashier shift.
    Route::post('{customerOrder}/payments', [OrderPaymentController::class, 'store'])
        ->middleware(ResolveOpenTillSession::class)
        ->name('payments.store');
    Route::post('{customerOrder}/payments/{payment}/refund', [OrderPaymentController::class, 'refund'])->name('payments.refund');

    // #1088 — Stripe Terminal (server-driven card_present). Charge hands the
    // outstanding balance to a registered branch reader; settlement rides the
    // payment_intent.succeeded webhook (#1125 async lifecycle).
    Route::post('{customerOrder}/stripe-terminal/charge', [StripeTerminalController::class, 'charge'])
        ->name('stripe-terminal.charge');

    // #1779 — hoá đơn đỏ IN TRỰC TIẾP qua workstation; đường phát hành/lưu
    // customer_invoices đã gỡ.

    // CRUD
    Route::get('/', [CustomerOrderController::class, 'index'])->name('index');
    // Plan 030 — order creation is hard-locked behind an open cashier shift.
    Route::post('/', [CustomerOrderController::class, 'store'])
        ->middleware(ResolveOpenTillSession::class)
        ->name('store');
    Route::get('{customerOrder}', [CustomerOrderController::class, 'show'])->name('show');
    Route::delete('{customerOrder}', [CustomerOrderController::class, 'destroy'])->name('destroy');
});

// =========================================================================
//  Plan-038 VAT invoice — show + void
// =========================================================================

// =========================================================================
//  Cashier Shift — Till sessions & reconciliation (Plan 030)
// =========================================================================

Route::prefix('till')->name('till.')->group(function () {
    Route::get('current', [TillController::class, 'current'])->name('current');
    Route::get('gap-preview', [TillController::class, 'gapPreview'])->name('gap-preview');
    // #2696 — anh em của gap-preview, cùng màn mở ca, KHỐI RIÊNG: đơn còn treo
    // tiền (tiền có thể chưa vào) chứ không phải khoản thu chưa gắn ca.
    Route::get('unresolved-orders', [TillController::class, 'unresolvedOrders'])->name('unresolved-orders');
    Route::get('denominations', [TillController::class, 'denominations'])->name('denominations');
    Route::get('tender-types', [TillController::class, 'tenderTypes'])->name('tender-types');
    Route::get('tender-categories', [TillController::class, 'tenderCategories'])->name('tender-categories');
    // #1156 — terminals + accepts for brand sub-choice / per-terminal 精算 sections.
    Route::get('payment-terminals', [TillController::class, 'paymentTerminals'])->name('payment-terminals');

    Route::prefix('sessions')->name('sessions.')->group(function () {
        Route::post('/', [TillSessionController::class, 'store'])->name('store');
        // Plan-032 — manager-only list of stale + expired sessions. Static
        // segment 'stale' must register BEFORE the {session} dynamic route
        // or it'd be swallowed as a session id.
        Route::get('stale', [TillSessionController::class, 'stale'])->name('stale');
        Route::get('{session}', [TillSessionController::class, 'show'])->name('show');
        Route::get('{session}/reconciliation', [TillSessionController::class, 'reconciliation'])->name('reconciliation');
        Route::get('{session}/order-summary', [TillSessionController::class, 'orderSummary'])->name('order-summary');
        Route::post('{session}/cash-events', [TillSessionController::class, 'cashEvent'])->name('cash-events.store');
        Route::patch('{session}/draft', [TillSessionController::class, 'draft'])->name('draft');
        Route::post('{session}/close', [TillSessionController::class, 'close'])->name('close');
        // Plan-046 — handover: settle the shift but keep the chain open.
        Route::post('{session}/handover', [TillSessionController::class, 'handover'])->name('handover');
        Route::post('{session}/abandon', [TillSessionController::class, 'abandon'])->name('abandon');
        // Plan-032 — manager exit doors.
        Route::post('{session}/force-abandon', [TillSessionController::class, 'forceAbandon'])->name('force-abandon');
        Route::post('{session}/manual-settle', [TillSessionController::class, 'manualSettle'])->name('manual-settle');
    });

    // Plan-046 — aggregate chain summary. {chainId} is a raw string (a chain has
    // no model). Branch-scoped in the controller (404 cross-branch).
    Route::get('chains/{chainId}/summary', [TillSessionController::class, 'chainSummary'])->name('chains.summary');
});

// #1088 — abort an in-flight Stripe Terminal collection (reader + intent).
Route::post('stripe-terminal/cancel', [StripeTerminalController::class, 'cancel'])
    ->name('stripe-terminal.cancel');
