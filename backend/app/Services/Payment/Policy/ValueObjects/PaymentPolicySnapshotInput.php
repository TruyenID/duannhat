<?php

namespace App\Services\Payment\Policy\ValueObjects;

use App\Services\Payment\Gateway\ValueObjects\GatewayValue;
use InvalidArgumentException;

final readonly class PaymentPolicySnapshotInput extends GatewayValue
{
    public string $organizationId;

    public string $brandId;

    public string $branchId;

    public string $ownershipRevision;

    public string $configurationHash;

    /** @var list<EffectivePaymentOption> */
    public array $options;

    /** @param list<EffectivePaymentOption> $options */
    public function __construct(
        string $organizationId,
        string $brandId,
        string $branchId,
        string $ownershipRevision,
        string $configurationHash,
        array $options,
    ) {
        $this->organizationId = self::uuid($organizationId, 'organizationId');
        $this->brandId = self::uuid($brandId, 'brandId');
        $this->branchId = self::uuid($branchId, 'branchId');
        $this->ownershipRevision = OpaqueOwnershipRevision::validate($ownershipRevision);
        $this->configurationHash = strtolower(trim($configurationHash));

        if (preg_match('/^[0-9a-f]{64}$/', $this->configurationHash) !== 1) {
            throw new InvalidArgumentException('configurationHash must be a lowercase SHA-256 digest.');
        }

        $seen = [];
        foreach ($options as $option) {
            if (! $option instanceof EffectivePaymentOption) {
                throw new InvalidArgumentException('Snapshot options contain an invalid value.');
            }

            if (self::uuid($option->optionId, 'option.optionId') !== $option->optionId) {
                throw new InvalidArgumentException('Snapshot option identifiers must use canonical lowercase UUIDs.');
            }

            foreach ([
                'connectionId' => $option->connectionId,
                'connectionOptionId' => $option->connectionOptionId,
                'shopOptionId' => $option->shopOptionId,
                'operatorOrgUnitId' => $option->operatorOrgUnitId,
            ] as $name => $identifier) {
                if ($identifier !== null && self::uuid($identifier, "option.{$name}") !== $identifier) {
                    throw new InvalidArgumentException('Snapshot option identifiers must use canonical lowercase UUIDs.');
                }
            }

            if ($option->ownershipRevision !== null) {
                OpaqueOwnershipRevision::validate($option->ownershipRevision);
            }

            if (isset($seen[$option->optionId])) {
                throw new InvalidArgumentException("Snapshot contains duplicate option [{$option->optionId}].");
            }

            if ($option->ownershipRevision !== null
                && $option->ownershipRevision !== $this->ownershipRevision) {
                throw new InvalidArgumentException('Snapshot option ownership revision does not match publication scope.');
            }

            if ($option->effective
                && ($option->connectionId === null
                    || $option->connectionOptionId === null
                    || $option->shopOptionId === null
                    || $option->ownerScope === null
                    || $option->operatorOrgUnitId === null
                    || $option->ownershipRevision === null)) {
                throw new InvalidArgumentException('An effective option requires complete immutable connection and owner identity.');
            }

            $seen[$option->optionId] = true;
        }

        $this->options = array_values($options);
    }
}
