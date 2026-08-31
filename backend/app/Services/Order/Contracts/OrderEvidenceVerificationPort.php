<?php

namespace App\Services\Order\Contracts;

use App\Services\Order\Commands\ReplayOfflineOrderCommand;
use App\Services\Order\ValueObjects\TrustedOrderSnapshot;

interface OrderEvidenceVerificationPort
{
    /** Verifies MAC/signature, issuer, device, branch, revision and expiry before returning server-trusted data. */
    public function verifyOfflineReplay(ReplayOfflineOrderCommand $command): TrustedOrderSnapshot;
}
