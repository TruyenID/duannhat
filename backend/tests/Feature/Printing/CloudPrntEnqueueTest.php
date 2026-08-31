<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\PrintTemplate;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\Enums\PrintTemplateStatus;
use App\Services\Print\Renderer\BillKindPlans;
use App\Services\Print\Renderer\Escpos;
use App\Services\Printing\CloudPrntEnqueueService;
use App\Services\Printing\Enums\PrintConfidence;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\Enums\PrintTransport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * plan-053 T5.4 (#1171) — the PRODUCER half of CloudPRNT.
 *
 * The serving path landed first, which left the feature in a shape that demos
 * fine and does nothing: a printer polls, is told there is no work, forever.
 * These tests are mostly about the seam between the two halves, because that is
 * where a producer written against a spec instead of against the consumer goes
 * wrong — the row exists, every column looks plausible, and the machine still
 * gets nothing.
 *
 * E3 is therefore the load-bearing one: it enqueues and then drives the REAL
 * poll/fetch/confirm sequence over HTTP. A test that only asserts columns would
 * have passed for a payload the serving path cannot parse.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $brand->console_brand_id,
        'slug' => 'enqueue-shop',
        'is_active' => true,
    ]);

    $this->printer = Printer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'transport' => PrintTransport::CloudPrnt,
    ]);

    $this->service = app(CloudPrntEnqueueService::class);

    // The same render data the Go side exports, so what gets queued is the
    // shape the emitters are byte-parity gated on rather than a hand-built
    // lookalike that only this test believes in.
    $fixture = json_decode(
        (string) file_get_contents(
            base_path('../workstation/internal/service/testdata/print_input_golden.json')
        ),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $this->clock = CarbonImmutable::parse((string) $fixture['clock']);
    $this->renderData = $fixture['cases']['receipt|ja|48'];
    $this->taxSummary = $fixture['tax_summaries']['receipt|ja|48'];
});

it('E1: stamps confidence from the printer profile, because P-33 makes it one-way', function () {
    // A `star_mcprint` printer, deliberately — NOT the factory default.
    //
    // The default resolves to `escpos_generic`, whose `error_detect.level` is
    // `none`, so `printConfidence()` is `sent_only`. Asserting against that alone
    // is vacuous: hard-coding `sent_only` in the service passes such a test, and
    // it did — measured, not supposed. The whole risk lives on the other branch,
    // where a machine CAN answer and a wrong stamp throws its answer away.
    //
    // `star_mcprint` is also the right machine to name here: it is the one
    // profile in `config/printer_profiles.php` that lists `cloudprnt` at all.
    $confirming = Printer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'transport' => PrintTransport::CloudPrnt,
        'model_profile' => 'star_mcprint',
    ]);

    expect($confirming->capabilityProfile()->printConfidence())->toBe('confirmed');

    $job = $this->service->enqueue(
        printer: $confirming,
        kind: PrintJobKind::Receipt,
        renderData: $this->renderData,
        locale: 'ja',
        taxSummary: $this->taxSummary,
    );

    expect($job->confidence)->toBe(PrintConfidence::Confirmed);

    // And the consequence of getting it wrong, made concrete: `sent_only` can
    // NEVER be promoted, so under-stamping at enqueue silently discards the
    // printer's own "200 OK" for the life of the row.
    $job->confidence = PrintConfidence::SentOnly;
    $job->save();
    $job->confidence = PrintConfidence::Confirmed;

    expect(fn () => $job->save())->toThrow(LogicException::class);

    // The generic machine still stamps its own (lower) ceiling — the value is
    // read from the profile, not fixed either way.
    $generic = $this->service->enqueue(
        printer: $this->printer,
        kind: PrintJobKind::Receipt,
        renderData: $this->renderData,
        locale: 'ja',
        taxSummary: $this->taxSummary,
    );

    expect($generic->confidence)->toBe(PrintConfidence::SentOnly);
});

it('E2: stamps expires_at at enqueue so re-tuning the TTL cannot reach back', function () {
    CarbonImmutable::setTestNow($this->clock);

    $job = $this->service->enqueue(
        printer: $this->printer,
        kind: PrintJobKind::Receipt,
        renderData: $this->renderData,
        locale: 'ja',
        taxSummary: $this->taxSummary,
    );

    expect($job->expires_at)->not->toBeNull();
    expect(CarbonImmutable::instance($job->expires_at)->greaterThan($this->clock))->toBeTrue();

    CarbonImmutable::setTestNow();
});

it('E3: a queued job is actually served to the printer, end to end', function () {
    $job = $this->service->enqueue(
        printer: $this->printer,
        kind: PrintJobKind::Receipt,
        renderData: $this->renderData,
        locale: 'ja',
        taxSummary: $this->taxSummary,
    );

    expect($job->status)->toBe(PrintJobStatus::Queued);

    $token = $this->printer->refresh()->print_token;

    // 1. poll
    $poll = $this->postJson("/api/v1/print/cloudprnt/{$token}", [
        'statusCode' => '200 OK',
        'printerMAC' => '00:11:62:00:00:01',
    ])->assertOk();

    expect($poll->json('jobReady'))->toBeTrue();

    // 2. fetch — and the bytes must be the ones Go's golden hash pins, which is
    //    what makes this a print rather than a plausible blob.
    $bytes = $this->get("/api/v1/print/cloudprnt/{$token}?type=application/vnd.star.starprnt")
        ->assertOk()
        ->getContent();

    $expected = json_decode(
        (string) file_get_contents(
            base_path('../workstation/internal/service/testdata/print_golden.json')
        ),
        true,
        512,
        JSON_THROW_ON_ERROR,
    )['receipt|ja|48'];

    // #1950 — the fixture records the NO-PROFILE render (`ESC d 3`). This printer
    // carries no `model_profile`, so the driver finishes it as `escpos_generic`:
    // feed 4, then `GS V 0`. Normalise the cut back before hashing, so the claim
    // stays "the same slip" instead of quietly becoming "roughly the same slip".
    $epilogue = str_repeat("\x0A", 4)."\x1D\x56\x00";
    Assert::assertStringEndsWith($epilogue, $bytes);
    $document = substr($bytes, 0, -strlen($epilogue)).Escpos::CUT;

    Assert::assertSame(
        $expected,
        hash('sha256', $document),
        'the bytes served over HTTP diverge from the workstation for the same order',
    );

    // 3. confirm
    $this->delete("/api/v1/print/cloudprnt/{$token}?code=200%20OK")->assertOk();

    expect($job->refresh()->status)->toBe(PrintJobStatus::Printed);
});

it('E4: refuses to queue for a printer whose queue Cloud does not own', function () {
    $wsLan = Printer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'transport' => PrintTransport::WsLan,
    ]);

    // Not a style preference. A `ws_lan` row is a JOURNAL entry for a print the
    // workstation already performed; a queued one would make Cloud a second
    // scheduler for a machine it cannot reach, and it would sit `queued` forever
    // because no CloudPRNT client will ever poll for it.
    expect(fn () => $this->service->enqueue(
        printer: $wsLan,
        kind: PrintJobKind::Receipt,
        renderData: $this->renderData,
        locale: 'ja',
    ))->toThrow(RuntimeException::class);

    expect(PrintJob::query()->where('printer_id', $wsLan->id)->count())->toBe(0);
});

it('E5: refuses an unrenderable payload AT ENQUEUE, not at poll time', function () {
    // The failure this prevents is invisible: bytes are built inside an HTTP
    // request whose only client is a thermal printer, so a bad payload there
    // means a machine that quietly prints nothing and a shop that notices a
    // missing receipt much later.
    expect(fn () => $this->service->enqueue(
        printer: $this->printer,
        kind: PrintJobKind::Receipt,
        renderData: ['Kind' => 'not_a_document_kind'],
        locale: 'ja',
    ))->toThrow(InvalidArgumentException::class);

    expect(PrintJob::query()->where('printer_id', $this->printer->id)->count())->toBe(0);
});

/**
 * plan-053 TR-28 (#1171) — WHICH layout drew the sheet, pinned at enqueue.
 *
 * Same argument as E2 (`expires_at`) applied to the drawing rather than the TTL:
 * the printer polls LATER, so anything resolved at poll time can have moved
 * since the job was created. E6 records the fact; E7 is what makes it a fact
 * rather than a decoration.
 */

/**
 * Publish a brand receipt version whose visible difference is `column_header`.
 *
 * `column_header` and NOT `footer_text`, which is the obvious choice and the
 * wrong one — measured, not assumed. In the BILL family `header_text` /
 * `footer_text` / `greeting` are registered as deliberate NO-OPS
 * ({@see BillKindPlans}: their bodies are a separate
 * slice), so a test that differentiated two versions by their footer would
 * compare two byte-identical slips and pass whatever the renderer did with the
 * pin. `column_header` has a real emitter, so the two versions genuinely differ
 * on paper.
 */
function publishReceiptVersion(Brand $brand, int $version, string $marker): void
{
    PrintTemplate::factory()->create([
        'brand_id' => $brand->id,
        'branch_id' => null,
        'kind' => 'receipt',
        'scope' => PrintTemplateScope::Brand->value,
        'status' => PrintTemplateStatus::Published->value,
        'version' => $version,
        'definition' => [
            'schema' => 'tempo.print.v1',
            'blocks' => [[
                'id' => 'column_header',
                'type' => 'text',
                'enabled' => true,
                'i18n' => ['ja' => $marker, 'en' => $marker, 'vi' => $marker],
            ]],
        ],
        'shop_editable' => [],
        'effective_from' => null,
        'published_at' => now(),
    ]);
}

function enqueueBrandOf(Branch $shop): Brand
{
    return Brand::query()
        ->where('console_brand_id', $shop->console_brand_id)
        ->firstOrFail();
}

it('E6: stamps the layout version the job will be drawn with', function () {
    publishReceiptVersion(enqueueBrandOf($this->shop), 3, 'MARKER-V3');

    $job = $this->service->enqueue(
        printer: $this->printer,
        kind: PrintJobKind::Receipt,
        renderData: $this->renderData,
        locale: 'ja',
        taxSummary: $this->taxSummary,
    );

    Assert::assertSame('brand:3', $job->refresh()->template_version);
});

it('E6b: stamps system:0 when the brand has published nothing', function () {
    // The code-shipped layer 0 is a real answer, not a missing one — and it must
    // be spelled exactly as the Go workstation spells it, because both write the
    // same column and a reader cannot tell which tier produced a row.
    $job = $this->service->enqueue(
        printer: $this->printer,
        kind: PrintJobKind::Receipt,
        renderData: $this->renderData,
        locale: 'ja',
        taxSummary: $this->taxSummary,
    );

    Assert::assertSame('system:0', $job->refresh()->template_version);
});

it('E7: a template published AFTER enqueue does not re-draw the queued job', function () {
    $brand = enqueueBrandOf($this->shop);
    publishReceiptVersion($brand, 1, 'MARKER-BEFORE');

    $job = $this->service->enqueue(
        printer: $this->printer,
        kind: PrintJobKind::Receipt,
        renderData: $this->renderData,
        locale: 'ja',
        taxSummary: $this->taxSummary,
    );

    Assert::assertSame('brand:1', $job->refresh()->template_version);

    // HQ republishes while the printer is still switched off. Without the pin the
    // machine wakes up and prints the NEW layout under the OLD job's ledger row —
    // the row then names a version that did not draw the paper, and "in lại đúng
    // bản gốc" is unanswerable from that moment on.
    publishReceiptVersion($brand, 2, 'MARKER-AFTER');

    $token = $this->printer->refresh()->print_token;

    $this->postJson("/api/v1/print/cloudprnt/{$token}", [
        'statusCode' => '200 OK',
        'printerMAC' => '00:11:62:00:00:01',
    ])->assertOk();

    $bytes = $this->get("/api/v1/print/cloudprnt/{$token}?type=application/vnd.star.starprnt")
        ->assertOk()
        ->getContent();

    Assert::assertStringContainsString(
        'MARKER-BEFORE',
        $bytes,
        'the queued job was drawn with a template published after it was queued',
    );
    Assert::assertStringNotContainsString('MARKER-AFTER', $bytes);
});

it('E7b: a job carrying no pin still prints, using the current template', function () {
    // Rows created before TR-28 carry no stamp, and so does every `ws_lan`
    // journal row. The renderer must fall through to the normal resolve rather
    // than refuse — a shop that cannot print is a shop that cannot trade.
    publishReceiptVersion(enqueueBrandOf($this->shop), 1, 'MARKER-CURRENT');

    $job = $this->service->enqueue(
        printer: $this->printer,
        kind: PrintJobKind::Receipt,
        renderData: $this->renderData,
        locale: 'ja',
        taxSummary: $this->taxSummary,
    );

    PrintJob::query()->whereKey($job->id)->update(['template_version' => null]);

    $token = $this->printer->refresh()->print_token;

    $this->postJson("/api/v1/print/cloudprnt/{$token}", [
        'statusCode' => '200 OK',
        'printerMAC' => '00:11:62:00:00:01',
    ])->assertOk();

    $bytes = $this->get("/api/v1/print/cloudprnt/{$token}?type=application/vnd.star.starprnt")
        ->assertOk()
        ->getContent();

    Assert::assertStringContainsString('MARKER-CURRENT', $bytes);
});

it('E7c: a pin whose version was hard-deleted still prints, from the current one', function () {
    // TR-29's visible "template has changed" marker is NOT built here; the log in
    // `CloudPrntJobRenderer::pinnedDefinition` is the honest interim. What must
    // never happen is a machine that refuses because a provenance row is gone.
    $brand = enqueueBrandOf($this->shop);
    publishReceiptVersion($brand, 1, 'MARKER-GONE');

    $job = $this->service->enqueue(
        printer: $this->printer,
        kind: PrintJobKind::Receipt,
        renderData: $this->renderData,
        locale: 'ja',
        taxSummary: $this->taxSummary,
    );
    Assert::assertSame('brand:1', $job->refresh()->template_version);

    PrintTemplate::query()->where('brand_id', $brand->id)->forceDelete();
    publishReceiptVersion($brand, 9, 'MARKER-REPLACEMENT');

    $token = $this->printer->refresh()->print_token;

    $this->postJson("/api/v1/print/cloudprnt/{$token}", [
        'statusCode' => '200 OK',
        'printerMAC' => '00:11:62:00:00:01',
    ])->assertOk();

    $bytes = $this->get("/api/v1/print/cloudprnt/{$token}?type=application/vnd.star.starprnt")
        ->assertOk()
        ->getContent();

    Assert::assertStringContainsString('MARKER-REPLACEMENT', $bytes);
});
