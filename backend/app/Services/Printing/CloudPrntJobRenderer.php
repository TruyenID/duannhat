<?php

declare(strict_types=1);

namespace App\Services\Printing;

use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\Print\Renderer\Finishing;
use App\Services\Print\Renderer\PrintRenderData;
use App\Services\Print\Renderer\PrintRenderDataHydrator;
use App\Services\Print\Renderer\PrintRenderer;
use App\Services\Print\Renderer\PrintRenderProfile;
use App\Services\Print\SystemTemplateDefaults;
use App\Services\Print\TemplateResolver;
use App\Services\Print\TemplateStamp;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * plan-053 T5.4 (#1171) — the bytes a CloudPRNT machine downloads.
 *
 * `print_jobs.payload` → {@see PrintRenderData} →
 * the shop's resolved template → ESC/POS. This is the first place Cloud builds
 * a slip for a real machine rather than for a preview, which is why it was the
 * blocker on opening the `cloudprnt` transport: the byte parity landed in T5.2b
 * proves the emitters agree with the workstation, but nothing had ever fed them
 * from a stored job.
 *
 * ── The dialect is Star, and that is not incidental ───────────────────────
 *
 * `Escpos` targets Star mC-Print in **StarPRNT emulation** — alignment is
 * `ESC GS a` and the cut is `ESC d`, deliberately not Epson's `ESC a` / `GS V`
 * (see the encoder's docblock, and #438, where an Epson cut command was
 * silently ignored and the receipt never ejected). That dialect is exactly what
 * a CloudPRNT client fetches as `application/vnd.star.starprnt`, which is why
 * CloudPRNT is the ONE cloud transport this parity unlocks: `epos_http` and
 * `webprnt` carry different languages that no renderer on either side speaks.
 *
 * ── It never computes money ───────────────────────────────────────────────
 *
 * The tax snapshot arrives in the payload or does not arrive at all
 * (#1092, #1937). This class does not reach for `OrderTaxBreakdownReads`, does
 * not recompute a rate, and must never construct a zero-filled summary to
 * "have something" — that fabrication prints tax lines nobody computed and no
 * test in this repo went red for it.
 */
final class CloudPrntJobRenderer
{
    /**
     * The ONLY media type this server advertises.
     *
     * One entry, on purpose: `mediaTypes` is a menu the printer chooses from,
     * and offering a format Cloud cannot actually produce turns a poll into a
     * GET that 404s forever. Star's own list also has `application/vnd.star.line`
     * (Star Line Mode) and `application/vnd.star.starprntcore`; both are
     * different command sets, and neither has a byte baseline here.
     *
     * @see https://star-m.jp/products/s_print/sdk/StarCloudPRNT/manual/en/protocol-reference/common-spec-reference/content-mediatypes/index.html
     */
    public const MEDIA_TYPE = 'application/vnd.star.starprnt';

    public function __construct(
        private readonly TemplateResolver $resolver,
        private readonly SystemTemplateDefaults $defaults,
        private readonly PrintRenderer $renderer,
        private readonly PrintRenderDataHydrator $hydrator,
    ) {}

    /** @return list<string> */
    public static function mediaTypes(): array
    {
        return [self::MEDIA_TYPE];
    }

    /**
     * True when the printer asked for a format this server can produce. An
     * omitted `type` means "server's choice" (Star's rule), not "any".
     */
    public static function servesMediaType(?string $requested): bool
    {
        if ($requested === null || $requested === '') {
            return true;
        }

        return in_array(strtolower(trim($requested)), self::mediaTypes(), true);
    }

    /**
     * @throws RuntimeException when the job cannot be turned into a slip. The
     *                          caller fails the job with this reason rather
     *                          than handing the printer a partial document.
     */
    public function render(PrintJob $job, Printer $printer): string
    {
        try {
            $payload = CloudPrntPayload::fromArray($job->payload);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        /*
         * There is deliberately NO "is this kind implemented?" check here.
         *
         * One stood in this spot and was deleted because it could not be made
         * to matter: {@see PrintRenderer::render()} already refuses a kind with
         * no registered plan, naming the kind, and the caller fails the job
         * either way. Two mutation runs confirmed it — deleting the check
         * changed no observable behaviour, which is the definition of a guard
         * that is decoration rather than protection.
         *
         * The rule it stated is still real and still enforced, just one layer
         * down: a kind with no emitter has no byte baseline against the
         * workstation, so Cloud must not improvise a slip for it. `kitchen` is
         * the one kind in that state today (SLIP_PARITY_NOT_YET).
         */
        $paperWidth = (int) ($printer->paper_width ?: 80);
        $capability = $printer->capabilityProfile();
        $profile = new PrintRenderProfile(
            columns: $capability->columnsFor($paperWidth),
            paper: $paperWidth <= 58 ? '58mm' : '80mm',
            // #1950 — the cut this MACHINE declares, not a hard-coded ESC d 3.
            finishing: self::finishingFor($capability),
        );

        try {
            $data = $this->hydrator->hydrate($payload->data);
            $tax = $this->hydrator->taxSummary($payload->taxSummary);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        return $this->renderer
            ->render($this->definitionFor($job, $printer, $payload), $data, $profile, $payload->locale, $tax)
            ->bytes();
    }

    /**
     * #1950 — the cut this machine actually understands.
     *
     * The emitters used to end in a hard-coded `ESC d 3`, so of the three
     * shipped presets `escpos_generic` (`gs_v_full`) and `epson_tm_i`
     * (`gs_v_partial`) were both sent a dialect they do not speak, and
     * `star_mcprint` was right only by coincidence — its declared command
     * happened to be the hard-coded one. `feed_before_cut` was ignored outright.
     *
     * `epson_tm_i` is the expensive one: it declares PARTIAL precisely so a tab
     * of paper keeps the slip hanging in the mechanism for the cashier to tear.
     * A full cut drops it on the floor, and no golden hash would ever say so.
     *
     * This is the point on the Cloud side where a rendered slip meets a REAL
     * machine, so it is the point that knows which command to ask for — the
     * mirror of `service.PrintRenderProfileFor` on the workstation, which fills
     * the same field from the same profile.
     *
     * ── A hazard worth stating, because it is data and not code ───────────
     *
     * CloudPRNT is Star-only (see MEDIA_TYPE), and a Star in StarPRNT emulation
     * IGNORES `GS V` (#438). A CloudPRNT printer whose `model_profile` is NULL
     * resolves to `escpos_generic`, which declares `gs_v_full` — so it will now
     * be sent a cut it does not understand and the paper will not eject. That is
     * the profile being WRONG rather than this code being wrong, and the fix is
     * the setup wizard (or a `star_mcprint` preset on the row). It is called out
     * because before #1950 the mistake was invisible: every machine got ESC d 3
     * regardless of what its profile claimed.
     */
    private static function finishingFor(PrinterCapabilityProfile $capability): Finishing
    {
        return new Finishing(
            cutMode: $capability->cutMode(),
            feedBeforeCut: $capability->feedBeforeCut(),
            autoCutPerJob: $capability->autoCutPerJob(),
        );
    }

    /**
     * The definition to draw with: the version PINNED on the job at enqueue,
     * falling back to the branch's current template, falling back to the
     * code-shipped default.
     *
     * ── Why the pin comes first (TR-28, #1171) ────────────────────────────
     *
     * The printer polls later — seconds usually, hours if it was switched off.
     * Resolving `forBranch` here would draw with whatever is current AT POLL
     * TIME, so a template published in the meantime silently re-draws work that
     * was already queued, and `print_jobs.template_version` would then record a
     * version the sheet was not printed from. A stamp the renderer ignores is
     * worse than no stamp: it reads like evidence and is not.
     *
     * This is the same argument as `expires_at`, stamped at enqueue by
     * {@see CloudPrntEnqueueService} so re-tuning the TTL matrix cannot
     * retroactively expire queued work.
     *
     * A pinned version that no longer resolves (the row was hard-deleted, or the
     * job predates the column) falls through to the current template with a loud
     * log. TR-29 wants a visible "template has changed" marker on that sheet;
     * that marker is not built here, and this log is the honest interim — it
     * says the substitution happened rather than hiding it.
     *
     * ── The fallback is HERE, and its first home was wrong ────────────────
     *
     * It was originally wrapped around `PrintRenderer::render()`, which reads
     * like the obvious place and is not. Measured: that renderer throws on
     * exactly two things — an empty kind and a kind with no registered plan —
     * and BOTH come from the payload, not the definition, so the system default
     * cannot fix either. A malformed block is skipped silently by design. The
     * catch was therefore unreachable, and a mutation deleting it changed
     * nothing, which is how it was found.
     *
     * What TR-14 / TESTS W5 actually describe is a stored definition that has
     * ROTTED — and that blows up in the resolver, one call earlier. Validation
     * happens at publish; at print time a shop that cannot print is a shop that
     * cannot trade, so the answer is the shipped default plus a loud log.
     *
     * @return array<string, mixed>
     */
    private function definitionFor(PrintJob $job, Printer $printer, CloudPrntPayload $payload): array
    {
        if ($pinned = $this->pinnedDefinition($job, $payload)) {
            return $pinned;
        }

        try {
            return $this->resolver->forBranch($payload->kind, (string) $job->branch_id)->definition;
        } catch (\Throwable $e) {
            Log::warning('cloudprnt_definition_unrenderable_falling_back_to_system_default', [
                'print_job_id' => $job->id,
                'printer_id' => $printer->id,
                'branch_id' => $job->branch_id,
                'kind' => $payload->kind->value,
                'reason' => $e->getMessage(),
            ]);

            return $this->defaults->forKind($payload->kind);
        }
    }

    /**
     * The definition named by the job's stamp, or null if there is nothing to
     * honour.
     *
     * Null covers three genuinely different situations, and all three want the
     * same answer — resolve normally — so they are not distinguished beyond the
     * log:
     *
     *   - no stamp (a `ws_lan` journal row, or a job queued before TR-28). There
     *     is nothing to honour and nothing is wrong;
     *   - `system:0`, the code-shipped layer 0. It has no database row, so it is
     *     served straight from {@see SystemTemplateDefaults} — NOT null;
     *   - a stamp whose row is genuinely gone. That one is logged: the sheet is
     *     about to be drawn with a definition other than the one recorded on it,
     *     which is exactly the fact a later audit needs and the only place it can
     *     be stated.
     *
     * @return array<string, mixed>|null
     */
    private function pinnedDefinition(PrintJob $job, CloudPrntPayload $payload): ?array
    {
        $stamp = TemplateStamp::parse($job->template_version);

        if ($stamp === null) {
            return null;
        }

        if ($stamp->isSystemDefault()) {
            return $this->defaults->forKind($payload->kind);
        }

        try {
            $resolved = $this->resolver->forVersion(
                $payload->kind,
                (string) $job->branch_id,
                $stamp->version,
                $stamp->scope,
            );
        } catch (\Throwable) {
            $resolved = null;
        }

        if ($resolved !== null) {
            return $resolved->definition;
        }

        Log::warning('cloudprnt_pinned_template_version_lost', [
            'print_job_id' => $job->id,
            'branch_id' => $job->branch_id,
            'kind' => $payload->kind->value,
            'template_version' => $job->template_version,
        ]);

        return null;
    }
}
