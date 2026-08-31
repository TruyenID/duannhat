<?php

namespace App\Services\Payment\Secret\Contracts;

use App\Services\Payment\Gateway\ValueObjects\EphemeralSecret;
use App\Services\Payment\Secret\Enums\GatewaySecretPurpose;
use App\Services\Payment\Secret\ValueObjects\GatewaySecretAccessContext;
use App\Services\Payment\Secret\ValueObjects\GatewaySecretRotationResult;
use App\Services\Payment\Secret\ValueObjects\ResolvedGatewaySecret;

interface GatewaySecretStoreContract
{
    public function resolveCurrent(
        GatewaySecretAccessContext $context,
        GatewaySecretPurpose $purpose,
    ): ResolvedGatewaySecret;

    /** @return list<ResolvedGatewaySecret> newest first */
    public function resolveWebhookCandidates(GatewaySecretAccessContext $context): array;

    public function rotate(
        GatewaySecretAccessContext $context,
        GatewaySecretPurpose $purpose,
        EphemeralSecret $newSecret,
        int $webhookOverlapSeconds = 0,
    ): GatewaySecretRotationResult;

    public function revoke(
        GatewaySecretAccessContext $context,
        GatewaySecretPurpose $purpose,
    ): void;
}
