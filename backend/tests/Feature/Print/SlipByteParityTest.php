<?php

declare(strict_types=1);

use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Renderer\PrintKindRegistry;
use App\Services\Print\Renderer\PrintRenderData;
use App\Services\Print\Renderer\PrintRenderer;
use App\Services\Print\Renderer\PrintRenderProfile;
use App\Services\Print\Renderer\ReceiptTaxSummary;
use App\Services\Print\SystemTemplateDefaults;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Assert;

/**
 * plan-053 T5.2b (#1171) — SLIP-LEVEL BYTE PARITY, Go ↔ PHP.
 *
 * The claim under test is the one the whole plan turns on: given the same
 * render data and the same template definition, the PHP emitters produce the
 * SAME ESC/POS bytes as the Go ones. Until that holds, cloud transport stays
 * fail-closed (T5.4) — Cloud may not print for a shop while its output is
 * merely *believed* to match the workstation's.
 *
 * ## Why this file did not exist before, and why the gap was invisible
 *
 * Both fixtures it needs have been committed for a while, and the plan says in
 * two places that they gate slip parity. Measured on 2026-08-06:
 *
 *     kinds in print_input_golden.json : [kitchen]
 *     kinds PHP can render             : the other 13
 *     intersection                     : EMPTY
 *
 * The input fixture was scoped by a hand-curated Go list whose stated rule was
 * "a kind belongs here only once its PHP emitter is gated on it". Applied
 * honestly, it produced the exact inverse: the only kind it carried was the one
 * kind PHP has no emitter for. Nothing was red, because no PHP test read the
 * file at all — Go wrote a fixture, and the reader it was written for was never
 * built.
 *
 * That is the failure this file is shaped against. Two structural consequences:
 *
 *  1. The Go side now exports EVERY kind in `goldenMatrix()`. There is no list
 *     to widen, so there is no list to forget.
 *  2. The gap lives here, in `NOT_YET`, and it CLOSES ITSELF: the last test in
 *     this file fails if a kind on that list turns out to be registered. Landing
 *     an emitter therefore breaks the build until its parity is switched on.
 *
 * ## What parity means here, precisely
 *
 * Not "PHP computes the same totals" — PHP must never compute money in the print
 * layer (#1092, #1937). Go's renderer builds its per-rate tax summary internally
 * (`buildReceiptTaxSummary`), so that snapshot is exported alongside the inputs
 * and handed to PHP. Parity is therefore: **the same tax facts emit the same
 * bytes**, which is a statement about the emitters and nothing else.
 *
 * The alternative — have PHP rebuild the summary so the test "looks" more
 * end-to-end — is the trap this repo has already paid for: a summary rebuilt
 * with `taxable: 0, tax: 0` prints fabricated tax lines, and no test went red.
 *
 * Regenerate the inputs after touching the Go renderer's data shape:
 *
 *     go test ./internal/service/ -run TestExportPrintInputGolden \
 *         -update-print-input-golden
 */

/**
 * Kinds with no PHP emitter yet. Guarded below — this list can only shrink.
 *
 * `kitchen` left it when the kitchen ticket and the hall/runner slip were put on
 * ONE shared template (differing only by the QR): the PHP bill emitters render
 * it as-is, so there was nothing left to port.
 */
const SLIP_PARITY_NOT_YET = [];

/**
 * Kinds whose emitters exist and DO NOT yet match Go byte-for-byte.
 *
 * First measurement ever taken of slip parity in this repo (2026-08-06) put 63
 * of 117 cells already matching, with the 54 misses landing on exactly six kinds
 * × 9 cells (3 locales × 3 paper widths). One root cause — the fixture recording
 * Go's zero time for `Now` — accounted for a whole kind on its own, and fixing it
 * at the source took `debt_slip` to byte-identical, leaving 72. The bill family's
 * remaining 45 cells closed the same day on three defects: a missing `ESC GS a 0`
 * in the prologue (all 45), a `qr_block` presence test that ignored `enabled`
 * (18), and an unwired `reprint_marker` emitter (18). **117 of 117 now match.**
 *
 * Neither deletion was voluntary. This test went red the moment `debt_slip`
 * started matching and named the line to remove — the two-way enforcement below
 * working on a real change rather than in a mutation drill — and it did so again
 * for all five bill kinds at once.
 *
 * The list stayed at kind granularity to the end: no kind was ever partially
 * diverging, because each defect lived in the plan shared by the whole family.
 *
 * It is enforced in BOTH directions on purpose. A kind outside it that starts
 * diverging goes red (no new drift), and a kind inside it that starts matching
 * ALSO goes red (the entry must be deleted). A one-directional allow-list is
 * how a temporary exemption becomes permanent: it keeps passing long after the
 * thing it excused is fixed, and nobody learns the debt is gone.
 */
const SLIP_PARITY_KNOWN_DIVERGENCE = [
    // EMPTY, and that is the whole point of the file. 117 of 117 registered
    // cells are byte-identical to Go as of 2026-08-06 (T5.2b, #1171).
    //
    // The note that used to sit here warned that `tax_summaries` was an UNGATED
    // input: every kind reading a tax summary was a bill kind, every bill kind
    // was listed here, so passing `null` instead of the snapshot could not
    // change the diverging set and the test stayed green. That is no longer
    // true — with the list empty the mutation goes red, which was the stated
    // condition for the input becoming gated. Verified by mutation, not assumed.
    //
    // Adding a name back is not a way to land a diverging emitter. It is a
    // statement that a slip Cloud prints differs from the one the workstation
    // prints for the same order, which is what T5.4 keeps transport fail-closed
    // over. Measure first (dump both sides, diff the bytes); the three defects
    // cleared here were each a missing byte sequence with no visible symptom on
    // paper, so "it looks right when printed" is not evidence.
];

function slipParityInputPath(): string
{
    return base_path('../workstation/internal/service/testdata/print_input_golden.json');
}

function slipParityExpectedPath(): string
{
    return base_path('../workstation/internal/service/testdata/print_golden.json');
}

/** @return array<string, mixed> */
function slipParityJson(string $path): array
{
    if (! is_file($path)) {
        throw new RuntimeException("missing parity fixture: {$path}");
    }

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Normalise a field name so the two repos' casings collapse onto one key.
 *
 * Go emits BOTH conventions in this one file, which is not sloppiness: the outer
 * `PrintRenderData` fields have no json tags so they marshal PascalCase
 * (`Kind`, `Items`), while the nested values are domain types carrying explicit
 * snake_case tags (`menu_item_name`). PHP promotes camelCase throughout. Casing
 * and underscores are therefore noise on every side; strip both and compare what
 * is left.
 */
function slipParityKey(string $name): string
{
    return str_replace('_', '', strtolower($name));
}

/**
 * Hydrate a Go-serialised value into a PHP constructor-promoted VO.
 *
 * Matching is case-insensitive on the parameter name rather than a hand-written
 * key map: a map has to be edited every time either side gains a field, and the
 * failure mode of forgetting is a silently-defaulted value — a zero total that
 * prints as a real zero.
 *
 * @param  array<string, mixed>  $row
 */
function slipParityHydrate(string $class, array $row): object
{
    $ctor = (new ReflectionClass($class))->getConstructor();
    if ($ctor === null) {
        throw new RuntimeException("{$class} has no constructor to hydrate");
    }

    $lower = [];
    foreach ($row as $key => $value) {
        $lower[slipParityKey((string) $key)] = $value;
    }

    $args = [];
    foreach ($ctor->getParameters() as $param) {
        $key = slipParityKey($param->getName());
        if (! array_key_exists($key, $lower)) {
            // Absent on the Go side: take the PHP default. A required parameter
            // with no counterpart is a genuine shape mismatch, so let it throw
            // rather than invent a value.
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();

                continue;
            }
            throw new RuntimeException("{$class}::\${$param->getName()} has no value in the fixture");
        }

        $args[] = slipParityCoerce($param, $lower[$key], slipParityElementTypes($ctor));
    }

    return new $class(...$args);
}

/**
 * Element class per array-typed constructor parameter, read from the docblock.
 *
 * PHP cannot type `array of Foo`, so the element type only exists in
 * `@param list<Foo> $name` — which this family of VOs writes consistently.
 * Parsing it keeps the hydrator following the source: a VO that gains a nested
 * collection is handled the moment it is declared. The obvious alternative, a
 * hand-maintained property→class map, fails silently in the one direction that
 * matters — a forgotten entry leaves raw arrays where VOs are expected, and the
 * emitter then reads properties off an array.
 *
 * @return array<string, class-string>
 */
function slipParityElementTypes(ReflectionMethod $ctor): array
{
    $doc = $ctor->getDocComment();
    if ($doc === false) {
        return [];
    }

    preg_match_all('/@param\s+(?:list|array)<\s*([A-Za-z0-9_\\\\]+)\s*>\s+\$(\w+)/', $doc, $m, PREG_SET_ORDER);

    $out = [];
    foreach ($m as $hit) {
        $short = $hit[1];
        $fqcn = str_contains($short, '\\') ? $short : 'App\\Services\\Print\\Renderer\\'.$short;
        if (class_exists($fqcn)) {
            $out[$hit[2]] = $fqcn;
        }
    }

    return $out;
}

/** @param array<string, class-string> $elementTypes */
function slipParityCoerce(ReflectionParameter $param, mixed $value, array $elementTypes = []): mixed
{
    $type = $param->getType();
    $name = $type instanceof ReflectionNamedType ? $type->getName() : '';

    if ($value === null) {
        return $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
    }

    if ($name === CarbonImmutable::class) {
        return CarbonImmutable::parse((string) $value);
    }

    if ($name === 'array' && isset($elementTypes[$param->getName()]) && is_array($value)) {
        $element = $elementTypes[$param->getName()];

        return array_map(fn (array $row) => slipParityHydrate($element, $row), $value);
    }

    if (class_exists($name) && is_array($value)) {
        return slipParityHydrate($name, $value);
    }

    return match ($name) {
        'int' => (int) $value,
        'float' => (float) $value,
        'bool' => (bool) $value,
        'string' => (string) $value,
        default => $value,
    };
}

/** Rebuild the ReceiptTaxSummary Go computed, in the shape PHP's factory takes. */
function slipParityTaxSummary(?array $snapshot): ?ReceiptTaxSummary
{
    if ($snapshot === null) {
        return null;
    }

    $blocks = $snapshot['Blocks'] ?? [];
    if ($blocks === [] || $blocks === null) {
        // No per-rate blocks is a real state (a single-rate order), and it is NOT
        // the same as "no snapshot": the emitters branch on it.
        $blocks = [];
    }

    return ReceiptTaxSummary::fromBreakdown([
        'by_rate' => array_map(fn (array $b) => [
            'rate' => (float) $b['Rate'],
            'taxable' => (int) $b['Taxable'],
            'tax' => (int) $b['Tax'],
        ], $blocks),
    ]);
}

/** @return list<array{key: string, kind: string, locale: string, paper: int}> */
function slipParityCases(): array
{
    $input = slipParityJson(slipParityInputPath());
    $out = [];
    foreach (array_keys($input['cases']) as $key) {
        [$kindSegment, $locale, $paper] = explode('|', (string) $key);

        // `kind~variant` — a VARIANT is a non-default ORDER rendered through the
        // same kind and template (today: `takeaway`). The kind segment stays a
        // real kind on purpose: this harness resolves a template from it, and a
        // compound name would resolve to nothing and skip the cell in silence.
        //
        // The variant dimension exists because both parity gates once passed
        // while Cloud printed NO payment row at all on takeaway slips:
        // `PrintLabelsParityTest` compares label strings (it cannot see that no
        // emitter calls them) and every cell here rendered the dine-in fixture,
        // so the row never appeared in a gated byte.
        [$kind] = explode('~', $kindSegment, 2);

        $out[] = ['key' => (string) $key, 'kind' => $kind, 'locale' => $locale, 'paper' => (int) $paper];
    }
    sort($out);

    return $out;
}

it('G2: the shared input fixture and the expected-output fixture cover the same cells', function () {
    $input = slipParityJson(slipParityInputPath());
    $expected = slipParityJson(slipParityExpectedPath());

    // The two fixtures are generated by two different Go tests. If they ever
    // cover different cells, every downstream assertion silently skips the
    // difference — which is precisely how this harness sat inert.
    expect(array_keys($input['cases']))->toEqualCanonicalizing(array_keys($expected));
    expect($input['cases'])->toHaveCount(153);
    expect($input)->toHaveKey('tax_summaries');
    expect(array_keys($input['tax_summaries']))->toEqualCanonicalizing(array_keys($input['cases']));
});

it('G3: PHP emits byte-identical ESC/POS to Go for every registered kind', function () {
    $input = slipParityJson(slipParityInputPath());
    $expected = slipParityJson(slipParityExpectedPath());
    $registry = app(PrintKindRegistry::class);
    $defaults = app(SystemTemplateDefaults::class);
    $renderer = app(PrintRenderer::class);

    CarbonImmutable::setTestNow(CarbonImmutable::parse((string) $input['clock']));

    $registered = $registry->kinds();
    $checked = 0;
    $mismatch = [];

    foreach (slipParityCases() as $case) {
        if (! in_array($case['kind'], $registered, true)) {
            continue;
        }

        $data = slipParityHydrate(PrintRenderData::class, $input['cases'][$case['key']]);
        $result = $renderer->render(
            $defaults->forKind(PrintTemplateKind::from($case['kind'])),
            $data,
            new PrintRenderProfile(columns: $case['paper']),
            $case['locale'],
            slipParityTaxSummary($input['tax_summaries'][$case['key']] ?? null),
        );

        $got = hash('sha256', $result->bytes());
        $checked++;
        if ($got !== $expected[$case['key']]) {
            $mismatch[] = $case['kind'];
        }
    }

    CarbonImmutable::setTestNow();

    // A green run with zero cells checked is the exact shape of the failure this
    // file was written after. Assert the count, not just the absence of errors.
    expect($checked)->toBeGreaterThan(0, 'no cell was compared — the registry and the fixture do not overlap');

    $diverging = array_values(array_unique($mismatch));
    sort($diverging);

    expect($diverging)->toBe(
        SLIP_PARITY_KNOWN_DIVERGENCE,
        'slip parity moved. A kind that newly diverges is a regression; a kind that '
        .'newly matches must be deleted from SLIP_PARITY_KNOWN_DIVERGENCE so it stays gated.',
    );
});

it('G5: every kind excluded from parity is genuinely unimplemented', function () {
    $registered = app(PrintKindRegistry::class)->kinds();

    foreach (SLIP_PARITY_NOT_YET as $kind) {
        // `assertNotContains`, not `expect()->not->toContain($kind, $message)`.
        // Pest's `toContain` is VARIADIC — the second argument is another needle,
        // not a message. Negated, it asserts the haystack holds NEITHER the kind
        // NOR the sentence, and the sentence is never in a list of kind slugs, so
        // it passed unconditionally. This assertion had never executed.
        //
        // Measured rather than reasoned: with SLIP_PARITY_NOT_YET set to
        // ['kitchen', 'receipt'] — where `receipt` IS registered and so must be
        // caught — the whole test stayed green, because the `$uncovered` check
        // below is blind to that case by construction. The self-closing property
        // this file advertises ("landing an emitter breaks the build until its
        // parity is switched on") was therefore not true for NOT_YET at all.
        //
        // Third instance of this exact API producing a never-run assertion in
        // this repo, which is why the fix is to stop using it for negation here.
        Assert::assertNotContains(
            $kind,
            $registered,
            "`{$kind}` now has a PHP emitter — remove it from SLIP_PARITY_NOT_YET so its bytes are gated",
        );
    }

    // And the converse: nothing may quietly fall outside both lists.
    $fixtureKinds = array_unique(array_column(slipParityCases(), 'kind'));
    $uncovered = array_diff($fixtureKinds, $registered, SLIP_PARITY_NOT_YET);
    expect(array_values($uncovered))->toBe([]);
});
