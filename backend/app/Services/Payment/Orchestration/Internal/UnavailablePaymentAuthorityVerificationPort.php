<?php

namespace App\Services\Payment\Orchestration\Internal;

use App\Services\Payment\Orchestration\Commands\PreparePaymentCommand;
use App\Services\Payment\Orchestration\Commands\RecordPaymentTenderCommand;
use App\Services\Payment\Orchestration\Commands\RequestPaymentRefundCommand;
use App\Services\Payment\Orchestration\Contracts\PaymentAuthorityVerificationPort;
use App\Services\Payment\Orchestration\ValueObjects\ResolvedPaymentMethodEvidence;
use App\Services\Payment\Orchestration\ValueObjects\VerifiedPaymentPreparation;
use App\Services\Payment\Orchestration\ValueObjects\VerifiedRefundIntent;
use LogicException;

/** Runtime default until the production payment authority adapter is installed. */
final class UnavailablePaymentAuthorityVerificationPort implements PaymentAuthorityVerificationPort
{
    public function verifyPreparation(PreparePaymentCommand $command): VerifiedPaymentPreparation
    {
        throw new LogicException('Payment authority verification is not wired yet.');
    }

    public function resolveTenderMethod(RecordPaymentTenderCommand $command): ResolvedPaymentMethodEvidence
    {
        throw new LogicException('Payment authority verification is not wired yet.');
    }

    public function verifyRefund(RequestPaymentRefundCommand $command): VerifiedRefundIntent
    {
        throw new LogicException('Payment authority verification is not wired yet.');
    }
}
