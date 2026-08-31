<?php

namespace App\Services\Payment\Gateway;

use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\Contracts\PaymentGatewayContract;
use App\Services\Payment\Gateway\Exceptions\InvalidPaymentGatewayDriver;
use App\Services\Payment\Gateway\Exceptions\UnsupportedPaymentGatewayProvider;
use App\Services\Payment\Gateway\Support\CircuitBreakerGateway;
use App\Services\Payment\Gateway\Support\PaymentProviderCircuitBreaker;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Resolves only explicitly configured provider adapters.
 *
 * Connection credentials are intentionally absent from this registry. An adapter receives the
 * non-secret connection identity in each command and resolves credentials through the server-only
 * secret boundary introduced by T2.4.
 */
final class PaymentGatewayRegistry
{
    /** @var array<string, class-string|non-empty-string> */
    private array $drivers;

    /**
     * @param  array<string, mixed>  $drivers  provider code => container service/class
     */
    public function __construct(
        private readonly Container $container,
        array $drivers,
    ) {
        $this->drivers = $this->validate($drivers);
    }

    public function forConnection(GatewayConnectionData $connection, string $correlationId): PaymentGatewayContract
    {
        return $this->forProvider($connection->provider, $correlationId);
    }

    public function forProvider(
        PaymentGatewayProviderCodeEnum $provider,
        string $correlationId,
    ): PaymentGatewayContract {
        $service = $this->drivers[$provider->value] ?? null;

        if ($service === null) {
            throw new UnsupportedPaymentGatewayProvider($provider, $this->configuredProviders(), $correlationId);
        }

        try {
            $driver = $this->container->make($service);
        } catch (Throwable) {
            throw new InvalidPaymentGatewayDriver($provider, 'unresolvable');
        }

        if (! $driver instanceof PaymentGatewayContract) {
            throw new InvalidPaymentGatewayDriver($provider, 'contract_mismatch');
        }

        // Plan-048 T7.5 / #1105 (J1) — with the flag ON, every registry-
        // resolved adapter runs behind the circuit breaker. Default OFF (the
        // #1105 deferral stands until thresholds are tuned on Gate-7 data), in
        // which case the raw driver is returned untouched — same runtime shape
        // as before the breaker existed. Read through the container (not the
        // config() helper) so a bare-Container unit-test registry — no config
        // service bound — simply resolves the raw driver.
        $breakerEnabled = $this->container->bound('config')
            && (bool) $this->container->make('config')->get('payments.circuit_breaker.enabled', false);
        if ($breakerEnabled) {
            return new CircuitBreakerGateway(
                $driver,
                $this->container->make(PaymentProviderCircuitBreaker::class),
            );
        }

        return $driver;
    }

    /** @return list<PaymentGatewayProviderCodeEnum> */
    public function configuredProviders(): array
    {
        return array_map(
            static fn (string $provider): PaymentGatewayProviderCodeEnum => PaymentGatewayProviderCodeEnum::from($provider),
            array_keys($this->drivers),
        );
    }

    /**
     * @param  array<string, mixed>  $drivers
     * @return array<string, class-string|non-empty-string>
     */
    private function validate(array $drivers): array
    {
        $validated = [];

        foreach ($drivers as $provider => $service) {
            if (! is_string($provider) || PaymentGatewayProviderCodeEnum::tryFrom($provider) === null) {
                throw new InvalidPaymentGatewayDriver(null, 'unknown_provider');
            }

            if (! is_string($service) || trim($service) === '') {
                throw new InvalidPaymentGatewayDriver(PaymentGatewayProviderCodeEnum::from($provider), 'invalid_service');
            }

            $validated[$provider] = $service;
        }

        ksort($validated);

        return $validated;
    }
}
