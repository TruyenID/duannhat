<?php

namespace App\Services\Payment\Gateway\Contracts;

use Throwable;

/**
 * Plan-048 T7.5 / #1105 (J1) — per-provider outage classification for the
 * circuit breaker. Implementations live INSIDE the provider adapter
 * directories (the only place an SDK import is allowed) and are wired to the
 * breaker via `payments.circuit_breaker.outage_classifiers`, keeping the
 * provider-neutral gateway boundary SDK-free.
 */
interface ProviderOutageClassifier
{
    /**
     * True when the throwable represents a provider OUTAGE (transport
     * failure / provider 5xx) — never a decline or other mapped business
     * failure.
     */
    public function isProviderOutage(Throwable $exception): bool;
}
