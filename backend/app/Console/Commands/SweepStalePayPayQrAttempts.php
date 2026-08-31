<?php

namespace App\Console\Commands;

use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Services\Customer\OrderPaymentService;
use App\Services\Order\Contracts\OrderQueryPort;
use App\Services\Order\Contracts\OrderSnapshot;
use App\Services\Payment\Gateway\PayPay\PayPayLifecycleMapper;
use App\Services\Payment\Gateway\PayPay\PayPayQrCodeClient;
use App\Services\Payment\Gateway\PayPay\PayPayQrSweepSchedule;
use App\Services\Payment\Orchestration\OrderPaymentOrchestrationCompat;
use App\Services\Payment\ProviderEvent\GatewayConnectionDataFactory;
use App\Support\CurrencyMinorUnit;
use App\Support\Logging\MoneyOrchestrationLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * plan-054 R13 — sweep PayPay QR attempts nobody is polling any more.
 *
 * The webhook receiver is descoped for the pilot, so the customer's own status
 * poll is the ONLY thing that advances a QR attempt. Close the tab and the
 * attempt stays open forever. Usually that is just clutter — the ordinary end
 * of a dynamic QR is that nobody scanned it — but the same shape covers the
 * case that costs money: a customer who paid in the second before they closed
 * the tab. That payment is sitting at PayPay and nothing in this system has
 * noticed.
 *
 * The rule this command is built around: NEVER conclude anything from local
 * state alone. PayPay is asked about every attempt before it is touched, and a
 * failed question (timeout, 5xx, unreachable) leaves the attempt exactly as it
 * was — writing a code off because the provider could not be reached is how a
 * paid order becomes unbookable, since every path that books a PayPay payment
 * refuses a terminal attempt.
 *
 * What PayPay's answers actually look like, because it is not what you would
 * guess and the first version of this command was wrong about it:
 *
 *   COMPLETED                   — paid. Book it.
 *   CREATED, no payment id      — minted, never scanned. PayPay reports this
 *                                 FOREVER: the code endpoint has no expiry
 *                                 state and never 404s for an unscanned code,
 *                                 so age is the only evidence. See
 *                                 `retireIfLapsed()`.
 *   CREATED, WITH a payment id  — a scan is in flight. Leave it alone.
 *   HTTP 404                    — no payment object at all, which is what a
 *                                 DELETED code answers (the re-mint path calls
 *                                 deleteQRCode). Retire it.
 *   EXPIRED / FAILED / CANCELED — terminal at the provider. Retire it.
 *
 * Shape follows `payments:reconcile-attempts` (cache lock, per-attempt
 * try/catch so one poisoned row cannot abort the batch, one structured tick
 * log) and its `--dry-run` follows `tills:expire-stale-shifts`. It is a
 * SEPARATE command rather than a second candidate query inside
 * `payments:reconcile-attempts` for two reasons: that command is scheduled
 * WITHOUT `--execute`, i.e. it deliberately runs as a preview until plan-047
 * Gate 3 is signed off, so anything folded into it would silently never run;
 * and `next_reconciliation_at` belongs to that mechanism — this sweep neither
 * reads nor writes it, and keys staleness off the attempt's own mint time.
 *
 * Money always moves in one direction here: the ledger row is written FIRST
 * through OrderPaymentService::recordPayPayPayment — the same funnel the
 * customer's poll uses, with the same five guards — and only then is the
 * attempt closed. Losing the process in between leaves the attempt open, the
 * next tick re-asks, and the funnel's idempotency probe makes the second write
 * a no-op. The reverse order would close the attempt over an unwritten row.
 */
#[Signature('payments:sweep-paypay-qr {--dry-run : Ask PayPay what happened and report the decisions without writing anything}')]
#[Description('Ask PayPay about stale QR attempts left open when a customer stopped polling: book what was paid, retire what lapsed (plan-054 R13)')]
final class SweepStalePayPayQrAttempts extends Command
{
    /**
     * Raw status recorded when PayPay has no payment object for the code.
     *
     * Not a PayPay status — it is the absence of one. PayPay answers 404 for a
     * merchant payment id with no payment behind it, which is what a deleted
     * code looks like, and the attempt row should say that rather than inherit
     * the mint-failure wording of `abandonPayPayQrAttempt`.
     */
    private const PROVIDER_NO_PAYMENT = 'NOT_FOUND';

    /**
     * Raw status recorded for a code that was minted and never scanned.
     *
     * Deliberately NOT PayPay's own word. PayPay says `CREATED` for such a code
     * for as long as anyone cares to ask, so writing `CREATED` onto a canceled
     * attempt would record a contradiction and hide the fact that the verdict
     * was OURS, reached from the age of the code rather than from anything the
     * provider said. A distinct value is also how an operator finds these rows
     * later — they are the only ones this system concluded about unilaterally.
     */
    private const LAPSED_UNSCANNED = 'LAPSED_UNSCANNED';

    private bool $dryRun = false;

    private int $graceMinutes = 15;

    /**
     * Whether age alone may retire an unscanned code.
     *
     * False when the configured grace does not clear PayPay's own code
     * lifetime, in which case "old enough to be dead" is not a safe inference
     * and the sweep confines itself to answers the provider gave explicitly.
     */
    private bool $mayRetireUnscanned = true;

    /** @var array<string, int> */
    private array $counters = [];

    public function handle(
        PayPayQrCodeClient $qrCodes,
        OrderPaymentService $orderPayments,
        OrderPaymentOrchestrationCompat $orchestration,
        PayPayQrSweepSchedule $schedule,
    ): int {
        $lock = Cache::lock('payments:sweep-paypay-qr', 300);

        if (! $lock->get()) {
            $this->warn('Another payments:sweep-paypay-qr run is already in progress.');

            return self::SUCCESS;
        }

        try {
            $startedAt = microtime(true);
            $this->dryRun = (bool) $this->option('dry-run');
            $this->graceMinutes = (int) config('payments.paypay_qr.stale_sweep_grace_minutes', 15);
            $this->mayRetireUnscanned = $this->graceClearsCodeLifetime();
            $limit = (int) config('payments.paypay_qr.stale_sweep_batch_limit', 100);
            $mapper = new PayPayLifecycleMapper;

            $this->counters = [
                'candidates' => 0,
                'booked' => 0,
                'stranded' => 0,
                'retired' => 0,
                'still_open' => 0,
                'unresolved' => 0,
            ];

            // ONE clock for the whole tick. The ladder both filters and orders
            // by the same age edges, and re-reading `now()` between the two
            // would let an attempt sit in one rung for the filter and the next
            // rung for the sort.
            $tickStartedAt = now();

            // Measured BEFORE the loop on purpose: sweeping RESOLVES attempts
            // (retire / book), so counting afterwards asks "how many are still
            // outstanding now", which is a different and much smaller number.
            // The question this warning answers is "how many did this tick have
            // to choose from", and that only exists at the start.
            $outstandingAtStart = $schedule->dueCount($tickStartedAt);

            foreach ($schedule->due($limit, $tickStartedAt) as $attempt) {
                $this->counters['candidates']++;

                $this->sweep($attempt, $qrCodes, $orderPayments, $orchestration, $mapper);

                // A dry run must stay read-only: moving every due time forward
                // would change what the next REAL tick does, which is the one
                // thing a preview must not do.
                if (! $this->dryRun) {
                    $schedule->markExamined($attempt, $tickStartedAt);
                }
            }

            $this->warnIfBatchTruncated($this->counters['candidates'], $outstandingAtStart, $limit);

            $summary = $this->counters + [
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'grace_minutes' => $this->graceMinutes,
                'dry_run' => $this->dryRun,
            ];

            Log::channel('payment_orchestration')->info('paypay_qr_sweep_tick', $summary);

            $this->info(sprintf(
                'payments:sweep-paypay-qr — candidates=%d booked=%d stranded=%d retired=%d still_open=%d unresolved=%d duration=%dms%s',
                $summary['candidates'],
                $summary['booked'],
                $summary['stranded'],
                $summary['retired'],
                $summary['still_open'],
                $summary['unresolved'],
                $summary['duration_ms'],
                $this->dryRun ? ' (dry-run)' : '',
            ));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * The margin that makes "too old to be scannable" a fact rather than a hope.
     *
     * An unscanned code is retired on AGE, because PayPay never reports that
     * one lapsed — it answers `CREATED` indefinitely. That inference is sound
     * only while the grace period stays comfortably longer than the provider's
     * own code lifetime: the shipped default is 15 minutes against ~5, a 3x
     * margin. Tune the grace DOWN towards that lifetime and the sweep would
     * start retiring codes a customer can still scan, so it refuses to draw the
     * inference at all and says why — loudly, because a silently narrowed
     * margin is indistinguishable from a working sweep until money goes
     * missing. The same protection applies from the other direction: if PayPay
     * ever lengthens PayPayQrCodeClient::CODE_LIFETIME_MINUTES, raise the grace
     * with it or this stops retiring rather than starts guessing.
     */
    private function graceClearsCodeLifetime(): bool
    {
        if ($this->graceMinutes > PayPayQrCodeClient::CODE_LIFETIME_MINUTES * 2) {
            return true;
        }

        MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_PAYPAY, 'paypay_qr_sweep_grace_too_short', [
            'grace_minutes' => $this->graceMinutes,
            'code_lifetime_minutes' => PayPayQrCodeClient::CODE_LIFETIME_MINUTES,
        ]);

        $this->warn(sprintf(
            'Grace of %d min does not clear PayPay\'s ~%d min code lifetime — unscanned codes will NOT be retired.',
            $this->graceMinutes,
            PayPayQrCodeClient::CODE_LIFETIME_MINUTES,
        ));

        return false;
    }

    /**
     * Say so when the batch cap hides work (#2454).
     *
     * The cap still exists, so it can still hide work — what changed with the
     * ladder is WHICH work. Candidates are now ordered hottest-rung-first, so
     * an overflow drops the attempts with the longest poll interval rather
     * than the newest ones; those keep their due time and arrive on a later
     * tick instead of never. Sustained truncation is therefore no longer a
     * correctness bug, but it IS the signal that the cap has stopped fitting
     * the shop count and PayPay is being asked less often than intended.
     *
     * The failure it guards against is silent by construction: the tick logs
     * `candidates: 100` whether there are 100 due or 10,000, so enquiries would
     * thin out with nothing in the logs to say why.
     *
     * Measured 2026-08-11: one shop mints ~9 QR/day and a tick usually sees 0
     * candidates, so this never fires today. It is here for the day it does.
     * Reporting a bounded view as if it were the whole is exactly the silent
     * cap the repo forbids.
     */
    private function warnIfBatchTruncated(int $examined, int $outstanding, int $limit): void
    {
        if ($examined < $limit || $outstanding <= $limit) {
            return;
        }

        MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_PAYPAY, 'paypay_qr_sweep_batch_truncated', [
            'examined' => $examined,
            'outstanding' => $outstanding,
            'skipped' => $outstanding - $limit,
            'batch_limit' => $limit,
            'note' => 'backoff ladder keeps the newest codes inside the cap, so the skipped ones are the '
                .'longest-interval attempts and they stay due for a later tick — but the cap no longer '
                .'fits the shop count, so every rung is being asked less often than configured (#2454)',
        ]);

        $this->warn(sprintf(
            'Batch cap reached: %d due, %d examined — %d longest-interval attempt(s) deferred to a later tick (#2454).',
            $outstanding, $examined, $outstanding - $limit,
        ));
    }

    private function staleBefore(): Carbon
    {
        return now()->subMinutes($this->graceMinutes);
    }

    private function sweep(
        PaymentAttempt $attempt,
        PayPayQrCodeClient $qrCodes,
        OrderPaymentService $orderPayments,
        OrderPaymentOrchestrationCompat $orchestration,
        PayPayLifecycleMapper $mapper,
    ): void {
        $merchantPaymentId = (string) $attempt->provider_object_id;
        // #1594 — ẢNH CHỤP qua cổng của Ordering, không phải model. Cùng MỘT
        // truy vấn như `CustomerOrder::find()` cũ, chỉ thêm ràng buộc
        // `organization_id` lấy từ chính attempt — hai giá trị này luôn được ghi
        // cùng lúc lúc chuẩn bị attempt. Lệch nhau thì attempt được để NGỎ, đúng
        // hướng an toàn mà nhánh dưới đã chọn sẵn.
        $order = app(OrderQueryPort::class)->findById(
            (string) $attempt->organization_id,
            (string) $attempt->customer_order_id,
        );
        $connection = PaymentGatewayConnection::query()->with('provider')->find($attempt->connection_id);

        if ($order === null || $connection === null) {
            // Left open deliberately. Without an order there is nowhere to book
            // the money, and without a connection there is nobody to ask —
            // closing the attempt would only hide that.
            MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_PAYPAY, 'paypay_qr_sweep_unlinked_attempt', [
                'attempt_id' => (string) $attempt->id,
                'merchant_payment_id' => $merchantPaymentId,
                'order_id' => $attempt->customer_order_id === null ? null : (string) $attempt->customer_order_id,
                'connection_id' => $attempt->connection_id === null ? null : (string) $attempt->connection_id,
            ]);

            $this->counters['unresolved']++;

            return;
        }

        try {
            // Read-only, and performed on a dry run too: the whole value of the
            // preview is what PayPay says about these codes, and asking changes
            // nothing on either side.
            $details = $qrCodes->findPayment(
                GatewayConnectionDataFactory::fromModel($connection),
                $merchantPaymentId,
                'paypay:qr:sweep:'.$merchantPaymentId,
            );
        } catch (Throwable $exception) {
            // One unreachable code must not abort the batch, and must not be
            // concluded about either.
            MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_PAYPAY, 'paypay_qr_sweep_unreachable', [
                'attempt_id' => (string) $attempt->id,
                'order_id' => $order->aggregateId(),
                'merchant_payment_id' => $merchantPaymentId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->counters['unresolved']++;

            return;
        }

        $rawStatus = $details === null
            ? self::PROVIDER_NO_PAYMENT
            : strtoupper((string) $details['status']);

        // EXPIRED is an ordinary terminal outcome for a QR and only the QR map
        // knows that; the preauth map would park it as reconciliation_required
        // and send an operator chasing money that never moved.
        $state = $details === null
            ? PaymentAttemptStateEnum::Canceled
            : $mapper->mapQrPaymentState($rawStatus);

        if ($state === PaymentAttemptStateEnum::Succeeded) {
            $this->book($attempt, $order, $merchantPaymentId, $details ?? [], $orderPayments, $orchestration);

            return;
        }

        if (in_array($state, [
            PaymentAttemptStateEnum::Canceled,
            PaymentAttemptStateEnum::Failed,
            PaymentAttemptStateEnum::Expired,
        ], true)) {
            $this->retire($attempt, $order, $merchantPaymentId, $rawStatus, $orchestration);

            return;
        }

        if ($this->retireIfLapsed($attempt, $order, $merchantPaymentId, $details, $orchestration)) {
            return;
        }

        // The provider has not finished with it, so neither have we: a scan in
        // flight, or a status this adapter does not recognise.
        Log::channel('payment_orchestration')->info('paypay_qr_sweep_still_open', [
            'attempt_id' => (string) $attempt->id,
            'order_id' => $order->aggregateId(),
            'merchant_payment_id' => $merchantPaymentId,
            'raw_status' => $rawStatus,
            'has_provider_payment_id' => ($details['paypay_payment_id'] ?? null) !== null,
        ]);

        $this->counters['still_open']++;
    }

    /**
     * The common case, and the one this command exists for: a code minted and
     * never scanned.
     *
     * PayPay answers `CREATED` for it indefinitely. The code endpoint has no
     * expiry state and does not 404 for an unscanned code, so there is no
     * provider answer that ever says "this lapsed" — age is the only evidence,
     * and it is good evidence precisely because the grace period is several
     * times the code's ~5 minute life (see graceClearsCodeLifetime).
     *
     * Two things must both hold, and the second is the load-bearing one:
     *
     *   1. The attempt is past the grace period. Re-checked here rather than
     *      trusted from the candidate query, because this is the one decision
     *      taken WITHOUT the provider's agreement, and it should not silently
     *      become unguarded if that query is ever loosened.
     *   2. PayPay reports no payment id. `CREATED` WITH a payment id is a scan
     *      in flight — the customer is mid-authorisation and the money is about
     *      to move, so retiring there would put the attempt beyond the reach of
     *      every path that books a PayPay payment.
     *
     * @param  array<string, mixed>|null  $details
     */
    private function retireIfLapsed(
        PaymentAttempt $attempt,
        OrderSnapshot $order,
        string $merchantPaymentId,
        ?array $details,
        OrderPaymentOrchestrationCompat $orchestration,
    ): bool {
        if (! $this->mayRetireUnscanned || $details === null) {
            return false;
        }

        if (strtoupper((string) $details['status']) !== 'CREATED') {
            return false;
        }

        if (($details['paypay_payment_id'] ?? null) !== null) {
            return false;
        }

        $preparedAt = $attempt->prepared_at;

        if ($preparedAt === null || $preparedAt->greaterThanOrEqualTo($this->staleBefore())) {
            return false;
        }

        $this->retire($attempt, $order, $merchantPaymentId, self::LAPSED_UNSCANNED, $orchestration);

        return true;
    }

    /**
     * PayPay says this code was paid. Book it, then close the attempt.
     *
     * @param  array<string, mixed>  $details
     */
    private function book(
        PaymentAttempt $attempt,
        OrderSnapshot $order,
        string $merchantPaymentId,
        array $details,
        OrderPaymentService $orderPayments,
        OrderPaymentOrchestrationCompat $orchestration,
    ): void {
        $minorAmount = (int) ($details['amount'] ?? 0);
        $currency = strtoupper((string) ($details['currency'] ?? 'JPY'));

        if ($minorAmount <= 0) {
            // A COMPLETED payment with no amount means the provider contract
            // changed under us. Never substitute the attempt's own
            // `amount_minor`: that is the figure we ASKED for, and booking it
            // would record a number PayPay never confirmed.
            MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_PAYPAY, 'paypay_qr_sweep_amount_missing', [
                'attempt_id' => (string) $attempt->id,
                'order_id' => $order->aggregateId(),
                'merchant_payment_id' => $merchantPaymentId,
            ]);

            $this->counters['unresolved']++;

            return;
        }

        $amount = $this->toMajorUnits($minorAmount, $currency);

        if ($this->dryRun) {
            $this->line(sprintf(
                '[dry-run] would book %s %s on order=%s attempt=%s mpid=%s (PayPay says COMPLETED)',
                $amount,
                $currency,
                $order->orderCode(),
                $attempt->id,
                $merchantPaymentId,
            ));

            $this->counters['booked']++;

            return;
        }

        $outcome = $orderPayments->recordPayPayPaymentByOrderId(
            $order->aggregateId(),
            $merchantPaymentId,
            $amount,
            $currency,
            (string) $attempt->id,
        );

        if ($outcome['stranded_amount'] !== null) {
            // The order is voided, expired, priced in another currency, or was
            // already settled at the counter while the code was live. The money
            // is real and it is at PayPay; the ledger legitimately refuses it,
            // and PayPay refunds are deliberately unwired (plan-054 D5), so
            // this line is the hand-off to whoever reverses it in the merchant
            // portal. The funnel logs its own `paypay_stranded_payment`; this
            // one names the sweep that found it.
            MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_STRANDED, 'paypay_qr_sweep_stranded', [
                'attempt_id' => (string) $attempt->id,
                'order_id' => $order->aggregateId(),
                'order_code' => $order->orderCode(),
                'order_status' => $order->status(),
                'merchant_payment_id' => $merchantPaymentId,
                'stranded_amount' => $outcome['stranded_amount'],
                'currency' => $currency,
                'reason' => $outcome['reason'],
            ]);

            $this->warn(sprintf(
                'STRANDED %s %s at PayPay for order=%s (%s) — reverse it in the merchant portal.',
                $outcome['stranded_amount'],
                $currency,
                $order->orderCode(),
                $outcome['reason'],
            ));

            $this->counters['stranded']++;
        } elseif ($outcome['recorded'] || $outcome['reason'] === 'already_recorded') {
            $this->counters['booked']++;
        } else {
            // Refused without naming an amount — the order row vanished between
            // this method's read and the funnel's lock. Leave the attempt open
            // so the next tick tries again.
            MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_PAYPAY, 'paypay_qr_sweep_unbooked', [
                'attempt_id' => (string) $attempt->id,
                'order_id' => $order->aggregateId(),
                'merchant_payment_id' => $merchantPaymentId,
                'reason' => $outcome['reason'],
            ]);

            $this->counters['unresolved']++;

            return;
        }

        // Second, never first: the attempt is terminal once this lands, and a
        // terminal attempt is refused by every path that books a PayPay
        // payment. The stranded case is closed too — PayPay's answer is that
        // the money moved, and that is what the attempt should record.
        $orchestration->settlePayPayQrAttempt(
            $order,
            (string) $attempt->id,
            $merchantPaymentId,
            $minorAmount,
            $currency,
            (int) $attempt->version,
        );
    }

    /**
     * This code will never be paid. Close the attempt, touch no money.
     *
     * Terminal for the ATTEMPT, not a write-off of the money: if a payment
     * somehow lands afterwards, the notification path refuses it LOUDLY rather
     * than silently — `applyPayPayNotification` returns
     * `paypay_ignored_terminal` and `alarmOnUnbookableNotification` raises an
     * error naming the merchant payment id. Retiring hides nothing; it only
     * stops the row being asked about forever.
     */
    private function retire(
        PaymentAttempt $attempt,
        OrderSnapshot $order,
        string $merchantPaymentId,
        string $rawStatus,
        OrderPaymentOrchestrationCompat $orchestration,
    ): void {
        if ($this->dryRun) {
            $this->line(sprintf(
                '[dry-run] would retire attempt=%s order=%s mpid=%s (%s)',
                $attempt->id,
                $order->orderCode(),
                $merchantPaymentId,
                $rawStatus,
            ));

            $this->counters['retired']++;

            return;
        }

        $orchestration->retirePayPayQrAttempt(
            $order,
            (string) $attempt->id,
            $merchantPaymentId,
            $rawStatus,
            (int) $attempt->version,
        );

        $this->counters['retired']++;
    }

    /**
     * The ledger stores major units; PayPay reports minor ones. JPY has
     * exponent 0 so this is an identity today — it exists so this path and the
     * poll path cannot silently book different numbers if PayPay ever settles
     * in anything else.
     */
    private function toMajorUnits(int $minorAmount, string $currency): float
    {
        $exponent = CurrencyMinorUnit::exponent($currency);

        return $exponent === 0
            ? (float) $minorAmount
            : round($minorAmount / (10 ** $exponent), $exponent);
    }
}
