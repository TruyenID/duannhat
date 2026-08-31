<?php

declare(strict_types=1);

use App\Http\Resources\PrinterResource;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\Print\Renderer\Escpos;
use App\Services\Print\Renderer\PrintRenderDataHydrator;
use App\Services\Print\TemplateResolver;
use App\Services\Printing\CloudPrntJobRenderer;
use App\Services\Printing\CloudPrntPayload;
use App\Services\Printing\Enums\PrintConfidence;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\Enums\PrintTransport;
use App\Services\Printing\Enums\UposPrinterStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

uses(RefreshDatabase::class);

/**
 * plan-052 M4 / plan-053 T5.4 (#1171) — the Star CloudPRNT serving path.
 *
 * The protocol here is Star's, taken from the CloudPRNT Protocol Guide, not
 * from `plans/plan-052/DESIGN.md` §2 — which sketches GET as the poll and POST
 * as the confirm, exactly backwards, and would not have talked to a machine:
 *
 *   POST   poll     → {jobReady, mediaTypes, jobToken, deleteMethod}
 *   GET    fetch    → application/vnd.star.starprnt
 *   DELETE confirm  → ?code=200%20OK
 *
 * @see https://star-m.jp/products/s_print/sdk/StarCloudPRNT/manual/en/protocol-reference/http-method-reference/server-polling-post/json-response.html
 */

/** The golden cell used wherever a REAL slip is needed, so bytes stay checkable. */
function cloudPrntGoldenCase(string $key = 'receipt|ja|48'): array
{
    $path = base_path('../workstation/internal/service/testdata/print_input_golden.json');
    $expectedPath = base_path('../workstation/internal/service/testdata/print_golden.json');

    $input = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $expected = json_decode((string) file_get_contents($expectedPath), true, 512, JSON_THROW_ON_ERROR);

    return [
        'clock' => (string) $input['clock'],
        'payload' => [
            CloudPrntPayload::SCHEMA_KEY => CloudPrntPayload::SCHEMA,
            CloudPrntPayload::LOCALE_KEY => 'ja',
            PrintRenderDataHydrator::DATA_KEY => $input['cases'][$key],
            PrintRenderDataHydrator::TAX_KEY => $input['tax_summaries'][$key] ?? null,
        ],
        'sha256' => (string) $expected[$key],
    ];
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

function cloudPrntPrinter(array $overrides = []): Printer
{
    return Printer::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'transport' => PrintTransport::CloudPrnt->value,
        'paper_width' => 80,
    ], $overrides));
}

function cloudPrntJob(Printer $printer, array $overrides = []): PrintJob
{
    return PrintJob::factory()->create(array_merge([
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
        'payload' => cloudPrntGoldenCase()['payload'],
    ], $overrides));
}

function cloudPrntUrl(string $token): string
{
    return "/api/v1/print/cloudprnt/{$token}";
}

/**
 * The served slip with its cut normalised back to `ESC d 3`, so it can be hashed
 * against the recorded golden.
 *
 * #1950 — the golden fixture records the NO-PROFILE render (`ESC d 3`, what this
 * renderer has always produced). A real print job carries a real machine, and
 * `CloudPrntJobRenderer` now finishes it the way that machine declares. The
 * factory printer has no `model_profile`, so it resolves to `escpos_generic`:
 * feed 4, then `GS V 0`.
 *
 * Swapping the epilogue rather than loosening the hash keeps BOTH claims
 * checkable and separate: everything up to the cut is byte-identical to the
 * workstation's slip for the same order, and the cut itself is asserted against
 * the profile in `CloudPrntFinishingTest`.
 */
function cloudPrntGenericEpilogue(): string
{
    return str_repeat("\x0A", 4)."\x1D\x56\x00";
}

function cloudPrntNormalisedToGolden(string $bytes): string
{
    $epilogue = cloudPrntGenericEpilogue();

    Assert::assertStringEndsWith($epilogue, $bytes, 'the served slip does not end in the escpos_generic cut');

    return substr($bytes, 0, -strlen($epilogue)).Escpos::CUT;
}

// ─── auth (P-16) ─────────────────────────────────────────────────────────────

it('P-16: an unknown, short, inactive or re-transported token is 401 on every verb', function () {
    $printer = cloudPrntPrinter();
    $token = (string) $printer->fresh()->print_token;

    $cases = [
        'unknown' => str_repeat('z', 64),
        // Below the ≥32-byte floor: rejected without a lookup at all.
        'short' => 'abc',
    ];

    foreach ($cases as $label => $bad) {
        expect($this->postJson(cloudPrntUrl($bad), [])->status())->toBe(401, $label);
        expect($this->get(cloudPrntUrl($bad))->status())->toBe(401, $label);
        expect($this->delete(cloudPrntUrl($bad).'?code=200+OK')->status())->toBe(401, $label);
    }

    // Deactivating revokes immediately, at the very next poll.
    $printer->update(['is_active' => false]);
    expect($this->postJson(cloudPrntUrl($token), [])->status())->toBe(401);

    // So does moving the machine off the transport — the token row still
    // exists, and it stops working. That is what makes "switch it back to
    // ws_lan" a real revocation rather than a UI preference.
    $printer->update(['is_active' => true, 'transport' => PrintTransport::WsLan->value]);
    expect($this->postJson(cloudPrntUrl($token), [])->status())->toBe(401);
});

it('P-16: the print token is minted for a cloudprnt printer and never re-readable', function () {
    $printer = cloudPrntPrinter();

    expect($printer->print_token)->toBeString();
    expect(strlen((string) $printer->print_token))->toBeGreaterThanOrEqual(32);

    // Revealed exactly once, on the response that minted it…
    $revealed = (new PrinterResource($printer))->toArray(request());
    expect($revealed['print_token'])->toBe($printer->print_token);

    // …and never again. A secret re-readable from a list endpoint is a secret
    // that ends up in a screenshot.
    $reloaded = Printer::query()->findOrFail($printer->id);
    $later = (new PrinterResource($reloaded))->toArray(request());
    Assert::assertArrayNotHasKey('print_token', $later);
});

// ─── poll (POST) ─────────────────────────────────────────────────────────────

it('answers a poll with jobReady false and still advertises what it can print', function () {
    $printer = cloudPrntPrinter();

    $this->postJson(cloudPrntUrl((string) $printer->print_token), ['statusCode' => '200 OK'])
        ->assertOk()
        ->assertExactJson([
            'jobReady' => false,
            'mediaTypes' => [CloudPrntJobRenderer::MEDIA_TYPE],
        ]);

    // The poll is the ONLY health signal this machine gives — it is behind the
    // shop's NAT and cannot be dialled, so Cloud infers silence (P-38).
    $printer->refresh();
    expect($printer->last_seen_at)->not->toBeNull();
    expect($printer->last_status)->toBe(UposPrinterStatus::Ok);
});

it('maps Star status codes onto the UPOS vocabulary the rest of the pipeline speaks', function () {
    $printer = cloudPrntPrinter();
    $url = cloudPrntUrl((string) $printer->print_token);

    $expected = [
        '200 OK' => UposPrinterStatus::Ok,
        '211 Paper low' => UposPrinterStatus::PaperNearEnd,
        '410 Out of paper' => UposPrinterStatus::PaperEnd,
        '420 Cover open' => UposPrinterStatus::CoverOpen,
        '411 Paper jam' => UposPrinterStatus::Error,
        '511 Media decoding error' => UposPrinterStatus::Error,
    ];

    foreach ($expected as $code => $upos) {
        $this->postJson($url, ['statusCode' => $code])->assertOk();
        expect($printer->fresh()->last_status)->toBe($upos, $code);
    }
});

it('offers a queued job, naming the job id as the CloudPRNT jobToken', function () {
    $printer = cloudPrntPrinter();
    $job = cloudPrntJob($printer);

    $this->postJson(cloudPrntUrl((string) $printer->print_token), ['statusCode' => '200 OK'])
        ->assertOk()
        ->assertExactJson([
            'jobReady' => true,
            'mediaTypes' => [CloudPrntJobRenderer::MEDIA_TYPE],
            'jobToken' => $job->id,
            'deleteMethod' => 'DELETE',
        ]);
});

it('never offers a ws_lan journal row — Cloud does not schedule what it did not print', function () {
    $printer = cloudPrntPrinter();

    // §1b: a ws_lan row is a FACT that already happened at the shop. Handing it
    // to a CloudPRNT machine would print a second copy of a slip the shop
    // already has in its hand.
    cloudPrntJob($printer, [
        'transport' => PrintTransport::WsLan->value,
        'status' => PrintJobStatus::Queued->value,
    ]);

    $this->postJson(cloudPrntUrl((string) $printer->print_token), [])
        ->assertOk()
        ->assertJsonPath('jobReady', false);
});

it('never offers a failed or needs_attention job — retry is not the printer\'s decision (PR1)', function () {
    $printer = cloudPrntPrinter();

    foreach ([PrintJobStatus::Failed, PrintJobStatus::NeedsAttention] as $status) {
        PrintJob::query()->delete();
        cloudPrntJob($printer, ['status' => $status->value]);

        $this->postJson(cloudPrntUrl((string) $printer->print_token), [])
            ->assertOk()
            ->assertJsonPath('jobReady', false, $status->value);
    }
});

it('P-06: a job past its TTL is expired rather than handed to a machine that polled late', function () {
    $printer = cloudPrntPrinter();
    $job = cloudPrntJob($printer, [
        'kind' => PrintJobKind::Kitchen->value,
        'expires_at' => now()->subMinute(),
    ]);

    $this->postJson(cloudPrntUrl((string) $printer->print_token), [])
        ->assertOk()
        ->assertJsonPath('jobReady', false);

    expect($job->fresh()->status)->toBe(PrintJobStatus::Expired);
});

it('P-34: a money document is withheld from a machine reporting an open cover', function () {
    $printer = cloudPrntPrinter();
    cloudPrntJob($printer, ['kind' => PrintJobKind::Receipt->value]);

    $this->postJson(cloudPrntUrl((string) $printer->print_token), ['statusCode' => '420 Cover open'])
        ->assertOk()
        ->assertJsonPath('jobReady', false);

    // …and is offered the moment the cover is closed. Withheld, not lost.
    $this->postJson(cloudPrntUrl((string) $printer->print_token), ['statusCode' => '200 OK'])
        ->assertOk()
        ->assertJsonPath('jobReady', true);
});

// ─── fetch (GET) ─────────────────────────────────────────────────────────────

it('serves StarPRNT bytes that are byte-identical to the workstation\'s slip', function () {
    $golden = cloudPrntGoldenCase();
    CarbonImmutable::setTestNow(CarbonImmutable::parse($golden['clock']));

    $printer = cloudPrntPrinter();
    $job = cloudPrntJob($printer, ['payload' => $golden['payload']]);

    $response = $this->get(cloudPrntUrl((string) $printer->print_token)
        .'?mac=00:11:62:00:00:01&type='.urlencode(CloudPrntJobRenderer::MEDIA_TYPE)
        .'&token='.$job->id);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe(CloudPrntJobRenderer::MEDIA_TYPE);

    $bytes = $response->getContent();

    // The whole point of T5.4: what a CloudPRNT machine downloads from Cloud is
    // the SAME slip the workstation would have printed for the same order. Not
    // "looks right" — the same sha256 the Go renderer produced, once the cut is
    // normalised back to the no-profile dialect the fixture records (#1950).
    expect(hash('sha256', cloudPrntNormalisedToGolden($bytes)))->toBe($golden['sha256']);

    // And it really is the Star dialect, not Epson: ESC @ then ESC GS a.
    expect(substr($bytes, 0, 2))->toBe("\x1B\x40");
    Assert::assertStringContainsString("\x1B\x1D\x61", $bytes);

    CarbonImmutable::setTestNow();
});

it('P-01: a re-fetch returns the same bytes and does NOT burn another attempt', function () {
    $golden = cloudPrntGoldenCase();
    CarbonImmutable::setTestNow(CarbonImmutable::parse($golden['clock']));

    $printer = cloudPrntPrinter();
    $job = cloudPrntJob($printer, ['payload' => $golden['payload']]);
    $url = cloudPrntUrl((string) $printer->print_token).'?token='.$job->id;

    $first = $this->get($url);
    $first->assertOk();

    $job->refresh();
    expect($job->status)->toBe(PrintJobStatus::Delivering);
    expect($job->attempts)->toBe(1);

    $second = $this->get($url);
    $second->assertOk();
    expect($second->getContent())->toBe($first->getContent());

    // A flaky link that makes the printer re-fetch is not a second delivery
    // attempt. Counting it as one would spend a money document's single-attempt
    // budget on a network hiccup.
    expect($job->fresh()->attempts)->toBe(1);

    CarbonImmutable::setTestNow();
});

it('refuses a media type it cannot produce rather than printing garbage', function () {
    $printer = cloudPrntPrinter();
    $job = cloudPrntJob($printer);

    // Star Line Mode is a DIFFERENT command set. Handing StarPRNT bytes to a
    // client that asked for it would put ink on paper that is not the receipt.
    $this->get(cloudPrntUrl((string) $printer->print_token).'?type=application/vnd.star.line')
        ->assertNotFound();

    expect($job->fresh()->status)->toBe(PrintJobStatus::Queued);
});

it('fails an unrenderable job BEFORE answering, so the machine cannot loop on it', function () {
    $printer = cloudPrntPrinter();
    $job = cloudPrntJob($printer, ['payload' => ['template' => 'kitchen_ticket', 'version' => 1]]);

    $this->get(cloudPrntUrl((string) $printer->print_token))->assertNotFound();

    $job->refresh();
    expect($job->status)->toBe(PrintJobStatus::Failed);
    Assert::assertStringContainsString('schema', (string) $job->last_error);

    // The next poll must move on. A lazily-rendered job that stays queued is a
    // machine that fetches, 404s, polls, fetches, forever — silent from the
    // shop's side, where the printer is simply "not printing".
    $this->postJson(cloudPrntUrl((string) $printer->print_token), [])
        ->assertOk()
        ->assertJsonPath('jobReady', false);
});

it('refuses a kind Cloud has no emitter for instead of improvising a slip', function () {
    $printer = cloudPrntPrinter();

    // This used to be `kitchen`, the one kind with no PHP emitter. It stopped
    // being an example when the kitchen ticket and the hall slip were put on ONE
    // shared template (differing only by the QR) — every kind Cloud knows now
    // renders. The GUARD still has to hold, so the subject is now a kind the
    // registry genuinely does not know; testing it with a name that renders
    // would have quietly retired the assertion instead of the example.
    $unknownKind = 'not_a_print_kind';

    $payload = cloudPrntGoldenCase()['payload'];
    $payload[PrintRenderDataHydrator::DATA_KEY]['Kind'] = $unknownKind;

    $job = cloudPrntJob($printer, ['payload' => $payload, 'kind' => PrintJobKind::Kitchen->value]);

    $this->get(cloudPrntUrl((string) $printer->print_token))->assertNotFound();

    $job->refresh();
    expect($job->status)->toBe(PrintJobStatus::Failed);

    // The reason must NAME the kind. An operator reading a failed job needs to
    // know that the document type is unsupported, not that "printing failed".
    Assert::assertStringContainsString($unknownKind, (string) $job->last_error);

    /*
     * Where this refusal LIVES moved during T5.4, and the move was forced by
     * measurement. `CloudPrntJobRenderer` used to carry its own
     * `in_array($kind, $registry->kinds())` check; a mutation deleting it
     * changed nothing observable, twice, because `PrintRenderer::render()`
     * already refuses a kind with no plan. The duplicate was decoration, so it
     * is gone. This assertion is on the OUTCOME, which is what the shop
     * experiences and the only thing worth pinning.
     */
});

it('TR-14: a stored definition that cannot be read falls back to the shipped default and STILL prints', function () {
    $golden = cloudPrntGoldenCase();
    CarbonImmutable::setTestNow(CarbonImmutable::parse($golden['clock']));

    $printer = cloudPrntPrinter();
    cloudPrntJob($printer, ['payload' => $golden['payload']]);

    // Bit rot in the stored template — the case TESTS W5 names. Validation
    // happens at PUBLISH; at print time a shop that cannot print is a shop that
    // cannot trade, so the answer is the code-shipped default plus a loud log.
    $this->mock(TemplateResolver::class)
        ->shouldReceive('forBranch')
        ->andThrow(new RuntimeException('definition cache is unreadable'));

    Log::spy();

    $response = $this->get(cloudPrntUrl((string) $printer->print_token));
    $response->assertOk();

    // Not merely "something came out": the shipped default IS the workstation's
    // baseline (TR-40), so the fallback slip is byte-identical to the one this
    // order would have printed anyway.
    expect(hash('sha256', cloudPrntNormalisedToGolden((string) $response->getContent())))->toBe($golden['sha256']);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $event) => $event === 'cloudprnt_definition_unrenderable_falling_back_to_system_default')
        ->once();

    CarbonImmutable::setTestNow();
});

// ─── confirm (DELETE) ────────────────────────────────────────────────────────

it('records a successful confirm as printed', function () {
    $golden = cloudPrntGoldenCase();
    CarbonImmutable::setTestNow(CarbonImmutable::parse($golden['clock']));

    $printer = cloudPrntPrinter();
    $job = cloudPrntJob($printer, ['payload' => $golden['payload']]);
    $base = cloudPrntUrl((string) $printer->print_token);

    $this->get($base.'?token='.$job->id)->assertOk();
    $this->delete($base.'?token='.$job->id.'&code='.urlencode('200 OK'))->assertOk();

    $job->refresh();
    expect($job->status)->toBe(PrintJobStatus::Printed);
    expect($job->printed_reported_at)->not->toBeNull();
    expect($job->confidence)->toBe(PrintConfidence::Confirmed);

    CarbonImmutable::setTestNow();
});

it('P-02: a repeated confirm is a 200 no-op, not a second event', function () {
    $golden = cloudPrntGoldenCase();
    CarbonImmutable::setTestNow(CarbonImmutable::parse($golden['clock']));

    $printer = cloudPrntPrinter();
    $job = cloudPrntJob($printer, ['payload' => $golden['payload']]);
    $base = cloudPrntUrl((string) $printer->print_token);

    $this->get($base.'?token='.$job->id)->assertOk();
    $this->delete($base.'?token='.$job->id.'&code='.urlencode('200 OK'))->assertOk();

    $first = $job->fresh();

    // The printer must never be told "no" for saying the same true thing twice
    // — it would keep retrying, and every retry would land in the ledger.
    $this->delete($base.'?token='.$job->id.'&code='.urlencode('200 OK'))->assertOk();

    $second = $job->fresh();
    expect($second->status)->toBe(PrintJobStatus::Printed);
    expect($second->attempts)->toBe($first->attempts);
    expect($second->printed_reported_at?->toIso8601String())
        ->toBe($first->printed_reported_at?->toIso8601String());

    CarbonImmutable::setTestNow();
});

it('records a failure code as failed, with the code kept verbatim', function () {
    $golden = cloudPrntGoldenCase();
    CarbonImmutable::setTestNow(CarbonImmutable::parse($golden['clock']));

    $printer = cloudPrntPrinter();
    $job = cloudPrntJob($printer, ['payload' => $golden['payload']]);
    $base = cloudPrntUrl((string) $printer->print_token);

    $this->get($base.'?token='.$job->id)->assertOk();
    $this->delete($base.'?token='.$job->id.'&code='.urlencode('420 Cover open'))->assertOk();

    $job->refresh();
    expect($job->status)->toBe(PrintJobStatus::Failed);
    expect($job->last_error)->toBe('420 Cover open');
    expect($printer->fresh()->last_status)->toBe(UposPrinterStatus::CoverOpen);

    // A money document that failed is NOT re-offered: the retry decision is a
    // human's (PR1), and the next poll must say so.
    $this->postJson($base, [])->assertOk()->assertJsonPath('jobReady', false);

    CarbonImmutable::setTestNow();
});

it('P-33: a confirm never promotes a sent_only row to confirmed', function () {
    $golden = cloudPrntGoldenCase();
    CarbonImmutable::setTestNow(CarbonImmutable::parse($golden['clock']));

    $printer = cloudPrntPrinter();
    $job = cloudPrntJob($printer, [
        'payload' => $golden['payload'],
        'confidence' => PrintConfidence::SentOnly->value,
    ]);
    $base = cloudPrntUrl((string) $printer->print_token);

    $this->get($base.'?token='.$job->id)->assertOk();
    $this->delete($base.'?token='.$job->id.'&code='.urlencode('200 OK'))->assertOk();

    $job->refresh();

    // The print DID happen — status moves. But the confidence does not, because
    // `sent_only` is a recorded statement that this row could never be
    // confirmed, and P-33 makes that one-way with no escape hatch. (The model
    // throws on the attempt; the service logs instead, because the real defect
    // is upstream: whatever enqueued this job should have stamped the
    // confidence the machine earns.)
    expect($job->status)->toBe(PrintJobStatus::Printed);
    expect($job->confidence)->toBe(PrintConfidence::SentOnly);

    CarbonImmutable::setTestNow();
});
