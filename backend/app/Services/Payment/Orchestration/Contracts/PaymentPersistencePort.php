<?php

namespace App\Services\Payment\Orchestration\Contracts;

use App\Services\Payment\Orchestration\Commands\AttachCustomerWebPrepareReferenceCommand;
use App\Services\Payment\Orchestration\Commands\FinalizePaymentCommand;
use App\Services\Payment\Orchestration\Commands\ProcessProviderEventCommand;
use App\Services\Payment\Orchestration\Commands\ReconcilePaymentCommand;
use App\Services\Payment\Orchestration\Commands\ReconcilePaymentRefundCommand;
use App\Services\Payment\Orchestration\Commands\RecordResolvedPaymentTenderCommand;
use App\Services\Payment\Orchestration\Commands\ReserveVerifiedPaymentAttemptCommand;
use App\Services\Payment\Orchestration\Commands\ReserveVerifiedRefundCommand;
use App\Services\Payment\Orchestration\Results\PaymentFinalizeResult;
use App\Services\Payment\Orchestration\Results\PaymentPrepareResult;
use App\Services\Payment\Orchestration\Results\PaymentRefundResult;
use App\Services\Payment\Orchestration\Results\PrepareReferenceAttachmentResult;
use App\Services\Payment\Orchestration\Results\ProviderEventResult;

interface PaymentPersistencePort
{
    public function reserveAttempt(ReserveVerifiedPaymentAttemptCommand $command): PaymentPrepareResult;

    public function recordTender(RecordResolvedPaymentTenderCommand $command): PaymentFinalizeResult;

    public function finalizeAttempt(FinalizePaymentCommand $command): PaymentFinalizeResult;

    public function markAttemptForReconciliation(ReconcilePaymentCommand $command): PaymentFinalizeResult;

    public function reserveRefund(ReserveVerifiedRefundCommand $command): PaymentRefundResult;

    public function finalizeRefund(ReconcilePaymentRefundCommand $command): PaymentRefundResult;

    public function recordVerifiedProviderEvent(ProcessProviderEventCommand $command): ProviderEventResult;

    public function attachCustomerWebPrepareReference(AttachCustomerWebPrepareReferenceCommand $command): PrepareReferenceAttachmentResult;
}
