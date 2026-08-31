<?php

namespace App\Services\Payment\Secret\Contracts;

use App\Services\Payment\Secret\ValueObjects\GatewayMasterKey;

interface GatewayMasterKeyProvider
{
    public function active(): GatewayMasterKey;

    public function byId(string $keyId): GatewayMasterKey;
}
