<?php

namespace App\Services\Payment\Orchestration\Contracts;

use App\Services\Payment\Orchestration\Commands\ApplyInboxProviderEventCommand;
use App\Services\Payment\Orchestration\Commands\FinalizePaymentCommand;
use App\Services\Payment\Orchestration\Commands\PreparePaymentCommand;
use App\Services\Payment\Orchestration\Commands\ProcessProviderEventCommand;
use App\Services\Payment\Orchestration\Commands\ReconcilePaymentCommand;
use App\Services\Payment\Orchestration\Commands\ReconcilePaymentRefundCommand;
use App\Services\Payment\Orchestration\Commands\RecordPaymentTenderCommand;
use App\Services\Payment\Orchestration\Commands\RequestPaymentRefundCommand;
use App\Services\Payment\Orchestration\Results\InboxEventApplicationResult;
use App\Services\Payment\Orchestration\Results\PaymentFinalizeResult;
use App\Services\Payment\Orchestration\Results\PaymentPrepareResult;
use App\Services\Payment\Orchestration\Results\PaymentRefundResult;
use App\Services\Payment\Orchestration\Results\ProviderEventResult;

interface PaymentMutationFacade
{
    public function prepare(PreparePaymentCommand $command): PaymentPrepareResult;

    public function recordTender(RecordPaymentTenderCommand $command): PaymentFinalizeResult;

    public function finalize(FinalizePaymentCommand $command): PaymentFinalizeResult;

    public function reconcile(ReconcilePaymentCommand $command): PaymentFinalizeResult;

    public function requestRefund(RequestPaymentRefundCommand $command): PaymentRefundResult;

    public function reconcileRefund(ReconcilePaymentRefundCommand $command): PaymentRefundResult;

    public function processProviderEvent(ProcessProviderEventCommand $command): ProviderEventResult;

    public function applyInboxEvent(ApplyInboxProviderEventCommand $command): InboxEventApplicationResult;
}
