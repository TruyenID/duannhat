<?php

/**
 * Plan-023 M5 T5.5 — DigestBuilderService coverage.
 *
 * Scenarios:
 *   M5-5: empty window → null
 *   M5-6: priority filter from preference excludes unwanted rows
 *   M5-7: sample caps at SAMPLE_CAP; counts remain accurate
 */

use App\Models\Notification;
use App\Models\NotificationDigestPreference;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notification\DigestBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
});

function seedDigestRow(string $orgId, User $recipient, string $priority = 'normal', string $type = 'stock.alert.low'): void
{
    $n = Notification::factory()->create([
        'organization_id' => $orgId,
        'type' => $type,
        'priority' => $priority,
    ]);
    NotificationRecipient::factory()->create([
        'notification_id' => $n->id,
        'recipient_type' => 'User',
        'recipient_id' => $recipient->id,
    ]);
}

it('M5-5: returns null when the window is empty', function () {
    $result = app(DigestBuilderService::class)->buildFor(
        $this->user,
        now()->subDay(),
        now(),
    );
    expect($result)->toBeNull();
});

it('M5-6: priority filter from preference excludes unwanted rows', function () {
    NotificationDigestPreference::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $this->user->id,
        'cadence' => 'daily',
        'delivery_time' => '08:00',
        'timezone' => 'Asia/Tokyo',
        'include_priorities' => ['urgent', 'high'],
    ]);

    seedDigestRow($this->orgId, $this->user, 'urgent');
    seedDigestRow($this->orgId, $this->user, 'low');     // excluded
    seedDigestRow($this->orgId, $this->user, 'high');

    $payload = app(DigestBuilderService::class)->buildFor(
        $this->user,
        now()->subDay(),
        now()->addHour(),
    );

    expect($payload)->not->toBeNull();
    expect($payload->totalCount)->toBe(2);
    expect($payload->countsByPriority)->toMatchArray(['urgent' => 1, 'high' => 1]);
});

it('M5-7: sample caps at SAMPLE_CAP; counts remain accurate', function () {
    // Seed slightly above the cap so the truncation is visible.
    foreach (range(1, DigestBuilderService::SAMPLE_CAP + 5) as $_) {
        seedDigestRow($this->orgId, $this->user);
    }

    $payload = app(DigestBuilderService::class)->buildFor(
        $this->user,
        now()->subDay(),
        now()->addHour(),
    );

    expect($payload)->not->toBeNull();
    expect($payload->totalCount)->toBe(DigestBuilderService::SAMPLE_CAP + 5);
    expect($payload->sample)->toHaveCount(DigestBuilderService::SAMPLE_CAP);
});

it('no preference row → includes every priority', function () {
    // No NotificationDigestPreference row — treat as "no filter".
    seedDigestRow($this->orgId, $this->user, 'urgent');
    seedDigestRow($this->orgId, $this->user, 'low');

    $payload = app(DigestBuilderService::class)->buildFor(
        $this->user,
        now()->subDay(),
        now()->addHour(),
    );

    expect($payload->totalCount)->toBe(2);
});
