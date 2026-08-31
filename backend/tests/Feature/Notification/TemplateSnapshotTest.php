<?php

/**
 * Template content snapshot at dispatch (issue #179 part B).
 *
 * Pin: editing a NotificationTemplate after dispatch must NOT change what
 * users see when they later open `/me/notifications`. The dispatch-time
 * render is captured into `params.__snapshot` so audit / forensic queries
 * can answer "what was sent to user X on day Y" even after the template
 * row is edited or deleted.
 *
 * Backwards-compat: notifications without a snapshot (legacy rows) still
 * fall back to live template render — Decision 15 from plan-008 stays
 * the default for inline / on-the-fly broadcasts that have no persisted
 * template row.
 */

use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
        'locale' => 'en',
    ]);
    $this->service = app(NotificationService::class);
});

function seedTemplate(string $orgId, string $key, array $content): NotificationTemplate
{
    return NotificationTemplate::query()->create([
        'organization_id' => $orgId,
        'key' => $key,
        'content' => $content,
        'is_system' => false,
    ]);
}

it('captures rendered title + body for every locale into params.__snapshot at dispatch', function () {
    $key = 'snap.basic.'.Str::random(4);
    seedTemplate($this->orgId, $key, [
        'ja' => ['title' => 'jaタイトル {{name}}', 'body' => 'jaボディ'],
        'en' => ['title' => 'en title {{name}}', 'body' => 'en body'],
        'vi' => ['title' => 'vi tiêu đề {{name}}', 'body' => 'vi body'],
    ]);

    $n = $this->service->dispatch([
        'type' => $key,
        'template_key' => $key,
        'params' => ['name' => 'Taro'],
        'recipients' => [$this->user],
        'organization_id' => $this->orgId,
        'idempotency_key' => 'snap-'.Str::random(6),
    ]);

    $snapshot = $n->fresh()->params[NotificationService::SNAPSHOT_KEY];
    expect($snapshot)->toBeArray()
        ->and($snapshot['en']['title'])->toBe('en title Taro')
        ->and($snapshot['ja']['title'])->toBe('jaタイトル Taro')
        ->and($snapshot['vi']['title'])->toBe('vi tiêu đề Taro');
});

it('serves the snapshot, not the live template, when the template is edited after dispatch', function () {
    $key = 'snap.edit.'.Str::random(4);
    $template = seedTemplate($this->orgId, $key, [
        'ja' => ['title' => 'original ja', 'body' => 'original'],
        'en' => ['title' => 'original en', 'body' => 'original'],
        'vi' => ['title' => 'original vi', 'body' => 'original'],
    ]);

    $this->service->dispatch([
        'type' => $key,
        'template_key' => $key,
        'recipients' => [$this->user],
        'organization_id' => $this->orgId,
        'idempotency_key' => 'snap-edit-'.Str::random(6),
    ]);

    // Admin "fixes a typo" after the email already went out.
    $template->update([
        'content' => [
            'ja' => ['title' => 'EDITED ja', 'body' => 'EDITED'],
            'en' => ['title' => 'EDITED en', 'body' => 'EDITED'],
            'vi' => ['title' => 'EDITED vi', 'body' => 'EDITED'],
        ],
    ]);

    $response = $this->actingAs($this->user)->getJson('/api/v1/me/notifications?collapse=false')->assertOk();
    expect($response->json('data.0.title'))->toBe('original en')
        ->and($response->json('data.0.body'))->toBe('original');
});

it('also serves the snapshot when the template row is deleted after dispatch', function () {
    $key = 'snap.del.'.Str::random(4);
    $template = seedTemplate($this->orgId, $key, [
        'ja' => ['title' => 'will be deleted ja', 'body' => 'gone'],
        'en' => ['title' => 'will be deleted en', 'body' => 'gone'],
        'vi' => ['title' => 'will be deleted vi', 'body' => 'gone'],
    ]);

    $this->service->dispatch([
        'type' => $key,
        'template_key' => $key,
        'recipients' => [$this->user],
        'organization_id' => $this->orgId,
        'idempotency_key' => 'snap-del-'.Str::random(6),
    ]);

    $template->delete();

    $response = $this->actingAs($this->user)->getJson('/api/v1/me/notifications?collapse=false')->assertOk();
    expect($response->json('data.0.title'))->toBe('will be deleted en');
});

it('falls back to live template render when no snapshot exists (backwards compat)', function () {
    // Simulate a legacy notification: dispatch via service then strip the
    // snapshot from params to mimic a row written before this feature shipped.
    $key = 'snap.legacy.'.Str::random(4);
    seedTemplate($this->orgId, $key, [
        'ja' => ['title' => 'live ja', 'body' => 'live'],
        'en' => ['title' => 'live en', 'body' => 'live'],
        'vi' => ['title' => 'live vi', 'body' => 'live'],
    ]);
    $n = $this->service->dispatch([
        'type' => $key,
        'template_key' => $key,
        'recipients' => [$this->user],
        'organization_id' => $this->orgId,
        'idempotency_key' => 'snap-legacy-'.Str::random(6),
    ]);

    $params = (array) $n->fresh()->params;
    unset($params[NotificationService::SNAPSHOT_KEY]);
    Notification::query()->where('id', $n->id)->update(['params' => $params]);

    $response = $this->actingAs($this->user)->getJson('/api/v1/me/notifications?collapse=false')->assertOk();
    // No snapshot → falls back to live render.
    expect($response->json('data.0.title'))->toBe('live en');
});

it('snapshot uses the locale fallback chain (en falls back to ja when en missing)', function () {
    $key = 'snap.fallback.'.Str::random(4);
    seedTemplate($this->orgId, $key, [
        'ja' => ['title' => 'only ja', 'body' => 'only ja body'],
        // en + vi missing
    ]);

    $n = $this->service->dispatch([
        'type' => $key,
        'template_key' => $key,
        'recipients' => [$this->user],
        'organization_id' => $this->orgId,
        'idempotency_key' => 'snap-fb-'.Str::random(6),
    ]);

    // User locale = 'en' (from beforeEach), template only has 'ja'.
    // Snapshot stored only `ja`, fallback chain (en → ja) finds it.
    $response = $this->actingAs($this->user)->getJson('/api/v1/me/notifications?collapse=false')->assertOk();
    expect($response->json('data.0.title'))->toBe('only ja');

    // Pin: snapshot itself only contains the ja key.
    $snapshot = $n->fresh()->params[NotificationService::SNAPSHOT_KEY];
    expect(array_keys($snapshot))->toBe(['ja']);
});

it('does not snapshot when notification has no resolvable template (inline)', function () {
    // No persisted template row — only a template_key that won't match.
    $n = $this->service->dispatch([
        'type' => 'unknown.adhoc.'.Str::random(4),
        'template_key' => 'unknown.adhoc.'.Str::random(4),
        'recipients' => [$this->user],
        'organization_id' => $this->orgId,
        'idempotency_key' => 'snap-noop-'.Str::random(6),
    ]);

    $params = (array) $n->fresh()->params;
    expect($params)->not->toHaveKey(NotificationService::SNAPSHOT_KEY);
});
