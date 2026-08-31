<?php

namespace App\Services\Payment\Observation;

use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Models\TillSession;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Omnify\Enums\TillSessionStatusEnum;
use App\Services\Payment\Gateway\PayPay\PayPayQrCodeClient;

/** Aggregates Plan 047 Gate 7 observation-window signals (T7.4). */
final class PaymentObservationReporter
{
    public function __construct(
        private readonly LedgerDriftScanner $ledgerDrift,
    ) {}

    /**
     * @return array{
     *     generated_at: string,
     *     ledger: array{inspected: int, drift_count: int, gap_payments: int},
     *     shadow_backlog: array{attempt_candidates: int, refund_candidates: int, total: int},
     *     refund_pending: array{prepared: int, submitted: int, pending: int, reconciliation_required: int, total: int},
     *     till_sessions_open: array{open: int, closing: int, total: int},
     *     paypay_qr: array{live: int, stale: int, grace_minutes: int},
     *     orchestrator_runtime: array{enabled: bool, transports: list<string>, transport_switches: array<string, bool>},
     *     gate_pass: bool
     * }
     */
    public function report(?string $organizationId = null, ?int $ledgerLimit = null, bool $strict = false): array
    {
        $ledger = $this->ledgerDrift->scan($organizationId, $ledgerLimit);
        $shadow = $this->countShadowBacklog();
        $shadowTotal = $shadow['attempt_candidates'] + $shadow['refund_candidates'];
        $refundPending = $this->countRefundPending();
        $tillOpen = $this->countOpenTillSessions();

        $gatePass = $ledger['drift_count'] === 0
            && (! $strict || $shadowTotal === 0);

        return [
            'generated_at' => now()->toIso8601String(),
            'ledger' => [
                'inspected' => $ledger['inspected'],
                'drift_count' => $ledger['drift_count'],
                'gap_payments' => $ledger['gap_payments'],
            ],
            'shadow_backlog' => [
                'attempt_candidates' => $shadow['attempt_candidates'],
                'refund_candidates' => $shadow['refund_candidates'],
                'total' => $shadowTotal,
            ],
            'refund_pending' => $refundPending,
            'till_sessions_open' => $tillOpen,
            'paypay_qr' => $this->countPayPayQrAttempts(),
            'orchestrator_runtime' => [
                'enabled' => (bool) config('payments.orchestrator_runtime.enabled', false),
                'transports' => array_values(config('payments.orchestrator_runtime.transports', [])),
                'transport_switches' => config('payments.orchestrator_runtime.transport_switches', []),
            ],
            'gate_pass' => $gatePass,
        ];
    }

    /**
     * @return array{attempt_candidates: int, refund_candidates: int}
     */
    private function countShadowBacklog(): array
    {
        return [
            'attempt_candidates' => PaymentAttempt::query()
                ->where('state', PaymentAttemptStateEnum::ReconciliationRequired->value)
                ->count(),
            'refund_candidates' => PaymentRefund::query()
                ->where('state', PaymentRefundStateEnum::ReconciliationRequired->value)
                ->count(),
        ];
    }

    /**
     * @return array{prepared: int, submitted: int, pending: int, reconciliation_required: int, total: int}
     */
    private function countRefundPending(): array
    {
        $counts = PaymentRefund::query()
            ->selectRaw('state, COUNT(*) as cnt')
            ->whereIn('state', [
                PaymentRefundStateEnum::Prepared->value,
                PaymentRefundStateEnum::Submitted->value,
                PaymentRefundStateEnum::Pending->value,
                PaymentRefundStateEnum::ReconciliationRequired->value,
            ])
            ->groupBy('state')
            ->pluck('cnt', 'state');

        $prepared = (int) ($counts[PaymentRefundStateEnum::Prepared->value] ?? 0);
        $submitted = (int) ($counts[PaymentRefundStateEnum::Submitted->value] ?? 0);
        $pending = (int) ($counts[PaymentRefundStateEnum::Pending->value] ?? 0);
        $reconciliationRequired = (int) ($counts[PaymentRefundStateEnum::ReconciliationRequired->value] ?? 0);

        return [
            'prepared' => $prepared,
            'submitted' => $submitted,
            'pending' => $pending,
            'reconciliation_required' => $reconciliationRequired,
            'total' => $prepared + $submitted + $pending + $reconciliationRequired,
        ];
    }

    /**
     * plan-054 T7.4 — outstanding customer-web PayPay QR codes.
     *
     * Deliberately NOT part of `gate_pass`. A live QR is a customer standing at
     * their phone; a non-zero count is the normal state of a working shop, not
     * a gate failure. `stale` is the number the operator actually reads: those
     * are past the sweep grace (the window after which an unscanned CREATED
     * code should have been retired, and any COMPLETED money should already
     * have been booked on an earlier every-minute tick). A stale count that
     * does not fall means the scheduler is not running the sweep — and an
     * unswept QR is money PayPay may have taken that TempoFast has not booked.
     *
     * Candidate definition is copied from the sweeper on purpose, including the
     * `tempoqr-` prefix: on the same provider, that prefix is the only thing
     * separating a QR attempt from a preauth one, and the two read from
     * different PayPay endpoints. Counting both would report preauth attempts
     * as un-swept QR backlog.
     *
     * @return array{live: int, stale: int, grace_minutes: int}
     */
    private function countPayPayQrAttempts(): array
    {
        $graceMinutes = (int) config('payments.paypay_qr.stale_sweep_grace_minutes', 15);

        $base = fn () => PaymentAttempt::query()
            ->where('provider', PaymentGatewayProviderCodeEnum::Paypay->value)
            ->whereIn('state', PayPayQrCodeClient::LIVE_ATTEMPT_STATES)
            ->whereNotNull('provider_object_id')
            ->where('provider_object_id', 'like', PayPayQrCodeClient::MPID_PREFIX.'%');

        return [
            'live' => $base()->count(),
            'stale' => $base()->where('prepared_at', '<', now()->subMinutes($graceMinutes))->count(),
            'grace_minutes' => $graceMinutes,
        ];
    }

    /**
     * @return array{open: int, closing: int, total: int}
     */
    private function countOpenTillSessions(): array
    {
        $counts = TillSession::query()
            ->selectRaw('status, COUNT(*) as cnt')
            ->whereIn('status', [
                TillSessionStatusEnum::Open->value,
                TillSessionStatusEnum::Closing->value,
            ])
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $open = (int) ($counts[TillSessionStatusEnum::Open->value] ?? 0);
        $closing = (int) ($counts[TillSessionStatusEnum::Closing->value] ?? 0);

        return [
            'open' => $open,
            'closing' => $closing,
            'total' => $open + $closing,
        ];
    }
}
