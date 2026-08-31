<?php

declare(strict_types=1);

namespace App\Services\Printing;

use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\Printing\Enums\PrintConfidence;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\Enums\PrintTransport;
use App\Services\Printing\Enums\UposPrinterStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * plan-052 M4 / plan-053 T5.4 (#1171) — the Star CloudPRNT server, printer side.
 *
 * CloudPRNT inverts the usual direction: the machine sits behind the shop's NAT
 * and POLLS Cloud, so Cloud is the tier CLOSEST to it and therefore owns its
 * queue (DESIGN §1b — the one sanctioned exception to "the queue lives near the
 * printer"). Everything below is the Cloud-owned queue mode: this class
 * schedules, expires and transitions cloudprnt rows, and touches nothing else.
 *
 * ── The protocol is Star's, not ours ──────────────────────────────────────
 *
 * Three methods on ONE URL, and the verbs are not the obvious ones:
 *
 *   POST   the printer polls, sending its status; the server answers JSON with
 *          `jobReady` and, when true, `mediaTypes` + `jobToken`.
 *   GET    the printer downloads the job bytes (`?mac=&uid=&type=&token=`).
 *   DELETE the printer confirms the outcome (`?mac=&uid=&code=&token=`), where
 *          `code` is a printer status code such as `200 OK`.
 *
 * `plans/plan-052/DESIGN.md` §2 sketches this as "GET = poll, POST = confirm",
 * which is the wrong way round and would not have talked to a real machine.
 * The spec wins; the sketch predates anyone reading it.
 *
 * @see https://star-m.jp/products/s_print/sdk/StarCloudPRNT/manual/en/protocol-reference/http-method-reference/server-polling-post/json-response.html
 * @see https://star-m.jp/products/s_print/sdk/StarCloudPRNT/manual/en/protocol-reference/http-method-reference/job-request-get/get-request.html
 * @see https://star-m.jp/products/s_print/sdk/StarCloudPRNT/manual/en/protocol-reference/http-method-reference/job-confirmation-delete/index.html
 * @see https://star-m.jp/products/s_print/sdk/StarCloudPRNT/manual/en/protocol-reference/common-spec-reference/printer-status-code/index.html
 */
class CloudPrntService
{
    /**
     * Star's confirmation verb. Declared explicitly rather than relying on the
     * documented default so a firmware that reads the field gets the same
     * answer as one that does not.
     */
    public const DELETE_METHOD = 'DELETE';

    /** Shortest token this server will even look up (P-16 asks for ≥32 bytes). */
    private const MIN_TOKEN_LENGTH = 32;

    public function __construct(private readonly PrintJobRegistry $registry) {}

    /**
     * The printer behind a `print_token`, or `null`.
     *
     * Fail-closed on every axis P-16 names: an unknown token, a deactivated
     * printer, or a printer whose transport has since been changed away from
     * `cloudprnt` all answer the same `null` → 401 at the next poll. The
     * transport check is what makes "switch this machine back to ws_lan" an
     * effective revocation: the token row still exists, and it stops working.
     */
    public function authenticate(?string $token): ?Printer
    {
        if ($token === null || strlen($token) < self::MIN_TOKEN_LENGTH) {
            return null;
        }

        return Printer::query()
            ->where('print_token', $token)
            ->where('is_active', true)
            ->where('transport', PrintTransport::CloudPrnt->value)
            ->first();
    }

    /**
     * Answer one poll. Returns the JSON body verbatim.
     *
     * @param  array<string, mixed>  $body  the printer's JSON request
     * @return array<string, mixed>
     */
    public function poll(Printer $printer, array $body, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $this->recordContact($printer, $body, $now);
        $this->expireOverdue($printer, $now);

        $job = $this->nextJob($printer, $now);

        if ($job === null) {
            return ['jobReady' => false, 'mediaTypes' => CloudPrntJobRenderer::mediaTypes()];
        }

        return [
            'jobReady' => true,
            'mediaTypes' => CloudPrntJobRenderer::mediaTypes(),
            // The job id IS the token. It is already the idempotency key of the
            // whole ledger (P-09: the id is client-generated so a replay
            // collides in the DB rather than in application code), so a second
            // opaque identifier could only ever drift from it.
            'jobToken' => (string) $job->id,
            'deleteMethod' => self::DELETE_METHOD,
        ];
    }

    /**
     * The job a GET should hand over, moved to `delivering`.
     *
     * P-01 double-GET: a job already `delivering` is returned AGAIN, with the
     * same bytes and WITHOUT a further attempt being counted. A flaky link that
     * makes the printer re-fetch is not a second delivery attempt, and counting
     * it as one would burn a money document's single-attempt budget on a
     * network hiccup.
     */
    public function claim(Printer $printer, ?string $jobToken, ?CarbonImmutable $now = null): ?PrintJob
    {
        $now ??= CarbonImmutable::now();

        $this->expireOverdue($printer, $now);

        $job = $this->locate($printer, $jobToken, [PrintJobStatus::Queued, PrintJobStatus::Delivering], $now);

        if ($job === null) {
            return null;
        }

        if ($job->status === PrintJobStatus::Queued) {
            $job->status = PrintJobStatus::Delivering;
            $job->attempts = (int) $job->attempts + 1;
            $job->acked_at = $now;
            $job->save();
        }

        return $job;
    }

    /**
     * A job whose bytes Cloud cannot build. It is failed HERE, before the
     * printer is answered, so the next poll offers the next job instead of the
     * same broken one for ever. A serving loop is the failure mode a lazy
     * renderer invites, and it is silent from the shop's side: the machine just
     * never prints.
     */
    public function failUnrenderable(PrintJob $job, string $reason, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        $job->status = PrintJobStatus::Failed;
        $job->last_error = mb_substr($reason, 0, 255);
        $job->acked_at ??= $now;
        $job->save();

        Log::warning('cloudprnt_job_unrenderable', [
            'print_job_id' => $job->id,
            'printer_id' => $job->printer_id,
            'kind' => $job->kind instanceof PrintJobKind ? $job->kind->value : $job->kind,
            'reason' => $reason,
        ]);
    }

    /**
     * The printer's confirmation (Star DELETE).
     *
     * P-02 idempotent: a retried DELETE on an already-terminal job is a no-op
     * that still answers 200. The printer must never be told "no" for saying
     * the same true thing twice — it would keep retrying, and the ledger would
     * count each retry.
     */
    public function confirm(Printer $printer, ?string $jobToken, string $code, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        $job = $this->locate($printer, $jobToken, [PrintJobStatus::Delivering, PrintJobStatus::Queued], $now);

        $this->applyStatusCode($printer, $code, $now);

        if ($job === null) {
            return;
        }

        if ($job->status->isTerminal()) {
            return;
        }

        if (self::isSuccessCode($code)) {
            $job->status = PrintJobStatus::Printed;
            $job->printed_reported_at = $now;
            $job->acked_at ??= $now;
            $this->recordConfidence($job, $printer);
        } else {
            $job->status = PrintJobStatus::Failed;
            $job->last_error = mb_substr($code, 0, 255);
            $job->acked_at ??= $now;
        }

        $job->save();
    }

    /**
     * Star status codes are "similar to HTTP status codes, but not directly
     * compatible": every code beginning with 2 means the printer is online, 4
     * is a printer error, 5 a client error.
     */
    public static function isSuccessCode(string $code): bool
    {
        return str_starts_with(trim($code), '2');
    }

    /**
     * Star status code → the UPOS vocabulary the rest of the pipeline speaks
     * (T1.3): one dashboard row must mean the same thing whichever machine
     * produced it.
     *
     * `offline` is deliberately unreachable here. A printer that is off cannot
     * post its own absence — silence is the only signal, and Cloud infers it
     * from `poll_silence` (P-38), not from a code.
     */
    public static function uposStatusFor(string $code): UposPrinterStatus
    {
        $number = (int) preg_replace('/\D.*$/', '', trim($code));

        return match (true) {
            $number === 211 => UposPrinterStatus::PaperNearEnd,   // paper low
            $number === 410 => UposPrinterStatus::PaperEnd,       // out of paper
            $number === 420 => UposPrinterStatus::CoverOpen,      // cover open
            $number >= 200 && $number < 300 => UposPrinterStatus::Ok,
            default => UposPrinterStatus::Error,
        };
    }

    /**
     * `last_seen_at` + `last_status` from a poll.
     *
     * This is the ONLY health signal a cloudprnt machine gives, which is why
     * the aging service treats silence as the offline detector for this
     * transport: it is behind NAT and cannot be dialled (P-38).
     *
     * @param  array<string, mixed>  $body
     */
    private function recordContact(Printer $printer, array $body, CarbonImmutable $now): void
    {
        $printer->last_seen_at = $now;

        $code = $body['statusCode'] ?? null;

        if (is_string($code) && $code !== '') {
            $printer->last_status = self::uposStatusFor($code);
        }

        $printer->save();
    }

    private function applyStatusCode(Printer $printer, string $code, CarbonImmutable $now): void
    {
        if (trim($code) === '') {
            return;
        }

        $printer->last_status = self::uposStatusFor($code);
        $printer->last_seen_at = $now;
        $printer->save();
    }

    /**
     * P-33 — the confidence a CloudPRNT confirm earns is the machine's, not the
     * protocol's.
     *
     * The one-way rule is HARD: a row already stamped `sent_only` says "this
     * machine cannot tell us more", and nothing may promote that. The model
     * throws on the attempt, so this method does not make it — but a cloudprnt
     * job stamped `sent_only` on a machine whose profile says it CAN confirm is
     * a contradiction that comes from whoever enqueued it, and staying quiet
     * about it would hide a defect in the enqueue path behind a correct-looking
     * ledger row.
     */
    private function recordConfidence(PrintJob $job, Printer $printer): void
    {
        $earned = $printer->capabilityProfile()->printConfidence();

        if ($earned !== PrintConfidence::Confirmed->value) {
            return;
        }

        if ($job->getRawOriginal('confidence') === PrintConfidence::SentOnly->value) {
            Log::warning('cloudprnt_confirm_cannot_upgrade_confidence', [
                'print_job_id' => $job->id,
                'printer_id' => $printer->id,
                'detail' => 'job was enqueued as sent_only on a machine that can confirm; P-33 forbids the upgrade, '
                    .'so the enqueue path must stamp the confidence the printer earns',
            ]);

            return;
        }

        $job->confidence = PrintConfidence::Confirmed;
    }

    /**
     * P-06 — a job past its TTL must not print, whatever its status says. Run
     * before every selection so an overdue kitchen ticket is never handed to a
     * machine that happened to poll late; the food is cold and the table has
     * left, so a late ticket is not a late ticket, it is a wrong one.
     */
    private function expireOverdue(Printer $printer, CarbonImmutable $now): void
    {
        $open = PrintJob::query()
            ->where('printer_id', $printer->id)
            ->where('transport', PrintTransport::CloudPrnt->value)
            ->whereIn('status', [PrintJobStatus::Queued->value, PrintJobStatus::Delivering->value])
            ->get();

        foreach ($open as $job) {
            if (! $this->isExpired($job, $now)) {
                continue;
            }

            $job->status = PrintJobStatus::Expired;
            $job->save();
        }
    }

    private function isExpired(PrintJob $job, CarbonImmutable $now): bool
    {
        // `expires_at` is stamped at enqueue and is authoritative: it records
        // the TTL that was in force WHEN the job was made, so re-tuning the
        // matrix cannot retroactively expire (or resurrect) work already queued.
        if ($job->expires_at !== null) {
            return $now->greaterThanOrEqualTo(CarbonImmutable::instance($job->expires_at));
        }

        $issuedAt = $job->created_at ?? $now;

        return $this->registry->isExpired(
            $job->kind instanceof PrintJobKind ? $job->kind : (string) $job->kind,
            $issuedAt,
            $now,
        );
    }

    /**
     * The next job to offer this printer.
     *
     * Only `queued` and `delivering` are candidates — never `failed` and never
     * `needs_attention`. Re-offering those would put the retry decision in the
     * hands of whichever machine polled next, bypassing the per-kind matrix
     * whose whole purpose is that a money document is NEVER re-sent without a
     * human (PR1). Retry belongs to the reconcile sweep and to the operator.
     */
    private function nextJob(Printer $printer, CarbonImmutable $now): ?PrintJob
    {
        $job = $this->openJobs($printer)->first();

        if ($job === null) {
            return null;
        }

        // P-34 pre-flight: a machine reporting an open cover or no paper does
        // not get handed an invoice. It gets it on the next poll, once the
        // shop has closed the cover — which is strictly better than a half slip
        // nobody can account for.
        if ($this->blockedByPrinterState($job, $printer)) {
            return null;
        }

        return $job;
    }

    private function blockedByPrinterState(PrintJob $job, Printer $printer): bool
    {
        if (! $job->kind instanceof PrintJobKind || ! $job->kind->isMoneyDocument()) {
            return false;
        }

        return $printer->last_status instanceof UposPrinterStatus
            && $printer->last_status->blocksMoneyDocument();
    }

    /**
     * @param  list<PrintJobStatus>  $statuses
     */
    private function locate(Printer $printer, ?string $jobToken, array $statuses, CarbonImmutable $now): ?PrintJob
    {
        $query = PrintJob::query()
            ->where('printer_id', $printer->id)
            ->where('transport', PrintTransport::CloudPrnt->value);

        if ($jobToken !== null && $jobToken !== '') {
            // A token that names a job this printer does not own resolves to
            // nothing rather than falling back to "the oldest open one" — the
            // fallback exists for firmware that cannot send a token at all, not
            // as a repair for one that sent the wrong token.
            return $query->whereKey($jobToken)->first();
        }

        return $query
            ->whereIn('status', array_map(static fn (PrintJobStatus $s): string => $s->value, $statuses))
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    /** @return Collection<int, PrintJob> */
    private function openJobs(Printer $printer)
    {
        return PrintJob::query()
            ->where('printer_id', $printer->id)
            ->where('transport', PrintTransport::CloudPrnt->value)
            ->whereIn('status', [PrintJobStatus::Queued->value, PrintJobStatus::Delivering->value])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
