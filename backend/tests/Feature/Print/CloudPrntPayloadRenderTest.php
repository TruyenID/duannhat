<?php

declare(strict_types=1);

use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Renderer\PrintKindRegistry;
use App\Services\Print\Renderer\PrintRenderDataHydrator;
use App\Services\Print\Renderer\PrintRenderer;
use App\Services\Print\Renderer\PrintRenderProfile;
use App\Services\Print\SystemTemplateDefaults;
use App\Services\Printing\CloudPrntPayload;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Assert;

/**
 * plan-053 T5.4 (#1171) — THE PRODUCTION HYDRATOR IS GATED ON THE SAME BYTES.
 *
 * `SlipByteParityTest` proves the EMITTERS match Go, using a hydrator that
 * lives inside the test file. That leaves a gap the size of the whole serving
 * path: the class that actually turns a stored `print_jobs.payload` into
 * render data on the CloudPRNT route is a different class, and nothing said it
 * places the fields the same way. A hydrator that silently defaulted `total`
 * to 0 would print a receipt reading ¥0 and every parity test would stay
 * green.
 *
 * So this file re-runs the parity claim through
 * {@see PrintRenderDataHydrator} — the code the printer route really calls —
 * against the SAME two Go fixtures. Failure here means Cloud would print a
 * different slip than the workstation for the same order, which is the exact
 * thing T5.4 was gated on.
 *
 * It is not a copy of `SlipByteParityTest`. That file asks "do the emitters
 * agree"; this one asks "does the production payload path feed them the same
 * inputs". The second question is the one that has a caller.
 */
function cloudPrntGolden(string $file): array
{
    $path = base_path('../workstation/internal/service/testdata/'.$file);

    if (! is_file($path)) {
        throw new RuntimeException("missing parity fixture: {$path}");
    }

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

it('T5.4a: the production hydrator renders byte-identical slips for every registered golden cell', function () {
    $input = cloudPrntGolden('print_input_golden.json');
    $expected = cloudPrntGolden('print_golden.json');

    $registry = app(PrintKindRegistry::class);
    $defaults = app(SystemTemplateDefaults::class);
    $renderer = app(PrintRenderer::class);
    $hydrator = app(PrintRenderDataHydrator::class);

    CarbonImmutable::setTestNow(CarbonImmutable::parse((string) $input['clock']));

    $registered = $registry->kinds();
    $checked = 0;
    $mismatch = [];

    try {
        foreach ($input['cases'] as $key => $case) {
            [$kind, $locale, $paper] = explode('|', (string) $key);

            if (! in_array($kind, $registered, true)) {
                continue;
            }

            // Exactly the envelope the CloudPRNT route reads off a print job.
            $payload = [
                CloudPrntPayload::SCHEMA_KEY => CloudPrntPayload::SCHEMA,
                CloudPrntPayload::LOCALE_KEY => $locale,
                PrintRenderDataHydrator::DATA_KEY => $case,
                PrintRenderDataHydrator::TAX_KEY => $input['tax_summaries'][$key] ?? null,
            ];

            $envelope = CloudPrntPayload::fromArray($payload);

            $result = $renderer->render(
                $defaults->forKind(PrintTemplateKind::from($kind)),
                $hydrator->hydrate($envelope->data),
                new PrintRenderProfile(columns: (int) $paper),
                $envelope->locale,
                $hydrator->taxSummary($envelope->taxSummary),
            );

            $checked++;

            if (hash('sha256', $result->bytes()) !== $expected[$key]) {
                $mismatch[] = $kind;
            }
        }
    } finally {
        CarbonImmutable::setTestNow();
    }

    // A green run over zero cells is the failure shape this whole family of
    // tests was written after. Assert the count, not merely the absence of a
    // mismatch.
    expect($checked)->toBeGreaterThan(0, 'no cell was rendered — the registry and the fixture do not overlap');

    $diverging = array_values(array_unique($mismatch));
    sort($diverging);

    expect($diverging)->toBe([], 'the production payload path renders a different slip than the workstation');
});

it('T5.4b: a payload field this build cannot place is refused, not dropped', function () {
    $hydrator = app(PrintRenderDataHydrator::class);

    $valid = ['kind' => 'receipt', 'config' => ['storeName' => 'Kissa']];

    expect($hydrator->hydrate($valid)->order)->toBeNull();

    // `assertStringContainsString` on the message, not `expect()->toThrow(...)`
    // with a substring: the point is that the REASON names the offending field,
    // because the operator reading the failed job has no other way to know
    // which producer sent what.
    try {
        $hydrator->hydrate($valid + ['grandTotal' => 9999]);
        Assert::fail('an unknown payload field must be refused — dropping it prints a slip missing that fact');
    } catch (InvalidArgumentException $e) {
        Assert::assertStringContainsString('grandtotal', $e->getMessage());
    }
});

it('T5.4b2: the envelope refuses an unversioned, kindless or wrong-locale payload', function () {
    $data = ['kind' => 'receipt', 'config' => ['storeName' => 'Kissa']];

    $valid = [
        CloudPrntPayload::SCHEMA_KEY => CloudPrntPayload::SCHEMA,
        CloudPrntPayload::LOCALE_KEY => 'ja',
        PrintRenderDataHydrator::DATA_KEY => $data,
    ];

    expect(CloudPrntPayload::fromArray($valid)->locale)->toBe('ja');

    // label => [payload, a word the REASON must contain].
    //
    // The substring is not decoration. Every case below throws
    // `InvalidArgumentException`, so a test that only checked the class would
    // pass on a build where the checks collapsed into one another — measured:
    // dropping the `data` check entirely still threw, from the kind check one
    // line later, and the mutation survived. The reason text is what an
    // operator reads off a failed job, and it is the only thing that tells the
    // two apart.
    $broken = [
        // `print_jobs.payload` is a free JSON column that already carries a
        // DIFFERENT shape for ws_lan journal rows (the factory writes
        // `{"template": …, "version": 1}`). Without the version check, that
        // legacy shape would reach the hydrator and be diagnosed as a hundred
        // missing fields instead of "wrong envelope".
        'no schema' => [array_diff_key($valid, [CloudPrntPayload::SCHEMA_KEY => null]), 'schema'],
        'old schema' => [array_merge($valid, [CloudPrntPayload::SCHEMA_KEY => 'print_render_data/0']), 'schema'],
        'legacy ws_lan payload' => [['template' => 'kitchen_ticket', 'version' => 1], 'schema'],
        'empty' => [[], 'empty'],

        // `data` absent is its OWN reason. Without this check the failure still
        // happens, one line later, reported as "kind is absent" — which sends
        // whoever reads it looking for a kind in a payload that has no body at
        // all.
        'no data' => [array_diff_key($valid, [PrintRenderDataHydrator::DATA_KEY => null]), '`data`'],
        'empty data' => [array_merge($valid, [PrintRenderDataHydrator::DATA_KEY => []]), '`data`'],

        // The DOCUMENT kind, not `print_jobs.kind`. Two vocabularies, and
        // `receipt` is spelled the same in both — which is exactly why reading
        // one as the other would look correct until `vat_invoice`.
        'no kind' => [array_merge($valid, [PrintRenderDataHydrator::DATA_KEY => ['config' => []]]), 'kind'],
        'unknown kind' => [array_merge($valid, [PrintRenderDataHydrator::DATA_KEY => ['kind' => 'napkin']]), 'kind'],

        // Locale decides the LABELS on a legal document. Defaulting an
        // unrecognised one to ja would print a Japanese receipt for a Vietnamese
        // shop and look like a rendering quirk rather than a rejected input.
        'no locale' => [array_diff_key($valid, [CloudPrntPayload::LOCALE_KEY => null]), 'locale'],
        'unsupported locale' => [array_merge($valid, [CloudPrntPayload::LOCALE_KEY => 'de']), 'locale'],

        'tax_summary not an object' => [array_merge($valid, [PrintRenderDataHydrator::TAX_KEY => 'none']), 'tax_summary'],
    ];

    foreach ($broken as $label => [$payload, $needle]) {
        try {
            CloudPrntPayload::fromArray($payload);
            Assert::fail("{$label}: must be refused");
        } catch (InvalidArgumentException $e) {
            Assert::assertStringContainsString($needle, $e->getMessage(), $label);
        }
    }
});

it('T5.4c: an absent tax snapshot stays absent — it is never fabricated as zero', function () {
    $hydrator = app(PrintRenderDataHydrator::class);

    // The trap this repo has already paid for: a `ReceiptTaxSummary` rebuilt as
    // `taxable: 0, tax: 0` prints a tax line nobody computed, and nothing goes
    // red. `null` is a real state the emitters branch on (the aggregate 「税」
    // line) and is the only honest answer when the payload carries no snapshot.
    expect($hydrator->taxSummary(null))->toBeNull();

    // An EMPTY snapshot is a different fact from a missing one and must stay
    // distinguishable: it means "this slip was issued with no per-rate rows".
    $empty = $hydrator->taxSummary(['by_rate' => []]);
    expect($empty)->not->toBeNull();
    expect($empty->isEmpty())->toBeTrue();

    // A rate row missing one of its three numbers is refused rather than
    // zero-filled, for the same reason.
    expect(fn () => $hydrator->taxSummary(['by_rate' => [['rate' => 10, 'taxable' => 1000]]]))
        ->toThrow(InvalidArgumentException::class);

    $summary = $hydrator->taxSummary(['by_rate' => [
        ['rate' => 10.0, 'taxable' => 1000, 'tax' => 100],
        ['rate' => 8.0, 'taxable' => 500, 'tax' => 40],
    ]]);

    expect($summary->blocks)->toHaveCount(2);
    expect($summary->blocks[0]->rate)->toBe(8.0);
    expect($summary->hasReduced)->toBeTrue();
});
