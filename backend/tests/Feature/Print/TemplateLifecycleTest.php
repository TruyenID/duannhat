<?php

declare(strict_types=1);

/**
 * plan-053 (#1171) — TESTS.md §2 (L1–L6): the version lifecycle.
 *
 * The theme running through all six: a published version is a FACT. It is
 * never edited (L1), never silently overwritten by a concurrent editor (L2/L3),
 * and never un-published — going back is itself a new version (L6). That is
 * what makes a reprint years later able to reproduce the original slip.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PrintTemplate;
use App\Models\User;
use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\Enums\PrintTemplateStatus;
use App\Services\Print\SystemTemplateDefaults;
use App\Services\Print\TemplateResolver;
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

    $this->admin = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->admin, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/print-templates/receipt";
    $this->definition = fn (string $footer = 'v1') => footerDefinition($footer);
});

/** The system default with a distinguishable footer, so versions are tellable apart. */
function footerDefinition(string $text): array
{
    $definition = app(SystemTemplateDefaults::class)->forKind(PrintTemplateKind::Receipt);

    foreach ($definition['blocks'] as $i => $block) {
        if ($block['id'] === 'footer_text') {
            $definition['blocks'][$i] = array_replace($block, [
                'enabled' => true,
                'fallback' => true,
                'i18n' => ['ja' => $text],
            ]);
        }
    }

    return $definition;
}

function footerOf(array $definition): ?string
{
    foreach ($definition['blocks'] as $block) {
        if (($block['id'] ?? null) === 'footer_text') {
            return $block['i18n']['ja'] ?? null;
        }
    }

    return null;
}

/** Draft + publish in one step, returning the published row. */
function draftAndPublish(object $test, string $base, array $definition, array $publishPayload = []): PrintTemplate
{
    $draft = $test->actingAs($test->admin)
        ->postJson("{$base}/draft", ['definition' => $definition])
        ->assertOk()
        ->json('data');

    $published = $test->actingAs($test->admin)
        ->postJson("{$base}/publish", $publishPayload + ['parent_version_id' => $draft['parent_version_id']])
        ->assertCreated()
        ->json('data');

    return PrintTemplate::findOrFail($published['id']);
}

// ─── L1 (TR-08) ──────────────────────────────────────────────────────────
it('L1: PATCH on a published version is a 409 and changes nothing (TR-08)', function () {
    $published = draftAndPublish($this, $this->base, ($this->definition)('original'));

    $this->actingAs($this->admin)
        ->patchJson("{$this->base}/versions/{$published->id}", [
            'definition' => ($this->definition)('hacked'),
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'PRINT_TEMPLATE_IMMUTABLE');

    expect(footerOf($published->fresh()->definition))->toBe('original');
});

it('L1b: a RETIRED version is equally immutable', function () {
    $published = draftAndPublish($this, $this->base, ($this->definition)('original'));

    $this->actingAs($this->admin)
        ->postJson("{$this->base}/versions/{$published->id}/retire")
        ->assertOk()
        ->assertJsonPath('data.status', 'retired');

    $this->actingAs($this->admin)
        ->patchJson("{$this->base}/versions/{$published->id}", [
            'definition' => ($this->definition)('hacked'),
        ])
        ->assertStatus(409);
});

// ─── L2 (TR-09) ──────────────────────────────────────────────────────────
it('L2: the second of two concurrent draft editors gets 409, with no auto-merge (TR-09)', function () {
    $first = $this->actingAs($this->admin)
        ->postJson("{$this->base}/draft", ['definition' => ($this->definition)('draft A')])
        ->assertOk()
        ->json('data');

    $staleToken = $first['lock_token'];

    // Editor 1 saves.
    $this->actingAs($this->admin)
        ->postJson("{$this->base}/draft", [
            'definition' => ($this->definition)('editor one'),
            'lock_token' => $staleToken,
        ])
        ->assertOk();

    // Editor 2 was holding the OLD timestamp.
    $this->actingAs($this->admin)
        ->postJson("{$this->base}/draft", [
            'definition' => ($this->definition)('editor two'),
            'lock_token' => $staleToken,
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'PRINT_TEMPLATE_DRAFT_STALE');

    // Editor 1's content stands — nothing was merged behind anyone's back.
    $draft = PrintTemplate::where('brand_id', $this->brand->id)
        ->where('status', PrintTemplateStatus::Draft->value)
        ->firstOrFail();

    expect(footerOf($draft->definition))->toBe('editor one');
});

it('L2b: saving an existing draft without the lock token is refused', function () {
    $this->actingAs($this->admin)
        ->postJson("{$this->base}/draft", ['definition' => ($this->definition)('first')])
        ->assertOk();

    $this->actingAs($this->admin)
        ->postJson("{$this->base}/draft", ['definition' => ($this->definition)('blind write')])
        ->assertStatus(409)
        ->assertJsonPath('code', 'PRINT_TEMPLATE_DRAFT_STALE');
});

// ─── L3 (TR-10) ──────────────────────────────────────────────────────────
it('L3: publishing a draft whose parent is no longer live demands a rebase (TR-10)', function () {
    $v1 = draftAndPublish($this, $this->base, ($this->definition)('v1'));

    // Someone opens a draft based on v1…
    $draft = $this->actingAs($this->admin)
        ->postJson("{$this->base}/draft", ['definition' => ($this->definition)('long-running edit')])
        ->assertOk()
        ->json('data');

    expect($draft['parent_version_id'])->toBe($v1->id);

    // …and meanwhile v2 is published from elsewhere.
    PrintTemplate::factory()->create([
        'brand_id' => $this->brand->id,
        'kind' => 'receipt',
        'scope' => PrintTemplateScope::Brand->value,
        'status' => PrintTemplateStatus::Published->value,
        'version' => 99,
        'definition' => footerDefinition('someone else v2'),
        'published_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->postJson("{$this->base}/publish", ['parent_version_id' => $v1->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'PRINT_TEMPLATE_REBASE_REQUIRED');

    // The other person's version is still what prints.
    expect(footerOf(app(TemplateResolver::class)->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id)->definition))
        ->toBe('someone else v2');
});

// ─── L4 (TR-11) ──────────────────────────────────────────────────────────
it('L4: a past effective_from publishes fine and takes effect at once, without rewriting history (TR-11)', function () {
    $v1 = draftAndPublish($this, $this->base, ($this->definition)('v1'));

    $v2 = draftAndPublish($this, $this->base, ($this->definition)('v2 backdated'), [
        'effective_from' => '2020-01-01 00:00:00',
    ]);

    expect(footerOf(app(TemplateResolver::class)->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id)->definition))
        ->toBe('v2 backdated');

    // A slip printed under v1 still resolves to v1's definition — backdating
    // schedules the FUTURE, it does not rewrite what was already printed.
    $historical = app(TemplateResolver::class)->forVersion(PrintTemplateKind::Receipt, (string) $this->branch->id, $v1->version);
    expect(footerOf($historical->definition))->toBe('v1');
    expect($v2->version)->toBeGreaterThan($v1->version);
});

// ─── L5 (TR-13) ──────────────────────────────────────────────────────────
it('L5: retiring the live version drops new prints back a version, and the old one still renders (TR-13)', function () {
    $v1 = draftAndPublish($this, $this->base, ($this->definition)('v1'));
    $v2 = draftAndPublish($this, $this->base, ($this->definition)('v2'));

    expect(footerOf(app(TemplateResolver::class)->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id)->definition))
        ->toBe('v2');

    $this->actingAs($this->admin)
        ->postJson("{$this->base}/versions/{$v2->id}/retire")
        ->assertOk();

    // New prints fall back to v1…
    expect(footerOf(app(TemplateResolver::class)->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id)->definition))
        ->toBe('v1');

    // …and a reprint of a job that used v2 still gets v2.
    $historical = app(TemplateResolver::class)->forVersion(PrintTemplateKind::Receipt, (string) $this->branch->id, $v2->version);
    expect(footerOf($historical->definition))->toBe('v2');
});

// ─── L6 (TR-38) ──────────────────────────────────────────────────────────
it('L6: rollback publishes a NEW version with auto notes, leaving the old rows intact (TR-38)', function () {
    $v1 = draftAndPublish($this, $this->base, ($this->definition)('the good one'));
    $v2 = draftAndPublish($this, $this->base, ($this->definition)('the mistake'));

    $rolledBack = $this->actingAs($this->admin)
        ->postJson("{$this->base}/versions/{$v1->id}/rollback")
        ->assertCreated()
        ->json('data');

    expect($rolledBack['version'])->toBeGreaterThan($v2->version)
        ->and($rolledBack['status'])->toBe('published')
        ->and($rolledBack['notes'])->toBe("Rollback from v{$v1->version}")
        ->and(footerOf($rolledBack['definition']))->toBe('the good one');

    // Nothing was un-published: both originals survive untouched (TR-38).
    expect($v1->fresh()->status)->toBe(PrintTemplateStatus::Published)
        ->and($v2->fresh()->status)->toBe(PrintTemplateStatus::Published)
        ->and(footerOf($v2->fresh()->definition))->toBe('the mistake');

    expect(footerOf(app(TemplateResolver::class)->forBranch(PrintTemplateKind::Receipt, (string) $this->branch->id)->definition))
        ->toBe('the good one');
});

// ─── history + diff (TR-31) ──────────────────────────────────────────────
it('records who/when/notes for every publish and diffs two versions (TR-31)', function () {
    draftAndPublish($this, $this->base, ($this->definition)('june'), ['notes' => 'June layout']);
    draftAndPublish($this, $this->base, ($this->definition)('july'), ['notes' => 'July layout']);

    $history = $this->actingAs($this->admin)
        ->getJson("{$this->base}/history")
        ->assertOk()
        ->json('data');

    expect($history)->toHaveCount(2)
        ->and($history[0]['notes'])->toBe('July layout')
        ->and($history[0]['published_by'])->toBe($this->admin->name)
        ->and($history[0]['published_at'])->not->toBeNull();

    $diff = $this->actingAs($this->admin)
        ->getJson("{$this->base}/diff?from={$history[1]['version']}&to={$history[0]['version']}")
        ->assertOk()
        ->json('data');

    $change = collect($diff['changes'])->firstWhere('path', 'footer_text.i18n');

    expect($change['op'])->toBe('changed')
        ->and($change['from']['ja'])->toBe('june')
        ->and($change['to']['ja'])->toBe('july');
});

it('diffs the first version against the system default rather than against nothing', function () {
    draftAndPublish($this, $this->base, ($this->definition)('first ever'));

    $diff = $this->actingAs($this->admin)
        ->getJson("{$this->base}/diff")
        ->assertOk()
        ->json('data');

    expect($diff['from_version'])->toBe(0)
        ->and(collect($diff['changes'])->pluck('path'))->toContain('footer_text.i18n');
});

// ─── index / show surface ────────────────────────────────────────────────
it('flags "using the system template" until the brand publishes (TR-01)', function () {
    $before = collect($this->actingAs($this->admin)->getJson("/api/v1/hq/{$this->brand->slug}/print-templates")->json('data'))
        ->firstWhere('kind', 'receipt');

    expect($before['is_system_default'])->toBeTrue()
        ->and($before['published_version'])->toBeNull();

    draftAndPublish($this, $this->base, ($this->definition)('published now'));

    $after = collect($this->actingAs($this->admin)->getJson("/api/v1/hq/{$this->brand->slug}/print-templates")->json('data'))
        ->firstWhere('kind', 'receipt');

    expect($after['is_system_default'])->toBeFalse()
        ->and($after['published_version'])->toBe(1);
});

it('exposes the block catalog and system default so the editor never hard-codes them', function () {
    $data = $this->actingAs($this->admin)
        ->getJson($this->base)
        ->assertOk()
        ->json('data');

    expect($data['catalog']['blocks'])->toContain('tax_breakdown')
        ->and($data['catalog']['required'])->toContain('grand_total')
        ->and($data['catalog']['mutability']['tax_breakdown'])->toBe('locked')
        ->and($data['catalog']['mutability']['registration_number'])->toBe('toggleable')
        ->and($data['catalog']['mutability']['footer_text'])->toBe('free')
        ->and($data['system_default']['schema'])->toBe('tempo.print.v1');
});

it('publishing without a draft is refused rather than publishing nothing', function () {
    $this->actingAs($this->admin)
        ->postJson("{$this->base}/publish", [])
        ->assertStatus(409)
        ->assertJsonPath('code', 'PRINT_TEMPLATE_NO_DRAFT');
});

it('rejects a shop_editable allow-list that names a locked block, at draft time', function () {
    $this->actingAs($this->admin)
        ->postJson("{$this->base}/draft", [
            'definition' => ($this->definition)('x'),
            'shop_editable' => ['grand_total'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PRINT_TEMPLATE_INVALID');
});
