<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Renderer\Escpos;
use App\Services\Print\Renderer\PrintJobConfig;
use App\Services\Print\Renderer\PrintKindRegistry;
use App\Services\Print\Renderer\PrintRenderData;
use App\Services\Print\Renderer\PrintRenderDataHydrator;
use App\Services\Print\Renderer\PrintRenderer;
use App\Services\Print\Renderer\PrintRenderProfile;
use App\Services\Print\SystemTemplateDefaults;
use App\Services\Printing\CloudPrntJobRenderer;
use App\Services\Printing\CloudPrntPayload;
use App\Services\Printing\Enums\PrintConfidence;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\Enums\PrintTransport;
use App\Services\Printing\PrinterCapabilityProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

uses(RefreshDatabase::class);

/**
 * #1950 — WHO OWNS THE CUT, on the Cloud side.
 *
 * `Escpos::finish()` had existed with no caller: every emitter ended in a
 * `fullCut()` that wrote `ESC d 3` whatever the machine was. Of the three
 * shipped presets that made `escpos_generic` (`gs_v_full`) and `epson_tm_i`
 * (`gs_v_partial`) both wrong, and `star_mcprint` right only because its
 * declared dialect happened to be the hard-coded one. `feed_before_cut` was
 * ignored entirely.
 *
 * The expensive case is `epson_tm_i`: it declares PARTIAL precisely so a tab of
 * paper keeps the slip hanging in the mechanism for the cashier to tear. Sent a
 * full cut it drops on the floor, and neither a golden hash nor a review would
 * ever have said so.
 *
 * The workstation half landed separately (#1950, PR godx-tempo-workstation-app
 * #205). This is the Cloud half, and the shape is deliberately the SAME so the
 * two repos cannot drift: `PrintRenderProfile` carries a NULLABLE finishing spec
 * mirroring Go's `Finishing *escpos.Finishing`, null reproduces today's bytes
 * exactly, and the driver — the one place that holds both a rendered slip and a
 * real machine — fills it in.
 *
 * These tests go through {@see CloudPrntJobRenderer::render()}, the code the
 * CloudPRNT route really calls, rather than through `Escpos::finish()` directly.
 * The bug was never in `finish()`; it was that nothing CALLED it, and only an
 * end-to-end assertion tells those two apart.
 */
function finishingPrinter(?array $modelProfile): Printer
{
    return Printer::factory()->create([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'paper_width' => 80,
        'model_profile' => $modelProfile,
    ]);
}

/**
 * The `receipt|ja|48` golden cell as a stored `print_jobs.payload`.
 *
 * Deliberately the SAME fixture the parity gate uses, so the document half of
 * these bytes is one the workstation is known to produce identically. What is
 * under test is only what follows it.
 */
function finishingPayload(): array
{
    $input = json_decode(
        (string) file_get_contents(base_path('../workstation/internal/service/testdata/print_input_golden.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $key = 'receipt|ja|48';

    return [
        CloudPrntPayload::SCHEMA_KEY => CloudPrntPayload::SCHEMA,
        CloudPrntPayload::LOCALE_KEY => 'ja',
        PrintRenderDataHydrator::DATA_KEY => $input['cases'][$key],
        PrintRenderDataHydrator::TAX_KEY => $input['tax_summaries'][$key] ?? null,
    ];
}

/** Bytes the driver would hand this machine for that cell. */
function finishingServedBytes(Printer $printer): string
{
    $job = PrintJob::factory()->create([
        'organization_id' => $printer->organization_id,
        'branch_id' => $printer->branch_id,
        'printer_id' => $printer->id,
        'transport' => PrintTransport::CloudPrnt->value,
        'kind' => PrintJobKind::Receipt->value,
        'status' => PrintJobStatus::Queued->value,
        'confidence' => PrintConfidence::Confirmed->value,
        'attempts' => 0,
        'printed_reported_at' => null,
        'expires_at' => now()->addHour(),
        'payload' => finishingPayload(),
    ]);

    return app(CloudPrntJobRenderer::class)->render($job->fresh(), $printer);
}

beforeEach(function (): void {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'timezone' => 'Asia/Tokyo',
        'is_active' => true,
    ]);
});

it('#1950 each shipped preset receives the cut dialect it DECLARES', function (?array $profile, string $expected, string $why) {
    $bytes = finishingServedBytes(finishingPrinter($profile));

    expect(substr($bytes, -strlen($expected)))->toBe($expected, $why);
})->with([
    'escpos_generic (the P-29 fallback)' => [
        null,
        str_repeat("\x0A", 4)."\x1D\x56\x00",
        'a generic ESC/POS box understands GS V 0, not the Star ESC d it used to be sent',
    ],
    'epson_tm_i — PARTIAL, so the slip does not fall on the floor' => [
        ['preset' => 'epson_tm_i'],
        str_repeat("\x0A", 4)."\x1D\x56\x01",
        'GS V 1 leaves a tab of paper holding the slip; a full cut drops it',
    ],
    'star_mcprint — ESC d 3, which feeds its own 3 lines' => [
        ['preset' => 'star_mcprint'],
        "\x1B\x64\x33",
        'ESC d n feeds n lines then cuts, so no separate feed may be emitted',
    ],
]);

it('#1950 a tear-bar machine is fed and receives NO cut command at all', function () {
    // P-36: some cheap firmware prints an unrecognised escape as literal garbage
    // onto the NEXT customer's slip, so "send it and hope" is not a safe default.
    // It must still be fed, or the last line sits inside the mechanism and the
    // operator tears through the total.
    $printer = finishingPrinter(['finishing' => ['cut' => ['mode' => 'none', 'feed_before_cut' => 4]]]);
    $bytes = finishingServedBytes($printer);

    foreach ([
        'ESC d 3' => Escpos::CUT,
        'ESC d 2' => Escpos::PARTIAL_CUT,
        'GS V 0' => Escpos::GS_V_FULL_CUT,
        'GS V 1' => Escpos::GS_V_PARTIAL_CUT,
    ] as $name => $command) {
        // `assertStringNotContainsString`, never `expect()->not->toContain()`:
        // Pest's `toContain` is variadic, so the second argument is another
        // needle rather than a message and the negation passes unconditionally.
        Assert::assertStringNotContainsString($command, $bytes, "tear-bar machine was sent {$name}");
    }

    expect(substr($bytes, -4))->toBe(str_repeat("\x0A", 4));
});

it('#1950 a machine that cuts by itself is sent nothing extra', function () {
    // A cut on top of the machine's own is a second, BLANK slip every time —
    // a roll of paper per busy day.
    $auto = finishingPrinter([
        'preset' => 'star_mcprint',
        'finishing' => ['cut' => ['auto_cut_per_job' => true]],
    ]);
    $plain = finishingPrinter(['preset' => 'star_mcprint']);

    $withAuto = finishingServedBytes($auto);
    $withoutAuto = finishingServedBytes($plain);

    expect($withAuto)->toBe(substr($withoutAuto, 0, -strlen(Escpos::CUT)));
    Assert::assertStringNotContainsString(Escpos::CUT, $withAuto);
});

it('#1950 feed_before_cut is honoured — it is a chassis measurement, not a preference', function () {
    // The distance from print head to blade differs per chassis; too little feed
    // slices the last line off the slip. It is data so a shop can correct it
    // without a release — and until #1950 nothing read it at all.
    $bytes = finishingServedBytes(finishingPrinter([
        'finishing' => ['cut' => ['mode' => 'gs_v_full', 'feed_before_cut' => 9]],
    ]));

    expect(substr($bytes, -12))->toBe(str_repeat("\x0A", 9)."\x1D\x56\x00");
});

it('#1950 an UNRECOGNISED cut mode sends no cut, matching the workstation', function () {
    // `model_profile` is a free-form JSON column — nothing validates its
    // contents — so a typo can be stored. Go's `Profile.normalised()` maps an
    // unknown mode to `none`; PHP must agree, or the two repos disagree about
    // what a corrupt profile does to paper. P-36 decides which way: no cut.
    $profile = PrinterCapabilityProfile::resolve([
        'finishing' => ['cut' => ['mode' => 'guillotine', 'feed_before_cut' => 4]],
    ]);
    expect($profile->cutMode())->toBe('none');

    $bytes = finishingServedBytes(finishingPrinter([
        'finishing' => ['cut' => ['mode' => 'guillotine', 'feed_before_cut' => 4]],
    ]));

    Assert::assertStringNotContainsString(Escpos::GS_V_FULL_CUT, $bytes);
    Assert::assertStringNotContainsString(Escpos::CUT, $bytes);
    expect(substr($bytes, -4))->toBe(str_repeat("\x0A", 4));
});

it('#1950 a NO-PROFILE render still emits exactly the bytes it always did', function () {
    /*
     * The safety half of this change, and the reason the byte goldens and the
     * Go↔PHP parity gate did not have to be regenerated.
     *
     * `PrintRenderProfile::$finishing` is nullable on purpose — it mirrors Go's
     * `Finishing *escpos.Finishing` down to the pointer — and null means "no
     * profile", which reproduces `fullCut()` (`ESC d 3`) byte for byte. If this
     * goes red, the change has leaked outside the scope it claims: a shop that
     * has never configured a printer profile would start printing something
     * other than what it printed yesterday.
     */
    $renderer = app(PrintRenderer::class);
    $defaults = app(SystemTemplateDefaults::class);

    $checked = 0;
    foreach (app(PrintKindRegistry::class)->kinds() as $kind) {
        foreach (['ja', 'en', 'vi'] as $locale) {
            foreach ([32, 42, 48] as $columns) {
                $bytes = $renderer->render(
                    $defaults->forKind(PrintTemplateKind::from($kind)),
                    new PrintRenderData(kind: $kind, config: new PrintJobConfig),
                    new PrintRenderProfile(columns: $columns),
                    $locale,
                )->bytes();
                $checked++;

                Assert::assertStringEndsWith(
                    Escpos::CUT,
                    $bytes,
                    "{$kind}|{$locale}|{$columns} no longer ends in ESC d 3 with no profile — #1950 leaked out of scope",
                );
            }
        }
    }

    // A green run over zero cells is the shape of a guard that guards nothing.
    // 14, not 13: `kitchen` became renderable when it and the hall slip were put
    // on one shared template, so the matrix covers one more kind.
    expect($checked)->toBe(14 * 3 * 3);
});
