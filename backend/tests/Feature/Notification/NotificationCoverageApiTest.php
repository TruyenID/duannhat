<?php

/**
 * Plan-023 M8 T8.13 — NotificationCoverageController tests.
 */

use App\Models\Brand;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\SystemNotificationRuleSeeder;
use Illuminate\Support\Str;

it('M8-35: coverage API returns three sets with system rules seeded', function () {
    // Seed system rules for the test org
    (new SystemNotificationRuleSeeder)->run();

    $brand = Brand::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    // Create a brand_admin user
    $orgId = '00000000-0000-0000-0000-000000000001';
    $role = Role::firstOrCreate(
        ['slug' => 'org-admin'],
        ['id' => (string) Str::uuid(), 'console_organization_id' => $orgId, 'name' => 'Brand Admin', 'level' => 80]
    );
    $user = User::factory()->create(['console_organization_id' => $orgId]);
    $user->assignRole($role, $orgId);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/hq/{$brand->slug}/notifications/coverage");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'system_rules' => [['id', 'name', 'trigger_event', 'trigger_model_type', 'is_active', 'fire_count', 'last_fired_at']],
            'covered_models',
            'uncovered_models',
        ]);

    $data = $response->json();
    expect(count($data['system_rules']))->toBeGreaterThan(0)
        ->and($data['covered_models'])->not->toBeEmpty()
        ->and($data['uncovered_models'])->toBeArray();
});
