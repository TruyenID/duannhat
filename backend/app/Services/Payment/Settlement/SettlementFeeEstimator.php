<?php

namespace App\Services\Payment\Settlement;

use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnectionOption;

/**
 * Plan-050 L1 (T1.2) — DASHBOARD-ONLY gateway fee estimate.
 *
 * Reads the `fee_estimate` declaration from the payment gateway connection
 * option's `merchant_configuration` JSON:
 *
 *     "fee_estimate": { "percent": 3.6, "fixed_minor": 0 }
 *
 * and computes `round(amount * percent / 100) + fixed_minor` in minor units.
 *
 * G1 CONTRACT — an estimate is NEVER the truth:
 *  - it is stamped onto payment_attempts.estimated_fee_minor (a column whose
 *    comment says "ESTIMATE ONLY"), never onto payment_settlements;
 *  - every official report reads payment_settlements exclusively — the
 *    Settlement services never reference estimated_fee_minor (enforced by
 *    the estimate-never-authoritative contract test);
 *  - no declared config ⇒ null (no guessed default rate, ever).
 */
final class SettlementFeeEstimator
{
    /**
     * Estimate the gateway fee for a succeeded attempt, or null when the
     * connection option declares no fee_estimate.
     */
    public function estimateForAttempt(PaymentAttempt $attempt): ?int
    {
        if ($attempt->connection_option_id === null) {
            return null;
        }

        $option = PaymentGatewayConnectionOption::query()->find($attempt->connection_option_id);
        if ($option === null) {
            return null;
        }

        return $this->estimate((int) $attempt->amount_minor, $this->feeEstimateConfig($option));
    }

    /**
     * @param  array<string, mixed>|null  $config  {percent, fixed_minor}
     */
    public function estimate(int $amountMinor, ?array $config): ?int
    {
        if ($config === null) {
            return null;
        }

        $percent = $config['percent'] ?? null;
        $fixedMinor = $config['fixed_minor'] ?? null;

        // A config that declares NEITHER component is not an estimate — null,
        // never a guessed 0 that could read as "this gateway is free" (G1).
        if (! is_numeric($percent) && ! is_numeric($fixedMinor)) {
            return null;
        }

        $percentFee = is_numeric($percent)
            ? (int) round($amountMinor * ((float) $percent) / 100)
            : 0;

        return $percentFee + (is_numeric($fixedMinor) ? (int) $fixedMinor : 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function feeEstimateConfig(PaymentGatewayConnectionOption $option): ?array
    {
        $configuration = $option->merchant_configuration;
        if (! is_array($configuration)) {
            return null;
        }

        $feeEstimate = $configuration['fee_estimate'] ?? null;

        return is_array($feeEstimate) ? $feeEstimate : null;
    }
}
