<?php

namespace App\Services\Order\Contracts;

use App\Services\DomainMutation\MutationResult;
use App\Services\Order\Commands\AdvanceOrderItemKitchenCommand;
use App\Services\Order\Commands\ApplyOrderCouponCommand;
use App\Services\Order\Commands\ApproveOrderItemRefundCommand;
use App\Services\Order\Commands\BeginOrderPaymentCommand;
use App\Services\Order\Commands\CancelOrderCommand;
use App\Services\Order\Commands\ChangeOrderSplitModeCommand;
use App\Services\Order\Commands\ChangeOrderTableCommand;
use App\Services\Order\Commands\CheckoutOrderCommand;
use App\Services\Order\Commands\CloseOrderCommand;
use App\Services\Order\Commands\ConfirmOrderCommand;
use App\Services\Order\Commands\ContinueTableOrderCommand;
use App\Services\Order\Commands\ExpireOrderCommand;
use App\Services\Order\Commands\InitializeOrderCommand;
use App\Services\Order\Commands\MergeOrderTablesCommand;
use App\Services\Order\Commands\PersistOfflineReplayOrderCommand;
use App\Services\Order\Commands\PersistOnlineOrderCommand;
use App\Services\Order\Commands\PersistResolvedOrderItemsCommand;
use App\Services\Order\Commands\PromoteOrderForPaymentCommand;
use App\Services\Order\Commands\RefreshOrderPaymentCacheCommand;
use App\Services\Order\Commands\RemoveOrderCouponCommand;
use App\Services\Order\Commands\RemoveOrderItemCommand;
use App\Services\Order\Commands\ReopenOrderCommand;
use App\Services\Order\Commands\RevertOrderItemKitchenCommand;
use App\Services\Order\Commands\ReviseOrderHeaderCommand;
use App\Services\Order\Commands\SettleOrderIfPaidCommand;
use App\Services\Order\Commands\StampOrderStripeIntentCommand;
use App\Services\Order\Commands\UnmergeOrderTableCommand;
use App\Services\Order\Commands\VoidOrderCommand;
use App\Services\Order\Commands\VoidOrderItemCommand;
use App\Services\Order\Results\OrderCreatedResult;
use App\Services\Order\Results\OrderSettlementResult;

interface OrderPersistencePort
{
    public function insertResolvedOrder(PersistOnlineOrderCommand $command): OrderCreatedResult;

    public function insertOfflineReplay(PersistOfflineReplayOrderCommand $command): OrderCreatedResult;

    public function markInitialized(InitializeOrderCommand $command): MutationResult;

    public function markConfirmed(ConfirmOrderCommand $command): MutationResult;

    public function markReopened(ReopenOrderCommand $command): MutationResult;

    public function continueTableSession(ContinueTableOrderCommand $command): MutationResult;

    public function markExpired(ExpireOrderCommand $command): MutationResult;

    public function markClosed(CloseOrderCommand $command): MutationResult;

    public function approveItemRefund(ApproveOrderItemRefundCommand $command): MutationResult;

    public function removeItem(RemoveOrderItemCommand $command): MutationResult;

    public function revertKitchenItem(RevertOrderItemKitchenCommand $command): MutationResult;

    public function applyItemChange(PersistResolvedOrderItemsCommand $command): MutationResult;

    public function reviseHeader(ReviseOrderHeaderCommand $command): MutationResult;

    public function changeSplitMode(ChangeOrderSplitModeCommand $command): MutationResult;

    public function applyCoupon(ApplyOrderCouponCommand $command): MutationResult;

    public function removeCoupon(RemoveOrderCouponCommand $command): MutationResult;

    public function advanceKitchenItem(AdvanceOrderItemKitchenCommand $command): MutationResult;

    public function voidKitchenItem(VoidOrderItemCommand $command): MutationResult;

    public function markCheckedOut(CheckoutOrderCommand $command): MutationResult;

    public function markPromotedForPayment(PromoteOrderForPaymentCommand $command): MutationResult;

    public function markPaying(BeginOrderPaymentCommand $command): MutationResult;

    public function stampStripePaymentIntent(StampOrderStripeIntentCommand $command): MutationResult;

    public function refreshPaymentCache(RefreshOrderPaymentCacheCommand $command): MutationResult;

    public function markCanceled(CancelOrderCommand $command): MutationResult;

    public function markVoided(VoidOrderCommand $command): MutationResult;

    public function replaceTableAssociation(ChangeOrderTableCommand $command): MutationResult;

    public function mergeTables(MergeOrderTablesCommand $command): MutationResult;

    public function unmergeTable(UnmergeOrderTableCommand $command): MutationResult;

    public function markSettled(SettleOrderIfPaidCommand $command): OrderSettlementResult;
}
