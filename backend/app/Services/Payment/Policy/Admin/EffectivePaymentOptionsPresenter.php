<?php

namespace App\Services\Payment\Policy\Admin;

use App\Models\PaymentGatewayConnection;
use App\Services\Payment\Policy\Enums\PolicyDecision;
use App\Services\Payment\Policy\Enums\PolicyLayer;
use App\Services\Payment\Policy\ValueObjects\BranchManagementProjection;
use App\Services\Payment\Policy\ValueObjects\EffectivePaymentOption;

final class EffectivePaymentOptionsPresenter
{
    /** @return array<string, mixed> */
    public function ownership(BranchManagementProjection $ownership): array
    {
        return [
            'management_model' => $ownership->managementModel->value,
            'brand_owner_org_unit_id' => $ownership->brandOwnerOrgUnitId,
            'operator_org_unit_id' => $ownership->operatorOrgUnitId,
            'ownership_revision' => $ownership->ownershipRevision,
            'reason' => $ownership->reason,
        ];
    }

    /** @return array<string, mixed> */
    public function connection(PaymentGatewayConnection $connection): array
    {
        $providerCode = $connection->provider?->code;

        return [
            'id' => (string) $connection->id,
            'provider' => $providerCode instanceof \BackedEnum
                ? $providerCode->value
                : (string) $providerCode,
            'environment' => $connection->environment instanceof \BackedEnum
                ? $connection->environment->value
                : (string) $connection->environment,
            'owner_scope' => $connection->owner_scope instanceof \BackedEnum
                ? $connection->owner_scope->value
                : (string) $connection->owner_scope,
            'merchant_display_name' => $connection->merchant_display_name,
            'merchant_account_id' => $connection->merchant_account_id,
            'health' => $connection->health instanceof \BackedEnum
                ? $connection->health->value
                : (string) $connection->health,
            'health_reason_code' => $connection->health_reason_code,
            'is_active' => (bool) $connection->is_active,
            'last_validated_at' => $connection->last_validated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function option(
        EffectivePaymentOption $resolved,
        string $displayName,
        string $provider,
        string $rail,
        string $shopPreference,
        string $devicePreference,
        ?string $methodType = null,
    ): array {
        return [
            'id' => $resolved->optionId,
            'display_name' => $displayName,
            'provider' => $provider,
            'rail' => $rail,
            'method_type' => $methodType,
            'effective' => $resolved->effective,
            'source' => $this->source($resolved),
            'reason' => $resolved->reason->value,
            'error_code' => $resolved->reason->publicErrorCode(),
            'connection_id' => $resolved->connectionId,
            'connection_option_id' => $resolved->connectionOptionId,
            'shop_option_id' => $resolved->shopOptionId,
            'owner_scope' => $resolved->ownerScope?->value,
            'operator_org_unit_id' => $resolved->operatorOrgUnitId,
            'shop_preference' => $shopPreference,
            'device_preference' => $devicePreference,
            'trace' => array_map(
                static fn ($entry): array => $entry->jsonSerialize(),
                $resolved->trace,
            ),
        ];
    }

    private function source(EffectivePaymentOption $resolved): string
    {
        if ($resolved->effective) {
            return 'effective';
        }

        $denying = null;
        foreach ($resolved->trace as $entry) {
            if ($entry->decision !== PolicyDecision::Allowed) {
                $denying = $entry->layer;
            }
        }

        return match ($denying) {
            PolicyLayer::Ownership => 'ownership',
            PolicyLayer::Provider => 'provider',
            PolicyLayer::Connection => 'connection',
            PolicyLayer::Capability => 'capability',
            PolicyLayer::OwnerPolicy => 'owner_policy',
            PolicyLayer::Shop => 'shop',
            PolicyLayer::Device => 'device',
            PolicyLayer::Runtime => 'runtime',
            default => 'unknown',
        };
    }
}
