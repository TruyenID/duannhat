<?php

namespace App\Services\Order\Internal\Concerns;

use App\Events\OrderItemAdded;
use App\Events\OrderItemStatusChanged;
use App\Events\OrderVoided;
use App\Exceptions\MenuPromotionException;
use App\Exceptions\OrderEditingLockedException;
use App\Exceptions\RefundException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderCondition;
use App\Models\OrderItemTopping;
use App\Models\ShopOrderSetting;
use App\Models\User;
use App\Models\VoidReason;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\CustomerOrderTypeEnum;
use App\Omnify\Enums\OrderItemPriceSourceEnum;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Omnify\Enums\StockDeductionTimingEnum;
use App\Omnify\Enums\TableStatusEnum;
use App\Services\Catalog\Contracts\ProductCategoryLookup;
use App\Services\Customer\OrderClosingService;
use App\Services\Customer\OrderPricingCalculator;
use App\Services\Customer\PricingResult;
use App\Services\Customer\SplitByItemsCalculator;
use App\Services\Inventory\Contracts\OrderLineStockDeduction;
use App\Services\Order\Contracts\OpenTillSessionLookup;
use App\Services\Order\Contracts\OrderCouponLedger;
use App\Services\Order\Contracts\OrderLineCatalogAnchors;
use App\Services\Order\Contracts\OrderLineTaxBatch;
use App\Services\Order\Contracts\OrderLineTaxPricing;
use App\Services\Order\Contracts\OrderMenuLineDirectory;
use App\Services\Order\Contracts\OrderPaymentLedgerReads;
use App\Services\Order\Contracts\OrderToppingSelectionPricing;
use App\Services\Order\Contracts\PricedToppingSelection;
use App\Services\Order\Contracts\ToppingSelectionExistence;
use App\Services\Order\Coupon\OrderCouponService;
use App\Services\Order\Internal\UnitPriceDriftGuard;
use App\Services\Promotion\Contracts\FloatingSectionPricing;
use App\Services\Promotion\Contracts\MenuPromotionResolver;
use App\Services\Shop\Contracts\TableOccupancy;
use App\Services\Shop\VoidableStatusResolver;
use App\Support\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** @internal Order/OrderItem Eloquent writers — consumed only by EloquentOrderPersistence. */
trait WritesCustomerOrders
{
    protected SplitByItemsCalculator $splitByItemsCalculator;

    protected OrderPricingCalculator $orderPricingCalculator;

    /**
     * #962 · 7a-8 — `ToppingLinePricing` không còn là tham số ở đây. Nó tồn tại
     * đúng để dựng `new ToppingSelectionPricer($this->toppingPricing)`, mà pricer
     * đã chuyển sang Catalog và được container tự dựng sau cổng.
     */
    protected function initializeOrderWriters(
        SplitByItemsCalculator $splitByItemsCalculator,
        OrderPricingCalculator $orderPricingCalculator,
    ): void {
        $this->splitByItemsCalculator = $splitByItemsCalculator;
        $this->orderPricingCalculator = $orderPricingCalculator;
    }

    private function couponService(): OrderCouponService
    {
        return app(OrderCouponService::class);
    }

    /**
     * #1590 — cổng chiếm dụng bàn. Resolve qua container giống
     * `couponService()` ở trên: đây là trait, không có constructor để tiêm.
     */
    private function tables(): TableOccupancy
    {
        return app(TableOccupancy::class);
    }

    private function promotionService(): MenuPromotionResolver
    {
        return app(MenuPromotionResolver::class);
    }

    /**
     * Plan-044 — order↔shift attribution resolver. Lazy like couponService()
     * so existing default-arg DI test setups stay green.
     *
     * #1662 — qua CỔNG, không cầm `TillSessionService` của Pos nữa.
     */
    private function tillSessions(): OpenTillSessionLookup
    {
        return app(OpenTillSessionLookup::class);
    }

    /**
     * #1662 — sổ tiền của đơn, đọc qua cổng do Ordering khai (Payments hiện thực).
     */
    private function paymentLedger(): OrderPaymentLedgerReads
    {
        return app(OrderPaymentLedgerReads::class);
    }

    /**
     * #962 · 7a-7 — đóng thuế cho dòng đơn, qua cổng do Ordering khai (Pricing hiện
     * thực). Trait này KHÔNG còn cầm `TaxResolver` hay `TaxType`.
     *
     * Luôn `beginBatch()` MỘT lần cho MỘT thao tác đơn hàng rồi dùng lại lô đó cho
     * mọi dòng trong thao tác — đó là vòng đời memo mà plan-043 §7 chốt. Gọi
     * `beginBatch()` trong vòng lặp là đưa N+1 quay lại.
     */
    private function lineTaxPricing(): OrderLineTaxPricing
    {
        return app(OrderLineTaxPricing::class);
    }

    /**
     * #962 · 7a-7 — tra dòng menu (tầng 1/2/3 của plan-043), qua cổng do Ordering
     * khai (Catalog hiện thực). Thay cho `MenuProduct::query()` viết thẳng ở đây.
     */
    private function menuLines(): OrderMenuLineDirectory
    {
        return app(OrderMenuLineDirectory::class);
    }

    /**
     * #962 · 7a-7 — kiểm tham chiếu topping của đường replay máy trạm, qua cổng do
     * Ordering khai (Catalog hiện thực).
     */
    private function toppingSelections(): ToppingSelectionExistence
    {
        return app(ToppingSelectionExistence::class);
    }

    /**
     * #962 · 7a-8 — neo dòng đơn vào SKU + dòng menu, qua cổng do Ordering khai
     * (Catalog hiện thực). Thay cho `ProductSku::` / `MenuProductSku::` viết thẳng
     * trong trait này.
     */
    private function catalogAnchors(): OrderLineCatalogAnchors
    {
        return app(OrderLineCatalogAnchors::class);
    }

    /**
     * #962 · 7a-8 — định giá topping, qua cổng do Ordering khai (Catalog hiện thực).
     *
     * Trước đây là `new ToppingSelectionPricer($this->toppingPricing)` — một class
     * của Ordering ôm 297 dòng luật Catalog. Class đó đã chuyển sang
     * `App\Services\Topping\Internal`.
     */
    private function toppingSelectionPricing(): OrderToppingSelectionPricing
    {
        return app(OrderToppingSelectionPricing::class);
    }

    /**
     * plan-051 — per-line stock deduction engine. Lazy like couponService()
     * so existing default-arg DI test setups stay green.
     */
    private function stockDeduction(): OrderLineStockDeduction
    {
        return app(OrderLineStockDeduction::class);
    }

    /**
     * plan-051 hooks — deduct freshly-persisted / freshly-merged lines per the
     * branch's `stock_deduction_timing`:
     *
     *   - on_add: every touched line deducts now; a merge bump onto an
     *     ALREADY-deducted pending line is a qty change → delta adjust.
     *   - on_preparing: only lines BORN at/past preparing (no-KDS shops with
     *     default_order_item_status = preparing/served — DESIGN §2.2
     *     born-at-status rule); born-pending lines wait for the transition.
     *   - on_close: nothing — the close sweep owns it.
     *
     * Ring-fenced: an inventory failure must never roll back the order
     * mutation (mirrors the close() savepoint rationale). Each service call
     * opens its own nested transaction (savepoint), so catching here leaves
     * the outer order transaction intact.
     *
     * @param  array<int, array{item: CustomerOrderItem, previous_quantity: float|null}>  $entries
     */
    private function applyStockDeductionAfterAdd(CustomerOrder $order, array $entries, ?\DateTimeInterface $occurredAt = null): void
    {
        if ($entries === []) {
            return;
        }

        try {
            $timing = $this->stockDeduction()->timingForBranch((string) $order->branch_id);

            foreach ($entries as $entry) {
                /** @var CustomerOrderItem $item */
                $item = $entry['item'];

                if ($item->stock_deducted_at !== null) {
                    // Already deducted (on_add) — a merge/sync qty bump on the
                    // line is a quantity revision (T2.2 delta adjust).
                    $previous = $entry['previous_quantity'];
                    if ($previous !== null && abs((float) $item->quantity - (float) $previous) > 1e-9) {
                        $this->stockDeduction()->adjustDeductedLineQuantity((string) $item->id, (float) $previous);
                    }

                    continue;
                }

                $shouldDeduct = match ($timing) {
                    StockDeductionTimingEnum::OnAdd => true,
                    StockDeductionTimingEnum::OnPreparing => $this->stockDeduction()->hasReachedPreparing($this->itemStatusValue($item)),
                    StockDeductionTimingEnum::OnClose => false,
                };

                if ($shouldDeduct) {
                    $this->stockDeduction()->deductLine(
                        (string) $item->id,
                        $timing === StockDeductionTimingEnum::OnAdd ? 'on_add' : 'on_preparing',
                        $occurredAt,
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::error('[inventory.stock_drift] plan-051: add-time stock deduction failed — order mutation preserved', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Plan-019 helper — the category_ids a product sits under, used when
     * MenuPromotion's applies_to is `categories` or `mixed`.
     *
     * #2371 — hỏi Catalog qua cổng thay vì tự đọc thô bảng pivot của nó. Bảng
     * đó thuộc Catalog; đọc thẳng từ Ordering là nợ xuyên module mà
     * `RawTableReadsTest` giữ ở ngân sách 0.
     *
     * Câu văn ở đây cố ý KHÔNG viết nguyên dạng lời gọi cũ: bộ quét
     * `architecture:raw-table-reads` khớp cả trong docblock, nên nhắc lại nó
     * bằng chữ sẽ tự đếm thành một lần đọc xuyên module.
     *
     * @return array<int, string>
     */
    private function productCategoryIds(string $productId): array
    {
        return app(ProductCategoryLookup::class)->categoryIdsFor($productId);
    }

    /**
     * Re-stamp every live line's tax type + rate from the CURRENT resolution
     * chain.
     *
     * This is the one operation in the order domain that genuinely rewrites
     * history, so it is the one place immutability has to be asserted. Until
     * now "a settled order is frozen" was only TRANSITIVE — true because every
     * caller happened to be state-gated upstream — which means it held by
     * convention, not by construction, and a new caller inherited nothing.
     *
     * A closed or voided order has already been billed: its per-line rate is
     * what the customer was charged, what the 適格請求書 printed and what the
     * Z-report totalled. Re-resolving it against today's catalog would silently
     * restate all three.
     *
     * #2188 — the freeze now has NO exception: the two escape hatches
     * (`orders:backfill-tax-snapshots` and the BUG-8 lazy re-stamp of
     * NULL-rate lines) were removed with the legacy ruling, so `allowSettled`
     * went with them. Nothing may rewrite tax on a settled order, period.
     */
    private function reResolveOrderLines(CustomerOrder $order): void
    {
        $status = $this->resolveStatus($order);
        $settled = in_array($status, [
            CustomerOrderStatusEnum::Closed,
            CustomerOrderStatusEnum::Voided,
        ], true);

        if ($settled) {
            abort(409, "Cannot re-resolve tax on a settled order: status is '{$status->value}'. The per-line rate is what the customer was billed and what the invoice printed.");
        }

        $order->unsetRelation('items');
        $order->load([
            'items.productSku.product.taxType',
            'items.taxType',
            'items.orderItemToppings.productSku.product',
        ]);
        // #962 · 7a-7 — MỘT lô cho cả vòng lặp, đúng như `new TaxResolver` trước đây.
        $taxBatch = $this->lineTaxPricing()->beginBatch();

        foreach ($order->items as $item) {
            if ($this->itemStatusValue($item) === OrderItemStatusEnum::Voided->value) {
                continue;
            }
            $product = $item->productSku?->product;
            if ($product === null) {
                continue;
            }

            $menuLine = $this->menuLines()->taxContextForBranchProduct(
                (string) $order->branch_id,
                (string) $product->id,
            );
            // Dòng đã có type đóng dấu thì giữ type đó (tầng 1); chưa có thì mượn
            // override của dòng menu. Trước đây so sánh trên MODEL `$item->taxType`
            // / `$menuLine?->taxType`, nay trên id — cùng một giá trị, xem
            // `OrderLineTaxBatch` về vì sao id và model tương đương.
            $menuTaxTypeId = $item->tax_type_id === null
                ? $menuLine->taxTypeId
                : $item->tax_type_id;

            $resolution = $taxBatch->resolveForLine(
                (string) $product->id,
                $product->tax_type_id,
                $menuTaxTypeId,
                $order->branch_id,
                $order->brand_id,
                $menuLine->menuId,
                $menuLine->menuSectionId,
            );

            $item->update([
                'tax_type_id' => $resolution->taxTypeId,
                'tax_rate' => $resolution->rate,
            ]);
        }
    }

    /**
     * #2411 — ảnh chụp thuế cho một dòng SẮP SINH RA.
     *
     * `reResolveOrderLines` chỉ chạm dòng ĐÃ nằm trong DB, nên nó không đóng dấu
     * hộ chính lượt INSERT. Hai đường máy trạm dựa vào nó và cả hai đều sai, theo
     * hai kiểu khác nhau: `transportWorkstationSyncItems` ghi dòng KHÔNG có
     * `tax_rate` rồi mới stamp ở lượt UPDATE ngay sau, còn
     * `transportWorkstationGhostItem` không chạy bước nào sau đó cả — dòng ma ở
     * lại vĩnh viễn với `tax_rate` NULL, tức bị các đường đọc thuế DROP thẳng
     * (xem `refundedSubtotalFor`, #2257).
     *
     * Chuyển tiếp, KHÔNG tính lại: đúng chuỗi tầng mà `addItems` và
     * `reResolveOrderLines` đi qua.
     *
     * @return array{tax_type_id: string|null, tax_rate: float}
     */
    private function bornLineTaxSnapshot(CustomerOrder $order, string $productSkuId, OrderLineTaxBatch $taxBatch): array
    {
        $sku = $this->catalogAnchors()->requireSku($productSkuId);

        // Sản phẩm đã xoá mềm ⇒ không tầng nào giải ra tỉ lệ có nghĩa. Cả hai chỗ
        // gọi đã chặn SKU không bán được ở tầng trên (`resolveAuthoritativeItemPrices`
        // và cổng `isSellable` của ghost-create), nên nhánh này không với tới từ
        // ngoài; nó ở đây để đường ghi không bao giờ tự bịa một ảnh chụp thuế khi
        // catalog không trả lời.
        abort_if(
            ! $sku->productResolved || $sku->productId === null,
            422,
            'Cannot stamp a tax snapshot for a line whose product no longer resolves.',
        );

        $menuContext = $this->menuLines()->taxContextForBranchProduct(
            (string) $order->branch_id,
            (string) $sku->productId,
        );

        $resolution = $taxBatch->resolveForLine(
            (string) $sku->productId,
            $sku->productTaxTypeId,
            $menuContext->taxTypeId,
            $order->branch_id,
            $order->brand_id,
            $menuContext->menuId,
            $menuContext->menuSectionId,
        );

        return [
            'tax_type_id' => $resolution->taxTypeId,
            'tax_rate' => $resolution->rate,
        ];
    }

    /**
     * #2617 (ruling #2132 §B) — `original_unit_price` cho một dòng SẮP SINH RA.
     *
     * Dấu vết định hình giá là BẮT BUỘC trên mọi dòng: bằng giá strikethrough
     * khi một cơ chế (khuyến mãi thực đơn…) đã hạ giá, bằng chính `unit_price`
     * khi không cơ chế nào chạm vào — "không giảm" cũng là một sự kiện phải ghi.
     * Cùng luật với {@see bornLineTaxSnapshot} (#2411): thêm đường ghi dòng đơn
     * thì đi qua đây, một dòng ra đời với `original_unit_price` NULL là dữ liệu
     * hỏng ngay tại lượt INSERT, không phải "chưa kịp cập nhật".
     */
    private function bornLineOriginalUnitPrice(float $unitPrice, ?float $strikethroughUnitPrice): float
    {
        return $strikethroughUnitPrice ?? $unitPrice;
    }

    /**
     * plan-043 §7 — re-resolve + re-stamp EVERY line's tax rate after the
     * order_type changes (e.g. takeaway → dine_in flips the bentō line 8%→10%).
     * The line's tax TYPE does not change with order_type (it is the snapshot
     * type, passed back through the resolver as the override); only the rate +
     * the 酒類 escalation flag are recomputed. Caller runs recalculateTotals
     * afterwards.
     */
    /**
     * #962 · 7a-7 — ba helper cũ (`resolveMenuTaxTypeForProduct`,
     * `resolveMenuLineForProduct`, `menuContextFor`) đã chuyển sang
     * {@see OrderMenuLineDirectory}, hiện thực ở
     * `App\Services\Menu\Internal\EloquentOrderMenuLineDirectory` — cùng truy vấn,
     * cùng thứ tự, chỉ trả scalar thay vì model `MenuProduct`.
     *
     * `resolveMenuTaxTypeForProduct` bị bỏ hẳn: nó CHẾT từ trước, không nơi nào gọi
     * (chỉ còn định nghĩa). Lý do #1420 vì sao tầng 1 phải đọc ở CẢ hai lối tra —
     * đích danh và suy-lại-từ-chi-nhánh — nay nằm ở docblock của cổng.
     *
     * @internal Reload order graph after a mutation — mirrors CustomerOrderService::findById.
     */
    protected function reloadOrder(string $id): CustomerOrder
    {
        return CustomerOrder::with([
            'branch',
            'customer',
            'conditions',
            'items.productSku.product',
            'items.productSku.galleryFirst',
            'items.productSku.optionValue1.option',
            'items.productSku.optionValue2.option',
            'items.productSku.optionValue3.option',
            'items.orderItemToppings.toppingGroupItem.product',
            'items.orderItemToppings.toppingGroupItem.toppingGroup',
            'payments.paymentMethod',
            'tables',
        ])->findOrFail($id);
    }

    // =========================================================================
    //  Create (Open order)
    // =========================================================================

    public function create(array $data): CustomerOrder
    {
        // plan-041 — durable idempotency for workstation (LAN) sync-ups.
        // `client_order_id` is the workstation's local order UUID. A replay of
        // the same local order (network retry, crash recovery) must map to the
        // same cloud order WITHOUT minting a second ORD-#### sequence number.
        $clientOrderId = isset($data['client_order_id']) && trim((string) $data['client_order_id']) !== ''
            ? trim((string) $data['client_order_id'])
            : null;

        // Fast path: the common replay lands seconds/hours later, so the row is
        // already committed. Cheap lookup outside the transaction.
        //
        // `withTrashed()` is required: the DB `unique(client_order_id)` constraint
        // counts soft-deleted rows, so a replay of a since-cancelled order must
        // still resolve to the existing (trashed) row. Without it the lookup
        // misses, the INSERT collides on the unique index, and the request 500s
        // instead of returning idempotently.
        if ($clientOrderId !== null) {
            $existing = CustomerOrder::withTrashed()->where('client_order_id', $clientOrderId)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        try {
            return DB::transaction(function () use ($data) {
                // Cloud is the single authority for ORD-#### codes (gapless,
                // global-per-year). A caller MAY still pass an explicit code
                // (legacy/old-build bridge); otherwise allocate from the
                // transactional counter so the number is gap-free and unique.
                $providedCode = isset($data['order_code']) && trim((string) $data['order_code']) !== ''
                    ? trim((string) $data['order_code'])
                    : null;
                $data['order_code'] = $providedCode ?? $this->nextOrderCode();

                // #423 — a caller-supplied code (workstation sync UP, seed import)
                // bypasses nextOrderCode(), so the counter row never advances past
                // it. Left unreconciled, MAX(order_code) eventually overtakes
                // next_value and nextOrderCode() re-mints an already-inserted code
                // → duplicate-key 500 on every subsequent POS order. Fast-forward
                // the counter for the code's own year so it can never lag.
                if ($providedCode !== null) {
                    $this->reconcileOrderCodeCounter($providedCode);
                }

                $order = $this->insertOrder($data);

                // insertOrder re-fetches the row (losing Eloquent's
                // `wasRecentlyCreated` flag). Force it true on this genuine
                // fresh-insert path so callers can reliably distinguish a new
                // create from an idempotent replay (the fast-path and
                // collision-recovery returns below leave it false).
                $order->wasRecentlyCreated = true;

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Rare: two posts of the SAME local order race the unique
            // client_order_id guard. The losing transaction rolled back — its
            // counter increment is released, so no sequence number is skipped.
            // Return the row the winning transaction committed. `withTrashed()`
            // for the same reason as the fast path: the unique index counts
            // soft-deleted rows, so a collision may be against a trashed order.
            if ($clientOrderId !== null) {
                $existing = CustomerOrder::withTrashed()->where('client_order_id', $clientOrderId)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            // Legacy bridge: old workstation builds supply an explicit
            // order_code and no client_order_id. A re-sync under a fresh
            // idempotency key (e.g. after a restart) collides on the unique
            // order_code — resolve to that row instead of 500-ing. The provided
            // code never touches the counter, so no number is skipped.
            $providedCode = isset($data['order_code']) && trim((string) $data['order_code']) !== ''
                ? trim((string) $data['order_code'])
                : null;
            if ($clientOrderId === null && $providedCode !== null) {
                $existing = CustomerOrder::withTrashed()->where('order_code', $providedCode)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    /** @param  array<string, mixed>  $data */
    private function insertOrder(array $data): CustomerOrder
    {
        $data['order_type'] = $data['order_type'] ?? CustomerOrderTypeEnum::Spot->value;
        // plan-035 — allow callers to override the default status (e.g.
        // CustomerOrderController::storeByBranch passes Open when the
        // shop's effective policy is `prep_before_payment=false`). Only
        // fill the default when caller didn't preset it.
        if (! isset($data['status'])) {
            $data['status'] = $data['order_type'] === CustomerOrderTypeEnum::Takeaway->value
                ? CustomerOrderStatusEnum::Pending->value
                : CustomerOrderStatusEnum::Open->value;
        }
        // #1091 — the caller may supply the REAL sale instant; only default it
        // when nobody knows better. An order sold offline at 20:00 and synced
        // at 09:00 next morning used to be stamped with the SYNC time, which
        // dated it into the wrong business day (and, for the signed offline
        // path, threw away the tamper-proof `issued_at` the device signed).
        $data['opened_at'] = $data['opened_at'] ?? now();
        $data['subtotal'] = 0;
        // #2041 — these three components live in order_conditions. A create
        // payload may still carry discount_amount as an input; preserve that
        // intent in the dedicated manual field, never as a header scalar.
        if (array_key_exists('discount_amount', $data) && ! array_key_exists('manual_discount_amount', $data)) {
            $data['manual_discount_amount'] = $data['discount_amount'];
        }
        unset($data['discount_amount'], $data['service_charge'], $data['tax_amount']);
        $data['total_amount'] = 0;
        $data['paid_amount'] = 0;
        $data['total_tip'] = 0;

        // plan-043 BUG-1 — snapshot the tax-display mode (総額表示) at creation
        // from the branch's current prices_include_tax so a later admin flip
        // can't retro-change this order (§6.3). EVERY order-creation path funnels
        // through here (customer QR / workstation store / seeder), so this is the
        // single place the per-order flag gets set. Without it the NOT NULL column
        // stays at its false default forever and included-mode never activates —
        // applyPricing()'s `$order->is_tax_included ?? $settings` fallback is dead
        // code because the attribute is never null once loaded from the DB.
        if (! array_key_exists('is_tax_included', $data)) {
            $data['is_tax_included'] = ! empty($data['branch_id'])
                && (bool) (ShopOrderSetting::where('branch_id', $data['branch_id'])->value('prices_include_tax') ?? false);
        }

        // Stamp the ordering surface's active locale (resolved by SetLocale from
        // the client's Accept-Language / app_locale cookie) onto the order when
        // the caller didn't pass one. The customer-web QR (dine-in) path relies
        // on this: the workstation pulls `customer_locale` down and prints the
        // kitchen + hold slips in the language the guest ordered in, instead of
        // the workstation's fallback. Takeaway already passes its own value.
        if (! array_key_exists('customer_locale', $data) || $data['customer_locale'] === null) {
            $data['customer_locale'] = app()->getLocale();
        }

        // plan-045 — snapshot the branch's tax rounding rule (mode + decimals)
        // onto the order at creation, immutable afterwards. The engine reads THIS,
        // not the live setting, so changing the shop's rounding never re-rounds a
        // historical order. Same single creation funnel as is_tax_included above.
        if (! array_key_exists('tax_rounding_mode', $data)) {
            $setting = ! empty($data['branch_id'])
                ? ShopOrderSetting::where('branch_id', $data['branch_id'])->first(['tax_rounding_mode', 'tax_rounding_decimals'])
                : null;
            $data['tax_rounding_mode'] = $setting?->tax_rounding_mode ?: 'round';
            $data['tax_rounding_decimals'] = $setting?->tax_rounding_decimals;
        }

        // plan-031 — set payment_due_at for takeaway orders with counter payment
        // Check shop-level override first, then brand-level default.
        //
        // plan-035: skip when the caller pre-set status=open (the
        // `prep_before_payment=false` policy). In that mode the kitchen
        // has already started cooking → there is no "expire-if-not-paid"
        // semantic to drive, so the order-success modal and the
        // /orders history should NOT show a countdown.
        if ($data['order_type'] === CustomerOrderTypeEnum::Takeaway->value
            && ($data['payment_method'] ?? null) === 'counter'
            && ($data['status'] ?? null) !== CustomerOrderStatusEnum::Open->value) {
            $timeoutMinutes = null;

            // Tier 3: Shop-level override
            if (! empty($data['branch_id'])) {
                $branch = Branch::find($data['branch_id']);
                $timeoutMinutes = $branch?->takeaway_payment_timeout_minutes;
            }

            // Tier 1: Brand-level default (fallback)
            if ($timeoutMinutes === null && ! empty($data['brand_id'])) {
                $brand = Brand::find($data['brand_id']);
                $timeoutMinutes = $brand?->takeaway_payment_timeout_minutes;
            }

            if ($timeoutMinutes !== null && $timeoutMinutes > 0) {
                $data['payment_due_at'] = now()->addMinutes($timeoutMinutes);
            }
        }

        $tableIds = $data['table_ids'] ?? [];
        unset($data['table_ids']);

        if (! empty($tableIds)) {
            $this->validateAndAssignTables($tableIds);
        }

        // plan-044 — stamp the branch's currently-open cashier shift (R1). This
        // is the single funnel for every order-creation surface (POS/Handy/
        // Customer branch + QR, workstation sync-up), so attribution lands once
        // here. Open-only: a `closing` shift or no shift → NULL (the order is a
        // "gap" order, adopted by the next shift's carry-over).
        //
        // The value is resolved *authoritatively* on the server and OVERWRITES
        // anything inbound — the generated store-request validates
        // `till_session_id`, so a client could otherwise smuggle an arbitrary
        // (even cross-branch) session id past the funnel. The workstation
        // sync-UP R6 accept path (honour a same-branch id the LAN remapped) is a
        // separate, still-unbuilt slice; until then no surface legitimately
        // supplies its own id.
        if (! empty($data['branch_id'])) {
            $data['till_session_id'] = $this->tillSessions()->openSessionIdForBranch($data['branch_id']);
        } else {
            unset($data['till_session_id']);
        }

        $order = CustomerOrder::create($data);

        if (! empty($tableIds)) {
            $this->tables()->assign($tableIds, $order->id);
            // table_id is guarded on the model — a plain update() silently
            // no-ops, leaving the denormalized pointer NULL and breaking
            // continue-table + table_id filters. forceFill is required.
            $order->forceFill(['table_id' => $tableIds[0]])->save();
        }

        $order->logAudit('opened');

        return CustomerOrder::with(['items.productSku.product.taxType'])->findOrFail($order->id);
    }

    // =========================================================================
    //  Confirm (pending → open) — staff acknowledges a customer-submitted
    //  takeaway order. Without this transition the order cannot be checked
    //  out (checkout requires status=open|dining).
    // =========================================================================

    public function confirmOrder(CustomerOrder $order): CustomerOrder
    {
        $this->assertStatus($order, [
            CustomerOrderStatusEnum::Pending,
            CustomerOrderStatusEnum::Confirmed,
        ], 'confirm');

        $order->update([
            'status' => CustomerOrderStatusEnum::Open->value,
        ]);

        $order->logAudit('confirmed');

        return CustomerOrder::with(['items.productSku.product.taxType'])->findOrFail($order->id);
    }

    /**
     * #2479 — `checkout` → `open`, để sửa được một bill vừa chốt nhầm.
     *
     * ## Vì sao cửa này phải tồn tại
     *
     * #2471 gộp luồng thanh toán từ 3 chạm còn 1, nên cú chạm "Tính tiền" ĐẦU
     * TIÊN giờ đã `POST /checkout` luôn. Trước đó nó chỉ mở một cái form đóng
     * lại được. Chạm nhầm khi khách còn đang gọi thêm món thì đường thoát duy
     * nhất là **huỷ cả đơn rồi gõ lại trước mặt khách** — và huỷ đơn còn kéo
     * theo lý do huỷ + dấu vết cho một việc vốn không phải sự cố.
     *
     * ## Ba rào, và vì sao là ba cái này
     *
     * 1. **Chỉ từ `checkout`.** Không phải một nút "về open" vạn năng.
     * 2. **Chỉ khi đơn KHÔNG còn giữ đồng nào.** Đây là ranh giới của mọi POS:
     *    một khi tiền đã vào, sửa bill không còn là sửa nhầm mà là hoàn tiền —
     *    đường đó là void/refund và nó có sổ riêng.
     *
     *    Vị ngữ dùng ĐÚNG cái mà void dùng: `netCollectedForOrder() > 0`. Không
     *    tự đếm dòng `order_payments`, không đọc cột đệm `paid_amount`.
     *    `OrderPaymentLedgerReads` nói thẳng lý do: một lần hoàn tiền được ghi
     *    là dòng gốc +X chuyển `refunded` CỘNG một dòng −X `succeeded`, nên mọi
     *    cách gộp tự chế đều đẻ ra tín dụng ma. Có đúng một định nghĩa "tiền đơn
     *    còn giữ", và cửa này chỉ được trỏ vào nó.
     * 3. **Bắt buộc lý do, ghi audit.** Không có nó, reopen thành đường sửa
     *    tiền rẻ hơn void mà không ai thấy — đúng thứ làm hỏng sổ.
     *
     * CỐ Ý **không** bắt quyền quản lý. Chạm nhầm là chuyện thường ở quầy; bắt
     * gọi quản lý cho từng lần sẽ đẩy nhân viên sang huỷ-rồi-gõ-lại, mà đường đó
     * để lại dấu vết TỆ HƠN. Bản ghi audit mới là cái kiểm soát, không phải cái
     * khoá.
     */
    public function reopenOrder(CustomerOrder $order, string $reason): CustomerOrder
    {
        $this->assertStatus($order, [CustomerOrderStatusEnum::Checkout], 'reopen');

        $collected = $this->paymentLedger()->netCollectedForOrder((string) $order->id);

        if ($collected > 0) {
            // 409 y như `assertStatus` — cùng một câu trả lời "trạng thái hiện
            // tại không cho làm việc này". Đẻ ra shape lỗi thứ hai chỉ để phân
            // biệt lý do là bắt pos-web xử lý hai đường cho cùng một nút.
            abort(409, sprintf(
                'Cannot reopen: order is holding %s in payments — refund it instead of reopening.',
                number_format($collected, 2),
            ));
        }

        $order->update(['status' => CustomerOrderStatusEnum::Open->value]);

        $order->logAudit('reopened', ['reason' => $reason, 'from' => CustomerOrderStatusEnum::Checkout->value]);

        return CustomerOrder::with(['items.productSku.product.taxType'])->findOrFail($order->id);
    }

    // =========================================================================
    //  Init (first-write-wins update)
    // =========================================================================

    public function initOrder(CustomerOrder $order, array $data): CustomerOrder
    {
        $this->assertStatus($order, [CustomerOrderStatusEnum::Open], 'init');

        return DB::transaction(function () use ($order, $data) {
            $tableIds = $data['table_ids'] ?? [];

            // First-write-wins: only assign tables if order has none yet
            if (! empty($tableIds)) {
                $hasTables = $this->tables()->countHeldBy($order->id) > 0;

                if (! $hasTables) {
                    $this->validateAndAssignTables($tableIds);

                    $this->tables()->assign($tableIds, $order->id);

                    // Back-fill the denormalized primary-table pointer so
                    // continue-table + table_id filters resolve this order.
                    // table_id is guarded on the model, so forceFill is
                    // required — a plain update() silently no-ops.
                    $order->forceFill(['table_id' => $tableIds[0]])->save();
                }
            }

            // First-write-wins: only set guest_count if DB value is null
            $guestCount = $data['guest_count'] ?? null;
            if ($guestCount !== null && $order->guest_count === null) {
                $order->update(['guest_count' => $guestCount]);
            }

            $order->logAudit('init_updated');

            return $this->reloadOrder($order->id);
        });
    }

    // =========================================================================
    //  Update (general, last-write-wins)
    // =========================================================================

    public function update(CustomerOrder $order, array $data): CustomerOrder
    {
        $this->assertStatus($order, [CustomerOrderStatusEnum::Open], 'update');

        $allowedFields = ['guest_count', 'note', 'customer_id', 'order_type'];
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (empty($updateData)) {
            return $this->reloadOrder($order->id);
        }

        // plan-043 §7 — detect an order_type flip so we can re-resolve every
        // line's tax rate afterwards (the final rate is item × order_type).
        $currentType = $order->order_type instanceof CustomerOrderTypeEnum
            ? $order->order_type->value
            : (string) $order->order_type;
        $orderTypeChanged = array_key_exists('order_type', $updateData)
            && $updateData['order_type'] !== $currentType;

        return DB::transaction(function () use ($order, $updateData, $orderTypeChanged) {
            $order->update($updateData);

            if ($orderTypeChanged) {
                $this->reResolveOrderLines($order);
                $this->recalculateTotals($order);
            }

            $order->logAudit('updated');

            return $this->reloadOrder($order->id);
        });
    }

    /**
     * Hard-delete an order and route linked product reviews through Eloquent so
     * ProductReview::deleting rolls back denormalized rating aggregates. The DB
     * FK is ON DELETE CASCADE, which would otherwise wipe review rows silently.
     * Call this instead of CustomerOrder::forceDelete() outside tests.
     */
    public function forceDeleteOrder(CustomerOrder $order): void
    {
        $this->purgeProductReviews($order);

        $order->forceDelete();
    }

    /**
     * Reverse denormalized product rating aggregates for an order's reviews.
     *
     * Lives on the canonical order write boundary so the raw delete never
     * escapes it (plan-047 T4.14). CustomerOrder::forceDeleting delegates here
     * as a safety net for the force-deletes that bypass forceDeleteOrder()
     * (tests, tinker, cascades) — running it twice is harmless because the
     * second pass finds no rows.
     */
    public function purgeProductReviews(CustomerOrder $order): void
    {
        foreach ($order->productReviews()->get() as $review) {
            $review->delete();
        }
    }

    public function delete(CustomerOrder $order): void
    {
        $this->assertStatus($order, [CustomerOrderStatusEnum::Open], 'delete');

        // Cannot delete if any item has been served
        $hasServed = $order->items()
            ->where('status', OrderItemStatusEnum::Served->value)
            ->exists();

        if ($hasServed) {
            abort(409, 'Cannot delete an order with served items.');
        }

        // #2866 — rào TIỀN cho việc xoá KHÔNG nằm ở đây, mà ở
        // `CustomerOrder::deleting`. Cố ý, và đã đo:
        //
        // Đặt ở đây thì nó chỉ canh được đường đi QUA biên ghi này, trong khi lỗ
        // thật là mọi `$order->delete()` từ tinker/service khác/cascade đi vòng
        // qua sạch — đó là cách `ORD-2026-0018` (đang giữ ¥297 đã capture ở
        // Stripe) bị xoá mềm dù rào nói chỉ `Open` mới được xoá.
        //
        // Đặt cả hai chỗ thì tạo ra hai nguồn chân lý cho cùng một câu hỏi, và
        // chúng sẽ lệch nhau. Gỡ bản ở đây rồi chạy lại bộ test #2866: vẫn 5/5
        // xanh — tức bản này không canh thêm được gì.

        DB::transaction(function () use ($order) {
            // Plan-019 BR-COUP07 — release coupon before deleting so the
            // counter rolls back for a draft order that never reached
            // closed. Symmetric with voidOrder.
            $this->couponService()->releaseIfApplied($order);

            // Release tables
            $this->tables()->releaseByOrder($order->id);

            $order->delete();
        });
    }

    // =========================================================================
    //  Plan-021 — Continue table order (auto-close old, create new)
    // =========================================================================

    /**
     * POS flow: auto-close any active order on the given table(s), then create
     * a new order with items. Idempotent — safe to call even if table is free.
     *
     * @param  array{table_ids: string[], items: array, order_type?: string, customer_id?: string, guest_count?: int, note?: string}  $data
     */
    public function continueTableOrder(string $branchId, array $data): CustomerOrder
    {
        return DB::transaction(function () use ($branchId, $data) {
            $tableIds = $data['table_ids'] ?? [];

            if (empty($tableIds)) {
                abort(422, 'table_ids is required for continue-table operation.');
            }

            // 1. Find any active orders on these tables
            $activeOrders = CustomerOrder::whereIn('table_id', $tableIds)
                ->whereIn('status', [
                    CustomerOrderStatusEnum::Open->value,
                    CustomerOrderStatusEnum::Dining->value,
                    CustomerOrderStatusEnum::Checkout->value,
                    CustomerOrderStatusEnum::Paying->value,
                ])
                ->get();

            // 2. Retire them before freeing the table. `closed` is a terminal
            //    "fully paid / completed" state that HQ revenue aggregates
            //    (aggregate() sums total_amount of closed orders), so an order
            //    still carrying an outstanding balance must NOT be closed —
            //    that would book phantom revenue and silently drop the unpaid
            //    balance (issue #554). Only fully-paid orders are closed;
            //    anything still owing is voided (coupon released, items voided)
            //    so it is excluded from revenue and leaves no dangling money.
            foreach ($activeOrders as $oldOrder) {
                if (OrderClosingService::isPaidEnough($oldOrder)) {
                    $oldOrder->update([
                        'status' => CustomerOrderStatusEnum::Closed->value,
                        'closed_at' => now(),
                    ]);
                    $oldOrder->logAudit('auto_closed_before_continue');

                    continue;
                }

                // Unpaid — void instead of close.
                $this->couponService()->releaseIfApplied($oldOrder);

                // #1283 — captured before the bulk update. No operator is
                // present on this path, so there is no VoidReason to pick: the
                // compensation lands on the conservative branch (no restock)
                // and records a warning + the trail #1257 sweeps, instead of
                // the silence this path used to leave behind.
                $deductedLineIds = $this->deductedLineIdsAboutToBeVoided($oldOrder);

                $oldOrder->items()
                    ->where('status', '!=', OrderItemStatusEnum::Voided->value)
                    ->update([
                        'status' => OrderItemStatusEnum::Voided->value,
                        'voided_at' => now(),
                        'void_reason' => 'auto_voided_unpaid_before_continue',
                    ]);

                $this->compensateBulkVoidedLines($oldOrder, null, $deductedLineIds);

                $oldOrder->update([
                    'status' => CustomerOrderStatusEnum::Voided->value,
                    'voided_at' => now(),
                    'void_reason' => 'auto_voided_unpaid_before_continue',
                ]);
                $oldOrder->logAudit('auto_voided_unpaid_before_continue', [
                    'paid_amount' => (float) $oldOrder->paid_amount,
                    'total_amount' => (float) $oldOrder->total_amount,
                ]);
            }

            // 3. Release all tables
            $this->tables()->releaseByIds($tableIds);

            // 4. Create new order with items
            $data['branch_id'] = $branchId;
            $data['order_type'] = $data['order_type'] ?? CustomerOrderTypeEnum::DineIn->value;

            $items = $data['items'] ?? [];
            unset($data['items']);

            $newOrder = $this->create($data);

            // 5. Add items to the new order
            if (! empty($items)) {
                $this->addItems($newOrder, ['items' => $items]);
            }

            return $this->reloadOrder($newOrder->id);
        });
    }

    // =========================================================================
    //  Workflow
    // =========================================================================

    public function checkout(CustomerOrder $order, array $data): CustomerOrder
    {
        $this->assertStatus($order, [CustomerOrderStatusEnum::Open, CustomerOrderStatusEnum::Dining], 'checkout');

        // Must have at least one non-voided item
        $activeItems = $order->items()
            ->where('status', '!=', OrderItemStatusEnum::Voided->value)
            ->count();

        if ($activeItems === 0) {
            abort(422, 'Cannot checkout with no active items.');
        }

        return DB::transaction(function () use ($order, $data) {
            $discountAmount = (float) ($data['discount_amount'] ?? $order->discount_amount);

            // #1124 (E4 governance) — domain-level on purpose (guard-by-domain,
            // #557): every route/namespace reaching checkout passes through
            // here.
            //
            // Floor (EVERYONE, manager included): a manual discount can comp
            // the whole order but never EXCEED it.
            $liveSubtotal = (float) $order->items()
                ->where('status', '!=', OrderItemStatusEnum::Voided->value)
                ->sum('subtotal');
            if ($discountAmount > $liveSubtotal + 0.005) {
                abort(422, sprintf(
                    'discount_amount (%s) exceeds the order subtotal (%s).',
                    rtrim(rtrim(number_format($discountAmount, 2, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format($liveSubtotal, 2, '.', ''), '0'), '.'),
                ));
            }

            // Governance for a manual discount supplied ON THIS REQUEST
            // (product decisions recorded on #1124, 2026-07-27):
            //   1. a reason is mandatory — money leaves the till with no coupon
            //      behind it, so it must always say why;
            //   2. a non-manager is capped at
            //      ShopOrderSetting.manual_discount_max_percent of the live
            //      subtotal (default 20%); manager+ may comp to the floor.
            // pos-web's checkout draft ECHOES the server-computed
            // order.discount_amount back (plan-019 removed the free-form
            // field) — an unchanged echo, coupon-derived or a re-checkout of
            // an already-governed manual discount, is a no-op and must not
            // re-trigger the reason/cap gate. Governance fires only when this
            // request CHANGES the discount to a positive value.
            $requestedDiscount = isset($data['discount_amount']) ? (float) $data['discount_amount'] : null;
            if ($requestedDiscount !== null && $requestedDiscount > 0
                && abs($requestedDiscount - (float) $order->discount_amount) > 0.001) {
                $discountReason = trim((string) ($data['discount_reason'] ?? ''));
                if ($discountReason === '') {
                    abort(422, 'discount_reason is required when applying a manual discount.');
                }

                $actor = isset($data['actor_user_id']) ? User::query()->find($data['actor_user_id']) : null;
                $isManager = $actor !== null && (
                    $actor->hasRoleInContext('shop-manager', (string) $order->organization_id, (string) $order->branch_id)
                    || $actor->hasRoleInContext('org-manager', (string) $order->organization_id, (string) $order->branch_id)
                    || $actor->hasRoleInContext('org-admin', (string) $order->organization_id)
                );
                if (! $isManager) {
                    $pct = (float) (ShopOrderSetting::query()
                        ->where('branch_id', $order->branch_id)
                        ->value('manual_discount_max_percent') ?? 20.0);
                    $cap = $liveSubtotal * $pct / 100.0;
                    if ($requestedDiscount > $cap + 0.005) {
                        abort(422, sprintf(
                            'discount_amount (%s) exceeds the cashier cap (%s%% of subtotal = %s) — a manager must apply this discount.',
                            rtrim(rtrim(number_format($requestedDiscount, 2, '.', ''), '0'), '.'),
                            rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.'),
                            rtrim(rtrim(number_format($cap, 2, '.', ''), '0'), '.'),
                        ));
                    }
                }

                // The manual entry is recorded in its OWN columns — the
                // effective discount_amount below may later be overridden by
                // the coupon recompute (#550), but what the operator typed and
                // why stays on the order.
                $order->update([
                    'manual_discount_amount' => $requestedDiscount,
                    'manual_discount_reason' => $discountReason,
                ]);
            }

            // BR-SOS05: tax + service charge are server-applied from the
            // branch's ShopOrderSetting. POS no longer sends per-checkout
            // overrides. plan-043 §8 — set the discount, then price through the
            // shared per-rate engine (applyPricing) so the persisted values
            // match what the kiosk read + split-by-items preview compute live,
            // AND the per-line tax snapshots get stamped.
            $this->applyPricing($order, $discountAmount);

            $order->update([
                'status' => CustomerOrderStatusEnum::Checkout->value,
                'checkout_at' => now(),
            ]);

            // #1124 — a manual discount is money leaving the till with no
            // coupon behind it; stamp who/how much/why on the audit trail.
            $order->logAudit('checked_out', $discountAmount > 0
                ? array_filter([
                    'manual_discount_amount' => $discountAmount,
                    'manual_discount_reason' => $order->manual_discount_reason,
                ])
                : []);

            return $this->reloadOrder($order->id);
        });
    }

    /**
     * Device transports (kiosk/workstation) may collect payment while the order is
     * still Confirmed/Open/Dining. Promote to checkout without repricing.
     */
    public function promoteForPayment(CustomerOrder $order): CustomerOrder
    {
        $currentStatus = $this->resolveStatus($order);

        if (! in_array($currentStatus, [
            CustomerOrderStatusEnum::Confirmed,
            CustomerOrderStatusEnum::Open,
            CustomerOrderStatusEnum::Dining,
        ], true)) {
            return $this->reloadOrder($order->id);
        }

        $order->update([
            'status' => CustomerOrderStatusEnum::Checkout->value,
            'checkout_at' => now(),
        ]);

        return $this->reloadOrder($order->id);
    }

    /** First payment while checkout → paying. Idempotent when already paying. */
    public function beginPaying(CustomerOrder $order): CustomerOrder
    {
        if ($this->resolveStatus($order) === CustomerOrderStatusEnum::Checkout) {
            $order->update(['status' => CustomerOrderStatusEnum::Paying->value]);
        }

        return $this->reloadOrder($order->id);
    }

    public function stampStripeIntent(CustomerOrder $order, ?string $paymentIntentId): CustomerOrder
    {
        $order->update(['stripe_payment_intent_id' => $paymentIntentId]);

        return $this->reloadOrder($order->id);
    }

    public function refreshPaymentCacheFromLedger(CustomerOrder $order): CustomerOrder
    {
        $totals = $this->paymentLedger()->cachedTotalsForOrder((string) $order->id);

        $order->update([
            'paid_amount' => $totals->totalPaid,
            'total_tip' => $totals->totalTip,
        ]);

        return $this->reloadOrder($order->id);
    }

    public function voidOrder(CustomerOrder $order, array $data): CustomerOrder
    {
        $currentStatus = $this->resolveStatus($order);

        if ($currentStatus === CustomerOrderStatusEnum::Closed) {
            abort(409, 'Cannot void a closed order.');
        }

        // #547 — a `paying` order can already hold collected cash (split-bill
        // cash auto-confirms → succeeded immediately). Voiding it used to leave
        // that payment orphaned: still succeeded, still counted into the shift's
        // revenue + expected_cash, no refund, no trace. Block the void while any
        // money is net-collected so staff must refund first (which nets
        // paid_amount back to 0), mirroring plan-032's "no abandon with stamped
        // payment" guard. The refund path stays available on the order.
        $this->assertNoCollectedPayments($order);

        return DB::transaction(function () use ($order, $data) {
            // Plan-019 BR-COUP07 — release coupon BEFORE flipping status,
            // so releaseIfApplied() can still mutate while order is in
            // a modifiable state. Closed orders do not reach this branch
            // (assertStatus above rejects them).
            $this->couponService()->releaseIfApplied($order);

            // #1283 — optional structured reason, so the plan-051 truth table
            // is reachable when voiding a whole order. Without it the operator
            // has no way to say "put it back" and every deducted line stays
            // out (correct-but-blunt: an unknown reason never restocks).
            $voidReason = $this->resolveOrderVoidReason($order, $data);

            // Captured BEFORE the bulk update — see the helper's docblock.
            $deductedLineIds = $this->deductedLineIdsAboutToBeVoided($order);

            // Void all non-voided items
            $order->items()
                ->where('status', '!=', OrderItemStatusEnum::Voided->value)
                ->update([
                    'status' => OrderItemStatusEnum::Voided->value,
                    'voided_at' => now(),
                    'void_reason' => $data['void_reason'],
                    'void_reason_id' => $voidReason?->id,
                ]);

            $this->compensateBulkVoidedLines($order, $voidReason, $deductedLineIds);

            $order->update([
                'status' => CustomerOrderStatusEnum::Voided->value,
                'voided_at' => now(),
                'void_reason' => $data['void_reason'],
            ]);

            // Release ALL merged tables → free (not cleaning, since nothing was served).
            $releasedTableIds = $this->releaseOrderTables($order);

            $order->logAudit('voided');

            $fresh = $this->reloadOrder($order->id);

            // Broadcast the void → table-release to POS terminals on cloud
            // fallback (workstation LAN WS down). ShouldDispatchAfterCommit
            // holds it until this transaction commits, so clients never see a
            // released-table event for a void that later rolled back.
            event(new OrderVoided($fresh, $releasedTableIds));

            return $fresh;
        });
    }

    /**
     * #547 — guard: refuse to void an order that still has net-collected money.
     *
     * Any positive remainder means real cash/card was taken and never returned
     * — aborts 409 with a structured code so the FE can route the cashier to
     * "refund first" instead of silently orphaning the payment.
     *
     * #816 — this used to sum `succeeded` rows only, which is NOT how a refund
     * is ledgered: the original keeps its +X and flips to `refunded` while a
     * -X `succeeded` row is added. Summing `succeeded` alone therefore dropped
     * the +X and kept the -X, so a refund minted a phantom credit that
     * cancelled out other payments' real cash — refund one diner on a split
     * bill and the guard read 0 while the other diner's cash was still in the
     * drawer. OrderPayment::netCollectedForOrder() is the single definition of
     * "money this order still holds"; do not re-derive it here.
     */
    private function assertNoCollectedPayments(CustomerOrder $order): void
    {
        $netCollected = $this->paymentLedger()->netCollectedForOrder((string) $order->id);

        if ($netCollected > 0.0) {
            abort(response()->json([
                'message' => 'Cannot void an order that has collected payments. Refund the payments first.',
                'code' => 'void_blocked_collected_payment',
                'collected_amount' => number_format($netCollected, 2, '.', ''),
            ], 409));
        }
    }

    /**
     * Free every table currently held by this order (merged tables included)
     * and return the freed ids so a broadcast can tell clients which tables
     * to re-render.
     *
     * @return array<int, string>
     */
    private function releaseOrderTables(CustomerOrder $order): array
    {
        return $this->tables()->releaseByOrder($order->id);
    }

    /**
     * Cancel an order — an alias for voidOrder() with a caller-supplied reason.
     *
     * The shop `/cancel` HTTP alias was removed (staff now always POST `/void`
     * so a reason is recorded), so this survives only for the typed
     * CancelOrderCommand path, which carries its own required reason. Final
     * status: Voided; every voidOrder() guard and side effect applies.
     */
    public function cancel(CustomerOrder $order, ?string $reason = null): CustomerOrder
    {
        return $this->voidOrder($order, [
            'void_reason' => $reason ?? 'Cancelled by user.',
        ]);
    }

    /**
     * plan-031 — expire an overdue takeaway counter-pay order.
     *
     * Same teardown as voidOrder() — coupon released (BR-COUP07), every
     * non-voided item voided, held tables freed, audit logged, OrderVoided
     * broadcast so POS/KDS drop the order and release tables — but lands on
     * the dedicated terminal `expired` status and stamps `auto_cancelled_at`
     * so the customer countdown UI and admin reporting can tell a payment
     * timeout apart from a manual void.
     *
     * Called by the CancelOverdueTakeawayOrders scheduled job. Runs in its
     * own transaction so a single failed row can't abort the whole sweep.
     * The previous job body just flipped `status`/`auto_cancelled_at` with a
     * raw update, leaking any applied coupon's usage, leaving order_items
     * `active`, never freeing tables, and emitting no event or audit trail.
     */
    public function expireOverdueTakeaway(CustomerOrder $order): CustomerOrder
    {
        return DB::transaction(function () use ($order) {
            $reason = 'Auto-expired: takeaway payment window elapsed.';

            // Release the coupon BEFORE flipping status so releaseIfApplied()
            // can still mutate while the order is in a modifiable state.
            $this->couponService()->releaseIfApplied($order);

            // #1283 — captured before the bulk update. Uncollected takeaway:
            // the food was made and nobody came for it, so the conservative
            // no-restock branch is the right answer — but it must be recorded,
            // not left silent.
            $deductedLineIds = $this->deductedLineIdsAboutToBeVoided($order);

            $order->items()
                ->where('status', '!=', OrderItemStatusEnum::Voided->value)
                ->update([
                    'status' => OrderItemStatusEnum::Voided->value,
                    'voided_at' => now(),
                    'void_reason' => $reason,
                ]);

            $this->compensateBulkVoidedLines($order, null, $deductedLineIds);

            $order->update([
                'status' => CustomerOrderStatusEnum::Expired->value,
                'auto_cancelled_at' => now(),
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            // Takeaway orders rarely hold tables, but a QR-seated order that
            // switched to takeaway mid-session might — free them like a void.
            $releasedTableIds = $this->releaseOrderTables($order);

            $order->logAudit('expired', ['reason' => 'payment_timeout']);

            $fresh = $this->reloadOrder($order->id);

            event(new OrderVoided($fresh, $releasedTableIds));

            return $fresh;
        });
    }

    // =========================================================================
    //  Items
    // =========================================================================

    /**
     * Add one or more items to an open order.
     *
     * Each item resolves its `unit_price` in this order:
     *   1. If `menu_product_sku_id` is provided, use THAT MenuProductSku's
     *      selling_price (the exact menu line the FE staff picked).
     *   2. Otherwise resolve deterministically (#514): among this branch's
     *      active menus carrying the SKU, take the LOWEST selling_price
     *      (tie-broken by id). This is the authoritative rule shared with
     *      customer-web (display) and the workstation LAN path, so the
     *      price the guest agreed to always equals the price charged.
     *   3. Fall back to the raw ProductSku.selling_price when the SKU is
     *      off-menu for the branch.
     *
     * Merge rule (BR-OI06): after resolving unit_price, look up a still-
     * pending line on the same order with the same product_sku_id,
     * unit_price and note. If found, bump its quantity/subtotal instead
     * of creating a duplicate row — this is what makes repeated taps of
     * the same dish on the POS catalog stack onto one cart line. Notes
     * and menu-overridden prices are part of the key so "extra spicy"
     * and "plain" stay separate, and a different menu's price keeps its
     * own line. Items that have moved past `pending` (kitchen picked
     * them up) are never merged into — they represent a different batch.
     *
     * @param  array{items: array<int, array{product_sku_id: string, menu_product_sku_id?: string|null, quantity: int|float, note?: string}>}  $data
     * @return CustomerOrderItem[]
     */
    public function addItems(CustomerOrder $order, array $data): array
    {
        // plan-037 — `awaiting_confirmation` orders are created via
        // `CustomerOrderService::create` and then items get appended in
        // the same transaction; allow the writes here so checkout doesn't
        // 409 before the order is even committed.
        $this->assertStatus($order, [
            CustomerOrderStatusEnum::AwaitingConfirmation,
            CustomerOrderStatusEnum::Confirmed,
            CustomerOrderStatusEnum::Open,
            CustomerOrderStatusEnum::Pending,
        ], 'add items');

        return DB::transaction(function () use ($order, $data) {
            // plan-034 — serialise add-item requests across all devices in
            // the same dine-in session. `SELECT ... FOR UPDATE` on the
            // order row blocks any other transaction that's also trying
            // to mutate this order (totals recompute, items merge) until
            // we commit. Without this, two phones tapping "+" at the
            // same time could each see "no existing pending line for SKU
            // X" and insert separate duplicates instead of merging into
            // one (BR-OI06).
            $order = CustomerOrder::lockForUpdate()->findOrFail($order->id);

            // plan-034 — POS staff edit soft-lock. If staff hit
            // /start-edit within the last 60s and hasn't released yet,
            // refuse the customer write so the FE can show "Nhân viên
            // đang xử lý đơn".
            if ($order->editing_by_staff_at
                && $order->editing_by_staff_at->gt(now()->subSeconds(60))
            ) {
                throw new OrderEditingLockedException(
                    'Order is being edited by staff. Please try again in a moment.',
                );
            }

            $defaultItemStatus = $this->resolveDefaultItemStatus($order->branch_id);
            $servedStatus = OrderItemStatusEnum::Served->value;

            // #2522 — which lines this batch may merge into.
            //
            // BR-OI06 used to require `pending`, full stop. At a shop whose
            // `default_order_item_status` is `served`, no line is EVER pending,
            // so the merge could never fire: four helpings of one dish became
            // four lines and four kitchen slips, and the shop read that as "one
            // customer placed four orders". That is 人形町店 C-6.
            //
            // The rule now has two tiers, and the second is deliberately narrow
            // because a born-served line is one the kitchen may already be
            // cooking:
            //
            //   pending      → mergeable, unbounded. Nothing has left for the
            //                  kitchen, so the age of the line is irrelevant.
            //   born-served  → mergeable only INSIDE a short window AND only
            //                  while no kitchen ticket has been issued for the
            //                  order since. Both conditions, not either.
            //
            // Why both: the window alone would still let a second helping be
            // folded into a line whose slip is already on the pass — the paper
            // says one bowl, the bill says two, and only the paper is in the
            // cook's hand. The ticket check alone would let a line from two
            // hours ago absorb a fresh order simply because printing was off.
            [$mergeableStatuses, $mergeWindowOpensAt] = $this->resolveMergeWindow(
                $order,
                $defaultItemStatus,
            );
            $touchedItems = [];
            // plan-051 — lines touched by this batch, queued for the add-time
            // stock-deduction hook (with the pre-merge qty for delta adjusts).
            $stockHookEntries = [];

            // plan-043 §7 — one resolver per addItems call so its branch/brand
            // default memo stays fresh for this operation but is shared across
            // the items in this batch (avoids re-querying the defaults per line).
            $taxBatch = $this->lineTaxPricing()->beginBatch();
            $floatingPrices = app(FloatingSectionPricing::class)->resolveForSkus(
                (string) $order->branch_id,
                array_column($data['items'], 'product_sku_id'),
            );

            // #1715 — gom mọi dòng lệch giá của lô này, ném một lượt sau vòng lặp.
            // Tiền tệ của CHI NHÁNH quyết định độ chính xác khi so (JPY/VND về
            // đơn vị, USD về cent) — một lượt đọc cho cả lô.
            $priceDrift = new UnitPriceDriftGuard(
                ShopOrderSetting::query()
                    ->where('branch_id', $order->branch_id)
                    ->value('currency_code') ?? 'JPY',
            );

            foreach ($data['items'] as $idx => $itemData) {
                // #962 · 7a-8 — qua cổng; `requireSku` giữ nguyên `findOrFail` (404).
                $sku = $this->catalogAnchors()->requireSku((string) $itemData['product_sku_id']);

                // #902 — refuse to add a SKU whose product is not sellable
                // (draft / pending / approved-but-not-activated / paused /
                // rejected) or whose SKU is inactive. Placed BEFORE the
                // menu/non-menu price branch below so it also blocks a stale
                // active menu line pointing at a since-paused product. Throwing
                // here rolls back the whole addItems batch (atomic).
                if (! $sku->sellable) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.product_sku_id" => "product_not_sellable: {$sku->skuId}",
                    ]);
                }

                $menuProductSkuId = $itemData['menu_product_sku_id'] ?? null;
                // #962 · 7a-7 — id thay vì model `TaxType`; giá trị y hệt.
                $menuTaxTypeId = null;
                // Menu line context for the SHOP topping tier
                // (menu_product_topping_item_overrides is keyed by
                // menu_product_id). Null → shop tier skipped, same as offline.
                $menuProductId = null;

                if ($menuProductSkuId) {
                    // Explicit menu-line reference — use THAT override's price
                    // AND its menu-level tax type override (plan-043 §7 tier 1).
                    $menuProductSku = $this->catalogAnchors()->activeMenuLine(
                        (string) $menuProductSkuId,
                        (string) $order->branch_id,
                        $sku->skuId,
                    );
                    if ($menuProductSku === null) {
                        throw ValidationException::withMessages([
                            "items.{$idx}.menu_product_sku_id" => 'Menu SKU does not belong to this branch or product.',
                        ]);
                    }
                    $menuPrice = $menuProductSku->sellingPrice;
                    $menuTaxTypeId = $menuProductSku->taxTypeId;
                    $menuProductId = $menuProductSku->menuProductId;
                } else {
                    // #514 — deterministic fallback. The same SKU can appear on
                    // several active menus of one branch with different
                    // selling_prices (staging seeds 16+ rows per SKU). The old
                    // query had NO branch scope and no ordering, so `->value()`
                    // returned whichever row the DB happened to surface — often
                    // a DIFFERENT menu-price than customer-web resolved for the
                    // same SKU. Result: the guest agreed to one total but the
                    // saved order charged another (ORD-2026-4216: paid 3.471đ,
                    // order 3.667đ).
                    //
                    // Authoritative rule (shared with customer-web + the
                    // workstation LAN path): scope to THIS branch's active menus
                    // and take the LOWEST active menu-price, tie-broken by id.
                    // Lowest is deterministic AND never charges the guest more
                    // than any menu advertised for that SKU. The tax tiers of
                    // that row — including its own tier-1 override — are picked
                    // up from `menuContextFor($menuProductId)` below (#1420);
                    // this branch used to skip tier 1 entirely.
                    // Take the SAME lowest-price row for both the price and the
                    // menu_product_id so the shop topping tier resolves against
                    // the exact menu line whose price we charge.
                    $menuLine = $this->catalogAnchors()->cheapestActiveMenuLine(
                        (string) $order->branch_id,
                        $sku->skuId,
                    );
                    $menuPrice = $menuLine?->sellingPrice;
                    $menuProductId = $menuLine?->menuProductId;
                }

                unset($itemData['menu_product_sku_id']);

                // #1180 — tier-1 topping owner for a line the guest tapped in
                // the floating-section spotlight. Those items are served with
                // menu_product_sku_id = null ("off-menu: order by
                // product_sku_id only"), so an ABSENT explicit menu anchor is
                // exactly the signal that the tap came from the spotlight —
                // which is the surface that displayed the promo topping price.
                // A line that DID name a menu line keeps the menu tier, so
                // each section charges what it showed.
                $floatingSectionProductId = $menuProductSkuId
                    ? null
                    : ($floatingPrices[$sku->skuId]['floating_section_product_id'] ?? null);

                $rawUnitPrice = (float) ($menuPrice ?? $sku->sellingPrice);
                // #2618 (ruling #2132 §B) — snapshot NGUỒN giá ngay tại chỗ giá
                // được quyết, theo đúng precedence bên dưới. Floating chỉ là
                // nguồn khi nó THẤP HƠN (min() giữ giá menu khi floating cao
                // hơn — ghi `floating` cho dòng đó là snapshot sai).
                $priceSource = $menuPrice !== null
                    ? OrderItemPriceSourceEnum::Menu
                    : OrderItemPriceSourceEnum::SkuBase;
                $floatingPrice = $floatingPrices[$sku->skuId]['price'] ?? null;
                if ($floatingPrice !== null) {
                    if ((float) $floatingPrice < $rawUnitPrice) {
                        $priceSource = OrderItemPriceSourceEnum::Floating;
                    }
                    $rawUnitPrice = min($rawUnitPrice, $floatingPrice);
                }
                $unitPrice = $rawUnitPrice;
                $quantity = $itemData['quantity'];
                $note = $itemData['note'] ?? null;

                // Plan-019 (Decision B2 + B6) — auto-apply MenuPromotion at
                // addItems. Snapshot the discounted unit_price into the
                // line; original_unit_price holds the strikethrough value.
                $appliedPromotion = $this->promotionService()->activeFor(
                    $order->branch_id,
                    $sku->productId,
                    $this->productCategoryIds($sku->productId),
                );

                $originalUnitPrice = null;
                $appliedPromotionSnapshot = null;
                if ($appliedPromotion !== null) {
                    // Decision B5 reverse stacking guard — order already has
                    // a coupon and the new item's promotion is exclusive.
                    // Reject with structured 422; FE handles via the
                    // "auto-remove coupon" confirm dialog (Decision B7).
                    if (
                        $order->coupon_id !== null
                        // stacking_mode casts to an enum, so the old
                        // === 'exclusive_with_coupons' string comparison was
                        // ALWAYS false — the Decision-B5 guard was dead and a
                        // couponed order could stack an exclusive promotion
                        // (double discount). Compare the enum.
                        && $appliedPromotion->isExclusiveWithCoupons()
                    ) {
                        throw MenuPromotionException::cannotAddPromotionItemWithCoupon(
                            (string) $order->coupon_id,
                            [
                                'product_sku_id' => $sku->skuId,
                                'applied_promotion_id' => $appliedPromotion->id,
                                'product_id' => $sku->productId,
                            ],
                        );
                    }

                    $originalUnitPrice = $rawUnitPrice;
                    // #2618 — promotion quyết CÔNG THỨC giá cuối, kể cả khi
                    // percent = 0: nguồn là menu_promotion, khớp với
                    // applied_promotion_id không null.
                    $priceSource = OrderItemPriceSourceEnum::MenuPromotion;
                    $unitPrice = round(
                        $rawUnitPrice * (100 - $appliedPromotion->discountPercent) / 100,
                        2,
                        PHP_ROUND_HALF_UP,
                    );
                    // #1597 — ảnh chụp do Pricing dựng: nó sở hữu bản dịch tên
                    // khuyến mãi, và hình dạng mảng này đi thẳng vào cột JSON
                    // của dòng đơn nên phải giữ nguyên từng khoá.
                    $appliedPromotionSnapshot = $this->promotionService()->snapshotFor($appliedPromotion->id);
                }

                // #1715 — `$unitPrice` đã chốt: so với giá client đang HIỂN THỊ.
                // Phải nằm trước merge BR-OI06 bên dưới, vì merge khớp theo
                // `unit_price` — một dòng lệch giá sẽ lặng lẽ tạo dòng MỚI thay vì
                // báo cho ai biết. Ghi nhận rồi đi tiếp; `assertNoDrift()` sau vòng
                // lặp ném một lượt để client cập nhật cả giỏ trong một lần.
                $priceDrift->record(
                    $idx,
                    $sku->skuId,
                    $itemData['expected_unit_price'] ?? null,
                    $unitPrice,
                );
                // Khoá này KHÔNG phải cột của `customer_order_items`; `$itemData`
                // đi thẳng vào `create()` bên dưới nên để sót là insert cột không
                // tồn tại — cùng lý do `menu_product_sku_id` và `toppings` bị unset.
                unset($itemData['expected_unit_price']);

                // Plan 015 — validate + price toppings BEFORE merge check so
                // the topping tuple becomes part of the merge key (BR-OI06
                // extension: same SKU + different toppings = separate line).
                $toppings = $itemData['toppings'] ?? [];
                unset($itemData['toppings']);

                $toppingResult = $this->validateAndPriceToppings($sku->skuId, $toppings, $menuProductId, $floatingSectionProductId);
                // Discount toppings may carry a negative price, but the line
                // (unit_price + topping_subtotal) must never go below zero —
                // a discount can zero out a line, never pay the customer.
                $toppingSubtotal = max($toppingResult->subtotal, -$unitPrice);
                $toppingRows = $toppingResult->rows;
                // BR-OI06: the merge key must reflect the toppings that are
                // actually persisted — i.e. AFTER autofill of mandatory-group
                // defaults (validateAndPriceToppings injects is_default items
                // for omitted mandatory groups). Building it from the RAW
                // pre-autofill payload made two identical requests that both
                // relied on autofill hash as "no toppings", while
                // existingMergeKey() reads the persisted rows (which DO carry
                // the autofilled defaults) — so the keys never matched and the
                // lines duplicated instead of merging. Hash the priced rows.
                $mergeKey = $this->toppingMergeKey($toppingRows);

                // Merge into an existing pending line if key matches (BR-OI06).
                // lockForUpdate guards against concurrent addItems on the same
                // order (e.g. staff double-submit) creating parallel duplicates.
                $existing = $order->items()
                    ->where('product_sku_id', $sku->skuId)
                    ->whereIn('status', $mergeableStatuses)
                    ->when(
                        $mergeWindowOpensAt !== null,
                        // A born-served line is only mergeable for a short
                        // while. Pending lines keep the old, unbounded rule:
                        // nothing has been told to the kitchen about them, so
                        // age changes nothing.
                        fn ($q) => $q->where(
                            fn ($inner) => $inner
                                ->where('status', OrderItemStatusEnum::Pending->value)
                                // STRICT: a line created in the same tick as
                                // the kitchen ticket was ON that ticket.
                                // `>=` let it merge and the paper went stale.
                                ->orWhere('created_at', '>', $mergeWindowOpensAt)
                        ),
                    )
                    ->where('unit_price', $unitPrice)
                    ->where('topping_subtotal', $toppingSubtotal)
                    ->when(
                        $note === null,
                        fn ($q) => $q->whereNull('note'),
                        fn ($q) => $q->where('note', $note),
                    )
                    // Eager-load orderItemToppings so existingMergeKey
                    // doesn't fire one query per merge candidate.
                    ->with('orderItemToppings:id,customer_order_item_id,topping_group_item_id,product_sku_id,quantity')
                    ->lockForUpdate()
                    ->get()
                    ->first(fn (CustomerOrderItem $candidate) => $this->existingMergeKey($candidate) === $mergeKey);

                if ($existing !== null) {
                    $previousQuantity = (float) $existing->quantity;
                    $newQuantity = $previousQuantity + (float) $quantity;
                    $existing->update([
                        'quantity' => $newQuantity,
                        'subtotal' => $newQuantity * ($unitPrice + $toppingSubtotal),
                    ]);
                    $mergedFresh = $existing->fresh();
                    $touchedItems[] = $mergedFresh;
                    $stockHookEntries[] = ['item' => $mergedFresh, 'previous_quantity' => $previousQuantity];

                    continue;
                }

                $itemData['unit_price'] = $unitPrice;
                $itemData['topping_subtotal'] = $toppingSubtotal;
                $itemData['subtotal'] = $quantity * ($unitPrice + $toppingSubtotal);
                $itemData['status'] = $defaultItemStatus;
                $itemData['served_at'] = $defaultItemStatus === $servedStatus ? now() : null;
                // Plan-019 — promotion snapshot fields: id/snapshot stay null
                // when no promotion matched. #2617 — original_unit_price is
                // stamped on EVERY line: the strikethrough when a promotion
                // lowered the price, the unit_price itself when nothing did.
                $itemData['original_unit_price'] = $this->bornLineOriginalUnitPrice($unitPrice, $originalUnitPrice);
                // #2618 — nguồn giá đã chốt ở khối precedence bên trên, đóng
                // dấu cùng lượt INSERT với các snapshot tiền còn lại.
                $itemData['price_source'] = $priceSource;
                $itemData['applied_promotion_id'] = $appliedPromotion?->id;
                $itemData['applied_promotion_snapshot'] = $appliedPromotionSnapshot;

                // plan-043 §7 — resolve + snapshot the tax type and rate onto
                // the line at add time. tax_amount is left at its 0 default;
                // the pricing engine (recalculateTotals) stamps the per-group
                // amounts (§8 step 4).
                // #1218 — menu + section come from $menuProductId, the same row
                // the line's price came from (explicit reference or the
                // lowest-price fallback), so the rate can never belong to a
                // different menu than the price does.
                // #1420 — and so does the tier-1 override: `$menuTaxTypeId` is set
                // only on the explicit branch, so without this coalesce the
                // fallback billed a 10%-overridden line at its 8% menu's rate.
                // Same row either way, so the explicit branch is unaffected.
                $menuContext = $this->menuLines()->taxContextForMenuProduct($menuProductId);

                $taxResolution = $taxBatch->resolveForLine(
                    (string) $sku->productId,
                    $sku->productTaxTypeId,
                    $menuTaxTypeId ?? $menuContext->taxTypeId,
                    $order->branch_id,
                    $order->brand_id,
                    $menuContext->menuId,
                    $menuContext->menuSectionId,
                );
                $itemData['tax_type_id'] = $taxResolution->taxTypeId;
                $itemData['tax_rate'] = $taxResolution->rate;

                /** @var CustomerOrderItem $newItem */
                $newItem = $order->items()->create($itemData);

                // Persist OrderItemTopping rows: one per chosen
                // (ToppingGroupItem, ProductSku) tuple, snapshotting full
                // unit_price (free_up_to_n discount lives at line level).
                // #2619 — waived_quantity records how many of THIS row's units
                // the group's free_up_to_n waived, so the money that left
                // topping_subtotal stays attributable per row.
                foreach ($toppingRows as $row) {
                    OrderItemTopping::create([
                        'customer_order_item_id' => $newItem->id,
                        'topping_group_item_id' => $row['topping_group_item_id'],
                        'product_sku_id' => $row['product_sku_id'],
                        'quantity' => $row['quantity'],
                        'unit_price' => $row['unit_price'],
                        'waived_quantity' => $row['waived_quantity'],
                        'note' => $row['note'] ?? null,
                    ]);
                }

                $touchedItems[] = $newItem;
                $stockHookEntries[] = ['item' => $newItem, 'previous_quantity' => null];
            }

            // #1715 — 409 nếu bất kỳ dòng nào server tính CAO hơn cái client đang
            // hiển thị. Ném ở đây, sau khi cả lô đã định giá, nên thân lỗi liệt kê
            // đủ mọi dòng lệch. Nằm trong transaction ⇒ rollback sạch, không đơn nào
            // sinh ra; và nằm TRƯỚC recalculateTotals + hook trừ kho nên không có
            // tác dụng phụ nào kịp chạy.
            $priceDrift->assertNoDrift();

            $this->recalculateTotals($order);

            // plan-051 — add-time deduction hook (on_add, and the
            // born-at-status half of on_preparing). After totals so a hook
            // failure can never leave the bill stale; ring-fenced inside.
            $this->applyStockDeductionAfterAdd($order, $stockHookEntries);

            // plan-034 — fan out to any other devices in the same dine-in
            // session so their cart UI refreshes within < 1s. No-op for
            // orders without a session (legacy + takeaway).
            if ($order->table_session_id) {
                event(new OrderItemAdded($order));
            }

            return $touchedItems;
        });
    }

    /**
     * Validate topping selections against the parent product's
     * ProductToppingGroup attachments + compute the per-unit topping
     * subtotal via ToppingPricingService.
     *
     * Throws ValidationException with structured codes documented in
     * plan-015 DESIGN — đã archive rồi xoá khỏi cây #2188, xem git history (toppings_below_min, toppings_above_max,
     * topping_qty_above_max, topping_group_not_attached,
     * topping_item_inactive, topping_item_no_price).
     *
     * @param  array<int, array{topping_group_item_id: string, product_sku_id: string, quantity: int, note?: string|null}>  $toppings
     */
    private function validateAndPriceToppings(string $productSkuId, array $toppings, ?string $menuProductId = null, ?string $floatingSectionProductId = null): PricedToppingSelection
    {
        // Extracted to ToppingSelectionPricer (plan-047 T2.12, #1090) so the
        // typed pricing resolver and this legacy path share ONE implementation.
        // #962 · 7a-8 — class đó nay sống ở Catalog và tới được qua cổng.
        return $this->toppingSelectionPricing()->priceForSku($productSkuId, $toppings, $menuProductId, $floatingSectionProductId);
    }

    /**
     * Build a deterministic merge key from a topping payload — order-independent
     * sorted tuple of `(topping_group_item_id, product_sku_id, quantity)`.
     * Two add-item requests merge ONLY when this key matches (BR-OI06).
     *
     * @param  array<int, array{topping_group_item_id: string, product_sku_id: string, quantity: int}>  $toppings
     */
    private function toppingMergeKey(array $toppings): string
    {
        $tuples = array_map(
            static fn (array $t) => sprintf(
                '%s|%s|%d',
                (string) $t['topping_group_item_id'],
                (string) $t['product_sku_id'],
                (int) $t['quantity'],
            ),
            $toppings,
        );
        sort($tuples);

        return implode('::', $tuples);
    }

    /**
     * Build the merge key from an existing CustomerOrderItem's persisted
     * OrderItemTopping rows so it can be compared to the incoming payload.
     */
    private function existingMergeKey(CustomerOrderItem $item): string
    {
        // Caller eager-loads `orderItemToppings` on the merge-candidate
        // query — fall through to a fresh query only if the relation
        // wasn't preloaded (e.g. unit-test path).
        $relation = $item->relationLoaded('orderItemToppings')
            ? $item->orderItemToppings
            : $item->orderItemToppings()->get();

        $rows = $relation
            ->map(fn (OrderItemTopping $r) => [
                'topping_group_item_id' => (string) $r->topping_group_item_id,
                'product_sku_id' => (string) $r->product_sku_id,
                'quantity' => (int) $r->quantity,
            ])
            ->all();

        return $this->toppingMergeKey($rows);
    }

    public function updateItem(CustomerOrder $order, string $itemId, array $data): CustomerOrderItem
    {
        $item = $order->items()->where('id', $itemId)->firstOrFail();

        // Decision 2026-07-27 (#1148, overrides the #1140 in-place swap): a
        // line's SKU is IMMUTABLE. A different variant is a different physical
        // dish — the only honest mutation is void (with reason) + add, which
        // keeps the kitchen trail, the recipe/stock genealogy of the old SKU,
        // and the audit history intact. In-place swap rewrote all three.
        if (
            (array_key_exists('product_sku_id', $data) && (string) $data['product_sku_id'] !== (string) $item->product_sku_id)
            || array_key_exists('menu_product_sku_id', $data)
        ) {
            abort(409, 'Item SKU cannot be edited in place. Void the line and add a new item instead.');
        }
        unset($data['product_sku_id'], $data['menu_product_sku_id']);

        $currentItemStatus = $item->status instanceof OrderItemStatusEnum
            ? $item->status
            : OrderItemStatusEnum::from($item->status);

        // Plan 016 — toppings edit follows the same pending-only rule as
        // qty / note. Once the kitchen has the line (preparing+), the only
        // safe path is void+re-add. `array_key_exists` (not isset) for
        // note + toppings so the client can explicitly send `null` /
        // `[]` to clear them on a pending line.
        if (
            isset($data['quantity'])
            || array_key_exists('note', $data)
            || array_key_exists('toppings', $data)
        ) {
            // #1148 tightened (2026-07-27): once the kitchen owns the line
            // (preparing+), it cannot be EDITED at all — not even with
            // allow_item_edit_any_status. The dish physically exists; the only
            // honest mutations are void-with-reason + add. The flag now
            // governs VOIDING non-pending lines only (see voidItem).
            if ($currentItemStatus !== OrderItemStatusEnum::Pending) {
                abort(409, 'Can only change quantity/note/toppings while the item is pending. Void the line (with a reason) and add a new item instead.');
            }
            // plan-037 follow-up — also allow edits while the order is
            // still awaiting customer confirmation; the BE / kiosk haven't
            // seen the line yet so qty / note changes are safe. `confirmed`
            // (counter-pay takeaway submitted from customer-web) is editable
            // too: staff adjusts the cart at the counter before taking
            // payment, mirroring addItems which already accepts it.
            $this->assertStatus($order, [
                CustomerOrderStatusEnum::AwaitingConfirmation,
                CustomerOrderStatusEnum::Confirmed,
                CustomerOrderStatusEnum::Open,
            ], 'update item');
        }

        // Status transitions
        if (isset($data['status'])) {
            $newStatus = OrderItemStatusEnum::from($data['status']);
            $this->validateItemTransition($currentItemStatus, $newStatus);
        }

        $previousStatus = $currentItemStatus->value;
        $idempotencyKey = $data['_idempotency_key'] ?? Str::uuid()->toString();
        $statusChanged = isset($data['status']);
        unset($data['_idempotency_key']);

        $result = DB::transaction(function () use ($item, $order, $data) {
            // Plan 016 (#615) — TOCTOU guard. The pending-status gate above
            // runs on a non-locked read BEFORE this transaction opens, so a
            // concurrent KDS/workstation fire (pending→preparing) can slip in
            // between the gate and the mutations below. Re-lock the row with
            // SELECT … FOR UPDATE and re-assert Pending here so an edit can
            // never mutate toppings / recompute the bill on a line the kitchen
            // already owns. Only for the qty/note/topping edit paths — a pure
            // status transition is validated separately.
            if (
                isset($data['quantity'])
                || array_key_exists('note', $data)
                || array_key_exists('toppings', $data)
            ) {
                $item = $order->items()
                    ->whereKey($item->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedStatus = $item->status instanceof OrderItemStatusEnum
                    ? $item->status
                    : OrderItemStatusEnum::from($item->status);

                if ($lockedStatus !== OrderItemStatusEnum::Pending) {
                    abort(409, 'Can only change quantity/note/toppings while the item is pending. Void the line (with a reason) and add a new item instead.');
                }
            }

            $updateData = [];
            $effectiveUnitPrice = (float) $item->unit_price;
            // #962 · 7a-8 — qua cổng; `requireSku` giữ nguyên `findOrFail` (404).
            $selectedSku = $this->catalogAnchors()->requireSku((string) $item->product_sku_id);

            // Plan 016 — atomic-replace toppings BEFORE computing subtotal so
            // the new topping_subtotal is what `quantity × (unit + topping)`
            // sees. `array_key_exists` (not `isset`) so an explicit `null` /
            // empty `[]` payload from the client clears all toppings.
            $newToppingSubtotal = null;
            if (array_key_exists('toppings', $data)) {
                $toppings = $data['toppings'] ?? [];
                // OrderItem stores no menu_product_id, so resolve it the same
                // way the add path's fallback does — the lowest-price active
                // menu line for this SKU on the order's branch — so the shop
                // topping tier prices against the same menu line.
                $menuProductId = $this->catalogAnchors()->cheapestActiveMenuLine(
                    (string) $order->branch_id,
                    $selectedSku->skuId,
                )?->menuProductId;
                $toppingResult = $this->validateAndPriceToppings(
                    $selectedSku->skuId,
                    is_array($toppings) ? $toppings : [],
                    $menuProductId,
                );
                // Line-level floor: a discount topping can zero the line but
                // never drive (unit_price + topping_subtotal) below zero.
                $newToppingSubtotal = max($toppingResult->subtotal, -$effectiveUnitPrice);

                // Wipe existing snapshots — append-only contract on
                // OrderItemTopping doesn't apply when staff is explicitly
                // editing on a pending line. order_item_toppings is hard-
                // delete by schema (no soft-delete column), so this is final.
                OrderItemTopping::where('customer_order_item_id', $item->id)->delete();

                // Re-snapshot at fresh prices (Decision: edit takes current
                // extra_price, not the price frozen at addItem time).
                // #2619 — the fresh pricing run re-attributes free_up_to_n
                // per row too; the replaced snapshots carry the new waived
                // counts, matching the recomputed topping_subtotal.
                foreach ($toppingResult->rows as $row) {
                    OrderItemTopping::create([
                        'customer_order_item_id' => $item->id,
                        'topping_group_item_id' => $row['topping_group_item_id'],
                        'product_sku_id' => $row['product_sku_id'],
                        'quantity' => $row['quantity'],
                        'unit_price' => $row['unit_price'],
                        'waived_quantity' => $row['waived_quantity'],
                        'note' => $row['note'] ?? null,
                    ]);
                }

                $updateData['topping_subtotal'] = $newToppingSubtotal;

                // Re-resolve the line's rate from its snapshot tax TYPE when
                // toppings changed. (The 酒類 escalation this used to mention
                // was removed with #1099 — toppings no longer move a line's
                // rate; this simply refreshes rate from the tier walk.)
                // #962 · 7a-8 — `productResolved` là bản scalar của `$product !== null`
                // cũ: cột `product_id` có thể còn giá trị trong khi QUAN HỆ trả null
                // (sản phẩm đã xoá mềm). Rẽ nhánh trên cột thay vì quan hệ sẽ đóng
                // một tỉ lệ MỚI lên dòng đơn của sản phẩm đã bị gỡ khỏi catalog.
                if ($selectedSku->productResolved) {
                    // #1218 — this path only refreshes the rate after a topping
                    // change and the line usually carries its own snapshot type,
                    // so the walk stops at tier 1 and what follows never applies.
                    // Context is supplied anyway for the line whose snapshot type
                    // is null, so it inherits from its menu rather than skipping
                    // to the product. Derived from the product, not the line:
                    // `customer_order_items` has no menu_product_id column, which
                    // is the same reason the sibling re-stamp path above resolves
                    // it this way.
                    //
                    // #962 · 7a-7 — cổng trả CẢ override tầng 1 của dòng menu, và
                    // ở đây ta cố ý KHÔNG dùng nó: tầng 1 của lối này là type đã
                    // đóng dấu trên chính dòng đơn (`$item->tax_type_id`), giữ
                    // nguyên như trước. Chỉ menu + section được mượn từ dòng menu.
                    $menuContext = $this->menuLines()->taxContextForBranchProduct(
                        (string) $order->branch_id,
                        (string) $selectedSku->productId,
                    );
                    $resolution = $this->lineTaxPricing()->beginBatch()->resolveForLine(
                        (string) $selectedSku->productId,
                        $selectedSku->productTaxTypeId,
                        $item->tax_type_id,
                        $order->branch_id,
                        $order->brand_id,
                        $menuContext->menuId,
                        $menuContext->menuSectionId,
                    );
                    $updateData['tax_type_id'] = $resolution->taxTypeId;
                    $updateData['tax_rate'] = $resolution->rate;
                }
            }

            $effectiveToppingSubtotal = $newToppingSubtotal !== null
                ? (float) $newToppingSubtotal
                : (float) $item->topping_subtotal;

            if (isset($data['quantity'])) {
                $updateData['quantity'] = $data['quantity'];
                // BR-OI02 — include topping_subtotal so the line row stays
                // consistent before recalculateTotals overwrites the rollup.
                $updateData['subtotal'] = $data['quantity'] * ($effectiveUnitPrice + $effectiveToppingSubtotal);
            } elseif (array_key_exists('toppings', $data)) {
                // Quantity unchanged but topping_subtotal moved — recompute
                // line subtotal so the total reflects the new topping cost.
                $updateData['subtotal'] = (float) $item->quantity * ($effectiveUnitPrice + $effectiveToppingSubtotal);
            }

            // `array_key_exists` so an explicit `null` from the client
            // clears the note on the line (Plan 016 — staff might want
            // to drop a kitchen request that's no longer relevant).
            if (array_key_exists('note', $data)) {
                $updateData['note'] = $data['note'];
            }

            if (isset($data['status'])) {
                $updateData['status'] = $data['status'];

                if ($data['status'] === OrderItemStatusEnum::Served->value) {
                    $updateData['served_at'] = now();
                }
            }

            // plan-051 — capture pre-mutation state for the stock hooks below.
            $stockPreviousQuantity = (float) $item->quantity;
            $stockPreviousStatus = $this->itemStatusValue($item);

            $item->update($updateData);

            if (isset($data['quantity']) || array_key_exists('toppings', $data)) {
                $this->recalculateTotals($order);
            }

            // plan-051 stock hooks (T2.2 + T2.3). Ring-fenced: the service
            // opens its own nested transaction (savepoint), so an inventory
            // failure rolls back only the stock writes — never the item edit.
            try {
                $freshItem = $item->fresh();

                // T2.2 — qty Revise on an ALREADY-deducted pending line
                // (on_add timing) → delta adjust (extra deduction / partial
                // compensation). Keyed on the marker, not the current timing
                // setting, so a mid-day flip keeps deducted lines consistent.
                if (isset($data['quantity'])
                    && $freshItem->stock_deducted_at !== null
                    && abs((float) $freshItem->quantity - $stockPreviousQuantity) > 1e-9
                ) {
                    $this->stockDeduction()->adjustDeductedLineQuantity((string) $freshItem->id, $stockPreviousQuantity);
                }

                // T2.3 — on_preparing: the line transitions THROUGH the
                // trigger (from below preparing to at/past it — pending →
                // preparing/ready/served all cross it). Every surface funnels
                // here: KDS gen-1/gen-2 bumps, the workstation sync-UP bump
                // (bumpKitchenItemStatus) and typed advance/revert commands.
                if (isset($data['status'])
                    && $freshItem->stock_deducted_at === null
                    && ! $this->stockDeduction()->hasReachedPreparing($stockPreviousStatus)
                    && $this->stockDeduction()->hasReachedPreparing((string) $data['status'])
                    && $this->stockDeduction()->timingForBranch((string) $order->branch_id) === StockDeductionTimingEnum::OnPreparing
                ) {
                    $this->stockDeduction()->deductLine((string) $freshItem->id, 'on_preparing');
                }
            } catch (\Throwable $e) {
                Log::error('[inventory.stock_drift] plan-051: item-update stock hook failed — item mutation preserved', [
                    'order_id' => $order->id,
                    'item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $item->fresh();
        });

        if ($statusChanged && $data['status'] !== $previousStatus) {
            event(new OrderItemStatusChanged(
                $order->refresh(),
                $item->refresh(),
                $previousStatus,
                $idempotencyKey,
            ));
        }

        return $result;
    }

    /**
     * Ids of the lines an ORDER-level bulk void is about to void that already
     * deducted stock (#1283).
     *
     * MUST be called BEFORE the bulk `update()`: afterwards every line reads
     * Voided, and the ones voided earlier — already compensated by voidItem —
     * become indistinguishable from the ones this operation just voided. Double
     * compensation would put the material back twice.
     */
    private function deductedLineIdsAboutToBeVoided(CustomerOrder $order): array
    {
        return $order->items()
            ->where('status', '!=', OrderItemStatusEnum::Voided->value)
            ->whereNotNull('stock_deducted_at')
            ->pluck('id')
            ->all();
    }

    /**
     * plan-051 stock compensation for the ORDER-level void paths (#1283).
     *
     * The item-level voids run the truth table per line; the three bulk paths
     * (`voidOrder`, `continueTableOrder`, `expireOverdueTakeaway`) update every
     * line in one statement and so ran no compensation at all. Harmless under
     * the default `on_close` timing — nothing is deducted before close — but a
     * shop set to `on_add`/`on_preparing` lost the material permanently, with
     * no log and no audit row, and the #1257 repair sweep could not see it
     * either (it resolves `void_reason_id`, which these paths never wrote).
     *
     * Ring-fenced per line, exactly as voidItem does: the compensation nests
     * its own transaction, so an inventory failure rolls back the compensation
     * only — the void itself stands.
     *
     * @param  list<string>  $itemIds  From deductedLineIdsAboutToBeVoided().
     */
    private function compensateBulkVoidedLines(CustomerOrder $order, ?VoidReason $voidReason, array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }

        foreach (CustomerOrderItem::query()->whereIn('id', $itemIds)->get() as $item) {
            try {
                $this->stockDeduction()->compensateVoid((string) $item->id, $voidReason?->id);
            } catch (\Throwable $e) {
                Log::error('[inventory.stock_drift] #1283: order-level void stock compensation failed — void preserved', [
                    'order_id' => $order->id,
                    'item_id' => $item->getKey(),
                    'void_reason_id' => $voidReason?->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Resolve an optional structured void reason for an ORDER-level void.
     *
     * Same contract as the item-level lookup: the row must belong to the
     * order's brand and still be active, or it is a hard 422 — a stale or
     * foreign id must never be able to drive a stock effect.
     */
    private function resolveOrderVoidReason(CustomerOrder $order, array $data): ?VoidReason
    {
        if (empty($data['void_reason_id'])) {
            return null;
        }

        $voidReason = VoidReason::query()
            ->whereKey($data['void_reason_id'])
            ->where('brand_id', $order->brand_id)
            ->where('is_active', true)
            ->first();

        if ($voidReason === null) {
            abort(response()->json([
                'message' => 'void_reason_id does not resolve to an active void reason of this brand.',
                'code' => 'VOID_REASON_INVALID',
            ], 422));
        }

        return $voidReason;
    }

    /**
     * #2173 — một khoản HOÀN đã phát hành không được xoá dấu vết.
     *
     * Void một dòng hoàn khiến chứng từ vừa nói khách đã được trả lại tiền, vừa
     * nói không: dòng âm biến khỏi tổng đơn (đơn thu lại khoản đã trả), nhưng
     * `refunded_quantity` trên dòng gốc **chỉ được CỘNG, không bao giờ trừ**
     * (`refundItem`, chỗ ghi duy nhất) nên hạn mức hoàn còn lại vẫn bị trừ. Hai
     * sổ nói hai chuyện khác nhau và không có gì đối chiếu chúng.
     *
     * Nguyên tắc kế toán: đảo một bút toán đảo là một **giao dịch mới**, không
     * phải xoá bút toán cũ. Cùng luật mà #1148 đã áp cho món đã nấu (void có lý
     * do + thêm mới, không sửa tại chỗ), và đối xứng với
     * `CANNOT_REFUND_REFUND_LINE` vốn đã cấm hoàn một dòng hoàn.
     *
     * Chặn ở đây chứ không dựa vào ma trận `item_voidable_statuses`: dòng hoàn
     * mang `status = served`, nên một shop bật `served` là mở luôn đường này —
     * và đường replay của máy trạm thì **không đi qua ma trận đó chút nào**.
     */
    private function assertNotRefundLine(CustomerOrderItem $item): void
    {
        if ($item->refund_of_item_id === null || $item->refund_of_item_id === '') {
            return;
        }

        abort(response()->json([
            'message' => 'A refund line cannot be voided. Reverse a refund with a new transaction, never by erasing it.',
            'code' => 'CANNOT_VOID_REFUND_LINE',
            'item_id' => $item->id,
            'refund_of_item_id' => $item->refund_of_item_id,
        ], 409));
    }

    /**
     * #2200 — mặt đối xứng của {@see assertNotRefundLine}: #2173 cấm xoá BÚT
     * TOÁN ĐẢO, nhưng void dòng GỐC mà bút toán đảo đang trỏ vào cũng xoá dấu
     * vết y hệt — `rateSubtotalsForOrder` bỏ dòng gốc đã void trong khi
     * `applyRefundLines` vẫn gộp dòng hoàn đang sống, ra tổng ÂM (đơn 3 × ¥100
     * đã hoàn 1: void gốc ⇒ total −110, đơn khẳng định quán nợ ngược khách).
     *
     * Đường đúng cho "muốn bỏ nốt món này" là HOÀN phần còn lại — bút toán
     * đảo mới, không phải xoá bút toán gốc.
     */
    private function assertNotRefundedOrigin(CustomerOrderItem $item): void
    {
        if ((float) $item->refunded_quantity <= 0) {
            return;
        }

        abort(response()->json([
            'message' => 'This line has an issued refund pointing at it. Refund the remaining quantity instead of voiding the original entry.',
            'code' => 'CANNOT_VOID_REFUNDED_ORIGIN',
            'item_id' => $item->id,
            'refunded_quantity' => (float) $item->refunded_quantity,
        ], 409));
    }

    public function voidItem(CustomerOrder $order, string $itemId, array $data): CustomerOrderItem
    {
        // plan-037 follow-up — awaiting_confirmation can void too; the
        // customer is just trimming their order before committing. Same for
        // `confirmed` (counter-pay takeaway): staff trims the cart at the
        // counter before payment, mirroring addItems/updateItem.
        $this->assertStatus($order, [
            CustomerOrderStatusEnum::AwaitingConfirmation,
            CustomerOrderStatusEnum::Confirmed,
            CustomerOrderStatusEnum::Open,
        ], 'void item');

        $item = $order->items()->where('id', $itemId)->firstOrFail();

        // #2173 — TRƯỚC ma trận trạng thái: dòng hoàn không bao giờ void được,
        // bất kể shop cấu hình `item_voidable_statuses` thế nào.
        $this->assertNotRefundLine($item);
        // #2200 — và dòng GỐC của một khoản hoàn đã phát hành cũng vậy.
        $this->assertNotRefundedOrigin($item);

        $currentItemStatus = $item->status instanceof OrderItemStatusEnum
            ? $item->status
            : OrderItemStatusEnum::from($item->status);

        // plan-051 (#1149) — the per-status void matrix replaces the blanket
        // allow_item_edit_any_status gate. VoidableStatusResolver keeps the
        // legacy semantics when `item_voidable_statuses` is unset (flag true →
        // all four active statuses, false → pending-only), so no backfill is
        // needed at deploy.
        $setting = ShopOrderSetting::query()
            ->where('branch_id', $order->branch_id)
            ->first();
        $voidableStatuses = VoidableStatusResolver::resolve($setting);

        if (! in_array($currentItemStatus->value, $voidableStatuses, true)) {
            abort(response()->json([
                'message' => "Items in status '{$currentItemStatus->value}' cannot be voided on this shop. Allowed: ".implode(', ', $voidableStatuses).'.',
                'code' => 'ITEM_STATUS_NOT_VOIDABLE',
                'item_status' => $currentItemStatus->value,
                'voidable_statuses' => $voidableStatuses,
            ], 409));
        }

        // plan-051 — optional structured reason from the VoidReason master.
        // Must belong to the order's brand and still be active; anything else
        // is a hard 422 so a stale/foreign id can never drive a stock effect.
        $voidReason = null;
        if (! empty($data['void_reason_id'])) {
            $voidReason = VoidReason::query()
                ->whereKey($data['void_reason_id'])
                ->where('brand_id', $order->brand_id)
                ->where('is_active', true)
                ->first();

            if ($voidReason === null) {
                abort(response()->json([
                    'message' => 'void_reason_id does not resolve to an active void reason of this brand.',
                    'code' => 'VOID_REASON_INVALID',
                ], 422));
            }
        }

        $note = trim((string) ($data['void_reason'] ?? ''));

        // requires_note reasons demand an operator-entered free-text note on
        // top of the picked reason (e.g. "Khách đổi món" — which dish?).
        if ($voidReason !== null && (bool) $voidReason->requires_note && $note === '') {
            abort(response()->json([
                'message' => 'This void reason requires a note.',
                'code' => 'VOID_NOTE_REQUIRED',
            ], 422));
        }

        if ($currentItemStatus !== OrderItemStatusEnum::Pending && $voidReason === null) {
            // #1148 — the dish was already made: the void MUST carry a real,
            // operator-entered reason for the audit/stock trail. A VoidReason
            // row satisfies this; otherwise the junk defaults the reasonless
            // endpoints fall back to don't count.
            if ($note === '' || in_array($note, ['Removed by staff', 'voided_by_workstation'], true)) {
                abort(422, 'A reason is required when voiding an item that has already been prepared.');
            }
        }

        // History self-contained: the text column always carries the human-
        // readable story — the picked reason's label (snapshotted, so a later
        // relabel/deactivate never rewrites history), plus the free note.
        if ($voidReason !== null) {
            $label = $voidReason->localizedLabel() ?? (string) $voidReason->id;
            $reasonText = ($note !== '' && $note !== $label) ? "{$label}: {$note}" : $label;
        } else {
            $reasonText = $data['void_reason'] ?? null;
        }

        return DB::transaction(function () use ($item, $order, $voidReason, $reasonText) {
            $item->update([
                'status' => OrderItemStatusEnum::Voided->value,
                'voided_at' => now(),
                'void_reason' => $reasonText,
                'void_reason_id' => $voidReason?->id,
            ]);

            $this->recalculateTotals($order);

            $item->logAudit('item_voided', [
                'void_reason' => $reasonText,
                'void_reason_id' => $voidReason?->id,
            ]);

            // plan-051 T2.4 — stock compensation per the truth table (no-op
            // for lines that were never deducted). Ring-fenced: the service
            // nests its own transaction, so an inventory failure rolls back
            // only the compensation — the void itself stands.
            try {
                $this->stockDeduction()->compensateVoid((string) $item->id, $voidReason?->id);
            } catch (\Throwable $e) {
                Log::error('[inventory.stock_drift] plan-051: void stock compensation failed — void preserved', [
                    'order_id' => $order->id,
                    'item_id' => $item->id,
                    'void_reason_id' => $voidReason?->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // #559 — voiding the LAST active item empties the order. An empty
            // Open order would otherwise strand its table as "occupied"
            // forever: the cashier can neither checkout (422 "no active items")
            // nor see the table freed. Treat it like a full void — flip the
            // order to Voided, release its tables, and broadcast so every
            // terminal re-renders. Scoped to Open orders; awaiting_confirmation
            // carts (customer-web, no table held) keep the plain per-item trim.
            if ($this->resolveStatus($order) === CustomerOrderStatusEnum::Open) {
                $remainingActive = $order->items()
                    ->where('status', '!=', OrderItemStatusEnum::Voided->value)
                    ->count();

                if ($remainingActive === 0) {
                    // BR-COUP07 (#1276) — voiding the last line voids the order,
                    // so the coupon has to come back here too. Before the flip:
                    // releaseIfApplied() can only mutate while the order is
                    // still in a modifiable state.
                    $this->couponService()->releaseIfApplied($order);

                    $order->update([
                        'status' => CustomerOrderStatusEnum::Voided->value,
                        'voided_at' => now(),
                        'void_reason' => $reasonText,
                    ]);

                    $releasedTableIds = $this->releaseOrderTables($order);
                    $order->logAudit('voided_empty');

                    event(new OrderVoided($this->reloadOrder($order->id), $releasedTableIds));
                }
            }

            return $item->fresh();
        });
    }

    public function removeItem(CustomerOrder $order, string $itemId): void
    {
        $this->voidItem($order, $itemId, ['void_reason' => 'Removed by staff']);
    }

    /**
     * plan-045 — refund N units of a line by APPENDING a negative-value order
     * item (never mutating the original, Stripe reversal model). The refund line
     * copies the original's price + tax snapshot negated, links back via
     * refund_of_item_id (carrying product_sku_id so LAN sync never drops it), and
     * bumps the original's refunded_quantity accumulator (guarded ≤ quantity).
     * The engine's applyRefundLines folds the negated tax directly so the reversal
     * is EXACT. Order-side record only — the payment refund (order_payments) is a
     * separate, complementary flow.
     *
     * NOTE (plan-045 DESIGN deviation): the plan named a standalone RefundService;
     * this lives on CustomerOrderService instead so it can reuse the private
     * recalculateTotals + status helpers (a sidecar could not reach them) and to
     * honour the "extend the existing service" convention.
     */
    public function refundItem(CustomerOrder $order, string $itemId, float $quantity, ?string $reason = null, ?string $refundLineId = null): CustomerOrder
    {
        if ($quantity <= 0) {
            throw RefundException::exceedsQuantity($quantity, 0.0);
        }

        $status = $this->resolveStatus($order)?->value ?? (string) $order->status;
        if ($status === CustomerOrderStatusEnum::Voided->value) {
            throw RefundException::orderNotRefundable($status);
        }

        return DB::transaction(function () use ($order, $itemId, $quantity, $reason, $refundLineId) {
            /** @var CustomerOrderItem $item */
            $item = $order->items()->where('id', $itemId)->lockForUpdate()->firstOrFail();

            if ($item->refund_of_item_id !== null) {
                throw RefundException::cannotRefundRefundLine();
            }

            $originalQty = (float) $item->quantity;
            $alreadyRefunded = (float) $item->refunded_quantity;
            if ($alreadyRefunded + $quantity > $originalQty + 1e-9) {
                throw RefundException::exceedsQuantity($quantity, max(0.0, $originalQty - $alreadyRefunded));
            }

            // Negated price snapshot — the quantity carries the sign.
            $unit = (float) $item->unit_price + (float) ($item->topping_subtotal ?? 0);
            $refundSubtotal = -1.0 * $unit * $quantity;

            // Proportional share of the original's GROSS (pre-discount,
            // group-once allocated) tax, rounded with the ORDER's snapshot rule,
            // negated.
            $settings = ShopOrderSetting::where('branch_id', $order->branch_id)
                ->first(['currency_code', 'tax_rounding_decimals', 'tax_rounding_mode']);
            $taxDecimals = $order->tax_rounding_decimals !== null ? (int) $order->tax_rounding_decimals : null;
            $taxStep = RoundingMode::taxStep($taxDecimals, $settings?->currency_code ?? 'VND');
            $taxMode = $order->tax_rounding_mode ?: 'round';
            // #2133 — làm tròn TỔNG LUỸ KẾ rồi lấy HIỆU, không làm tròn từng lần.
            //
            // Bản cũ tính `tax_amount × quantity / originalQty` rồi làm tròn ĐỘC
            // LẬP cho mỗi lần hoàn. Ba lần hoàn 1 món trên dòng qty=3 mang thuế
            // 302: mỗi lần `302 × 1 / 3 = 100,67 → 101`, ba lần = **303**. Quán
            // trả cho khách nhiều hơn đã thu 1 đồng, im lặng.
            //
            // Cùng họ với lỗi làm tròn TỪNG DÒNG mà インボイス cấm ở phía thu và
            // plan-043 đã dọn — chỉ khác là nó nằm trên đường ĐẢO tiền.
            //
            // Cách chữa soi gương phía thu: `allocateGroupTax` làm tròn MỘT lần
            // cho cả nhóm rồi chia xuống, nên đường hoàn cũng phải làm tròn trên
            // một đại lượng LUỸ KẾ. Mỗi lần hoàn nhận đúng phần chênh giữa hai
            // mốc đã làm tròn:
            //
            //   lần 1: round(302×1/3)=101 − round(0)  =0   → −101
            //   lần 2: round(302×2/3)=201 − 101            → −100
            //   lần 3: round(302×3/3)=302 − 201            → −101
            //                                       Σ = −302, khớp đúng đã thu
            //
            // Tính chất: Σ mọi lần hoàn LUÔN bằng `tax_amount` khi hoàn hết, với
            // MỌI cách chia nhỏ — vì mốc cuối là `round(taxTotal)` = chính nó.
            //
            // `abs()` giữ nguyên ở cả hai mốc: primitive half-up bất đối xứng qua
            // 0 (`-100,5 → -100`), nên phải làm tròn trên trị tuyệt đối rồi mới
            // đảo dấu. Ghim ở `RefundReversesTaxExactlyTest` (#2117).
            //
            // #2182 — nền là thuế GỘP của dòng, KHÔNG phải `item.tax_amount`.
            //
            // `item.tax_amount` mang khoản giảm đã pro-rata vào; dùng nó làm nền
            // hoàn là TRỘN HAI MÔ HÌNH, vì phía coupon repo chọn ĐÁNH GIÁ LẠI
            // chứ không phân bổ theo tỉ lệ (#2079/#550/#2114). Trả lại một món
            // thì coupon không đi theo món ấy — nó dồn hết sang phần hàng còn
            // giữ — nên phần phải hoàn là GỘP của món đó cộng thuế trên gộp.
            //
            // Đo trên 2 × ¥1.000 @10% + coupon ¥500, hoàn cả hai:
            //
            //   nền cũ (đã trừ giảm):  −75 rồi −50, Σ −125
            //   phía sống định giá lại KHÔNG còn coupon:      +200
            //                                                 ─────
            //                                     đọng lại      75   ← đơn không
            //                                                        bán gì mà
            //                                                        vẫn khai thuế
            //
            // Nền GỘP cho −100 và −100, triệt tiêu đúng +200 ⇒ 0. Ở bước giữa
            // chừng nó cũng đúng: hoàn một món ⇒ 150 − 100 = 50, khớp phần hàng
            // còn giữ (1.000 − 500 = 500 @10%).
            //
            // KHÔNG có coupon/khoản giảm thì nền gộp trùng đúng `item.tax_amount`
            // (cùng phép phân bổ, khoản giảm 0) — mọi ca không giảm giá giữ
            // nguyên từng đồng, gồm cả ba ca của `RefundReversesTaxExactlyTest`.
            //
            // Ảnh chụp của dòng hoàn vẫn BẤT BIẾN: chỉ cách tính lúc TẠO đổi,
            // không có đường nào viết lại dòng hoàn đã ghi.
            $order->load('items');
            $taxTotal = (float) ($this->allocateLineTaxes(
                $order,
                // Nền GỘP: không áp khoản giảm nào lên phép phân bổ này.
                0.0,
                0.0,
                (bool) $order->is_tax_included,
                $taxStep,
                $taxMode,
            )[(string) $item->id] ?? 0.0);

            // #2180 — phép hiệu-hai-mốc sống ở `RoundingMode::refundTaxDelta` để
            // `RefundTaxGoldenParityTest` quan sát ĐÚNG engine production thay vì
            // một bản cài lại trong file test. Nền GỘP ở trên là của #2182;
            // helper chỉ ôm phần luỹ-kế-rồi-lấy-hiệu, không đổi một phép nào.
            $refundTax = -1.0 * RoundingMode::refundTaxDelta(
                $taxTotal, $alreadyRefunded, $quantity, $originalQty, $taxStep, $taxMode,
            );

            $refundLine = $order->items()->make([
                'product_sku_id' => $item->product_sku_id,
                'quantity' => -1.0 * $quantity,
                'unit_price' => $item->unit_price,
                // #2617 — copy nguyên, không tính lại: dòng hoàn là ảnh gương
                // giá của dòng gốc, kể cả dấu vết định hình giá của nó.
                'original_unit_price' => $item->original_unit_price,
                // #2618 — nguồn giá cũng là dấu vết định hình giá: copy nguyên.
                'price_source' => $item->price_source,
                'topping_subtotal' => $item->topping_subtotal,
                'subtotal' => $refundSubtotal,
                'tax_type_id' => $item->tax_type_id,
                'tax_rate' => $item->tax_rate,
                'tax_amount' => $refundTax,
                'refund_of_item_id' => $item->id,
                'status' => OrderItemStatusEnum::Served->value,
                'note' => $reason,
            ]);
            // plan-045 — stamp the workstation's local refund-line UUID as the id
            // when synced UP (set before save so HasUuids won't override it), so a
            // lost-response re-drain finds this row (durable idempotency) instead
            // of appending a duplicate refund. Locally-created refunds keep a
            // fresh auto-UUID.
            if ($refundLineId) {
                $refundLine->id = $refundLineId;
            }
            $refundLine->save();

            $item->update(['refunded_quantity' => $alreadyRefunded + $quantity]);

            // plan-045 — append-only refund condition (never regenerated). Amount
            // is the negated GROSS refunded (excluded: subtotal + tax; included:
            // subtotal already gross). meta links the original + carries the tax.
            $refundGross = (bool) $order->is_tax_included ? $refundSubtotal : $refundSubtotal + $refundTax;
            $refundLine->conditions()->create([
                'type' => 'refund',
                'source' => 'manual',
                'label' => $reason ?: 'Refund',
                'rate' => $item->tax_rate,
                'amount' => $refundGross,
                'currency_code' => $settings?->currency_code ?? 'VND',
                'meta' => [
                    'refund_of_item_id' => $item->id,
                    'quantity' => $quantity,
                    'tax' => $refundTax,
                ],
            ]);

            $order->load('items');
            $this->recalculateTotals($order);

            $order->logAudit('order_item.refunded', [
                'item_id' => $item->id,
                'refund_line_id' => $refundLine->id,
                'quantity' => $quantity,
                'refund_tax' => $refundTax,
                'reason' => $reason,
            ]);

            return $this->reloadOrder($order->id);
        });
    }

    // =========================================================================
    //  Table Merging
    // =========================================================================

    public function mergeTable(CustomerOrder $order, string $tableId): CustomerOrder
    {
        $this->assertStatus($order, [CustomerOrderStatusEnum::Open], 'merge table');

        return DB::transaction(function () use ($order, $tableId) {
            // Scope to the order's branch so a caller cannot merge a table
            // belonging to another tenant's branch (cross-org isolation).
            $table = $this->tables()->lockInBranch($order->branch_id, $tableId);

            if ($table->isHeld()) {
                abort(409, 'Table is already occupied by another order.');
            }

            $tableStatus = TableStatusEnum::tryFrom((string) $table->status);

            if (! in_array($tableStatus, [TableStatusEnum::Free, TableStatusEnum::Reserved], true)) {
                abort(422, 'Table must be free or reserved to merge.');
            }

            $this->tables()->assign([$tableId], $order->id);

            if (! $order->table_id) {
                // table_id is guarded — forceFill required (update() no-ops).
                $order->forceFill(['table_id' => $tableId])->save();
            }

            $order->logAudit('table_merged', ['table_id' => $tableId]);

            return $this->reloadOrder($order->id);
        });
    }

    public function unmergeTable(CustomerOrder $order, string $tableId): CustomerOrder
    {
        $this->assertStatus($order, [CustomerOrderStatusEnum::Open], 'unmerge table');

        return DB::transaction(function () use ($order, $tableId) {
            $this->tables()->lockHeldByOrder($tableId, $order->id);

            // Cannot unmerge last table for dine_in
            $tableCount = $this->tables()->countHeldBy($order->id);
            $orderType = $order->order_type instanceof CustomerOrderTypeEnum
                ? $order->order_type
                : CustomerOrderTypeEnum::from($order->order_type);
            if ($tableCount <= 1 && $orderType === CustomerOrderTypeEnum::DineIn) {
                abort(409, 'Cannot unmerge the last table from a dine-in order.');
            }

            $this->tables()->releaseByIds([$tableId], $order->id);

            $order->logAudit('table_unmerged', ['table_id' => $tableId]);

            return $this->reloadOrder($order->id);
        });
    }

    // =========================================================================
    //  Split Bill
    // =========================================================================

    /**
     * @return array{total_amount: string, remaining_amount: string, split_count: int, per_person_amount: string, per_person_amounts: array<string>, rounding_note: string|null}
     */
    public function splitBill(CustomerOrder $order, ?int $splitCount = null): array
    {
        $this->assertStatus($order, [CustomerOrderStatusEnum::Checkout, CustomerOrderStatusEnum::Paying], 'split bill');

        $splitCount = $splitCount ?? $order->guest_count;

        if (! $splitCount || $splitCount < 2) {
            abort(422, 'Split count must be at least 2.');
        }

        $remaining = (float) $order->total_amount - (float) $order->paid_amount;

        if ($remaining <= 0) {
            return [
                'total_amount' => number_format($order->total_amount, 2, '.', ''),
                'remaining_amount' => '0.00',
                'split_count' => $splitCount,
                'per_person_amount' => '0.00',
                'per_person_amounts' => array_fill(0, $splitCount, '0.00'),
                'rounding_note' => null,
            ];
        }

        $baseAmount = floor($remaining / $splitCount);
        $remainder = $remaining - ($baseAmount * $splitCount);

        $amounts = [];
        $roundingNote = null;

        for ($i = 0; $i < $splitCount; $i++) {
            if ($i === 0 && $remainder > 0) {
                $amounts[] = number_format($baseAmount + $remainder, 2, '.', '');
                $roundingNote = 'First person pays extra '.number_format($remainder, 2, '.', '').' due to rounding';
            } else {
                $amounts[] = number_format($baseAmount, 2, '.', '');
            }
        }

        return [
            'total_amount' => number_format($order->total_amount, 2, '.', ''),
            'remaining_amount' => number_format($remaining, 2, '.', ''),
            'split_count' => $splitCount,
            'per_person_amount' => $amounts[0],
            'per_person_amounts' => $amounts,
            'rounding_note' => $roundingNote,
        ];
    }

    // =========================================================================
    //  Split-by-items Preview (plan-033)
    // =========================================================================

    /**
     * Build the preview shape for `GET /split-by-items/preview` on POS,
     * kiosk, and customer-web QR surfaces. Pure read; does not write.
     *
     * @param  array<int, array{item_id: string, units: int, bill_index: int}>|null  $candidateAllocations
     * @return array<string, mixed>
     */
    public function splitByItemsPreview(CustomerOrder $order, ?array $candidateAllocations = null): array
    {
        // Eager-load the product (incl. soft-deleted) + its translations so
        // each line's locale-resolved name works without an N+1 per item and
        // historical names survive a deleted product.
        $order->loadMissing([
            'items.productSku.product' => fn ($q) => $q->withTrashed()->with('translations'),
            'payments',
        ]);

        $setting = ShopOrderSetting::query()
            ->where('branch_id', $order->branch_id)
            ->first();
        $roundingMode = $setting?->split_bill_rounding_mode ?? 'auto';
        $currencyCode = $setting?->currency_code ?? 'JPY'; // #815 — default JPY, khớp charge currency
        $taxRate = 0.0 /* plan-043 T6.2: legacy branch tax_rate dropped */;
        $serviceChargeRate = (float) ($setting?->service_charge_rate ?? 0);

        // Reflect the effective (live for pre-checkout) tax/service/total onto
        // the in-memory order so the calculator's order context and the
        // response header agree with GET /kiosk/orders. Pure read — not saved.
        // Without this, an open order's stored total_amount is the bare
        // subtotal (tax applied only at checkout) while per-bill totals below
        // add tax live, so the bills overshoot the header.
        $pricing = $this->orderPricingCalculator->forOrder($order, $setting);
        $order->setAttribute('total_amount', $pricing->totalAmount);

        // Sum existing claims from non-failed payments' metadata.
        $claimedByItem = [];
        $claimsByItem = [];
        foreach ($order->payments as $payment) {
            $statusValue = is_object($payment->status) && property_exists($payment->status, 'value') ? $payment->status->value : $payment->status;
            if ($statusValue === 'failed') {
                continue;
            }
            $meta = $payment->metadata;
            if (! is_array($meta) || ($meta['split_mode'] ?? null) !== 'by_items') {
                continue;
            }
            foreach ($meta['item_allocations'] ?? [] as $alloc) {
                $iid = (string) ($alloc['item_id'] ?? '');
                $units = (int) ($alloc['units'] ?? 0);
                if ($iid === '' || $units < 1) {
                    continue;
                }
                $claimedByItem[$iid] = ($claimedByItem[$iid] ?? 0) + $units;
                $claimsByItem[$iid][] = [
                    'payment_id' => (string) $payment->id,
                    'bill_index' => (int) ($meta['bill_index'] ?? 0),
                    'units' => $units,
                    'status' => $statusValue,
                ];
            }
        }

        $itemsView = [];
        $totalAllocated = 0.0;
        foreach ($order->items as $item) {
            $statusRaw = $item->status;
            $statusValue = is_object($statusRaw) && property_exists($statusRaw, 'value') ? $statusRaw->value : $statusRaw;
            if ($statusValue === 'voided') {
                continue;
            }
            $iid = (string) $item->id;
            $qty = max(1, (int) $item->quantity);
            $claimed = $claimedByItem[$iid] ?? 0;
            $itemsView[] = [
                'item_id' => $iid,
                'product_sku_id' => (string) $item->product_sku_id,
                // Same resolver as the kiosk order read (SKU name → localized
                // product name), so split rows never show a blank label.
                'name' => $item->menu_item_name ?: '(unknown)',
                // Locale-resolved product name + SKU code, mirroring kiosk shape.
                'product_name' => $item->productSku?->product?->localizedName(),
                'sku_code' => $item->productSku?->sku,
                'quantity' => $qty,
                'units_claimed' => $claimed,
                'units_remaining' => max(0, $qty - $claimed),
                'claims' => $claimsByItem[$iid] ?? [],
            ];
            $totalAllocated += $claimed * (float) $item->unit_price;
        }

        $totalAmount = $pricing->totalAmount;
        $allocatedAmount = min($totalAllocated, $totalAmount);

        $response = [
            'order_id' => (string) $order->id,
            'total_amount' => number_format($totalAmount, 2, '.', ''),
            'allocated_amount' => number_format($allocatedAmount, 2, '.', ''),
            'remaining_amount' => number_format(max(0.0, $totalAmount - $allocatedAmount), 2, '.', ''),
            'rounding_mode' => $roundingMode,
            'rounding_step' => number_format(RoundingMode::step($roundingMode, $currencyCode), 3, '.', ''),
            'currency_code' => $currencyCode,
            'items' => $itemsView,
        ];

        if (is_array($candidateAllocations) && count($candidateAllocations) > 0) {
            // Cap candidate-allocation count to keep response under 4 KB.
            $shaped = [];
            foreach ($candidateAllocations as $a) {
                $shaped[] = [
                    'item_id' => (string) ($a['item_id'] ?? ''),
                    'units' => (int) ($a['units'] ?? 0),
                    'bill_index' => (int) ($a['bill_index'] ?? 0),
                ];
            }
            $peopleCount = 1;
            foreach ($shaped as $a) {
                $peopleCount = max($peopleCount, $a['bill_index'] + 1);
            }

            // Acceptance invariant: when the candidate covers every unit, the
            // per-bill totals must sum to order.total exactly. Enable the
            // calculator's last-bill remainder absorption (largest-remainder)
            // only then — a partial allocation must NOT inflate the last bill
            // with unclaimed items.
            $claimedPerItem = [];
            foreach ($shaped as $a) {
                if ($a['units'] >= 1) {
                    $claimedPerItem[$a['item_id']] = ($claimedPerItem[$a['item_id']] ?? 0) + $a['units'];
                }
            }
            $isFullAllocation = true;
            foreach ($order->items as $item) {
                $statusRaw = $item->status;
                $statusValue = is_object($statusRaw) && property_exists($statusRaw, 'value') ? $statusRaw->value : $statusRaw;
                if ($statusValue === 'voided') {
                    continue;
                }
                if (($claimedPerItem[(string) $item->id] ?? 0) < max(1, (int) $item->quantity)) {
                    $isFullAllocation = false;
                    break;
                }
            }

            $calculatorResult = $this->splitByItemsCalculator->compute(
                $order,
                $shaped,
                $roundingMode,
                $currencyCode,
                $taxRate,
                $serviceChargeRate,
                $peopleCount,
                reconcile: $isFullAllocation,
                pricesIncludeTax: (bool) ($order->is_tax_included ?? false),
            );
            $previewBills = [];
            foreach ($calculatorResult['bills'] as $bill) {
                $previewBills[] = [
                    'bill_index' => (int) $bill['index'],
                    'subtotal' => number_format($bill['subtotal'], 2, '.', ''),
                    'discount' => number_format($bill['discount'], 2, '.', ''),
                    'tax' => number_format($bill['tax'], 2, '.', ''),
                    'service' => number_format($bill['service'], 2, '.', ''),
                    'total' => number_format($bill['total'], 2, '.', ''),
                    'is_empty' => (bool) $bill['is_empty'],
                ];
            }
            $response['preview_bills'] = $previewBills;
        }

        return $response;
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    /**
     * Validate that all tables exist and can be assigned to a new order.
     *
     * A table can be assigned if:
     * - It has no current order (current_order_id is null)
     * - It is in one of these states: free, reserved, OR occupied (customer just sat down)
     * - It cannot be in cleaning or out_of_service state
     *
     * @param  string[]  $tableIds
     */
    private function validateAndAssignTables(array $tableIds): void
    {
        // plan-006 — pessimistic row lock closes a table-assignment TOCTOU race.
        // Both callers (create()/insertOrder and initOrder) run inside a
        // DB::transaction, so `lockForUpdate()` serializes concurrent order
        // creations that target the same table: the losing transaction blocks
        // until the winner commits, then re-reads the now-occupied row and
        // aborts 422 instead of double-booking the table. Without the lock two
        // requests can both read `current_order_id === null` and both assign.
        $tables = $this->tables()->lockAll($tableIds);

        if (count($tables) !== count($tableIds)) {
            abort(422, 'One or more tables do not exist.');
        }

        foreach ($tables as $table) {
            if ($table->isHeld()) {
                abort(422, "Table '{$table->code}' is already occupied by another order.");
            }

            $tableStatus = TableStatusEnum::tryFrom((string) $table->status);

            // Allow free, reserved, OR occupied (customer just occupied but hasn't ordered yet)
            if (! in_array($tableStatus, [TableStatusEnum::Free, TableStatusEnum::Reserved, TableStatusEnum::Occupied], true)) {
                abort(422, "Table '{$table->code}' must be free, reserved, or occupied to assign to an order.");
            }
        }
    }

    /**
     * Allocate the next globally-unique, gapless `ORD-{year}-{NNNN}` code.
     *
     * plan-041 — replaces the old `MAX(SUBSTRING(...))+1` scan with a
     * transactional counter row (`order_code_counters`, one per year). MUST be
     * called inside the order-insert transaction: `lockForUpdate()` serializes
     * concurrent allocators on the counter row, and because the increment and
     * the INSERT share one transaction, a rollback releases the number — so the
     * sequence stays gap-free and never duplicates.
     *
     * The year row is normally seeded by the migration; the runtime
     * `insertOrIgnore` only covers the first order after a year rollover.
     */
    private function nextOrderCode(): string
    {
        $year = (int) now()->year;

        if (! DB::table('order_code_counters')->where('year', $year)->exists()) {
            // The omnify table has a uuid primary key; DB::table bypasses the
            // model's auto-uuid, so generate it here. insertOrIgnore keeps the
            // year-rollover seed race-safe (unique index on year).
            DB::table('order_code_counters')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'year' => $year,
                'next_value' => $this->seedNextValueForYear($year),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $row = DB::table('order_code_counters')->where('year', $year)->lockForUpdate()->first();
        $seq = (int) $row->next_value;
        DB::table('order_code_counters')->where('year', $year)->update([
            'next_value' => $seq + 1,
            'updated_at' => now(),
        ]);

        return sprintf('ORD-%d-%04d', $year, $seq);
    }

    /**
     * #423 — fast-forward the `order_code_counters` row so it can never mint a
     * sequence a caller-supplied code already consumed.
     *
     * When a caller passes an explicit `ORD-{year}-{seq}` (workstation sync UP,
     * seed import) the code skips `nextOrderCode()`, so the counter row is never
     * advanced. Bump the row for the code's OWN year (not necessarily the
     * current year — a late 31 Dec sync can land a prior/next-year code) to
     * `max(next_value, seq + 1)`. MUST run inside the order-insert transaction so
     * the bump and the row insert commit atomically.
     */
    private function reconcileOrderCodeCounter(string $providedCode): void
    {
        if (! preg_match('/^ORD-(\d{4})-(\d{4,})$/', $providedCode, $m)) {
            return;
        }

        $year = (int) $m[1];
        $seq = (int) $m[2];

        // Ensure the year row exists (a code for a year we've never allocated
        // through nextOrderCode() yet). insertOrIgnore keeps the seed race-safe.
        if (! DB::table('order_code_counters')->where('year', $year)->exists()) {
            DB::table('order_code_counters')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'year' => $year,
                'next_value' => $seq + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        // Only move forward — never rewind a counter already ahead of this code.
        DB::table('order_code_counters')
            ->where('year', $year)
            ->where('next_value', '<=', $seq)
            ->update([
                'next_value' => $seq + 1,
                'updated_at' => now(),
            ]);
    }

    /**
     * Seed value for a freshly-encountered year: MAX existing sequence + 1
     * (defaults to 1). Guards against colliding with seed/imported codes.
     */
    private function seedNextValueForYear(int $year): int
    {
        $prefix = "ORD-{$year}-";

        $lastNumber = CustomerOrder::withTrashed()
            ->where('order_code', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(order_code, ?) AS UNSIGNED)) as last_num', [strlen($prefix) + 1])
            ->value('last_num') ?? 0;

        return (int) $lastNumber + 1;
    }

    /**
     * Public entry point for callers outside the addItems/checkout flow — the
     * workstation LAN sync (OrderLifecycleController::addItems) upserts item
     * rows directly and needs the order's stored money fields recomputed
     * afterwards, or shop/HQ would render the line list against a stale ¥0
     * total (order.create syncs before any item does).
     */
    public function refreshOrderTotals(CustomerOrder $order, ?float $requestedDiscount = null): void
    {
        $this->recalculateTotals($order, $requestedDiscount);
    }

    private function recalculateTotals(CustomerOrder $order, ?float $requestedDiscount = null): void
    {
        $this->applyPricing($order, $requestedDiscount);
    }

    /**
     * plan-043 §8 — price the order through the per-rate engine and persist
     * the results. Groups non-voided lines by their snapshot tax_rate, applies
     * the coupon pro-rata per group, taxes the service charge with its own
     * rate, rounds ONCE per rate group, in tax-excluded or tax-included mode.
     * Also stamps the per-line tax_amount snapshots (allocated so Σ line ==
     * the group tax — {@see stampLineTaxAmounts}). Returns the result so
     * checkout() can reuse it without a second computation.
     *
     * Replaces the old single-rate recalculateTotals (which used one branch
     * tax_rate on the whole subtotal + ceil-to-step rounding — plan-043 kills
     * that, spec §3.4 / §8).
     */
    private function applyPricing(CustomerOrder $order, ?float $requestedDiscount = null): PricingResult
    {
        $settings = ShopOrderSetting::where('branch_id', $order->branch_id)->first();
        $step = RoundingMode::step('auto', $settings?->currency_code ?? 'JPY'); // #815 default JPY
        // plan-045 — the order's SNAPSHOT tax rounding rule (immutable, stamped
        // at creation from ShopOrderSetting). The engine reads this, never the
        // live setting, so a settings change never re-rounds a historical order.
        // Legacy/blank orders default to round + null-decimals (currency step) =
        // pre-plan-045 behaviour (round ≡ the old half_up; roundToStep also still
        // accepts the legacy half_up/round_up/round_down aliases).
        $taxMode = $order->tax_rounding_mode ?: 'round';
        $taxDecimals = $order->tax_rounding_decimals !== null ? (int) $order->tax_rounding_decimals : null;
        $taxStep = RoundingMode::taxStep($taxDecimals, $settings?->currency_code ?? 'JPY'); // #815 default JPY
        // Per-order mode snapshot governs (immutable once set at creation).
        // #2108 — no branch-flag fallback: the column is NOT NULL (default
        // false) so the old `?? $settings?->prices_include_tax` arm was dead
        // code, and reading the live flag on a recompute is the exact
        // reinterpretation the 税込/税抜 ruling forbids.
        $includeTax = (bool) $order->is_tax_included;

        // #857 — price the lines exactly as they are in the DB *now*. Every
        // mutating caller (voidItem/updateItem/addItems/workstation sync) edits
        // a row through a separately-fetched model, so a previously-hydrated
        // `items` collection on this instance is stale; loadMissing() would then
        // price the pre-mutation cart (voided line still counted → coupon
        // min-spend never re-checked → negative totals, #550). load() always
        // re-queries. This is the single pricing choke-point, so fixing it here
        // immunises every recompute path regardless of upstream eager-loads.
        $order->load('items');

        $rateSubtotals = $this->orderPricingCalculator->rateSubtotalsForOrder($order);

        // #550 — the coupon discount must track the LIVE cart, not the frozen
        // apply()-time snapshot. Re-derive it against the current subtotal
        // (re-checks min-spend + max cap): a coupon that dropped below its
        // min_order_subtotal recomputes to 0. null → the order carries no coupon,
        // so keep the manual intent supplied above.
        // #2079 — giỏ SỐNG là giỏ sau khi trừ hàng đã trả lại.
        //
        // `rateSubtotalsForOrder()` cố ý bỏ qua dòng hoàn (chúng mang ảnh chụp
        // thuế âm sẵn, không được đi qua bộ phân bổ ≥0). Đúng cho việc tính
        // thuế — nhưng dùng lại chính con số ấy làm nền TÍNH LẠI COUPON thì
        // hoàn nửa đơn xong coupon vẫn tính trên giỏ NGUYÊN.
        //
        // Đo được: đơn 2 món ¥1.000 + coupon ¥500, khách trả 1.650. Hoàn một
        // món ⇒ hệ thống ra tổng 575, trong khi phần thuộc về món giữ lại là
        // 1.000 − 250 + 75 = 825. Quán hoàn 1.075 cho món khách trả 825 — mất
        // 250 mỗi lần, và toàn bộ giá trị coupon dồn lên phần hàng còn lại.
        //
        // Cùng lỗi đã sửa cho VOID ở #550; refund bị bỏ sót. Ý định của #550
        // viết ngay trên đây — "coupon phải bám GIỎ HÀNG SỐNG" — vốn đã bao
        // gồm refund; chỉ phép tính là thiếu.
        $liveSubtotal = max(
            0.0,
            array_sum($rateSubtotals) + $this->orderPricingCalculator->refundedSubtotalFor($order->items),
        );
        // Manual intent can be larger than the applied ledger amount (the
        // engine clamps it to the live cart), so it lives in the dedicated
        // manual field. Coupon amounts are recomputed below; its current ledger
        // value is only the no-materialised-cart fallback.
        $discountAmount = $requestedDiscount ?? ($order->coupon_id === null
            ? (float) $order->manual_discount_amount
            : (float) $order->discount_amount);
        // Only re-evaluate against a materialised cart. A stored-subtotal-only row
        // with no priced lines (e.g. a freshly synced workstation order before its
        // items land, or a just-applied coupon on a headless order) has no live
        // cart to recompute against — keep the applied discount as-is.
        //
        // #2114 — `$liveSubtotal === 0` mang HAI nghĩa, và điều kiện cũ gộp chúng
        // làm một:
        //
        //	chưa có giỏ   — không dòng nào được định giá (đơn máy trạm vừa sync,
        //	                đơn headless vừa áp coupon) ⇒ ĐÚNG là phải giữ nguyên
        //	giỏ co về 0   — hoàn hết / huỷ hết ⇒ khoản giảm phải về 0
        //
        // Nghĩa thứ hai rơi vào nhánh "giữ nguyên" và cho ra tổng ÂM: đơn 2 món
        // ¥1.000 + coupon cố định ¥500 (ngưỡng 0), hoàn cả hai món ⇒
        // `subtotal 0 · discount 500 · total −475`. Quán nợ ngược khách một
        // khoản khách chưa từng trả.
        //
        // Phân biệt bằng CÓ DÒNG HAY KHÔNG, không phải bằng tổng tiền: một đơn
        // đã có `order_items` là một giỏ đã hiện hữu, kể cả khi mọi dòng đã bị
        // huỷ hoặc hoàn. Đơn chưa có dòng nào mới là đơn không có gì để tính lại.
        //
        // `recomputeDiscountForOrder` tại nền 0 tự trả về 0 — không phải nhờ thêm
        // kẹp nào: `computeDiscount` đã có `min(discount_value, subtotal)` cho
        // coupon cố định, và phần trăm của 0 vốn là 0. Lỗi nằm ở chỗ nó KHÔNG
        // ĐƯỢC GỌI, không nằm trong phép tính.
        if ($liveSubtotal > 0 || $order->items->isNotEmpty()) {
            $recomputedDiscount = $this->couponService()->recomputeDiscountForOrder($order, $liveSubtotal);
            if ($recomputedDiscount !== null) {
                $discountAmount = $recomputedDiscount;
            }
        }

        // #2182 — khoản giảm ÁP DỤNG ĐƯỢC không bao giờ lớn hơn giỏ SỐNG.
        //
        // `priceGroups` đã kẹp `min($discount, $subtotal)`, nhưng cái `$subtotal`
        // ấy là tổng GỘP của các dòng sống — nó KHÔNG co lại khi hàng được trả
        // (dòng hoàn là một dòng âm riêng). Nên với giỏ đã hoàn hết, phép kẹp
        // kia vẫn thấy 2.000 và để nguyên khoản giảm 500.
        //
        // Coupon thì đã an toàn: `recomputeDiscountForOrder` tự tính trên
        // `$liveSubtotal`. Giảm giá TAY thì KHÔNG được đánh giá lại (nó là quyết
        // định của con người — xem `CouponRecomputeOnRefundTest`), nên nó là
        // đường duy nhất còn treo khoản giảm trên một giỏ rỗng: đo được
        // `subtotal 0 · discount 500 · total −475`, tức đơn khẳng định quán NỢ
        // khách một khoản khách chưa từng trả. Đúng triệu chứng #2114 đã chữa
        // cho coupon, chỉ khác đường vào.
        //
        // Kẹp phần ÁP DỤNG, **không** ghi đè ý định: `manual_discount_amount`
        // giữ số YÊU CẦU của thu ngân (đầu vào đi qua cổng governance ở
        // checkout), sổ `order_conditions` giữ số THỰC TẾ. Hai vai khác nhau, và
        // `ConditionLedgerEdgeCasesTest` ghim đúng ranh giới ấy.
        //
        // #2240 vòng 2 — cận kẹp phải là CHÍNH mẫu số phân bổ (Σ survivingGross),
        // không phải `$liveSubtotal`. Hai đại lượng đọc hai nguồn: liveSubtotal
        // cộng `subtotal` ĐÓNG BĂNG của dòng hoàn, survivingGross nhân đơn giá
        // HIỆN TẠI với (qty − refunded_qty). Chúng lệch được qua đường nghiệp vụ
        // thật (downgrade promotion nâng lại giá dòng đã hoàn; sync-UP ghi đè giá
        // sau khi HQ đổi menu), và khi D lọt vào khoảng (ΣW, liveSubtotal] thì
        // share dòng bị kẹp `max(0,…)` ở mức dòng mà mức nhóm không kẹp cùng
        // lượng ⇒ Σ thuế dòng ≠ tax_amount. Kẹp bằng ΣW thì `D ≤ ΣW` đúng theo
        // định nghĩa — trùng nguồn, không nhờ hai nguồn tình cờ khớp.
        //
        // #2240 vòng 4 — SIẾT bằng CẢ HAI cận, không thay cận này bằng cận kia:
        // hai đại lượng lệch theo CẢ HAI chiều. Giá dòng đã hoàn TĂNG ⇒ ΣW <
        // liveSubtotal, cận ΣW giữ bất biến "Σ thuế dòng == tax_amount". Giá
        // GIẢM ⇒ liveSubtotal < ΣW, cận liveSubtotal giữ bất biến "total không
        // âm" (#2114 — bỏ nó thì đơn 300 gánh khoản giảm 1000, total −770).
        // Mỗi cận bảo vệ một bất biến khác nhau; bỏ cận nào cũng tái mở một
        // bug đã có số đo (verdict vòng 2 + vòng 3 của PR #2250).
        $survivingGross = $this->orderPricingCalculator->survivingGrossByRate($order);
        $appliedDiscount = min($discountAmount, $liveSubtotal, array_sum($survivingGross));

        // #2182 — GIỮ kết quả trước khi gấp dòng hoàn vào.
        //
        // Ảnh chụp thuế từng dòng SỐNG phải được phân bổ trên đúng nền mà
        // `priceGroups` vừa dùng (tổng GỘP + khoản giảm pro-rata trên nền ấy).
        // Lấy `$pricing` SAU `applyRefundLines` làm mẫu số là so tử số GỘP với
        // mẫu số ĐÃ TRỪ HOÀN — hai tập khác nhau: trên đơn 2 × ¥1.000 + coupon
        // ¥500, hoàn một món cho mẫu số 1.000 trong khi mỗi dòng vẫn là 1.000,
        // nên phần giảm của dòng thành 500 (đáng lẽ 250) và ảnh chụp tụt từ 75
        // xuống 50. Bất biến "Σ thuế từng dòng == tax_amount của đơn" — thứ mọi
        // báo cáo cộng dòng dựa vào — vỡ ngay ở lần hoàn ĐẦU TIÊN.
        // #2240 — mẫu số phân bổ khoản giảm là gross CÒN SỐNG từng nhóm (đã trừ
        // refunded_quantity), không phải gross thô: phần giảm từng ngồi trên
        // nhóm bị hoàn phải DI CƯ sang nhóm còn giữ (mô hình đánh-giá-lại).
        // `allocateLineTaxes` dùng đúng trọng số này ở mức dòng — một nguồn.
        $basePricing = $this->orderPricingCalculator->priceGroups(
            $rateSubtotals,
            $appliedDiscount,
            (float) ($settings?->service_charge_rate ?? 0),
            (float) ($settings?->service_charge_tax_rate ?? 0),
            $includeTax,
            $step,
            $taxStep,
            $taxMode,
            discountWeights: $survivingGross,
        );

        // plan-045 — fold appended refund lines (negated snapshot) into the
        // persisted totals; the group-once above priced only the positive lines.
        $pricing = $this->orderPricingCalculator->applyRefundLines($basePricing, $order->items);

        $order->update([
            'subtotal' => $pricing->subtotal,
            'total_amount' => $pricing->totalAmount,
        ]);

        $this->stampLineTaxAmounts($order, $basePricing->subtotal, $basePricing->discount, $includeTax, $taxStep, $taxMode);

        // plan-045 — regenerate the tax + discount condition ledger from the
        // fresh figures (refund conditions are append-only events, untouched).
        $this->writeConditions($order, $pricing, $settings);

        // The accessor caches the relation to avoid three queries per order.
        // The rows were replaced above, so force the next read to load the new
        // ledger rather than the pre-pricing collection.
        $order->unsetRelation('conditions');

        if ($order->coupon_id !== null) {
            app(OrderCouponLedger::class)->syncRedemptionAmountForOrder(
                (string) $order->id,
                (float) $pricing->discount,
            );
        }

        return $pricing;
    }

    /**
     * plan-045 — (re)write the order's derived `tax` + `discount` + `service_charge`
     * condition ledger rows: delete the previous ones (order + its items), then
     * insert value-copied snapshots from the fresh PricingResult. `refund`
     * conditions are append-only events written by refundItem() and are NEVER
     * touched here. Reconciles:
     *   Σ(tax).amount            ==  order.tax_amount
     *   Σ(discount).amount       == −order.discount_amount
     *   Σ(service_charge).amount ==  order.service_charge
     *
     * `tip` deliberately has NO row here (#2041): it already lives in its own
     * table as `order_payments.tip_amount`, it is attached to a PAYMENT (split
     * bill ⇒ each payer tips on their own card), and BR-P03 keeps it out of
     * `total_amount`. This ledger's invariant is
     * `total_amount == subtotal + Σ(amount)`, so folding tip in would break the
     * very property that makes the table reconcilable.
     */
    private function writeConditions(CustomerOrder $order, PricingResult $pricing, ?ShopOrderSetting $settings): void
    {
        $currency = $settings?->currency_code ?? 'VND';
        $itemIds = $order->items->pluck('id')->all();
        $orderMorph = $order->getMorphClass();
        $itemMorph = (new CustomerOrderItem)->getMorphClass();

        // #2403 — đơn CHƯA có giỏ thì KHÔNG được đụng dòng `discount`.
        //
        // `$pricing->discount` bị kẹp `min(discount, subtotal)`, mà subtotal của
        // đơn không dòng là 0 ⇒ engine luôn ra 0. Trước #2041 khoản giảm nằm ở
        // CỘT nên nó chỉ đơn giản không bị ghi đè; nay `writeConditions` xoá rồi
        // ghi lại từ `$pricing`, nên cái xoá đó làm bay khoản giảm.
        //
        // Đó đúng là ca #2114 dựng ra để chặn: đơn máy trạm vừa sync lên trước
        // khi các dòng của nó tới phải GIỮ khoản giảm đã áp — "đơn chưa có giỏ
        // thì không có gì để tính lại". Thuế/phí phục vụ vẫn tính lại như cũ.
        $cartMaterialised = $order->items->isNotEmpty();
        $recomputableTypes = $cartMaterialised
            ? ['tax', 'discount', 'service_charge']
            : ['tax', 'service_charge'];

        OrderCondition::query()
            ->whereIn('type', $recomputableTypes)
            ->where(function ($q) use ($order, $itemIds, $orderMorph, $itemMorph) {
                $q->where(fn ($q2) => $q2->where('conditionable_type', $orderMorph)->where('conditionable_id', $order->id));
                if ($itemIds !== []) {
                    $q->orWhere(fn ($q2) => $q2->where('conditionable_type', $itemMorph)->whereIn('conditionable_id', $itemIds));
                }
            })
            ->delete();

        // Order-level tax row per rate group (positive tax + folded sc tax).
        foreach ($pricing->groups as $g) {
            // Bỏ qua khi nhóm KHÔNG CÓ GÌ — cả thuế lẫn nền đều 0.
            //
            // Trước đây điều kiện là `tax === 0`, và nó nuốt mất nhóm 0%: một
            // đơn có món 非課税 cạnh món 10% chỉ ghi dòng cho mức 10%, nên tổng
            // nền chịu thuế trên sổ KHÔNG còn bằng subtotal — phần 非課税 biến
            // mất khỏi bảng thuế của hoá đơn.
            //
            // Cả hai chuẩn đều đòi nhóm ấy phải có mặt: Peppol/EN16931 có
            // BR-Z-08 (zero-rated) và BR-E-08 (exempt) yêu cầu nhóm xuất hiện
            // trong bảng thuế kèm nền của nó; và 非課税 ở Nhật phải phân biệt
            // được trên chứng từ, chứ "0 thuế" không đồng nghĩa "không tồn tại".
            if ((float) $g->tax === 0.0 && (float) $g->taxable === 0.0) {
                continue;
            }
            $rate = (float) $g->rate;
            $order->conditions()->create([
                'type' => 'tax',
                'source' => 'tax_type',
                'label' => rtrim(rtrim(number_format($rate, 2), '0'), '.').'%',
                'rate' => $rate,
                'amount' => (float) $g->tax,
                // #2031 — 税率ごとに区分した対価の額. Lưu chứ không suy lại lúc in:
                // đường hoá đơn từng gom `SUM(items.subtotal)` (GỘP) cạnh
                // `SUM(items.tax_amount)` (đã trừ giảm giá), nên trên đơn có
                // khuyến mãi hai cột không khớp nhau ở chính mức đã in.
                'taxable_base' => (float) $g->taxable,
                'currency_code' => $currency,
                'meta' => ['rate_group' => (string) $rate],
            ]);
        }

        // #2031 — giảm giá ghi MỘT DÒNG CHO MỖI MỨC, không phải một dòng tổng.
        //
        // Trước đây `rate` là null: sổ biết tổng giảm giá nhưng không biết nó rơi
        // vào mức nào. Đơn vừa 10% (tại chỗ) vừa 8% (mang đi) thì phải chạy lại
        // logic nghiệp vụ mới dựng lại được con số đã in — đúng thứ mà sổ chụp
        // giá trị của plan-045 sinh ra để tránh.
        //
        // Phân bổ dùng lại đúng phép pro-rata mà `OrderPricingCalculator` dùng để
        // tính thuế, rồi ĐẶT PHẦN DƯ VÀO MỨC CUỐI để Σ khớp `discount_amount`
        // tuyệt đối. Không có phần dư trôi mất: bất biến
        // `Σ(discount).amount == −order.discount_amount` là thứ bảng này tự khai.
        // Giảm giá ĐÃ ÁP DỤNG, không phải giảm giá được YÊU CẦU. `OrderPricingCalculator`
        // kẹp `min($discount, $subtotal)`, nên một yêu cầu giảm ¥5.000 trên đơn
        // ¥1.000 thực tế chỉ trừ ¥1.000. Ghi con số chưa kẹp vào sổ là nói dối
        // về tiền: sổ khai khách được giảm 5.000 trong khi họ được giảm 1.000,
        // và `subtotal + Σ(sổ)` ra âm trong khi đơn có tổng bằng 0.
        //
        // `manual_discount_amount` giữ số YÊU CẦU (đầu vào của thu ngân, đi qua
        // cổng governance ở checkout); sổ giữ số THỰC TẾ. Hai vai khác nhau, và khi
        // chúng khác nhau thì sổ mới là chỗ nói về tiền.
        $discount = (float) $pricing->discount;
        if ($discount > 0) {
            $source = $order->coupon_id ? 'coupon' : 'manual';
            $label = $order->coupon_code_snapshot ?: 'Discount';
            $baseMeta = $order->coupon_id ? ['coupon_id' => $order->coupon_id] : [];

            $subtotal = (float) $pricing->subtotal;
            $rows = [];

            if ($subtotal > 0.0 && $pricing->groups !== []) {
                $step = RoundingMode::taxStep(
                    $order->tax_rounding_decimals !== null ? (int) $order->tax_rounding_decimals : null,
                    $currency,
                );
                $allocated = 0.0;
                $last = count($pricing->groups) - 1;

                foreach ($pricing->groups as $i => $g) {
                    $share = $i === $last
                        ? $discount - $allocated
                        : RoundingMode::roundHalfUpToStep($discount * $this->groupGross($order, (float) $g->rate) / $subtotal, $step);
                    $allocated += $share;

                    if ($share > 0.0) {
                        $rows[] = ['rate' => (float) $g->rate, 'amount' => -1.0 * $share];
                    }
                }
            }

            // Không dựng được nhóm (đơn không có dòng nào chịu thuế) — vẫn phải
            // ghi tổng, nếu không bất biến Σ ở trên vỡ và tiền biến mất khỏi sổ.
            if ($rows === []) {
                $rows[] = ['rate' => null, 'amount' => -1.0 * $discount];
            }

            foreach ($rows as $row) {
                $order->conditions()->create([
                    'type' => 'discount',
                    'source' => $source,
                    'label' => $label,
                    'rate' => $row['rate'],
                    'amount' => $row['amount'],
                    'currency_code' => $currency,
                    'meta' => $row['rate'] === null
                        ? $baseMeta
                        : $baseMeta + ['rate_group' => (string) $row['rate']],
                ]);
            }
        }

        // #2041 — phí phục vụ vào SỔ, không chỉ là cột.
        //
        // Hôm nay khoản này bị tách làm đôi một cách kỳ quặc: phần THUẾ của nó
        // đã được gấp vào dòng `tax` của nhóm mức tương ứng (OrderPricingCalculator
        // §"attribute the residual"), nên nhìn sổ thì nền của nhóm ấy đã gồm cả
        // phí — nhưng BẢN THÂN khoản phí không có dòng nào. Hệ quả: không truy
        // được nguồn, và mọi nơi cần con số ấy phải đọc một cột vô hướng nằm
        // ngoài sổ, đúng hình dạng đã sinh ra #2031.
        //
        // `rate` ở đây là mức thuế khoản phí CHỊU, không phải tỉ lệ tính phí
        // (`service_charge_rate`, ví dụ 5% trên subtotal). Tỉ lệ tính phí là đầu
        // vào cấu hình và có thể đổi bất cứ lúc nào; cái sổ cần biết khoản tiền
        // ấy đã rơi vào NHÓM MỨC nào, vì đó là thứ dựng lại được nền chịu thuế
        // đã in. Null = ngoài phạm vi thuế.
        // Từ KẾT QUẢ ĐỊNH GIÁ, không phải từ cột. Cột được ghi ngay trước lượt
        // gọi này nên hôm nay hai bên bằng nhau — nhưng chiều phụ thuộc thì
        // ngược: đọc cột biến sổ thành DẪN XUẤT CỦA CỘT, và ngày nào có đường
        // ghi khác chạm cột thì sổ lặng lẽ đi theo con số sai.
        //
        // Đây cũng là điều kiện tiên quyết để xoá được cột (#2041 bước 3): chừng
        // nào sổ còn đọc cột thì xoá cột là làm sổ ngừng có dòng.
        $serviceCharge = (float) $pricing->serviceCharge;
        if ($serviceCharge > 0.0) {
            $scRate = (float) ($settings?->service_charge_tax_rate ?? 0);
            $order->conditions()->create([
                'type' => 'service_charge',
                'source' => 'service_charge',
                'label' => 'Service charge',
                'rate' => $scRate > 0.0 ? $scRate : null,
                'amount' => $serviceCharge,
                'currency_code' => $currency,
                'meta' => $settings?->service_charge_rate !== null
                    ? ['charge_rate' => (string) $settings->service_charge_rate]
                    : [],
            ]);
        }
    }

    /**
     * Tổng tiền món GỘP (chưa trừ giảm giá) của một mức thuế — mẫu số của phép
     * pro-rata, đúng cái `OrderPricingCalculator` nhận vào qua `$rateSubtotals`.
     *
     * Đọc lại từ dòng món thay vì từ `TaxGroup` vì `TaxGroup::$taxable` đã là nền
     * SAU giảm giá; lấy nó làm mẫu số sẽ phân bổ theo một tỉ lệ khác với tỉ lệ đã
     * dùng lúc tính thuế, và hai chỗ lệch nhau là cách phần dư đi lạc.
     */
    private function groupGross(CustomerOrder $order, float $rate): float
    {
        $sum = 0.0;

        foreach ($order->items as $item) {
            // `itemStatusValue()`, KHÔNG phải `$item->status` trần.
            //
            // `status` được cast sang `OrderItemStatusEnum` (base model), nên
            // `$item->status === OrderItemStatusEnum::Voided->value` là so một
            // enum với một CHUỖI — luôn false, và dòng đã huỷ vẫn vào mẫu số.
            //
            // Đo được hậu quả: đơn 1000@8% + 1000@10% với một dòng 3000@8% ĐÃ
            // HUỶ, giảm giá 400 ⇒ mẫu số mức 8% thành 4000 thay vì 1000, mức 8%
            // ăn −800 (gấp đôi cả khoản giảm), và mức 10% không có dòng nào vì
            // phần còn lại ra âm. Bất biến Σ(discount) == −discount_amount vỡ.
            //
            // Nó ẩn được lâu vì mức CUỐI hấp thụ phần dư: dòng huỷ nằm ở mức
            // cuối thì tổng vẫn khớp và không gì lộ ra.
            if ($this->itemStatusValue($item) === OrderItemStatusEnum::Voided->value) {
                continue;
            }
            // Dòng hoàn tiền cũng phải loại — chúng mang ảnh chụp âm và đã bị
            // `rateSubtotalsForOrder` loại khỏi tử số, nên để lại trong mẫu số
            // là so hai tập khác nhau. (Phía Go loại đúng từ đầu.)
            if ($item->refund_of_item_id !== null && $item->refund_of_item_id !== '') {
                continue;
            }
            if ((float) ($item->tax_rate ?? 0.0) !== $rate) {
                continue;
            }
            $sum += (float) $item->quantity * ((float) $item->unit_price + (float) ($item->topping_subtotal ?? 0));
        }

        return $sum;
    }

    /**
     * plan-043 — stamp the per-line tax_amount snapshots. Lines are grouped by
     * their snapshot rate, the group's tax is rounded ONCE (the SAME
     * {@see OrderPricingCalculator::groupTaxFor} figure the order total uses),
     * then allocated back to the lines by largest remainder so that, within
     * each rate group, **Σ line tax_amount == the group tax**. This keeps every
     * report that sums the per-line snapshots (Z-report, revenue dashboards,
     * `tax_breakdown`) reconciled to `order.tax_amount` and compliant with the
     * インボイス once-per-rate-group rounding rule — the previous
     * independently-rounded per-line values could sum to a different (forbidden)
     * figure. See {@see OrderPricingCalculator::allocateGroupTax}.
     *
     * `order.tax_amount` may still exceed Σ line tax_amount by the
     * service-charge tax, which is an order-level charge with no owning line.
     */
    private function stampLineTaxAmounts(
        CustomerOrder $order,
        float $subtotal,
        float $discount,
        bool $includeTax,
        float $taxStep,
        string $taxMode = 'round',
    ): void {
        $allocated = $this->allocateLineTaxes($order, $subtotal, $discount, $includeTax, $taxStep, $taxMode);

        foreach ($order->items as $item) {
            $lineTax = $allocated[(string) $item->id] ?? null;
            if ($lineTax !== null && (float) $item->tax_amount !== $lineTax) {
                $item->update(['tax_amount' => $lineTax]);
            }
        }
    }

    /**
     * Phép phân bổ đứng sau {@see stampLineTaxAmounts}, tách ra để dùng được ở
     * hai vai — KHÔNG ghi gì, chỉ trả về `id dòng → thuế`.
     *
     * Vai thứ hai là đường HOÀN (#2182): dòng hoàn cần biết phần thuế của dòng
     * gốc trên nền GỘP (gọi với `$discount = 0`), và nó phải là **cùng một phép
     * phân bổ** thì con số mới dựng lại được. Tính lại bằng
     * `round(subtotal × rate)` cho từng dòng là một phép KHÁC: phân bổ
     * largest-remainder có thể để một dòng mang số thuế không dựng lại được từ
     * chính nó (ba dòng ¥1.005 @10% ⇒ 101/101/100, xem
     * `RefundReversesTaxExactlyTest`), và Σ của các số tính rời không bằng thuế
     * nhóm — tức hoàn hết vẫn còn đọng một bước tiền.
     *
     * @return array<string, float> id dòng → thuế đã phân bổ
     */
    private function allocateLineTaxes(
        CustomerOrder $order,
        float $subtotal,
        float $discount,
        bool $includeTax,
        float $taxStep,
        string $taxMode = 'round',
    ): array {
        $subtotal = max(0.0, $subtotal);
        $discount = max(0.0, min($discount, $subtotal));

        // #2240 — mẫu số của share dòng là Σ gross CÒN SỐNG, cùng nguồn với
        // trọng số nhóm trong priceGroups (survivingGrossByRate): share nhóm =
        // Σ share các dòng của nó theo cấu trúc, nên "Σ thuế dòng == header"
        // không phụ thuộc vào việc đơn có dòng hoàn hay không.
        $survivingSum = array_sum($this->orderPricingCalculator->survivingGrossByRate($order));

        // Bucket the non-voided lines by rate, carrying each line's net base
        // (subtotal − pro-rata discount) — the exact input priceGroups uses, so
        // the per-group tax reconstructed below is byte-for-byte the order's.
        /** @var array<string, array{rate: float, items: list<CustomerOrderItem>, nets: list<float>}> $groups */
        $groups = [];
        foreach ($order->items as $item) {
            if ($this->itemStatusValue($item) === OrderItemStatusEnum::Voided->value) {
                continue;
            }
            // plan-045 — refund lines carry a fixed copied+negated tax snapshot;
            // they are NOT re-stamped via group-once (handled by applyRefundLines).
            if ($item->refund_of_item_id !== null) {
                continue;
            }
            // #2188 — a line with no snapshot rate is broken input, not a
            // pricing tier: it is skipped here exactly as the engine skipped it
            // in rateSubtotalsForOrder() (bỏ dòng + WARN, không bịa — #2067).
            if ($item->tax_rate === null) {
                continue;
            }
            $rate = (float) $item->tax_rate;
            $lineSubtotal = (float) $item->quantity * ((float) $item->unit_price + (float) ($item->topping_subtotal ?? 0));
            $lineSurviving = $this->orderPricingCalculator->survivingLineGross($item);
            $discountShare = $survivingSum > 0 ? $discount * $lineSurviving / $survivingSum : 0.0;
            $net = max(0.0, $lineSubtotal - $discountShare);

            $key = (string) $rate;
            $groups[$key]['rate'] = $rate;
            $groups[$key]['items'][] = $item;
            $groups[$key]['nets'][] = $net;
        }

        $out = [];

        foreach ($groups as $group) {
            $netGroup = array_sum($group['nets']);
            $groupTax = $this->orderPricingCalculator->groupTaxFor($netGroup, $group['rate'], $includeTax, $taxStep, $taxMode);
            $ideals = array_map(
                fn (float $net) => $this->orderPricingCalculator->lineTaxIdeal($net, $group['rate'], $includeTax),
                $group['nets'],
            );
            $allocated = $this->orderPricingCalculator->allocateGroupTax($ideals, $groupTax, $taxStep);

            foreach ($group['items'] as $i => $item) {
                $out[(string) $item->id] = (float) $allocated[$i];
            }
        }

        return $out;
    }

    private function itemStatusValue(CustomerOrderItem $item): string
    {
        $status = $item->status;

        return $status instanceof OrderItemStatusEnum ? $status->value : (string) $status;
    }

    /**
     * @param  CustomerOrderStatusEnum[]  $allowed
     */
    private function assertStatus(CustomerOrder $order, array $allowed, string $action): void
    {
        $current = $this->resolveStatus($order);
        $allowedValues = array_map(fn ($s) => $s->value, $allowed);

        if (! in_array($current->value, $allowedValues, true)) {
            abort(409, "Cannot {$action}: order status is '{$current->value}', expected one of: ".implode(', ', $allowedValues));
        }
    }

    private function resolveStatus(CustomerOrder $order): CustomerOrderStatusEnum
    {
        return $order->status instanceof CustomerOrderStatusEnum
            ? $order->status
            : CustomerOrderStatusEnum::from($order->status);
    }

    private function validateItemTransition(OrderItemStatusEnum $current, OrderItemStatusEnum $new): void
    {
        // Allow free transitions between active statuses.
        // Voided is excluded — it goes through the dedicated void flow.
        $activeStatuses = [
            OrderItemStatusEnum::Pending,
            OrderItemStatusEnum::Preparing,
            OrderItemStatusEnum::Ready,
            OrderItemStatusEnum::Served,
        ];

        if (! in_array($new, $activeStatuses, true)) {
            abort(409, "Invalid item status transition: {$current->value} → {$new->value}");
        }
    }

    /**
     * Dòng nào của đơn được gộp khi khách gọi lại cùng SKU (#2623).
     *
     * Không còn cửa sổ thời gian, không còn rào "đã có phiếu bếp". Cloud gộp
     * theo cùng vị ngữ với máy trạm — MỘT luật, đúng mục tiêu #2551.
     *
     * # Vì sao bỏ được, đo trên `origin/dev`
     *
     * Cửa sổ 120s là XẤP XỈ cho câu hỏi "bếp đã biết phần này chưa". Câu hỏi đó
     * nay trả lời CHÍNH XÁC được bằng `printed_quantity`, và chuỗi khép kín:
     *
     *   1. Cloud gộp ⇒ `quantity` tăng.
     *   2. Máy trạm pull DOWN: upsert `order_items` cập nhật `quantity` và
     *      **KHÔNG** đụng `printed_quantity` (`sync_pull.go`, `DO UPDATE SET`
     *      không liệt kê cột đó).
     *   3. `onOrderMerged` kích `fireKitchenForOrder`, in đúng phần chênh
     *      `quantity - printed_quantity`.
     *   4. Máy trạm sync UP `printed_quantity` mới về Cloud.
     *
     * # Còn chi nhánh KHÔNG có máy trạm thì sao — câu hỏi chặn của #2623
     *
     * Nó không tồn tại như một rủi ro: **Cloud không bao giờ phát phiếu bếp**.
     * Hàng `print_jobs` kind `kitchen` chỉ tới Cloud qua `POST
     * /workstation/print-jobs` — sync UP nhật ký in của máy trạm, vốn sở hữu
     * hàng đợi in (DESIGN §1b). Đo: không lời gọi `CloudPrntEnqueueService::enqueue`
     * nào trong `app/`, và tham chiếu `PrintJobKind::Kitchen` duy nhất là hàm
     * ĐỌC — và chính nó cũng đã được gỡ cùng lượt này, vì rào là consumer
     * duy nhất của nó.
     *
     * Nói cách khác **phiếu bếp chỉ tồn tại ở nơi có máy trạm**, mà ở đó
     * `printed_quantity` là nguồn có thẩm quyền. Không có topology nào vừa in
     * bếp vừa thiếu người đóng dấu.
     *
     * Vì thế rào cũ cũng chưa bao giờ bảo vệ chi nhánh không-máy-trạm: nó chỉ
     * làm Cloud dè dặt ở đúng nơi đã có đủ thông tin để không cần dè dặt.
     */
    private function resolveMergeWindow(CustomerOrder $order, string $defaultItemStatus): array
    {
        $pending = OrderItemStatusEnum::Pending->value;

        // Quán born-pending: dòng vốn đã gộp được theo luật gốc. Trả sớm để
        // đường đó giữ nguyên từng byte thay vì chỉ tương đương.
        if ($defaultItemStatus === $pending) {
            return [[$pending], null];
        }

        // `null` = KHÔNG có cận dưới thời gian. Chữ ký giữ nguyên hai phần tử
        // vì call-site vẫn cần biết TẬP TRẠNG THÁI nào gộp được — cái bỏ đi là
        // cận, không phải câu hỏi.
        return [[$pending, $defaultItemStatus], null];
    }

    /**
     * Resolve the default item status for a branch.
     *
     * Reads branch.default_order_item_status. Falls back to 'pending'
     * if not set or invalid.
     */
    private function resolveDefaultItemStatus(string $branchId): string
    {
        try {
            $setting = ShopOrderSetting::where('branch_id', $branchId)->first();
            $status = $setting?->default_order_item_status;

            if ($status && in_array($status, OrderItemStatusEnum::values(), true)) {
                return $status;
            }
        } catch (QueryException) {
            // Table may not exist yet — fall through to default
        }

        return OrderItemStatusEnum::Pending->value;
    }

    // =========================================================================
    //  Internal unchecked writers (CouponService, OrderClosingService, jobs)
    // =========================================================================

    public function patchOrderUnchecked(CustomerOrder $order, array $data): void
    {
        $order->update($data);
    }

    public function patchOrderItemUnchecked(CustomerOrderItem $item, array $data): void
    {
        $item->update($data);
    }

    public function markUnservedItemsAsServedAtClose(CustomerOrder $order): void
    {
        $order->items()
            ->whereNotIn('status', [
                OrderItemStatusEnum::Served->value,
                OrderItemStatusEnum::Voided->value,
            ])
            ->update([
                'status' => OrderItemStatusEnum::Served->value,
                'served_at' => now(),
            ]);
    }

    public function commitAwaitingConfirmation(CustomerOrder $order): CustomerOrder
    {
        $order->update([
            'status' => CustomerOrderStatusEnum::Pending->value,
            'confirmation_due_at' => null,
        ]);

        return $order->fresh();
    }

    public function voidAwaitingConfirmation(CustomerOrder $order, string $reason): CustomerOrder
    {
        // BR-COUP07 (#1276). applyCoupon refuses only Closed and Voided, so an
        // awaiting_confirmation cart can hold a coupon — and a guest cancelling
        // their own counter-pay order must get that use back.
        $this->couponService()->releaseIfApplied($order);

        // #1290 — the other three cleanups every order-void path owes, which
        // this one never did. They are all no-ops today: nothing in the backend
        // can put an order INTO awaiting_confirmation (no code assigns
        // `confirmation_due_at`, and the request classes that accept it are
        // generated and unused — see #1212), so only tests reach here.
        //
        // Patched anyway, because the day someone wires the entry transition
        // they would otherwise ship #1283 and #1285 on day one: a table left
        // pointing at a voided order, lines still reading `pending` under it.
        // Each call costs nothing when there is nothing to clean.
        DB::transaction(function () use ($order, $reason): void {
            $deductedLineIds = $this->deductedLineIdsAboutToBeVoided($order);

            $order->items()
                ->where('status', '!=', OrderItemStatusEnum::Voided->value)
                ->update([
                    'status' => OrderItemStatusEnum::Voided->value,
                    'voided_at' => now(),
                    'void_reason' => $reason,
                ]);

            $order->update([
                'status' => CustomerOrderStatusEnum::Voided->value,
                'voided_at' => now(),
                'void_reason' => $reason,
                'confirmation_due_at' => null,
            ]);

            $this->compensateBulkVoidedLines($order, null, $deductedLineIds);
            $this->releaseOrderTables($order);
        });

        return $order->fresh();
    }

    /**
     * @param  array<int, string>  $orderIds
     */
    public function expireAwaitingConfirmationOrders(array $orderIds): int
    {
        if ($orderIds === []) {
            return 0;
        }

        // BR-COUP07 (#1276) — the quietest way to burn a coupon: a guest applies
        // one, walks off without confirming, and the reaper voids the order.
        // Nobody did anything wrong and the use is gone.
        //
        // The bulk UPDATE below stays bulk; only the orders that actually hold a
        // coupon are loaded, which is a small subset of an already-small sweep.
        // Released BEFORE the status flip, since releaseIfApplied() can only
        // mutate while the order is still modifiable.
        CustomerOrder::query()
            ->whereIn('id', $orderIds)
            ->whereNotNull('coupon_id')
            ->get()
            ->each(fn (CustomerOrder $order) => $this->couponService()->releaseIfApplied($order));

        $voidedAt = now();

        // #1290 — same three cleanups the single-order path owes (lines, stock,
        // table). Unreachable today for the reason recorded there, and patched
        // for the same reason: whoever wires the entry transition must not have
        // to rediscover #1283 and #1285.
        //
        // Per order rather than one bulk statement: releaseOrderTables and the
        // stock compensation are both keyed to a specific order, and this sweep
        // is small by construction (only rows whose confirmation window just
        // elapsed).
        CustomerOrder::query()
            ->whereIn('id', $orderIds)
            ->get()
            ->each(function (CustomerOrder $order) use ($voidedAt): void {
                $deductedLineIds = $this->deductedLineIdsAboutToBeVoided($order);

                $order->items()
                    ->where('status', '!=', OrderItemStatusEnum::Voided->value)
                    ->update([
                        'status' => OrderItemStatusEnum::Voided->value,
                        'voided_at' => $voidedAt,
                        'void_reason' => 'confirmation_window_expired',
                    ]);

                $this->compensateBulkVoidedLines($order, null, $deductedLineIds);
                $this->releaseOrderTables($order);
            });

        return CustomerOrder::query()
            ->whereIn('id', $orderIds)
            ->update([
                'status' => CustomerOrderStatusEnum::Voided->value,
                'voided_at' => $voidedAt,
                'void_reason' => 'confirmation_window_expired',
                'confirmation_due_at' => null,
                'updated_at' => $voidedAt,
            ]);
    }

    /**
     * @param  array<int, string>  $orderIds
     */
    public function claimGuestOrderRows(string $customerId, array $orderIds): int
    {
        if ($orderIds === []) {
            return 0;
        }

        return CustomerOrder::query()
            ->whereIn('id', $orderIds)
            ->whereNull('customer_id')
            ->update(['customer_id' => $customerId]);
    }

    public function setStaffEditLock(CustomerOrder $order, ?\DateTimeInterface $lockedAt): CustomerOrder
    {
        $order->forceFill(['editing_by_staff_at' => $lockedAt])->save();

        return $order->refresh();
    }

    public function assignTableSession(CustomerOrder $order, string $sessionId): CustomerOrder
    {
        $order->update(['table_session_id' => $sessionId]);

        return $order->refresh();
    }

    public function setSplitModeFields(CustomerOrder $order, string $splitMode, ?int $splitPeopleCount): CustomerOrder
    {
        $order->update([
            'split_mode' => $splitMode,
            'split_people_count' => $splitPeopleCount,
        ]);

        return $order->refresh();
    }

    public function finalizeClosedOrderHeader(CustomerOrder $order): void
    {
        $order->update([
            'status' => CustomerOrderStatusEnum::Closed->value,
            'closed_at' => now(),
            'checkout_at' => $order->checkout_at ?? now(),
            'paid_amount' => $order->total_amount,
        ]);
    }

    public function stampStockOutTransactionId(CustomerOrder $order, string $transactionId): void
    {
        $order->update(['stock_out_transaction_id' => $transactionId]);
    }

    public function bindCouponToOrder(CustomerOrder $order, string $couponId, string $couponCode, float $discount): void
    {
        $order->update([
            'coupon_id' => $couponId,
            'coupon_code_snapshot' => $couponCode,
        ]);
        $this->recalculateOrderTotalAfterDiscountChange($order, $discount);
    }

    public function clearCouponFromOrder(CustomerOrder $order): void
    {
        $order->update([
            'coupon_id' => null,
            'coupon_code_snapshot' => null,
            'manual_discount_amount' => 0,
        ]);
        $this->recalculateOrderTotalAfterDiscountChange($order, 0.0);
    }

    private function recalculateOrderTotalAfterDiscountChange(CustomerOrder $order, float $discount): void
    {
        $order->refresh();

        if ($order->items()->exists()) {
            $this->refreshOrderTotals($order, $discount);

            return;
        }

        // A workstation shell can carry a frozen subtotal before its item rows
        // arrive. Ghi lại thành phần giảm giá vào sổ, rồi CỘNG LẠI tổng từ các
        // thành phần — không cộng dồn theo delta.
        //
        // #2403 — bản #2041 đổi chỗ này thành delta
        // (`total + oldDiscount - discount`) và làm mất bất biến #555 M14: một
        // coupon lớn hơn giỏ phải kẹp tổng về 0. Delta chỉ đúng khi
        // `total_amount` đã phản ánh đúng khoản giảm cũ; đơn shell không bảo đảm
        // điều đó, nên đo được `subtotal 3.000 · discount 5.000 · total 3.000`
        // — khách bị tính đủ tiền trong khi coupon phủ hết giỏ. Phép cộng lại
        // vẫn đọc được sau #2041 vì `service_charge`/`tax_amount` nay là getter
        // trên sổ, không còn là cột.
        $order->conditions()->where('type', 'discount')->delete();
        if ($discount > 0.0) {
            $order->conditions()->create([
                'type' => 'discount',
                'source' => $order->coupon_id === null ? 'manual' : 'coupon',
                'label' => $order->coupon_code_snapshot ?: 'Discount',
                'amount' => -$discount,
                'currency_code' => ShopOrderSetting::where('branch_id', $order->branch_id)->value('currency_code') ?? 'JPY',
                'meta' => $order->coupon_id === null ? null : ['coupon_id' => $order->coupon_id],
            ]);
        }
        $order->unsetRelation('conditions');
        $order->update([
            'total_amount' => max(0.0, (float) $order->subtotal
                - $discount
                + (float) $order->service_charge
                + (float) $order->tax_amount),
        ]);
    }

    public function stampKitchenTimestampIfNull(CustomerOrderItem $item, string $column): CustomerOrderItem
    {
        if (! in_array($column, ['started_preparing_at', 'ready_at'], true)) {
            throw new \InvalidArgumentException("Unsupported kitchen timestamp column: {$column}");
        }

        if ($item->{$column} === null) {
            $item->update([$column => now()]);
        }

        return $item->refresh();
    }

    // =========================================================================
    //  Workstation LAN sync transports (idempotent replay semantics)
    // =========================================================================

    public function transportWorkstationPatchOrder(CustomerOrder $order, array $patch): CustomerOrder
    {
        if ($patch === []) {
            return $order->fresh();
        }

        $order->update($patch);

        // #1099 — a type flip used to re-resolve every line here, from back when
        // a tax type carried a dine-in/takeaway rate PAIR. It no longer does:
        // TaxResolver has no order-type parameter, the rate rides the MENU LINE
        // the customer ordered from, and "changing the order type never
        // re-prices the order" is the property the single-rate model exists to
        // guarantee. Re-walking the chain here could only ever produce a
        // DIFFERENT answer than the one billed — by picking up an unrelated
        // catalog edit made since the sale.
        $this->refreshOrderTotals($order);

        return $order->fresh();
    }

    public function transportWorkstationSoftDelete(CustomerOrder $order): void
    {
        if ($order->trashed()) {
            return;
        }

        // #1286 — this used to be `$order->delete()` alone, skipping both
        // cleanups the shop's delete() performs. Same two consequences that
        // #1285 and #1276 fixed on the void paths:
        //
        //   - the table kept `current_order_id` pointing at a deleted order,
        //     and nothing else clears it, so a later merge aborted 409 "Table
        //     is already occupied by another order";
        //   - the guest's coupon stayed consumed for an order that no longer
        //     exists — times_used never decremented, redemption row standing.
        //
        // Deliberately NOT adopting the shop guards (open-only, no served
        // items): sync-UP is lenient on purpose, since rejecting here strands
        // the workstation's sync op in a retry loop. Guards are a different
        // question from cleanup.
        DB::transaction(function () use ($order): void {
            $this->couponService()->releaseIfApplied($order);

            $this->tables()->releaseByOrder($order->id);

            $order->delete();
        });
    }

    public function transportWorkstationVoid(CustomerOrder $order, string $reason): CustomerOrder
    {
        $currentStatus = $this->resolveStatus($order);

        if ($currentStatus === CustomerOrderStatusEnum::Voided) {
            return $order;
        }

        // Both guards below mirror the shop path (voidOrder) and were absent
        // here, so a workstation LAN void bypassed protections Cloud enforces
        // everywhere else — Cloud is the authority on both paths.
        //
        // A closed (settled) order is done; voiding it would unwind a completed
        // sale. #547 — an order still holding net-collected cash/card must be
        // refunded first, else the void orphans the payment: money stays in the
        // drawer / on the gateway with no order to reconcile it against.
        if ($currentStatus === CustomerOrderStatusEnum::Closed) {
            abort(409, 'Cannot void a closed order.');
        }

        $this->assertNoCollectedPayments($order);

        // BR-COUP07, and the third thing this method was missing relative to
        // voidOrder (#1276). Release BEFORE flipping status: releaseIfApplied()
        // can only mutate while the order is still in a modifiable state, and
        // without it the guest's coupon stays consumed — times_used decremented
        // never, their redemption row left standing — for an order that no
        // longer exists.
        $this->couponService()->releaseIfApplied($order);

        // #1283 — the FOURTH thing this method was missing relative to
        // voidOrder: it flipped the order and left every line in its old
        // status. The lines then read `pending`/`preparing` under a Voided
        // order — inconsistent for anything that counts by item status, and
        // invisible to the #1257 repair sweep, which looks for voided lines.
        // Any stock those lines deducted stayed out with nothing recording it.
        $deductedLineIds = $this->deductedLineIdsAboutToBeVoided($order);

        // #1285 — one transaction, like voidOrder. Voiding the lines, flipping
        // the order and freeing the table are one fact about the world; half of
        // it committing is how a table ends up pointing at an order that no
        // longer exists.
        $releasedTableIds = DB::transaction(function () use ($order, $reason, $deductedLineIds): array {
            $order->items()
                ->where('status', '!=', OrderItemStatusEnum::Voided->value)
                ->update([
                    'status' => OrderItemStatusEnum::Voided->value,
                    'voided_at' => now(),
                    'void_reason' => $reason,
                ]);

            $order->update([
                'status' => CustomerOrderStatusEnum::Voided->value,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            // No operator picker on the LAN void path, so the conservative
            // branch applies: recorded, never silently restocked.
            $this->compensateBulkVoidedLines($order, null, $deductedLineIds);

            // #1285 — the FIFTH divergence from voidOrder. Without this the
            // table keeps `current_order_id` pointing at a voided order, and
            // nothing else ever clears it: the four sites that null that column
            // are delete(), continueTableOrder(), releaseOrderTables() and
            // unmergeTable(), none of which run here. The workstation's own
            // table sync-UP does not help either — TableStatusService writes
            // `status` and leaves the pointer alone. A later merge onto that
            // table then 409s with "Table is already occupied by another
            // order", about an order that was cancelled.
            return $this->releaseOrderTables($order);
        });

        $fresh = $order->fresh();

        // Same broadcast the shop path makes: the void and its table release
        // reach TMS and any POS terminal that is not on this LAN.
        // ShouldDispatchAfterCommit holds it until the transaction commits.
        event(new OrderVoided($fresh, $releasedTableIds));

        return $fresh;
    }

    public function transportWorkstationCheckout(CustomerOrder $order, array $data): CustomerOrder
    {
        if (in_array($this->resolveStatus($order), [
            CustomerOrderStatusEnum::Checkout,
            CustomerOrderStatusEnum::Paying,
            CustomerOrderStatusEnum::Closed,
            CustomerOrderStatusEnum::Voided,
            CustomerOrderStatusEnum::Expired,
        ], true)) {
            return $order;
        }

        $patch = [
            'status' => CustomerOrderStatusEnum::Checkout->value,
            'checkout_at' => now(),
        ];

        $discountChanged = isset($data['discount_amount']);
        if ($discountChanged) {
            $patch['manual_discount_amount'] = $data['discount_amount'];
        }

        // #1287 — this was `$order->update($patch)` alone, making it the only
        // workstation op that writes money without recomputing the totals it
        // invalidates. Every sibling (patch-order, sync-items, apply-coupon,
        // release-coupon) refreshes; this one recorded the cashier's discount
        // and left `total_amount` at its pre-discount value. Since
        // settleOrderIfPaid measures `total_amount - paid_amount`, the guest
        // who paid the discounted price still read as owing the difference,
        // and Cloud's revenue was overstated by exactly the discount.
        //
        // Transaction for the reason #1270 records at OrderLifecycleController:
        // the refresh drags applyPricing → writeConditions behind it, and a
        // failure between them leaves totals that disagree with the conditions
        // that produced them.
        DB::transaction(function () use ($order, $patch, $discountChanged, $data): void {
            $order->update($patch);

            if ($discountChanged) {
                // A workstation shell may carry its frozen subtotal before the
                // item rows arrive. The discount helper owns exactly that split:
                // price a materialized cart, or update the ledger component +
                // total directly for a headless shell.
                $this->recalculateOrderTotalAfterDiscountChange($order, (float) $data['discount_amount']);
            }
        });

        return $order->fresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, float>  $authoritativePrices
     */
    public function transportWorkstationSyncItems(CustomerOrder $order, array $items, array $authoritativePrices): CustomerOrder
    {
        DB::transaction(function () use ($order, $items, $authoritativePrices) {
            // plan-051 — lines touched by this sync pass, queued for the same
            // add-time stock-deduction hook as the Cloud addItems funnel.
            $stockHookEntries = [];

            // #2411 — MỘT lô cho cả lượt sync, như `addItems`: 20 dòng thì một
            // truy vấn mặc định branch/brand chứ không phải 20.
            $taxBatch = $this->lineTaxPricing()->beginBatch();

            foreach ($items as $idx => $row) {
                $unitPrice = $authoritativePrices[$idx];
                $quantity = (int) $row['quantity'];
                $toppings = $row['toppings'] ?? [];

                // #2622 (tầng 1 của #2551) — máy trạm báo số đơn vị của CHÍNH
                // dòng này đã gửi bếp (order_items.printed_quantity, migration
                // 034 phía máy trạm). Payload THIẾU key ⇒ null: dòng mới nhận
                // default 0 của cột, dòng có sẵn GIỮ giá trị cũ — build máy
                // trạm cũ chưa gửi field không được phép reset số đã báo.
                // Giá trị dị dạng clamp về [0, quantity] thay vì 422: đường
                // transport này hội tụ chứ không từ chối (cùng trust model với
                // quantity/note trên chính payload), và một bookkeeping field
                // không được phép dead-letter cả lô item mang tiền.
                $printedQuantity = array_key_exists('printed_quantity', $row) && $row['printed_quantity'] !== null
                    ? max(0, min((int) $row['printed_quantity'], $quantity))
                    : null;

                $toppingSubtotal = 0.0;
                foreach ($toppings as $t) {
                    $toppingSubtotal += (float) ($t['unit_price'] ?? 0) * (int) ($t['quantity'] ?? 1);
                }
                $subtotal = $quantity * ($unitPrice + $toppingSubtotal);

                $existing = ! empty($row['id'])
                    ? CustomerOrderItem::find($row['id'])
                    : null;

                // #2200 — hai kiểm trên cùng một find(), trước khi update:
                //
                // (1) id thuộc ĐƠN KHÁC: find() không scope nên một payload dị
                //     dạng sửa được item của đơn khác (khác chi nhánh/tổ chức).
                //     404 như thể không tồn tại — không tiết lộ gì thêm.
                // (2) id là DÒNG HOÀN: upsert lật quantity/subtotal thành dương
                //     trong khi `refund_of_item_id` sống sót, nên
                //     `applyRefundLines` vẫn gộp nó như khoản hoàn ⇒ khoản hoàn
                //     bị tính thành khoản THU (đo: 330 → hoàn 1 → 220 → sync đè
                //     dòng hoàn ⇒ 390). Từ chối tường minh, không âm thầm bỏ
                //     qua — hội tụ về trạng thái sai tệ hơn là dừng và lộ ra.
                if ($existing !== null && (string) $existing->customer_order_id !== (string) $order->id) {
                    abort(response()->json([
                        'message' => 'Item does not belong to this order.',
                        'code' => 'ITEM_NOT_IN_ORDER',
                        'item_id' => $row['id'],
                    ], 404));
                }
                if ($existing !== null && $existing->refund_of_item_id !== null && $existing->refund_of_item_id !== '') {
                    abort(response()->json([
                        'message' => 'A refund line cannot be rewritten by sync-items. Refund lines are immutable ledger entries.',
                        'code' => 'CANNOT_MODIFY_REFUND_LINE',
                        'item_id' => $existing->id,
                        'refund_of_item_id' => $existing->refund_of_item_id,
                    ], 409));
                }

                if ($existing !== null) {
                    $previousQuantity = (float) $existing->quantity;
                    $existing->update(array_merge([
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'topping_subtotal' => $toppingSubtotal,
                        'subtotal' => $subtotal,
                        'note' => $row['note'] ?? $existing->note,
                    ], $printedQuantity !== null ? ['printed_quantity' => $printedQuantity] : []));
                    if ($toppings !== [] && $existing->orderItemToppings()->count() === 0) {
                        $this->transportWorkstationPersistToppings($existing->id, $toppings);
                    }
                    $stockHookEntries[] = ['item' => $existing->fresh(), 'previous_quantity' => $previousQuantity];

                    continue;
                }

                // #2411 — đóng dấu thuế NGAY lúc sinh dòng. `reResolveOrderLines`
                // ở cuối hàm vẫn chạy và vẫn cho cùng kết quả (cùng chuỗi tầng,
                // cùng lô memo), nhưng nó không được là chỗ DUY NHẤT đóng dấu:
                // một dòng đơn không có ảnh chụp thuế là dữ liệu hỏng ngay tại
                // lượt INSERT, không phải "chưa kịp cập nhật".
                $taxSnapshot = $this->bornLineTaxSnapshot($order, (string) $row['product_sku_id'], $taxBatch);

                $item = new CustomerOrderItem([
                    'customer_order_id' => $order->id,
                    'product_sku_id' => $row['product_sku_id'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    // #2618 — price_source CỐ Ý để NULL: unit_price của dòng
                    // sync-UP do workstation khai, engine Cloud không quyết giá
                    // này và payload không chở nguồn — đóng một trong bốn giá
                    // trị lên đây là snapshot bịa.
                    'original_unit_price' => $this->bornLineOriginalUnitPrice(
                        (float) $unitPrice,
                        isset($row['original_unit_price']) ? (float) $row['original_unit_price'] : null,
                    ),
                    'applied_promotion_id' => $row['applied_promotion_id'] ?? null,
                    'subtotal' => $subtotal,
                    'topping_subtotal' => $toppingSubtotal,
                    'status' => OrderItemStatusEnum::Pending->value,
                    'note' => $row['note'] ?? null,
                    'tax_type_id' => $taxSnapshot['tax_type_id'],
                    'tax_rate' => $taxSnapshot['tax_rate'],
                    'printed_quantity' => $printedQuantity ?? 0,
                ]);
                if (! empty($row['id'])) {
                    $item->id = $row['id'];
                }
                $item->save();

                if ($toppings !== []) {
                    $this->transportWorkstationPersistToppings($item->id, $toppings);
                }

                $stockHookEntries[] = ['item' => $item, 'previous_quantity' => null];
            }

            $order->unsetRelation('items');
            $this->reResolveOrderLines($order);
            $this->refreshOrderTotals($order);

            // plan-051 — on_add deduction for workstation-synced lines (they
            // are born pending, so the on_preparing half fires later from the
            // KDS-bump funnel). Ring-fenced inside.
            $this->applyStockDeductionAfterAdd($order, $stockHookEntries);
        });

        return $order->fresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $toppings
     */
    public function transportWorkstationPersistToppings(string $orderItemId, array $toppings): void
    {
        foreach ($toppings as $t) {
            $toppingGroupItemId = $t['topping_group_item_id'] ?? null;
            $productSkuId = $t['product_sku_id'] ?? null;

            // #962 · 7a-7 — qua cổng; hai `::exists()` cũ nay nằm ở Catalog.
            if (! $toppingGroupItemId || ! $productSkuId
                || ! $this->toppingSelections()->selectionExists((string) $toppingGroupItemId, (string) $productSkuId)) {
                Log::warning('workstation.addItems: skipped topping with unresolved reference', [
                    'order_item_id' => $orderItemId,
                    'topping_group_item_id' => $toppingGroupItemId,
                    'product_sku_id' => $productSkuId,
                ]);

                continue;
            }

            OrderItemTopping::create([
                'customer_order_item_id' => $orderItemId,
                'topping_group_item_id' => $toppingGroupItemId,
                'product_sku_id' => $productSkuId,
                'quantity' => (int) ($t['quantity'] ?? 1),
                'unit_price' => (float) ($t['unit_price'] ?? 0),
                // #2619 — transport row: the workstation applied free_up_to_n
                // locally; accept its waived count when the payload carries
                // one, same trust model as quantity/unit_price on this path.
                'waived_quantity' => (int) ($t['waived_quantity'] ?? 0),
                'note' => $t['note'] ?? null,
            ]);
        }
    }

    public function transportWorkstationPatchItem(CustomerOrderItem $item, array $patch): void
    {
        if ($patch === []) {
            return;
        }

        $order = CustomerOrder::findOrFail($item->customer_order_id);
        // Reuse the same authoritative mutation as the online POS route.
        // Cloud re-resolves SKU price/promotion/tax and topping snapshots;
        // it must never trust the workstation's cached monetary values.
        $this->updateItem($order, (string) $item->id, $patch);
    }

    /**
     * Workstation LAN replay sink — item removal is a void (no deleted_at on
     * customer_order_items). Matches #825: reason distinguishes delete vs void.
     */
    public function transportWorkstationSoftDeleteItem(CustomerOrderItem $item): void
    {
        $this->transportWorkstationVoidItem($item, 'deleted_by_workstation');
    }

    public function transportWorkstationVoidItem(CustomerOrderItem $item, string $reason, ?string $voidReasonId = null): void
    {
        // #2173 — đường replay KHÔNG đi qua `voidItem`, nên nó phải tự mang
        // guard. Đây là đường tới được mà KHÔNG cần shop bật gì:
        // `EloquentOrderPersistence::voidWorkstationItem` chỉ `findOrFail` rồi
        // gọi thẳng vào đây, không ma trận trạng thái, không guard trạng thái đơn.
        //
        // Từ chối chứ không "degrade cho sync hội tụ" như ca `void_reason_id`
        // ngay dưới: một id không giải được là THIẾU THÔNG TIN, còn void một
        // dòng hoàn là một thao tác KHÔNG HỢP LỆ. Hội tụ về một trạng thái sai
        // thì tệ hơn là dừng lại và lộ ra.
        $this->assertNotRefundLine($item);
        // #2200 — cùng nguyên tắc cho dòng GỐC đã có khoản hoàn phát hành:
        // đường replay cũng không được xoá bút toán mà bút toán đảo trỏ vào.
        $this->assertNotRefundedOrigin($item);

        $status = $item->status instanceof OrderItemStatusEnum
            ? $item->status->value
            : (string) $item->status;

        if ($status !== OrderItemStatusEnum::Voided->value) {
            $order = CustomerOrder::find($item->customer_order_id);

            // plan-051 — a sync-UP void MAY carry a VoidReason id (new
            // workstation builds). Sync must converge, so an id that doesn't
            // resolve (wrong brand, deactivated, unknown) degrades to the
            // legacy text-only void — unknown reason → no stock compensation
            // (compensateVoid warns) — instead of rejecting the replay.
            $voidReason = null;
            if ($voidReasonId !== null && $order !== null) {
                $voidReason = VoidReason::query()
                    ->whereKey($voidReasonId)
                    ->where('brand_id', $order->brand_id)
                    ->where('is_active', true)
                    ->first();

                if ($voidReason === null) {
                    Log::warning('plan-051: workstation void carried an unresolvable void_reason_id — treated as legacy text void', [
                        'item_id' => $item->id,
                        'void_reason_id' => $voidReasonId,
                    ]);
                }
            }

            $item->update([
                'status' => OrderItemStatusEnum::Voided->value,
                'voided_at' => now(),
                'void_reason' => $reason,
                'void_reason_id' => $voidReason?->id,
            ]);

            // #1294 — a settled order's total is a POSTED figure. A late replay
            // used to restate it: a closed sale ended up reading paid 1000
            // against a total of 0, with no refund and no 適格返還請求書 to
            // explain the difference.
            //
            // Two rules decide this, and they agree. Under 電子帳簿保存法 a
            // recorded transaction may not be altered without a retained
            // 訂正・削除の履歴, and under the 適格請求書 regime a billed amount is
            // corrected by ISSUING a return invoice, never by editing the
            // original downward. Ordinary accounting says the same thing:
            // posted entries are immutable, corrections are compensating
            // entries.
            //
            // So the line still voids — #825 requires the replay to land, or
            // the workstation's sync queue retries forever — but the money
            // stands, and the void is written to the audit trail instead of
            // silently changing a settled figure. Reversing the money, if that
            // is what the situation calls for, goes through the refund path
            // that raises a return invoice (#1123).
            $settled = $order !== null && in_array($this->resolveStatus($order), [
                CustomerOrderStatusEnum::Closed,
                CustomerOrderStatusEnum::Voided,
            ], true);

            if ($order !== null && ! $settled) {
                $this->refreshOrderTotals($order);
            }

            // The history half of the same rule — and it was missing entirely
            // on this path, while the shop's voidItem has always written it.
            $item->logAudit('item_voided', [
                'void_reason' => $reason,
                'void_reason_id' => $voidReason?->id,
                'origin' => 'workstation_sync',
                'order_settled' => $settled,
                'totals_restated' => ! $settled,
            ]);

            // plan-051 T2.4 — same compensation truth table as the Cloud void
            // path (a LAN-voided line may have been deducted at on_preparing /
            // on_add on Cloud). Ring-fenced: sync replay must never fail on an
            // inventory error.
            try {
                $this->stockDeduction()->compensateVoid((string) $item->id, $voidReason?->id);
            } catch (\Throwable $e) {
                Log::error('[inventory.stock_drift] plan-051: workstation void stock compensation failed — void preserved', [
                    'item_id' => $item->id,
                    'void_reason_id' => $voidReason?->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function transportWorkstationApplyCoupon(CustomerOrder $order, string $couponId, string $couponCode, float $discount): void
    {
        $order->update([
            'coupon_id' => $couponId,
            'coupon_code_snapshot' => $couponCode,
        ]);

        $this->recalculateOrderTotalAfterDiscountChange($order, $discount);
    }

    public function transportWorkstationReleaseCoupon(CustomerOrder $order): void
    {
        // #1288 — this used to null the columns by hand, which is a copy of
        // clearCouponFromOrder: the INNERMOST step of releasing a coupon, with
        // the half that concerns the coupon itself left out. CouponService's
        // releaseLocked does three things — decrement `times_used`, stamp
        // `released_at` on the redemption, then clear the binding — and the
        // shop's DELETE /pos/orders/{order}/coupon goes through all three.
        //
        // Doing only the third meant the order forgot the coupon while the
        // coupon did not forget the order: the guest lost a use permanently,
        // and because `released_at` was never stamped, no sweep could find it.
        //
        // releaseIfApplied returns early when there is no coupon, so the path
        // stays as lenient as sync-UP requires. Same call voidOrder makes.
        $this->couponService()->releaseIfApplied($order);
    }

    public function transportWorkstationGhostItem(CustomerOrder $order, string $itemId, array $snap): CustomerOrderItem
    {
        // #2411 — dòng ma cũng là một dòng bán được tính tiền: nó lên hoá đơn,
        // lên Z-report và lên parity Go y như dòng thường. Trước đây nó ra đời
        // KHÔNG có ảnh chụp thuế và không có bước nào sau đó đóng dấu hộ, nên nó
        // ở lại NULL vĩnh viễn — mà các đường tổng thuế DROP dòng NULL, tức món
        // đó được bán mà không nộp thuế.
        $taxSnapshot = $this->bornLineTaxSnapshot(
            $order,
            (string) $snap['product_sku_id'],
            $this->lineTaxPricing()->beginBatch(),
        );

        $ghost = new CustomerOrderItem([
            'customer_order_id' => $order->id,
            'product_sku_id' => $snap['product_sku_id'],
            'quantity' => (int) ($snap['quantity'] ?? 1),
            'unit_price' => (int) ($snap['unit_price'] ?? 0),
            // #2617 — the ghost snapshot carries no strikethrough (the KDS bump
            // payload has none), so the mandatory price-formation trace is the
            // unit price itself.
            // #2618 — price_source stays NULL on purpose: the ghost price is
            // device-claimed, the Cloud engine never resolved it, and stamping
            // one of the four sources here would be a fabricated snapshot.
            'original_unit_price' => $this->bornLineOriginalUnitPrice((float) ($snap['unit_price'] ?? 0), null),
            'subtotal' => (int) ($snap['quantity'] ?? 1) * (int) ($snap['unit_price'] ?? 0),
            'status' => OrderItemStatusEnum::Pending,
            'note' => $snap['note'] ?? null,
            'tax_type_id' => $taxSnapshot['tax_type_id'],
            'tax_rate' => $taxSnapshot['tax_rate'],
        ]);
        $ghost->id = $itemId;
        $ghost->save();

        return $ghost;
    }
}
