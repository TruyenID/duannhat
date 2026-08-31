<?php

declare(strict_types=1);

/**
 * #2043 phần B — the block CATALOG is readable from the SHOP surface.
 *
 * Why this endpoint exists at all: `config/print_blocks.php` decides which
 * controls a template editor may draw (which blocks are toggleable, which props
 * are editable, which param fields and sources exist). The only place that
 * shipped it was `GET /hq/{brand}/print-templates/{kind}`, which needs
 * `menu.manage` — a permission the shop-override surface deliberately does not
 * require (TR-37: the shop surface rides `shop.manage`). So admin-web kept a
 * hand-copied mirror of the catalog for its shop screen, and that mirror
 * drifted FOUR times without anything going red (#1181 ×2, #2000, #2040 — the
 * last one five separate divergences at once). Every drift was silent: a block
 * with no on/off switch, a param field with no checkbox.
 *
 * The fix is not a parity test over the copy — it is deleting the reason the
 * copy exists. What this file pins:
 *
 *   1. a shop manager can READ the catalog on the shop surface;
 *   2. a cashier still cannot (the surface is invisible, not read-only);
 *   3. a user from another organization cannot;
 *   4. HQ and shop serve the BYTE-IDENTICAL catalog, so the two surfaces can
 *      never describe one config file two ways;
 *   5. the payload actually carries the four things the editor used to mirror,
 *      for every kind — not just the one kind a happy-path test happens to ask
 *      for.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Print\Enums\PrintTemplateKind;
use Database\Seeders\IamSeeder;
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
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'timezone' => 'Asia/Tokyo',
    ]);
});

/**
 * Assign a named system role, seeding the IAM matrix on first use.
 *
 * Deliberately NOT reusing `grantRole()` from `TemplateValidationTest.php`: a
 * function declared inside a test file only exists while that file is loaded,
 * so running this file on its own would die with "undefined function".
 */
function grantCatalogRole(User $user, string $organizationId, string $slug): void
{
    if (! Permission::query()->exists()) {
        (new IamSeeder)->run();
    }

    $user->assignRole(Role::query()->where('slug', $slug)->firstOrFail(), $organizationId);
}

it('serves the block catalog to a shop manager on the shop surface', function () {
    $manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantCatalogRole($manager, $this->orgId, 'shop-manager');

    $catalog = $this->actingAs($manager)
        ->getJson("/api/v1/shops/{$this->branch->slug}/print-templates/receipt")
        ->assertOk()
        ->json('data.catalog');

    expect($catalog)->toHaveKeys([
        'blocks', 'required', 'sources', 'param_fields',
        'mutability', 'editable_props', 'prop_enums',
    ]);

    // The exact things admin-web used to hand-copy, on the wire.
    expect($catalog['blocks'])->toBe(config('print_blocks.kinds.receipt.blocks'))
        ->and($catalog['required'])->toBe(config('print_blocks.kinds.receipt.required'))
        ->and($catalog['sources'])->toBe(config('print_blocks.sources'))
        ->and($catalog['param_fields'])->toBe(config('print_blocks.param_fields'))
        ->and($catalog['mutability']['grand_total'])->toBe('locked')
        ->and($catalog['mutability']['registration_number'])->toBe('toggleable')
        ->and($catalog['mutability']['footer_text'])->toBe('free')
        // #3082 — `size` mở cho brand chỉnh cỡ chữ dòng món. Không mở thì quán
        // nhìn phiếu thật rồi muốn hạ/nâng phải chờ một lần deploy.
        ->and($catalog['editable_props']['items'])->toBe(['columns', 'size'])
        // Enum phải TỚI được editor: đây là chỗ nó thành một ô chọn thay vì một
        // ô chữ tự do, và một ô chữ tự do cho prop này là phiếu vỡ bố cục.
        ->and($catalog['prop_enums']['items']['size'])->toBe(['normal', 'tall'])
        ->and($catalog['editable_props']['grand_total'])->toBe([])
        ->and($catalog['prop_enums']['items']['columns'])
        ->toBe(config('print_blocks.blocks.items.prop_enums.columns'));
});

it('gives a cashier nothing — the shop surface is invisible, not read-only (TR-37)', function () {
    $cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantCatalogRole($cashier, $this->orgId, 'shop-staff');

    $this->actingAs($cashier)
        ->getJson("/api/v1/shops/{$this->branch->slug}/print-templates/receipt")
        ->assertForbidden();
});

it('refuses a shop manager of a DIFFERENT organization', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);

    $outsider = User::factory()->create(['console_organization_id' => $otherOrgId]);
    grantCatalogRole($outsider, $otherOrgId, 'shop-manager');

    $this->actingAs($outsider)
        ->getJson("/api/v1/shops/{$this->branch->slug}/print-templates/receipt")
        ->assertForbidden();
});

it('serves HQ and shop the SAME catalog — one config file, one description', function () {
    $admin = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantCatalogRole($admin, $this->orgId, 'org-admin');

    foreach (['receipt', 'shift_report', 'vat_invoice'] as $kind) {
        $hq = $this->actingAs($admin)
            ->getJson("/api/v1/hq/{$this->brand->slug}/print-templates/{$kind}")
            ->assertOk()
            ->json('data.catalog');

        $shop = $this->actingAs($admin)
            ->getJson("/api/v1/shops/{$this->branch->slug}/print-templates/{$kind}")
            ->assertOk()
            ->json('data.catalog');

        // Non-empty first, so "both null" cannot pass as agreement.
        expect($hq['blocks'] ?? [])->not->toBeEmpty("empty HQ catalog on {$kind}")
            ->and($shop)->toBe($hq, "catalog mismatch on kind {$kind}");
    }
});

it('describes every block of every kind — no kind ships a half-catalog', function () {
    $manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantCatalogRole($manager, $this->orgId, 'shop-manager');

    foreach (PrintTemplateKind::cases() as $kind) {
        $catalog = $this->actingAs($manager)
            ->getJson("/api/v1/shops/{$this->branch->slug}/print-templates/{$kind->value}")
            ->assertOk()
            ->json('data.catalog');

        // A block the editor can be asked to draw but cannot look up is exactly
        // the #2040 shape: it falls through to "engine owned" and loses its
        // switch. Both maps must cover the kind's blocks, no more and no less.
        expect(array_keys($catalog['mutability']))->toBe($catalog['blocks'], "mutability gap on {$kind->value}")
            ->and(array_keys($catalog['editable_props']))->toBe($catalog['blocks'], "editable_props gap on {$kind->value}");

        // `required` is a subset of the kind's own blocks, or the editor would
        // badge a block that is not on the slip.
        expect(array_diff($catalog['required'], $catalog['blocks']))->toBe([], "stray required on {$kind->value}");
    }
});

it('names a toggleable block as toggleable wherever it appears — the #2040 regression', function () {
    $manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantCatalogRole($manager, $this->orgId, 'shop-manager');

    $catalog = $this->actingAs($manager)
        ->getJson("/api/v1/shops/{$this->branch->slug}/print-templates/shift_report")
        ->assertOk()
        ->json('data.catalog');

    // The seven 精算 sections #2040 found with no switch in the mirror.
    foreach ([
        'sales_summary', 'non_cash_change', 'discount_summary', 'acct_correction',
        'check_count', 'cash_movement', 'void_summary',
    ] as $blockId) {
        expect($catalog['mutability'][$blockId] ?? null)->toBe('toggleable', "{$blockId} lost its switch")
            ->and($catalog['editable_props'][$blockId] ?? null)->toBe(['enabled'], "{$blockId} lost its editable prop");
    }
});
