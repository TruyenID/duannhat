<?php

namespace App\Services\Payment\Gateway\Support;

use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\Contracts\ProviderOutageClassifier;
use App\Services\Payment\Gateway\Exceptions\PaymentGatewayException;
use App\Services\Payment\Gateway\Exceptions\PaymentProviderCircuitOpen;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plan-048 T7.5 / #1105 (J1) — adapter-level circuit breaker, per
 * (provider, connection).
 *
 * FLAG-GATED, DEFAULT OFF (`payments.circuit_breaker.enabled`): the deferral
 * decision on #1105 stands for production — thresholds must be tuned against
 * real Gate-7 error-rate data before this is switched on. The runtime ships now
 * so ops can flip a flag instead of waiting on a deploy.
 *
 * State machine (cache-backed so every PHP worker shares it):
 *   closed     — normal. Provider-OUTAGE failures increment a windowed counter.
 *   open       — counter hit the threshold. `guardCreate` refuses with
 *                `PAYMENT_PROVIDER_CIRCUIT_OPEN` until the cooldown elapses.
 *   half-open  — cooldown elapsed: exactly ONE request wins the probe slot and
 *                is allowed through; everyone else keeps getting refused.
 *                Probe success closes the circuit, probe failure re-opens it
 *                for a fresh cooldown.
 *
 * Only provider OUTAGES count (transport errors / provider 5xx). Mapped
 * business failures — declines, validation, auth/config problems (any
 * PaymentGatewayException) — never trip the breaker: a run of declined cards
 * is not an outage, and refusing to sell because of it would be the
 * self-inflicted outage J1's deferral warned about.
 */
class PaymentProviderCircuitBreaker
{
    public function enabled(): bool
    {
        return (bool) config('payments.circuit_breaker.enabled', false);
    }

    /**
     * Gate a CREATE (new money) — throws when the circuit is open. Call this
     * BEFORE any provider call / attempt reservation. Non-create operations
     * (capture, refund, retrieve — the recovery paths) are never refused;
     * they only feed the state machine via record*().
     */
    public function guardCreate(
        PaymentGatewayProviderCodeEnum $provider,
        string $connectionId,
        string $correlationId,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        $openedUntil = Cache::get($this->key($provider, $connectionId, 'open_until'));
        if ($openedUntil === null) {
            return;
        }

        $now = now()->getTimestamp();
        if ($now < (int) $openedUntil) {
            throw new PaymentProviderCircuitOpen(
                $provider,
                $connectionId,
                $correlationId,
                (int) $openedUntil - $now,
            );
        }

        // Cooldown elapsed → half-open. Cache::add is the atomic winner-takes-
        // the-probe: the first request through proceeds, the rest stay refused
        // until the probe resolves (success closes, failure re-opens).
        $probeTtl = max(10, (int) config('payments.circuit_breaker.probe_ttl_seconds', 30));
        if (! Cache::add($this->key($provider, $connectionId, 'probe'), $correlationId, $probeTtl)) {
            throw new PaymentProviderCircuitOpen(
                $provider,
                $connectionId,
                $correlationId,
                $probeTtl,
            );
        }
    }

    public function recordSuccess(
        PaymentGatewayProviderCodeEnum $provider,
        string $connectionId,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        $wasOpen = Cache::get($this->key($provider, $connectionId, 'open_until')) !== null;

        Cache::forget($this->key($provider, $connectionId, 'failures'));
        Cache::forget($this->key($provider, $connectionId, 'open_until'));
        Cache::forget($this->key($provider, $connectionId, 'probe'));

        if ($wasOpen) {
            Log::channel('payment_orchestration')->info('payment_provider_circuit_closed', [
                'provider' => $provider->value,
                'connection_id' => $connectionId,
            ]);
        }
    }

    public function recordFailure(
        PaymentGatewayProviderCodeEnum $provider,
        string $connectionId,
        Throwable $exception,
    ): void {
        if (! $this->enabled() || ! $this->isProviderOutage($exception)) {
            return;
        }

        $window = max(1, (int) config('payments.circuit_breaker.failure_window_seconds', 120));
        $threshold = max(1, (int) config('payments.circuit_breaker.failure_threshold', 5));
        $cooldown = max(1, (int) config('payments.circuit_breaker.cooldown_seconds', 60));

        $failuresKey = $this->key($provider, $connectionId, 'failures');
        Cache::add($failuresKey, 0, $window);
        $failures = (int) Cache::increment($failuresKey);

        // A failed half-open probe re-opens immediately regardless of count.
        $probing = Cache::get($this->key($provider, $connectionId, 'probe')) !== null;

        if ($failures < $threshold && ! $probing) {
            return;
        }

        Cache::put(
            $this->key($provider, $connectionId, 'open_until'),
            now()->getTimestamp() + $cooldown,
            $cooldown + $window,
        );
        Cache::forget($this->key($provider, $connectionId, 'probe'));
        Cache::forget($failuresKey);

        Log::channel('payment_orchestration')->warning('payment_provider_circuit_opened', [
            'provider' => $provider->value,
            'connection_id' => $connectionId,
            'failures' => $failures,
            'threshold' => $threshold,
            'cooldown_seconds' => $cooldown,
            'reopened_by_probe' => $probing,
            'exception' => $exception::class,
        ]);
    }

    /**
     * Provider OUTAGE classifier — the only failures that may trip the
     * breaker. Everything mapped/business-level is excluded by design.
     *
     * SDK-specific classification is delegated to per-adapter
     * ProviderOutageClassifier implementations wired via
     * `payments.circuit_breaker.outage_classifiers` — this neutral layer must
     * stay free of provider SDK imports (enforced by the gateway-boundary
     * architecture test).
     */
    private function isProviderOutage(Throwable $e): bool
    {
        if ($e instanceof PaymentGatewayException) {
            return false;
        }

        // Transport-level failures from any HTTP stack (cURL/Guzzle/Laravel).
        if ($e instanceof ConnectionException || $e instanceof ConnectException) {
            return true;
        }

        foreach ((array) config('payments.circuit_breaker.outage_classifiers', []) as $class) {
            $classifier = app($class);
            if ($classifier instanceof ProviderOutageClassifier && $classifier->isProviderOutage($e)) {
                return true;
            }
        }

        return false;
    }

    private function key(
        PaymentGatewayProviderCodeEnum $provider,
        string $connectionId,
        string $suffix,
    ): string {
        return "payments:circuit:{$provider->value}:{$connectionId}:{$suffix}";
    }
}
