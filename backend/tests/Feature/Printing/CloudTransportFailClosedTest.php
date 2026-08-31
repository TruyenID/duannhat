<?php

declare(strict_types=1);

use App\Http\Requests\PrinterStoreRequest;
use App\Http\Requests\PrinterUpdateRequest;
use App\Models\Printer;
use App\Services\Printing\Enums\PrintTransport;
use PHPUnit\Framework\Assert;

/**
 * plan-052 P-39 / plan-053 T5.4 (#1171) — TRANSPORTS CLOUD CANNOT RENDER FOR
 * STAY SHUT.
 *
 * P-39 says a transport whose payload Cloud cannot build is "refused at the
 * config layer, fail-closed, with a clear message". This file is that rule's
 * enforcement.
 *
 * ## What T5.4 changed, and why the file had to change with it
 *
 * Before T5.4 the refusal existed only as an ACCIDENT of shape: the editable
 * `PrinterStoreRequest` / `PrinterUpdateRequest` overrode `rules()` without
 * mentioning `transport`, so the field never survived `validated()`. Real
 * protection, but one line from gone in two unremarkable ways —
 * `Printer::getFillable()` deliberately merges `transport` back in, and the
 * omnify-generated request base declares it `required|string`, so a `rules()`
 * that called `parent::rules()` would have accepted ANY string.
 *
 * T5.4 opened the field on purpose, for `cloudprnt` only, which DELETES that
 * accident. So the earlier version of this file — whose main assertion was
 * satisfied by `transport` simply being absent from the rules — would now pass
 * for the wrong reason on a rule that no longer exists to protect anything.
 * The claim is therefore restated as an effect: the rule must EXIST, must
 * accept exactly the renderable transports, and must name the refused ones.
 *
 * ## Why `cloudprnt` and not the other two
 *
 * T5.2b landed Go↔PHP byte parity for **ESC/POS** (117/117 golden cells), and
 * the encoder targets Star mC-Print in StarPRNT emulation — which is exactly
 * what a CloudPRNT client fetches as `application/vnd.star.starprnt`. So the
 * one transport that parity unlocks is the one whose bytes it measured.
 * `epos_http` carries ePOS XML and `webprnt` carries WebPRNT markup; NEITHER
 * repo has a renderer for either, so there is no baseline to compare a new one
 * against and writing one would crown itself the standard (TR-34). Parity being
 * green says nothing about them, and reading it as if it did is the inference
 * this file exists to keep out of the codebase.
 */
it('P-39a: only transports Cloud can render for are accepted by the printer write path', function () {
    $rules = [
        'store' => (new PrinterStoreRequest)->rules(),
        'update' => (new PrinterUpdateRequest)->rules(),
    ];

    foreach ($rules as $which => $set) {
        // The rule must EXIST. Its absence used to satisfy P-39 by accident;
        // now that `transport` is a real field, absence means the omnify base's
        // `required|string` is the only thing left, which accepts everything.
        // `assertArrayHasKey`, not `expect()->toHaveKey($key, $msg)`: Pest's
        // second argument to `toHaveKey` is the EXPECTED VALUE, so passing a
        // sentence there asserts `$set['transport'] === '<sentence>'` and fails
        // for a reason that has nothing to do with the rule. Caught here, on
        // this exact line, the first time this file ran.
        Assert::assertArrayHasKey(
            'transport',
            $set,
            "{$which}: `transport` must be validated explicitly — falling through to the generated base "
            .'accepts any string, including a transport Cloud cannot render for.'
        );

        // Flatten with (string), NOT json_encode. `Rule::in([...])` returns an
        // `Illuminate\Validation\Rules\In` object whose JSON form is `{}` — the
        // allowed values live only in its `__toString()`. The first version of
        // this test encoded to JSON and so never saw them: opening the rule to
        // `cloudprnt` left it green. An assertion that cannot observe the thing
        // it forbids is worse than no assertion, because it reports the axis as
        // covered.
        $text = implode(' ', array_map(
            fn ($rule) => is_object($rule) && method_exists($rule, '__toString')
                ? (string) $rule
                : (is_scalar($rule) ? (string) $rule : json_encode($rule)),
            (array) $set['transport'],
        ));

        foreach (PrintTransport::cases() as $transport) {
            if ($transport->isSelectable()) {
                // `assertStringContainsString`, not `expect()->toContain(…)`:
                // see the negative case below for why this API is avoided here.
                Assert::assertStringContainsString(
                    $transport->value,
                    $text,
                    "{$which}: `{$transport->value}` is renderable by Cloud and must be selectable."
                );

                continue;
            }

            // `assertStringNotContainsString`, not `expect()->not->toContain(…, $msg)`.
            // Pest's `toContain` is VARIADIC: a second argument is another
            // needle, not a message. Negated, it then asserts the text contains
            // neither the transport NOR the explanatory sentence — and since the
            // sentence is never there, the whole thing passes no matter what.
            // That is how an earlier version of this test survived a mutation
            // that opened the rule to `cloudprnt`.
            Assert::assertStringNotContainsString(
                $transport->value,
                $text,
                "{$which}: `{$transport->value}` needs Cloud to render its payload and Cloud cannot. "
                .'P-39 requires it refused at the config layer.'
            );
        }
    }
});

it('P-39a2: the refusal carries a message that names the reason, not just "invalid"', function () {
    $messages = (new PrinterStoreRequest)->messages();

    expect($messages)->toHaveKey('transport.in');

    $text = (string) $messages['transport.in'];

    foreach (PrintTransport::refusedValues() as $refused) {
        Assert::assertStringContainsString(
            $refused,
            $text,
            'P-39 asks for a CLEAR message. An operator told only "the selected transport is invalid" '
            .'goes to look at a printer that is working fine.'
        );
    }

    Assert::assertStringContainsString('renderer', $text);
});

it('P-39b: transport is mass-assignable, so validation is the only thing holding the line', function () {
    // Not a complaint — it is deliberate (`Printer::getFillable()` merges it in
    // for the workstation replica path). Pinned so that anyone reading P-39a
    // knows the second lock they might assume exists does not.
    expect((new Printer)->getFillable())->toContain('transport');
});

it('P-39c: the rule is expressed as an effect, and the classification stays intact', function () {
    $callers = [];
    $root = base_path('app');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $body = (string) file_get_contents($file->getPathname());
        if (str_contains($body, 'isSelectable(') && ! str_contains($file->getPathname(), 'Enums/PrintTransport.php')) {
            $callers[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    // Before T5.4 the equivalent check was reported but NOT asserted, because
    // zero callers was the honest state and the real protection lived
    // elsewhere. That is no longer true: `isSelectable()` is now the sole
    // source of the allow-list, so a build where nothing reads it is a build
    // where the gate has been quietly detached from the rule it states.
    expect($callers)->not->toBeEmpty(
        'nothing in app/ consults PrintTransport::isSelectable() — the config gate has been detached '
        .'from the rule it is supposed to enforce.'
    );

    // The classification itself: if `requiresCloudRenderer()` ever reports
    // ws_lan as needing a Cloud renderer (or a cloud transport as not needing
    // one), every consumer inverts.
    expect(PrintTransport::WsLan->requiresCloudRenderer())->toBeFalse();
    foreach ([PrintTransport::EposHttp, PrintTransport::WebPrnt, PrintTransport::CloudPrnt] as $t) {
        expect($t->requiresCloudRenderer())->toBeTrue();
    }

    // `cloudprnt` still REQUIRES Cloud to render — what changed in T5.4 is that
    // Cloud CAN. Keeping the two apart is the whole reason a second method
    // exists; collapsing them would make every reader of the first one flip.
    expect(PrintTransport::CloudPrnt->cloudCanRender())->toBeTrue();
    expect(PrintTransport::EposHttp->cloudCanRender())->toBeFalse();
    expect(PrintTransport::WebPrnt->cloudCanRender())->toBeFalse();

    expect(PrintTransport::selectableValues())->toBe(['ws_lan', 'cloudprnt']);
    expect(PrintTransport::refusedValues())->toBe(['epos_http', 'webprnt']);
});
