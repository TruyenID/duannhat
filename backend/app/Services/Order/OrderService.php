<?php

namespace App\Services\Order;

use App\Events\Order\OrderMutated;
use App\Services\DomainMutation\MutationContext;
use App\Services\DomainMutation\MutationResult;
use App\Services\DomainMutation\VerificationAuthority;
use App\Services\Order\Commands\AdvanceOrderItemKitchenCommand;
use App\Services\Order\Commands\ApplyOrderCouponCommand;
use App\Services\Order\Commands\ApplyWorkstationOrderCouponCommand;
use App\Services\Order\Commands\ApproveOrderItemRefundCommand;
use App\Services\Order\Commands\AssignOrderTableSessionCommand;
use App\Services\Order\Commands\BeginOrderPaymentCommand;
use App\Services\Order\Commands\BindOrderCouponCommand;
use App\Services\Order\Commands\BumpKitchenOrderItemStatusCommand;
use App\Services\Order\Commands\CancelOrderCommand;
use App\Services\Order\Commands\ChangeOrderItemsBatchCommand;
use App\Services\Order\Commands\ChangeOrderItemsCommand;
use App\Services\Order\Commands\ChangeOrderSplitModeCommand;
use App\Services\Order\Commands\ChangeOrderTableCommand;
use App\Services\Order\Commands\CheckoutOrderCommand;
use App\Services\Order\Commands\CheckoutWorkstationOrderCommand;
use App\Services\Order\Commands\ClaimGuestOrdersCommand;
use App\Services\Order\Commands\CloseOrderCommand;
use App\Services\Order\Commands\CommitOrderConfirmationCommand;
use App\Services\Order\Commands\ConfirmOrderCommand;
use App\Services\Order\Commands\ContinueTableOrderCommand;
use App\Services\Order\Commands\CreateOrderCommand;
use App\Services\Order\Commands\DowngradeExclusivePromotionsCommand;
use App\Services\Order\Commands\ExpireOrderCommand;
use App\Services\Order\Commands\GhostCreateWorkstationOrderItemCommand;
use App\Services\Order\Commands\InitializeOrderCommand;
use App\Services\Order\Commands\MergeOrderTablesCommand;
use App\Services\Order\Commands\PatchWorkstationOrderCommand;
use App\Services\Order\Commands\PatchWorkstationOrderItemCommand;
use App\Services\Order\Commands\PersistOfflineReplayOrderCommand;
use App\Services\Order\Commands\PersistOnlineOrderCommand;
use App\Services\Order\Commands\PersistResolvedOrderCommand;
use App\Services\Order\Commands\PersistResolvedOrderItemsCommand;
use App\Services\Order\Commands\PromoteOrderForPaymentCommand;
use App\Services\Order\Commands\RefreshOrderPaymentCacheCommand;
use App\Services\Order\Commands\RefreshOrderPricingCommand;
use App\Services\Order\Commands\ReleaseWorkstationOrderCouponCommand;
use App\Services\Order\Commands\RemoveOrderCouponCommand;
use App\Services\Order\Commands\RemoveOrderItemCommand;
use App\Services\Order\Commands\ReopenOrderCommand;
use App\Services\Order\Commands\ReplayOfflineOrderCommand;
use App\Services\Order\Commands\RevertOrderItemKitchenCommand;
use App\Services\Order\Commands\ReviseOrderHeaderCommand;
use App\Services\Order\Commands\RunKitchenBatchCommand;
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
use App\Services\Order\Contracts\OrderEvidenceVerificationPort;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Contracts\OrderPricingResolutionPort;
use App\Services\Order\Internal\EloquentOrderPersistence;
use App\Services\Order\Results\OrderCreatedResult;
use App\Services\Order\Results\OrderMutationBatchResult;
use App\Services\Order\Results\OrderSettlementResult;
use Illuminate\Support\Facades\DB;

/**
 * Sole public Order mutation facade.
 *
 * All consumers mutate Order aggregates through typed commands on this facade.
 */
final class OrderService implements OrderMutationFacade
{
    public function __construct(
        private readonly EloquentOrderPersistence $persistence,
        private readonly OrderPricingResolutionPort $pricing,
        private readonly OrderEvidenceVerificationPort $evidenceVerifier,
    ) {}

    public function create(CreateOrderCommand $command): OrderCreatedResult
    {
        $snapshot = $this->pricing->resolveOrder($command);
        $resolved = PersistResolvedOrderCommand::fromTrustedSnapshot(
            $command->context,
            $command->orderId,
            $command->branchId,
            $snapshot,
            $snapshot->fingerprint(),
        );

        return $this->emit('create', $command->context, $this->persistence->insertResolvedOrder(
            PersistOnlineOrderCommand::fromResolved($resolved),
        ));
    }

    public function replayOffline(ReplayOfflineOrderCommand $command): OrderCreatedResult
    {
        // #1097 — the verifier is the ONLY thing that can turn signed device
        // evidence into a trusted snapshot; it throws OfflineEvidenceRejected
        // on any failed check, so nothing below runs on unverified money.
        $snapshot = $this->evidenceVerifier->verifyOfflineReplay($command);

        $resolved = PersistResolvedOrderCommand::fromTrustedSnapshot(
            $command->context,
            $command->orderId,
            $command->branchId,
            $snapshot,
            $snapshot->fingerprint(),
        );

        return $this->emit('replayOffline', $command->context, $this->persistence->insertOfflineReplay(
            PersistOfflineReplayOrderCommand::fromResolved($resolved),
        ));
    }

    public function initialize(InitializeOrderCommand $command): MutationResult
    {
        return $this->emit('initialize', $command->context, $this->persistence->markInitialized($command));
    }

    public function confirm(ConfirmOrderCommand $command): MutationResult
    {
        return $this->emit('confirm', $command->context, $this->persistence->markConfirmed($command));
    }

    public function reopen(ReopenOrderCommand $command): MutationResult
    {
        return $this->emit('reopen', $command->context, $this->persistence->markReopened($command));
    }

    public function commitConfirmation(CommitOrderConfirmationCommand $command): MutationResult
    {
        return $this->emit('commitConfirmation', $command->context, $this->persistence->markConfirmationCommitted($command));
    }

    public function voidAwaitingConfirmation(VoidAwaitingConfirmationOrderCommand $command): MutationResult
    {
        return $this->emit('voidAwaitingConfirmation', $command->context, $this->persistence->markAwaitingConfirmationVoided($command));
    }

    public function claimGuestOrders(ClaimGuestOrdersCommand $command): MutationResult
    {
        return $this->emit('claimGuestOrders', $command->context, $this->persistence->claimGuestOrders($command));
    }

    public function setStaffEditLock(SetStaffEditLockCommand $command): MutationResult
    {
        return $this->emit('setStaffEditLock', $command->context, $this->persistence->applyStaffEditLock($command));
    }

    public function assignTableSession(AssignOrderTableSessionCommand $command): MutationResult
    {
        return $this->emit('assignTableSession', $command->context, $this->persistence->applyAssignTableSession($command));
    }

    public function continueTable(ContinueTableOrderCommand $command): MutationResult
    {
        return $this->emit('continueTable', $command->context, $this->persistence->continueTableSession($command));
    }

    public function expire(ExpireOrderCommand $command): MutationResult
    {
        return $this->emit('expire', $command->context, $this->persistence->markExpired($command));
    }

    public function close(CloseOrderCommand $command): MutationResult
    {
        return $this->emit('close', $command->context, $this->persistence->markClosed($command));
    }

    public function approveItemRefund(ApproveOrderItemRefundCommand $command): MutationResult
    {
        return $this->emit('approveItemRefund', $command->context, $this->persistence->approveItemRefund($command));
    }

    public function removeItem(RemoveOrderItemCommand $command): MutationResult
    {
        return $this->emit('removeItem', $command->context, $this->persistence->removeItem($command));
    }

    public function revertKitchenItem(RevertOrderItemKitchenCommand $command): MutationResult
    {
        return $this->emit('revertKitchenItem', $command->context, $this->persistence->revertKitchenItem($command));
    }

    /**
     * Apply several line changes as ONE all-or-nothing batch (#1666).
     *
     * `Handy::addItems` and `Shop\CustomerOrderController::addItem` both looped
     * `changeItems()` inside their own `DB::transaction`, with the same comment
     * explaining why: a failing line must roll back every line, matching the
     * legacy batch contract. Two copies of a consistency boundary is two places
     * for it to be dropped — and a controller is not where a caller should have
     * to know that adding three items is one act.
     *
     * @param  list<ChangeOrderItemsCommand>  $commands
     * @return list<MutationResult> in the order given
     */
    public function changeItemsBatch(ChangeOrderItemsBatchCommand $command): OrderMutationBatchResult
    {
        return DB::transaction(function () use ($command): OrderMutationBatchResult {
            $results = array_map(
                fn (ChangeOrderItemsCommand $c): MutationResult => $this->changeItems($c),
                $command->commands,
            );

            // The batch announces ITSELF on top of the per-line events, because
            // the transaction boundary is a fact a listener may care about:
            // these three lines were added as ONE act, not three that happened
            // to be close together. Emitted last so a subscriber sees the parts
            // before the whole.
            return $this->emit('changeItemsBatch', $command->context, new OrderMutationBatchResult(
                $command->commands[0]->orderId,
                $results,
            ));
        });
    }

    /**
     * Apply a run of kitchen commands as ONE batch (#1666, `Kds::bumpAll`).
     *
     * Mixed on purpose: a bump-all is a status bump per item PLUS the
     * first-write-wins timestamp stamps that go with it, and half of that
     * applied is a ticket whose status and timestamps disagree. The caller
     * decides WHICH commands belong in the run — it is the side holding the
     * loaded items and can see whether a timestamp is already set.
     *
     * @param  list<BumpKitchenOrderItemStatusCommand|StampKitchenItemTimestampCommand>  $commands
     */
    public function runKitchenBatch(RunKitchenBatchCommand $command): OrderMutationBatchResult
    {
        return DB::transaction(function () use ($command): OrderMutationBatchResult {
            $results = [];

            foreach ($command->commands as $step) {
                $results[] = $step instanceof BumpKitchenOrderItemStatusCommand
                    ? $this->bumpKitchenItemStatus($step)
                    : $this->stampKitchenTimestamp($step);
            }

            // Same reason as `changeItemsBatch`: a bump-all is one act at the
            // pass, and the per-item events alone cannot tell a listener where
            // one bump-all ended and the next began.
            return $this->emit('runKitchenBatch', $command->context, new OrderMutationBatchResult(
                $command->commands[0]->orderId,
                $results,
            ));
        });
    }

    public function changeItems(ChangeOrderItemsCommand $command): MutationResult
    {
        // Resolve the single line against the shared menu/promotion/topping/tax
        // engine, then hand the SEALED result to persistence. The seal proves
        // the payload came from the configured resolver, not a request body.
        $line = $this->pricing->resolveLine($command);

        return $this->emit('changeItems', $command->context, $this->persistence->applyItemChange(PersistResolvedOrderItemsCommand::fromPricingResolver(
            $this->pricing,
            VerificationAuthority::forConfiguredAdapter(
                $this->pricing,
                OrderPricingResolutionPort::class,
                ['order.persist_resolved_items'],
            ),
            $command->context,
            $command->orderId,
            $command->operation,
            $line,
            $line->fingerprint(),
            $command->itemId,
        )));
    }

    public function reviseHeader(ReviseOrderHeaderCommand $command): MutationResult
    {
        return $this->emit('reviseHeader', $command->context, $this->persistence->reviseHeader($command));
    }

    public function changeSplitMode(ChangeOrderSplitModeCommand $command): MutationResult
    {
        return $this->emit('changeSplitMode', $command->context, $this->persistence->changeSplitMode($command));
    }

    public function bindCoupon(BindOrderCouponCommand $command): MutationResult
    {
        return $this->emit('bindCoupon', $command->context, $this->persistence->bindCoupon($command));
    }

    public function applyCoupon(ApplyOrderCouponCommand $command): MutationResult
    {
        return $this->emit('applyCoupon', $command->context, $this->persistence->applyCoupon($command));
    }

    public function removeCoupon(RemoveOrderCouponCommand $command): MutationResult
    {
        return $this->emit('removeCoupon', $command->context, $this->persistence->removeCoupon($command));
    }

    public function downgradeExclusivePromotions(DowngradeExclusivePromotionsCommand $command): MutationResult
    {
        return $this->emit('downgradeExclusivePromotions', $command->context,
            $this->persistence->downgradeExclusivePromotions($command));
    }

    public function refreshPricing(RefreshOrderPricingCommand $command): MutationResult
    {
        return $this->emit('refreshPricing', $command->context, $this->persistence->refreshPricing($command));
    }

    public function advanceKitchenItem(AdvanceOrderItemKitchenCommand $command): MutationResult
    {
        return $this->emit('advanceKitchenItem', $command->context, $this->persistence->advanceKitchenItem($command));
    }

    public function voidKitchenItem(VoidOrderItemCommand $command): MutationResult
    {
        return $this->emit('voidKitchenItem', $command->context, $this->persistence->voidKitchenItem($command));
    }

    public function checkout(CheckoutOrderCommand $command): MutationResult
    {
        return $this->emit('checkout', $command->context, $this->persistence->markCheckedOut($command));
    }

    public function promoteForPayment(PromoteOrderForPaymentCommand $command): MutationResult
    {
        return $this->emit('promoteForPayment', $command->context, $this->persistence->markPromotedForPayment($command));
    }

    public function beginPaying(BeginOrderPaymentCommand $command): MutationResult
    {
        return $this->emit('beginPaying', $command->context, $this->persistence->markPaying($command));
    }

    public function stampStripeIntent(StampOrderStripeIntentCommand $command): MutationResult
    {
        return $this->emit('stampStripeIntent', $command->context, $this->persistence->stampStripePaymentIntent($command));
    }

    public function refreshPaymentCache(RefreshOrderPaymentCacheCommand $command): MutationResult
    {
        return $this->emit('refreshPaymentCache', $command->context, $this->persistence->refreshPaymentCache($command));
    }

    public function cancel(CancelOrderCommand $command): MutationResult
    {
        return $this->emit('cancel', $command->context, $this->persistence->markCanceled($command));
    }

    public function void(VoidOrderCommand $command): MutationResult
    {
        return $this->emit('void', $command->context, $this->persistence->markVoided($command));
    }

    public function changeTable(ChangeOrderTableCommand $command): MutationResult
    {
        return $this->emit('changeTable', $command->context, $this->persistence->replaceTableAssociation($command));
    }

    public function mergeTables(MergeOrderTablesCommand $command): MutationResult
    {
        return $this->emit('mergeTables', $command->context, $this->persistence->mergeTables($command));
    }

    public function unmergeTable(UnmergeOrderTableCommand $command): MutationResult
    {
        return $this->emit('unmergeTable', $command->context, $this->persistence->unmergeTable($command));
    }

    /**
     * The patch is one write, but it drags refreshOrderTotals → recalculateTotals
     * → applyPricing → writeConditions behind it, and `order_conditions` is where
     * the order's pricing rules live — so a failure between them leaves totals
     * that disagree with the conditions that produced them (#1270).
     *
     * The transaction lives here rather than at the caller (#1666): a consistency
     * boundary is a property of the mutation, not of the HTTP surface that
     * happens to invoke it. `emit()` stays inside it, exactly as it was when
     * `OrderLifecycleController` owned the wrapper.
     */
    public function patchWorkstationOrder(PatchWorkstationOrderCommand $command): MutationResult
    {
        // Kept on ONE line on purpose: `OrderMutationEventsTest` proves every
        // facade command announces itself by grepping for the literal
        // `$this->emit('<name>',`, so wrapping this call across lines makes a
        // method that DOES emit look like one that does not. That false
        // positive is what flagged this method after #1666 wrapped it.
        return DB::transaction(fn (): MutationResult => $this->emit('patchWorkstationOrder', $command->context, $this->persistence->patchWorkstationOrder($command)));
    }

    public function softDeleteWorkstationOrder(SoftDeleteWorkstationOrderCommand $command): MutationResult
    {
        return $this->emit('softDeleteWorkstationOrder', $command->context, $this->persistence->softDeleteWorkstationOrder($command));
    }

    public function voidWorkstationOrder(VoidWorkstationOrderCommand $command): MutationResult
    {
        return $this->emit('voidWorkstationOrder', $command->context, $this->persistence->voidWorkstationOrder($command));
    }

    public function checkoutWorkstationOrder(CheckoutWorkstationOrderCommand $command): MutationResult
    {
        return $this->emit('checkoutWorkstationOrder', $command->context, $this->persistence->checkoutWorkstationOrder($command));
    }

    public function syncWorkstationItems(SyncWorkstationOrderItemsCommand $command): MutationResult
    {
        return $this->emit('syncWorkstationItems', $command->context, $this->persistence->syncWorkstationItems($command));
    }

    public function patchWorkstationItem(PatchWorkstationOrderItemCommand $command): MutationResult
    {
        return $this->emit('patchWorkstationItem', $command->context, $this->persistence->patchWorkstationItem($command));
    }

    public function softDeleteWorkstationItem(SoftDeleteWorkstationOrderItemCommand $command): MutationResult
    {
        return $this->emit('softDeleteWorkstationItem', $command->context, $this->persistence->softDeleteWorkstationItem($command));
    }

    public function voidWorkstationItem(VoidWorkstationOrderItemCommand $command): MutationResult
    {
        return $this->emit('voidWorkstationItem', $command->context, $this->persistence->voidWorkstationItem($command));
    }

    public function applyWorkstationCoupon(ApplyWorkstationOrderCouponCommand $command): MutationResult
    {
        return $this->emit('applyWorkstationCoupon', $command->context, $this->persistence->applyWorkstationCoupon($command));
    }

    public function releaseWorkstationCoupon(ReleaseWorkstationOrderCouponCommand $command): MutationResult
    {
        return $this->emit('releaseWorkstationCoupon', $command->context, $this->persistence->releaseWorkstationCoupon($command));
    }

    public function ghostCreateWorkstationItem(GhostCreateWorkstationOrderItemCommand $command): MutationResult
    {
        return $this->emit('ghostCreateWorkstationItem', $command->context, $this->persistence->ghostCreateWorkstationItem($command));
    }

    public function bumpKitchenItemStatus(BumpKitchenOrderItemStatusCommand $command): MutationResult
    {
        return $this->emit('bumpKitchenItemStatus', $command->context, $this->persistence->bumpKitchenItemStatus($command));
    }

    public function stampKitchenTimestamp(StampKitchenItemTimestampCommand $command): MutationResult
    {
        return $this->emit('stampKitchenTimestamp', $command->context, $this->persistence->stampKitchenTimestamp($command));
    }

    public function settleIfPaid(SettleOrderIfPaidCommand $command): OrderSettlementResult
    {
        return $this->emit('settleIfPaid', $command->context, $this->persistence->markSettled($command));
    }

    /**
     * Announce a completed mutation and hand the result straight back.
     *
     * Every facade method routes its return value through here, so the event
     * stream cannot drift from the command surface: a new command that forgets
     * to emit is caught by OrderMutationEventsTest, which walks the interface.
     *
     * The dispatch is deferred to commit (see OrderMutated), so a listener never
     * observes a mutation that then rolls back.
     */
    private function emit(string $command, MutationContext $context, mixed $result): mixed
    {
        OrderMutated::dispatch($command, $this->orderIdOf($result), $context, $result);

        return $result;
    }

    /** Best-effort order id from a result object; null when it names none. */
    private function orderIdOf(mixed $result): ?string
    {
        if (is_object($result) && property_exists($result, 'orderId')) {
            /** @var object{orderId: string|null} $result */
            return $result->orderId === null ? null : (string) $result->orderId;
        }

        return null;
    }
}
