<?php

namespace App\Services\Payment\Orchestration\Contracts;

use App\Services\Payment\Orchestration\Commands\PreparePaymentCommand;
use App\Services\Payment\Orchestration\Commands\RecordPaymentTenderCommand;
use App\Services\Payment\Orchestration\Commands\RequestPaymentRefundCommand;
use App\Services\Payment\Orchestration\ValueObjects\ResolvedPaymentMethodEvidence;
use App\Services\Payment\Orchestration\ValueObjects\VerifiedPaymentPreparation;
use App\Services\Payment\Orchestration\ValueObjects\VerifiedRefundIntent;

interface PaymentAuthorityVerificationPort
{
    /** Verifies actor/org/branch, effective policy revision, option, connection, provider and attempt/order binding. */
    public function verifyPreparation(PreparePaymentCommand $command): VerifiedPaymentPreparation;

    public function resolveTenderMethod(RecordPaymentTenderCommand $command): ResolvedPaymentMethodEvidence;

    /** Verifies actor/org/branch, payment/attempt/order/connection/provider binding and remaining refundable amount. */
    public function verifyRefund(RequestPaymentRefundCommand $command): VerifiedRefundIntent;
}
