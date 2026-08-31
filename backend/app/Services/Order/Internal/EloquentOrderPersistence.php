<?php

namespace App\Services\Order\Internal;

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderItemTopping;
use App\Models\User;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Omnify\Enums\TableStatusEnum;
use App\Services\Customer\OrderClosingService;
use App\Services\Customer\OrderPricingCalculator;
use App\Services\Customer\SplitByItemsCalculator;
use App\Services\DomainMutation\MutationResult;
use App\Services\Order\Commands\AdvanceOrderItemKitchenCommand;
use App\Services\Order\Commands\ApplyOrderCouponCommand;
use App\Services\Order\Commands\ApplyWorkstationOrderCouponCommand;
use App\Services\Order\Commands\ApproveOrderItemRefundCommand;
use App\Services\Order\Commands\AssignOrderTableSessionCommand;
use App\Services\Order\Commands\BeginOrderPaymentCommand;
use App\Services\Order\Commands\BindOrderCouponCommand;
use App\Services\Order\Commands\BumpKitchenOrderItemStatusCommand;
use App\Services\Order\Commands\CancelOrderCommand;
use App\Services\Order\Commands\ChangeOrderSplitModeCommand;
use App\Services\Order\Commands\ChangeOrderTableCommand;
use App\Services\Order\Commands\CheckoutOrderCommand;
use App\Services\Order\Commands\CheckoutWorkstationOrderCommand;
use App\Services\Order\Commands\ClaimGuestOrdersCommand;
use App\Services\Order\Commands\CloseOrderCommand;
use App\Services\Order\Commands\CommitOrderConfirmationCommand;
use App\Services\Order\Commands\ConfirmOrderCommand;
use App\Services\Order\Commands\ContinueTableOrderCommand;
use App\Services\Order\Commands\DowngradeExclusivePromotionsCommand;
use App\Services\Order\Commands\ExpireOrderCommand;
use App\Services\Order\Commands\GhostCreateWorkstationOrderItemCommand;
use App\Services\Order\Commands\InitializeOrderCommand;
use App\Services\Order\Commands\MergeOrderTablesCommand;
use App\Services\Order\Commands\PatchWorkstationOrderCommand;
use App\Services\Order\Commands\PatchWorkstationOrderItemCommand;
use App\Services\Order\Commands\PersistOfflineReplayOrderCommand;
use App\Services\Order\Commands\PersistOnlineOrderCommand;
use App\Services\Order\Commands\PersistResolvedOrderItemsCommand;
use App\Services\Order\Commands\PromoteOrderForPaymentCommand;
use App\Services\Order\Commands\RefreshOrderPaymentCacheCommand;
use App\Services\Order\Commands\RefreshOrderPricingCommand;
use App\Services\Order\Commands\ReleaseWorkstationOrderCouponCommand;
use App\Services\Order\Commands\RemoveOrderCouponCommand;
use App\Services\Order\Commands\RemoveOrderItemCommand;
use App\Services\Order\Commands\ReopenOrderCommand;
use App\Services\Order\Commands\RevertOrderItemKitchenCommand;
use App\Services\Order\Commands\ReviseOrderHeaderCommand;
use App\Services\Order\Commands\SetStaffEditLockCommand;
use App\Services\Order\Commands\SettleOrderIfPaidCommand;
use App\Services\Order\Commands\SoftDeleteWorkstationOrderCommand;
use App\Services\Order\Commands\SoftDeleteWorkstationOrderItemCommand;
use App\Services\Order\Commands\StampKitchenItemTimestampCommand;
use App\Services\Order\Commands\StampOrderStripeIntentCommand;
use App\Services\Order\Commands\SyncWorkstationOrderItemsCommand;
use App\Services\Order\Commands\UnmergeOrderTableCommand;
use App\Services\Order\Commands\VoidAwaitingConfirmationOrderCommand;
use App\Services\Order\Commands\VoidOrderCommand;
use App\Services\Order\Commands\VoidOrderItemCommand;
use App\Services\Order\Commands\VoidWorkstationOrderCommand;
use App\Services\Order\Commands\VoidWorkstationOrderItemCommand;
use App\Services\Order\Contracts\OrderPersistencePort;
use App\Services\Order\Coupon\OrderCouponService;
use App\Services\Order\Enums\OrderChannel;
use App\Services\Order\Enums\OrderItemMutation;
use App\Services\Order\Internal\Concerns\WritesCustomerOrders;
use App\Services\Order\Results\OrderCreatedResult;
use App\Services\Order\Results\OrderSettlementResult;
use App\Services\Order\ValueObjects\OrderLineSelectionPayload;
use App\Services\Order\ValueObjects\TrustedOrderSnapshot;
use App\Services\Promotion\Contracts\MenuPromotionResolver;
use App\Support\CurrencyMinorUnit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/** Sole approved Order/OrderItem Eloquent mutation boundary for Plan 047. */
final class EloquentOrderPersistence implements OrderPersistencePort
{
    use WritesCustomerOrders {
        removeItem as protected writerRemoveItem;
        unmergeTable as protected writerUnmergeTable;
    }

    public function __construct(
        SplitByItemsCalculator $splitByItemsCalculator,
        OrderPricingCalculator $orderPricingCalculator,
        private readonly OrderClosingService $closingService,
    ) {
        $this->initializeOrderWriters($splitByItemsCalculator, $orderPricingCalculator);
    }

    public function insertResolvedOrder(PersistOnlineOrderCommand $command): OrderCreatedResult
    {
        $command->assertTrusted();

        return $this->persistTrustedSnapshot($command->resolved->snapshot);
    }

    /**
     * #1097 — an offline replay persists through the SAME funnel as an online
     * create. The only difference lives upstream: the snapshot was sealed by the
     * evidence verifier (signature + catalog revision) instead of the live
     * pricing resolver. Sharing the funnel is the point — order-code minting,
     * till stamping, table binding, the tax/rounding snapshots and the witness
     * re-check must not fork between the two paths, or an offline order would
     * land subtly different from the same basket sold online.
     */
    public function insertOfflineReplay(PersistOfflineReplayOrderCommand $command): OrderCreatedResult
    {
        $command->assertTrusted();

        // #1091 — an offline sale is dated by WHEN IT HAPPENED, not when it
        // reached Cloud. The instant comes from the signed evidence, so the
        // device cannot backdate a sale without breaking its own signature.
        return $this->persistTrustedSnapshot(
            $command->resolved->snapshot,
            $command->resolved->snapshot->soldAt,
            // plan-055 T5.1 (#1826) — stamp the order as offline-originated.
            // This line sits AFTER assertTrusted(), so it can only run once
            // OfflineOrderEvidenceVerifier has accepted the device signature:
            // Cloud is recording a fact it has verified, not repeating a claim
            // the device made. The payment path later waives the policy check
            // for orders carrying this stamp — money already in the till must
            // not be refused on a policy technicality when it syncs.
            offlineReplay: true,
        );
    }

    /**
     * @param  bool  $offlineReplay  plan-055 T5.1 — true ONLY from
     *                               insertOfflineReplay(). An explicit flag
     *                               rather than inferring from `$soldAt !== null`:
     *                               the online path must never be able to set
     *                               this stamp by accident, and a reader should
     *                               not have to derive intent from a date.
     */
    private function persistTrustedSnapshot(TrustedOrderSnapshot $snapshot, ?string $soldAt = null, bool $offlineReplay = false): OrderCreatedResult
    {
        $draft = $snapshot->draft;
        $exponent = CurrencyMinorUnit::exponent($snapshot->currencyCode);
        $fromMinor = static fn (int $minor): float => $minor / (10 ** $exponent);
        $toMinor = static fn (float $major): int => (int) round($major * (10 ** $exponent));

        return DB::transaction(function () use ($snapshot, $draft, $fromMinor, $toMinor, $soldAt, $offlineReplay): OrderCreatedResult {
            // Idempotent replays resolve to the existing row — same contract as
            // the legacy create() fast path, on both identity keys.
            if ($draft->clientOrderId !== null) {
                $existing = CustomerOrder::withTrashed()->where('client_order_id', $draft->clientOrderId)->first();
                if ($existing !== null) {
                    return new OrderCreatedResult((string) $existing->id, 1, $existing->items()->count());
                }
            }
            $existing = CustomerOrder::withTrashed()->find($snapshot->orderId);
            if ($existing !== null) {
                return new OrderCreatedResult((string) $existing->id, 1, $existing->items()->count());
            }

            // Tenant identity anchors on the resolved menu, matching the
            // resolver: the snapshot's line evidence names the menu, the menu
            // names the brand.
            $menuId = $draft->lines[0]->evidence?->menuId;
            // #962 · 7a-8 — qua cổng: "menu này thuộc brand nào" là câu hỏi của
            // Catalog, và đây là chỗ DUY NHẤT trong file cần model `Menu`.
            $brandId = $menuId !== null ? $this->catalogAnchors()->brandIdForMenu((string) $menuId) : null;
            if ($brandId === null) {
                throw new LogicException('Trusted order lines carry no resolvable menu brand; refusing to persist a brandless order.');
            }

            // insertOrder() is the single legacy creation funnel: order_code
            // minting, qr_token, till stamping, table assignment, and the
            // is_tax_included / rounding snapshots all happen there. The typed
            // path reuses it so none of those behaviours fork.
            // unguarded: `id` is mass-assignment-guarded on CustomerOrder, but
            // the snapshot's order id IS the command identity — the caller and
            // every downstream consumer already hold it. The id-equality check
            // below still verifies it landed.
            $order = CustomerOrder::unguarded(fn () => $this->insertOrder([
                'id' => $snapshot->orderId,
                // Minted here, not in insertOrder — the legacy create() mints it
                // before calling the funnel, so the funnel expects it present.
                'order_code' => $this->nextOrderCode(),
                'order_type' => $draft->orderType->value,
                'status' => $draft->status->value,
                // Null on the online path → the funnel defaults to now().
                'opened_at' => $soldAt,
                // Cloud's receipt instant, not the sale instant: the sale time
                // is `opened_at` above (signature-bound). Null on every online
                // order — NULL means "this order did not come from a replay".
                'offline_replayed_at' => $offlineReplay ? now() : null,
                'organization_id' => $snapshot->organizationId,
                'brand_id' => (string) $brandId,
                'branch_id' => $snapshot->branchId,
                'table_ids' => $draft->tableIds,
                'guest_count' => $draft->guestCount,
                'note' => $draft->note,
                'customer_id' => $draft->customerId,
                'client_order_id' => $draft->clientOrderId,
                'customer_locale' => $draft->locale->value,
                'pickup_type' => $draft->pickupType->value,
                'scheduled_pickup_time' => $draft->scheduledPickupAt,
                'customer_takeaway_name' => $draft->contact?->name,
                'customer_takeaway_phone' => $draft->contact?->phone,
                'customer_takeaway_email' => $draft->contact?->email,
                'split_mode' => $draft->splitMode?->value,
                'split_people_count' => $draft->splitPeopleCount,
                'is_tax_included' => $draft->pricingEvidence->taxIncluded,
                'tax_rounding_mode' => $draft->pricingEvidence->taxRoundingMode,
                'tax_rounding_decimals' => $draft->pricingEvidence->taxRoundingDecimals,
            ]));

            if ((string) $order->id !== $snapshot->orderId) {
                throw new LogicException('The persisted order did not keep the trusted snapshot order id; the id column is being dropped by mass assignment.');
            }

            foreach ($draft->lines as $line) {
                $evidence = $line->evidence;
                // Evidence carries LINE TOTALS (qty x per-unit); the DB columns
                // are per-unit. The resolver built toppingSubtotalMinor as
                // qty x toMinor(per-unit), so this division is exact.
                $perUnitToppingMinor = intdiv($evidence->toppingSubtotalMinor, $line->quantity);
                $perUnitTopping = $fromMinor($perUnitToppingMinor);
                $unitPrice = $fromMinor($line->unitPriceMinor);

                $item = $order->items()->create([
                    'id' => $line->itemId,
                    'product_sku_id' => $line->skuId,
                    'quantity' => $line->quantity,
                    'unit_price' => $unitPrice,
                    'topping_subtotal' => $perUnitTopping,
                    'subtotal' => $line->quantity * ($unitPrice + $perUnitTopping),
                    'status' => OrderItemStatusEnum::Pending->value,
                    'note' => $line->note,
                    // #2617 — evidence carries the strikethrough only when a
                    // promotion lowered the price; the trace itself is mandatory
                    // on every line, so an untouched price stamps unit_price.
                    // Covers BOTH evidence producers: the live resolver and the
                    // offline verifier (which always ships null here).
                    'original_unit_price' => $this->bornLineOriginalUnitPrice(
                        $unitPrice,
                        $evidence->originalUnitPriceMinor === null ? null : $fromMinor($evidence->originalUnitPriceMinor),
                    ),
                    // #2618 — the price SOURCE is resolved where the price is
                    // (live resolver / offline verifier); persistence only
                    // copies the stamped evidence.
                    'price_source' => $evidence->priceSource,
                    'applied_promotion_id' => $evidence->promotionId,
                    'applied_promotion_snapshot' => $this->promotionSnapshotFor($evidence->promotionId),
                    'tax_type_id' => $evidence->taxTypeId,
                    'tax_rate' => $evidence->taxRateBasisPoints / 100,
                    // tax_amount stays 0 here — refreshOrderTotals stamps the
                    // per-group allocation, exactly as the legacy path does.
                ]);

                // One row per chosen (ToppingGroupItem, ProductSku) tuple at
                // FULL unit price — group-strategy discounts live at line level
                // (DESIGN.md Decision 6), same as the legacy addItems persist.
                foreach ($line->toppings as $topping) {
                    OrderItemTopping::create([
                        'customer_order_item_id' => $item->id,
                        'topping_group_item_id' => $topping->toppingId,
                        'product_sku_id' => $topping->productSkuId,
                        'quantity' => $topping->quantity,
                        'unit_price' => $fromMinor($topping->unitPriceMinor),
                        // #2619 — per-row waiver trace from the same pricing
                        // run (see OrderToppingPayload::$waivedQuantity).
                        'waived_quantity' => $topping->waivedQuantity,
                        'note' => $topping->note,
                    ]);
                }
            }

            // THE WITNESS: recompute the totals through the battle-tested legacy
            // engine and require them to equal the snapshot's evidence to the
            // minor unit. A resolver drift becomes a loud rollback instead of a
            // wrong bill.
            $order = $this->reloadOrder((string) $order->id);
            $this->refreshOrderTotals($order);
            $order->refresh();

            $checks = [
                'subtotal' => [$toMinor((float) $order->subtotal), $draft->pricingEvidence->subtotalMinor],
                'service_charge' => [$toMinor((float) $order->service_charge), $draft->pricingEvidence->serviceChargeMinor],
                'tax' => [$toMinor((float) $order->tax_amount), $draft->pricingEvidence->taxMinor],
                'total' => [$toMinor((float) $order->total_amount), $draft->pricingEvidence->totalMinor],
            ];
            foreach ($checks as $field => [$persistedMinor, $resolvedMinor]) {
                if ($persistedMinor !== $resolvedMinor) {
                    throw new RuntimeException(sprintf(
                        'Typed/legacy pricing drift on %s for order %s: the trusted snapshot resolved %d but the legacy engine recomputed %d (off by %d minor units of %s). Rolling back — nobody gets billed off a disputed number.',
                        $field,
                        $snapshot->orderId,
                        $resolvedMinor,
                        $persistedMinor,
                        $persistedMinor - $resolvedMinor,
                        $snapshot->currencyCode,
                    ));
                }
            }

            // Quick order (default_order_item_status): the trusted snapshot
            // models RESOLUTION-time state, so lines are constructed pending —
            // but a quick-order branch persists them at its configured default
            // with served_at stamped, exactly as legacy addItems does. Applied
            // AFTER the witness because status never changes money.
            $defaultItemStatus = $this->resolveDefaultItemStatus($snapshot->branchId);
            if ($defaultItemStatus !== OrderItemStatusEnum::Pending->value) {
                $order->items()->update([
                    'status' => $defaultItemStatus,
                    'served_at' => $defaultItemStatus === OrderItemStatusEnum::Served->value ? now() : null,
                ]);
            }

            // Coupon LAST, after the witness has verified the pre-coupon
            // pricing — mirroring the legacy atomic create+coupon flow
            // (CustomerOrderController::store): CouponService::apply owns
            // freshness, counters, branch eligibility, stacking guards, and
            // the total recompute in BOTH engines, and an invalid code throws
            // here so the whole order rolls back rather than landing uncouponed.
            if ($draft->couponCode !== null) {
                app(OrderCouponService::class)->apply(
                    $order,
                    $draft->couponCode,
                    $draft->customerId,
                    match ($draft->channel) {
                        OrderChannel::CustomerWeb, OrderChannel::Api => 'customer_web',
                        default => $draft->channel->value,
                    },
                );
            }

            // plan-051 — birth-time stock deduction for the typed funnel:
            // on_add deducts every line at creation; on_preparing deducts the
            // lines BORN at/past preparing (quick-order default status — they
            // never see a pending→preparing transition). Runs LAST: stock never
            // changes money, so the witness above is unaffected. #1091 — an
            // offline replay carries the REAL sale instant ($soldAt, from the
            // signed evidence), which becomes stock_deducted_at; the online
            // path has no better instant than now(). Ring-fenced inside.
            $this->applyStockDeductionAfterAdd(
                $order,
                $order->items()->get()
                    ->map(static fn (CustomerOrderItem $line): array => ['item' => $line, 'previous_quantity' => null])
                    ->all(),
                $soldAt !== null ? Carbon::parse($soldAt) : null,
            );

            return new OrderCreatedResult((string) $order->id, 1, count($draft->lines));
        });
    }

    /**
     * #1597 — ảnh chụp khuyến mãi do PRICING dựng.
     *
     * Trước đây method này tự `MenuPromotion::query()->find()` rồi tự gộp bản
     * dịch — tức Ordering đọc model VÀ máy dịch của Pricing. Hình dạng mảng trả
     * về giữ nguyên từng khoá: nó đi thẳng vào cột JSON của dòng đơn, nên đổi
     * hình dạng là đổi dữ liệu đã lưu của mọi đơn cũ.
     *
     * @return array{name: array<string, string>, discount_percent: string, stacking_mode: string}|null
     */
    private function promotionSnapshotFor(?string $promotionId): ?array
    {
        return app(MenuPromotionResolver::class)->snapshotFor($promotionId);
    }

    public function markInitialized(InitializeOrderCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        // Pass the typed payload through — legacy initOrder owns the
        // first-write-wins semantics for both fields.
        $this->initOrder($order, array_filter([
            'table_ids' => $command->tableIds,
            'guest_count' => $command->guestCount,
        ], static fn ($v) => $v !== null && $v !== []));

        return new MutationResult($order->id, null, true);
    }

    public function markConfirmed(ConfirmOrderCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->confirmOrder($order);

        return new MutationResult($order->id, null, true);
    }

    public function markReopened(ReopenOrderCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->reopenOrder($order, $command->reason);

        return new MutationResult($order->id, null, true);
    }

    public function markConfirmationCommitted(CommitOrderConfirmationCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->commitAwaitingConfirmation($order);

        return new MutationResult($order->id, null, true);
    }

    public function markAwaitingConfirmationVoided(VoidAwaitingConfirmationOrderCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->voidAwaitingConfirmation($order, $command->reason);

        return new MutationResult($order->id, null, true);
    }

    public function claimGuestOrders(ClaimGuestOrdersCommand $command): MutationResult
    {
        $claimed = $this->claimGuestOrderRows($command->customerId, $command->orderIds);

        return new MutationResult($command->customerId, version: $claimed, changed: $claimed > 0);
    }

    public function applyStaffEditLock(SetStaffEditLockCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->setStaffEditLock($order, $command->lockedAt);

        return new MutationResult($order->id, null, true);
    }

    public function applyAssignTableSession(AssignOrderTableSessionCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->assignTableSession($order, $command->tableSessionId);

        return new MutationResult($order->id, null, true);
    }

    public function continueTableSession(ContinueTableOrderCommand $command): MutationResult
    {
        $payload = $command->payload;

        // Bridge to the legacy funnel so the #554 close-vs-void decision,
        // coupon release, table rebinding, and the addItems pricing recompute
        // keep their single writer. The typed command already verified the
        // tenant, the table set, and the selection fingerprint.
        $items = array_map(function (OrderLineSelectionPayload $line): array {
            // Legacy items are keyed by product_sku_id; a menu-anchored line
            // that omitted it resolves through its menu line deterministically.
            // #962 · 7a-8 — qua cổng; `require…` giữ nguyên 404 của `findOrFail`.
            $productSkuId = $line->productSkuId
                ?? $this->catalogAnchors()->requireProductSkuIdForMenuLine((string) $line->menuProductSkuId);

            return array_filter([
                'product_sku_id' => $productSkuId,
                'menu_product_sku_id' => $line->menuProductSkuId,
                'quantity' => $line->quantity,
                'note' => $line->note,
                'toppings' => array_map(static fn ($t): array => [
                    'topping_group_item_id' => $t->toppingId,
                    'product_sku_id' => $t->productSkuId,
                    'quantity' => $t->quantity,
                    'note' => $t->note,
                ], $line->toppings),
            ], static fn ($v) => $v !== null && $v !== []);
        }, $payload->lines);

        // Legacy create() fills nothing from the branch — the caller owns the
        // tenant columns. Organization + actor come from the verified context;
        // the brand is the branch's own (a branch belongs to exactly one brand).
        $branch = Branch::query()->findOrFail($command->branchId);
        $brandId = $branch->brand()->value('id');
        if ($brandId === null) {
            throw new LogicException("Branch {$command->branchId} has no brand; refusing to create a brandless order.");
        }

        $order = $this->continueTableOrder($command->branchId, array_filter([
            'table_ids' => $payload->tableIds,
            'order_type' => $payload->orderType->value,
            'customer_id' => $payload->customerId,
            'guest_count' => $payload->guestCount,
            'note' => $payload->note,
            'organization_id' => $command->context->organizationId,
            'brand_id' => (string) $brandId,
            'created_by_id' => $command->context->actorId,
            'items' => $items,
        ], static fn ($v) => $v !== null));

        return new MutationResult($order->id, null, true);
    }

    public function markExpired(ExpireOrderCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->expireOverdueTakeaway($order);

        return new MutationResult($order->id, null, true);
    }

    public function markClosed(CloseOrderCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->closingService->close($order);

        return new MutationResult($order->id, null, true);
    }

    public function approveItemRefund(ApproveOrderItemRefundCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->refundItem(
            $order,
            $command->itemId,
            $command->quantity,
            $command->reason,
            $command->refundLineId,
        );

        return new MutationResult($order->id, null, true);
    }

    public function removeItem(RemoveOrderItemCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->writerRemoveItem($order, $command->itemId);

        return new MutationResult($order->id, null, true);
    }

    public function revertKitchenItem(RevertOrderItemKitchenCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $item = $order->items()->where('id', $command->itemId)->firstOrFail();
        $this->updateItem($order, $item->id, [
            'status' => $command->targetStatus->value,
        ]);

        return new MutationResult($item->id, null, true);
    }

    public function applyItemChange(PersistResolvedOrderItemsCommand $command): MutationResult
    {
        $command->assertTrusted();
        $order = CustomerOrder::findOrFail($command->orderId);
        $line = $command->resolvedLine;

        switch ($command->operation) {
            case OrderItemMutation::Add:
                // Bridge to legacy addItems so BR-OI06 merge semantics, stock
                // checks, and the pricing recompute keep their single writer.
                // The typed resolution already validated the line (stale menu,
                // sellable, tenant anchor, coupon-stacking) with the SAME
                // components legacy re-uses, so re-resolution cannot disagree.
                $this->addItems($order, ['items' => [array_filter([
                    'product_sku_id' => $line->skuId,
                    'menu_product_sku_id' => $line->evidence?->menuProductSkuId,
                    'quantity' => $line->quantity,
                    'note' => $line->note,
                    'toppings' => array_map(static fn ($t): array => [
                        'topping_group_item_id' => $t->toppingId,
                        'product_sku_id' => $t->productSkuId,
                        'quantity' => $t->quantity,
                        'note' => $t->note,
                    ], $line->toppings),
                ], static fn ($v) => $v !== null && $v !== [])]]);
                break;

            case OrderItemMutation::Revise:
                if ($command->itemId === null) {
                    throw new InvalidArgumentException('Revising an order line requires the item id.');
                }
                $this->updateItem($order, $command->itemId, array_filter([
                    'quantity' => $line->quantity,
                    'note' => $line->note,
                ], static fn ($v) => $v !== null));
                break;

            default:
                throw new LogicException(
                    "Item mutation '{$command->operation->value}' has a dedicated command (void/refund) or is not ported yet — see #1090.",
                );
        }

        return new MutationResult($order->id, null, true);
    }

    public function reviseHeader(ReviseOrderHeaderCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $patch = $command->patch;
        $data = array_filter([
            'pickup_type' => $patch->pickupType?->value,
            'scheduled_pickup_at' => $patch->scheduledPickupAt,
            'customer_id' => $patch->customerId,
            'guest_count' => $patch->guestCount,
            'note' => $patch->note,
            // Legacy update() owns the tax re-resolve an order_type flip needs.
            'order_type' => $patch->orderType?->value,
        ], static fn ($value) => $value !== null);

        if ($patch->contact !== null) {
            $data['customer_name'] = $patch->contact->name;
            $data['customer_phone'] = $patch->contact->phone;
            $data['customer_email'] = $patch->contact->email;
        }

        $this->update($order, $data);

        return new MutationResult($order->id, null, true);
    }

    public function changeSplitMode(ChangeOrderSplitModeCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->setSplitModeFields(
            $order,
            $command->splitMode?->value ?? 'none',
            $command->peopleCount,
        );

        return new MutationResult($order->id, null, true);
    }

    public function bindCoupon(BindOrderCouponCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->bindCouponToOrder(
            $order,
            $command->couponId,
            $command->couponCode,
            $command->discountAmount,
        );

        return new MutationResult($order->id, null, true);
    }

    /**
     * Trả các dòng đang mang khuyến mãi ĐỘC QUYỀN về giá gốc (#1564).
     *
     * Chuyển từ `CouponService::downgradeExclusivePromotions()` sang đây. Ở chỗ
     * cũ, Pricing tự truy vấn `$order->items()`, tự `lockForUpdate()`, tự ghi
     * giá qua `app(EloquentOrderPersistence::class)->patchOrderItemUnchecked()`
     * và tự ghi audit lên order — bốn việc của Ordering làm từ ngoài module,
     * đi vòng qua `OrderMutationFacade`, trên đường tiền.
     *
     * Hành vi giữ NGUYÊN từng bước, kể cả nhánh phòng thủ: dòng bị đánh dấu có
     * khuyến mãi mà thiếu `original_unit_price` thì **bỏ qua**, không hạ giá về
     * 0. Đó là lựa chọn cũ và nó đúng — mất giá gốc thì không có gì để khôi
     * phục an toàn. (#2617 đổi MỘT bước: sau khi khôi phục giá, cột không bị
     * null nữa mà giữ `original == unit` — dấu vết định hình giá là bắt buộc
     * trên mọi dòng.)
     */
    public function downgradeExclusivePromotions(DowngradeExclusivePromotionsCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);

        $affected = $order->items()
            ->where('status', '!=', OrderItemStatusEnum::Voided->value)
            ->whereNotNull('applied_promotion_id')
            ->whereHas('appliedPromotion', function ($q) {
                $q->where('stacking_mode', 'exclusive_with_coupons');
            })
            ->lockForUpdate()
            ->get();

        foreach ($affected as $item) {
            $originalPrice = $item->original_unit_price;
            if ($originalPrice === null) {
                continue;
            }
            $snapshot = $item->applied_promotion_snapshot;
            $promotionId = $item->applied_promotion_id;

            $this->patchOrderItemUnchecked($item, [
                'unit_price' => $originalPrice,
                // #2617 — trước đây nulled; dấu vết định hình giá là bắt buộc
                // trên mọi dòng, nên sau khi hạ khuyến mãi original == unit
                // (độ sâu giảm giá của dòng về 0). Hai reader báo cáo khuyến
                // mãi đều scope theo applied_promotion_id nên dòng này vẫn rời
                // khỏi báo cáo như cũ.
                'original_unit_price' => $originalPrice,
                'applied_promotion_id' => null,
                'applied_promotion_snapshot' => null,
            ]);

            $order->logAudit('promotion_downgraded', [
                'order_item_id' => $item->id,
                'promotion_id' => $promotionId,
                'promotion_snapshot' => $snapshot,
                'reason' => 'coupon_apply_downgrade',
                'restored_unit_price' => (string) $originalPrice,
                'user_id' => $command->userId,
            ]);
        }

        return new MutationResult($order->id, null, true);
    }

    public function applyCoupon(ApplyOrderCouponCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);

        // CouponService::apply is the legacy single writer: modifiability
        // gate, freshness/caps/branch eligibility, redemption row, discount
        // recompute, and the plan-019 exclusive-promotion downgrade.
        $this->couponService()->apply(
            order: $order,
            code: $command->couponCode,
            customerId: $command->customerId,
            via: $command->via,
            user: $command->context->actorId === null ? null : User::find($command->context->actorId),
            downgradeExclusivePromotions: $command->downgradeExclusivePromotions,
        );

        return new MutationResult($order->id, null, true);
    }

    public function removeCoupon(RemoveOrderCouponCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->clearCouponFromOrder($order);

        return new MutationResult($order->id, null, true);
    }

    public function refreshPricing(RefreshOrderPricingCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        // #2041 đổi tên `recalculateOrderTotalAfterCouponChange` thành
        // `…AfterDiscountChange` và sửa hai nơi gọi trong `WritesCustomerOrders`,
        // nhưng bỏ sót chỗ này — nó ở file khác. Tính lại giá KHÔNG đổi khoản
        // giảm, nên truyền lại chính khoản giảm hiệu lực đang có.
        $this->recalculateOrderTotalAfterDiscountChange($order, (float) $order->discount_amount);

        return new MutationResult($order->id, null, true);
    }

    public function advanceKitchenItem(AdvanceOrderItemKitchenCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->updateItem($order, $command->itemId, [
            'status' => $command->status->value,
        ]);

        return new MutationResult($command->itemId, null, true);
    }

    public function voidKitchenItem(VoidOrderItemCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->voidItem($order, $command->itemId, [
            'void_reason' => $command->reason,
        ]);

        return new MutationResult($command->itemId, null, true);
    }

    public function markCheckedOut(CheckoutOrderCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        // Null discount keeps the order's current value — same default the
        // legacy array path applies. #1124 — the reason + the acting user ride
        // along so the writer can enforce reason-required and the role cap.
        $data = $command->discountAmount === null ? [] : ['discount_amount' => $command->discountAmount];
        if ($command->discountReason !== null) {
            $data['discount_reason'] = $command->discountReason;
        }
        if ($command->context->actorId !== null) {
            $data['actor_user_id'] = $command->context->actorId;
        }
        $this->checkout($order, $data);

        return new MutationResult($order->id, null, true);
    }

    public function markPromotedForPayment(PromoteOrderForPaymentCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->promoteForPayment($order);

        return new MutationResult($order->id, null, true);
    }

    public function markPaying(BeginOrderPaymentCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->beginPaying($order);

        return new MutationResult($order->id, null, true);
    }

    public function stampStripePaymentIntent(StampOrderStripeIntentCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->stampStripeIntent($order, $command->paymentIntentId);

        return new MutationResult($order->id, null, true);
    }

    public function refreshPaymentCache(RefreshOrderPaymentCacheCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->refreshPaymentCacheFromLedger($order);

        return new MutationResult($order->id, null, true);
    }

    public function markCanceled(CancelOrderCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->cancel($order, $command->reason);

        return new MutationResult($order->id, null, true);
    }

    public function markVoided(VoidOrderCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->voidOrder($order, [
            'void_reason' => $command->reason,
            'void_reason_id' => $command->reasonId,
        ]);

        return new MutationResult($order->id, null, true);
    }

    public function replaceTableAssociation(ChangeOrderTableCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        // Same lifecycle gate as merge/unmerge: tables only move while open.
        $this->assertStatus($order, [CustomerOrderStatusEnum::Open], 'change table');

        return DB::transaction(function () use ($order, $command): MutationResult {
            $target = $command->payload->tableIds;
            $current = $this->tables()->idsHeldBy($order->id);

            // The command models the RESULTING table set (like mergeTables) —
            // a replay with the already-bound set is a no-op, not a 409.
            if (array_diff($current, $target) === [] && array_diff($target, $current) === []) {
                return new MutationResult($order->id, null, false);
            }

            // Release tables leaving the set. The dine-in "never zero tables"
            // invariant holds on the RESULT: the payload guarantees >=1 table.
            $released = array_values(array_diff($current, $target));
            if ($released !== []) {
                $this->tables()->releaseByIds($released, $order->id);
            }

            // Bind the newcomers with the exact mergeTable() occupancy rules,
            // branch-scoped so a foreign tenant's table id 404s.
            foreach (array_diff($target, $current) as $tableId) {
                $table = $this->tables()->lockInBranch($order->branch_id, $tableId);

                if ($table->isHeld()) {
                    abort(409, 'Table is already occupied by another order.');
                }

                $tableStatus = TableStatusEnum::tryFrom((string) $table->status);
                if (! in_array($tableStatus, [TableStatusEnum::Free, TableStatusEnum::Reserved], true)) {
                    abort(422, 'Table must be free or reserved to merge.');
                }

                $this->tables()->assign([$tableId], $order->id);
            }

            // Keep the denormalized primary-table pointer inside the new set
            // (continue-table and the table_id filters resolve through it).
            if (! in_array((string) $order->table_id, $target, true)) {
                $order->forceFill(['table_id' => $target[0]])->save();
            }

            if ($command->payload->tableSessionId !== null) {
                $this->assignTableSession($order, $command->payload->tableSessionId);
            }

            $order->logAudit('table_changed', [
                'released_table_ids' => $released,
                'table_ids' => $target,
            ]);

            return new MutationResult($order->id, null, true);
        });
    }

    public function mergeTables(MergeOrderTablesCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        // The command models the RESULTING table set, so ids already bound to
        // THIS order are no-ops rather than 409s — that keeps the typed call
        // idempotent on replay. A table occupied by ANOTHER order still 409s
        // inside mergeTable, exactly as the legacy path does.
        $alreadyBound = $order->tables()->pluck('id')->map(fn ($id) => (string) $id)->all();
        foreach ($command->tables->tableIds as $tableId) {
            if (in_array((string) $tableId, $alreadyBound, true)) {
                continue;
            }
            $this->mergeTable($order, $tableId);
            $order = CustomerOrder::findOrFail($order->id);
        }

        return new MutationResult($order->id, null, true);
    }

    public function unmergeTable(UnmergeOrderTableCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->writerUnmergeTable($order, $command->tableId);

        return new MutationResult($order->id, null, true);
    }

    public function markSettled(SettleOrderIfPaidCommand $command): OrderSettlementResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);

        if (! OrderClosingService::isPaidEnough($order)) {
            $currency = (string) ($order->branch?->currency ?? 'JPY');

            return new OrderSettlementResult(
                $order->id,
                version: 1,
                settled: false,
                settledAmountMinor: CurrencyMinorUnit::fromMajor((string) $order->paid_amount, $currency) ?? 0,
                currencyCode: $currency,
            );
        }

        $closed = $this->closingService->close($order);
        $currency = (string) ($closed->branch?->currency ?? 'JPY');

        return new OrderSettlementResult(
            $closed->id,
            version: 1,
            settled: true,
            settledAmountMinor: CurrencyMinorUnit::fromMajor((string) $closed->paid_amount, $currency) ?? 0,
            currencyCode: $currency,
        );
    }

    public function patchWorkstationOrder(PatchWorkstationOrderCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->transportWorkstationPatchOrder($order, $command->patch);

        return new MutationResult($order->id, null, true);
    }

    public function softDeleteWorkstationOrder(SoftDeleteWorkstationOrderCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->transportWorkstationSoftDelete($order);

        return new MutationResult($order->id, null, true);
    }

    public function voidWorkstationOrder(VoidWorkstationOrderCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->transportWorkstationVoid($order, $command->reason);

        return new MutationResult($order->id, null, true);
    }

    public function checkoutWorkstationOrder(CheckoutWorkstationOrderCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $data = $command->discountAmount !== null ? ['discount_amount' => $command->discountAmount] : [];
        $this->transportWorkstationCheckout($order, $data);

        return new MutationResult($order->id, null, true);
    }

    public function syncWorkstationItems(SyncWorkstationOrderItemsCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->transportWorkstationSyncItems($order, $command->items, $command->authoritativePrices);

        return new MutationResult($order->id, null, true);
    }

    public function patchWorkstationItem(PatchWorkstationOrderItemCommand $command): MutationResult
    {
        $item = CustomerOrderItem::where('customer_order_id', $command->orderId)->findOrFail($command->itemId);
        $this->transportWorkstationPatchItem($item, $command->patch);

        return new MutationResult($item->id, null, true);
    }

    public function softDeleteWorkstationItem(SoftDeleteWorkstationOrderItemCommand $command): MutationResult
    {
        $item = CustomerOrderItem::where('customer_order_id', $command->orderId)->findOrFail($command->itemId);
        $this->transportWorkstationSoftDeleteItem($item);

        return new MutationResult($item->id, null, true);
    }

    public function voidWorkstationItem(VoidWorkstationOrderItemCommand $command): MutationResult
    {
        $item = CustomerOrderItem::where('customer_order_id', $command->orderId)->findOrFail($command->itemId);
        $this->transportWorkstationVoidItem($item, $command->reason, $command->voidReasonId);

        return new MutationResult($item->id, null, true);
    }

    public function applyWorkstationCoupon(ApplyWorkstationOrderCouponCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->transportWorkstationApplyCoupon(
            $order,
            $command->couponId,
            $command->couponCode,
            $command->discountAmount,
        );

        return new MutationResult($order->id, null, true);
    }

    public function releaseWorkstationCoupon(ReleaseWorkstationOrderCouponCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $this->transportWorkstationReleaseCoupon($order);

        return new MutationResult($order->id, null, true);
    }

    public function ghostCreateWorkstationItem(GhostCreateWorkstationOrderItemCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $item = $this->transportWorkstationGhostItem($order, $command->itemId, $command->snapshot);

        return new MutationResult($item->id, null, true);
    }

    public function bumpKitchenItemStatus(BumpKitchenOrderItemStatusCommand $command): MutationResult
    {
        $order = CustomerOrder::findOrFail($command->orderId);
        $item = $this->updateItem($order, $command->itemId, [
            'status' => $command->status,
            '_idempotency_key' => $command->idempotencyKey,
        ]);

        return new MutationResult($item->id, null, true);
    }

    public function stampKitchenTimestamp(StampKitchenItemTimestampCommand $command): MutationResult
    {
        $item = CustomerOrderItem::where('customer_order_id', $command->orderId)->findOrFail($command->itemId);
        $this->stampKitchenTimestampIfNull($item, $command->column);

        return new MutationResult($item->id, null, true);
    }

    // -------------------------------------------------------------------------
    // Array transport (CustomerOrderService / tests) — remove in Gate 4.
    //
    // `transport*` = the array-shaped surface, as opposed to the typed Command
    // methods above. Not a compatibility branch for old DATA (#2188): it is the
    // door every kiosk and customer-web order still walks through today.
    // -------------------------------------------------------------------------

    public function transportCreate(array $data): CustomerOrder
    {
        return $this->create($data);
    }

    public function transportRemoveItem(CustomerOrder $order, string $itemId): void
    {
        $this->writerRemoveItem($order, $itemId);
    }

    public function transportUnmergeTable(CustomerOrder $order, string $tableId): CustomerOrder
    {
        return $this->writerUnmergeTable($order, $tableId);
    }
}
