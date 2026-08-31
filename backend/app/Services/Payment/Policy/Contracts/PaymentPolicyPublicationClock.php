<?php

namespace App\Services\Payment\Policy\Contracts;

use DateTimeImmutable;

interface PaymentPolicyPublicationClock
{
    public function now(): DateTimeImmutable;
}
