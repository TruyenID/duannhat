<?php

declare(strict_types=1);

/**
 * plan-053 (#1171) — TESTS.md §1 (R1–R7): the three-layer resolve.
 *
 * The load-bearing assertions here are R3 (merge by FIELD, not wholesale) and
 * R7 (the version switch happens on the BRANCH's clock, #1091). Everything
 * else in the plan rests on those two behaving.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PrintTemplate;
use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\Enums\PrintTemplateStatus;
use App\Services\Print\SystemTemplateDefaults;
use App\Services\Print\TemplateResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'timezone' => 'Asia/Tokyo',
    ]);

    $this->resolver = fn () => app(TemplateResolver::class);
});

/** Find one block of a resolved definition by id. */
function blockOf(array $definition, string $id): ?array
{
    foreach ($definition['blocks'] as $block) {
        if (($block['id'] ?? null) === $id) {
            return $block;
        }
    }

    return null;
}

/** Publish a brand-layer row directly (the lifecycle itself is §2's job). */
function publishBrandTemplate(
    Brand $brand,
    array $definition,
    array $shopEditable = [],
    int $version = 1,
    ?string $effectiveFrom = null,
    string $kind = 'receipt',
): PrintTemplate {
    return PrintTemplate::factory()->create([
        'brand_id' => $brand->id,
        'branch_id' => null,
        'kind' => $kind,
        'scope' => PrintTemplateScope::Brand->value,
        'status' => PrintTemplateStatus::Published->value,
        'version' => $version,
        'definition' => $definition,
        'shop_editable' => $shopEditable,
        'effective_from' => $effectiveFrom,
        'published_at' => now(),
    ]);
}

function publishShopTemplate(
    Brand $brand,
    Branch $branch,
    array $definition,
    int $version = 1,
    ?string $effectiveFrom = null,
    string $kind = 'receipt',
): PrintTemplate {
    return PrintTemplate::factory()->create([
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'kind' => $kind,
        'scope' => PrintTemplateScope::Shop->value,
        'status' => PrintTemplateStatus::Published->value,
        'version' => $version,
        'definition' => $definition,
        'shop_editable' => null,
        'effective_from' => $effectiveFrom,
        'published_at' => now(),
    ]);
}

// ─── R1 ──────────────────────────────────────────────────────────────────
it('R1: falls back to the system default when the brand has published nothing (TR-01)', function () {
    $resolved = ($this->resolver)()->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id);

    expect($resolved->isSystemDefault())->toBeTrue()
        ->and($resolved->sourceScope)->toBe(PrintTemplateScope::System)
        ->and($resolved->version)->toBeNull()
        ->and($resolved->definition)->toBe(app(SystemTemplateDefaults::class)->forKind(PrintTemplateKind::Receipt))
        // A definition that can't be checksummed can't be cached (§5).
        ->and($resolved->checksum())->toHaveLength(64);
});

it('R1b: every one of the 13 kinds resolves to a printable system default', function () {
    $resolver = ($this->resolver)();

    foreach (PrintTemplateKind::cases() as $kind) {
        $resolved = $resolver->forBranch($kind, (string) $this->branch->id);

        expect($resolved->definition['blocks'])->not->toBeEmpty("kind {$kind->value} has no blocks");
        expect($resolved->definition['schema'])->toBe('tempo.print.v1');
    }
});

// ─── R2 ──────────────────────────────────────────────────────────────────
it('R2: uses the latest effective brand version when the shop does not override', function () {
    publishBrandTemplate($this->brand, [
        'schema' => 'tempo.print.v1',
        'blocks' => [['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'v1']]],
    ], version: 1);

    publishBrandTemplate($this->brand, [
        'schema' => 'tempo.print.v1',
        'blocks' => [['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'v2']]],
    ], version: 2);

    $resolved = ($this->resolver)()->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id);

    expect($resolved->sourceScope)->toBe(PrintTemplateScope::Brand)
        ->and($resolved->version)->toBe(2)
        ->and(blockOf($resolved->definition, 'footer_text')['i18n']['ja'])->toBe('v2');
});

// ─── R3 — the decisive one ───────────────────────────────────────────────
it('R3: merges by FIELD — a shop override survives a brand version that changes other fields (TR-02)', function () {
    // Brand v1 delegates three things; the shop overrides all three.
    publishBrandTemplate($this->brand, [
        'schema' => 'tempo.print.v1',
        'blocks' => [
            ['id' => 'logo', 'type' => 'image', 'enabled' => false, 'source' => 'brand_logo'],
            ['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'brand footer v1']],
            ['id' => 'greeting', 'type' => 'text', 'enabled' => false, 'i18n' => ['ja' => 'brand greeting v1']],
            ['id' => 'header_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'brand header v1']],
            ['id' => 'qr_block', 'type' => 'qr', 'enabled' => false, 'source' => 'order_url'],
        ],
    ], shopEditable: ['logo', 'footer_text', 'greeting'], version: 1);

    publishShopTemplate($this->brand, $this->branch, [
        'schema' => 'tempo.print.v1',
        'blocks' => [
            ['id' => 'logo', 'type' => 'image', 'enabled' => true, 'source' => 'branch_logo'],
            ['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'SHOP footer']],
            ['id' => 'greeting', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'SHOP greeting']],
        ],
    ]);

    // Brand v2 rewrites five OTHER fields and keeps the same delegation.
    publishBrandTemplate($this->brand, [
        'schema' => 'tempo.print.v1',
        'paper' => ['columns_58mm' => 32, 'columns_80mm' => 48],
        'blocks' => [
            ['id' => 'logo', 'type' => 'image', 'enabled' => false, 'source' => 'brand_logo'],
            ['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'brand footer v2']],
            ['id' => 'greeting', 'type' => 'text', 'enabled' => false, 'i18n' => ['ja' => 'brand greeting v2']],
            ['id' => 'header_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'brand header v2']],
            ['id' => 'qr_block', 'type' => 'qr', 'enabled' => true, 'source' => 'order_url'],
            ['id' => 'column_header', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'v2 columns']],
        ],
    ], shopEditable: ['logo', 'footer_text', 'greeting'], version: 2);

    $definition = ($this->resolver)()->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id)->definition;

    // The 3 delegated fields still carry the shop's values…
    expect(blockOf($definition, 'logo')['enabled'])->toBeTrue()
        ->and(blockOf($definition, 'logo')['source'])->toBe('branch_logo')
        ->and(blockOf($definition, 'footer_text')['i18n']['ja'])->toBe('SHOP footer')
        ->and(blockOf($definition, 'greeting')['i18n']['ja'])->toBe('SHOP greeting');

    // …and everything else followed the brand to v2.
    expect(blockOf($definition, 'header_text')['i18n']['ja'])->toBe('brand header v2')
        ->and(blockOf($definition, 'qr_block')['enabled'])->toBeTrue()
        ->and(blockOf($definition, 'column_header')['i18n']['ja'])->toBe('v2 columns');
});

it('R3b: reports which paths the shop overrides so admin can say "3 mục" (TR-02)', function () {
    publishBrandTemplate($this->brand, [
        'schema' => 'tempo.print.v1',
        'blocks' => [
            ['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'brand']],
            ['id' => 'greeting', 'type' => 'text', 'enabled' => false, 'i18n' => ['ja' => 'brand']],
        ],
    ], shopEditable: ['footer_text', 'greeting']);

    publishShopTemplate($this->brand, $this->branch, [
        'schema' => 'tempo.print.v1',
        'blocks' => [
            ['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'shop']],
            ['id' => 'greeting', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'shop']],
        ],
    ]);

    $resolved = ($this->resolver)()->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id);

    expect($resolved->shopOverriddenPaths)
        ->toContain('footer_text.i18n')
        ->toContain('greeting.i18n')
        ->toContain('greeting.enabled');
});

// ─── R4 ──────────────────────────────────────────────────────────────────
it('R4: narrowing shop_editable silences the override without destroying it, and widening revives it (TR-04)', function () {
    $shopRow = publishShopTemplate($this->brand, $this->branch, [
        'schema' => 'tempo.print.v1',
        'blocks' => [
            ['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'SHOP footer']],
            ['id' => 'greeting', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'SHOP greeting']],
        ],
    ]);

    $brandDefinition = [
        'schema' => 'tempo.print.v1',
        'blocks' => [
            ['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'BRAND footer']],
            ['id' => 'greeting', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'BRAND greeting']],
        ],
    ];

    $brandRow = publishBrandTemplate($this->brand, $brandDefinition, ['footer_text', 'greeting']);

    $definition = app(TemplateResolver::class)->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id)->definition;
    expect(blockOf($definition, 'footer_text')['i18n']['ja'])->toBe('SHOP footer')
        ->and(blockOf($definition, 'greeting')['i18n']['ja'])->toBe('SHOP greeting');

    // Brand drops `footer_text` from the allow-list.
    $brandRow->update(['shop_editable' => ['greeting']]);

    $definition = app(TemplateResolver::class)->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id)->definition;
    expect(blockOf($definition, 'footer_text')['i18n']['ja'])->toBe('BRAND footer')
        ->and(blockOf($definition, 'greeting')['i18n']['ja'])->toBe('SHOP greeting');

    // The shop's stored row is UNTOUCHED — the override was only filtered.
    expect($shopRow->fresh()->definition)->toBe($shopRow->definition);

    // Widen the list again: the override comes back to life.
    $brandRow->update(['shop_editable' => ['footer_text', 'greeting']]);

    $definition = app(TemplateResolver::class)->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id)->definition;
    expect(blockOf($definition, 'footer_text')['i18n']['ja'])->toBe('SHOP footer');
});

it('R4b: an empty shop_editable locks the slip completely', function () {
    publishBrandTemplate($this->brand, [
        'schema' => 'tempo.print.v1',
        'blocks' => [['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'BRAND']]],
    ], shopEditable: []);

    publishShopTemplate($this->brand, $this->branch, [
        'schema' => 'tempo.print.v1',
        'blocks' => [['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'SHOP']]],
    ]);

    $resolved = ($this->resolver)()->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id);

    expect(blockOf($resolved->definition, 'footer_text')['i18n']['ja'])->toBe('BRAND')
        ->and($resolved->sourceScope)->toBe(PrintTemplateScope::Brand);
});

// ─── R5 ──────────────────────────────────────────────────────────────────
it('R5: picks the newest version whose effective_from has already arrived (TR-12)', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-28 12:00:00', 'Asia/Tokyo'));

    publishBrandTemplate($this->brand, [
        'schema' => 'tempo.print.v1',
        'blocks' => [['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'now in force']]],
    ], version: 1, effectiveFrom: '2026-07-01 00:00:00');

    publishBrandTemplate($this->brand, [
        'schema' => 'tempo.print.v1',
        'blocks' => [['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'the future']]],
    ], version: 2, effectiveFrom: '2026-08-01 00:00:00');

    $resolved = app(TemplateResolver::class)->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id);

    expect($resolved->version)->toBe(1)
        ->and(blockOf($resolved->definition, 'footer_text')['i18n']['ja'])->toBe('now in force');

    // Cross the boundary: the scheduled version takes over on its own.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01 00:00:01', 'Asia/Tokyo'));

    $resolved = app(TemplateResolver::class)->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id);
    expect($resolved->version)->toBe(2);

    CarbonImmutable::setTestNow();
});

it('R5b: a null effective_from is in force from publication (DESIGN §4)', function () {
    publishBrandTemplate($this->brand, [
        'schema' => 'tempo.print.v1',
        'blocks' => [['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'immediate']]],
    ], effectiveFrom: null);

    expect(app(TemplateResolver::class)->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id)->version)
        ->toBe(1);
});

it('R5c: retired versions leave service for new prints but stay renderable (TR-13)', function () {
    publishBrandTemplate($this->brand, [
        'schema' => 'tempo.print.v1',
        'blocks' => [['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'v1']]],
    ], version: 1);

    $v2 = publishBrandTemplate($this->brand, [
        'schema' => 'tempo.print.v1',
        'blocks' => [['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'v2']]],
    ], version: 2);

    $v2->update(['status' => PrintTemplateStatus::Retired->value]);

    $resolved = app(TemplateResolver::class)->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id);
    expect($resolved->version)->toBe(1);

    // …and a reprint that names v2 still gets v2.
    $historical = app(TemplateResolver::class)->forVersion(PrintTemplateKind::Receipt, (string) $this->branch->id, 2);
    expect($historical?->version)->toBe(2)
        ->and(blockOf($historical->definition, 'footer_text')['i18n']['ja'])->toBe('v2');
});

// ─── R6 ──────────────────────────────────────────────────────────────────
it('R6: a branch moved to another brand resolves against its NEW brand (TR-07)', function () {
    $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);

    publishBrandTemplate($this->brand, [
        'schema' => 'tempo.print.v1',
        'blocks' => [['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'OLD BRAND']]],
    ]);
    publishBrandTemplate($otherBrand, [
        'schema' => 'tempo.print.v1',
        'blocks' => [['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'NEW BRAND']]],
    ]);

    expect(blockOf(app(TemplateResolver::class)->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id)->definition, 'footer_text')['i18n']['ja'])
        ->toBe('OLD BRAND');

    $this->branch->update(['console_brand_id' => $otherBrand->console_brand_id]);

    expect(blockOf(app(TemplateResolver::class)->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id)->definition, 'footer_text')['i18n']['ja'])
        ->toBe('NEW BRAND');
});

// ─── R7 — #1091 ──────────────────────────────────────────────────────────
it('R7: the switch happens on the BRANCH clock, not the server clock (#1091)', function () {
    // A single frozen instant. At that instant it is already 2026-08-01 in
    // Tokyo (UTC+9), still 2026-07-31 in Hanoi (UTC+7) and in UTC. A version
    // scheduled for "2026-08-01 00:00" must therefore be live in Tokyo and
    // NOT YET live in Hanoi or at a UTC branch — the whole point of business
    // time. Resolving on `now()` would flip all three together.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-31T15:30:00Z'));

    $branches = [];
    foreach (['Asia/Tokyo' => true, 'Asia/Ho_Chi_Minh' => false, 'UTC' => false] as $timezone => $expectSwitched) {
        $branches[$timezone] = [
            'branch' => Branch::factory()->create([
                'console_organization_id' => $this->orgId,
                'console_brand_id' => $this->brand->console_brand_id,
                'timezone' => $timezone,
            ]),
            'expect' => $expectSwitched,
        ];
    }

    publishBrandTemplate($this->brand, [
        'schema' => 'tempo.print.v1',
        'blocks' => [['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'july']]],
    ], version: 1, effectiveFrom: '2026-07-01 00:00:00');

    publishBrandTemplate($this->brand, [
        'schema' => 'tempo.print.v1',
        'blocks' => [['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'august']]],
    ], version: 2, effectiveFrom: '2026-08-01 00:00:00');

    foreach ($branches as $timezone => $case) {
        $resolved = app(TemplateResolver::class)->forBranch(PrintTemplateKind::Receipt, (string) $case['branch']->id);

        expect($resolved->version)->toBe(
            $case['expect'] ? 2 : 1,
            "branch in {$timezone} resolved v{$resolved->version}",
        );
    }

    CarbonImmutable::setTestNow();
});

it('R7b: an effective_from in the past is simply in force now (TR-11)', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-28 09:00:00', 'Asia/Tokyo'));

    publishBrandTemplate($this->brand, [
        'schema' => 'tempo.print.v1',
        'blocks' => [['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'backdated']]],
    ], effectiveFrom: '2020-01-01 00:00:00');

    expect(app(TemplateResolver::class)->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id)->version)
        ->toBe(1);

    CarbonImmutable::setTestNow();
});
