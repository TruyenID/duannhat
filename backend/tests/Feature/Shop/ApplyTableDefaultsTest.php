<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Table;
use App\Models\TableTemplate;
use App\Models\User;
use App\Models\Zone;
use App\Models\ZoneTemplate;
use Illuminate\Support\Str;

/**
 * issue #890 — "Nhận bàn mặc định từ HQ": copy the brand's zone/table
 * templates into real shop zones/tables, idempotent by code, without ever
 * touching tables the shop created itself.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    $this->org = Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'main-shop',
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->manager->assignRole($this->managerRole, $this->orgId);

    $this->applyUrl = "/api/v1/shops/{$this->shop->slug}/tables/defaults/apply";
    $this->previewUrl = "/api/v1/shops/{$this->shop->slug}/tables/defaults/preview";
});

/** Helper: seed one zone template with N table templates in the test brand. */
function seedBrandDefaults(int $tables = 2, array $zoneOverrides = []): ZoneTemplate
{
    $zone = ZoneTemplate::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'is_active' => true,
    ], $zoneOverrides));

    TableTemplate::factory()->count($tables)->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'zone_template_id' => $zone->id,
        'is_active' => true,
    ]);

    return $zone;
}

// =========================================================================
//  Apply
// =========================================================================

it('copies brand templates into real shop zones and tables', function () {
    $zoneTemplate = seedBrandDefaults(tables: 2, zoneOverrides: ['code' => 'HALL', 'name' => 'Main hall']);

    $this->actingAs($this->manager)
        ->postJson($this->applyUrl)
        ->assertOk()
        ->assertJsonPath('zones_created', 1)
        ->assertJsonPath('tables_created', 2)
        ->assertJsonPath('zones_skipped', 0)
        ->assertJsonPath('tables_skipped', 0);

    $zone = Zone::where('branch_id', $this->shop->id)->where('code', 'HALL')->first();
    expect($zone)->not->toBeNull()
        ->and($zone->name)->toBe('Main hall')
        ->and($zone->organization_id)->toBe($this->orgId);

    $tables = Table::where('branch_id', $this->shop->id)->get();
    expect($tables)->toHaveCount(2);
    foreach ($tables as $table) {
        expect($table->zone_id)->toBe($zone->id)
            ->and($table->qr_token)->toHaveLength(32);
        $this->assertDatabaseHas('tables', ['id' => $table->id, 'status' => 'free']);
    }

    // Each copied table gets its OWN qr_token.
    expect($tables->pluck('qr_token')->unique())->toHaveCount(2);
});

it('is idempotent — re-applying creates nothing and skips everything', function () {
    seedBrandDefaults(tables: 2);

    $this->actingAs($this->manager)->postJson($this->applyUrl)->assertOk();

    $this->actingAs($this->manager)
        ->postJson($this->applyUrl)
        ->assertOk()
        ->assertJsonPath('zones_created', 0)
        ->assertJsonPath('tables_created', 0)
        ->assertJsonPath('zones_skipped', 1)
        ->assertJsonPath('tables_skipped', 2);

    expect(Table::where('branch_id', $this->shop->id)->count())->toBe(2);
});

it('never overwrites a shop table that already uses the same code', function () {
    $zoneTemplate = seedBrandDefaults(tables: 0, zoneOverrides: ['code' => 'HALL']);
    TableTemplate::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'zone_template_id' => $zoneTemplate->id,
        'code' => 'T-01',
        'seat_count' => 8,
    ]);

    // The shop already created its own T-01 with 2 seats in its own zone.
    $ownZone = Zone::factory()->for($this->shop, 'branch')->create(['organization_id' => $this->orgId, 'code' => 'OWN']);
    $ownTable = Table::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'zone_id' => $ownZone->id,
        'code' => 'T-01',
        'seat_count' => 2,
    ]);

    $this->actingAs($this->manager)
        ->postJson($this->applyUrl)
        ->assertOk()
        ->assertJsonPath('tables_created', 0)
        ->assertJsonPath('tables_skipped', 1);

    expect($ownTable->fresh()->seat_count)->toBe(2)
        ->and($ownTable->fresh()->zone_id)->toBe($ownZone->id);
});

it('skips a template whose code only differs in case from an existing shop code', function () {
    // Shop already has an UPPERCASE zone/table; HQ template uses mixed case.
    // The MySQL unique index is case-insensitive, so these are "the same"
    // code — apply() must skip, not try to insert (issue #890 server error).
    $ownZone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
        'code' => 'COUNTER',
    ]);
    Table::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'zone_id' => $ownZone->id,
        'code' => 'T-01',
    ]);

    $zoneTemplate = seedBrandDefaults(tables: 0, zoneOverrides: ['code' => 'Counter']);
    TableTemplate::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'zone_template_id' => $zoneTemplate->id,
        'code' => 't-01',
    ]);

    $this->actingAs($this->manager)
        ->postJson($this->applyUrl)
        ->assertOk()
        ->assertJsonPath('zones_created', 0)
        ->assertJsonPath('zones_skipped', 1)
        ->assertJsonPath('tables_created', 0)
        ->assertJsonPath('tables_skipped', 1);

    // No duplicate rows created; the shop's originals are untouched.
    expect(Zone::where('branch_id', $this->shop->id)->count())->toBe(1)
        ->and(Table::where('branch_id', $this->shop->id)->count())->toBe(1);
});

it('skips inactive templates and tables under inactive zone templates', function () {
    seedBrandDefaults(tables: 1, zoneOverrides: ['code' => 'ON']);
    seedBrandDefaults(tables: 1, zoneOverrides: ['code' => 'OFF', 'is_active' => false]);

    $this->actingAs($this->manager)
        ->postJson($this->applyUrl)
        ->assertOk()
        ->assertJsonPath('zones_created', 1)
        ->assertJsonPath('tables_created', 1);

    expect(Zone::where('branch_id', $this->shop->id)->pluck('code')->all())->toBe(['ON']);
});

it('409s when the shop is not linked to a brand', function () {
    $orphanShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => null,
        'slug' => 'orphan-shop',
        'is_active' => true,
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$orphanShop->slug}/tables/defaults/apply")
        ->assertStatus(409)
        ->assertJsonPath('code', 'SHOP_HAS_NO_BRAND');
});

it('forbids staff without a manager role from applying', function () {
    seedBrandDefaults();

    $staffRole = Role::firstOrCreate(
        ['slug' => 'staff'],
        ['name' => 'Staff', 'level' => 10],
    );
    $staff = User::factory()->create(['console_organization_id' => $this->orgId]);
    $staff->assignRole($staffRole, $this->orgId);

    $this->actingAs($staff)
        ->postJson($this->applyUrl)
        ->assertForbidden();
});

it('leaves the shop free to create its own tables afterwards', function () {
    seedBrandDefaults(tables: 1, zoneOverrides: ['code' => 'HALL']);
    $this->actingAs($this->manager)->postJson($this->applyUrl)->assertOk();

    $zone = Zone::where('branch_id', $this->shop->id)->where('code', 'HALL')->firstOrFail();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tables", [
            'code' => 'EXTRA-01',
            'zone_id' => $zone->id,
            'seat_count' => 4,
        ])
        ->assertCreated();

    expect(Table::where('branch_id', $this->shop->id)->count())->toBe(2);
});

// =========================================================================
//  Preview
// =========================================================================

it('previews counts without writing anything', function () {
    seedBrandDefaults(tables: 3);

    $this->actingAs($this->manager)
        ->getJson($this->previewUrl)
        ->assertOk()
        ->assertJsonPath('zones.create', 1)
        ->assertJsonPath('tables.create', 3)
        ->assertJsonPath('zones.skip', 0)
        ->assertJsonPath('tables.skip', 0);

    expect(Zone::where('branch_id', $this->shop->id)->count())->toBe(0)
        ->and(Table::where('branch_id', $this->shop->id)->count())->toBe(0);
});

// =========================================================================
//  Delete permissions (BR-T09 / BR-Z04) — shop chỉ xoá được bàn mình tạo
// =========================================================================

it('stamps HQ origin markers on copied zones and tables', function () {
    seedBrandDefaults(tables: 1, zoneOverrides: ['code' => 'HALL']);

    $this->actingAs($this->manager)->postJson($this->applyUrl)->assertOk();

    $zone = Zone::where('branch_id', $this->shop->id)->where('code', 'HALL')->firstOrFail();
    $table = Table::where('branch_id', $this->shop->id)->firstOrFail();

    expect($zone->zone_template_id)->not->toBeNull()
        ->and($table->table_template_id)->not->toBeNull();

    // The resources expose the marker so the UI can hide the delete action.
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/tables")
        ->assertOk()
        ->assertJsonPath('data.0.table_template_id', $table->table_template_id);
});

it('blocks the shop from deleting an HQ-origin table but allows deleting its own', function () {
    seedBrandDefaults(tables: 1, zoneOverrides: ['code' => 'HALL']);
    $this->actingAs($this->manager)->postJson($this->applyUrl)->assertOk();

    $hqTable = Table::where('branch_id', $this->shop->id)->whereNotNull('table_template_id')->firstOrFail();
    $zone = Zone::where('branch_id', $this->shop->id)->where('code', 'HALL')->firstOrFail();

    // Shop-created table in the same (HQ-origin) zone deletes fine.
    $ownTable = Table::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'zone_id' => $zone->id,
        'code' => 'OWN-01',
    ]);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/tables/{$hqTable->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'TABLE_DELETE_BLOCKED_HQ_TEMPLATE');

    $this->assertNotSoftDeleted('tables', ['id' => $hqTable->id]);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/tables/{$ownTable->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('tables', ['id' => $ownTable->id]);
});

it('still allows the shop to deactivate an HQ-origin table', function () {
    seedBrandDefaults(tables: 1);
    $this->actingAs($this->manager)->postJson($this->applyUrl)->assertOk();

    $hqTable = Table::where('branch_id', $this->shop->id)->firstOrFail();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tables/{$hqTable->id}/toggle-active")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
});

it('blocks deleting an HQ-origin zone and any zone still holding HQ tables', function () {
    seedBrandDefaults(tables: 1, zoneOverrides: ['code' => 'HALL']);
    $this->actingAs($this->manager)->postJson($this->applyUrl)->assertOk();

    $hqZone = Zone::where('branch_id', $this->shop->id)->where('code', 'HALL')->firstOrFail();

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/zones/{$hqZone->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'ZONE_DELETE_BLOCKED_HQ_TEMPLATE');

    // A zone the shop created itself still deletes fine.
    $ownZone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
        'code' => 'OWN-ZONE',
    ]);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/zones/{$ownZone->id}")
        ->assertNoContent();
});

it('blocks the shop from editing an HQ-origin table but allows editing its own', function () {
    seedBrandDefaults(tables: 1, zoneOverrides: ['code' => 'HALL']);
    $this->actingAs($this->manager)->postJson($this->applyUrl)->assertOk();

    $hqTable = Table::where('branch_id', $this->shop->id)->whereNotNull('table_template_id')->firstOrFail();
    $zone = Zone::where('branch_id', $this->shop->id)->where('code', 'HALL')->firstOrFail();

    $ownTable = Table::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'zone_id' => $zone->id,
        'code' => 'OWN-01',
        'seat_count' => 2,
    ]);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/tables/{$hqTable->id}", ['seat_count' => 9])
        ->assertStatus(409)
        ->assertJsonPath('code', 'TABLE_UPDATE_BLOCKED_HQ_TEMPLATE');

    expect($hqTable->fresh()->seat_count)->not->toBe(9);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/tables/{$ownTable->id}", [
            'code' => 'OWN-02',
            'name' => 'Renamed',
            'seat_count' => 6,
        ])
        ->assertOk()
        ->assertJsonPath('data.code', 'OWN-02')
        ->assertJsonPath('data.seat_count', 6);
});

// =========================================================================
//  HQ toggle: allow_shop_edit_hq_tables (#890)
// =========================================================================

it('lets the shop edit an HQ-origin table when HQ enables the switch — delete stays blocked', function () {
    seedBrandDefaults(tables: 1);
    $this->actingAs($this->manager)->postJson($this->applyUrl)->assertOk();

    $hqTable = Table::where('branch_id', $this->shop->id)->whereNotNull('table_template_id')->firstOrFail();

    // Default (no policy row): locked.
    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/tables/{$hqTable->id}", ['seat_count' => 9])
        ->assertStatus(409);

    BrandOrderPolicy::create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'allow_shop_edit_hq_tables' => true,
    ]);

    // Switch on: edit is allowed…
    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/tables/{$hqTable->id}", ['seat_count' => 9, 'name' => 'Renamed by shop'])
        ->assertOk()
        ->assertJsonPath('data.seat_count', 9);

    // …but delete is still HQ-only.
    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/tables/{$hqTable->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'TABLE_DELETE_BLOCKED_HQ_TEMPLATE');
});

it('exposes the switch on HQ brand settings and shop info', function () {
    // Default: off everywhere.
    $this->actingAs($this->manager)
        ->getJson("/api/v1/hq/{$this->brand->slug}/settings/brand")
        ->assertOk()
        ->assertJsonPath('data.allow_shop_edit_hq_tables', false);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}")
        ->assertOk()
        ->assertJsonPath('data.allow_shop_edit_hq_tables', false);

    // HQ flips it on.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", ['allow_shop_edit_hq_tables' => true])
        ->assertOk()
        ->assertJsonPath('data.allow_shop_edit_hq_tables', true);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}")
        ->assertOk()
        ->assertJsonPath('data.allow_shop_edit_hq_tables', true);
});

// =========================================================================
//  Branch targeting (#890 follow-up: field "Chi nhánh" on HQ templates)
// =========================================================================

it('applies brand-wide templates plus only the ones targeting this branch', function () {
    // Brand-wide zone with one brand-wide table.
    $sharedZone = seedBrandDefaults(tables: 1, zoneOverrides: ['code' => 'SHARED']);

    // Zone targeted at THIS shop.
    $mineZone = ZoneTemplate::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'code' => 'MINE',
    ]);
    TableTemplate::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'zone_template_id' => $mineZone->id,
        'code' => 'MINE-T1',
    ]);

    // Zone targeted at ANOTHER shop of the same brand.
    $otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'other-shop',
        'is_active' => true,
    ]);
    $foreignZone = ZoneTemplate::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $otherShop->id,
        'code' => 'THEIRS',
    ]);
    TableTemplate::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'zone_template_id' => $foreignZone->id,
        'code' => 'THEIRS-T1',
    ]);

    // Brand-wide TABLE inside the foreign-targeted zone → still skipped here
    // (zone not applicable to this shop).
    TableTemplate::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'zone_template_id' => $foreignZone->id,
        'branch_id' => null,
        'code' => 'ORPHANED',
    ]);

    $this->actingAs($this->manager)
        ->postJson($this->applyUrl)
        ->assertOk()
        ->assertJsonPath('zones_created', 2)
        ->assertJsonPath('tables_created', 2);

    expect(Zone::where('branch_id', $this->shop->id)->pluck('code')->sort()->values()->all())
        ->toBe(['MINE', 'SHARED'])
        ->and(Table::where('branch_id', $this->shop->id)->pluck('code')->all())
        ->not->toContain('THEIRS-T1', 'ORPHANED');

    // The other shop receives SHARED + THEIRS (+ ORPHANED), not MINE.
    $otherManager = $this->manager;
    $this->actingAs($otherManager)
        ->postJson("/api/v1/shops/{$otherShop->slug}/tables/defaults/apply")
        ->assertOk();

    expect(Zone::where('branch_id', $otherShop->id)->pluck('code')->sort()->values()->all())
        ->toBe(['SHARED', 'THEIRS']);
});

it('matches existing codes case-insensitively (MySQL ci unique index)', function () {
    // Shop already has zone "COUNTER"; HQ template is "Counter".
    Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
        'code' => 'COUNTER',
    ]);
    seedBrandDefaults(tables: 1, zoneOverrides: ['code' => 'Counter']);

    // Must skip (not 500 on the ci unique index).
    $this->actingAs($this->manager)
        ->postJson($this->applyUrl)
        ->assertOk()
        ->assertJsonPath('zones_created', 0)
        ->assertJsonPath('zones_skipped', 1);

    expect(Zone::where('branch_id', $this->shop->id)->count())->toBe(1);
});
