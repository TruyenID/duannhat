<?php

/**
 * EffectiveChannelService batch API tests (plan-012 audit follow-up).
 *
 * Asserts a single route + single prefs lookup regardless of recipient count.
 * Catches the N+1 query regression that shipped originally.
 */

use App\Models\Notification;
use App\Models\NotificationChannelRoute;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\NotificationChannelEnum;
use App\Services\Notification\EffectiveChannelService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
});

it('effectiveChannelsForBatch runs exactly 2 queries for N user recipients', function () {
    NotificationChannelRoute::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'recipe.approved',
        'channels' => ['in_app', 'email'],
    ]);

    $users = collect(range(1, 20))->map(fn () => User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]));

    // Seed opt-out for one of them so the path actually reads prefs.
    NotificationPreference::query()->create([
        'user_id' => $users[5]->id,
        'type' => 'recipe.approved',
        'channel' => 'email',
        'enabled' => false,
    ]);

    $notif = Notification::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'recipe.approved',
        'template_key' => 'recipe.approved',
        'params' => [],
        'priority' => 'normal',
    ]);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $channels = app(EffectiveChannelService::class)->effectiveChannelsForBatch($notif, $users);

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Expected queries: 1 route lookup + 1 batched prefs IN query.
    expect($queryCount)->toBeLessThanOrEqual(2);
    expect($channels)->toHaveCount(20);

    // Opted-out user has in_app only.
    $key = $users[5]->getMorphClass().':'.$users[5]->id;
    expect($channels[$key]->map(fn ($c) => $c->value)->all())->toBe(['in_app']);

    // Other users get both channels.
    $other = $users[0]->getMorphClass().':'.$users[0]->id;
    expect($channels[$other]->map(fn ($c) => $c->value)->sort()->values()->all())
        ->toBe(['email', 'in_app']);
});

it('effectiveChannelsFor (singular) delegates to the batch path', function () {
    $user = User::factory()->create(['console_organization_id' => $this->orgId]);
    $notif = Notification::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'stock.alert.low',
        'template_key' => 'stock.alert.low',
        'params' => [],
        'priority' => 'normal',
    ]);

    $channels = app(EffectiveChannelService::class)->effectiveChannelsFor($notif, $user);
    expect($channels->first())->toBe(NotificationChannelEnum::InApp);
});
