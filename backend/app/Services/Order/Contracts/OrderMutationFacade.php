<?php

namespace App\Services\Order\Contracts;

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
use App\Services\Order\Results\OrderCreatedResult;
use App\Services\Order\Results\OrderMutationBatchResult;
use App\Services\Order\Results\OrderSettlementResult;

interface OrderMutationFacade
{
    public function create(CreateOrderCommand $command): OrderCreatedResult;

    public function replayOffline(ReplayOfflineOrderCommand $command): OrderCreatedResult;

    public function initialize(InitializeOrderCommand $command): MutationResult;

    public function confirm(ConfirmOrderCommand $command): MutationResult;

    /**
     * #2479 — `checkout` → `open`, để thu ngân sửa được đơn đã chốt nhầm.
     *
     * CHỈ khi đơn chưa nhận đồng nào. Có tiền rồi thì đường ra là void/refund,
     * không phải cửa này.
     */
    public function reopen(ReopenOrderCommand $command): MutationResult;

    public function commitConfirmation(CommitOrderConfirmationCommand $command): MutationResult;

    public function voidAwaitingConfirmation(VoidAwaitingConfirmationOrderCommand $command): MutationResult;

    public function claimGuestOrders(ClaimGuestOrdersCommand $command): MutationResult;

    public function setStaffEditLock(SetStaffEditLockCommand $command): MutationResult;

    public function assignTableSession(AssignOrderTableSessionCommand $command): MutationResult;

    public function continueTable(ContinueTableOrderCommand $command): MutationResult;

    public function expire(ExpireOrderCommand $command): MutationResult;

    public function close(CloseOrderCommand $command): MutationResult;

    public function approveItemRefund(ApproveOrderItemRefundCommand $command): MutationResult;

    public function removeItem(RemoveOrderItemCommand $command): MutationResult;

    public function revertKitchenItem(RevertOrderItemKitchenCommand $command): MutationResult;

    public function changeItems(ChangeOrderItemsCommand $command): MutationResult;

    /**
     * Áp nhiều thay đổi dòng như MỘT lô được-ăn-cả-ngã-về-không (#1666).
     */
    public function changeItemsBatch(ChangeOrderItemsBatchCommand $command): OrderMutationBatchResult;

    public function reviseHeader(ReviseOrderHeaderCommand $command): MutationResult;

    public function changeSplitMode(ChangeOrderSplitModeCommand $command): MutationResult;

    public function bindCoupon(BindOrderCouponCommand $command): MutationResult;

    public function applyCoupon(ApplyOrderCouponCommand $command): MutationResult;

    public function removeCoupon(RemoveOrderCouponCommand $command): MutationResult;

    /** Trả dòng mang khuyến mãi độc quyền về giá gốc khi áp coupon (#1564). */
    public function downgradeExclusivePromotions(DowngradeExclusivePromotionsCommand $command): MutationResult;

    public function refreshPricing(RefreshOrderPricingCommand $command): MutationResult;

    public function advanceKitchenItem(AdvanceOrderItemKitchenCommand $command): MutationResult;

    public function voidKitchenItem(VoidOrderItemCommand $command): MutationResult;

    public function checkout(CheckoutOrderCommand $command): MutationResult;

    public function promoteForPayment(PromoteOrderForPaymentCommand $command): MutationResult;

    public function beginPaying(BeginOrderPaymentCommand $command): MutationResult;

    public function stampStripeIntent(StampOrderStripeIntentCommand $command): MutationResult;

    public function refreshPaymentCache(RefreshOrderPaymentCacheCommand $command): MutationResult;

    public function cancel(CancelOrderCommand $command): MutationResult;

    public function void(VoidOrderCommand $command): MutationResult;

    public function changeTable(ChangeOrderTableCommand $command): MutationResult;

    public function mergeTables(MergeOrderTablesCommand $command): MutationResult;

    public function unmergeTable(UnmergeOrderTableCommand $command): MutationResult;

    public function patchWorkstationOrder(PatchWorkstationOrderCommand $command): MutationResult;

    public function softDeleteWorkstationOrder(SoftDeleteWorkstationOrderCommand $command): MutationResult;

    public function voidWorkstationOrder(VoidWorkstationOrderCommand $command): MutationResult;

    public function checkoutWorkstationOrder(CheckoutWorkstationOrderCommand $command): MutationResult;

    public function syncWorkstationItems(SyncWorkstationOrderItemsCommand $command): MutationResult;

    public function patchWorkstationItem(PatchWorkstationOrderItemCommand $command): MutationResult;

    public function softDeleteWorkstationItem(SoftDeleteWorkstationOrderItemCommand $command): MutationResult;

    public function voidWorkstationItem(VoidWorkstationOrderItemCommand $command): MutationResult;

    public function applyWorkstationCoupon(ApplyWorkstationOrderCouponCommand $command): MutationResult;

    public function releaseWorkstationCoupon(ReleaseWorkstationOrderCouponCommand $command): MutationResult;

    public function ghostCreateWorkstationItem(GhostCreateWorkstationOrderItemCommand $command): MutationResult;

    public function bumpKitchenItemStatus(BumpKitchenOrderItemStatusCommand $command): MutationResult;

    public function stampKitchenTimestamp(StampKitchenItemTimestampCommand $command): MutationResult;

    /**
     * Chạy một loạt lệnh bếp như MỘT lô — bump trạng thái kèm các dấu thời gian
     * ghi-lần-đầu-thắng đi với nó (#1666).
     */
    public function runKitchenBatch(RunKitchenBatchCommand $command): OrderMutationBatchResult;

    public function settleIfPaid(SettleOrderIfPaidCommand $command): OrderSettlementResult;
}
