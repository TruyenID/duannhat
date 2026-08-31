<?php

/**
 * EffectiveChannelService quiet-hours boundary / equality / DST-naive tests
 * (plan-012 audit follow-up).
 *
 * The existing suite only exercises 23:00 — the middle of a wrapping window.
 * This file pins the edge behaviour of EffectiveChannelService::isInQuietHours:
 *   - inclusive start (>= quiet_from), exclusive end (< quiet_to)
 *   - equal from/to disables quiet hours entirely
 *   - non-wrapping (from < to) windows
 *   - wrapping (midnight-crossing) windows
 *   - DST-naiveté: the decision is on wall-clock minutes in the stored tz, so
 *     the same local wall-clock time yields the same result across a DST shift
 *   - null/blank tz falls back to UTC
 *
 * Tests call EffectiveChannelService::effectiveChannelsFor directly (single
 * recipient) and check whether interrupting channels (realtime/email/push)
 * survive. Only in_app survives during quiet hours.
 */

use App\Models\Notification;
use App\Models\NotificationChannelRoute;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notification\EffectiveChannelService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    // Route carries every interrupting channel so quiet-hours stripping is visible.
    NotificationChannelRoute::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'recipe.approved',
        'channels' => ['in_app', 'realtime', 'email', 'push'],
    ]);

    $this->notif = Notification::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'recipe.approved',
        'template_key' => 'recipe.approved',
        'params' => [],
        'priority' => 'normal',
    ]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array{from:?string,to:?string,tz:?string}  $quiet
 */
function quietUser(string $orgId, array $quiet): User
{
    $user = User::factory()->create([
        'console_organization_id' => $orgId,
    ]);

    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'type' => '*',
        'channel' => '*',
        'enabled' => true,
        'master_mute' => false,
        'quiet_from' => $quiet['from'],
        'quiet_to' => $quiet['to'],
        'quiet_timezone' => $quiet['tz'],
    ]);

    return $user;
}

/**
 * @return array<int, string>
 */
function channelValuesFor(User $user, Notification $notif): array
{
    return app(EffectiveChannelService::class)
        ->effectiveChannelsFor($notif, $user)
        ->map(fn ($c) => $c->value)
        ->sort()
        ->values()
        ->all();
}

// ---------------------------------------------------------------------------
// Wrapping window (22:00 → 07:00) boundary equality
// ---------------------------------------------------------------------------

it('quiet hours boundary: exactly quiet_from (22:00) is IN quiet hours — inclusive start', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-22 22:00:00', 'Asia/Tokyo'));

    $user = quietUser($this->orgId, ['from' => '22:00', 'to' => '07:00', 'tz' => 'Asia/Tokyo']);

    // >= quiet_from is inclusive → interrupting channels stripped, only in_app.
    expect(channelValuesFor($user, $this->notif))->toBe(['in_app']);
});

it('quiet hours boundary: exactly quiet_to (07:00) is NOT in quiet hours — exclusive end', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-22 07:00:00', 'Asia/Tokyo'));

    $user = quietUser($this->orgId, ['from' => '22:00', 'to' => '07:00', 'tz' => 'Asia/Tokyo']);

    // < quiet_to is exclusive → 07:00 is outside, every channel survives.
    expect(channelValuesFor($user, $this->notif))->toBe(['email', 'in_app', 'push', 'realtime']);
});

it('quiet hours boundary: one minute before start (21:59) is NOT in quiet hours', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-22 21:59:00', 'Asia/Tokyo'));

    $user = quietUser($this->orgId, ['from' => '22:00', 'to' => '07:00', 'tz' => 'Asia/Tokyo']);

    expect(channelValuesFor($user, $this->notif))->toBe(['email', 'in_app', 'push', 'realtime']);
});

it('quiet hours boundary: one minute before end (06:59) is IN quiet hours', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-22 06:59:00', 'Asia/Tokyo'));

    $user = quietUser($this->orgId, ['from' => '22:00', 'to' => '07:00', 'tz' => 'Asia/Tokyo']);

    expect(channelValuesFor($user, $this->notif))->toBe(['in_app']);
});

// ---------------------------------------------------------------------------
// Equal from/to — quiet hours disabled entirely
// ---------------------------------------------------------------------------

it('quiet hours equality: quiet_from === quiet_to disables quiet hours even at that exact minute', function () {
    // Even standing on the shared boundary minute, an empty window never mutes.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-22 22:00:00', 'Asia/Tokyo'));

    $user = quietUser($this->orgId, ['from' => '22:00', 'to' => '22:00', 'tz' => 'Asia/Tokyo']);

    expect(channelValuesFor($user, $this->notif))->toBe(['email', 'in_app', 'push', 'realtime']);
});

it('quiet hours: null quiet_from/quiet_to means no quiet hours', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-22 23:00:00', 'Asia/Tokyo'));

    $user = quietUser($this->orgId, ['from' => null, 'to' => null, 'tz' => 'Asia/Tokyo']);

    expect(channelValuesFor($user, $this->notif))->toBe(['email', 'in_app', 'push', 'realtime']);
});

// ---------------------------------------------------------------------------
// Non-wrapping window (09:00 → 17:00)
// ---------------------------------------------------------------------------

it('non-wrapping window: inside (12:00) strips interrupting channels', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-22 12:00:00', 'Asia/Tokyo'));

    $user = quietUser($this->orgId, ['from' => '09:00', 'to' => '17:00', 'tz' => 'Asia/Tokyo']);

    expect(channelValuesFor($user, $this->notif))->toBe(['in_app']);
});

it('non-wrapping window: exactly start (09:00) is IN — inclusive', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-22 09:00:00', 'Asia/Tokyo'));

    $user = quietUser($this->orgId, ['from' => '09:00', 'to' => '17:00', 'tz' => 'Asia/Tokyo']);

    expect(channelValuesFor($user, $this->notif))->toBe(['in_app']);
});

it('non-wrapping window: exactly end (17:00) is OUT — exclusive', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-22 17:00:00', 'Asia/Tokyo'));

    $user = quietUser($this->orgId, ['from' => '09:00', 'to' => '17:00', 'tz' => 'Asia/Tokyo']);

    expect(channelValuesFor($user, $this->notif))->toBe(['email', 'in_app', 'push', 'realtime']);
});

it('non-wrapping window: before start (08:59) is OUT', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-22 08:59:00', 'Asia/Tokyo'));

    $user = quietUser($this->orgId, ['from' => '09:00', 'to' => '17:00', 'tz' => 'Asia/Tokyo']);

    expect(channelValuesFor($user, $this->notif))->toBe(['email', 'in_app', 'push', 'realtime']);
});

// ---------------------------------------------------------------------------
// DST-naiveté — decision is wall-clock minutes in the stored tz, offset-agnostic
// ---------------------------------------------------------------------------

it('DST-naive: same local wall-clock (23:00) is IN quiet hours in winter EST and summer EDT alike', function () {
    $quiet = ['from' => '22:00', 'to' => '07:00', 'tz' => 'America/New_York'];

    // Winter — EST (UTC-5).
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-15 23:00:00', 'America/New_York'));
    $winterUser = quietUser($this->orgId, $quiet);
    expect(CarbonImmutable::now('America/New_York')->format('T'))->toBe('EST');
    expect(channelValuesFor($winterUser, $this->notif))->toBe(['in_app']);

    // Summer — EDT (UTC-4). Same wall clock, different UTC offset.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 23:00:00', 'America/New_York'));
    $summerUser = quietUser($this->orgId, $quiet);
    expect(CarbonImmutable::now('America/New_York')->format('T'))->toBe('EDT');
    expect(channelValuesFor($summerUser, $this->notif))->toBe(['in_app']);
});

it('DST-naive: identical UTC instant lands differently by wall-clock across the offset shift', function () {
    $quiet = ['from' => '22:00', 'to' => '07:00', 'tz' => 'America/New_York'];

    // 03:30 UTC in winter → 22:30 EST (prev day) → IN quiet hours.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-16 03:30:00', 'UTC'));
    $winterUser = quietUser($this->orgId, $quiet);
    expect(CarbonImmutable::now('America/New_York')->format('H:i'))->toBe('22:30');
    expect(channelValuesFor($winterUser, $this->notif))->toBe(['in_app']);

    // Same 03:30 UTC in summer → 23:30 EDT → still IN quiet hours, but the local
    // wall clock advanced an hour: the decision follows local time, not UTC.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-16 03:30:00', 'UTC'));
    $summerUser = quietUser($this->orgId, $quiet);
    expect(CarbonImmutable::now('America/New_York')->format('H:i'))->toBe('23:30');
    expect(channelValuesFor($summerUser, $this->notif))->toBe(['in_app']);
});

it('DST spring-forward day: 21:59 local wall-clock is OUT of a 22:00 window even though clocks jumped earlier that day', function () {
    // 2026-03-08 is the US spring-forward date (02:00 → 03:00 skipped). The
    // quiet-hours math is wall-clock naive, so the boundary still lands at 22:00.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-08 21:59:00', 'America/New_York'));

    $user = quietUser($this->orgId, ['from' => '22:00', 'to' => '07:00', 'tz' => 'America/New_York']);

    expect(channelValuesFor($user, $this->notif))->toBe(['email', 'in_app', 'push', 'realtime']);
});

// ---------------------------------------------------------------------------
// tz fallback — null/blank quiet_timezone resolves to UTC
// ---------------------------------------------------------------------------

it('tz fallback: null quiet_timezone evaluates the window in UTC', function () {
    // 23:00 UTC is inside 22:00–07:00; Asia/Tokyo would be 08:00 (outside).
    // Proves the fallback tz is UTC, not the app/server tz.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-22 23:00:00', 'UTC'));

    $user = quietUser($this->orgId, ['from' => '22:00', 'to' => '07:00', 'tz' => null]);

    expect(channelValuesFor($user, $this->notif))->toBe(['in_app']);
});
