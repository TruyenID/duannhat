<?php

/**
 * Plan-023 M4 T4.8 — HQ email suppression admin contract.
 */

use App\Models\Brand;
use App\Models\NotificationEmailSuppression;
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
        'slug' => 'supp-hq-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/notifications/email-suppressions";
});

it('M4-12: lists suppressions scoped to brand orgs + filters by reason', function () {
    foreach ([
        ['email' => 'a@example.com', 'reason' => 'hard_bounce'],
        ['email' => 'b@example.com', 'reason' => 'spam_complaint'],
    ] as $seed) {
        NotificationEmailSuppression::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->orgId,
            'email' => $seed['email'],
            'reason' => $seed['reason'],
            'source_provider' => 'postmark',
            'suppressed_at' => now(),
        ]);
    }

    $this->actingAs($this->user)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($this->user)
        ->getJson("{$this->base}?reason=hard_bounce")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'a@example.com');
});

it('M4-12: active_only=true hides un-suppressed rows by default', function () {
    NotificationEmailSuppression::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'email' => 'gone@example.com',
        'reason' => 'manual',
        'source_provider' => 'manual',
        'suppressed_at' => now()->subDay(),
        'un_suppressed_at' => now(),
    ]);

    $this->actingAs($this->user)->getJson($this->base)->assertJsonCount(0, 'data');
    $this->actingAs($this->user)->getJson("{$this->base}?active_only=0")->assertJsonCount(1, 'data');
});

it('store creates a manual suppression', function () {
    $response = $this->actingAs($this->user)->postJson($this->base, [
        'email' => 'Blocked@Example.com',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.email', 'blocked@example.com')   // normalised
        ->assertJsonPath('data.reason', 'manual')
        ->assertJsonPath('data.source_provider', 'manual');
});

it('store rejects an invalid email', function () {
    $this->actingAs($this->user)->postJson($this->base, ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('M4-13: destroy writes un_suppressed_at rather than deleting the row', function () {
    $row = NotificationEmailSuppression::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'email' => 'live@example.com',
        'reason' => 'hard_bounce',
        'source_provider' => 'postmark',
        'suppressed_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$row->id}")
        ->assertNoContent();

    $fresh = NotificationEmailSuppression::find($row->id);
    expect($fresh)->not->toBeNull();
    expect($fresh->un_suppressed_at)->not->toBeNull();
});

it('filters suppressions by from/to date range', function () {
    foreach ([
        ['email' => 'old@example.com', 'at' => now()->subDays(60)],
        ['email' => 'recent@example.com', 'at' => now()->subDays(2)],
        ['email' => 'today@example.com', 'at' => now()],
    ] as $seed) {
        NotificationEmailSuppression::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->orgId,
            'email' => $seed['email'],
            'reason' => 'hard_bounce',
            'source_provider' => 'postmark',
            'suppressed_at' => $seed['at'],
        ]);
    }

    $from = urlencode(now()->subDays(7)->toIso8601String());
    $this->actingAs($this->user)
        ->getJson("{$this->base}?from={$from}")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $from = urlencode(now()->subDays(7)->toIso8601String());
    $to = urlencode(now()->subDay()->toIso8601String());
    $this->actingAs($this->user)
        ->getJson("{$this->base}?from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'recent@example.com');
});

it('ignores blank or invalid date params instead of 422-ing', function () {
    NotificationEmailSuppression::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'email' => 'a@example.com',
        'reason' => 'hard_bounce',
        'source_provider' => 'postmark',
        'suppressed_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->getJson("{$this->base}?from=&to=not-a-date")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('cross-org access returns 404', function () {
    $otherOrg = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrg, 'console_organization_id' => $otherOrg]);

    $stranger = NotificationEmailSuppression::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $otherOrg,
        'email' => 'stranger@example.com',
        'reason' => 'hard_bounce',
        'source_provider' => 'postmark',
        'suppressed_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$stranger->id}")
        ->assertNotFound();
});
