<?php

declare(strict_types=1);

namespace App\Services\Printing;

use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\Print\Renderer\PrintRenderDataHydrator;
use App\Services\Print\TemplateResolver;
use App\Services\Print\TemplateStamp;
use App\Services\Printing\Enums\PrintConfidence;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\Enums\PrintTransport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * plan-053 T5.4 (#1171) — the PRODUCER side of CloudPRNT.
 *
 * T5.4 built the serving path: a Star printer polls, downloads bytes, confirms.
 * Nothing put a row in front of it. This is that half — the one place a
 * `cloudprnt` job is created, so the invariants below live once instead of at
 * every future call site.
 *
 * Cloud owns this queue, and it is the only queue Cloud owns (DESIGN §1b). A
 * `ws_lan` row is a JOURNAL entry the workstation already printed; Cloud must
 * never schedule one. Hence the transport guard: enqueueing "for" a workstation
 * printer would make Cloud a second scheduler for a machine it cannot reach, and
 * the row would sit `queued` forever because no CloudPRNT client will ever poll
 * for it.
 *
 * ## Three things are stamped AT ENQUEUE, and each is stamped here for a reason
 *
 * **`confidence` — because P-33 makes it one-way.** It records the ceiling of
 * what this machine will ever be able to tell us, not an outcome: `sent_only`
 * means "the bytes left and this machine cannot say more". `PrintJob` refuses to
 * promote `sent_only` → `confirmed`, with no escape hatch. So a job stamped
 * `sent_only` on a machine that CAN confirm is permanently under-recorded — the
 * printer's own DELETE saying "200 OK" would be discarded. Taking the value from
 * the printer's capability profile is therefore not a nicety; leaving it to a
 * default is a silent, unrecoverable downgrade of the ledger.
 *
 * **`expires_at` — because the TTL matrix is tunable.** `CloudPrntService`
 * treats a stamped `expires_at` as authoritative precisely so that re-tuning
 * `config/print_jobs.php` cannot retroactively expire (or resurrect) work
 * already queued.
 *
 * **The payload envelope — because the alternative fails where nobody is
 * looking.** The bytes are built at POLL time, inside an HTTP request whose only
 * client is a thermal printer. A malformed payload discovered there produces a
 * machine that quietly prints nothing; nobody is watching that request, and the
 * shop's first symptom is a missing receipt. So the envelope is round-tripped
 * through {@see CloudPrntPayload::fromArray()} here, at enqueue, where the
 * caller is a human action with somewhere to show an error.
 *
 * **`template_version` (TR-28) — for exactly the `expires_at` reason.** The
 * printer polls LATER; a template published in between would otherwise silently
 * re-draw work already queued, and the row would carry no record that it had.
 * Resolving the layout here and having {@see CloudPrntJobRenderer} draw from
 * THAT stamp is what makes the recorded version a fact about the sheet rather
 * than a guess about it — a stamp the renderer ignores is decoration, and worse
 * than none, because it reads like evidence.
 */
final class CloudPrntEnqueueService
{
    public function __construct(
        private readonly PrintJobRegistry $registry,
        private readonly TemplateResolver $resolver,
    ) {}

    /**
     * Queue one document for a CloudPRNT printer.
     *
     * @param  array<string, mixed>  $renderData  the `PrintRenderData` shape, exactly as
     *                                            `print_input_golden.json` records it
     * @param  array<string, mixed>|null  $taxSummary  per-rate snapshot COMPUTED UPSTREAM.
     *                                                 Never build one here: this layer must not
     *                                                 compute money (#1092, #1937), and a summary
     *                                                 rebuilt with zero amounts prints fabricated
     *                                                 tax lines with no test red.
     */
    public function enqueue(
        Printer $printer,
        PrintJobKind $kind,
        array $renderData,
        string $locale,
        ?array $taxSummary = null,
        ?string $orderId = null,
        ?string $paymentId = null,
        int $reprintNo = 1,
        ?string $requestedById = null,
        ?string $requestedVia = null,
        ?string $reprintReason = null,
        ?CarbonImmutable $now = null,
    ): PrintJob {
        if ($printer->transport !== PrintTransport::CloudPrnt) {
            throw new RuntimeException(sprintf(
                'printer %s uses `%s`; Cloud does not own that queue and must not schedule for it (DESIGN §1b)',
                $printer->id,
                $printer->transport?->value ?? 'ws_lan',
            ));
        }

        $now ??= CarbonImmutable::now();

        // No separate template-kind parameter. The document kind lives inside
        // `data` (the renderer reads `data.kind`), so accepting it a second time
        // as an argument would create two places to say the same thing and one
        // of them would eventually be wrong. `PrintJobKind` here is a DIFFERENT
        // vocabulary — 8 ledger values driving the retry matrix, versus 13
        // template values driving the drawing — and `receipt` is spelled the same
        // in both, which is exactly why conflating them survives testing and then
        // falls over on `vat_invoice`.
        $payload = [
            CloudPrntPayload::SCHEMA_KEY => CloudPrntPayload::SCHEMA,
            CloudPrntPayload::LOCALE_KEY => $locale,
            PrintRenderDataHydrator::DATA_KEY => $renderData,
        ];

        if ($taxSummary !== null) {
            $payload[PrintRenderDataHydrator::TAX_KEY] = $taxSummary;
        }

        // Round-trip now, not at poll time. `fromArray` is the same validator the
        // serving path runs, so passing here means the printer will get bytes.
        try {
            $envelope = CloudPrntPayload::fromArray($payload);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(
                'refusing to queue an unrenderable print job: '.$e->getMessage(),
                previous: $e,
            );
        }

        $job = new PrintJob;
        $job->id = (string) Str::uuid();
        $job->organization_id = $printer->organization_id;
        $job->branch_id = $printer->branch_id;
        $job->printer_id = $printer->id;
        $job->transport = PrintTransport::CloudPrnt;
        $job->kind = $kind;
        $job->order_id = $orderId;
        $job->payment_id = $paymentId;
        $job->payload = $payload;
        $job->reprint_no = $reprintNo;
        $job->requested_by_id = $requestedById;
        $job->requested_via = $requestedVia;
        $job->reprint_reason = $reprintReason;
        // TR-28 — the layout this job WILL be drawn with, pinned now.
        // `CloudPrntJobRenderer` reads it back at poll time, so the recorded
        // version is the one on the paper, not the one that happened to be
        // current when the printer got round to asking.
        $job->template_version = TemplateStamp::of(
            $this->resolver->forBranch($envelope->kind, (string) $printer->branch_id),
        );
        $job->status = PrintJobStatus::Queued;
        $job->confidence = $this->confidenceFor($printer);
        $job->attempts = 0;
        $job->expires_at = $this->registry->expiresAt($kind, $now);
        $job->save();

        return $job;
    }

    /**
     * P-33 — the ceiling this machine's eventual success may claim.
     *
     * Read from the printer's resolved capability profile, which answers even
     * for a machine that never went through the setup wizard (`escpos_generic`,
     * P-29) — a shop that has not filled in a form must still be able to print.
     */
    private function confidenceFor(Printer $printer): PrintConfidence
    {
        return PrintConfidence::from($printer->capabilityProfile()->printConfidence());
    }
}
