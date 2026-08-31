<?php

/**
 * Plan-023 M7 T7.9 — field-options endpoint contract.
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'rule-fields-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/notifications/rules/field-options";
});

it('M7-T7.9 returns fields + relations for a mapped morph alias', function () {
    $this->actingAs($this->user)
        ->getJson("{$this->base}?model=Recipe")
        ->assertOk()
        ->assertJsonPath('data.model', 'Recipe')
        ->assertJsonStructure([
            'data' => [
                'model',
                'fields' => [['name', 'type', 'ops_supported']],
                'relations',
            ],
        ]);
});

it('M7-T7.9 returns 404 for unmapped class', function () {
    $this->actingAs($this->user)
        ->getJson("{$this->base}?model=NotARealMorphAlias")
        ->assertNotFound()
        ->assertJsonPath('error', 'model_unmapped');
});

it('M7-T7.9 returns 422 when model param missing', function () {
    $this->actingAs($this->user)
        ->getJson($this->base)
        ->assertStatus(422)
        ->assertJsonPath('error', 'model_missing');
});

it('M7-T7.9 op list per field reflects type heuristics', function () {
    $response = $this->actingAs($this->user)->getJson("{$this->base}?model=Recipe");
    $response->assertOk();
    $fields = collect($response->json('data.fields'));

    // Every field always gets the `=` op and the change ops.
    $fields->each(function (array $field) {
        expect($field['ops_supported'])
            ->toContain('=')
            ->toContain('changed_to');
    });
});
