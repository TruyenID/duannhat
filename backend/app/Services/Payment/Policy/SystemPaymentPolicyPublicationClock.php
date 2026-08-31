<?php

namespace App\Services\Payment\Policy;

use App\Services\Payment\Policy\Contracts\PaymentPolicyPublicationClock;
use DateTimeImmutable;

final class SystemPaymentPolicyPublicationClock implements PaymentPolicyPublicationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
