<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

/**
 * #1952 — the JSON reference for print templates must stay true to the config.
 *
 * The document was written with the right intent: its own issue asked for
 * "số liệu TRÍCH TỪ config chứ không chép tay". What landed is accurate — 46
 * blocks, all six block types — but the numbers are TYPED INTO THE MARKDOWN.
 * They are right today by coincidence of being written today.
 *
 * That is the exact drift this repo keeps paying for, and always in the same
 * shape: a document that was true when written, stays confident, and is read as
 * current long after it stopped being so.
 *
 *   - the umbrella CLAUDE.md endpoint table claimed to be complete at 11 of 61
 *   - `PrintRenderData::$slip` documented `array` for weeks after #1923 made it
 *     a VO — and `prepareBillTax` followed the docblock, so no slip printed
 *   - `docs/guide/printing.md` pointed the renderer flag at "T5.4", a task that
 *     is about something else entirely (#1946, in this same review batch)
 *
 * A reader cannot tell a stale number from a fresh one. This test can.
 */
function printTemplateJsonDoc(): string
{
    $path = base_path('../docs/guide/print-template-json.md');
    if (! is_file($path)) {
        throw new RuntimeException("missing doc: {$path}");
    }

    return (string) file_get_contents($path);
}

/** @return array<string, mixed> */
function printBlockCatalog(): array
{
    $config = config('print_blocks');
    $blocks = $config['blocks'] ?? $config;

    if (! is_array($blocks) || $blocks === []) {
        throw new RuntimeException('print_blocks config is empty — the guard would pass vacuously');
    }

    return $blocks;
}

it('D1: the block COUNT in the doc matches the config', function () {
    $count = count(printBlockCatalog());
    $doc = printTemplateJsonDoc();

    // Asserted as a whole-word number so "46" does not accidentally match a
    // version string or a line reference elsewhere in the page.
    Assert::assertMatchesRegularExpression(
        '/\b'.$count.'\b/',
        $doc,
        "the doc no longer states the real block count ({$count}). "
        .'Adding a block to config/print_blocks.php means updating this page — '
        .'a template author reading a stale total will look for a block that is '
        .'documented and not there, or miss one that exists and is not.',
    );
});

it('D2: every block TYPE in the config appears in the doc', function () {
    $types = [];
    foreach (printBlockCatalog() as $block) {
        if (is_array($block) && isset($block['type']) && is_string($block['type'])) {
            $types[$block['type']] = true;
        }
    }

    expect(array_keys($types))->not->toBe([], 'no block declares a type — the guard would pass vacuously');

    $doc = printTemplateJsonDoc();

    foreach (array_keys($types) as $type) {
        // Backticked, because the doc is a reference: a type named in prose but
        // never shown as a value is not something an author can copy.
        Assert::assertStringContainsString(
            '`'.$type.'`',
            $doc,
            "block type `{$type}` exists in config but the JSON reference never shows it. ".
            'The page is the only place an author can learn what is valid; a type '.
            'missing from it is a type nobody will use.',
        );
    }
});

it('D3: the doc names no block type that does NOT exist', function () {
    // The converse, and the one a reader cannot check: a type invented in the
    // documentation reads exactly like a real one, and the author only finds
    // out when publish rejects their template with a validator error that does
    // not mention the doc.
    $real = [];
    foreach (printBlockCatalog() as $block) {
        if (is_array($block) && isset($block['type']) && is_string($block['type'])) {
            $real[] = $block['type'];
        }
    }
    $real = array_unique($real);

    $doc = printTemplateJsonDoc();

    // Only inspect the type table, not the whole page — the prose legitimately
    // mentions words like `text` in other senses.
    preg_match_all('/"type"\s*:\s*"([a-z_]+)"/', $doc, $m);

    foreach (array_unique($m[1] ?? []) as $named) {
        Assert::assertContains(
            $named,
            $real,
            "the doc shows `\"type\": \"{$named}\"` but no such block type exists in config",
        );
    }
});

it('D5: no STALE block total survives anywhere in the doc (#2547)', function () {
    // D1 only asks whether the real total appears SOMEWHERE. That passes while
    // the page still states an old total elsewhere — measured on #2547: after
    // fixing one of the two "47"s to "46", D1 went green with the other "47"
    // untouched. A reader who lands on the stale sentence is misled exactly as
    // much as if neither had been fixed.
    //
    // So: every "N block" / "N/M block" figure on the page must agree with the
    // config. Scoped to those two shapes on purpose — the page is full of
    // unrelated numbers (column widths, TR references, version strings) and a
    // blanket "no other integers" rule would fight the prose forever.
    $count = count(printBlockCatalog());
    $doc = printTemplateJsonDoc();

    preg_match_all('/(\d+)\s*\/\s*(\d+)\s+block/u', $doc, $ratios, PREG_SET_ORDER);
    foreach ($ratios as $m) {
        expect((int) $m[2])->toBe(
            $count,
            "\"{$m[0]}\" states a total of {$m[2]}, but the config has {$count}",
        );
    }

    preg_match_all('/cả\s+(\d+)\s+block/u', $doc, $totals, PREG_SET_ORDER);
    foreach ($totals as $m) {
        expect((int) $m[1])->toBe(
            $count,
            "\"{$m[0]}\" states a total of {$m[1]}, but the config has {$count}",
        );
    }

    // The guard must have found something to check — otherwise a rewrite that
    // drops both phrasings turns this into a test that passes on an empty page.
    expect(count($ratios) + count($totals))->toBeGreaterThan(0);
});

it('D6: the per-mutability counts in the doc match the config (#2547)', function () {
    // The bug #2547 actually shipped: removing `vat_disclaimer` (a `locked`
    // block) changed BOTH the total and the locked tally, and the tally had its
    // own number — `**`locked` (25)**` — that no assertion looked at.
    $tally = [];
    foreach (printBlockCatalog() as $block) {
        $mutability = is_array($block) ? ($block['mutability'] ?? null) : null;
        if (is_string($mutability)) {
            $tally[$mutability] = ($tally[$mutability] ?? 0) + 1;
        }
    }

    expect($tally)->not->toBeEmpty();

    $doc = printTemplateJsonDoc();

    foreach ($tally as $mutability => $expected) {
        if (preg_match('/`'.preg_quote($mutability, '/').'`\s*\((\d+)\)/u', $doc, $m) === 1) {
            expect((int) $m[1])->toBe(
                $expected,
                "the doc says `{$mutability}` has {$m[1]} blocks, the config has {$expected}",
            );
        }
    }
});
