<?php

/**
 * One-click email unsubscribe (issue #173 part A).
 *
 * Pins:
 *   - Valid signed link flips the (user, type, email) preference to enabled=false
 *   - POST one-click variant works (RFC 8058 / Gmail Feb 2024 bulk-sender rules)
 *   - Tampered signature → 403 (Laravel signed-route middleware)
 *   - Non-existent user → 200 (opaque success, no enumeration)
 *   - Other channels for the same type are not touched
 *   - Unsubscribe is idempotent
 */

use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\NotificationChannelEnum;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
        'email' => 'taro@example.com',
    ]);
});

function signedUnsubscribeUrl(string $userId, string $type): string
{
    return URL::signedRoute(
        'api.v1.notifications.email.unsubscribe',
        ['user' => $userId, 'type' => $type],
        now()->addDays(60),
    );
}

it('flips the (user, type, email) preference to enabled=false on valid signed GET', function () {
    $url = signedUnsubscribeUrl($this->user->id, 'recipe.approved');

    $this->getJson($url)->assertOk();

    $pref = NotificationPreference::query()
        ->where('user_id', $this->user->id)
        ->where('type', 'recipe.approved')
        ->where('channel', NotificationChannelEnum::Email->value)
        ->sole();
    expect($pref->enabled)->toBeFalse();
});

it('also works as RFC 8058 one-click POST', function () {
    $url = signedUnsubscribeUrl($this->user->id, 'recipe.approved');
    // The same query-string-signed URL works for POST when the route is
    // registered for POST too. Email clients send `List-Unsubscribe=One-Click`
    // as the body; we don't read the body, only the signature.
    $this->postJson($url, ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect(
        NotificationPreference::query()
            ->where('user_id', $this->user->id)
            ->where('type', 'recipe.approved')
            ->where('channel', NotificationChannelEnum::Email->value)
            ->sole()
            ->enabled
    )->toBeFalse();
});

it('returns 403 when the signature has been tampered with', function () {
    $url = signedUnsubscribeUrl($this->user->id, 'recipe.approved');
    // Swap one query param to invalidate the HMAC.
    $tampered = str_replace('recipe.approved', 'system.critical', $url);

    $this->getJson($tampered)->assertForbidden();
});

it('returns 200 (opaque) when the user does not exist (no enumeration leak)', function () {
    $randomId = (string) Str::uuid();
    $url = signedUnsubscribeUrl($randomId, 'recipe.approved');

    $this->getJson($url)->assertOk();

    // Pin: no preference rows were silently created for the bogus user id.
    expect(NotificationPreference::where('user_id', $randomId)->count())->toBe(0);
});

it('does not affect non-email channels for the same type', function () {
    NotificationPreference::query()->create([
        'user_id' => $this->user->id,
        'type' => 'recipe.approved',
        'channel' => NotificationChannelEnum::InApp->value,
        'enabled' => true,
    ]);
    NotificationPreference::query()->create([
        'user_id' => $this->user->id,
        'type' => 'recipe.approved',
        'channel' => NotificationChannelEnum::Push->value,
        'enabled' => true,
    ]);

    $this->getJson(signedUnsubscribeUrl($this->user->id, 'recipe.approved'))->assertOk();

    $stillEnabled = NotificationPreference::query()
        ->where('user_id', $this->user->id)
        ->where('type', 'recipe.approved')
        ->whereIn('channel', [
            NotificationChannelEnum::InApp->value,
            NotificationChannelEnum::Push->value,
        ])
        ->where('enabled', true)
        ->count();
    expect($stillEnabled)->toBe(2);
});

it('is idempotent — second call leaves the preference disabled', function () {
    $url = signedUnsubscribeUrl($this->user->id, 'recipe.approved');

    $this->getJson($url)->assertOk();
    $this->getJson($url)->assertOk();

    $pref = NotificationPreference::query()
        ->where('user_id', $this->user->id)
        ->where('type', 'recipe.approved')
        ->where('channel', NotificationChannelEnum::Email->value)
        ->sole();
    expect($pref->enabled)->toBeFalse();
});
