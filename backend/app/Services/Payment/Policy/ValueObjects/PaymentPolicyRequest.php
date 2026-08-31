<?php

namespace App\Services\Payment\Policy\ValueObjects;

use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Gateway\ValueObjects\GatewayValue;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PaymentPolicyRequest extends GatewayValue
{
    public string $organizationId;

    public string $brandId;

    public string $branchId;

    public string $identityBrandId;

    public string $branchOrgUnitId;

    public string $optionId;

    public ?string $paymentBrand;

    public ?string $deviceId;

    public string $deviceClass;

    public string $currency;

    public string $correlationId;

    /** @var array<string, bool|string> */
    public array $capabilityFacts;

    /** @param array<string, bool|string> $capabilityFacts */
    public function __construct(
        string $organizationId,
        string $brandId,
        string $branchId,
        string $identityBrandId,
        string $branchOrgUnitId,
        string $optionId,
        ?string $paymentBrand,
        ?string $deviceId,
        public PaymentChannelEnum $channel,
        string $deviceClass,
        string $currency,
        public PaymentGatewayEnvironmentEnum $environment,
        public GatewayCapability $operation,
        public DateTimeImmutable $operationStartedAt,
        string $correlationId,
        array $capabilityFacts = [],
    ) {
        $this->organizationId = self::uuid($organizationId, 'organizationId');
        $this->brandId = self::uuid($brandId, 'brandId');
        $this->branchId = self::uuid($branchId, 'branchId');
        $this->identityBrandId = self::externalId($identityBrandId, 'identityBrandId');
        $this->branchOrgUnitId = self::externalId($branchOrgUnitId, 'branchOrgUnitId');
        $this->optionId = self::uuid($optionId, 'optionId');
        $this->paymentBrand = PaymentBrand::normalize($paymentBrand);
        $this->deviceId = $deviceId === null ? null : self::uuid($deviceId, 'deviceId');
        $this->deviceClass = self::requestKey($deviceClass, 'deviceClass');
        $this->currency = strtoupper(trim($currency));
        $this->correlationId = self::requestKey($correlationId, 'correlationId');

        if (preg_match('/^[A-Z]{3}$/', $this->currency) !== 1) {
            throw new InvalidArgumentException('currency must be an ISO 4217 alpha-3 code.');
        }

        foreach ($capabilityFacts as $name => $value) {
            if (! is_string($name) || ! is_bool($value) && ! is_string($value)) {
                throw new InvalidArgumentException('capabilityFacts must contain scalar machine-evaluable facts.');
            }
        }

        ksort($capabilityFacts);
        $this->capabilityFacts = $capabilityFacts;
    }

    public function ownershipLookup(): BranchManagementLookup
    {
        return new BranchManagementLookup(
            $this->organizationId,
            $this->identityBrandId,
            $this->branchOrgUnitId,
            $this->operationStartedAt,
        );
    }
}
