<?php

namespace App\Services\Payment\Gateway\Commands;

use App\Omnify\Enums\PaymentAttemptOperationEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Services\Payment\Gateway\ValueObjects\EphemeralSecret;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Gateway\ValueObjects\GatewayRequestContext;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\RedactedData;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

final readonly class CreatePaymentCommand
{
    public string $orderId;

    public string $gatewayOptionId;

    public function __construct(
        public GatewayConnectionData $connection,
        public GatewayRequestContext $request,
        string $orderId,
        string $gatewayOptionId,
        public Money $money,
        public PaymentAttemptOperationEnum $operation,
        public PaymentChannelEnum $channel,
        public int $policyRevision,
        #[SensitiveParameter]
        ?string $paymentSourceReference = null,
        public RedactedData $metadata = new RedactedData,
    ) {
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->gatewayOptionId = self::uuid($gatewayOptionId, 'gatewayOptionId');

        if (! in_array($operation, [PaymentAttemptOperationEnum::Sale, PaymentAttemptOperationEnum::Authorize], true)) {
            throw new InvalidArgumentException('Create payment supports only sale or authorize operations.');
        }

        if ($policyRevision < 1) {
            throw new InvalidArgumentException('policyRevision must be at least one.');
        }

        if ($paymentSourceReference !== null && trim($paymentSourceReference) === '') {
            throw new InvalidArgumentException('paymentSourceReference cannot be blank.');
        }

        $this->paymentSource = $paymentSourceReference === null ? null : new EphemeralSecret($paymentSourceReference);
    }

    private ?EphemeralSecret $paymentSource;

    public function paymentSourceReference(): ?string
    {
        return $this->paymentSource?->reveal();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Commands containing payment source material cannot be serialized.');
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'connection' => $this->connection,
            'request' => $this->request,
            'orderId' => $this->orderId,
            'gatewayOptionId' => $this->gatewayOptionId,
            'money' => $this->money,
            'operation' => $this->operation,
            'channel' => $this->channel,
            'policyRevision' => $this->policyRevision,
            'metadata' => $this->metadata,
        ];
    }

    private static function uuid(string $value, string $name): string
    {
        $value = strtolower(trim($value));

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) !== 1) {
            throw new InvalidArgumentException("{$name} must be a valid UUID.");
        }

        return $value;
    }
}
