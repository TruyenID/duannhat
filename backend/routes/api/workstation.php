<?php

use App\Http\Controllers\Api\V1\Workstation\AlertController;
use App\Http\Controllers\Api\V1\Workstation\BranchController;
use App\Http\Controllers\Api\V1\Workstation\CashDeviceObservationController;
use App\Http\Controllers\Api\V1\Workstation\CashDeviceTransactionController;
use App\Http\Controllers\Api\V1\Workstation\CouponReplicaController;
use App\Http\Controllers\Api\V1\Workstation\CustomerController;
use App\Http\Controllers\Api\V1\Workstation\CustomerReplicaController;
use App\Http\Controllers\Api\V1\Workstation\DeviceController;
use App\Http\Controllers\Api\V1\Workstation\ExpectedBuildController;
use App\Http\Controllers\Api\V1\Workstation\LogRecordController;
use App\Http\Controllers\Api\V1\Workstation\LogRequestController;
use App\Http\Controllers\Api\V1\Workstation\LotController;
use App\Http\Controllers\Api\V1\Workstation\MenuAvailabilityController;
use App\Http\Controllers\Api\V1\Workstation\MenuCatalogReplicaController;
use App\Http\Controllers\Api\V1\Workstation\MenuController;
use App\Http\Controllers\Api\V1\Workstation\MenuPromotionReplicaController;
use App\Http\Controllers\Api\V1\Workstation\MenuScheduleReplicaController;
use App\Http\Controllers\Api\V1\Workstation\MoneyOverwriteController;
use App\Http\Controllers\Api\V1\Workstation\OrderController;
use App\Http\Controllers\Api\V1\Workstation\OrderLifecycleController;
use App\Http\Controllers\Api\V1\Workstation\PaymentController;
use App\Http\Controllers\Api\V1\Workstation\PaymentMethodReplicaController;
use App\Http\Controllers\Api\V1\Workstation\PeripheralDeviceReplicaController;
use App\Http\Controllers\Api\V1\Workstation\PrintImageReplicaController;
use App\Http\Controllers\Api\V1\Workstation\PrintTemplateReplicaController;
use App\Http\Controllers\Api\V1\Workstation\StaffReplicaController;
use App\Http\Controllers\Api\V1\Workstation\SyncManifestController;
use App\Http\Controllers\Api\V1\Workstation\TableController;
use App\Http\Controllers\Api\V1\Workstation\TillController;
use App\Http\Controllers\Api\V1\Workstation\WorkstationEffectivePaymentOptionsController;
use App\Http\Controllers\Api\V1\Workstation\WorkstationPrinterReplicaController;
use App\Http\Controllers\Api\V1\Workstation\WorkstationPrintJobController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  Workstation Device Endpoints — authenticated via device.auth middleware
// =========================================================================

// Throttle: 300 req/min per device via the named `workstation` limiter
// (AppServiceProvider). It keys by device id — NOT client IP — because
// device.auth authenticates the device without calling Auth::shouldUse(),
// so a numeric `throttle:300,1` would fall back to IP and make every
// workstation/pos-web/kiosk behind one branch NAT share a single bucket,
// 429-ing the pull/push loop.
//
// The read-side baseline this number was sized against (12 catalog feeds
// every 5 s) is HISTORY: #1175 replaced the unconditional pulls with one
// conditional GET on `sync-manifest`, and #2712 moved the last static
// replica feeds behind it. An idle workstation now costs 12 manifest GETs
// (mostly 304) + 12 till-sessions + 12 effective-payment-options + 12
// customers + 12 kitchen orders per minute — roughly 60, with the pull
// bursts happening only when something actually changed.
// Sensitive write endpoints (payments) keep their per-route override below.
Route::prefix('v1/workstation')
    ->middleware(['device.auth:workstation', 'throttle:workstation'])
    ->group(function () {
        // #1175 phase 2 — one conditional GET per tick; 304 when nothing
        // changed, otherwise a per-feed version map so the client re-pulls
        // only the feeds that moved. Feed keys are a FROZEN contract.
        Route::get('sync-manifest', [SyncManifestController::class, 'index'])
            ->name('api.v1.workstation.sync-manifest');

        Route::get('lots', [LotController::class, 'index'])->name('api.v1.workstation.lots');
        Route::get('menu', [MenuController::class, 'index'])->name('api.v1.workstation.menu');
        Route::get('menu/handy', [MenuController::class, 'handy'])->name('api.v1.workstation.menu.handy');
        Route::get('branch', [BranchController::class, 'show'])->name('api.v1.workstation.branch');
        Route::get('orders', [OrderController::class, 'index'])->name('api.v1.workstation.orders.index');
        // plan-042 GAP-2: existence check for the recover-order flow.
        Route::get('orders/{customerOrder}', [OrderController::class, 'show'])->name('api.v1.workstation.orders.show');
        Route::post('orders', [OrderController::class, 'store'])->name('api.v1.workstation.orders.store');
        // #1097/#1114 — signed offline order replay (evidence-verified path).
        Route::post('orders/replay-offline', [OrderController::class, 'replayOffline'])
            ->name('api.v1.workstation.orders.replayOffline');
        Route::patch('orders/{customerOrder}/items/{item}/status', [OrderController::class, 'updateItemStatus'])
            ->name('api.v1.workstation.orders.items.status');
        // plan-045 — sync-UP of a LAN-created item refund (order.item_refund op).
        Route::post('orders/{customerOrder}/items/{item}/refund', [OrderController::class, 'refundItem'])
            ->name('api.v1.workstation.orders.items.refund');

        Route::post('payments', [PaymentController::class, 'store'])
            ->middleware('throttle:workstation-payments')
            ->name('api.v1.workstation.payments.store');
        Route::get('payments/{id}/status', [PaymentController::class, 'status'])
            ->middleware('throttle:workstation-payment-status')
            ->name('api.v1.workstation.payments.status');
        Route::post('payments/{payment}/confirm', [PaymentController::class, 'confirm'])
            ->name('api.v1.workstation.payments.confirm');
        Route::post('payments/{payment}/fail', [PaymentController::class, 'fail'])
            ->name('api.v1.workstation.payments.fail');
        // Plan-044 R2 (endpoint D) — propagate a post-creation till_session_id change
        // (a gap claim done locally on the workstation) UP to Cloud.
        Route::post('payments/{payment}/attribution', [PaymentController::class, 'attribution'])
            ->name('api.v1.workstation.payments.attribution');

        // plan-052 T1.2 (#1166) — sync UP of the local print JOURNAL. The
        // workstation owns its print queue outright (DESIGN §1b); this endpoint
        // only records what already came out of the paper, idempotently on the
        // device-generated job id.
        Route::post('print-jobs', [WorkstationPrintJobController::class, 'store'])
            ->name('api.v1.workstation.print-jobs.store');

        Route::post('self-revoke', [DeviceController::class, 'selfRevoke'])->name('api.v1.workstation.self-revoke');
        // #1093 — Ed25519 signing-key rotation (old key keeps its grace window).
        Route::post('keys/rotate', [DeviceController::class, 'rotateSigningKey'])->name('api.v1.workstation.keys.rotate');

        // Sync UP a LAN-applied table status change (pos-web → workstation local
        // mirror → here). Device-authed twin of POST /api/v1/pos/tables/{table}/status.
        Route::post('tables/{table}/status', [TableController::class, 'changeStatus'])
            ->name('api.v1.workstation.tables.status');

        // plan-056 — sync UP a LAN dish/variant availability toggle. Same shape
        // of door as tables/status above: the shop's POS wrote it to the
        // workstation's SQLite while possibly offline, and this is the replay.
        //
        // Carries a VALUE, never a flip: `sync_queue` delivery is at-least-once
        // and a retried toggle puts a sold-out dish back on sale.
        //
        // POST, not PUT, and that is not a semantics call — the workstation's
        // `cloudPost` is the ONLY outbound path that carries the device-token
        // re-stamp, the RECONCILE_PENDING / VARIANCE / dead-letter
        // classification and the global-cooldown rules. A PUT here would need a
        // second copy of ~150 lines of that logic, and the copy would drift.
        // Same door shape as `tables/{table}/status` right above.
        Route::post('menu-products/{menuProduct}/availability', [MenuAvailabilityController::class, 'setProductAvailability'])
            ->name('api.v1.workstation.menu-products.availability');
        Route::post('menu-product-skus/{menuProductSku}/availability', [MenuAvailabilityController::class, 'setSkuAvailability'])
            ->name('api.v1.workstation.menu-product-skus.availability');
        // Section-wide toggle, carried as an EXPLICIT id list — see the
        // controller docblock for why the section name is not replay-safe.
        Route::post('menu-availability/bulk', [MenuAvailabilityController::class, 'bulk'])
            ->name('api.v1.workstation.menu-availability.bulk');
        // Sync UP of "turn off size Lớn" — an explicit menu_product_sku id list.
        // An option value has no shop-scoped row of its own, so the write lands
        // on the variant rows that carry it (see the Shop-side controller).
        Route::post('menu-availability/skus/bulk', [MenuAvailabilityController::class, 'bulkSkus'])
            ->name('api.v1.workstation.menu-availability.skus.bulk');
        // Topping visibility. Body speaks `is_hidden` here because that IS the
        // stored column; the LAN wire speaks `is_active` and the workstation
        // inverts once, at its own boundary.
        Route::post('menu-products/{menuProduct}/toppings/{toppingItem}/availability', [MenuAvailabilityController::class, 'setToppingAvailability'])
            ->name('api.v1.workstation.menu-products.toppings.availability');

        // Replica feeds — read-only mirrors of branch-scoped catalog data the
        // workstation pulls DOWN every 5 min so pos-web can read them offline.
        // The shop is resolved from the authenticated workstation device's
        // paired branch, NOT from a slug or header — these endpoints belong to
        // the device, not to a user session.
        Route::get('payment-methods', [PaymentMethodReplicaController::class, 'index'])
            ->name('api.v1.workstation.payment-methods.index');
        // #2580 — khoản HOÀN phát sinh ở Cloud (điển hình: webhook charge.refunded
        // của Stripe) là vô hình với máy trạm, nên màn kết ca ở quán cao hơn Cloud
        // đúng bằng khoản đó. Feed này là đường để máy trạm biết.
        Route::get('customers', [CustomerReplicaController::class, 'index'])
            ->name('api.v1.workstation.customers.index');
        // Sync UP a LAN-created customer. Device-authed twin of
        // POST /api/v1/pos/customers/find-or-create — dedupes by phone within
        // the device's org+branch so the workstation gets the canonical id.
        Route::post('customers/find-or-create', [CustomerController::class, 'findOrCreate'])
            ->name('api.v1.workstation.customers.find-or-create');
        Route::get('menu-schedules', [MenuScheduleReplicaController::class, 'index'])
            ->name('api.v1.workstation.menu-schedules.index');
        Route::get('peripheral-devices', [PeripheralDeviceReplicaController::class, 'index'])
            ->name('api.v1.workstation.peripheral-devices.index');
        Route::post('peripheral-devices', [PeripheralDeviceReplicaController::class, 'store'])
            ->name('api.v1.workstation.peripheral-devices.store');
        Route::put('peripheral-devices/{peripheral_device}', [PeripheralDeviceReplicaController::class, 'update'])
            ->name('api.v1.workstation.peripheral-devices.update');
        Route::delete('peripheral-devices/{peripheral_device}', [PeripheralDeviceReplicaController::class, 'destroy'])
            ->name('api.v1.workstation.peripheral-devices.destroy');
        Route::get('printers', [WorkstationPrinterReplicaController::class, 'index'])
            ->name('api.v1.workstation.printers.index');
        // plan-053 (#1171) — print template registry, sync DOWN. Returns the
        // definitions ALREADY RESOLVED for the device's branch (system →
        // brand → shop merged server-side) so the workstation never has to
        // re-implement the three-layer merge in Go. `?since=` makes it a
        // delta; the version route serves a historical version for a reprint.
        Route::get('print-templates', [PrintTemplateReplicaController::class, 'index'])
            ->name('api.v1.workstation.print-templates.index');
        Route::get('print-templates/{kind}/versions/{version}', [PrintTemplateReplicaController::class, 'showVersion'])
            ->whereNumber('version')
            ->name('api.v1.workstation.print-templates.version');
        // #1957 mảnh B — ảnh in. `index` là DANH MỤC (hash + kích thước mỗi
        // biến thể bề rộng), `{hash}` là BYTE. Tách hai bước cố ý: gộp byte vào
        // index sẽ đẩy vài trăm KB qua đường truyền của quán mỗi tick 60s chỉ để
        // nói "chưa có gì đổi". Byte bất biến theo hash nên cache vĩnh viễn.
        Route::get('print-images', [PrintImageReplicaController::class, 'index'])
            ->name('api.v1.workstation.print-images.index');
        Route::get('print-images/{hash}', [PrintImageReplicaController::class, 'show'])
            ->where('hash', '[0-9a-f]{64}')
            ->name('api.v1.workstation.print-images.show');
        Route::get('menu-catalog', [MenuCatalogReplicaController::class, 'index'])
            ->name('api.v1.workstation.menu-catalog.index');
        Route::get('staff', [StaffReplicaController::class, 'index'])
            ->name('api.v1.workstation.staff.index');
        Route::get('coupons', [CouponReplicaController::class, 'index'])
            ->name('api.v1.workstation.coupons.index');
        Route::get('menu-promotions', [MenuPromotionReplicaController::class, 'index'])
            ->name('api.v1.workstation.menu-promotions.index');

        Route::get('effective-payment-options', [WorkstationEffectivePaymentOptionsController::class, 'index'])
            ->name('api.v1.workstation.effective-payment-options.index');

        // #1080 — device×option matrix so the LAN mirror preserves device
        // restrictions and per-terminal channel resolution (one request per
        // pull tick instead of N per paired device).
        Route::get('effective-payment-options/matrix', [WorkstationEffectivePaymentOptionsController::class, 'matrix'])
            ->name('api.v1.workstation.effective-payment-options.matrix');

        // Cashier-shift sync DOWN (replica) + sync UP (lifecycle). Sync UP
        // accepts workstation-supplied ids + timestamps so LAN-offline
        // open/close/abandon/cash-event preserves the real clock time.
        Route::get('till', [TillController::class, 'current'])
            ->name('api.v1.workstation.till.current');
        Route::get('till-sessions/active', [TillController::class, 'activeSessions'])
            ->name('api.v1.workstation.till.active-sessions');
        Route::get('till-denominations', [TillController::class, 'denominations'])
            ->name('api.v1.workstation.till.denominations');
        Route::get('till-tender-categories', [TillController::class, 'tenderCategories'])
            ->name('api.v1.workstation.till.tender-categories');
        Route::get('till-tender-types', [TillController::class, 'tenderTypes'])
            ->name('api.v1.workstation.till.tender-types');
        Route::post('till/sessions', [TillController::class, 'openSession'])
            ->name('api.v1.workstation.till.open');
        Route::post('till/sessions/{session}/cash-events', [TillController::class, 'cashEvent'])
            ->name('api.v1.workstation.till.cash-event');
        Route::post('till/sessions/{session}/close', [TillController::class, 'close'])
            ->name('api.v1.workstation.till.close');
        Route::post('till/sessions/{session}/abandon', [TillController::class, 'abandon'])
            ->name('api.v1.workstation.till.abandon');

        // LAN-offline order lifecycle replay. Endpoints are idempotent on the
        // workstation-supplied IDs so a queue retry after network failure
        // never duplicates items or double-applies a coupon. See
        // OrderLifecycleController for the full per-action contract.
        Route::prefix('orders/{customerOrder}')->group(function () {
            Route::post('init', [OrderLifecycleController::class, 'init'])
                ->name('api.v1.workstation.orders.init');
            Route::post('update', [OrderLifecycleController::class, 'update'])
                ->name('api.v1.workstation.orders.update');
            Route::post('delete', [OrderLifecycleController::class, 'delete'])
                ->name('api.v1.workstation.orders.delete');
            Route::post('void', [OrderLifecycleController::class, 'void'])
                ->name('api.v1.workstation.orders.void');
            // Accept a customer-submitted takeaway (pending|confirmed → open)
            // so it can flow through the regular checkout pipeline. Any other
            // status responds 200 no-op — replay-safe by design.
            Route::post('confirm', [OrderLifecycleController::class, 'confirm'])
                ->name('api.v1.workstation.orders.confirm');
            Route::post('checkout', [OrderLifecycleController::class, 'checkout'])
                ->name('api.v1.workstation.orders.checkout');
            Route::post('items', [OrderLifecycleController::class, 'addItems'])
                ->name('api.v1.workstation.orders.items.add');
            Route::post('items/{item}', [OrderLifecycleController::class, 'updateItem'])
                ->name('api.v1.workstation.orders.items.update');
            Route::post('items/{item}/delete', [OrderLifecycleController::class, 'deleteItem'])
                ->name('api.v1.workstation.orders.items.delete');
            Route::post('items/{item}/void', [OrderLifecycleController::class, 'voidItem'])
                ->name('api.v1.workstation.orders.items.void');
            Route::post('apply-coupon', [OrderLifecycleController::class, 'applyCoupon'])
                ->name('api.v1.workstation.orders.apply-coupon');
            Route::post('release-coupon', [OrderLifecycleController::class, 'releaseCoupon'])
                ->name('api.v1.workstation.orders.release-coupon');
            // P5 — table junction + payment refund.
            Route::post('merge-table', [OrderLifecycleController::class, 'mergeTable'])
                ->name('api.v1.workstation.orders.merge-table');
            Route::post('unmerge-table', [OrderLifecycleController::class, 'unmergeTable'])
                ->name('api.v1.workstation.orders.unmerge-table');
            // BR-O11 — gộp một đơn khác VÀO đơn này. `{customerOrder}` là ĐÍCH.
            Route::post('payments/{payment}/refund', [OrderLifecycleController::class, 'refundPayment'])
                ->name('api.v1.workstation.orders.payments.refund');
        });
        // #1806 S3 — máy trạm đẩy alert đang mở lên HQ. Fail-open: hỏng ở
        // đây không được chặn vòng đồng bộ; alert vẫn còn trong SQLite của quán
        // và vẫn hiện trên panel tại chỗ.
        Route::post('alerts', [AlertController::class, 'store'])
            ->name('api.v1.workstation.alerts.store');

        // #2878 (T1 của #2876) — sổ lượt thu tiền ở máy 釣銭機. Cùng luật
        // fail-open với `alerts`, nhưng ngược lại nó CÓ bảng ở Cloud: thứ đẩy
        // lên là chứng từ (tra theo mã, đối chiếu theo ca, lưu nhiều năm), chứ
        // không phải cảnh báo. Idempotent theo (máy, mã giao dịch) nên gửi lại
        // là vô hại — máy trạm cứ đẩy bù sau khi mất mạng.
        Route::post('cash-device-transactions', [CashDeviceTransactionController::class, 'store'])
            ->name('api.v1.workstation.cash-device-transactions.store');

        // #2879 (T2) — 在高 tại ranh ca. Chân THỨ BA của đối soát tiền mặt:
        // sổ ↔ MÁY ↔ người đếm. Trước đó chỉ có hai chân, nên một lệch không
        // phân định được là người đếm sai hay tiền thật sự thiếu.
        Route::post('cash-device-inventory', [CashDeviceObservationController::class, 'inventory'])
            ->name('api.v1.workstation.cash-device-inventory.store');

        // #2882 (T5) — sự cố có dấu thời gian. Alert trả lời "bây giờ có sao
        // không"; sổ này trả lời "tháng qua mất bao nhiêu". Hai câu hỏi khác
        // nhau, và câu thứ hai không suy ra được từ câu thứ nhất.
        Route::post('cash-device-errors', [CashDeviceObservationController::class, 'errors'])
            ->name('api.v1.workstation.cash-device-errors.store');
        // #2885 — bằng chứng lệch tiền (`order_money_overwrites` của máy trạm)
        // đi lên Cloud. Cùng middleware device-token với `alerts` ngay trên.
        // Append-only, idempotent theo `(device_id, local_id)` với unique index
        // ở tầng DB; endpoint này KHÔNG đụng đường tiền, nó chỉ ghi bằng chứng.
        Route::post('money-overwrites', [MoneyOverwriteController::class, 'store'])
            ->name('api.v1.workstation.money-overwrites.store');

        // #2901 — điều tra sự cố từ xa, theo cơ chế KÉO THEO YÊU CẦU.
        //
        // Cloud không gọi ngược vào máy trạm được (LAN của quán, sau NAT), nên
        // "kéo" cài thành yêu cầu TREO mà máy trạm tự nhận: nó hỏi
        // `log-requests` ở nhịp sync, thấy gì thì LỌC TẠI CHỖ theo
        // `docs/reference/workstation-log-allowlist.md` rồi đẩy lên
        // `log-records`. Cloud kiểm lại theo cùng bảng — không tin đầu kia đã
        // lọc đúng.
        //
        // Cùng middleware device-token với `alerts` ngay trên. Đường này CHỈ
        // ĐỌC LOG, phạm vi cố định — không có trường tự do nào đi tới máy
        // trạm, để nó không bao giờ thành cửa hậu thực thi lệnh.
        Route::get('log-requests', [LogRequestController::class, 'index'])
            ->name('api.v1.workstation.log-requests.index');
        Route::post('log-records', [LogRecordController::class, 'store'])
            ->name('api.v1.workstation.log-records.store');

        // #1806 S4 — feed RIÊNG cho bản phát hành mong đợi. Không nhét vào
        // `branch`: cấu hình quán và vòng đời phát hành đổi theo hai nhịp khác
        // nhau, gộp lại thì chúng kéo nhau invalidate cache.
        Route::get('expected-build', [ExpectedBuildController::class, 'index'])
            ->name('api.v1.workstation.expected-build');

    });
