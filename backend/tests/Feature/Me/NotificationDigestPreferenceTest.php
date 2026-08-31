<?php

/**
 * Plan-023 M5 T5.8 — digest preference API contract.
 */

use App\Models\NotificationDigestPreference;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
});

it('M5-T5.8 GET returns defaults when no row exists', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/me/notification-preferences/digest')
        ->assertOk()
        ->assertJsonPath('data.cadence', 'off')
        ->assertJsonPath('data.delivery_time', '08:00')
        ->assertJsonPath('data.timezone', 'Asia/Tokyo');
});

it('M5-T5.8 PATCH stores cadence=daily + roundtrips on GET', function () {
    $this->actingAs($this->user)
        ->patchJson('/api/v1/me/notification-preferences/digest', [
            'cadence' => 'daily',
            'delivery_time' => '07:30',
            'timezone' => 'Asia/Tokyo',
            'include_priorities' => ['urgent', 'high'],
        ])
        ->assertOk()
        ->assertJsonPath('data.cadence', 'daily')
        ->assertJsonPath('data.delivery_time', '07:30')
        ->assertJsonPath('data.include_priorities.0', 'urgent');

    $this->actingAs($this->user)
        ->getJson('/api/v1/me/notification-preferences/digest')
        ->assertJsonPath('data.cadence', 'daily');

    expect(NotificationDigestPreference::query()->where('user_id', $this->user->id)->exists())->toBeTrue();
});

it('M5-T5.8 PATCH requires weekday when cadence=weekly', function () {
    $this->actingAs($this->user)
        ->patchJson('/api/v1/me/notification-preferences/digest', [
            'cadence' => 'weekly',
            'delivery_time' => '08:00',
            'timezone' => 'Asia/Tokyo',
            // weekday missing
        ])
        ->assertStatus(422);
});

it('M5-T5.8 PATCH rejects invalid timezone', function () {
    $this->actingAs($this->user)
        ->patchJson('/api/v1/me/notification-preferences/digest', [
            'cadence' => 'daily',
            'delivery_time' => '08:00',
            'timezone' => 'Mars/Olympus',
        ])
        ->assertStatus(422);
});
