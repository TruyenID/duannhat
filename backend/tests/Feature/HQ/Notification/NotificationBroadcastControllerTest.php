<?php

/**
 * Admin broadcast composer tests (plan-012 T4.11).
 */

use App\Jobs\NotificationChannelJob;
use App\Models\Brand;
use App\Models\NotificationAudience;
use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

beforeEach(function () {
    Bus::fake([NotificationChannelJob::class]);
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'bc-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
    $this->base = "/api/v1/hq/{$this->brand->slug}/notifications/broadcast";
});

function setupAudienceWithRecipients(string $orgId, Brand $brand, int $count): array
{
    $users = [];
    for ($i = 0; $i < $count; $i++) {
        $u = User::factory()->create(['console_organization_id' => $orgId]);
        $users[] = $u;
    }

    $audience = NotificationAudience::query()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'name' => 'bc-aud-'.Str::random(4),
        'rule' => [
            'version' => 1,
            'combinator' => 'or',
            'rules' => [
                ['type' => 'user', 'user_ids' => collect($users)->pluck('id')->all()],
            ],
        ],
        'is_system' => false,
    ]);

    return ['audience' => $audience, 'users' => $users];
}

function createInlineTemplate(string $orgId): NotificationTemplate
{
    return NotificationTemplate::query()->create([
        'organization_id' => $orgId,
        'key' => 'compose.'.Str::random(4),
        'content' => [
            'ja' => ['title' => 'タイトル', 'body' => '本文'],
            'en' => ['title' => 'Title', 'body' => 'Body'],
            'vi' => ['title' => 'Tiêu đề', 'body' => 'Nội dung'],
        ],
        'default_channels' => ['in_app'],
        'is_system' => false,
    ]);
}

it('dispatches with saved audience + template + 3 channels yields N×3 deliveries', function () {
    $setup = setupAudienceWithRecipients($this->orgId, $this->brand, 3);
    $template = createInlineTemplate($this->orgId);

    $response = $this->actingAs($this->user)->postJson($this->base, [
        'audience_id' => $setup['audience']->id,
        'template_id' => $template->id,
        'channels' => ['in_app', 'realtime', 'email'],
        'priority' => 'normal',
    ])->assertCreated();

    $notifId = $response->json('data.id');
    $deliveries = NotificationDelivery::query()
        ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $notifId))
        ->get();

    expect($deliveries->count())->toBe(9); // 3 recipients × 3 channels
    expect($deliveries->pluck('channel')->map(fn ($c) => $c->value)->unique()->sort()->values()->all())
        ->toBe(['email', 'in_app', 'realtime']);
});

it('returns 422 audience_empty when the audience resolves to 0 recipients', function () {
    $template = createInlineTemplate($this->orgId);

    // Audience with no users.
    $audience = NotificationAudience::query()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'empty-'.Str::random(4),
        'rule' => [
            'version' => 1,
            'combinator' => 'or',
            'rules' => [
                ['type' => 'user', 'user_ids' => []],
            ],
        ],
        'is_system' => false,
    ]);

    $this->actingAs($this->user)->postJson($this->base, [
        'audience_id' => $audience->id,
        'template_id' => $template->id,
        'channels' => ['in_app'],
        'priority' => 'normal',
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'audience_empty');
});

it('rejects payload when both audience_id and audience_inline are present', function () {
    $template = createInlineTemplate($this->orgId);
    $setup = setupAudienceWithRecipients($this->orgId, $this->brand, 1);

    $this->actingAs($this->user)->postJson($this->base, [
        'audience_id' => $setup['audience']->id,
        'audience_inline' => ['rules' => [['type' => 'user', 'user_ids' => []]]],
        'template_id' => $template->id,
        'channels' => ['in_app'],
        'priority' => 'normal',
    ])->assertStatus(422);
});

it('rejects payload when template_inline is missing a locale', function () {
    $setup = setupAudienceWithRecipients($this->orgId, $this->brand, 1);

    $this->actingAs($this->user)->postJson($this->base, [
        'audience_id' => $setup['audience']->id,
        'template_inline' => [
            'key' => 'x.y',
            'content' => [
                'ja' => ['title' => 't', 'body' => 'b'],
                'en' => ['title' => 't', 'body' => 'b'],
                // vi missing
            ],
        ],
        'channels' => ['in_app'],
        'priority' => 'normal',
    ])->assertStatus(422);
});

it('forbids outsiders', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $outsider = User::factory()->create(['console_organization_id' => $otherOrgId]);

    $template = createInlineTemplate($this->orgId);
    $setup = setupAudienceWithRecipients($this->orgId, $this->brand, 1);

    $this->actingAs($outsider)->postJson($this->base, [
        'audience_id' => $setup['audience']->id,
        'template_id' => $template->id,
        'channels' => ['in_app'],
        'priority' => 'normal',
    ])->assertForbidden();
});

// =============================================================================
// Cross-tenant IDOR — see GH #170
// =============================================================================
//
// Before the fix, `resolveTemplate()` and `resolveRule()` called `findOrFail()`
// without scoping. An admin in Brand A could pass a `template_id` / `audience_id`
// belonging to Brand B (in a different org) and the controller would happily
// load it, leading to cross-tenant broadcast / rule leak.
//
// Post-fix, both methods scope the query to the route brand's organization
// IDs (via `brandOrgIds()`) so foreign UUIDs collapse to 404.
//
// =============================================================================

it('rejects template_id from a foreign organization with 404', function () {
    $foreignOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $foreignOrgId,
        'console_organization_id' => $foreignOrgId,
    ]);
    $foreignBrand = Brand::factory()->create([
        'console_organization_id' => $foreignOrgId,
        'is_active' => true,
    ]);
    $foreignTemplate = NotificationTemplate::query()->create([
        'organization_id' => $foreignOrgId,
        'brand_id' => $foreignBrand->id,
        'key' => 'foreign.'.Str::random(4),
        'content' => [
            'ja' => ['title' => 'foreign', 'body' => 'foreign'],
            'en' => ['title' => 'foreign', 'body' => 'foreign'],
            'vi' => ['title' => 'foreign', 'body' => 'foreign'],
        ],
        'default_channels' => ['in_app'],
        'is_system' => false,
    ]);
    $setup = setupAudienceWithRecipients($this->orgId, $this->brand, 1);

    $this->actingAs($this->user)->postJson($this->base, [
        'audience_id' => $setup['audience']->id,
        'template_id' => $foreignTemplate->id,
        'channels' => ['in_app'],
        'priority' => 'normal',
    ])->assertNotFound();
});

it('rejects audience_id from a foreign organization with 404', function () {
    $foreignOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $foreignOrgId,
        'console_organization_id' => $foreignOrgId,
    ]);
    $foreignBrand = Brand::factory()->create([
        'console_organization_id' => $foreignOrgId,
        'is_active' => true,
    ]);
    $foreignAudience = NotificationAudience::query()->create([
        'organization_id' => $foreignOrgId,
        'brand_id' => $foreignBrand->id,
        'name' => 'foreign-aud-'.Str::random(4),
        'rule' => [
            'version' => 1,
            'combinator' => 'or',
            'rules' => [['type' => 'user', 'user_ids' => [(string) Str::uuid()]]],
        ],
        'is_system' => false,
    ]);
    $template = createInlineTemplate($this->orgId);

    $this->actingAs($this->user)->postJson($this->base, [
        'audience_id' => $foreignAudience->id,
        'template_id' => $template->id,
        'channels' => ['in_app'],
        'priority' => 'normal',
    ])->assertNotFound();
});

// =============================================================================
// Cross-tenant INLINE audience injection (plan-038/012 audit CRITICAL)
// =============================================================================
//
// The GH #170 fix only scoped `audience_id` / `template_id` lookups. The
// `audience_inline` path hands fully caller-controlled rule JSON straight to
// the resolvers, which expand arbitrary user_ids / device_ids from ANY org.
// A Brand-A admin could inline-target Brand-B recipients and fan a broadcast
// across the tenant boundary. Post-fix, every inline recipient is checked
// against the route brand's organization; a single foreign recipient aborts
// the whole broadcast with 422 `audience_cross_tenant`.
//
// =============================================================================

it('rejects an inline audience that targets a foreign-org user (422, no delivery)', function () {
    $foreignOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $foreignOrgId,
        'console_organization_id' => $foreignOrgId,
    ]);
    $foreignUser = User::factory()->create([
        'console_organization_id' => $foreignOrgId,
    ]);

    $this->actingAs($this->user)->postJson($this->base, [
        'audience_inline' => [
            'version' => 1,
            'combinator' => 'or',
            'rules' => [
                ['type' => 'user', 'user_ids' => [$foreignUser->id]],
            ],
        ],
        'template_inline' => [
            'key' => 'x.y',
            'content' => [
                'ja' => ['title' => 't', 'body' => 'b'],
                'en' => ['title' => 't', 'body' => 'b'],
                'vi' => ['title' => 't', 'body' => 'b'],
            ],
        ],
        'channels' => ['in_app'],
        'priority' => 'normal',
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'audience_cross_tenant');

    // Nothing was enqueued for the foreign user.
    expect(NotificationDelivery::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('rejects an inline audience mixing an own user with a foreign-org user (422)', function () {
    $ownUser = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $foreignOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $foreignOrgId,
        'console_organization_id' => $foreignOrgId,
    ]);
    $foreignUser = User::factory()->create([
        'console_organization_id' => $foreignOrgId,
    ]);

    $this->actingAs($this->user)->postJson($this->base, [
        'audience_inline' => [
            'version' => 1,
            'combinator' => 'or',
            'rules' => [
                ['type' => 'user', 'user_ids' => [$ownUser->id, $foreignUser->id]],
            ],
        ],
        'template_inline' => [
            'key' => 'x.y',
            'content' => [
                'ja' => ['title' => 't', 'body' => 'b'],
                'en' => ['title' => 't', 'body' => 'b'],
                'vi' => ['title' => 't', 'body' => 'b'],
            ],
        ],
        'channels' => ['in_app'],
        'priority' => 'normal',
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'audience_cross_tenant');

    expect(NotificationDelivery::query()->count())->toBe(0);
});

it('accepts an inline audience that targets only own-org users', function () {
    $ownUsers = collect(range(1, 2))->map(fn () => User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]));

    $response = $this->actingAs($this->user)->postJson($this->base, [
        'audience_inline' => [
            'version' => 1,
            'combinator' => 'or',
            'rules' => [
                ['type' => 'user', 'user_ids' => $ownUsers->pluck('id')->all()],
            ],
        ],
        'template_inline' => [
            'key' => 'x.y',
            'content' => [
                'ja' => ['title' => 't', 'body' => 'b'],
                'en' => ['title' => 't', 'body' => 'b'],
                'vi' => ['title' => 't', 'body' => 'b'],
            ],
        ],
        'channels' => ['in_app'],
        'priority' => 'normal',
    ])->assertCreated();

    $notifId = $response->json('data.id');
    $deliveries = NotificationDelivery::query()
        ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $notifId))
        ->get();

    expect($deliveries->count())->toBe(2); // 2 own users × 1 channel
});

it('throttles broadcast to 30/min per org (issue #171)', function () {
    // Each over-cap call must return 429. We don't need to check what the
    // 30 successful calls do — happy path is covered elsewhere — only that
    // a 31st over-cap request is rejected before the controller runs.
    $setup = setupAudienceWithRecipients($this->orgId, $this->brand, 1);
    $template = createInlineTemplate($this->orgId);
    $payload = [
        'audience_id' => $setup['audience']->id,
        'template_id' => $template->id,
        'channels' => ['in_app'],
        'priority' => 'normal',
    ];

    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($this->user)->postJson($this->base, $payload)->assertCreated();
    }

    $this->actingAs($this->user)
        ->postJson($this->base, $payload)
        ->assertStatus(429);
});

it('still accepts is_system templates regardless of brand', function () {
    // System templates are seeded with no organization_id and is_system=true.
    // They MUST stay reachable from every brand — that is the whole point of
    // marking them is_system. The cross-tenant guard must not break this.
    $systemTemplate = NotificationTemplate::query()->create([
        'organization_id' => null,
        'brand_id' => null,
        'key' => 'system.'.Str::random(4),
        'content' => [
            'ja' => ['title' => 'sys', 'body' => 'sys'],
            'en' => ['title' => 'sys', 'body' => 'sys'],
            'vi' => ['title' => 'sys', 'body' => 'sys'],
        ],
        'default_channels' => ['in_app'],
        'is_system' => true,
    ]);
    $setup = setupAudienceWithRecipients($this->orgId, $this->brand, 1);

    $this->actingAs($this->user)->postJson($this->base, [
        'audience_id' => $setup['audience']->id,
        'template_id' => $systemTemplate->id,
        'channels' => ['in_app'],
        'priority' => 'normal',
    ])->assertCreated();
});
