<?php

namespace App\Services\Customer;

use App\Events\OrderPaid;
use App\Events\WorkstationSyncPoke;
use App\Mail\OrderPaidInvoiceMail;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\ShopOrderSetting;
use App\Models\TableSession;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\CustomerOrderTypeEnum;
use App\Omnify\Enums\TableStatusEnum;
use App\Services\Inventory\Contracts\OrderLineStockDeduction;
use App\Services\Order\Internal\EloquentOrderPersistence;
use App\Services\Order\StockDriftAlertService;
use App\Services\Shop\Contracts\TableOccupancy;
use App\Services\Shop\EffectiveOrderPolicyService;
use App\Support\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OrderClosingService
{
    /**
     * #1666 (#962) — cổng {@see OrderLineStockDeduction} thay cho
     * `StockDeductionService`. Cùng engine bên dưới, chỉ khác chỗ nhận id thay vì
     * model, nên đường trừ kho không đổi hành vi — xem hợp đồng để biết vì sao
     * chữ ký toàn primitive là ĐIỀU KIỆN để cổng được công bố.
     */
    public function __construct(
        private OrderLineStockDeduction $stockDeduction,
        /**
         * #2697 — trừ kho hỏng ở đây là tiền đã thu + sổ kho lệch. Dòng
         * `Log::error` bên dưới ở lại cho alerting của DevOps; lớp này là
         * đường tới một CON NGƯỜI, thứ mà 69 lần nổ ngày 12/08 không có.
         */
        private StockDriftAlertService $stockDriftAlerts,
    ) {}

    private function orderPersistence(): EloquentOrderPersistence
    {
        return app(EloquentOrderPersistence::class);
    }

    /**
     * Is the order paid enough to close — allowing for currency rounding?
     *
     * order.total_amount is roundUpToStep(subtotal + tax + service), and the
     * tax/service components are themselves rounded up, so the amount a client
     * computes + pays can land a couple of minor units BELOW total_amount on
     * integer currencies (JPY/VND/…). A strict `paid >= total` then leaves a
     * fully-intended payment stuck at `paying` (never "Hoàn thành") over a
     * ¥2 rounding gap — the takeaway bug.
     *
     * Tolerance = 2× the currency rounding step (the realistic cumulative
     * round-up across tax/service/total). On JPY that's ¥2 — a negligible
     * write-off, far below any meaningful underpayment.
     */
    public static function isPaidEnough(CustomerOrder $order): bool
    {
        // Resolve the currency from the SAME authority the pricing engine uses —
        // shop_order_settings.currency_code — NOT branches.currency. The latter is
        // routinely null, and the 'JPY' fallback then sized the rounding tolerance
        // at 2 yen (step 1.0 × 2) for a USD order: a 98.01 payment on a 100.00 USD
        // bill fell inside 100.00 − 2.00 and auto-closed as fully paid, booking
        // 1.99 of phantom revenue (#821 E3). In USD the tolerance is 0.02, so the
        // short payment correctly does NOT close.
        $currency = ShopOrderSetting::where('branch_id', $order->branch_id)->value('currency_code')
            ?? $order->branch?->currency
            ?? 'JPY';
        $tolerance = 2 * RoundingMode::step('auto', (string) $currency);

        return (float) $order->paid_amount >= (float) $order->total_amount - $tolerance;
    }

    // =========================================================================
    //  Close
    // =========================================================================

    /**
     * Atomically close a customer order once all payments are collected.
     *
     * Idempotent — if the order is already closed the method returns early
     * without aborting, so callers do not need to guard against duplicate
     * confirm callbacks.
     */
    public function close(CustomerOrder $order): CustomerOrder
    {
        return DB::transaction(function () use ($order) {
            // 1. Lock the row to prevent concurrent closes.
            $order = CustomerOrder::lockForUpdate()->find($order->id);

            // 2. Guard: already closed — return early (idempotent).
            $currentStatus = $order->status instanceof CustomerOrderStatusEnum
                ? $order->status
                : CustomerOrderStatusEnum::from($order->status);
            if ($currentStatus === CustomerOrderStatusEnum::Closed) {
                return $order->load(['customer', 'items.productSku']);
            }

            // 3. Verify sufficient payment (rounding-tolerant — see isPaidEnough).
            if (! self::isPaidEnough($order)) {
                abort(409, 'Insufficient payment');
            }

            // 3b. Dine-in (pay-after) only: verify every non-voided item has
            // been served. A closed dine-in bill should mean the kitchen
            // finished and the customer received everything — otherwise the
            // bill shows Hoàn thành while items still say "Chờ chế biến"
            // (confused QA during plan-024). Voided items are skipped.
            //
            // Takeaway is prepay (kiosk/counter pay-before-prep): the customer
            // pays first and the kitchen makes the item AFTER, so "all served"
            // cannot be a precondition — these close on full payment alone.
            $orderType = $order->order_type instanceof CustomerOrderTypeEnum
                ? $order->order_type
                : CustomerOrderTypeEnum::tryFrom((string) $order->order_type);

            if ($orderType !== CustomerOrderTypeEnum::Takeaway) {
                // Customer đã thanh toán đầy đủ (kiosk/POS/online) → coi như
                // họ đã nhận đủ món. Auto-mark unserved items thành served
                // tại thời điểm close (bảo toàn invariant "closed bill ↔ all
                // items served" mà KDS UI dựa vào — fixes plan-024 issue).
                // Thay vì abort(409) làm kiosk pay flow kẹt, trust payment
                // signal: khách trả tiền nghĩa là đã hài lòng với đồ ăn.
                $this->orderPersistence()->markUnservedItemsAsServedAtClose($order);
            }

            // 4. Close the order. Absorb any sub-total-unit rounding remainder
            // into paid_amount so a closed bill never shows a stray "còn nợ ¥2"
            // (the gap isPaidEnough tolerated is a rounding write-off, not debt).
            $this->orderPersistence()->finalizeClosedOrderHeader($order);
            $order->refresh();

            // plan-034 — close the shared dine-in session along with the
            // order so any future scan of the same QR opens a fresh one.
            // No-op for orders without a session (legacy + takeaway).
            if ($order->table_session_id) {
                TableSession::where('id', $order->table_session_id)
                    ->where('status', TableSession::STATUS_OPEN)
                    ->update([
                        'status' => TableSession::STATUS_CLOSED,
                        'closed_at' => now(),
                    ]);
            }

            // 5. Stock-out transactions for non-voided items.
            //
            // Plan-024 changes the existing single-transaction flow into a
            // 2-phase deduction:
            //   (a) Phase 1 — SKU stock-out for items whose ProductSku has
            //       `inventory_mode = track_stock`. Items marked
            //       `made_to_order` (default) are skipped because the SKU
            //       itself is ephemeral; raw materials are the inventory
            //       of record.
            //   (b) Phase 2 — Recipe → Material deduction. For every
            //       `track_stock` SKU with a Recipe, aggregate the
            //       ingredient consumption across all order items and
            //       emit a SINGLE combined `sales_material_consumption`
            //       stock_out transaction. FEFO inside
            //       StockTransactionService picks lots automatically.
            //
            // Both transactions are submitted (not just created) so the
            // warehouse's `auto_approve_stock_out` flag drives
            // `completeTransaction` and the StockLevel writes land
            // synchronously. Without `submit()` the transaction would sit
            // in Draft forever — a pre-existing latent bug discovered
            // during plan-024 T0.6 discovery.
            // A collected payment must NEVER be rolled back by a stock failure.
            // close() runs inside the same DB transaction that confirmed the
            // final payment (OrderPaymentService::create/confirm → close), so an
            // InsufficientStockException bubbling out of the stock-out submit
            // below would roll the whole transaction back — losing money the
            // customer already handed over. Ring-fence the entire inventory
            // deduction in a savepoint (nested DB::transaction) + try/catch: a
            // shortage or any inventory error rolls back ONLY the stock writes,
            // logs for ops reconciliation, and lets the order stay closed with
            // the payment intact. Inventory can be corrected via a manual
            // adjustment; the deduction being best-effort mirrors the existing
            // soft-deleted-material handling in emitMaterialConsumptionTransaction.
            try {
                DB::transaction(function () use ($order) {
                    $this->deductStock($order);
                });
            } catch (\Throwable $e) {
                Log::error('[inventory.stock_drift] order-close: stock deduction failed — order stays closed, payment preserved', [
                    'order_id' => $order->id,
                    'order_code' => $order->order_code,
                    'error' => $e->getMessage(),
                ]);

                // #2697 — và nói với một con người. `raise()` tự nuốt mọi lỗi
                // của nó: cái ring-fence ngay trên vừa cứu một khoản tiền đã
                // thu, một sự cố ở tầng thông báo không được phép huỷ việc đó.
                $this->stockDriftAlerts->raise(
                    $order,
                    StockDriftAlertService::STAGE_ORDER_CLOSE,
                    $e->getMessage(),
                );
            }

            // 6. Release all merged tables. #491 — the branch policy decides
            // whether a paid table returns to `free` (ready immediately) or
            // `cleaning` (staff must mark it clean first). Resolves to `free`
            // by default, preserving the #432 behaviour, unless HQ / this shop
            // opted into `cleaning`.
            //
            // #962 — qua cổng `TableOccupancy` thay vì ghi thẳng `tables`:
            // `App\Models\Table` thuộc Organization. Chính sách vẫn được phân
            // giải Ở ĐÂY (nó là luật của đơn), cổng chỉ ghi kết quả.
            app(TableOccupancy::class)->releaseByOrderAfterPayment(
                (string) $order->id,
                $this->tableStatusAfterPayment($order) === TableStatusEnum::Cleaning->value,
            );

            // 7. Audit log.
            $order->logAudit('closed');

            // plan-035 — fire the paid-invoice mail when the customer left
            // an email (queued so we don't block the close transaction).
            if ($order->customer_takeaway_email) {
                try {
                    Mail::to($order->customer_takeaway_email)
                        ->queue(new OrderPaidInvoiceMail($order->fresh(['branch', 'items.productSku.product'])));
                } catch (\Throwable $e) {
                    \Log::warning('OrderPaidInvoiceMail queue failed', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 8. Broadcast OrderPaid event — customer-web đang ở màn QR
            // "Đang chờ thanh toán" sẽ tự swap sang "Thanh toán thành công".
            // ShouldDispatchAfterCommit + ShouldBroadcastNow → fire ngay sau
            // khi outer DB transaction commit, không qua queue.
            OrderPaid::dispatch($order);

            // Empty poke so the branch workstation pulls orders now (PayPay /
            // customer-web close never hits a local POS confirm). A dead
            // broadcaster must not fail close — same catch as catalog rebuild.
            try {
                if ($order->branch_id) {
                    WorkstationSyncPoke::dispatch((string) $order->branch_id);
                }
            } catch (\Throwable $e) {
                Log::warning('workstation_sync_poke_failed', [
                    'branch_id' => $order->branch_id,
                    'order_id' => $order->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }

            // 9. Return the order loaded with relations.
            return $order->load(['customer', 'items.productSku']);
        });
    }

    /**
     * Phase 5 of close() — stock-out deduction for non-voided items.
     *
     * Extracted so close() can wrap it in its own savepoint + try/catch: an
     * inventory failure (e.g. InsufficientStockException on a warehouse that
     * disallows negative sales) must roll back ONLY the stock writes, never the
     * already-collected payment. See the call site for the money-safety rationale.
     */
    private function deductStock(CustomerOrder $order): void
    {
        // plan-051 (#1150) — the deduction engine moved to
        // StockDeductionService so it can also fire per-line at on_add /
        // on_preparing. At close it sweeps every line WITHOUT a per-line
        // marker (on the default on_close path that is every line, and the
        // emitted transactions are byte-identical to the legacy phase-5
        // shape, per-order marker included). Lines already deducted by an
        // earlier hook are skipped — no double deduction when the shop's
        // stock_deduction_timing changed mid-day.
        $this->stockDeduction->sweepUndeductedLinesAtClose((string) $order->id);
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    /**
     * #491 — the effective table status a paid table returns to:
     * shop override (`shop_order_settings.table_status_after_payment`) ??
     * HQ default (`brand_order_policies.default_table_status_after_payment`) ??
     * `free`. NULL on the shop row means "inherit HQ". Only `free`/`cleaning`
     * are valid; anything unexpected falls back to `free`.
     */
    private function tableStatusAfterPayment(CustomerOrder $order): string
    {
        return EffectiveOrderPolicyService::tableStatusAfterPayment(
            $order->branch_id,
            $order->brand_id,
        );
    }

    /**
     * plan-040 C8: callers that already recorded genealogy from a LOCKED FEFO
     * allocation (track_stock material consumption) pass the made_to_order
     * subset in `$items` so those rows are not double-recorded. When `$items`
     * is null the legacy behaviour (all non-voided items) is preserved for the
     * direct-call test fixtures and any external caller.
     *
     * @param  Collection<int, CustomerOrderItem>|null  $items
     */
    public function recordSalesGenealogy(CustomerOrder $order, string $transactionId, ?Collection $items = null): void
    {
        // plan-051 — the walk moved to StockDeductionService together with the
        // rest of phase 5; this delegate keeps the public API (tests + any
        // external caller) stable.
        //
        // #1666 — it now goes through the published port, which takes ids. `null`
        // must stay `null` (= "every non-voided line"); an empty Collection must
        // stay an empty LIST, not collapse back into `null`.
        $this->stockDeduction->recordSalesGenealogy(
            (string) $order->id,
            $transactionId,
            $items === null
                ? null
                : $items->map(static fn ($item): string => (string) $item->id)->values()->all(),
        );
    }
}
