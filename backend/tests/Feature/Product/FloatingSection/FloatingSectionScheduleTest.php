<?php

use App\Models\Brand;
use App\Models\FloatingSection;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
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
    ]);

    $this->adminRole = Role::firstOrCreate(
        ['slug' => 'org-admin'],
        ['name' => 'Org Admin', 'level' => 100],
    );
    $this->adminRole->permissions()->syncWithoutDetaching(collect(['menu.view', 'menu.manage'])
        ->map(fn ($slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'group' => 'menu'])->id));

    $this->admin = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->admin->assignRole($this->adminRole, $this->orgId);

    $this->section = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}/floating-sections/{$this->section->id}";
});

it('can create, list, update, and delete a schedule window', function () {
    $create = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/schedules", [
            'start_time' => '17:00',
            'end_time' => '19:00',
            'days_of_week' => 127,
        ]);
    $create->assertCreated()
        ->assertJsonPath('data.days_of_week', 127)
        ->assertJsonPath('data.is_active', true);

    $scheduleId = $create->json('data.id');

    $this->actingAs($this->admin)
        ->getJson("{$this->baseUrl}/schedules")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($this->admin)
        ->putJson("{$this->baseUrl}/schedules/{$scheduleId}", [
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->actingAs($this->admin)
        ->deleteJson("{$this->baseUrl}/schedules/{$scheduleId}")
        ->assertNoContent();

    $this->assertSoftDeleted('floating_section_schedules', ['id' => $scheduleId]);
});

it('rejects end_time before start_time', function () {
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/schedules", [
            'start_time' => '19:00',
            'end_time' => '17:00',
            'days_of_week' => 127,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['end_time']);
});

it('can reorder schedules', function () {
    $first = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/schedules", [
            'start_time' => '11:00', 'end_time' => '13:00', 'days_of_week' => 127,
        ])->json('data.id');
    $second = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/schedules", [
            'start_time' => '17:00', 'end_time' => '19:00', 'days_of_week' => 127,
        ])->json('data.id');

    $this->actingAs($this->admin)
        ->putJson("{$this->baseUrl}/schedules/reorder", [
            'schedule_ids' => [$second, $first],
        ])
        ->assertOk();

    $ordered = $this->section->schedules()->orderBy('priority')->pluck('id')->all();
    expect($ordered)->toBe([$second, $first]);
});

it('scopes a schedule to its own floating section — cross-section access 404s', function () {
    $otherSection = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $scheduleId = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/schedules", [
            'start_time' => '17:00', 'end_time' => '19:00', 'days_of_week' => 127,
        ])->json('data.id');

    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brand->slug}/floating-sections/{$otherSection->id}/schedules/{$scheduleId}", [
            'is_active' => false,
        ])
        ->assertNotFound();
});
