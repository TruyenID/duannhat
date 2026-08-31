<?php

namespace App\Services\Payment\Policy\Contracts;

use App\Services\Payment\Policy\ValueObjects\PaymentPolicyPublication;
use App\Services\Payment\Policy\ValueObjects\PublishedPaymentPolicyRevision;

/** Owns the atomic branch lock, latest-hash check, and append-only revision allocation. */
interface PaymentPolicyRevisionPersistence
{
    public function publishAtomically(PaymentPolicyPublication $publication): PublishedPaymentPolicyRevision;
}
