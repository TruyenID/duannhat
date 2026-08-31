<?php

namespace App\Services\Payment\Settlement;

use App\Models\PaymentGatewayConnection;
use App\Models\PaymentSettlement;
use App\Services\Payment\Settlement\Enums\SettlementKind;
use App\Services\Payment\Settlement\Enums\SettlementSource;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use App\Services\Payment\Settlement\Exceptions\SettlementRowIntegrityException;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Plan-050 — the ONLY sanctioned builder of payment_settlements rows.
 *
 * Guarantees before anything touches the DB:
 *  - S-15: `net = gross - fee - fee_tax` or the row is refused — we never
 *    store a self-contradictory number.
 *  - S-11: amounts are signed integers passed through untouched — no abs(),
 *    no clamping; negative refunds/disputes/payouts are legal values.
 *  - S-16: fee_tax is whatever the statement/API said (caller-supplied),
 *    never derived by multiplying a guessed rate.
 *  - Idempotency: persistence funnels through the UNIQUE
 *    (provider, external_ref) constraint — a concurrent duplicate resolves
 *    to the existing row instead of throwing (G3: retry is the default).
 */
final class SettlementRowAssembler
{
    /**
     * Validate row integrity and return the attribute array.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    public function assemble(
        PaymentGatewayConnection $connection,
        SettlementKind $kind,
        SettlementSource $source,
        string $externalRef,
        int $grossMinor,
        int $feeMinor,
        int $feeTaxMinor,
        int $netMinor,
        string $currency,
        ?DateTimeInterface $providerSettledAt,
        SettlementStatus $status = SettlementStatus::PendingPayout,
        ?string $orderPaymentId = null,
        ?string $paymentAttemptId = null,
        ?string $reportBatchId = null,
        ?string $payoutId = null,
        ?array $metadata = null,
    ): array {
        if ($externalRef === '') {
            throw new SettlementRowIntegrityException('Settlement row requires a non-empty external_ref.');
        }

        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new SettlementRowIntegrityException(
                "Settlement row {$externalRef} carries a malformed currency \"{$currency}\".",
            );
        }

        // S-15 [HARD] — the invariant every downstream Σ relies on. A gateway
        // row that does not satisfy it is corrupt input, not a rounding case.
        if ($netMinor !== $grossMinor - $feeMinor - $feeTaxMinor) {
            throw new SettlementRowIntegrityException(sprintf(
                'Settlement row %s violates net = gross - fee - fee_tax (gross=%d fee=%d fee_tax=%d net=%d, expected net=%d).',
                $externalRef,
                $grossMinor,
                $feeMinor,
                $feeTaxMinor,
                $netMinor,
                $grossMinor - $feeMinor - $feeTaxMinor,
            ));
        }

        return [
            'connection_id' => (string) $connection->id,
            'provider' => $this->providerCode($connection),
            'kind' => $kind,
            'order_payment_id' => $orderPaymentId,
            'payment_attempt_id' => $paymentAttemptId,
            'gross_minor' => $grossMinor,
            'fee_minor' => $feeMinor,
            'fee_tax_minor' => $feeTaxMinor,
            'net_minor' => $netMinor,
            'currency' => $currency,
            'source' => $source,
            'external_ref' => $externalRef,
            'report_batch_id' => $reportBatchId,
            'payout_id' => $payoutId,
            'provider_settled_at' => $providerSettledAt,
            'status' => $status,
            'metadata' => $metadata,
        ];
    }

    /**
     * Idempotent persist: the (provider, external_ref) DB constraint is the
     * arbiter. Returns [row, wasCreated] — a duplicate (webhook redelivery,
     * report re-import, concurrent worker) resolves to the existing row.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: PaymentSettlement, 1: bool}
     */
    public function persistIdempotent(array $attributes): array
    {
        $existing = PaymentSettlement::query()
            ->where('provider', $attributes['provider'])
            ->where('external_ref', $attributes['external_ref'])
            ->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            return [PaymentSettlement::query()->create($attributes), true];
        } catch (UniqueConstraintViolationException) {
            // Lost the race to a concurrent worker — the constraint held (G3).
            $row = PaymentSettlement::query()
                ->where('provider', $attributes['provider'])
                ->where('external_ref', $attributes['external_ref'])
                ->firstOrFail();

            return [$row, false];
        }
    }

    private function providerCode(PaymentGatewayConnection $connection): string
    {
        $connection->loadMissing('provider');
        $code = $connection->provider->code;

        return $code instanceof \BackedEnum ? (string) $code->value : (string) $code;
    }
}
