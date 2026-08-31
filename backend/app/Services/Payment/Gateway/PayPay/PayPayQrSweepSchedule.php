<?php

namespace App\Services\Payment\Gateway\PayPay;

use App\Models\PaymentAttempt;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which outstanding PayPay QR codes `payments:sweep-paypay-qr` asks about, and
 * how often (#2454).
 *
 * WHY THIS IS NOT IN THE COMMAND. Two reasons, and the second is the one that
 * would have bitten later. The guard first: writing `last_swept_at` is a write
 * into the `payment` aggregate, and those may only happen inside a declared
 * boundary file — `architecture:domain-writers` fails the build otherwise, by
 * design. The second is cohesion. "Who is due" and "record that we asked" are
 * two halves of ONE policy; split across a command and a service they drift,
 * and the drift is invisible — a ladder whose filter and whose bookkeeping
 * disagree still runs, it just quietly asks about the wrong codes.
 *
 * WHAT THE LADDER REPLACED. `candidates()` used to take the OLDEST
 * `batch_limit` attempts every tick. That is exactly backwards: the newest
 * attempt is the customer standing at the counter who just scanned, and once
 * more than `batch_limit` codes were live at once the newest were the ones
 * that never entered a tick — the more customers a shop had, the later each
 * one was booked. Extrapolated at 1000 shops that threshold is ~110 live codes
 * against a cap of 100, so it was reachable, not theoretical.
 *
 * Simply REVERSING the order trades one death for another: newest-first
 * starves the old, which then never get retired and pile up without bound. A
 * ladder has neither failure. Every attempt keeps a due time, so nothing is
 * abandoned; and the hot rung is small enough that it always fits inside the
 * cap — at the same 1000-shop peak (~22 mints/min) it needs ~44 of the 100
 * slots, so a code under two minutes old is asked about EVERY tick even while
 * total demand exceeds the cap.
 */
class PayPayQrSweepSchedule
{
    /**
     * How often an attempt of a given age may be asked about.
     *
     * `[max age in minutes | null for the last rung, minimum seconds between
     * enquiries]`, hottest first.
     *
     * The rungs beyond 15 minutes exist only to drain: `grace_minutes` is 15,
     * so an unscanned code that old is retired the next time it is examined.
     */
    public const LADDER = [
        [2, 0],
        [5, 120],
        [15, 300],
        [null, 600],
    ];

    /**
     * The attempts due this tick, hottest rung first.
     *
     * Within a rung, never-asked wins and then longest-waiting, so no attempt
     * inside a rung can be passed over indefinitely either.
     *
     * @return Collection<int, PaymentAttempt>
     */
    public function due(int $limit, Carbon $now): Collection
    {
        return $this->dueQuery($now)
            ->orderByRaw($this->rungRankSql(), $this->rungRankBindings($now))
            ->orderByRaw('CASE WHEN last_swept_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('last_swept_at')
            ->limit($limit)
            ->get();
    }

    /** Everything `due()` could have returned, before the cap. */
    public function dueCount(Carbon $now): int
    {
        return $this->dueQuery($now)->count();
    }

    /**
     * Record that this tick EXAMINED the attempt.
     *
     * Stamped for every candidate, including the ones the sweep could not ask
     * PayPay about (unlinked order/connection, provider unreachable). That is
     * deliberate, and it is the difference between a working ladder and a stuck
     * one: `last_swept_at IS NULL` is the top priority, so an attempt that can
     * never be resolved would otherwise hold a slot in the hot rung on EVERY
     * tick, reintroducing by the back door the starvation the ladder exists to
     * remove. The column records that we LOOKED; what moved is in `state`.
     *
     * `updateQuietly` and no touch: this is scheduler bookkeeping, and bumping
     * `updated_at` would corrupt the one timestamp operators use to ask when
     * the attempt ITSELF last changed.
     */
    public function markExamined(PaymentAttempt $attempt, Carbon $examinedAt): void
    {
        $attempt->updateQuietly(['last_swept_at' => $examinedAt]);
    }

    /** @return Builder<PaymentAttempt> */
    private function dueQuery(Carbon $now): Builder
    {
        return $this->liveQrAttempts()->where(function (Builder $anyRung) use ($now) {
            foreach (self::LADDER as $index => [$maxAgeMinutes, $everySeconds]) {
                $anyRung->orWhere(function (Builder $rung) use ($index, $maxAgeMinutes, $everySeconds, $now) {
                    $this->constrainToRung($rung, $index, $maxAgeMinutes, $now);

                    // everySeconds === 0 is the hottest rung: always due, so it
                    // carries no `last_swept_at` predicate at all.
                    if ($everySeconds > 0) {
                        $rung->where(fn (Builder $overdue) => $overdue
                            ->whereNull('last_swept_at')
                            ->orWhere('last_swept_at', '<=', $now->copy()->subSeconds($everySeconds)));
                    }
                });
            }
        });
    }

    /**
     * The same definition of "this code may still be outstanding" the
     * customer's own poll uses.
     *
     * A mint leaves the attempt in `prepared`, so a sweep that looked only at
     * `provider_pending` would see none of them. `prepared_at` rather than
     * `created_at` throughout: it is NOT NULL with a database default, while
     * `created_at` is nullable.
     *
     * Grace does NOT gate this query (#2445). Waiting `grace_minutes` before
     * even *asking* meant a CLOSED TAB after a successful scan sat unbooked
     * for grace + schedule interval (~30 min on the live pilot). Booking a
     * COMPLETED payment is safe at any age — the ledger funnel is idempotent
     * with the customer's own poll. Age only decides whether an unscanned
     * `CREATED` code may be RETIRED, which is the command's business, not this
     * one's. The ladder above uses age for a different purpose entirely: how
     * often to ask, never whether to ask at all.
     *
     * @return Builder<PaymentAttempt>
     */
    private function liveQrAttempts(): Builder
    {
        return PaymentAttempt::query()
            ->where('provider', PaymentGatewayProviderCodeEnum::Paypay->value)
            ->whereIn('state', PayPayQrCodeClient::LIVE_ATTEMPT_STATES)
            ->whereNotNull('provider_object_id')
            // The prefix is what separates a QR attempt from a preauth one on
            // the same provider; they read from different PayPay endpoints.
            ->where('provider_object_id', 'like', PayPayQrCodeClient::MPID_PREFIX.'%')
            ->whereNotNull('prepared_at');
    }

    /**
     * Bound one rung by age.
     *
     * Older attempts have SMALLER `prepared_at`, so the younger edge of a rung
     * is an upper bound on the timestamp and the older edge is a lower one.
     * Every boundary is computed in PHP rather than with `INTERVAL`: production
     * is MySQL but the suite runs on sqlite, and date arithmetic is the first
     * thing that stops meaning the same in both.
     *
     * @param  Builder<PaymentAttempt>  $rung
     */
    private function constrainToRung(Builder $rung, int $index, ?int $maxAgeMinutes, Carbon $now): void
    {
        if ($index > 0) {
            $youngerEdge = self::LADDER[$index - 1][0];
            $rung->where('prepared_at', '<=', $now->copy()->subMinutes($youngerEdge));
        }

        // A null ceiling is the last rung — everything older falls in it.
        if ($maxAgeMinutes !== null) {
            $rung->where('prepared_at', '>', $now->copy()->subMinutes($maxAgeMinutes));
        }
    }

    /**
     * `CASE` rather than a computed age column: portable to both drivers, and
     * it sorts on the same edges the filter uses, so the ordering can never
     * disagree with eligibility.
     */
    private function rungRankSql(): string
    {
        $whens = '';

        foreach (self::LADDER as $index => [$maxAgeMinutes, $_]) {
            if ($maxAgeMinutes !== null) {
                $whens .= "WHEN prepared_at > ? THEN {$index} ";
            }
        }

        return 'CASE '.$whens.'ELSE '.(count(self::LADDER) - 1).' END';
    }

    /** @return list<string> */
    private function rungRankBindings(Carbon $now): array
    {
        $bindings = [];

        foreach (self::LADDER as [$maxAgeMinutes, $_]) {
            if ($maxAgeMinutes !== null) {
                $bindings[] = $now->copy()->subMinutes($maxAgeMinutes)->toDateTimeString();
            }
        }

        return $bindings;
    }
}
