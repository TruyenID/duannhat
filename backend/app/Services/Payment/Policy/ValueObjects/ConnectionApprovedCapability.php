<?php

namespace App\Services\Payment\Policy\ValueObjects;

use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentOptionRailEnum;
use App\Services\Payment\Gateway\Enums\CapabilitySupport;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\GatewayValue;
use App\Services\Payment\Gateway\ValueObjects\OperationCapability;
use App\Services\Payment\Policy\Enums\ConnectionCapabilityVerification;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

/** Exact catalog identity plus the account-approved dimensions that may only narrow it. */
final readonly class ConnectionApprovedCapability extends GatewayValue
{
    public string $capabilityId;

    public string $capabilityHash;

    public string $integrationProduct;

    public string $apiVersion;

    public string $methodType;

    /** @var list<string> */
    public array $approvedBrands;

    /** @var list<string> */
    public array $approvedDeviceClasses;

    /** @var list<string> */
    public array $configuredMerchantIdentities;

    /** @var list<string> */
    public array $approvedCurrencies;

    /** @var list<PaymentChannelEnum> */
    public array $approvedChannels;

    /** @var list<OperationCapability> */
    public array $approvedOperations;

    public ?string $evidenceReference;

    /**
     * @param  list<string>  $approvedBrands
     * @param  list<string>  $approvedDeviceClasses
     * @param  list<string>  $configuredMerchantIdentities
     * @param  list<string>  $approvedCurrencies
     * @param  list<PaymentChannelEnum>  $approvedChannels
     * @param  list<OperationCapability>  $approvedOperations
     */
    public function __construct(
        string $capabilityId,
        public int $catalogRevision,
        string $capabilityHash,
        string $integrationProduct,
        string $apiVersion,
        public PaymentOptionRailEnum $rail,
        string $methodType,
        array $approvedBrands,
        array $approvedDeviceClasses,
        array $configuredMerchantIdentities,
        public ConnectionCapabilityVerification $verification,
        public bool $enabled,
        array $approvedCurrencies,
        array $approvedChannels,
        array $approvedOperations,
        public DateTimeImmutable $effectiveFrom,
        public ?DateTimeImmutable $effectiveTo,
        ?string $evidenceReference,
    ) {
        $this->capabilityId = self::requestKey($capabilityId, 'capabilityId');
        $this->capabilityHash = strtolower(trim($capabilityHash));
        $this->integrationProduct = self::requestKey($integrationProduct, 'integrationProduct');
        $this->apiVersion = self::nonEmpty($apiVersion, 'apiVersion', 100);
        $this->methodType = self::requestKey($methodType, 'methodType');
        $this->approvedBrands = $this->brands($approvedBrands);
        $this->approvedDeviceClasses = $this->strings($approvedDeviceClasses, 'approvedDeviceClasses');
        $this->configuredMerchantIdentities = $this->strings($configuredMerchantIdentities, 'configuredMerchantIdentities');
        $this->approvedCurrencies = $this->currencies($approvedCurrencies);
        $this->approvedChannels = $this->channels($approvedChannels);
        $this->approvedOperations = $this->operations($approvedOperations);
        $this->evidenceReference = $evidenceReference === null
            ? null
            : self::nonEmpty($evidenceReference, 'evidenceReference');

        if ($catalogRevision < 1 || preg_match('/^[0-9a-f]{64}$/', $this->capabilityHash) !== 1) {
            throw new InvalidArgumentException('Connection capability requires a valid catalog revision and SHA-256 hash.');
        }

        if ($effectiveTo !== null && $effectiveTo <= $effectiveFrom) {
            throw new InvalidArgumentException('Connection capability effectiveTo must be after effectiveFrom.');
        }
    }

    public function matchesCatalog(CapabilitySet $catalog, ?string $requestedBrand): bool
    {
        if ($this->capabilityId !== $catalog->id
            || $this->catalogRevision !== $catalog->revision
            || ! hash_equals($this->capabilityHash, self::fingerprint($catalog))
            || $this->integrationProduct !== $catalog->integrationProduct
            || $this->apiVersion !== $catalog->apiVersion
            || $this->rail !== $catalog->rail
            || $this->methodType !== $catalog->methodType
            || ! $this->isSubset($this->approvedDeviceClasses, $catalog->deviceClasses)
            || ! $this->containsAll($this->configuredMerchantIdentities, $catalog->merchantIdentityRequirements)) {
            return false;
        }

        try {
            $catalogBrands = array_map(
                static fn (string $brand): string => PaymentBrand::normalize($brand, 'catalog.brand')
                    ?? throw new InvalidArgumentException('Catalog brand cannot be null.'),
                $catalog->brands,
            );
            $requestedBrand = PaymentBrand::normalize($requestedBrand);
        } catch (InvalidArgumentException) {
            return false;
        }

        if (count($catalogBrands) !== count(array_unique($catalogBrands))
            || in_array(PaymentBrand::ACCOUNT_CONFIGURED, $this->approvedBrands, true)) {
            return false;
        }

        if ($catalogBrands === []) {
            return $requestedBrand === null && $this->approvedBrands === [];
        }

        if (in_array(PaymentBrand::ACCOUNT_CONFIGURED, $catalogBrands, true)) {
            return $catalogBrands === [PaymentBrand::ACCOUNT_CONFIGURED]
                && $requestedBrand !== null
                && $requestedBrand !== PaymentBrand::ACCOUNT_CONFIGURED
                && in_array($requestedBrand, $this->approvedBrands, true);
        }

        return $requestedBrand !== null
            && in_array($requestedBrand, $catalogBrands, true)
            && in_array($requestedBrand, $this->approvedBrands, true)
            && $this->isSubset($this->approvedBrands, $catalogBrands);
    }

    public function appliesAt(DateTimeImmutable $at): bool
    {
        return $at >= $this->effectiveFrom && ($this->effectiveTo === null || $at < $this->effectiveTo);
    }

    /** @param array<string, bool|string> $facts */
    public function supportsOperation(GatewayCapability $operation, array $facts): bool
    {
        foreach ($this->approvedOperations as $approved) {
            if ($approved->operation !== $operation) {
                continue;
            }

            return match ($approved->rule->support) {
                CapabilitySupport::Supported => true,
                CapabilitySupport::Unsupported => false,
                CapabilitySupport::Conditional => $approved->rule->condition?->evaluate($facts) === true,
            };
        }

        return false;
    }

    /** @throws JsonException */
    public static function fingerprint(CapabilitySet $capability): string
    {
        return hash('sha256', json_encode(
            $capability,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @param list<string> $subset
     * @param  list<string>  $superset
     */
    private function isSubset(array $subset, array $superset): bool
    {
        return array_diff($subset, $superset) === [];
    }

    /** @param list<string> $values
     * @param  list<string>  $required
     */
    private function containsAll(array $values, array $required): bool
    {
        return array_diff($required, $values) === [];
    }

    /** @param array<array-key, mixed> $values
     * @return list<string>
     */
    private function strings(array $values, string $name): array
    {
        $result = [];
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException("{$name} contains an invalid value.");
            }
            $result[] = $value;
        }

        return array_values(array_unique($result));
    }

    /** @param array<array-key, mixed> $values
     * @return list<string>
     */
    private function brands(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException('approvedBrands contains an invalid value.');
            }

            $brand = PaymentBrand::normalize($value, 'approvedBrands');
            $result[$brand] = $brand;
        }

        return array_values($result);
    }

    /** @param array<array-key, mixed> $values
     * @return list<string>
     */
    private function currencies(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (! is_string($value) || preg_match('/^[A-Za-z]{3}$/', trim($value)) !== 1) {
                throw new InvalidArgumentException('approvedCurrencies contains an invalid ISO currency.');
            }
            $result[] = strtoupper(trim($value));
        }

        return array_values(array_unique($result));
    }

    /** @param array<array-key, mixed> $values
     * @return list<PaymentChannelEnum>
     */
    private function channels(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (! $value instanceof PaymentChannelEnum) {
                throw new InvalidArgumentException('approvedChannels contains an invalid value.');
            }
            $result[$value->value] = $value;
        }

        return array_values($result);
    }

    /** @param array<array-key, mixed> $values
     * @return list<OperationCapability>
     */
    private function operations(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (! $value instanceof OperationCapability) {
                throw new InvalidArgumentException('approvedOperations contains an invalid value.');
            }
            $result[$value->operation->value] = $value;
        }

        return array_values($result);
    }
}
