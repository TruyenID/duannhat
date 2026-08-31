<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\TableTemplate;
use App\Models\User;
use App\Models\ZoneTemplate;
use Illuminate\Support\Str;

/**
 * issue #890 — HQ CRUD for brand-scoped zone/table templates
 * (the "default tables" a shop pulls down via tables/defaults/apply).
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-tables',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->zoneUrl = "/api/v1/hq/{$this->brand->slug}/zone-templates";
    $this->tableUrl = "/api/v1/hq/{$this->brand->slug}/table-templates";

    $this->actingAs($this->user);
});

/** Helper: create a zone template in the test brand/org. */
function zoneTemplateInBrand(array $overrides = []): ZoneTemplate
{
    return ZoneTemplate::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
    ], $overrides));
}

/** Helper: create a table template in the test brand/org. */
function tableTemplateInBrand(array $overrides = []): TableTemplate
{
    return TableTemplate::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'zone_template_id' => $overrides['zone_template_id'] ?? zoneTemplateInBrand()->id,
    ], $overrides));
}

// =========================================================================
//  Zone templates — CRUD
// =========================================================================

describe('zone templates', function () {
    it('creates a zone template scoped to the brand', function () {
        $this->postJson($this->zoneUrl, [
            'code' => 'TER',
            'name' => 'Terrace',
            'description' => 'Outdoor seating',
            'display_order' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'TER')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('zone_templates', [
            'code' => 'TER',
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
    });

    it('rejects a duplicate code within the brand but allows it on another brand', function () {
        zoneTemplateInBrand(['code' => 'TER']);

        $this->postJson($this->zoneUrl, ['code' => 'TER', 'name' => 'Terrace'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'slug' => 'other-brand',
            'is_active' => true,
        ]);

        $this->postJson("/api/v1/hq/{$otherBrand->slug}/zone-templates", ['code' => 'TER', 'name' => 'Terrace'])
            ->assertCreated();
    });

    it('lists zone templates ordered by display_order, scoped to the brand', function () {
        zoneTemplateInBrand(['code' => 'B', 'display_order' => 2]);
        zoneTemplateInBrand(['code' => 'A', 'display_order' => 1]);

        $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
        ZoneTemplate::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $otherBrand->id,
            'code' => 'FOREIGN',
        ]);

        $this->getJson($this->zoneUrl)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'A')
            ->assertJsonPath('data.1.code', 'B');
    });

    it('updates a zone template', function () {
        $zone = zoneTemplateInBrand(['name' => 'Old']);

        $this->putJson("{$this->zoneUrl}/{$zone->id}", ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New');
    });

    it('404s when touching another brand\'s zone template by UUID', function () {
        $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
        $foreign = ZoneTemplate::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $otherBrand->id,
        ]);

        $this->getJson("{$this->zoneUrl}/{$foreign->id}")->assertNotFound();
        $this->putJson("{$this->zoneUrl}/{$foreign->id}", ['name' => 'X'])->assertNotFound();
        $this->deleteJson("{$this->zoneUrl}/{$foreign->id}")->assertNotFound();
    });

    it('soft-deletes a zone template and cascades to its table templates', function () {
        $zone = zoneTemplateInBrand();
        $table = tableTemplateInBrand(['zone_template_id' => $zone->id]);

        $this->deleteJson("{$this->zoneUrl}/{$zone->id}")->assertNoContent();

        $this->assertSoftDeleted('zone_templates', ['id' => $zone->id]);
        $this->assertSoftDeleted('table_templates', ['id' => $table->id]);
    });

    it('restores a zone template without restoring its table templates', function () {
        $zone = zoneTemplateInBrand();
        $table = tableTemplateInBrand(['zone_template_id' => $zone->id]);
        $this->deleteJson("{$this->zoneUrl}/{$zone->id}")->assertNoContent();

        $this->postJson("{$this->zoneUrl}/{$zone->id}/restore")->assertOk();

        $this->assertNotSoftDeleted('zone_templates', ['id' => $zone->id]);
        $this->assertSoftDeleted('table_templates', ['id' => $table->id]);
    });

    it('toggles is_active', function () {
        $zone = zoneTemplateInBrand(['is_active' => true]);

        $this->postJson("{$this->zoneUrl}/{$zone->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    });

    it('returns active templates via lookup', function () {
        zoneTemplateInBrand(['code' => 'ACT', 'is_active' => true]);
        zoneTemplateInBrand(['code' => 'OFF', 'is_active' => false]);

        $this->getJson("{$this->zoneUrl}/lookup")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'ACT');
    });
});

// =========================================================================
//  Table templates — CRUD
// =========================================================================

describe('table templates', function () {
    it('creates a table template inside a zone template of the brand', function () {
        $zone = zoneTemplateInBrand();

        $this->postJson($this->tableUrl, [
            'code' => 'T-01',
            'name' => 'Window table',
            'seat_count' => 4,
            'zone_template_id' => $zone->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'T-01')
            ->assertJsonPath('data.seat_count', 4)
            ->assertJsonPath('data.zone_template.id', $zone->id);

        $this->assertDatabaseHas('table_templates', [
            'code' => 'T-01',
            'brand_id' => $this->brand->id,
        ]);
    });

    it('rejects a zone template belonging to another brand', function () {
        $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
        $foreignZone = ZoneTemplate::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $otherBrand->id,
        ]);

        $this->postJson($this->tableUrl, [
            'code' => 'T-01',
            'zone_template_id' => $foreignZone->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('zone_template_id');
    });

    it('rejects a duplicate code within the brand', function () {
        tableTemplateInBrand(['code' => 'T-01']);

        $this->postJson($this->tableUrl, [
            'code' => 'T-01',
            'zone_template_id' => zoneTemplateInBrand()->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    });

    it('rejects seat_count below 1', function () {
        $this->postJson($this->tableUrl, [
            'code' => 'T-01',
            'seat_count' => 0,
            'zone_template_id' => zoneTemplateInBrand()->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('seat_count');
    });

    it('updates and soft-deletes a table template', function () {
        $table = tableTemplateInBrand(['seat_count' => 2]);

        $this->putJson("{$this->tableUrl}/{$table->id}", ['seat_count' => 6])
            ->assertOk()
            ->assertJsonPath('data.seat_count', 6);

        $this->deleteJson("{$this->tableUrl}/{$table->id}")->assertNoContent();
        $this->assertSoftDeleted('table_templates', ['id' => $table->id]);
    });

    it('blocks restoring a table template while its zone template is deleted', function () {
        $zone = zoneTemplateInBrand();
        $table = tableTemplateInBrand(['zone_template_id' => $zone->id]);

        $this->deleteJson("{$this->zoneUrl}/{$zone->id}")->assertNoContent();

        $this->postJson("{$this->tableUrl}/{$table->id}/restore")
            ->assertStatus(409)
            ->assertJsonPath('code', 'ZONE_TEMPLATE_DELETED');

        $this->postJson("{$this->zoneUrl}/{$zone->id}/restore")->assertOk();
        $this->postJson("{$this->tableUrl}/{$table->id}/restore")->assertOk();
    });

    it('filters by zone_template_id', function () {
        $zoneA = zoneTemplateInBrand();
        $zoneB = zoneTemplateInBrand();
        tableTemplateInBrand(['code' => 'A-01', 'zone_template_id' => $zoneA->id]);
        tableTemplateInBrand(['code' => 'B-01', 'zone_template_id' => $zoneB->id]);

        $this->getJson("{$this->tableUrl}?zone_template_id={$zoneA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'A-01');
    });
});

// =========================================================================
//  Branch targeting (#890 follow-up)
// =========================================================================

describe('branch targeting', function () {
    it('creates a zone template targeted at a branch of the brand and returns it', function () {
        $shop = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'is_active' => true,
        ]);

        $this->postJson($this->zoneUrl, ['code' => 'MINE', 'name' => 'Chi nhánh A', 'branch_id' => $shop->id])
            ->assertCreated()
            ->assertJsonPath('data.branch.id', $shop->id)
            ->assertJsonPath('data.branch.name', $shop->name);

        // Null = brand-wide.
        $this->postJson($this->zoneUrl, ['code' => 'SHARED', 'name' => 'Chung', 'branch_id' => null])
            ->assertCreated()
            ->assertJsonPath('data.branch', null);
    });

    it('rejects a branch that belongs to another brand', function () {
        $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
        $foreignShop = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $otherBrand->console_brand_id,
            'is_active' => true,
        ]);

        $this->postJson($this->zoneUrl, ['code' => 'X', 'name' => 'X', 'branch_id' => $foreignShop->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');

        $this->postJson($this->tableUrl, [
            'code' => 'T-X',
            'zone_template_id' => zoneTemplateInBrand()->id,
            'branch_id' => $foreignShop->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    });
});
