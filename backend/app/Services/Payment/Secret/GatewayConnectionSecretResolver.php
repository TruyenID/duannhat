<?php

namespace App\Services\Payment\Secret;

use App\Services\Payment\Gateway\ValueObjects\EphemeralSecret;
use App\Services\Payment\Secret\Contracts\GatewaySecretStoreContract;
use App\Services\Payment\Secret\Enums\GatewaySecretPurpose;
use App\Services\Payment\Secret\ValueObjects\GatewaySecretAccessContext;
use App\Services\Payment\Secret\ValueObjects\GatewaySecretRotationResult;
use App\Services\Payment\Secret\ValueObjects\ResolvedGatewaySecret;

final readonly class GatewayConnectionSecretResolver
{
    public function __construct(private GatewaySecretStoreContract $store) {}

    public function api(GatewaySecretAccessContext $context): ResolvedGatewaySecret
    {
        return $this->store->resolveCurrent($context, GatewaySecretPurpose::Api);
    }

    /** @return list<ResolvedGatewaySecret> */
    public function webhookCandidates(GatewaySecretAccessContext $context): array
    {
        return $this->store->resolveWebhookCandidates($context);
    }

    public function rotateApi(GatewaySecretAccessContext $context, EphemeralSecret $secret): GatewaySecretRotationResult
    {
        return $this->store->rotate($context, GatewaySecretPurpose::Api, $secret);
    }

    public function rotateWebhook(
        GatewaySecretAccessContext $context,
        EphemeralSecret $secret,
        int $overlapSeconds,
    ): GatewaySecretRotationResult {
        return $this->store->rotate($context, GatewaySecretPurpose::Webhook, $secret, $overlapSeconds);
    }

    public function revokeApi(GatewaySecretAccessContext $context): void
    {
        $this->store->revoke($context, GatewaySecretPurpose::Api);
    }

    public function revokeWebhook(GatewaySecretAccessContext $context): void
    {
        $this->store->revoke($context, GatewaySecretPurpose::Webhook);
    }
}
