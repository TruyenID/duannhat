<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentOptionRailEnum;
use App\Services\Payment\Gateway\Enums\CapabilitySupport;
use App\Services\Payment\Gateway\Enums\CapabilityVerificationState;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Gateway\Enums\PaymentWorkflow;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;

/** Immutable, time-bounded capability snapshot. Missing dimensions fail closed. */
final readonly class CapabilitySet extends GatewayValue implements JsonSerializable
{
    public string $id;

    public string $integrationProduct;

    public string $apiVersion;

    public string $methodType;

    /** @var list<string> */
    public array $brands;

    /** @var list<PaymentChannelEnum> */
    public array $channels;

    /** @var list<string> */
    public array $deviceClasses;

    /** @var list<CurrencyCapability> */
    public array $currencies;

    /** @var list<PaymentWorkflow> */
    public array $workflows;

    /** @var list<OperationCapability> */
    public array $operations;

    /** @var list<string> */
    public array $merchantIdentityRequirements;

    /**
     * @param  list<string>  $brands
     * @param  list<PaymentChannelEnum>  $channels
     * @param  list<string>  $deviceClasses
     * @param  list<CurrencyCapability>  $currencies
     * @param  list<PaymentWorkflow>  $workflows
     * @param  list<OperationCapability>  $operations
     * @param  list<string>  $merchantIdentityRequirements
     */
    public function __construct(
        string $id,
        public int $revision,
        public PaymentGatewayProviderCodeEnum $provider,
        string $integrationProduct,
        string $apiVersion,
        public PaymentOptionRailEnum $rail,
        string $methodType,
        array $brands,
        array $channels,
        array $deviceClasses,
        array $currencies,
        public PaymentGatewayEnvironmentEnum $environment,
        array $workflows,
        array $operations,
        public CapabilityLimits $limits,
        public RecoveryCapability $recovery,
        array $merchantIdentityRequirements,
        public DateTimeImmutable $effectiveFrom,
        public ?DateTimeImmutable $effectiveTo,
        public CapabilityVerification $verification,
    ) {
        $this->id = self::requestKey($id, 'id');
        $this->integrationProduct = self::requestKey($integrationProduct, 'integrationProduct');
        $this->apiVersion = self::nonEmpty($apiVersion, 'apiVersion', 100);
        $this->methodType = self::requestKey($methodType, 'methodType');

        if ($revision < 1) {
            throw new InvalidArgumentException('Capability revision must be at least one.');
        }

        $this->brands = self::uniqueStrings($brands, 'brands');
        $this->channels = self::uniqueObjects($channels, PaymentChannelEnum::class, 'channels');
        $this->deviceClasses = self::uniqueStrings($deviceClasses, 'deviceClasses');
        $this->currencies = self::uniqueObjects($currencies, CurrencyCapability::class, 'currencies', fn (CurrencyCapability $currency): string => $currency->code);
        $this->workflows = self::uniqueObjects($workflows, PaymentWorkflow::class, 'workflows');
        $this->operations = self::uniqueObjects($operations, OperationCapability::class, 'operations', fn (OperationCapability $operation): string => $operation->operation->value);
        $this->merchantIdentityRequirements = self::uniqueStrings($merchantIdentityRequirements, 'merchantIdentityRequirements');

        foreach (['channels' => $this->channels, 'currencies' => $this->currencies, 'workflows' => $this->workflows, 'operations' => $this->operations, 'merchantIdentityRequirements' => $this->merchantIdentityRequirements] as $name => $values) {
            if ($values === []) {
                throw new InvalidArgumentException("Capability {$name} cannot be empty.");
            }
        }

        if ($effectiveTo !== null && $effectiveTo <= $effectiveFrom) {
            throw new InvalidArgumentException('Capability effectiveTo must be after effectiveFrom.');
        }

        $this->assertCoherent();
    }

    public function supports(
        GatewayCapability $capability,
        DateTimeImmutable $operationStartedAt,
        array $facts = [],
    ): bool {
        $operation = $this->operation($capability);
        if ($operation === null
            || ! $this->appliesAt($operationStartedAt)
            || $this->verification->state !== CapabilityVerificationState::Verified
            || ! $this->verification->hasApplicableEvidence($operationStartedAt)) {
            return false;
        }

        return match ($operation->rule->support) {
            CapabilitySupport::Supported => true,
            CapabilitySupport::Unsupported => false,
            CapabilitySupport::Conditional => $operation->rule->condition?->evaluate($facts) === true,
        };
    }

    public function appliesAt(DateTimeImmutable $startedAt): bool
    {
        return $startedAt >= $this->effectiveFrom
            && ($this->effectiveTo === null || $startedAt < $this->effectiveTo);
    }

    public function currency(string $code): ?CurrencyCapability
    {
        $code = strtoupper(trim($code));

        foreach ($this->currencies as $currency) {
            if ($currency->code === $code) {
                return $currency;
            }
        }

        return null;
    }

    public function operation(GatewayCapability $capability): ?OperationCapability
    {
        foreach ($this->operations as $operation) {
            if ($operation->operation === $capability) {
                return $operation;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'revision' => $this->revision,
            'provider' => $this->provider->value,
            'integration_product' => $this->integrationProduct,
            'api_version' => $this->apiVersion,
            'rail' => $this->rail->value,
            'method_type' => $this->methodType,
            'brands' => $this->brands,
            'channels' => array_column($this->channels, 'value'),
            'device_classes' => $this->deviceClasses,
            'currencies' => $this->currencies,
            'environment' => $this->environment->value,
            'workflows' => array_column($this->workflows, 'value'),
            'operations' => $this->operations,
            'limits' => $this->limits,
            'recovery' => $this->recovery,
            'merchant_identity' => $this->merchantIdentityRequirements,
            'effective_from' => $this->effectiveFrom->format(DATE_ATOM),
            'effective_to' => $this->effectiveTo?->format(DATE_ATOM),
            'verification' => $this->verification,
        ];
    }

    /** @param array<array-key, mixed> $values
     * @return list<string>
     */
    private static function uniqueStrings(array $values, string $name): array
    {
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 100) {
                throw new InvalidArgumentException("Capability {$name} contains an invalid value.");
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @template T of object
     *
     * @param  array<array-key, mixed>  $values
     * @param  class-string<T>  $class
     * @param  (callable(T): string)|null  $identity
     * @return list<T>
     */
    private static function uniqueObjects(array $values, string $class, string $name, ?callable $identity = null): array
    {
        $result = [];
        $seen = [];

        foreach ($values as $value) {
            if (! $value instanceof $class) {
                throw new InvalidArgumentException("Capability {$name} contains an invalid value.");
            }

            $key = $identity === null ? serialize($value) : $identity($value);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Capability {$name} contains duplicate identity [{$key}].");
            }

            $seen[$key] = true;
            $result[] = $value;
        }

        return $result;
    }

    private function assertCoherent(): void
    {
        $create = $this->canOperate(GatewayCapability::Create);
        $authorize = $this->canOperate(GatewayCapability::Authorize);
        $capture = $this->canOperate(GatewayCapability::Capture);
        $refund = $this->canOperate(GatewayCapability::Refund);
        $retrievePayment = $this->canOperate(GatewayCapability::RetrievePayment);
        $retrieveRefund = $this->canOperate(GatewayCapability::RetrieveRefund);
        $webhook = $this->canOperate(GatewayCapability::WebhookVerification);

        if (in_array(PaymentWorkflow::Sale, $this->workflows, true) && ! $create) {
            throw new InvalidArgumentException('Sale workflow requires an available create operation.');
        }

        // #1158 — a separate `authorize` operation is OPTIONAL. Holding funds
        // for a later capture requires create + capture; whether the hold is a
        // distinct call or a manual-capture mode of create is a provider detail,
        // and the common case authorizes THROUGH create with no standalone
        // authorize call at all.
        if (in_array(PaymentWorkflow::AuthorizeCapture, $this->workflows, true) && (! $create || ! $capture)) {
            throw new InvalidArgumentException('Authorize/capture workflow requires create and capture operations.');
        }

        if (($capture || $authorize) && ! in_array(PaymentWorkflow::AuthorizeCapture, $this->workflows, true)) {
            throw new InvalidArgumentException('Capture operation requires the authorize/capture workflow.');
        }

        if ($this->limits->partialCapture->support !== CapabilitySupport::Unsupported && ! $capture) {
            throw new InvalidArgumentException('Partial capture limits require an available capture operation.');
        }

        if (($this->limits->partialRefund->support !== CapabilitySupport::Unsupported
            || $this->limits->multipleRefunds->support !== CapabilitySupport::Unsupported) && ! $refund) {
            throw new InvalidArgumentException('Refund limits require an available refund operation.');
        }

        if ($refund && ! ($this->recovery->pollRefund || $this->recovery->webhookEvents || $this->recovery->reconciliationArtifact !== null)) {
            throw new InvalidArgumentException('Refund operation requires a recovery source.');
        }

        if ($this->recovery->pollPayment !== $retrievePayment) {
            throw new InvalidArgumentException('Payment polling and retrieve-payment operation must agree.');
        }

        if ($this->recovery->pollRefund !== $retrieveRefund) {
            throw new InvalidArgumentException('Refund polling and retrieve-refund operation must agree.');
        }

        if (($create || $authorize || $capture)
            && ! ($retrievePayment || $webhook || $this->recovery->reconciliationArtifact !== null)) {
            throw new InvalidArgumentException('Money-moving payment operations require a recovery source.');
        }

        if ($webhook !== $this->recovery->webhookEvents) {
            throw new InvalidArgumentException('Webhook verification operation and recovery declaration must agree.');
        }
    }

    private function canOperate(GatewayCapability $capability): bool
    {
        $operation = $this->operation($capability);

        return $operation !== null && $operation->rule->support !== CapabilitySupport::Unsupported;
    }
}
