<?php

namespace App\Services\Printing;

use App\Models\PrintJob;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * plan-052 P-05 / P-06 — the ONE place that answers "may this be retried?"
 * and "is this still worth printing?".
 *
 * Both answers are per KIND and come from `config/print_jobs.php`. They are
 * applied ONLY by the tier that owns the queue (DESIGN §1b): Cloud runs this
 * matrix for cloudprnt, the workstation runs its own equivalent for ws_lan.
 * Cloud calling `shouldAutoRetry()` on a ws_lan row would be a bug, which is
 * why {@see PrintJob} refuses to let Cloud touch such a row at all.
 */
class PrintJobRegistry
{
    /**
     * @return array{auto_retry: bool, max_attempts: int, backoff_seconds: list<int>, ttl_seconds: int}
     */
    public function policyFor(PrintJobKind|string $kind): array
    {
        $key = $kind instanceof PrintJobKind ? $kind->value : $kind;

        /** @var array<string, mixed> $default */
        $default = config('print_jobs.default');
        /** @var array<string, mixed> $policy */
        $policy = config("print_jobs.kinds.{$key}", $default);

        return [
            'auto_retry' => (bool) ($policy['auto_retry'] ?? false),
            'max_attempts' => (int) ($policy['max_attempts'] ?? 1),
            'backoff_seconds' => array_map('intval', $policy['backoff_seconds'] ?? []),
            'ttl_seconds' => (int) ($policy['ttl_seconds'] ?? $default['ttl_seconds']),
        ];
    }

    public function allowsAutoRetry(PrintJobKind|string $kind): bool
    {
        return $this->policyFor($kind)['auto_retry'];
    }

    public function maxAttempts(PrintJobKind|string $kind): int
    {
        return $this->policyFor($kind)['max_attempts'];
    }

    public function ttlSeconds(PrintJobKind|string $kind): int
    {
        return $this->policyFor($kind)['ttl_seconds'];
    }

    /**
     * Wait before attempt number `$attempt` (1-based: the wait AFTER attempt 1
     * is `backoffSeconds($kind, 1)`). The list's last value repeats once the
     * schedule is exhausted, so a long budget degrades to a steady interval
     * instead of falling off the end of the array.
     */
    public function backoffSeconds(PrintJobKind|string $kind, int $attempt): int
    {
        $schedule = $this->policyFor($kind)['backoff_seconds'];

        if ($schedule === []) {
            return 0;
        }

        return $schedule[min(max($attempt, 1), count($schedule)) - 1];
    }

    public function expiresAt(PrintJobKind|string $kind, DateTimeInterface $issuedAt): CarbonImmutable
    {
        return CarbonImmutable::instance($issuedAt)->addSeconds($this->ttlSeconds($kind));
    }

    /**
     * P-06 — a job past its TTL must not print, whatever its status says.
     * Terminal jobs are left alone: an already-printed receipt does not become
     * "expired" tomorrow.
     */
    public function isExpired(PrintJobKind|string $kind, DateTimeInterface $issuedAt, ?DateTimeInterface $now = null): bool
    {
        $now = $now === null ? CarbonImmutable::now() : CarbonImmutable::instance($now);

        return $now->greaterThanOrEqualTo($this->expiresAt($kind, $issuedAt));
    }

    /**
     * P-05 — the full decision for "may the queue owner send this again by
     * itself?". Money documents answer NO from every state, including
     * `needs_attention`, which is the whole point of the matrix (PR1).
     */
    public function shouldAutoRetry(PrintJobKind|string $kind, PrintJobStatus $status, int $attempts): bool
    {
        if (! $status->isRetryable()) {
            return false;
        }

        $policy = $this->policyFor($kind);

        if (! $policy['auto_retry']) {
            return false;
        }

        return $attempts < $policy['max_attempts'];
    }
}
