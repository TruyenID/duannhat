<?php

namespace App\Services\Order;

use App\Models\CustomerOrder;
use App\Models\TableSession;
use App\Services\Customer\CustomerQrOrderService;
use App\Services\Order\Commands\AssignOrderTableSessionCommand;
use App\Services\Order\Commands\ChangeOrderItemsCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Coupon\OrderCouponService;
use App\Services\Order\Enums\OrderItemMutation;
use App\Services\Order\Internal\OrderMutationContextFactory;
use App\Services\Order\Results\CustomerTableOrderResult;
use App\Services\Order\ValueObjects\OrderLineSelectionPayload;
use App\Services\Order\ValueObjects\OrderToppingSelectionPayload;
use App\Services\Shop\Contracts\TableOccupancy;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * #1688 (plan-047 thin-controller/fat-service) — the dine-in "create or append
 * on a QR table" transaction, extracted verbatim from
 * `CustomerOrderController::store`. The controller kept HTTP concerns (table
 * lookup + 404, the `Idempotency-Key` header, the payload shape, the
 * `JsonResponse`); everything that must be atomic lives here.
 *
 * The transaction SCOPE is unchanged on purpose — every statement that used to
 * sit inside `DB::transaction` still does, in the same order:
 *
 *  1. row-lock the table,
 *  2. read the idempotency cache (early return on a hit),
 *  3. create-or-append + open the table session + apply the coupon,
 *  4. WRITE the idempotency cache, then commit.
 *
 * Step 4 staying INSIDE the transaction is load-bearing, not an oversight. The
 * cache write happens before COMMIT and therefore before the table lock is
 * released, so a concurrent replay of the same key — which blocks on that lock —
 * is guaranteed to see the cached result the moment it gets in. Move the write
 * after the commit and the replay slips into the gap, finds no cache entry, sees
 * the now-committed order and appends the same cart a second time: exactly the
 * double-charge plan-034 added the key to prevent.
 *
 * The flip side is documented rather than fixed here: because the cache is not
 * transactional, a COMMIT that fails after step 4 leaves a cached response for
 * an order that was rolled back, and later replays of that key would return it.
 * That hazard predates this refactor and changing it is a behaviour decision,
 * not a move.
 */
class CustomerTableOrderService
{
    /**
     * plan-034 — how long a shared-append `POST /orders` result is cached
     * against its `Idempotency-Key` so a retried request (double-tap, network
     * timeout) returns the first result instead of double-adding items.
     */
    public const IDEMPOTENCY_TTL_SECONDS = 86400;

    public function __construct(
        private readonly CustomerQrOrderService $qrOrders,
        private readonly OrderCouponService $couponService,
        private readonly OrderMutationFacade $orders,
        // #962 — bảng `tables` thuộc Organization, nên khoá hàng đi qua cổng
        // này thay vì query builder thô như bản cũ trong controller. Không chỉ
        // là hình thức: `architecture:raw-table-reads` có ngân sách 0 cho đọc
        // thô xuyên module, nên chép nguyên câu lệnh cũ sang đây là ĐỎ.
        private readonly TableOccupancy $tables,
    ) {}

    /**
     * Create the table's first order, or append this device's cart to the one
     * already open on it — atomically, and idempotently when the caller passes
     * a cache key.
     *
     * @param  array<string, mixed>  $validated  the CustomerOrderStoreRequest payload
     * @param  string|null  $customerId  the logged-in customer's id, or null for a guest
     * @param  string|null  $idempotencyCacheKey  null disables the replay guard entirely
     * @param  Closure(CustomerOrder, bool): array<string, mixed>  $presentOrder  renders the
     *                                                                            payload for an order; second arg is true on the shared-session append path.
     *                                                                            Called INSIDE the transaction because its output is what gets cached —
     *                                                                            it must read the order and nothing else.
     */
    public function createOrAppend(
        string $tableId,
        ?string $organizationId,
        ?string $branchId,
        array $validated,
        ?string $customerId,
        ?string $idempotencyCacheKey,
        Closure $presentOrder,
    ): CustomerTableOrderResult {
        // plan-034 — race-safe shared-session create/append. Lock the
        // table row first so two devices hitting POST /orders at the same
        // time on a still-free table can't each create a sibling order;
        // the second blocks until the first commits, then sees the new
        // session + order and APPENDS to it instead.
        //
        // plan-019 — coupon apply lives in the same transaction so a bad
        // code rolls back the dine-in order creation too.
        return DB::transaction(function () use (
            $tableId,
            $organizationId,
            $branchId,
            $validated,
            $customerId,
            $idempotencyCacheKey,
            $presentOrder,
        ): CustomerTableOrderResult {
            $this->tables->lockAll([$tableId]);

            if ($idempotencyCacheKey !== null && ($cached = Cache::get($idempotencyCacheKey)) !== null) {
                return new CustomerTableOrderResult($cached['body'], $cached['status']);
            }

            $session = TableSession::where('table_id', $tableId)
                ->where('status', TableSession::STATUS_OPEN)
                ->latest('opened_at')
                ->first();

            $existingOrder = $session
                ? CustomerOrder::where('table_session_id', $session->id)->active()->first()
                : null;

            // Device B / C / N path — session has an open order. Append
            // this device's cart items via the row-locked addItems path
            // (so concurrent "+" taps from multiple phones merge into one
            // line per SKU instead of racing into duplicates), broadcast,
            // and return the merged order.
            //
            // Previously we returned the existing order untouched and
            // silently dropped device B's items — the "lộn sộn" bug
            // reported 2026-06-12.
            if ($existingOrder) {
                if (! empty($validated['items'])) {
                    // plan-047 T2.12 phase 2 (#1090) — typed facade per item;
                    // the surrounding store transaction keeps the batch atomic.
                    foreach ($validated['items'] as $mergeItem) {
                        $mergePayload = new OrderLineSelectionPayload(
                            (string) Str::uuid(),
                            $mergeItem['menu_product_sku_id'] ?? null,
                            (int) $mergeItem['quantity'],
                            array_map(static fn (array $t) => new OrderToppingSelectionPayload(
                                (string) $t['topping_group_item_id'],
                                (string) $t['product_sku_id'],
                                (int) $t['quantity'],
                                $t['note'] ?? null,
                            ), $mergeItem['toppings'] ?? []),
                            $mergeItem['note'] ?? null,
                            $mergeItem['product_sku_id'] ?? null,
                        );
                        $this->orders->changeItems(new ChangeOrderItemsCommand(
                            OrderMutationContextFactory::fromOrder($existingOrder),
                            (string) $existingOrder->id,
                            OrderItemMutation::Add,
                            $mergePayload->fingerprint(),
                            $mergePayload,
                            null,
                            // #1715 — giá client đang HIỂN THỊ cho dòng này. Server
                            // chỉ dùng để từ chối (409 `line_unit_price_drift`),
                            // không bao giờ để tính. Cố ý ngoài `$mergePayload`:
                            // fingerprint chỉ nói về lựa chọn của khách.
                            isset($mergeItem['expected_unit_price'])
                                ? (float) $mergeItem['expected_unit_price']
                                : null,
                        ));
                    }
                    // refresh + load stay: the RESPONSE below is built from this
                    // instance, not just the broadcast.
                    $existingOrder->refresh();
                    $existingOrder->load('items.productSku.product');

                    // #2522 — the `OrderItemAdded` that used to fire here is
                    // GONE. `changeItems()` already fires one per call
                    // (WritesCustomerOrders), so a cart of N items broadcast
                    // N+1 times, not twice: the ×2 seen in the 人形町店 C-6 logs
                    // was the N=1 case, the mildest one.
                    //
                    // Removed from THIS side rather than from `changeItems()`
                    // because that method has three callers — HandyController
                    // (staff PDA), Shop\CustomerOrderController (POS) and this
                    // one — and only this one had a second emitter. Taking the
                    // event out of the shared path would have silenced the
                    // session fan-out for PDA and POS instead of de-duplicating
                    // it for the customer.
                }

                return $this->remember($idempotencyCacheKey, $presentOrder($existingOrder, true), 200);
            }

            // Device A path — no session/order yet. Create the order,
            // ensure a session exists, pin the order to it.
            $order = $this->qrOrders->createOrder($tableId, $validated, $customerId);

            if (! $session) {
                $session = TableSession::create([
                    'id' => (string) Str::uuid(),
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'table_id' => $tableId,
                    'status' => TableSession::STATUS_OPEN,
                    'opened_at' => now(),
                ]);
            }
            // plan-047 T2.12 (#1090) — canonical facade for the session bind.
            $this->orders->assignTableSession(new AssignOrderTableSessionCommand(
                OrderMutationContextFactory::fromOrder($order),
                (string) $order->id,
                (string) $session->id,
            ));
            $order->refresh();

            if (! empty($validated['coupon_code'])) {
                $this->couponService->apply(
                    $order,
                    $validated['coupon_code'],
                    $customerId,
                    'customer_web',
                    user: null,
                    downgradeExclusivePromotions: (bool) ($validated['downgrade_exclusive_promotions'] ?? false),
                );
                $order->refresh();
                $order->load('items.productSku.product');
            }

            return $this->remember($idempotencyCacheKey, $presentOrder($order, false), 201);
        });
    }

    /**
     * Record this request's outcome against its `Idempotency-Key` so a replay
     * short-circuits to it. Runs inside the caller's transaction, before the
     * commit that releases the table lock — see the class docblock for why that
     * ordering is the point.
     *
     * @param  array<string, mixed>  $body
     */
    private function remember(?string $idempotencyCacheKey, array $body, int $status): CustomerTableOrderResult
    {
        if ($idempotencyCacheKey !== null) {
            Cache::put(
                $idempotencyCacheKey,
                ['body' => $body, 'status' => $status],
                self::IDEMPOTENCY_TTL_SECONDS,
            );
        }

        return new CustomerTableOrderResult($body, $status);
    }
}
