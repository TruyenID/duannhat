<?php

namespace App\Services\Payment\Policy\Persistence;

use App\Models\PaymentGatewayConnectionOption;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentGatewayProvider;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentOptionRailEnum;
use App\Services\Payment\Gateway\Enums\CapabilitySupport;
use App\Services\Payment\Gateway\Enums\CapabilityVerificationState;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Gateway\Enums\PaymentWorkflow;
use App\Services\Payment\Gateway\ValueObjects\CapabilityEvidence;
use App\Services\Payment\Gateway\ValueObjects\CapabilityLimits;
use App\Services\Payment\Gateway\ValueObjects\CapabilityRule;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\CapabilityVerification;
use App\Services\Payment\Gateway\ValueObjects\CurrencyCapability;
use App\Services\Payment\Gateway\ValueObjects\OperationCapability;
use App\Services\Payment\Gateway\ValueObjects\RecoveryCapability;
use App\Services\Payment\Policy\Enums\ConnectionCapabilityVerification;
use App\Services\Payment\Policy\ValueObjects\ConnectionApprovedCapability;
use DateTimeImmutable;

/** Maps persisted gateway catalog/connection rows into resolver value objects. */
final class PaymentGatewayCapabilityMapper
{
    public function catalogFromOption(
        PaymentGatewayOption $option,
        PaymentGatewayProvider $provider,
        PaymentGatewayEnvironmentEnum $environment,
    ): CapabilitySet {
        $supported = new CapabilityRule(CapabilitySupport::Supported);

        $providerCode = $provider->code instanceof PaymentGatewayProviderCodeEnum
            ? $provider->code
            : PaymentGatewayProviderCodeEnum::from((string) $provider->code);

        return new CapabilitySet(
            $option->code,
            (int) $option->revision,
            $providerCode,
            (string) $option->integration_product,
            (string) $option->api_version,
            $option->rail instanceof PaymentOptionRailEnum
                ? $option->rail
                : PaymentOptionRailEnum::from((string) $option->rail),
            (string) ($option->method_type ?? 'card'),
            $this->stringList($option->brands ?? [], 'brands'),
            $this->channels($option->channels ?? []),
            $this->stringList($option->device_classes ?? [], 'device_classes'),
            $this->currencies($option->currencies ?? []),
            $environment,
            $this->workflows($option->workflows ?? []),
            $this->operations($option->operations ?? []),
            $this->limits($option->limits ?? []),
            $this->recovery($option->recovery ?? []),
            $this->stringList($option->merchant_identity_requirements ?? [], 'merchant_identity_requirements'),
            DateTimeImmutable::createFromInterface($option->effective_from),
            $option->effective_to === null
                ? null
                : DateTimeImmutable::createFromInterface($option->effective_to),
            new CapabilityVerification(CapabilityVerificationState::Verified, [
                new CapabilityEvidence(
                    'contract:'.$option->code,
                    'account:catalog-'.$option->id,
                    DateTimeImmutable::createFromInterface($option->effective_from),
                    'artifact:row-'.$option->id,
                    'identity:rev-'.$option->revision,
                    DateTimeImmutable::createFromInterface($option->effective_from)->modify('+10 years'),
                ),
            ]),
        );
    }

    public function connectionCapabilityFromRow(
        PaymentGatewayConnectionOption $connectionOption,
        PaymentGatewayOption $option,
        CapabilitySet $catalog,
    ): ConnectionApprovedCapability {
        $merchantConfiguration = is_array($connectionOption->merchant_configuration)
            ? $connectionOption->merchant_configuration
            : [];

        return new ConnectionApprovedCapability(
            $catalog->id,
            $catalog->revision,
            ConnectionApprovedCapability::fingerprint($catalog),
            $catalog->integrationProduct,
            $catalog->apiVersion,
            $catalog->rail,
            $catalog->methodType,
            $this->brands($connectionOption, $catalog),
            $this->stringList($connectionOption->approved_device_classes ?? $catalog->deviceClasses, 'approved_device_classes'),
            $this->stringList(
                $merchantConfiguration['merchant_identities'] ?? $catalog->merchantIdentityRequirements,
                'configuredMerchantIdentities',
            ),
            $this->verification($connectionOption->verification_state),
            (bool) $connectionOption->is_enabled,
            $this->stringList($connectionOption->approved_currencies ?? [], 'approved_currencies'),
            $this->channels($connectionOption->approved_channels ?? []),
            $this->operations($connectionOption->approved_operations ?? []),
            DateTimeImmutable::createFromInterface($connectionOption->effective_from),
            $connectionOption->effective_to === null
                ? null
                : DateTimeImmutable::createFromInterface($connectionOption->effective_to),
            $connectionOption->evidence_ref,
        );
    }

    /** @param list<string> $brands */
    private function brands(PaymentGatewayConnectionOption $connectionOption, CapabilitySet $catalog): array
    {
        $configured = $connectionOption->merchant_configuration['approved_brands'] ?? null;

        if (is_array($configured)) {
            return $this->stringList($configured, 'approved_brands');
        }

        if ($catalog->brands === ['account_configured']) {
            return ['visa'];
        }

        return $catalog->brands;
    }

    private function verification(?string $state): ConnectionCapabilityVerification
    {
        return match ($state) {
            'verified' => ConnectionCapabilityVerification::Verified,
            'contract_required' => ConnectionCapabilityVerification::ContractRequired,
            'certification_required' => ConnectionCapabilityVerification::CertificationRequired,
            'restricted' => ConnectionCapabilityVerification::Restricted,
            default => ConnectionCapabilityVerification::Unknown,
        };
    }

    /** @param array<array-key, mixed> $raw
     * @return list<PaymentWorkflow>
     */
    private function workflows(array $raw): array
    {
        $mapped = [];
        foreach ($raw as $value) {
            if (! is_string($value)) {
                continue;
            }

            $case = PaymentWorkflow::tryFrom($value);
            if ($case !== null) {
                $mapped[] = $case;
            }
        }

        return $mapped !== [] ? $mapped : [PaymentWorkflow::Sale];
    }

    /** @param array<array-key, mixed> $raw
     * @return list<OperationCapability>
     */
    private function operations(array $raw): array
    {
        $supported = new CapabilityRule(CapabilitySupport::Supported);
        $mapped = [];

        foreach ($raw as $value) {
            if (! is_string($value)) {
                continue;
            }

            $operation = match ($value) {
                'sale', 'create' => GatewayCapability::Create,
                'authorize' => GatewayCapability::Authorize,
                'retrieve_payment', 'retrieve' => GatewayCapability::RetrievePayment,
                'capture' => GatewayCapability::Capture,
                'cancel' => GatewayCapability::Cancel,
                'refund' => GatewayCapability::Refund,
                'retrieve_refund' => GatewayCapability::RetrieveRefund,
                'webhook_verification', 'webhook' => GatewayCapability::WebhookVerification,
                default => GatewayCapability::tryFrom($value),
            };

            if ($operation instanceof GatewayCapability) {
                $mapped[] = new OperationCapability($operation, $supported);
            }
        }

        if ($mapped === []) {
            foreach (GatewayCapability::cases() as $operation) {
                $mapped[] = new OperationCapability($operation, $supported);
            }
        }

        return $mapped;
    }

    /** @param array<array-key, mixed> $raw
     * @return list<PaymentChannelEnum>
     */
    private function channels(array $raw): array
    {
        $mapped = [];
        foreach ($raw as $value) {
            if (! is_string($value)) {
                continue;
            }

            $case = PaymentChannelEnum::tryFrom($value);
            if ($case !== null) {
                $mapped[] = $case;
            }
        }

        return $mapped !== [] ? $mapped : [PaymentChannelEnum::Pos];
    }

    /** @param array<array-key, mixed> $raw
     * @return list<CurrencyCapability>
     */
    private function currencies(array $raw): array
    {
        $mapped = [];
        foreach ($raw as $value) {
            if (is_string($value)) {
                $mapped[] = new CurrencyCapability(strtolower($value), 0);

                continue;
            }

            if (is_array($value) && isset($value['code'])) {
                $mapped[] = new CurrencyCapability(
                    strtolower((string) $value['code']),
                    (int) ($value['minor_unit'] ?? 0),
                );
            }
        }

        return $mapped !== [] ? $mapped : [new CurrencyCapability('jpy', 0)];
    }

    /** @param array<array-key, mixed> $raw */
    private function limits(array $raw): CapabilityLimits
    {
        $supported = new CapabilityRule(CapabilitySupport::Supported);
        $unsupported = new CapabilityRule(CapabilitySupport::Unsupported);

        return new CapabilityLimits(
            ($raw['partial_capture'] ?? false) ? $supported : $unsupported,
            ($raw['partial_refund'] ?? false) ? $supported : $unsupported,
            ($raw['multiple_refunds'] ?? false) ? $supported : $unsupported,
            isset($raw['min_amount']) ? (int) $raw['min_amount'] : null,
            isset($raw['max_amount']) ? (int) $raw['max_amount'] : null,
            isset($raw['authorization_ttl']) ? (int) $raw['authorization_ttl'] : null,
            isset($raw['capture_ttl']) ? (int) $raw['capture_ttl'] : null,
            isset($raw['refund_ttl']) ? (int) $raw['refund_ttl'] : null,
        );
    }

    /** @param array<array-key, mixed> $raw */
    private function recovery(array $raw): RecoveryCapability
    {
        return new RecoveryCapability(
            (bool) ($raw['retrieve_payment'] ?? true),
            (bool) ($raw['retrieve_refund'] ?? true),
            (bool) ($raw['webhook_verification'] ?? true),
            isset($raw['strategy']) ? (string) $raw['strategy'] : 'daily_reconciliation',
        );
    }

    /** @param array<array-key, mixed> $values
     * @return list<string>
     */
    private function stringList(array $values, string $field): array
    {
        $mapped = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $mapped[] = $value;
            }
        }

        if ($mapped === [] && $field === 'brands') {
            return ['account_configured'];
        }

        if ($mapped === [] && $field === 'merchant_identity_requirements') {
            return ['connected_account'];
        }

        return array_values($mapped);
    }
}
