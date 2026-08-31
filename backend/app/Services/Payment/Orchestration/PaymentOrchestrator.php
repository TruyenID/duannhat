<?php

namespace App\Services\Payment\Orchestration;

use App\Models\PaymentAttempt;
use App\Services\Order\Commands\SettleOrderIfPaidCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Payment\Orchestration\Commands\ApplyInboxProviderEventCommand;
use App\Services\Payment\Orchestration\Commands\FinalizePaymentCommand;
use App\Services\Payment\Orchestration\Commands\PreparePaymentCommand;
use App\Services\Payment\Orchestration\Commands\ProcessProviderEventCommand;
use App\Services\Payment\Orchestration\Commands\ReconcilePaymentCommand;
use App\Services\Payment\Orchestration\Commands\ReconcilePaymentRefundCommand;
use App\Services\Payment\Orchestration\Commands\RecordPaymentTenderCommand;
use App\Services\Payment\Orchestration\Commands\RecordResolvedPaymentTenderCommand;
use App\Services\Payment\Orchestration\Commands\RequestPaymentRefundCommand;
use App\Services\Payment\Orchestration\Commands\ReserveVerifiedPaymentAttemptCommand;
use App\Services\Payment\Orchestration\Commands\ReserveVerifiedRefundCommand;
use App\Services\Payment\Orchestration\Contracts\PaymentAuthorityVerificationPort;
use App\Services\Payment\Orchestration\Contracts\PaymentMutationFacade;
use App\Services\Payment\Orchestration\Internal\EloquentPaymentPersistence;
use App\Services\Payment\Orchestration\Results\InboxEventApplicationResult;
use App\Services\Payment\Orchestration\Results\PaymentFinalizeResult;
use App\Services\Payment\Orchestration\Results\PaymentPrepareResult;
use App\Services\Payment\Orchestration\Results\PaymentRefundResult;
use App\Services\Payment\Orchestration\Results\ProviderEventResult;
use App\Services\Payment\Orchestration\Support\PaymentOrchestrationLogContext;
use App\Services\Payment\ProviderEvent\ProviderEventApplicator;
use Illuminate\Support\Facades\Log;

/** Sole public Payment mutation facade. */
final class PaymentOrchestrator implements PaymentMutationFacade
{
    public function __construct(
        private readonly EloquentPaymentPersistence $persistence,
        private readonly PaymentAuthorityVerificationPort $authority,
        private readonly OrderMutationFacade $orders,
    ) {}

    public function prepare(PreparePaymentCommand $command): PaymentPrepareResult
    {
        $verification = $this->authority->verifyPreparation($command);
        $reserved = ReserveVerifiedPaymentAttemptCommand::fromVerifiedPreparation(
            $command->context,
            $command->attemptId,
            $verification,
            $command->tender,
            $command->splitPlan,
        );

        Log::channel('payment_orchestration')->info(
            'payment.prepare',
            PaymentOrchestrationLogContext::enrich($command->context, [
                'attempt_id' => $command->attemptId,
                'order_id' => $command->orderId,
            ]),
        );

        return $this->persistence->reserveAttempt($reserved);
    }

    public function recordTender(RecordPaymentTenderCommand $command): PaymentFinalizeResult
    {
        $method = $this->authority->resolveTenderMethod($command);
        $changeMinor = $command->tender->tenderedMinor === null
            ? null
            : $command->tender->tenderedMinor - $command->amountMinor - $command->tender->tipMinor;
        $resolved = RecordResolvedPaymentTenderCommand::fromVerifiedMethod($command, $method, $changeMinor);

        Log::channel('payment_orchestration')->info(
            'payment.record_tender',
            PaymentOrchestrationLogContext::enrich($command->context, [
                'order_payment_id' => $command->orderPaymentId,
                'order_id' => $command->orderId,
            ]),
        );

        return $this->persistence->recordTender($resolved);
    }

    public function finalize(FinalizePaymentCommand $command): PaymentFinalizeResult
    {
        $result = $this->persistence->finalizeAttempt($command);

        Log::channel('payment_orchestration')->info(
            'payment.finalize',
            PaymentOrchestrationLogContext::enrich($command->context, [
                'attempt_id' => $result->attemptId,
                'state' => $result->outcome->state->value,
                'order_settlement_required' => $result->orderSettlementRequired,
            ]),
        );

        return $this->settleOrderWhenRequired($command, $result);
    }

    public function reconcile(ReconcilePaymentCommand $command): PaymentFinalizeResult
    {
        $result = $this->persistence->markAttemptForReconciliation($command);

        Log::channel('payment_orchestration')->info(
            'payment.reconcile',
            PaymentOrchestrationLogContext::enrich($command->context, [
                'attempt_id' => $result->attemptId,
                'state' => $result->outcome->state->value,
            ]),
        );

        return $this->settleOrderWhenRequired($command, $result);
    }

    public function requestRefund(RequestPaymentRefundCommand $command): PaymentRefundResult
    {
        $intent = $this->authority->verifyRefund($command);
        $reserved = ReserveVerifiedRefundCommand::fromVerifiedIntent($command->context, $intent);

        Log::channel('payment_orchestration')->info(
            'payment.request_refund',
            PaymentOrchestrationLogContext::enrich($command->context, [
                'refund_id' => $command->refundId,
                'attempt_id' => $command->attemptId,
            ]),
        );

        return $this->persistence->reserveRefund($reserved);
    }

    public function reconcileRefund(ReconcilePaymentRefundCommand $command): PaymentRefundResult
    {
        $result = $this->persistence->finalizeRefund($command);

        Log::channel('payment_orchestration')->info(
            'payment.reconcile_refund',
            PaymentOrchestrationLogContext::enrich($command->context, [
                'refund_id' => $result->refundId,
                'state' => $result->state->value,
            ]),
        );

        return $result;
    }

    public function processProviderEvent(ProcessProviderEventCommand $command): ProviderEventResult
    {
        return $this->persistence->recordVerifiedProviderEvent($command);
    }

    public function applyInboxEvent(ApplyInboxProviderEventCommand $command): InboxEventApplicationResult
    {
        Log::channel('payment_orchestration')->info(
            'payment.apply_inbox_event',
            ['provider_event_record_id' => $command->providerEventRecordId],
        );

        return new InboxEventApplicationResult(
            app(ProviderEventApplicator::class)->apply($command->providerEventRecordId),
        );
    }

    private function settleOrderWhenRequired(FinalizePaymentCommand|ReconcilePaymentCommand $command, PaymentFinalizeResult $result): PaymentFinalizeResult
    {
        if (! $result->orderSettlementRequired) {
            return $result;
        }

        $attempt = PaymentAttempt::query()->findOrFail($result->attemptId);

        $this->orders->settleIfPaid(new SettleOrderIfPaidCommand(
            $command->context,
            (string) $attempt->customer_order_id,
        ));

        return $result;
    }
}
