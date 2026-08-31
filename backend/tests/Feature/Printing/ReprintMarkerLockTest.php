<?php

declare(strict_types=1);

/**
 * plan-052 P-10b [HARD] (#1166) — nobody can turn the reprint mark off.
 *
 * This is the load-bearing half of the 2026-07-28 ruling. The 422 that used to
 * refuse a reprint is gone; what actually stops a shop from holding two sheets
 * that both look like an original is the 「再印刷 #N」 mark on copy 2. A brand
 * that could disable, delete, reword or relocate that block would quietly undo
 * the entire control — so this file asserts the publish gate refuses all four,
 * for every money document.
 *
 * The mark's presence on PAPER is proved on the workstation side
 * (workstation/internal/service/print_reprint_marker_test.go, byte-level,
 * every kind × locale × paper width). This file proves the block survives the
 * one place a human could otherwise remove it: Cloud's template publish.
 */

use App\Exceptions\Print\TemplateValidationException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\SystemTemplateDefaults;
use App\Services\Print\TemplateValidator;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'invoice_registration_number' => null,
    ]);
    Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'timezone' => 'Asia/Tokyo',
    ]);

    $this->validator = app(TemplateValidator::class);
    $this->defaults = app(SystemTemplateDefaults::class);
});

/** The money documents — the kinds a second copy of is an accounting event. */
function moneyDocumentKinds(): array
{
    return [
        PrintTemplateKind::Receipt,
        PrintTemplateKind::RedInvoice,
        PrintTemplateKind::VatInvoice,
        PrintTemplateKind::DebtSlip,
    ];
}

/** @return list<string> the violation codes of a rejected publish */
function reprintLockCodes(callable $publish): array
{
    try {
        $publish();
    } catch (TemplateValidationException $e) {
        return $e->codes();
    }

    throw new RuntimeException('Expected the definition to be rejected, but it validated.');
}

it('ships the reprint_marker block on every money document, locked', function () {
    foreach (moneyDocumentKinds() as $kind) {
        $blocks = collect($this->defaults->forKind($kind)['blocks']);
        $marker = $blocks->firstWhere('id', 'reprint_marker');

        expect($marker)->not->toBeNull("kind {$kind->value} ships no reprint_marker block")
            ->and($marker['type'])->toBe('locked', "kind {$kind->value} reprint_marker is editable");
    }
});

it('refuses to publish a money document with the reprint mark switched off', function () {
    foreach (moneyDocumentKinds() as $kind) {
        $definition = $this->defaults->forKind($kind);
        foreach ($definition['blocks'] as $i => $block) {
            if (($block['id'] ?? null) === 'reprint_marker') {
                $definition['blocks'][$i]['enabled'] = false;
            }
        }

        $codes = reprintLockCodes(fn () => $this->validator->validateForPublish(
            $definition, $kind, PrintTemplateScope::Brand, $this->brand, null,
        ));

        expect($codes)->not->toBeEmpty("kind {$kind->value} allowed the mark to be disabled");
    }
});

it('refuses to publish a money document with the reprint mark deleted', function () {
    foreach (moneyDocumentKinds() as $kind) {
        $definition = $this->defaults->forKind($kind);
        $definition['blocks'] = array_values(array_filter(
            $definition['blocks'],
            fn (array $b): bool => ($b['id'] ?? null) !== 'reprint_marker',
        ));

        $codes = reprintLockCodes(fn () => $this->validator->validateForPublish(
            $definition, $kind, PrintTemplateScope::Brand, $this->brand, null,
        ));

        expect($codes)->not->toBeEmpty("kind {$kind->value} allowed the mark to be removed");
    }
});

it('refuses to publish a reprint mark whose wording a brand rewrote', function () {
    // "BAN IN #2" reworded to something reassuring is the same attack as
    // deleting it, and a locked block exists precisely so this is not a
    // judgement call at review time.
    foreach (moneyDocumentKinds() as $kind) {
        $definition = $this->defaults->forKind($kind);
        foreach ($definition['blocks'] as $i => $block) {
            if (($block['id'] ?? null) === 'reprint_marker') {
                $definition['blocks'][$i]['i18n'] = ['ja' => 'Cam on quy khach'];
            }
        }

        $codes = reprintLockCodes(fn () => $this->validator->validateForPublish(
            $definition, $kind, PrintTemplateScope::Brand, $this->brand, null,
        ));

        expect($codes)->toContain('LOCKED_BLOCK_MODIFIED');
    }
});

it('refuses to publish a reprint mark a brand reordered against the other locked blocks', function () {
    // TR-16 fixes the RELATIVE ORDER of locked blocks. Hoisting the mark above
    // `items` is rejected, which is what stops a brand from burying it inside
    // the body of the slip.
    //
    // KNOWN RESIDUAL GAP, deliberately not asserted here: pushing the mark to
    // the very END of the definition keeps its position among the LOCKED blocks
    // unchanged (it is already the last of them on every money kind), so the
    // publish gate accepts it even though the mark then prints after the footer.
    // Closing that needs an absolute-position rule in the block catalog
    // (`config/print_blocks.php`) rather than a relative one — a change to the
    // shared Cloud↔workstation catalog, tracked for #1181/M5 rather than
    // smuggled in here.
    foreach (moneyDocumentKinds() as $kind) {
        $definition = $this->defaults->forKind($kind);

        $blocks = collect($definition['blocks']);
        $marker = $blocks->firstWhere('id', 'reprint_marker');
        $rest = $blocks
            ->reject(fn (array $b): bool => ($b['id'] ?? null) === 'reprint_marker')
            ->values()
            ->all();

        $itemsIndex = (int) collect($rest)->search(fn (array $b): bool => ($b['id'] ?? null) === 'items');
        array_splice($rest, $itemsIndex, 0, [$marker]);
        $definition['blocks'] = $rest;

        $codes = reprintLockCodes(fn () => $this->validator->validateForPublish(
            $definition, $kind, PrintTemplateScope::Brand, $this->brand, null,
        ));

        expect($codes)->toContain('LOCKED_BLOCK_REORDERED');
    }
});

it('still accepts the untouched default — the lock must not make publishing impossible', function () {
    foreach (moneyDocumentKinds() as $kind) {
        $result = $this->validator->validateForPublish(
            $this->defaults->forKind($kind), $kind, PrintTemplateScope::Brand, $this->brand, null,
        );

        expect($result['blocks'])->not->toBeEmpty("kind {$kind->value} cannot publish its own default");
    }
});
