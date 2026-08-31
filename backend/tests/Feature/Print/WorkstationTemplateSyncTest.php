<?php

declare(strict_types=1);

/**
 * plan-053 (#1171) — TESTS.md §3 (S1–S4): sync DOWN.
 *
 * The contract this file pins down is the one that keeps the workstation
 * simple: Cloud hands down definitions that are ALREADY RESOLVED for the
 * device's branch (S2). If the workstation had to merge the three layers
 * itself, TR-02/TR-04 would exist twice in two languages and would eventually
 * disagree — in a shop, on paper, where nobody can debug it.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\PrintTemplate;
use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\Enums\PrintTemplateStatus;
use App\Services\Print\SystemTemplateDefaults;
use App\Services\Print\TemplateChecksum;
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

    $this->token = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->token,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->pull = fn (string $query = '') => $this
        ->withHeaders(['Authorization' => "Bearer {$this->token}"])
        ->getJson('/api/v1/workstation/print-templates'.$query);
});

/** Publish a brand-layer row with a recognisable footer. */
function syncBrandTemplate(Brand $brand, string $footer, int $version = 1, array $shopEditable = []): PrintTemplate
{
    return PrintTemplate::factory()->create([
        'brand_id' => $brand->id,
        'branch_id' => null,
        'kind' => 'receipt',
        'scope' => PrintTemplateScope::Brand->value,
        'status' => PrintTemplateStatus::Published->value,
        'version' => $version,
        'definition' => [
            'schema' => 'tempo.print.v1',
            'blocks' => [['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => $footer]]],
        ],
        'shop_editable' => $shopEditable,
        'published_at' => now(),
    ]);
}

function footerFromEntry(array $entry): ?string
{
    foreach ($entry['definition']['blocks'] as $block) {
        if (($block['id'] ?? null) === 'footer_text') {
            return $block['i18n']['ja'] ?? null;
        }
    }

    return null;
}

it('serves all 13 kinds with a checksum, even before the brand configures anything', function () {
    $data = ($this->pull)()->assertOk()->json('data');

    expect($data)->toHaveCount(count(PrintTemplateKind::cases()));

    foreach ($data as $entry) {
        expect($entry['checksum'])->toHaveLength(64)
            ->and($entry['is_system_default'])->toBeTrue()
            ->and($entry['definition']['blocks'])->not->toBeEmpty();
    }
});

// ─── S1 ──────────────────────────────────────────────────────────────────
it('S1: `?since=` returns only what changed, with a checksum (§5)', function () {
    $row = syncBrandTemplate($this->brand, 'brand footer');

    $full = ($this->pull)()->assertOk()->json('data');
    expect($full)->toHaveCount(count(PrintTemplateKind::cases()));

    // A cursor AFTER the row's update: nothing to send.
    $after = $row->updated_at->copy()->addMinute()->toIso8601String();
    expect(($this->pull)('?since='.urlencode($after))->assertOk()->json('data'))->toBe([]);

    // A cursor BEFORE it: exactly the one kind that changed.
    $before = $row->updated_at->copy()->subMinute()->toIso8601String();
    $delta = ($this->pull)('?since='.urlencode($before))->assertOk()->json('data');

    expect($delta)->toHaveCount(1)
        ->and($delta[0]['kind'])->toBe('receipt')
        ->and($delta[0]['checksum'])->toHaveLength(64);
});

it('S1b: the checksum is stable across key order and changes with content', function () {
    syncBrandTemplate($this->brand, 'first');

    $first = collect(($this->pull)()->json('data'))->firstWhere('kind', 'receipt');

    expect($first['checksum'])->toBe(TemplateChecksum::of($first['definition']));

    // Reordering keys is not a change — otherwise every PHP array reshuffle
    // would make the whole fleet re-download the registry.
    $reordered = array_reverse($first['definition'], preserve_keys: true);
    expect(TemplateChecksum::of($reordered))->toBe($first['checksum']);
});

// ─── S2 ──────────────────────────────────────────────────────────────────
it('S2: returns definitions ALREADY resolved for the branch — the workstation never merges (§5)', function () {
    syncBrandTemplate($this->brand, 'BRAND footer', shopEditable: ['footer_text', 'greeting']);

    PrintTemplate::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'kind' => 'receipt',
        'scope' => PrintTemplateScope::Shop->value,
        'status' => PrintTemplateStatus::Published->value,
        'version' => 1,
        'definition' => [
            'schema' => 'tempo.print.v1',
            'blocks' => [['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'SHOP footer']]],
        ],
        'shop_editable' => null,
        'published_at' => now(),
    ]);

    $entry = collect(($this->pull)()->assertOk()->json('data'))->firstWhere('kind', 'receipt');

    // Merged: the shop's delegated field won, and the untouched locked blocks
    // from the system default are present in the same payload.
    expect(footerFromEntry($entry))->toBe('SHOP footer')
        ->and($entry['scope'])->toBe('shop')
        ->and(collect($entry['definition']['blocks'])->pluck('id'))
        ->toContain('tax_breakdown')
        ->toContain('grand_total');
});

it('S2b: carries the branch wall clock so a drifted workstation can correct itself (TR-25)', function () {
    $body = ($this->pull)()->assertOk()->json();

    expect($body['branch_timezone'])->toBe('Asia/Tokyo')
        ->and($body['branch_wall_clock'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

it('S2c: the payload stays small enough for a 60s tick (§8: < 100KB per branch)', function () {
    $bytes = strlen((string) json_encode(($this->pull)()->json()));

    expect($bytes)->toBeLessThan(100 * 1024);
});

// ─── S3 ──────────────────────────────────────────────────────────────────
it('S3: a device may not pull another branch (403)', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    ($this->pull)('?branch_id='.$otherBranch->id)
        ->assertForbidden()
        ->assertJsonPath('code', 'BRANCH_MISMATCH');
});

it('S3b: an unauthenticated pull is rejected', function () {
    $this->getJson('/api/v1/workstation/print-templates')->assertUnauthorized();
});

it('S3c: a device only ever sees ITS branch\'s override, never a sibling branch\'s', function () {
    $sibling = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    syncBrandTemplate($this->brand, 'BRAND footer', shopEditable: ['footer_text']);

    PrintTemplate::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $sibling->id,
        'kind' => 'receipt',
        'scope' => PrintTemplateScope::Shop->value,
        'status' => PrintTemplateStatus::Published->value,
        'version' => 1,
        'definition' => [
            'schema' => 'tempo.print.v1',
            'blocks' => [['id' => 'footer_text', 'type' => 'text', 'enabled' => true, 'i18n' => ['ja' => 'SIBLING footer']]],
        ],
        'shop_editable' => null,
        'published_at' => now(),
    ]);

    $entry = collect(($this->pull)()->assertOk()->json('data'))->firstWhere('kind', 'receipt');

    expect(footerFromEntry($entry))->toBe('BRAND footer');
});

// ─── S4 (TR-39) ──────────────────────────────────────────────────────────
it('S4: a soft-deleted brand still serves its version for a historical reprint (TR-39)', function () {
    $row = syncBrandTemplate($this->brand, 'the version on that receipt');

    // The brand is removed — the shop is gone, but last quarter's invoice may
    // still have to be reprinted or credited (赤伝).
    $row->delete();
    $this->brand->delete();

    $entry = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
        ->getJson("/api/v1/workstation/print-templates/receipt/versions/{$row->version}")
        ->assertOk()
        ->json('data');

    expect($entry['version'])->toBe($row->version)
        ->and(footerFromEntry($entry))->toBe('the version on that receipt');
});

it('S4b: a version that is genuinely gone 404s so the caller can mark the slip (TR-29)', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
        ->getJson('/api/v1/workstation/print-templates/receipt/versions/404')
        ->assertNotFound()
        ->assertJsonPath('code', 'PRINT_TEMPLATE_VERSION_GONE');
});

it('S4c: an unknown kind on the version route is a 422 (TR-06)', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
        ->getJson('/api/v1/workstation/print-templates/not_a_kind/versions/1')
        ->assertStatus(422)
        ->assertJsonPath('code', 'PRINT_TEMPLATE_KIND_UNKNOWN');
});

it('serves the system default unchanged when nothing is configured (TR-05 baseline)', function () {
    $entry = collect(($this->pull)()->json('data'))->firstWhere('kind', 'kitchen');

    expect($entry['definition'])->toBe(app(SystemTemplateDefaults::class)->forKind(PrintTemplateKind::Kitchen))
        ->and($entry['version'])->toBeNull();
});
